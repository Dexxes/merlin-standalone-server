<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Auth\SessionService;
use Merlin\Db\ContentFilterRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\I18n\Translator;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\ContentFilterMerger;
use Merlin\Service\ContentFilterSchema;
use Merlin\Service\ContentFilterTrace;
use Merlin\Service\ContentFilterValidator;
use Psr\Log\LoggerInterface;

/**
 * Persönliche Content-Filter-Overrides: jeder eingeloggte Nutzer darf seine
 * eigene, private dritte Ebene bearbeiten (Bundle < Admin-Custom <
 * User-Custom). Bundle- und Admin-Regeln sind hier read-only Referenz,
 * geschrieben wird ausschliesslich die eigene Zeile - siehe
 * ContentFilterRepository. Vereinfachter Port von
 * merlin-nextcloud/lib/Controller/UserContentFilterController.php ohne
 * JSON-Regel-Builder (rohes XML statt "rules"-Struktur).
 *
 * Isolation: jede Lese-/Schreib-/Löschoperation hier bekommt $userId aus
 * $request->authUserId() (Session- oder Token-Auth, serverseitig aufgelöst),
 * niemals aus einem Request-Parameter - ein Nutzer kann so über keinen
 * Endpunkt dieser Klasse den Override eines anderen Nutzers lesen oder
 * verändern.
 */
final class UserContentFilterController {
    public function __construct(
        private readonly ContentFilterRepository $repository,
        private readonly ContentFilterValidator $validator,
        private readonly ContentFilterMerger $merger,
        private readonly ContentExtractorService $extractor,
        private readonly LoggerInterface $logger,
        private readonly SessionService $sessions,
    ) {
    }

    public function index(Request $request): Response {
        $userId = $request->authUserId();
        $ownDomains = array_flip($this->repository->listUserOverrideDomains($userId));

        $domains = array_map(static function (array $entry) use ($ownDomains) {
            return [
                'domain' => $entry['domain'],
                'hasBundle' => $entry['hasBundle'],
                'hasAdminCustom' => $entry['hasCustom'],
                'hasOwnOverride' => isset($ownDomains[$entry['domain']]),
            ];
        }, $this->repository->listFilters());

        return Response::json(['domains' => $domains]);
    }

    public function show(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        $t = $this->translator($request);
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error($t->t('cfApi.invalidDomain'), 400);
        }

        $userId = $request->authUserId();
        $bundle = $this->repository->readBundle($domain);
        $admin = $this->repository->readAdminCustom($domain);
        $own = $this->repository->readUserCustom($domain, $userId);

        if ($bundle === null && $admin === null && $own === null) {
            return $this->error($t->t('cfApi.noFilterForDomain'), 404);
        }

        $payload = [
            'domain' => $domain,
            'reference' => null, // Bundle + Admin-Custom, read-only
            'own' => $own,
            'merged' => null,
        ];

        try {
            $referenceXml = $this->merger->mergeToString($bundle, $admin, $domain, ContentFilterSchema::ORIGIN_ADMIN);
            $payload['reference'] = $referenceXml;
            $payload['merged'] = $this->merger->mergeToString($referenceXml, $own, $domain, ContentFilterSchema::ORIGIN_USER);
        } catch (\Throwable $e) {
            $payload['mergeError'] = $e->getMessage();
        }

        return Response::json($payload);
    }

    public function update(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        $t = $this->translator($request);
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error($t->t('cfApi.invalidDomain'), 400);
        }

        $xml = (string) $request->input('xml', '');
        $errors = $this->validator->validate($xml, $domain);
        if ($errors !== []) {
            return Response::json(['message' => $t->t('cfApi.filterInvalid'), 'errors' => $errors], 400);
        }

        try {
            $this->repository->saveUserCustom($request->authUserId(), $domain, $xml);
        } catch (\Throwable $e) {
            $this->logger->error('content-filters: Speichern des User-Overrides fehlgeschlagen', [
                'domain' => $domain,
                'exception' => $e,
            ]);
            return $this->error($e->getMessage(), 500);
        }

        return $this->show($request);
    }

    public function destroy(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error($this->translator($request)->t('cfApi.invalidDomain'), 400);
        }

        $this->repository->deleteUserCustom($request->authUserId(), $domain);
        return Response::json(['domain' => $domain, 'deleted' => true]);
    }

    /**
     * Testet den EIGENEN Override gegen Bundle+Admin (nicht wie
     * ContentFilterController::test() mit userId=null) - über
     * ContentExtractorService::extract() mit der eigenen UID, damit
     * ContentFilterRepository::getMerged() alle drei Ebenen zusammenführt.
     */
    public function test(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        $t = $this->translator($request);
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error($t->t('cfApi.invalidDomain'), 400);
        }

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return $this->error($t->t('cfApi.noTestUrl'), 400);
        }

        $urlDomain = $this->repository->normalizeUrlDomain($url);
        if ($urlDomain === '') {
            return $this->error($t->t('cfApi.urlNoHostname'), 400);
        }
        if ($urlDomain !== $domain) {
            return $this->error(
                $t->t('cfApi.urlDomainMismatch', ['urlDomain' => $urlDomain, 'domain' => $domain]),
                400
            );
        }

        $userId = $request->authUserId();
        $draftXml = trim((string) $request->input('xml', ''));
        $draftApplied = false;
        if ($draftXml !== '') {
            $errors = $this->validator->validate($draftXml, $domain);
            if ($errors !== []) {
                return Response::json(['message' => $t->t('cfApi.testFilterInvalid'), 'errors' => $errors], 400);
            }
            $this->repository->setPendingUserCustom($userId, $domain, $draftXml);
            $draftApplied = true;
        }

        $trace = new ContentFilterTrace();
        try {
            $article = $this->extractor->extract($url, $trace, (string) $userId);
        } catch (\Throwable $e) {
            return Response::json([
                'message' => $e->getMessage(),
                'url' => $url,
                'domain' => $domain,
                'draft' => $draftApplied,
                'trace' => $trace->toArray(),
            ], 400);
        }

        $published = $article['publishedAt'] ?? null;

        return Response::json([
            'url' => $url,
            'domain' => $domain,
            'draft' => $draftApplied,
            'result' => [
                'title' => $article['title'] ?? '',
                'author' => $article['author'] ?? null,
                'excerpt' => $article['excerpt'] ?? null,
                'siteName' => $article['siteName'] ?? null,
                'imageUrl' => $article['imageUrl'] ?? null,
                'category' => $article['category'] ?? null,
                'readingTime' => $article['readingTime'] ?? 0,
                'publishedAt' => $published instanceof \DateTimeInterface ? $published->format(\DateTimeInterface::ATOM) : null,
                'content' => $article['content'] ?? '',
            ],
            'trace' => $trace->toArray(),
            'summary' => [
                'rules' => count($trace->toArray()),
                'misses' => $trace->countMisses(),
                'errors' => $trace->countErrors(),
            ],
        ]);
    }

    private function error(string $message, int $status): Response {
        return Response::json(['message' => $message], $status);
    }

    private function translator(Request $request): Translator {
        return Translator::forRequest($request, $this->sessions);
    }
}
