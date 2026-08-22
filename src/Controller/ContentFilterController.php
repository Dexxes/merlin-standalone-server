<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Db\ContentFilterRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\ContentFilterMerger;
use Merlin\Service\ContentFilterSchema;
use Merlin\Service\ContentFilterTrace;
use Merlin\Service\ContentFilterValidator;
use Psr\Log\LoggerInterface;

/**
 * Verwaltung des Admin-Custom-Layers der Content-Filter (instanzweit,
 * gilt für alle Nutzer). Vereinfachter Port von
 * merlin-nextcloud/lib/Controller/ContentFilterController.php: kein
 * JSON-Regel-Builder (kein Vue in merlin-server) - der Custom-Filter wird
 * direkt als rohes XML bearbeitet. Ein separater import()-Endpunkt entfällt
 * dadurch: eine neue Domain wird einfach über update() mit noch nicht
 * existierender Domain angelegt.
 */
final class ContentFilterController {
    public function __construct(
        private readonly ContentFilterRepository $repository,
        private readonly ContentFilterValidator $validator,
        private readonly ContentFilterMerger $merger,
        private readonly ContentExtractorService $extractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): Response {
        return Response::json(['filters' => $this->repository->listFilters()]);
    }

    public function show(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error('Ungültiger Domainname.', 400);
        }

        $bundle = $this->repository->readBundle($domain);
        $custom = $this->repository->readAdminCustom($domain);
        if ($bundle === null && $custom === null) {
            return $this->error('Für diese Domain existiert kein Filter.', 404);
        }

        return Response::json($this->describe($domain, $bundle, $custom));
    }

    public function update(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error('Ungültiger Domainname.', 400);
        }

        $xml = (string) $request->input('xml', '');
        $errors = $this->validator->validate($xml, $domain);
        if ($errors !== []) {
            return Response::json(['message' => 'Der Filter ist ungültig.', 'errors' => $errors], 400);
        }

        try {
            $this->repository->saveAdminCustom($domain, $xml, $request->authUserId());
        } catch (\Throwable $e) {
            $this->logger->error('content-filters: Speichern fehlgeschlagen', ['domain' => $domain, 'exception' => $e]);
            return $this->error($e->getMessage(), 500);
        }

        return $this->show($request);
    }

    public function destroy(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error('Ungültiger Domainname.', 400);
        }

        $this->repository->deleteAdminCustom($domain);
        return Response::json(['domain' => $domain, 'deleted' => true]);
    }

    /** Lädt den Admin-Custom-Filter als Datei herunter, ersatzweise den Bundle-Filter. */
    public function export(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error('Ungültiger Domainname.', 400);
        }

        $xml = $this->repository->readAdminCustom($domain) ?? $this->repository->readBundle($domain);
        if ($xml === null) {
            return $this->error('Für diese Domain existiert kein Filter.', 404);
        }

        return Response::download($xml, $domain . '.xml', 'application/xml');
    }

    /**
     * Extrahiert eine Test-URL mit dem Filter dieser Domain (ohne
     * Admin-eigenen User-Override, siehe merlin-nextcloud-Vorbild) und
     * liefert das Ergebnis samt Trefferzahl je Regel. Enthält der Request
     * ein "xml"-Feld, wird DIESER ungespeicherte Entwurf getestet.
     */
    public function test(Request $request): Response {
        $domain = (string) $request->routeParam('domain');
        if (!$this->repository->isValidDomain($domain)) {
            return $this->error('Ungültiger Domainname.', 400);
        }

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return $this->error('Es wurde keine Test-URL übergeben.', 400);
        }

        $urlDomain = $this->repository->normalizeUrlDomain($url);
        if ($urlDomain === '') {
            return $this->error('Die URL enthält keinen Hostnamen.', 400);
        }
        if ($urlDomain !== $domain) {
            return $this->error(
                sprintf('Die URL gehört zu "%s", getestet wird der Filter für "%s".', $urlDomain, $domain),
                400
            );
        }

        $draftXml = trim((string) $request->input('xml', ''));
        $draftApplied = false;
        if ($draftXml !== '') {
            $errors = $this->validator->validate($draftXml, $domain);
            if ($errors !== []) {
                return Response::json(['message' => 'Der zu testende Filter ist ungültig.', 'errors' => $errors], 400);
            }
            $this->repository->setPendingAdminCustom($domain, $draftXml);
            $draftApplied = true;
        }

        $trace = new ContentFilterTrace();
        try {
            $article = $this->extractor->extract($url, $trace, null);
        } catch (\Throwable $e) {
            return Response::json([
                'message' => $e->getMessage(),
                'url' => $url,
                'domain' => $domain,
                'draft' => $draftApplied,
                'trace' => $trace->toArray(),
            ], 400);
        }

        return Response::json($this->buildTestResponse($url, $domain, $draftApplied, $article, $trace));
    }

    /** @param array<string,mixed> $article */
    private function buildTestResponse(string $url, string $domain, bool $draftApplied, array $article, ContentFilterTrace $trace): array {
        $published = $article['publishedAt'] ?? null;

        return [
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
        ];
    }

    private function describe(string $domain, ?string $bundle, ?string $custom): array {
        $payload = [
            'domain' => $domain,
            'bundle' => $bundle,
            'custom' => $custom,
            'merged' => null,
        ];

        try {
            $payload['merged'] = $this->merger->mergeToString($bundle, $custom, $domain, ContentFilterSchema::ORIGIN_ADMIN);
        } catch (\Throwable $e) {
            $payload['mergeError'] = $e->getMessage();
        }

        return $payload;
    }

    private function error(string $message, int $status): Response {
        return Response::json(['message' => $message], $status);
    }
}
