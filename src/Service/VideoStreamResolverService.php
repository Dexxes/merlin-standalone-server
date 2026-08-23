<?php

declare(strict_types=1);

namespace Merlin\Service;

use Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Löst die aktuelle HLS-Stream-URL für ARD-Mediathek-, ZDF- und Arte-Artikel
 * über deren interne, nicht öffentlich dokumentierte Player-APIs auf – analog
 * zu dem, was Tools wie yt-dlp/streamlink tun (deren Extraktoren dienten als
 * Referenz für die Endpunkte/JSON-Pfade unten).
 *
 * BEWUSSTE PRODUKTENTSCHEIDUNG, nicht Versehen: Anders als die offiziellen
 * iFrame-/Widget-Embeds (siehe ContentExtractorService::isAllowedVideoEmbedSrc())
 * ist das hier kein von den Sendern autorisierter Einbettungsweg. ZDFs
 * Nutzungsbedingungen verlangen für Vervielfältigung/Speicherung/Verbreitung
 * ihrer Inhalte vorherige schriftliche Zustimmung; das OLG Köln hat einem
 * privaten Anbieter genau diese Art der Weiterverwendung von ARD-Mediathek-
 * Inhalten gerichtlich untersagt. Der Nutzer wurde auf dieses Risiko
 * hingewiesen und hat sich bewusst dafür entschieden, es zu tragen. Um den
 * Eingriff so klein wie möglich zu halten: keine Speicherung, kein Download,
 * kein dauerhafter Proxy/Cache – nur ein transientes Auflösen der vom Sender
 * selbst gelieferten Stream-URL für die Dauer eines einzelnen Requests, die
 * der Browser direkt vom Sender-CDN lädt.
 *
 * Fail-closed durchgängig: jeder unerwartete API-Zustand (Netzwerkfehler,
 * geänderte Response-Form, Altersprüfung/Geoblocking) liefert null statt
 * einer Exception – ein nicht auflösbares Video darf den Artikel-Reader nie
 * zum Absturz bringen, es fehlt dann einfach der Player.
 */
class VideoStreamResolverService {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{type: 'hls', url: string}|null
	 */
	public function resolve(string $articleUrl): ?array {
		$host = strtolower((string) parse_url($articleUrl, PHP_URL_HOST));
		if ($host === '') {
			return null;
		}

		try {
			if ($this->hostMatches($host, 'ardmediathek.de')) {
				return $this->resolveArd($articleUrl);
			}
			if ($this->hostMatches($host, 'zdf.de')) {
				return $this->resolveZdf($articleUrl);
			}
			if ($this->hostMatches($host, 'arte.tv')) {
				return $this->resolveArte($articleUrl);
			}
		} catch (\Throwable $e) {
			// Fail-closed: jeder Fehler (Netzwerk, JSON, unerwartete Struktur)
			// bedeutet einfach "kein Player", nie einen kaputten Reader.
			$this->logger->info('VideoStreamResolverService: Auflösen fehlgeschlagen', [
				'url'       => $articleUrl,
				'exception' => $e,
			]);
			return null;
		}

		return null;
	}

	private function hostMatches(string $host, string $domain): bool {
		return $host === $domain || str_ends_with($host, '.' . $domain);
	}

