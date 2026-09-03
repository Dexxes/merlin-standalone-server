<?php

declare(strict_types=1);

namespace Merlin\Service;

use Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Löst einen gespeicherten Mastodon-Post-Link zu seinem "Self-Thread" auf:
 * der linearen Kette von Posts, die dieselbe Autorin/derselbe Autor als
 * Antwort auf den eigenen vorherigen Post verfasst hat. Fremde Antworten
 * (andere Autor:innen) sind nie Teil des Ergebnisses.
 *
 * Anders als bsky.app (fester Domain-Filter, siehe BlueskyThreadResolverService)
 * ist Mastodon föderiert - jede Instanz ist eine andere Domain, es gibt also
 * keine feste content-filters/{domain}.xml dafür. Die Erkennung läuft daher
 * domain-unabhängig über die URL-Form "/@user/12345…" (siehe looksLikeMastodonPostUrl()),
 * angewendet auf jede Domain OHNE eigenen Content-Filter (siehe
 * ContentExtractorService::processHtml()).
 *
 * Nutzt ausschließlich die öffentliche, unauthentifizierte Mastodon-REST-API
 * (/api/v1/statuses/{id} und /context) - Teil der offiziellen, dokumentierten
 * Mastodon-API, kein App-Token nötig für öffentliche Posts.
 *
 * Fail-closed wie BlueskyThreadResolverService/VideoStreamResolverService:
 * jeder unerwartete Zustand liefert null statt einer Exception.
 */
class MastodonPostResolverService {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * true, wenn der Pfad die Form "/@user/12345…" hat (Mastodons Status-URL-
	 * Schema, instanzübergreifend identisch). Rein syntaktisch - ob die Domain
	 * tatsächlich eine Mastodon-Instanz ist, entscheidet erst der API-Aufruf
	 * in resolveSelfThread().
	 */
	public function looksLikeMastodonPostUrl(string $url): bool {
		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && preg_match('#^/@[\w.]+/\d+$#', $path) === 1;
	}

