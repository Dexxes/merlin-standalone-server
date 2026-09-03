<?php

declare(strict_types=1);

namespace Merlin\Service;

use Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Löst einen gespeicherten bsky.app-Post-Link zu seinem "Self-Thread" auf:
 * der linearen Kette von Posts, die dieselbe Autorin/derselbe Autor als
 * Antwort auf den eigenen vorherigen Post verfasst hat (das Bluesky-Äquivalent
 * eines Twitter-Threads). Fremde Antworten (andere Autor:innen) sind nie Teil
 * des Ergebnisses.
 *
 * Nutzt ausschließlich die öffentliche, unauthentifizierte AT-Protocol-AppView
 * (public.api.bsky.app) – kein App-Passwort/OAuth nötig, funktioniert für
 * jeden öffentlichen Post.
 *
 * Fail-closed wie VideoStreamResolverService: jeder unerwartete Zustand
 * (Netzwerkfehler, gelöschter Post, geändertes Response-Format, blockierter
 * Thread) liefert null statt einer Exception – ein nicht auflösbarer Thread
 * darf den Artikel-Import nie zum Absturz bringen, ContentExtractorService
 * fällt dann auf die normale Metadaten-Extraktion zurück.
 */
class BlueskyThreadResolverService {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;
	private const API_BASE = 'https://public.api.bsky.app';