	// ──────────────────────────────────────────────────────────────────────
	// ARD Mediathek
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * ARD-Artikel-URLs enden auf die für die page-gateway-API nutzbare ID
	 * (Base64url-artiger "crid"), z. B.
	 * https://www.ardmediathek.de/video/<show>/<titel>/<sender>/<id>.
	 */
	private function resolveArd(string $articleUrl): ?array {
		$path = (string) parse_url($articleUrl, PHP_URL_PATH);
		$segments = array_values(array_filter(explode('/', $path), static fn (string $s) => $s !== ''));
		$id = end($segments) ?: null;
		if ($id === null || preg_match('/^[A-Za-z0-9_-]{5,64}$/', $id) !== 1) {
			return null;
		}

		$json = $this->httpGetJson(
			'https://api.ardmediathek.de/page-gateway/pages/ard/item/' . rawurlencode($id)
				. '?embedded=false&mcV6=true',
		);
		if ($json === null) {
			return null;
		}

		$widgets = $json['widgets'] ?? null;
		if (!is_array($widgets)) {
			return null;
		}

		foreach ($widgets as $widget) {
			if (!is_array($widget)) {
				continue;
			}
			$type = $widget['type'] ?? '';
			if ($type !== 'player_ondemand' && $type !== 'player_live') {
				continue;
			}
			$streams = $widget['mediaCollection']['embedded']['streams'] ?? null;
			if (!is_array($streams)) {
				continue;
			}
			foreach ($streams as $stream) {
				$mediaList = $stream['media'] ?? null;
				if (!is_array($mediaList)) {
					continue;
				}
				foreach ($mediaList as $media) {
					$url = $media['url'] ?? null;
					if (is_string($url) && $this->looksLikeHlsUrl($url)) {
						return $this->validated($url);
					}
				}
			}
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────
	// ZDF
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * ZDF braucht ein kurzlebiges Bootstrap-Token (Api-Auth-Header) für den
	 * eigentlichen GraphQL-/PTMD-Aufruf. Wird bewusst pro Request frisch
	 * geholt statt gecacht: PHP läuft hier stateless pro Request, ein
	 * Datei-/DB-Cache für ein einzelnes zusätzliches, kurzes HTTP-Roundtrip
	 * wäre mehr Komplexität als der Aufruf selbst kostet.
	 *
	 * Am unsichersten von den drei Sendern recherchiert (ZDFs GraphQL-Schema
	 * war aus der Analyse nicht 1:1 rekonstruierbar) – deshalb besonders
	 * defensiv: jede Abweichung von der erwarteten Struktur → null.
	 */
	private function resolveZdf(string $articleUrl): ?array {
		$id = $this->extractZdfId($articleUrl);
		if ($id === null) {
			return null;
		}

		$tokenResponse = $this->httpGetJson('https://zdf-prod-futura.zdf.de/mediathekV2/token');
		$tokenType  = $tokenResponse['type'] ?? null;
		$tokenValue = $tokenResponse['token'] ?? null;
		if (!is_string($tokenType) || !is_string($tokenValue) || $tokenValue === '') {
			return null;
		}
		$authHeader = trim($tokenType . ' ' . $tokenValue);

		$graphqlQuery = <<<'GRAPHQL'
			query VideoByCanonical($id: String!) {
				smartCollectionByCanonical(canonical: $id) {
					mainVideoContent {
						nodes {
							... on Video {
								currentMedia {
									nodes {
										... on Video {
											streams {
												ptmdTemplate
											}
										}
									}
								}
							}
						}
					}
				}
			}
			GRAPHQL;

		$graphqlResponse = $this->httpPostJson(
			'https://api.zdf.de/graphql',
			['query' => $graphqlQuery, 'variables' => ['id' => $id]],
			['Api-Auth: ' . $authHeader],
		);
		if ($graphqlResponse === null) {
			return null;
		}

		$ptmdTemplate = $this->findFirstStringValue($graphqlResponse, 'ptmdTemplate');
		if ($ptmdTemplate === null) {
			return null;
		}

		$ptmdUrl = str_replace('{playerId}', 'android_native_6', $ptmdTemplate);
		if (!$this->looksLikeAllowedApiHost($ptmdUrl, ['api.zdf.de'])) {
			return null;
		}

		$ptmd = $this->httpGetJson($ptmdUrl, ['Api-Auth: ' . $authHeader]);
		if ($ptmd === null) {
			return null;
		}

		$priorityList = $ptmd['priorityList'] ?? null;
		if (!is_array($priorityList)) {
			return null;
		}
		foreach ($priorityList as $priority) {
			$formitaeten = $priority['formitaeten'] ?? null;
			if (!is_array($formitaeten)) {
				continue;
			}
			foreach ($formitaeten as $formitaet) {
				$qualities = $formitaet['qualities'] ?? null;
				if (!is_array($qualities)) {
					continue;
				}
				foreach ($qualities as $quality) {
					$tracks = $quality['audio']['tracks'] ?? null;
					if (!is_array($tracks)) {
						continue;
					}
					foreach ($tracks as $track) {
						$uri = $track['uri'] ?? null;
						if (is_string($uri) && $this->looksLikeHlsUrl($uri)) {
							return $this->validated($uri);
						}
					}
				}
			}
		}

		return null;
	}

	private function extractZdfId(string $articleUrl): ?string {
		$path = (string) parse_url($articleUrl, PHP_URL_PATH);
		if (preg_match('#/(?:video|play)/(?:[^/]+/)*([^/]+?)(?:\.html)?/?$#', $path, $m) === 1
			&& preg_match('/^[A-Za-z0-9_-]{3,120}$/', $m[1]) === 1) {
			return $m[1];
		}
		if (preg_match('#/([^/]+)\.html$#', $path, $m) === 1
			&& preg_match('/^[A-Za-z0-9_-]{3,120}$/', $m[1]) === 1) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Sucht rekursiv den ersten String-Wert unter dem Schlüssel $key –
	 * bewusst strukturunabhängig statt an einen genauen GraphQL-Response-Pfad
	 * gebunden, weil dessen exakte Verschachtelung unsicher recherchiert ist
	 * (siehe Klassen-Docblock). Robuster gegen kleinere Schema-Abweichungen
	 * als ein starrer Pfad, ohne die Fail-closed-Eigenschaft aufzugeben: kein
	 * Treffer bleibt einfach null.
	 */
	private function findFirstStringValue(mixed $data, string $key): ?string {
		if (!is_array($data)) {
			return null;
		}
		foreach ($data as $k => $v) {
			if ($k === $key && is_string($v) && $v !== '') {
				return $v;
			}
			if (is_array($v)) {
				$found = $this->findFirstStringValue($v, $key);
				if ($found !== null) {
					return $found;
				}
			}
		}
		return null;
	}

	// ──────────────────────────────────────────────────────────────────────
	// Arte
	// ──────────────────────────────────────────────────────────────────────

	private function resolveArte(string $articleUrl): ?array {
		if (preg_match('#(\d{6}-\d{3}-[AF]|LIVE)#', $articleUrl, $m) !== 1) {
			return null;
		}
		$videoId = $m[1];

		$lang = 'de';
		if (preg_match('#arte\.tv/([a-z]{2})/#i', $articleUrl, $langMatch) === 1) {
			$candidate = strtolower($langMatch[1]);
			if (preg_match('/^[a-z]{2}$/', $candidate) === 1) {
				$lang = $candidate;
			}
		}

		$json = $this->httpGetJson(
			'https://api.arte.tv/api/player/v2/config/' . rawurlencode($lang) . '/' . rawurlencode($videoId),
			['x-validated-age: 18'],
		);
		if ($json === null || isset($json['error'])) {
			return null;
		}

		$streams = $json['data']['attributes']['streams'] ?? null;
		if (!is_array($streams)) {
			return null;
		}

		foreach ($streams as $stream) {
			$protocol = strtoupper((string) ($stream['protocol'] ?? ''));
			$url = $stream['url'] ?? null;
			if (str_starts_with($protocol, 'HLS') && is_string($url) && $this->looksLikeHlsUrl($url)) {
				return $this->validated($url);
			}
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────
	// Gemeinsame Helfer
	// ──────────────────────────────────────────────────────────────────────

	private function looksLikeHlsUrl(string $url): bool {
		$path = (string) parse_url($url, PHP_URL_PATH);
		return str_starts_with($url, 'https://') && str_ends_with(strtolower($path), '.m3u8');
	}

	/**
	 * @param list<string> $allowedHosts
	 */
	private function looksLikeAllowedApiHost(string $url, array $allowedHosts): bool {
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}
		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}
		return in_array(strtolower($parts['host']), $allowedHosts, true);
	}

	/**
	 * @return array{type: 'hls', url: string}|null
	 */
	private function validated(string $url): ?array {
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
			return null;
		}
		return ['type' => 'hls', 'url' => $url];
	}

	/**
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	private function httpGetJson(string $url, array $extraHeaders = []): ?array {
		return $this->httpJsonRequest($url, 'GET', null, $extraHeaders);
	}

	/**
	 * @param array<string, mixed> $body
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	private function httpPostJson(string $url, array $body, array $extraHeaders = []): ?array {
		return $this->httpJsonRequest($url, 'POST', json_encode($body, JSON_THROW_ON_ERROR), $extraHeaders);
	}

	/**
	 * Minimaler SSRF-abgesicherter JSON-Request (einzelner Hop, keine
	 * Redirect-Verfolgung): anders als ContentExtractorService::
	 * httpRequestFollowingRedirects() rufen wir hier ausschließlich fest
	 * hinterlegte Sender-API-Hosts auf, nie eine vom Nutzer stammende URL
	 * direkt – das komplexere Redirect-Hardening dort ist für diesen
	 * Anwendungsfall nicht nötig, die IP-Prüfung/Pinning-Logik aus dem
	 * SsrfSafeResolver-Trait aber trotzdem als Defense-in-Depth sinnvoll.
	 *
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	private function httpJsonRequest(string $url, string $method, ?string $body, array $extraHeaders): ?array {
		$parsed = parse_url($url);
		$host   = $parsed['host'] ?? '';
		$scheme = strtolower($parsed['scheme'] ?? '');
		$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

		$ips  = $this->assertPublicHostAndResolve($url);
		$pins = $this->buildResolvePin($host, $port, $ips);

		$ch = curl_init($url);
		$headers = array_merge(['Accept: application/json'], $extraHeaders);
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}

		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_RESOLVE        => $pins,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Merlin/1.0)',
			CURLOPT_CUSTOMREQUEST  => $method,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($ch, $opts);

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