	/**
	 * @return list<array{
	 *   id: string, url: string, contentHtml: string,
	 *   authorDisplayName: ?string, authorHandle: string, authorAvatar: ?string,
	 *   createdAt: string, imageUrls: list<string>,
	 * }>|null Geordnete Post-Liste (älteste zuerst), oder null wenn die URL
	 *         syntaktisch kein Mastodon-Post ist, die Domain sich als keine
	 *         (erreichbare) Mastodon-Instanz herausstellt, oder die Auflösung
	 *         sonst fehlschlägt. Ein einzelner Post ohne Self-Thread-
	 *         Fortsetzung liefert eine 1-elementige Liste.
	 */
	public function resolveSelfThread(string $url): ?array {
		try {
			$ref = $this->parsePostUrl($url);
			if ($ref === null) {
				return null;
			}
			[$origin, $statusId] = $ref;

			$target = $this->fetchStatus($origin, $statusId);
			if ($target === null) {
				return null;
			}

			$authorId = $target['account']['id'] ?? null;
			if (!is_string($authorId) || $authorId === '') {
				return null;
			}

			$context = $this->fetchContext($origin, $statusId);
			$ancestors   = is_array($context['ancestors'] ?? null) ? $context['ancestors'] : [];
			$descendants = is_array($context['descendants'] ?? null) ? $context['descendants'] : [];

			$selfAncestors = $this->collectSelfAncestors($ancestors, $authorId);
			$selfDescendants = $this->collectSelfDescendants($descendants, $statusId, $authorId);

			$posts = [...$selfAncestors, $target, ...$selfDescendants];
			return array_map(fn(array $p) => $this->toPostDto($p, $origin), $posts);
		} catch (\Throwable $e) {
			$this->logger->info('MastodonPostResolverService: Auflösen fehlgeschlagen', [
				'url'       => $url,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * @return array{0: string, 1: string}|null [origin (https://instanz), statusId]
	 */
	private function parsePostUrl(string $url): ?array {
		if (!$this->looksLikeMastodonPostUrl($url)) {
			return null;
		}
		$parts = parse_url($url);
		if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
			return null;
		}
		$path = $parts['path'] ?? '';
		if (!preg_match('#^/@[\w.]+/(\d+)$#', $path, $m)) {
			return null;
		}
		$origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		return [$origin, $m[1]];
	}

	/**
	 * @return array<mixed>|null
	 */
	private function fetchStatus(string $origin, string $statusId): ?array {
		$json = $this->httpGetJson($origin . '/api/v1/statuses/' . rawurlencode($statusId));
		if (!is_array($json) || !isset($json['id'], $json['account']['id'])) {
			return null;
		}
		return $json;
	}

	/**
	 * @return array{ancestors?: array<mixed>, descendants?: array<mixed>}|null
	 */
	private function fetchContext(string $origin, string $statusId): ?array {
		$json = $this->httpGetJson($origin . '/api/v1/statuses/' . rawurlencode($statusId) . '/context');
		return is_array($json) ? $json : null;
	}

	/**
	 * $ancestors ist chronologisch (älteste zuerst) bis direkt vor den
	 * Zielpost geordnet. Gesucht ist das Suffix derselben Autorin/desselben
	 * Autors - also rückwärts ab dem Ende laufen, bis der erste fremde Autor
	 * abbricht.
	 *
	 * @param list<array<mixed>> $ancestors
	 * @return list<array<mixed>>
	 */
	private function collectSelfAncestors(array $ancestors, string $authorId): array {
		$selected = [];
		for ($i = count($ancestors) - 1; $i >= 0; $i--) {
			$post = $ancestors[$i];
			if (!is_array($post) || ($post['account']['id'] ?? null) !== $authorId) {
				break;
			}
			array_unshift($selected, $post);
		}
		return $selected;
	}

	/**
	 * $descendants ist eine flache Liste des GESAMTEN Reply-Baums (nicht nur
	 * eigene Antworten), verknüpft über in_reply_to_id. Läuft die Kette ab
	 * dem Zielpost entlang, folgt an jedem Punkt nur der frühesten Antwort
	 * derselben Autorin/desselben Autors. Bricht ab, sobald keine passende
	 * nächste Antwort mehr gefunden wird. Verzweigte Self-Reply-Bäume werden
	 * bewusst nicht abgebildet - nur der lineare Pfad (wie beim
	 * Bluesky-Pendant).
	 *
	 * @param list<array<mixed>> $descendants
	 * @return list<array<mixed>>
	 */
	private function collectSelfDescendants(array $descendants, string $targetId, string $authorId): array {
		$selected = [];
		$currentId = $targetId;
		while (true) {
			$next = null;
			foreach ($descendants as $post) {
				if (!is_array($post)) {
					continue;
				}
				if ((string) ($post['in_reply_to_id'] ?? '') !== $currentId) {
					continue;
				}
				if (($post['account']['id'] ?? null) !== $authorId) {
					continue;
				}
				$candidateCreatedAt = (string) ($post['created_at'] ?? '');
				$nextCreatedAt = $next !== null ? (string) ($next['created_at'] ?? '') : null;
				if ($next === null || $candidateCreatedAt < $nextCreatedAt) {
					$next = $post;
				}
			}
			if ($next === null) {
				break;
			}
			$selected[] = $next;
			$currentId = (string) $next['id'];
		}
		return $selected;
	}

	/**
	 * @param array<mixed> $post
	 * @return array{
	 *   id: string, url: string, contentHtml: string,
	 *   authorDisplayName: ?string, authorHandle: string, authorAvatar: ?string,
	 *   createdAt: string, imageUrls: list<string>,
	 * }
	 */
	private function toPostDto(array $post, string $origin): array {
		$account = is_array($post['account'] ?? null) ? $post['account'] : [];
		$handle  = (string) ($account['acct'] ?? ($account['username'] ?? ''));

		$imageUrls = [];
		foreach (($post['media_attachments'] ?? []) as $media) {
			if (!is_array($media) || ($media['type'] ?? '') !== 'image') {
				continue;
			}
			$src = $media['preview_url'] ?? ($media['url'] ?? null);
			if (is_string($src) && $src !== '') {
				$imageUrls[] = $src;
			}
		}

		return [
			'id'                => (string) ($post['id'] ?? ''),
			// Eigene, kanonische Permalink-Form statt des API-"url"-Felds: das
			// kann bei manchen Post-Arten (z. B. reinen Boosts) auf eine
			// ActivityPub-Activity-URI statt der menschenlesbaren Seite zeigen.
			'url'               => $origin . '/@' . rawurlencode($handle !== '' ? $handle : 'unbekannt') . '/' . (string) ($post['id'] ?? ''),
			'contentHtml'       => (string) ($post['content'] ?? ''),
			'authorDisplayName' => $account['display_name'] ?? null,
			'authorHandle'      => $handle,
			'authorAvatar'      => $account['avatar'] ?? null,
			'createdAt'         => (string) ($post['created_at'] ?? ''),
			'imageUrls'         => $imageUrls,
		];
	}

	/**
	 * Minimaler SSRF-abgesicherter JSON-GET (einzelner Hop, keine
	 * Redirect-Verfolgung) - gleiches Muster wie
	 * BlueskyThreadResolverService::httpGetJson(), nur mit variablem Host
	 * statt einem fest hinterlegten (jede Mastodon-Instanz ist eine andere
	 * Domain). Die IP-Prüfung/Pinning-Logik aus dem SsrfSafeResolver-Trait
	 * bleibt trotzdem Pflicht statt Kür: anders als bei den anderen Resolvern
	 * kommt der Host hier aus einer vom Nutzer gespeicherten URL, nicht aus
	 * einem fest verdrahteten String.
	 *
	 * @return array<mixed>|null
	 */
	private function httpGetJson(string $url): ?array {
		$parsed = parse_url($url);
		$host   = $parsed['host'] ?? '';
		$scheme = strtolower($parsed['scheme'] ?? '');
		$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

		$ips  = $this->assertPublicHostAndResolve($url);
		$pins = $this->buildResolvePin($host, $port, $ips);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_RESOLVE        => $pins,
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Merlin/1.0)',
		]);

		$response  = curl_exec($ch);
		$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($response === false || $curlError !== '') {
			return null;
		}
		if ($httpCode < 200 || $httpCode >= 300) {
			return null;
		}

		try {
			$decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