	/** Begrenzt die Tiefe des Thread-Walks in beide Richtungen (siehe getPostThread-Parameter unten). */
	private const MAX_DEPTH = 25;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array{
	 *   uri: string, cid: string, text: string,
	 *   authorDid: string, authorHandle: string,
	 *   authorDisplayName: ?string, authorAvatar: ?string,
	 *   createdAt: string, imageUrl: ?string,
	 * }>|null Geordnete Post-Liste (älteste zuerst), oder null wenn die URL
	 *         kein bsky.app-Post ist bzw. die Auflösung fehlschlägt. Ein
	 *         einzelner Post ohne Self-Thread-Fortsetzung liefert eine
	 *         1-elementige Liste (kein Sonderfall nötig).
	 */
	public function resolveSelfThread(string $postUrl): ?array {
		try {
			$ref = $this->parsePostUrl($postUrl);
			if ($ref === null) {
				return null;
			}
			[$actor, $rkey] = $ref;

			$did = str_starts_with($actor, 'did:') ? $actor : $this->resolveHandleToDid($actor);
			if ($did === null) {
				return null;
			}

			$uri = 'at://' . $did . '/app.bsky.feed.post/' . $rkey;
			$thread = $this->fetchThreadView($uri);
			if ($thread === null) {
				return null;
			}

			$authorDid = $thread['post']['author']['did'] ?? null;
			if (!is_string($authorDid) || $authorDid === '') {
				return null;
			}

			$ancestors = $this->collectSelfAncestors($thread, $authorDid);
			$descendants = $this->collectSelfDescendants($thread, $authorDid);

			$posts = [...$ancestors, $thread['post'], ...$descendants];
			return array_map($this->toPostDto(...), $posts);
		} catch (\Throwable $e) {
			$this->logger->info('BlueskyThreadResolverService: Auflösen fehlgeschlagen', [
				'url'       => $postUrl,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * @return array{0: string, 1: string}|null [actor (Handle oder DID), rkey]
	 */
	private function parsePostUrl(string $postUrl): ?array {
		$path = parse_url($postUrl, PHP_URL_PATH);
		if (!is_string($path)) {
			return null;
		}
		if (!preg_match('#^/profile/([^/]+)/post/([A-Za-z0-9]+)$#', $path, $m)) {
			return null;
		}
		return [rawurldecode($m[1]), $m[2]];
	}

	private function resolveHandleToDid(string $handle): ?string {
		$json = $this->httpGetJson(
			self::API_BASE . '/xrpc/com.atproto.identity.resolveHandle?' . http_build_query(['handle' => $handle])
		);
		$did = $json['did'] ?? null;
		return is_string($did) && $did !== '' ? $did : null;
	}

	/**
	 * @return array<mixed>|null Der "thread"-Knoten der API-Antwort, nur wenn
	 *                           er ein regulärer threadViewPost ist (kein
	 *                           notFoundPost/blockedPost).
	 */
	private function fetchThreadView(string $atUri): ?array {
		$json = $this->httpGetJson(
			self::API_BASE . '/xrpc/app.bsky.feed.getPostThread?' . http_build_query([
				'uri'          => $atUri,
				'depth'        => self::MAX_DEPTH,
				'parentHeight' => self::MAX_DEPTH,
			])
		);
		$thread = $json['thread'] ?? null;
		if (!is_array($thread) || ($thread['$type'] ?? '') !== 'app.bsky.feed.defs#threadViewPost') {
			return null;
		}
		return $thread;
	}

	/**
	 * Läuft vom gegebenen Thread-Knoten über <parent> aufwärts, solange die
	 * Autorin/der Autor mit $authorDid übereinstimmt. Bricht beim ersten
	 * fremden Autor, notFoundPost/blockedPost oder fehlendem Parent ab.
	 *
	 * @return list<array<mixed>> Posts in chronologischer Reihenfolge (älteste zuerst)
	 */
	private function collectSelfAncestors(array $thread, string $authorDid): array {
		$ancestors = [];
		$node = $thread;
		while (
			isset($node['parent'])
			&& is_array($node['parent'])
			&& ($node['parent']['$type'] ?? '') === 'app.bsky.feed.defs#threadViewPost'
			&& ($node['parent']['post']['author']['did'] ?? null) === $authorDid
		) {
			$node = $node['parent'];
			array_unshift($ancestors, $node['post']);
		}
		return $ancestors;
	}

	/**
	 * Läuft vom gegebenen Thread-Knoten über <replies> abwärts, folgt an
	 * jedem Punkt nur der frühesten Antwort derselben Autorin/desselben
	 * Autors. Bricht beim ersten fremden Autor, notFoundPost/blockedPost
	 * oder Ende der Kette ab. Verzweigte Self-Reply-Bäume (mehrere eigene
	 * Antworten auf denselben Post) werden bewusst nicht abgebildet - nur
	 * der lineare Pfad.
	 *
	 * @return list<array<mixed>> Posts in chronologischer Reihenfolge
	 */
	private function collectSelfDescendants(array $thread, string $authorDid): array {
		$descendants = [];
		$node = $thread;
		while (true) {
			$next = null;
			foreach (($node['replies'] ?? []) as $reply) {
				if (!is_array($reply) || ($reply['$type'] ?? '') !== 'app.bsky.feed.defs#threadViewPost') {
					continue;
				}
				if (($reply['post']['author']['did'] ?? null) !== $authorDid) {
					continue;
				}
				$candidateCreatedAt = $reply['post']['record']['createdAt'] ?? '';
				$nextCreatedAt = $next['post']['record']['createdAt'] ?? null;
				if ($next === null || $candidateCreatedAt < $nextCreatedAt) {
					$next = $reply;
				}
			}
			if ($next === null) {
				break;
			}
			$descendants[] = $next['post'];
			$node = $next;
		}
		return $descendants;
	}

	/**
	 * @param array<mixed> $post
	 * @return array{
	 *   uri: string, cid: string, text: string,
	 *   authorDid: string, authorHandle: string,
	 *   authorDisplayName: ?string, authorAvatar: ?string,
	 *   createdAt: string, imageUrl: ?string,
	 * }
	 */
	private function toPostDto(array $post): array {
		return [
			'uri'               => (string) ($post['uri'] ?? ''),
			'cid'               => (string) ($post['cid'] ?? ''),
			'text'              => (string) ($post['record']['text'] ?? ''),
			'authorDid'         => (string) ($post['author']['did'] ?? ''),
			'authorHandle'      => (string) ($post['author']['handle'] ?? ''),
			'authorDisplayName' => $post['author']['displayName'] ?? null,
			'authorAvatar'      => $post['author']['avatar'] ?? null,
			'createdAt'         => (string) ($post['record']['createdAt'] ?? ($post['indexedAt'] ?? '')),
			'imageUrl'          => $this->firstEmbedImage($post['embed'] ?? null),
		];
	}

	/**
	 * @param mixed $embed
	 */
	private function firstEmbedImage($embed): ?string {
		if (!is_array($embed)) {
			return null;
		}
		$images = $embed['images'] ?? ($embed['media']['images'] ?? null);
		if (!is_array($images) || !isset($images[0]['thumb']) || !is_string($images[0]['thumb'])) {
			return null;
		}
		return $images[0]['thumb'];
	}

	/**
	 * Minimaler SSRF-abgesicherter JSON-GET (einzelner Hop, keine
	 * Redirect-Verfolgung): anders als ContentExtractorService::
	 * httpRequestFollowingRedirects() rufen wir hier ausschließlich den fest
	 * hinterlegten public.api.bsky.app-Host auf, nie eine vom Nutzer
	 * stammende URL direkt - die IP-Prüfung/Pinning-Logik aus dem
	 * SsrfSafeResolver-Trait bleibt trotzdem als Defense-in-Depth sinnvoll
	 * (siehe VideoStreamResolverService::httpJsonRequest(), gleiches Muster).
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
