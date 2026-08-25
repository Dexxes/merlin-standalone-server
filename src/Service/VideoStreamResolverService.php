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

	/**
	 * Substrings (kleingeschrieben), die eine Variante als "nicht der
	 * Standard" markieren - z. B. Gebärdensprache oder Audiodeskription.
	 * Solche Varianten sind für Sehende/Hörende meist unpraktisch als
	 * Voreinstellung (eingeblendete/r Dolmetscher:in, zusätzliche
	 * Beschreibungs-Tonspur), bleiben aber über das Dropdown wählbar.
	 */
	private const SPECIAL_VARIANT_KEYWORDS = [
		'dgs', 'gebärden', 'gebarden', 'sign',
		'audiodeskription', 'hörfilm', 'horfilm', 'audio description',
	];

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{type: 'hls', variants: list<array{label: string, url: string}>, defaultIndex: int}|null
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
		// Die crid ist base64url("crid://<domain>/<uuid>") - je nach Domain-Länge
		// deutlich länger als eine kurze ID (z. B. "sportschau.de" ergibt 76
		// Zeichen). 64 war hier zu eng geraten und hat echte ARD-URLs verworfen,
		// bevor die API überhaupt angefragt wurde - 300 lässt genug Luft für
		// jede realistische Domain, bleibt aber eine Obergrenze gegen absurd
		// lange Eingaben.
		if ($id === null || preg_match('/^[A-Za-z0-9_-]{5,300}$/', $id) !== 1) {
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

		// Ein Stream-Eintrag pro verfügbarer Variante (kind/kindName, z. B.
		// "main"/"Normal" vs. "signLanguage"/"Gebärdensprache") - alle
		// sammeln statt nur die erste zu nehmen, damit das Frontend sie zur
		// Auswahl anbieten kann (siehe buildVariantResult()).
		$variants = [];
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
				if (!is_array($stream)) {
					continue;
				}
				$mediaList = $stream['media'] ?? null;
				if (!is_array($mediaList)) {
					continue;
				}
				$label = $this->firstNonEmptyString([$stream['kindName'] ?? null, $stream['kind'] ?? null]) ?? 'Standard';
				foreach ($mediaList as $media) {
					$url = $media['url'] ?? null;
					if (is_string($url) && $this->looksLikeHlsUrl($url)) {
						// Nur die erste m3u8 pro Stream-Gruppe - die mp4-
						// Fallback-Auflösungen derselben Gruppe sind keine
						// eigenständige Variante, sondern dieselbe Quelle.
						$variants[] = ['label' => $label, 'url' => $url];
						break;
					}
				}
			}
		}

		return $this->buildVariantResult($variants);
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
	 * War beim ersten Implementieren die unsicherste Rekonstruktion der drei
	 * Sender - inzwischen anhand des öffentlichen ZDF-Extractors in yt-dlp
	 * (yt_dlp/extractor/zdf.py) verifiziert: Root-Feld ist videoByCanonical()
	 * mit Variable $canonical (nicht smartCollectionByCanonical/$id, wie
	 * ursprünglich geraten), ptmdTemplate hängt direkt unter
	 * currentMedia.nodes (nicht unter einem zusätzlichen streams-Feld), und
	 * der Wert ist ein SERVER-RELATIVER Pfad ("/tmd/2/{playerId}/...") - erst
	 * nach dem Voranstellen von "https://api.zdf.de" ist er eine gültige URL.
	 * Trotzdem weiterhin defensiv: jede Abweichung von der erwarteten
	 * Struktur → null statt Exception.
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

		// label/vodMediaType (z. B. "Normal"/"DEFAULT" vs. "DGS"/"DGS" für
		// Deutsche Gebärdensprache) leben nur auf VodMedia - LiveMedia hat
		// diese Felder nicht, deshalb der Inline-Fragment statt sie direkt
		// auf dem Interface abzufragen.
		$graphqlQuery = <<<'GRAPHQL'
			query VideoByCanonical($canonical: String!) {
				videoByCanonical(canonical: $canonical) {
					canonical
					currentMedia {
						nodes {
							ptmdTemplate
							... on VodMedia {
								label
								vodMediaType
							}
						}
					}
				}
			}
			GRAPHQL;

		$graphqlResponse = $this->httpPostJson(
			'https://api.zdf.de/graphql',
			[
				'operationName' => 'VideoByCanonical',
				'query'         => $graphqlQuery,
				'variables'     => ['canonical' => $id],
			],
			['Api-Auth: ' . $authHeader],
		);
		if ($graphqlResponse === null) {
			return null;
		}

		// currentMedia.nodes enthält typischerweise 1-3 Einträge - eine
		// Standardvariante ("DEFAULT") und optional weitere wie "DGS"
		// (Gebärdensprache) oder eine Hörfilm-/Audiodeskriptionsfassung.
		// Für jede wird separat die PTMD abgefragt, damit alle als Variante
		// im Frontend-Dropdown zur Auswahl stehen (siehe buildVariantResult()).
		$nodes = $graphqlResponse['data']['videoByCanonical']['currentMedia']['nodes'] ?? null;
		if (!is_array($nodes)) {
			return null;
		}

		$variants = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			$ptmdTemplate = $node['ptmdTemplate'] ?? null;
			// Nur ein Pfad-Präfix mit erwartetem Schema erlauben, bevor die
			// feste api.zdf.de-Basis vorangestellt wird - verhindert, dass
			// eine unerwartete absolute URL im Template (z. B. durch eine
			// künftige API-Änderung) versehentlich auf einen fremden Host
			// zeigen könnte.
			if (!is_string($ptmdTemplate) || !str_starts_with($ptmdTemplate, '/')) {
				continue;
			}
			$ptmdUrl = 'https://api.zdf.de' . str_replace('{playerId}', 'android_native_6', $ptmdTemplate);
			if (!$this->looksLikeAllowedApiHost($ptmdUrl, ['api.zdf.de'])) {
				continue;
			}

			$ptmd = $this->httpGetJson($ptmdUrl, ['Api-Auth: ' . $authHeader]);
			if ($ptmd === null) {
				continue;
			}
			$url = $this->findFirstHlsUrlInZdfPtmd($ptmd);
			if ($url === null) {
				continue;
			}

			$label = $this->firstNonEmptyString([$node['label'] ?? null, $node['vodMediaType'] ?? null]) ?? 'Standard';
			$variants[] = ['label' => $label, 'url' => $url];
		}

		return $this->buildVariantResult($variants);
	}

	/**
	 * Durchsucht eine ZDF-PTMD-Antwort nach der ersten m3u8-URL
	 * (priorityList[].formitaeten[].qualities[].audio.tracks[].uri).
	 */
	private function findFirstHlsUrlInZdfPtmd(array $ptmd): ?string {
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
							return $uri;
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

		// Sammelseiten wie "/kurzfassungen/<slug>-100" referenzieren das
		// tatsächlich gemeinte Video nicht über den Pfad, sondern über einen
		// URL-Fragment-Anker "#focus=<video-slug>-100" (per Klick auf einen
		// einzelnen Clip innerhalb der Seite gesetzt). Fragmente werden vom
		// Browser nie an den Server geschickt, stehen aber in der beim
		// Speichern erfassten article.url, also hier zusätzlich auswerten.
		$fragment = (string) parse_url($articleUrl, PHP_URL_FRAGMENT);
		if (preg_match('/^focus=([A-Za-z0-9_-]{3,120})$/', $fragment, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────
	// Arte
	// ──────────────────────────────────────────────────────────────────────

	private function resolveArte(string $articleUrl): ?array {
		// Arte nutzt mehrere ID-Formate in freier Wildbahn: das klassische
		// "129847-001-A" (numerisch + Episoden-/A-F-Suffix) UND kürzere,
		// zweibuchstabig-präfixierte IDs wie "RC-027957" (z. B. Serien-
		// Kurzformate) - die Player-API akzeptiert beide gleichermaßen, nur
		// diese Regex kannte das zweite Format ursprünglich nicht.
		if (preg_match('#(\d{6}-\d{3}-[AF]|[A-Z]{2}-\d+|LIVE)#', $articleUrl, $m) !== 1) {
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

		// Live verifiziert (siehe Commit-Historie): Arte stellt dem
		// eigentlichen Protokoll ein "API_"-Präfix voran (z. B.
		// "API_HLS_NG_MA" statt nur "HLS..."), deshalb str_contains() statt
		// str_starts_with() - Letzteres hatte hier jeden Stream verworfen.
		//
		// Mehrere Sprach-/Untertitel-Kombinationen ("Originalfassung - UT
		// deutsch" etc.) sind bei Arte eigene Stream-Einträge mit jeweils
		// eigener Manifest-URL (genau wie bei ARD/ZDF) - live verifiziert per
		// API-Response: jeder streams[]-Eintrag trägt eine eigene .url UND
		// ein .versions[]-Array mit dem eigentlichen Label ("Originalfassung
		// - UT deutsch", "Originalfassung", …), während .mainQuality.label
		// nur die Bildqualität beschreibt (z. B. "720p") und deshalb NICHT
		// zum Beschriften taugt.
		$variants = [];
		foreach ($streams as $stream) {
			if (!is_array($stream)) {
				continue;
			}
			$protocol = strtoupper((string) ($stream['protocol'] ?? ''));
			$url = $stream['url'] ?? null;
			if (!str_contains($protocol, 'HLS') || !is_string($url) || !$this->looksLikeHlsUrl($url)) {
				continue;
			}
			$versions = $stream['versions'] ?? null;
			$firstVersion = is_array($versions) && is_array($versions[0] ?? null) ? $versions[0] : null;
			$label = $this->firstNonEmptyString([
				$firstVersion['label'] ?? null,
				$firstVersion['shortLabel'] ?? null,
			]) ?? ('Version ' . (count($variants) + 1));
			$variants[] = ['label' => $label, 'url' => $url];
		}

		return $this->buildVariantResult($variants);
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
	 * Erster nicht-leerer String aus einer Liste von Kandidatenwerten
	 * (bereits getrimmt) - für "nimm das erste vorhandene Label-Feld".
	 *
	 * @param list<mixed> $candidates
	 */
	private function firstNonEmptyString(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return trim($candidate);
			}
		}
		return null;
	}

	/**
	 * true, wenn $label auf eine Variante hindeutet, die als Voreinstellung
	 * unpraktisch wäre (Gebärdensprache, Audiodeskription, …) - siehe
	 * SPECIAL_VARIANT_KEYWORDS.
	 */
	private function isSpecialVariant(string $label): bool {
		$lower = mb_strtolower($label);
		foreach (self::SPECIAL_VARIANT_KEYWORDS as $keyword) {
			if (str_contains($lower, $keyword)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Baut aus den pro Sender gesammelten Kandidaten die finale
	 * resolve()-Antwort: dedupliziert nach URL (erste Nennung gewinnt) und
	 * wählt als defaultIndex die erste NICHT-spezielle Variante, damit
	 * Gebärdensprache/Audiodeskription nie stillschweigend die Vorauswahl
	 * ist - bleibt aber im Dropdown wählbar.
	 *
	 * @param list<array{label: string, url: string}> $variants
	 * @return array{type: 'hls', variants: list<array{label: string, url: string}>, defaultIndex: int}|null
	 */
	private function buildVariantResult(array $variants): ?array {
		$seenUrls = [];
		$deduped = [];
		foreach ($variants as $variant) {
			if (isset($seenUrls[$variant['url']])) {
				continue;
			}
			$seenUrls[$variant['url']] = true;
			$deduped[] = $variant;
		}
		if ($deduped === []) {
			return null;
		}

		$defaultIndex = 0;
		foreach ($deduped as $i => $variant) {
			if (!$this->isSpecialVariant($variant['label'])) {
				$defaultIndex = $i;
				break;
			}
		}

		return ['type' => 'hls', 'variants' => $deduped, 'defaultIndex' => $defaultIndex];
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
