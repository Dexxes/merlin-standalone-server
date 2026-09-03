<?php

declare(strict_types=1);

namespace Merlin\Service;

// Load vendor autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Merlin\Service\Http\SsrfSafeResolver;
use Merlin\Service\Login\PaywallLoginRequiredException;
use Psr\Log\LoggerInterface;

class ContentExtractorService {
	use SsrfSafeResolver;

	private LoggerInterface $logger;

	/** @var list<string>|null Gecachte Regex-Patterns aus url-shorteners.json */
	private ?array $shortenerPatterns = null;

	/**
	 * Maximale Anzahl an HTTP-Redirects, denen manuell gefolgt wird (fetchUrl()
	 * und followHttpRedirect()). CURLOPT_FOLLOWLOCATION wird bewusst NICHT genutzt,
	 * weil libcurl damit jedem Location-Header folgt, ohne dass wir die Ziel-IP vor
	 * dem Connect gegen private/reservierte Ranges prüfen könnten (SSRF-via-Redirect).
	 */
	private const MAX_REDIRECTS = 10;

	/**
	 * Header-Namen, die eine Domain-Config über <fetch> setzen darf (kleingeschrieben).
	 *
	 * Whitelist statt Blacklist, weil die XML-Dateien Konfigurationsdaten sind:
	 * Ein frei wählbarer Header-Name könnte sonst den Request umbiegen (Host),
	 * Zugangsdaten anhängen (Authorization) oder den Body-Parser verwirren
	 * (Content-Length, Transfer-Encoding).
	 *
	 * Die Liste steht in ContentFilterSchema, weil sie an zwei Stellen gilt: hier
	 * beim Abruf und im ContentFilterValidator, der einen unerlaubten Header schon
	 * beim Speichern in der Admin-UI ablehnt. Zwei Kopien würden auseinanderlaufen.
	 */
	private const FETCH_HEADER_WHITELIST = ContentFilterSchema::FETCH_HEADER_WHITELIST;

	/**
	 * Trennzeichen, das in Bildunterschriften jeden Zeilenumbruch ersetzt.
	 *
	 * Bildunterschriften sollen immer einlaufender Fließtext sein: Quellseiten
	 * packen dort gerne Titel, Copyright und Fotografennamen als eigene Blöcke
	 * bzw. per <br> untereinander, was im Reader unter dem Bild als mehrzeiliger
	 * Klotz landet. flattenCaptions() ersetzt jeden solchen Umbruch durch den
	 * Bullet – der Wortlaut bleibt erhalten, nur die Zeilenstruktur fällt weg.
	 */
	private const CAPTION_BULLET    = '•';
	private const CAPTION_SEPARATOR = ' ' . self::CAPTION_BULLET . ' ';

	/**
	 * Tags, die innerhalb einer <figcaption> einen sichtbaren Umbruch erzeugen.
	 *
	 * Bewusst nur Block-Elemente: Inline-Auszeichnung (<a>, <em>, <span>, …)
	 * bleibt unangetastet, weil sie ohnehin in derselben Zeile rendert.
	 */
	private const CAPTION_BLOCK_TAGS = [
		'p', 'div', 'section', 'article', 'header', 'footer', 'aside',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		'blockquote', 'pre', 'figure', 'figcaption',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
	];

	private DomainConfigProvider $domainConfig;

	/**
	 * UID des Nutzers, in dessen Kontext gerade extrahiert wird – für die Dauer
	 * genau eines extract()/extractFromHtml()-Aufrufs, danach durch den
	 * nächsten Aufruf überschrieben.
	 *
	 * Warum ein Instanzfeld statt eines Parameters, der durch alle acht
	 * loadDomainConfig()-Aufrufstellen (Fetch-Header, Bilder, Zitate, Pre-/
	 * Post-Filter, Infoboxen, Klassenmarker, Metadaten) durchgereicht werden
	 * müsste: extract()/extractFromHtml() sind nicht reentrant (processHtml()
	 * ruft sich nie selbst auf), und der Service wird pro Request neu
	 * konstruiert – ein Datenleck zwischen Nutzern ist damit ausgeschlossen.
	 * loadDomainConfig() liest dieses Feld, statt dass jede private
	 * Applier-Methode einen zusätzlichen $userId-Parameter bekäme.
	 */
	private ?string $currentUserId = null;

	public function __construct(
		LoggerInterface $logger,
		DomainConfigProvider $domainConfig,
		private SiteCredentialService $siteCredentials,
		private BlueskyThreadResolverService $blueskyThreadResolver,
	) {
		$this->logger       = $logger;
		$this->domainConfig = $domainConfig;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Public API & Orchestration
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract article content from URL
	 *
	 * @param string $url
	 * @return array{
	 *   url: string,
	 *   title: string,
	 *   content: string,
	 *   excerpt: ?string,
	 *   author: ?string,
	 *   siteName: ?string,
	 *   imageUrl: ?string,
	 *   readingTime: int,
	 *   publishedAt: ?\DateTime,
	 *   category: ?string
	 * }
	 * @param ContentFilterTrace|null $trace Optionale Regel-Diagnose für den
	 *        Filter-Testlauf in den Admin-Einstellungen. Im Normalbetrieb null,
	 *        dann entsteht kein zusätzlicher Aufwand.
	 * @param string|null $userId UID des aufrufenden Nutzers, für die private
	 *        User-Custom-Ebene der Content-Filter (siehe $currentUserId). null
	 *        bei fehlendem Nutzerkontext – dann greift nur Bundle+Admin-Custom.
	 * @throws \Exception
	 */
	public function extract(string $url, ?ContentFilterTrace $trace = null, ?string $userId = null): array
	{
		$this->currentUserId = $userId;

		try {
			// Resolve tracking/redirect URLs (e.g. google.com/url?url=...) to their
			// real target before fetching, so we get the actual article.
			$url = $this->resolveRedirectUrl($url);

			// Fetch HTML content from the network. httpRequestFollowingRedirects()
			// (aufgerufen über fetchUrl()) folgt jeder 3xx-Kette bereits vollständig,
			// unabhängig davon, ob der Host in resources/url-shorteners.json steht –
			// resolveRedirectUrl() deckt nur die Vorab-Auflösung ohne Body-Download ab.
			// $finalUrl ist daher die tatsächliche Artikel-URL nach ALLEN Redirects,
			// auch bei Shortenern/Trackern, die nicht auf der kuratierten Liste stehen.
			// Domain-Filter-Auswahl und die gespeicherte Artikel-URL müssen sich darauf
			// stützen, sonst greift bei unbekannten Shortenern die falsche (oder gar
			// keine) Content-Filter-Konfiguration.
			['body' => $rawHtml, 'httpCharset' => $httpCharset, 'finalUrl' => $finalUrl] = $this->fetchUrl($url);
			$url = $finalUrl;

			$this->assertNotPaywalled($url, $rawHtml);

			return $this->processHtml($url, $rawHtml, $httpCharset, $trace);
		}
		catch (PaywallLoginRequiredException $e)
		{
			// Absichtlich VOR dem generischen \Exception-Catch unten: die
			// Exception muss unverändert bei ArticleController ankommen, das
			// daraus eine eindeutige "Login erforderlich"-API-Antwort baut
			// (siehe PLATFORMS.md) statt eines generischen Fehlschlags.
			throw $e;
		}
		catch (ParseException $e)
		{
			$this->logger->error('Failed to parse article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to extract article content: ' . $e->getMessage());
		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to fetch article: ' . $e->getMessage());
		}
	}

	/**
	 * Extract article content from pre-fetched HTML (e.g. sent by a browser extension).
	 * Skips the HTTP fetch step; the extraction pipeline is identical to extract().
	 *
	 * @return array{
	 *   url: string,
	 *   title: string,
	 *   content: string,
	 *   excerpt: ?string,
	 *   author: ?string,
	 *   siteName: ?string,
	 *   imageUrl: ?string,
	 *   readingTime: int,
	 *   publishedAt: ?\DateTime,
	 *   category: ?string
	 * }
	 * @param string|null $userId UID des aufrufenden Nutzers, siehe extract().
	 */
	public function extractFromHtml(string $url, string $html, ?string $userId = null): array
	{
		$this->currentUserId = $userId;

		try
		{
			return $this->processHtml($url, $html);
		}
		catch (ParseException $e) 
		{
			$this->logger->error('Failed to parse article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to extract article content: ' . $e->getMessage());
		} 
		catch (\Exception $e) 
		{
			$this->logger->error('Failed to process article HTML: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to process article HTML: ' . $e->getMessage());
		}
	}

	/**
	 * Run the full extraction pipeline on already-fetched HTML.
	 * Called by both extract() (after HTTP fetch) and extractFromHtml() (browser-sent HTML).
	 *
	 * @param string      $url         Artikel-URL (für relative Link-Auflösung und Domain-Config)
	 * @param string      $rawHtml     Roher HTML-Body
	 * @param string|null $httpCharset Charset aus dem HTTP-Content-Type-Header (Vorrang vor <meta>)
	 */
	private function processHtml(
		string $url,
		string $rawHtml,
		?string $httpCharset = null,
		?ContentFilterTrace $trace = null
	): array
	{
		// Normalise domain (strips www.) for config-file lookup
		$domain = $this->normalizeDomain($url);

		// ── Step 0: Encoding normalisation ───────────────────────────────────
		// Seiten mit iso-8859-1 oder anderen Nicht-UTF-8-Encodings erzeugen
		// Mojibake, wenn DOMDocument oder html_entity_decode fälschlicherweise
		// UTF-8 annehmen. Deshalb: Encoding ermitteln und das Dokument vor
		// allen weiteren Schritten nach UTF-8 konvertieren.
		//
		// Priorität nach RFC 7231 §3.1.1.5:
		//   1. HTTP-Content-Type-Header  (fetchUrl() liefert $httpCharset)
		//   2. <meta>-Tag im HTML-Body   (detectHtmlEncoding())
		$detectedEncoding = $httpCharset ?? $this->detectHtmlEncoding($rawHtml);
		if ($detectedEncoding !== null) {
			$this->logger->debug('ContentExtractor: encoding source', [
				'source'   => $httpCharset !== null ? 'HTTP-Header' : 'meta-tag',
				'encoding' => $detectedEncoding,
			]);
		}
		if ($detectedEncoding !== null
			&& !in_array($detectedEncoding, ['utf-8', 'utf8'], true)
		) {
			$converted = mb_convert_encoding($rawHtml, 'UTF-8', $detectedEncoding);
			if ($converted !== false && $converted !== '') {
				$rawHtml = $converted;

				// Nach der Konvertierung ist die ursprüngliche Encoding-Deklaration
				// falsch – DOMDocument oder nachgelagerte Parser würden sonst wieder
				// das alte Encoding annehmen und die nun korrekt kodierten Bytes
				// fehlinterpretieren. Deshalb charset-Angaben aus beiden Meta-Formen
				// auf "utf-8" umschreiben.
				//
				// Form 1: <meta http-equiv="Content-Type" content="…; charset=iso-8859-1">
				$rawHtml = preg_replace(
					'/(;\s*charset=)[^\s;"\'>\\/]+/i',
					'${1}utf-8',
					$rawHtml
				) ?? $rawHtml;
				// Form 2: <meta charset="iso-8859-1"> (HTML5-Kurzform)
				$rawHtml = preg_replace(
					'/(<meta[^>]+charset=["\']?)[^\s;"\'>\\/]+/i',
					'${1}utf-8',
					$rawHtml
				) ?? $rawHtml;

				$this->logger->info('ContentExtractor: converted HTML encoding', [
					'from' => $detectedEncoding,
					'to'   => 'UTF-8',
				]);
			}
		}

		// ── Step 2: Domain metadata extraction ───────────────────────────────
		// Must run on the ORIGINAL HTML before any pre-filter stripping,
		// because applyRemoveRules() removes all <script> tags — which would
		// destroy JSON-LD and other embedded JSON sources before they can be read.
		$domainMeta = $this->extractDomainMetadata($rawHtml, $domain, $trace);

		//When the except is too long, short it
		if(isset($domainMeta) && key_exists("except", $domainMeta) && strlen($domainMeta['excerpt']) > 300)
			$domainMeta['excerpt'] = substr($domainMeta['excerpt'],0,300) . "...";

		// ── Step 3: Image caption normalisation ─────────────────────────────
		// Rewrap domain-specific image+caption structures into standard
		// <figure><img><figcaption> HTML so Readability preserves them.
		// Must run before Readability; affects all images in the article body.
		if($domainMeta['category'] != "Video" && $domainMeta['category'] != "Thread")
			$rawHtml = $this->normalizeImageCaptions($rawHtml, $domain, $trace);

		// ── Step 4: Pre-filter ────────────────────────────────────────────────
		// Apply per-domain <pre-filter> remove rules BEFORE Readability sees
		// the HTML, so filtered elements are never considered as article content.
		$rawHtml = $this->applyPreFilters($rawHtml, $domain, $trace);
		// ENT_NOQUOTES statt ENT_QUOTES: saveHTML() re-encodiert Attributwerte
		// korrekt (z. B. &quot;/&#34; für ein eingebettetes literales Anführungs-
		// zeichen). ENT_QUOTES decodierte diese Quote-Entities aber wieder in
		// literale " / ' zurück — auf dem rohen String, ohne erneute DOM-
		// Serialisierung. Bei Attributen, die selbst JSON/JS mit Anführungs-
		// zeichen tragen (z. B. Alpine.js' x-data="{…&#34;key&#34;…}", verbreitet
		// u. a. bei spiegel.de), riss das die Attributgrenze mittendrin auf: der
		// Rest des Attributwerts (oft ein ganzer Script-Block) rutschte als
		// kaputtes Markup/Textinhalt in den Baum und konnte von Readability als
		// Artikeltext ausgewählt werden. ENT_NOQUOTES decodiert weiterhin named/
		// numeric Entities in sichtbarem Text (Umlaute, &amp; …), lässt aber
		// Quote-Entities unangetastet, sodass Attributwerte gültig bleiben.
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// ── Step 5: Infobox markers ──────────────────────────────────────────
		// Add 'merlin-infobox' CSS class to elements declared as <infobox> in
		// the domain config. Must run BEFORE Readability so the class survives.
		$rawHtml = $this->applyInfoboxMarkers($rawHtml, $domain, $trace);
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// ── Step 6: Custom class markers ────────────────────────────────────
		// Add arbitrary CSS classes to elements declared as <saveElements> in
		// the domain config. Must run BEFORE Readability so the classes survive.
		$rawHtml = $this->applyClassMarkers($rawHtml, $domain, $trace);
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// Nur von Step 5 (domainMeta-Override) oder dem Video-Zweig unten gesetzt,
		// wenn kein og:description/domain-Excerpt vorhanden ist — explizit auf
		// null initialisiert, damit stripDuplicateMetadata()/der Rückgabewert
		// unten keine "undefined variable"-Warnung auslösen.
		$excerpt = null;

		// Der Video-Zweig unten überspringt Readability komplett und hat daher nie
		// $siteName gesetzt (undefined variable → null im Rückgabewert). Da
		// PublicArticleView.vue/ArticleReader.vue den URL-Link an
		// "article.siteName && safeArticleUrl" knüpfen, fehlte für Video-Domains
		// (z. B. ardmediathek.de) die komplette URL-Anzeige in den Metadaten.
		// extractSiteName() wertet ohnehin nur den Host aus $url aus, ist also in
		// beiden Zweigen identisch berechenbar – deshalb hier vorab setzen.
		$siteName = $this->extractSiteName($rawHtml, $url);
		$siteName = html_entity_decode($siteName ?? '', ENT_QUOTES, 'UTF-8');

		if($domainMeta['category'] != "Video" && $domainMeta['category'] != "Thread")
		{
			// ── Step 7: Quote normalisation + Readability ──────────────────────────
			// Normalise quote structures before Readability:
			//   1. Domain-specific <quotes> rules from the content-filter XML
			//   2. Standard <blockquote> elements → merlin-quote class
			//   3. <q> elements → merlin-quote-inline class
			// keepClasses=true (below) ensures Readability does NOT strip class attributes,
			// so all Merlin marker classes survive post-processing.
			$html = $this->normalizeQuotes($rawHtml, $domain, $trace);

			$readabilityConfig = new Configuration([
				'fixRelativeURLs' => true,
				'originalURL' => $url,
				'summonCthulhu' => true, // Remove unlikely candidates
				// Keep class attributes so that Merlin-specific marker classes (e.g.
				// merlin-infobox, merlin-quote) added before parsing survive intact.
				'keepClasses'   => true,
			]);

			$readability = new Readability($readabilityConfig);

			// If the quote-transform corrupted the HTML, fall back to the original
			try
			{
				// Debug-Dump des Pre-Readability-HTML, auskommentiert: schrieb bei
				// JEDEM Extract-Aufruf eine Datei (unbedingter Hot-Path-I/O). Bei
				// Bedarf für gezieltes Debugging wieder einkommentieren.
				//$path = __DIR__ . "/../../test/preReadability.html";
				//file_put_contents($path, $html);
				$html = str_replace('<?xml encoding="utf-8" ?>', '', $html);
				$readability->parse($html);
			}
			catch (ParseException $e) {
				$this->logger->warning('normalizeQuotes output rejected by Readability, retrying with raw HTML', ['url' => $url]);
				$readability = new Readability($readabilityConfig);
				$readability->parse($rawHtml);
			}

			// ── Step 8: Collect Readability results ───────────────────────────────
			$title = $readability->getTitle() ?: $this->extractTitleFromHtml($html);
			$title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');

			$content = $readability->getContent() ?: '';
			// ENT_NOQUOTES statt ENT_QUOTES: $content ist weiterhin HTML-Markup
			// (Readability liefert einen HTML-Fragment-String, keinen reinen Text),
			// das anschließend durch applyPostFilters()/sanitizeHtml() erneut per
			// DOM geparst wird. ENT_QUOTES decodierte &quot;/&#34; in Attributen
			// (z. B. <blockquote data-instgrm-permalink="…&#34;…">, oder Reste
			// von Widget-Markup, das Readability unverändert übernommen hat)
			// zurück in literale Anführungszeichen und riss damit dieselbe
			// Attributgrenze auf wie bei den Pre-Readability-Decode-Aufrufen oben
			// (siehe dortiger Kommentar) — mit demselben Resultat: der Rest des
			// Attributwerts rutschte als kaputtes Markup/Text in den extrahierten
			// Content. ENT_NOQUOTES decodiert weiterhin named/numeric Entities in
			// sichtbarem Text, lässt Quote-Entities aber unangetastet.
			$content = html_entity_decode($content, ENT_NOQUOTES, 'UTF-8');

			//$excerpt = $readability->getExcerpt();
			//$excerpt = html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8');

			$author = $readability->getAuthor() ?: '';
			$author = html_entity_decode($author, ENT_QUOTES, 'UTF-8');

			// Prefer og:image (most reliable), fall back to Readability's detected image,
			// then scan raw HTML for a prominent hero figure (rescued before Readability drops it).
			// $heroImageData wird in Step 7b für den Post-Inject mit Caption verwendet.
			$heroImageData = $this->extractHeroImageFromHtml($rawHtml, $url);
			$imageUrl      = $this->extractOgImage($html)
				?: ($readability->getImage() ?: null)
				?: ($heroImageData['src'] ?? null);
			$publishedAt = $this->extractPublishedDate($html, $content);
		}
		elseif ($domainMeta['category'] === "Thread") {
			// Self-Thread-Zweig (bsky.app, siehe BlueskyThreadResolverService):
			// Readability wird übersprungen (bsky.app liefert als SPA praktisch
			// keinen Server-Side-Content). Titel/Excerpt/Bild zunächst aus dem
			// og:title/og:description/og:image-Fallback der bsky.app.xml
			// (domainMeta, aus Step 2 oben) vorbelegen - das greift, wenn die
			// API-Auflösung unten fehlschlägt.
			$title       = $domainMeta['title'] ?? '';
			$author      = null;
			$imageUrl    = $domainMeta['image'] ?? null;
			$publishedAt = null;

			$threadPosts = $this->blueskyThreadResolver->resolveSelfThread($url);
			if ($threadPosts !== null && $threadPosts !== []) {
				$content   = $this->buildBlueskyThreadHtml($threadPosts);
				$firstPost = $threadPosts[0];

				$title  = $firstPost['text'] !== '' ? $this->truncateText($firstPost['text'], 80) : $title;
				$excerpt = $this->truncateText($firstPost['text'], 300);
				$author = $firstPost['authorDisplayName'] ?: ($firstPost['authorHandle'] ?: null);

				$threadImage = null;
				foreach ($threadPosts as $threadPost) {
					if ($threadPost['imageUrl'] !== null) {
						$threadImage = $threadPost['imageUrl'];
						break;
					}
				}
				$imageUrl = $threadImage ?? ($firstPost['authorAvatar'] ?? $imageUrl);

				$publishedAt = $firstPost['createdAt'] !== '' ? $this->parseDateString($firstPost['createdAt']) : null;

				// Diese Werte gelten für den ganzen Self-Thread (ältester Post) -
				// nicht von Step 9 unten mit og:title/og:description/og:image der
				// einzelnen VERLINKTEN Post-Seite überschreiben lassen, die bei
				// einem mehrteiligen Thread nur einen Teilausschnitt zeigen.
				$domainMeta['title']   = $title;
				$domainMeta['excerpt'] = $excerpt;
				$domainMeta['image']   = $imageUrl;
			} else {
				// API-Auflösung fehlgeschlagen (gelöschter Post, Rate-Limit,
				// Netzwerkfehler) - einfacher Link-Fallback statt leerem Artikel.
				// Titel/Excerpt/Bild bleiben der og:-Fallback von oben.
				$escapedBlueskyUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
				$content = '<a href="' . $escapedBlueskyUrl . '" class="merlin-bluesky-fallback-link">Zum Bluesky-Post</a>';
				if ($title === '') {
					$title = 'Bluesky-Post';
				}
			}
		}
		else {
			// $url in ein Attribut eingebettet → escapen, damit ein URL mit ' oder
			// " nicht aus dem href ausbricht. Der finale sanitizeHtml()-Durchlauf
			// filtert zusätzlich ein evtl. javascript:-Schema heraus.
			$escapedVideoUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
			// Eigene Marker-Klasse (wie merlin-hero-image/merlin-infobox), damit der
			// Reader diesen Fallback-Link ausblenden kann, sobald
			// VideoStreamResolverService::resolve() für dieselbe URL einen
			// abspielbaren Stream gefunden hat - sonst stünde er redundant neben
			// dem nativen Player.
			$content = '<a href="' . $escapedVideoUrl . '" class="merlin-video-fallback-link">Zum Video</a>';
		}

		// ── Step 9: Apply domain metadata overrides ───────────────────────────
		if (!empty($domainMeta['title']))     { $title     = $domainMeta['title']; }
		if (!empty($domainMeta['author']))    { $author    = $domainMeta['author']; }
		if (!empty($domainMeta['excerpt']))   { $excerpt   = $domainMeta['excerpt']; }
		if (!empty($domainMeta['image']))     { $imageUrl  = $domainMeta['image']; }
		if (!empty($domainMeta['published'])) { $publishedAt = $this->parseDateString($domainMeta['published']); }

		// ── Step 10: Post-filter ───────────────────────────────────────────────
		// Apply per-domain <post-filter> remove rules to the Readability content.
		$content = $this->applyPostFilters($content, $domain, $trace);

		// ── Step 11: Cleanup pipeline ──────────────────────────────────────────
		$wordCount   = str_word_count(strip_tags($content));
		$readingTime = max(1, (int) ceil($wordCount / 200));

		$content = $this->cleanHtml($content);

		$normalizedImageUrl = $imageUrl ? $this->normalizeUrl($imageUrl, $url) : null;

		// ── Step 12: Hero-Image in Content einfügen ───────────────────────────
		// Readability entfernt Hero-Bilder aus <figure>-Containern, wenn sie vor
		// dem Fließtext stehen. Falls der extrahierte Content kein <img> enthält
		// (geprüft anhand der ersten 1000 Zeichen), aber imageUrl bekannt ist,
		// wird das Bild als merlin-hero-image an den Anfang prepended.
		// Eine vorhandene <figcaption> aus dem Quell-HTML wird mitübernommen.
		// Das Bild darf so nur einmal erscheinen – stripDuplicateMetadata läuft danach.
		$start = mb_substr(trim($content), 0, 2000);
		if ($normalizedImageUrl !== null && !preg_match('/<img\b/i', $start)) {
			$escapedUrl  = htmlspecialchars($normalizedImageUrl, ENT_QUOTES, 'UTF-8');
			$figcaption  = '';
			// Caption aus dem HTML-Scan übernehmen (nur wenn kein og:image die imageUrl
			// geliefert hat – dann stammt $heroImageData von derselben figure).
			//if (!empty($heroImageData['caption']) && ($heroImageData['src'] ?? null) === $normalizedImageUrl) {
			$escapedCaption = "";
			if (!empty($heroImageData['caption']))
				$escapedCaption = htmlspecialchars($heroImageData['caption'], ENT_QUOTES, 'UTF-8');
				
				$figcaption     = '<figcaption>' . $escapedCaption . '</figcaption>';
			//}
			$content = '<figure class="merlin-hero-image"><img src="' . $escapedUrl . '" alt="">' . $figcaption . '</figure>' . $content;
		}

		$content = $this->stripDuplicateMetadata($content, $title, $excerpt);

		// ── Step 13: HTML-Sanitizing (XSS-Schutz) ──────────────────────────────
		// Letzter Schritt vor der Rückgabe: Der Inhalt wird im Web-Reader und in
		// der öffentlichen Share-Ansicht per v-html gerendert. cleanHtml() und die
		// Readability-Pipeline entfernen zwar <script>/<style>, aber KEINE
		// Event-Handler-Attribute (onerror, onload, …), javascript:-URLs oder
		// gefährliche Tags (<iframe>, <object>, <form>). sanitizeHtml() schließt
		// diese Lücke serverseitig per DOM-Allowlist, statt die XSS-Abwehr allein
		// der Content-Security-Policy zu überlassen (Defense-in-Depth).
		$content = $this->sanitizeHtml($content);

		return [
			'url'         => $url,
			'title'       => $title,
			'content'     => $content,
			'excerpt'     => $excerpt,
			'author'      => $author,
			'siteName'    => $siteName,
			'imageUrl'    => $normalizedImageUrl,
			'readingTime' => $readingTime,
			'publishedAt' => $publishedAt,
			'category'    => $domainMeta['category'],
		];
	}

	/**
	 * Baut den Artikel-Content für einen Bluesky-Self-Thread: ein
	 * Blueskys-offizielles Embed-<blockquote data-bluesky-uri="…"> je Post
	 * (in chronologischer Reihenfolge), gefolgt vom offiziellen Loader-Script.
	 * embed.bsky.app ersetzt jedes [data-bluesky-uri]-Element client-seitig
	 * durch ein <iframe> mit dem echten, live gerenderten Post - der
	 * Blockquote-Inhalt hier ist nur der No-JS-Fallback-Text.
	 *
	 * @param list<array{uri: string, cid: string, text: string, authorDid: string,
	 *   authorHandle: string, authorDisplayName: ?string, authorAvatar: ?string,
	 *   createdAt: string, imageUrl: ?string}> $posts
	 */
	private function buildBlueskyThreadHtml(array $posts): string {
		$blocks = [];
		foreach ($posts as $post) {
			$escapedUri  = htmlspecialchars($post['uri'], ENT_QUOTES, 'UTF-8');
			$escapedText = nl2br(htmlspecialchars($post['text'], ENT_QUOTES, 'UTF-8'));

			$blocks[] = '<blockquote class="bluesky-embed" data-bluesky-uri="' . $escapedUri . '">'
				. '<p>' . $escapedText . '</p>'
				. '</blockquote>';
		}
		$blocks[] = '<script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>';

		return implode("\n", $blocks);
	}

	private function truncateText(string $text, int $maxLen): string {
		$text = trim($text);
		// mb_-Varianten statt substr()/strlen(): Bluesky-Post-Text ist UTF-8 mit
		// häufigen Mehrbyte-Zeichen (Umlaute, Gedankenstriche, Emoji). Byteweises
		// substr() kann mittendrin in einem Mehrbyte-Zeichen abschneiden und eine
		// kaputte UTF-8-Sequenz erzeugen, die die DB (SQLSTATE 22007/1366) ablehnt.
		return mb_strlen($text, 'UTF-8') > $maxLen ? mb_substr($text, 0, $maxLen, 'UTF-8') . '...' : $text;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// URL Resolution & Redirects
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve common redirect/tracking URLs to their real target.
	 *
	 * Zwei Strategien:
	 *   1. Query-Parameter-Extraktion  – kein Netzwerk-Roundtrip nötig
	 *      · google.com/url?url=<target>  (Google Alerts, Google News, …)
	 *      · google.com/url?q=<target>    (älteres Google-Format)
	 *
	 *   2. HTTP-3xx-Folgen via HEAD-Request  – für reine Shortener ohne Payload
	 *      · dlvr.it    · bit.ly      · tinyurl.com  · t.co (Twitter/X)
	 *      · ow.ly      · buff.ly     · ift.tt        · fb.me
	 *      · wp.me      · rebrand.ly  · short.gy      · cutt.ly
	 *      · is.gd      · feeds.feedburner.com
	 */
	private function resolveRedirectUrl(string $url): string {
		$parsed = parse_url($url);
		if (empty($parsed['host'])) {
			return $url;
		}

		$host = strtolower($parsed['host']);

		// Strategie 1: Query-Parameter-Extraktion (kein Netzwerk-Roundtrip)
		// google.com/url?url=... or google.com/url?q=...
		if (preg_match('/(?:^|\.)google\.[a-z]{2,}$/', $host)) {
			if (!empty($parsed['query'])) {
				parse_str($parsed['query'], $params);
				$target = $params['url'] ?? $params['q'] ?? null;
				if ($target && filter_var($target, FILTER_VALIDATE_URL)) {
					$this->logger->info('Resolved Google redirect URL', [
						'from' => $url,
						'to'   => $target,
					]);
					return $target;
				}
			}
		}

		// Strategie 2: HTTP-3xx-Kette folgen (HEAD-Request, kein Body-Download)
		// Die Liste der bekannten Shortener kommt aus resources/url-shorteners.json –
		// dort können neue Einträge ergänzt werden, ohne PHP-Code anzufassen.
		foreach ($this->loadShortenerPatterns() as $pattern) {
			if (preg_match($pattern, $host)) {
				// followHttpRedirect() validiert jeden Hop gegen private/reservierte
				// IP-Ranges und wirft bei Verstoß eine Exception. Statt die gesamte
				// Extraktion abzubrechen, fallen wir hier auf die unaufgelöste
				// Shortener-URL zurück – fetchUrl() prüft sie beim eigentlichen
				// Abruf ohnehin erneut mit derselben SSRF-Guard-Logik.
				try {
					$resolved = $this->followHttpRedirect($url);
				} catch (\Exception $e) {
					$this->logger->warning('URL-Shortener-Auflösung abgelehnt oder fehlgeschlagen', [
						'url'   => $url,
						'error' => $e->getMessage(),
					]);
					return $url;
				}
				if ($resolved !== $url) {
					$this->logger->info('Resolved URL shortener redirect', [
						'from' => $url,
						'to'   => $resolved,
					]);
				}
				return $resolved;
			}
		}

		return $url;
	}

	/**
	 * Lädt die Shortener-Host-Liste aus resources/url-shorteners.json und wandelt
	 * jeden Hostnamen in ein Regex-Pattern um. Das Ergebnis wird gecacht, damit die
	 * Datei pro Request nur einmal gelesen wird.
	 *
	 * @return list<string>
	 */
	private function loadShortenerPatterns(): array {
		if ($this->shortenerPatterns !== null) {
			return $this->shortenerPatterns;
		}

		$file = __DIR__ . '/../../resources/url-shorteners.json';
		$json = @file_get_contents($file);
		if ($json === false) {
			$this->logger->warning('url-shorteners.json nicht gefunden', ['path' => $file]);
			return $this->shortenerPatterns = [];
		}

		$entries = json_decode($json, true);
		if (!is_array($entries)) {
			$this->logger->warning('url-shorteners.json ist kein gültiges JSON-Array');
			return $this->shortenerPatterns = [];
		}

		$this->shortenerPatterns = array_map(
			// Hostnamen in ein vollständig anchored Regex übersetzen.
			// Punkte escapen, damit "bitXly" nicht matcht.
			static fn(array $entry): string =>
				'/(?:^|\.)' . preg_quote($entry['host'], '/') . '$/',
			array_filter($entries, static fn($e) => is_array($e) && isset($e['host']))
		);

		return $this->shortenerPatterns;
	}

	/**
	 * Normalize relative URLs to absolute
	 */
	private function normalizeUrl(string $imageUrl, string $baseUrl): string {
		// Already absolute
		if (preg_match('/^https?:\/\//i', $imageUrl)) {
			return $imageUrl;
		}

		$base = parse_url($baseUrl);
		$scheme = $base['scheme'] ?? 'https';
		$host = $base['host'] ?? '';

		// Protocol-relative URL
		if (str_starts_with($imageUrl, '//')) {
			return $scheme . ':' . $imageUrl;
		}

		// Absolute path
		if (str_starts_with($imageUrl, '/')) {
			return $scheme . '://' . $host . $imageUrl;
		}

		// Relative path
		$path = $base['path'] ?? '/';
		$pathParts = explode('/', $path);
		array_pop($pathParts); // Remove filename
		$basePath = implode('/', $pathParts);

		return $scheme . '://' . $host . $basePath . '/' . $imageUrl;
	}

	/**
	 * Folgt der HTTP-Redirect-Kette eines URL-Shorteners und gibt die finale URL zurück.
	 *
	 * Wir nutzen einen reinen HEAD-Request (kein Body-Download), um schnell und
	 * ressourcenschonend die Ziel-URL zu ermitteln, ohne den Artikel bereits zu laden.
	 *
	 * @throws \Exception wenn ein Hop auf eine private/reservierte Adresse zeigt oder
	 *                     der Request fehlschlägt (siehe httpRequestFollowingRedirects()).
	 */
	private function followHttpRedirect(string $url): string {
		return $this->httpRequestFollowingRedirects($url, nobody: true)['finalUrl'];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// HTTP Fetching & SSRF Protection
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch URL content.
	 *
	 * Speed improvements over the naive implementation:
	 *   - Accept-Encoding: gzip / deflate  →  60-80 % smaller transfer
	 *   - decode_content: true             →  Guzzle decompresses transparently
	 *   - connect_timeout: 5 s             →  fail fast on unreachable hosts
	 *   - charset from Content-Type header →  skip mb_detect_encoding on full body
	 *   - meta charset fallback            →  scan only the first 2 KB
	 *
	 * @return array{body: string, httpCharset: ?string, finalUrl: string}
	 * @throws \Exception wenn ein Hop auf eine private/reservierte Adresse zeigt oder
	 *                     der Request fehlschlägt (siehe httpRequestFollowingRedirects()).
	 */
	private function fetchUrl(string $url): array
	{
		$result = $this->httpRequestFollowingRedirects($url, nobody: false);
		return ['body' => $result['body'], 'httpCharset' => $result['httpCharset'], 'finalUrl' => $result['finalUrl']];
	}

	/**
	 * Führt einen HTTP-Request aus und folgt 3xx-Redirects manuell statt über
	 * CURLOPT_FOLLOWLOCATION.
	 *
	 * SSRF-Schutz (siehe SECURITY-AUDIT.md, "SSRF beim Artikel-Import"):
	 * CURLOPT_FOLLOWLOCATION lässt libcurl jedem Location-Header selbstständig
	 * folgen – dabei gäbe es keine Gelegenheit, die Ziel-IP VOR dem Connect gegen
	 * private/reservierte Ranges zu prüfen. Ein Angreifer könnte so über einen
	 * öffentlichen Erst-Redirect (z. B. einen offenen URL-Shortener) intern auf
	 * 127.0.0.1, RFC1918-Adressen oder Cloud-Metadata-Endpunkte (169.254.169.254)
	 * umleiten. Deshalb:
	 *   1. Jeder Hop wird einzeln aufgelöst und über assertPublicHostAndResolve()
	 *      geprüft, BEVOR verbunden wird.
	 *   2. Die Verbindung wird per CURLOPT_RESOLVE auf genau die geprüfte(n) IP(s)
	 *      gepinnt, damit ein zweiter DNS-Lookup zwischen Prüfung und Connect
	 *      (DNS-Rebinding) nicht auf eine private Adresse umschwenken kann.
	 *   3. Redirects werden manuell über den Location-Header verfolgt, maximal
	 *      MAX_REDIRECTS mal.
	 *
	 * @return array{body: string, httpCharset: ?string, finalUrl: string}
	 * @throws \Exception bei ungültigem/privatem Host, zu vielen Redirects oder curl-Fehlern.
	 */
	private function httpRequestFollowingRedirects(string $url, bool $nobody): array
	{
		$currentUrl  = $url;
		$httpCharset = null;

		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$parsed = parse_url($currentUrl);
			$host   = $parsed['host'] ?? '';
			$scheme = strtolower($parsed['scheme'] ?? '');
			$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

			// Wirft eine Exception bei ungültigem Schema, nicht auflösbarem Host
			// oder privater/reservierter Ziel-IP.
			$ips  = $this->assertPublicHostAndResolve($currentUrl);
			$pins = $this->buildResolvePin($host, $port, $ips);

			// Domain-spezifische Header (z. B. Consent-Cookies) werden für JEDEN Hop
			// einzeln anhand des aktuellen Hosts geladen – siehe loadFetchOverrides().
			$overrides = $this->loadFetchOverrides($currentUrl);

			$ch   = curl_init($currentUrl);
			$opts = [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false, // manuelles Redirect-Following, siehe Docblock
				CURLOPT_HEADER         => true,  // Header mitliefern, um Location selbst auszuwerten
				CURLOPT_NOBODY         => $nobody,
				CURLOPT_TIMEOUT        => $nobody ? 10 : 20,
				CURLOPT_CONNECTTIMEOUT => $nobody ? 5 : 10,
				CURLOPT_RESOLVE        => $pins, // IP-Pinning gegen DNS-Rebinding
				CURLOPT_USERAGENT      => $nobody
					? 'Mozilla/5.0 (compatible; Merlin/1.0)'
					: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/150.0',
			];

			$headers = [];

			if (!$nobody) {
				$opts[CURLOPT_AUTOREFERER] = true;
				$opts[CURLOPT_ENCODING]    = ''; // Leerer String = alle unterstützten Encodings aktivieren
				$headers = [
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
					'Accept-Encoding: gzip, deflate, br, zstd',
					'Accept-Language: de,en-US;q=0.9,en;q=0.8',
					'Cache-Control: no-cache',
					'Connection: keep-alive',
					'DNT: 1',
					'Host: ' . $host,
					'Pragma: no-cache',
					'Priority: u=0, i',
					'Referer: https://google.com/',
					'Sec-Fetch-Dest: document',
					'Sec-Fetch-Mode: navigate',
					'Sec-Fetch-Site: same-origin',
					'Sec-Fetch-User: ?1',
					'Sec-GPC: 1',
					'Upgrade-Insecure-Requests: 1',
				];
			}

			foreach ($overrides as $name => $value) {
				// User-Agent geht über CURLOPT_USERAGENT statt als Header-Zeile,
				// sonst sendet curl den Header doppelt.
				if (strcasecmp($name, 'User-Agent') === 0) {
					$opts[CURLOPT_USERAGENT] = $value;
					continue;
				}
				// Gleichnamigen Default entfernen, damit der Override ihn ersetzt
				// statt einen zweiten Header derselben Art zu erzeugen.
				$headers = array_values(array_filter(
					$headers,
					static fn(string $h): bool => stripos($h, $name . ':') !== 0
				));
				$headers[] = $name . ': ' . $value;
			}

			if ($headers !== []) {
				$opts[CURLOPT_HTTPHEADER] = $headers;
			}

			curl_setopt_array($ch, $opts);
			$response = curl_exec($ch);

			if ($response === false) {
				$error = curl_error($ch);
				curl_close($ch);
				throw new \Exception('HTTP-Request fehlgeschlagen: ' . $error);
			}

			$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$statusCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); // z. B. "text/html; charset=iso-8859-1"
			curl_close($ch);

			$rawHeaders = substr((string) $response, 0, $headerSize);
			$body       = substr((string) $response, $headerSize);

			if (in_array($statusCode, [301, 302, 303, 307, 308], true)
				&& preg_match('/^Location:\s*(.+?)\r?$/im', $rawHeaders, $m)
			) {
				// Location-Header können relativ sein (RFC 7231 erlaubt das, auch
				// wenn die meisten Server absolute URLs senden) – gegen den
				// aktuellen Hop auflösen, nicht gegen die ursprüngliche URL.
				$currentUrl = $this->normalizeUrl(trim($m[1]), $currentUrl);
				continue;
			}

			// Charset aus dem HTTP-Header extrahieren – der HTTP-Header hat nach
			// RFC 7231 Vorrang vor <meta>-Angaben im Body.
			if (is_string($contentType)
				&& preg_match('/;\s*charset=([^\s;]+)/i', $contentType, $m)
			) {
				$httpCharset = strtolower(trim($m[1], " \t\"'"));
			}

			return ['body' => $body, 'httpCharset' => $httpCharset, 'finalUrl' => $currentUrl];
		}

		throw new \Exception('Zu viele Redirects (>' . self::MAX_REDIRECTS . '): ' . $url);
	}

	/**
	 * Lädt domain-spezifische HTTP-Header aus der <fetch>-Sektion von
	 * content-filters/{domain}.xml.
	 *
	 * Anwendungsfall: Seiten wie golem.de antworten auf Server-Requests mit einem
	 * 302 auf ihre Consent-Seite, bevor überhaupt Artikel-HTML ausgeliefert wird.
	 * Ein mitgeschickter Consent-Cookie beendet diese Weiterleitung.
	 *
	 * Warum pro Redirect-Hop und nicht einmal pro Request: Cookies sind an einen
	 * Host gebunden. Würden wir sie einmal setzen und der gesamten Redirect-Kette
	 * mitgeben, landete das Cookie von Host A beim nächsten Hop auf Host B – genau
	 * das Leck, das Browser über die Same-Origin-Regeln verhindern.
	 *
	 * @return array<string,string> Header-Name => Wert (nur Whitelist, CRLF-frei)
	 */
	private function loadFetchOverrides(string $url): array
	{
		$domain = $this->normalizeDomain($url);
		$config = $this->loadDomainConfig($domain);

		$headers = [];

		if ($config !== null && isset($config->fetch)) {
			foreach ($config->fetch->header as $header) {
				$name  = trim((string) ($header['name'] ?? ''));
				$value = trim((string) ($header['value'] ?? ''));

				if ($name === '' || $value === '') {
					continue;
				}

				if (!in_array(strtolower($name), self::FETCH_HEADER_WHITELIST, true)) {
					$this->logger->warning(
						'Merlin: <fetch>-Header nicht erlaubt und ignoriert: ' . $name,
						['url' => $url]
					);
					continue;
				}

				// CR/LF entfernen: Ein Wert mit Zeilenumbruch würde sonst weitere
				// Header-Zeilen in den Request schmuggeln (Header-Injection).
				$headers[$name] = str_replace(["\r", "\n"], '', $value);
			}
		}

		$this->appendSiteCredentialCookies($domain, $headers);

		return $headers;
	}

	/**
	 * Hängt den per Paywall-Login gewonnenen Session-Cookie-Satz des
	 * AUFRUFENDEN Nutzers ($currentUserId) an den Cookie-Header an, sofern die
	 * Domain eine <login>-Sektion hat. Löst bei Bedarf einen frischen Login
	 * aus (SiteCredentialService::ensureValidCookies() cached selbst), holt
	 * aber NIE Zugangsdaten eines anderen Nutzers – ohne Nutzerkontext
	 * (anonymer/System-Aufruf) bleibt die Cookie-Injektion aus.
	 *
	 * @param array<string,string> $headers
	 */
	private function appendSiteCredentialCookies(string $domain, array &$headers): void
	{
		if ($this->currentUserId === null) {
			return;
		}

		$loginConfig = $this->siteCredentials->loadLoginConfig($domain);
		if ($loginConfig === null) {
			return;
		}

		$cookies = $this->siteCredentials->ensureValidCookies((int) $this->currentUserId, $domain, $loginConfig);
		if ($cookies === null || $cookies === []) {
			return;
		}

		$cookiePairs = [];
		foreach ($cookies as $name => $value) {
			$cookiePairs[] = $name . '=' . str_replace(["\r", "\n", ';'], '', $value);
		}

		$existing = $headers['Cookie'] ?? '';
		$headers['Cookie'] = $existing === '' ? implode('; ', $cookiePairs) : $existing . '; ' . implode('; ', $cookiePairs);
	}

	/**
	 * Wirft PaywallLoginRequiredException, wenn die Domain eine
	 * <login>-Sektion mit paywall-marker-Pattern hat, dieses Pattern im
	 * gerade abgerufenen HTML greift UND der aufrufende Nutzer keine
	 * gültigen Session-Cookies für die Domain hat (kein Login-Kontext, keine
	 * Zugangsdaten hinterlegt, oder letzter Login-Versuch fehlgeschlagen).
	 * Ein Treffer trotz gültiger Cookies wird NICHT geworfen – dann ist der
	 * Cookie vermutlich einfach nicht (mehr) ausreichend, aber ein erneuter
	 * Login wurde in appendSiteCredentialCookies() bereits versucht.
	 */
	private function assertNotPaywalled(string $url, string $rawHtml): void
	{
		$domain      = $this->normalizeDomain($url);
		$loginConfig = $this->siteCredentials->loadLoginConfig($domain);
		if ($loginConfig === null || $loginConfig->paywallMarkerPattern === null) {
			return;
		}

		if (@preg_match($loginConfig->paywallMarkerPattern, $rawHtml) !== 1) {
			return;
		}

		$hasValidCookies = $this->currentUserId !== null
			&& $this->siteCredentials->getCachedCookies((int) $this->currentUserId, $domain) !== null;
		if ($hasValidCookies) {
			return;
		}

		throw new PaywallLoginRequiredException($domain, $loginConfig->page);
	}

	// SSRF-Guard (assertPublicHostAndResolve/resolveHostIps/isPublicIp/buildResolvePin) via SsrfSafeResolver-Trait.

	// ──────────────────────────────────────────────────────────────────────────
	// Encoding Detection
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract the character encoding declared in HTML meta tags.
	 *
	 * Unterstützte Formen:
	 *   <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
	 *   <meta charset="utf-8">
	 *
	 * Scannt nur die ersten 4 KB (der <head> steht immer am Anfang) und gibt
	 * den Charset-String in Kleinbuchstaben zurück (z. B. "iso-8859-1") oder
	 * null, wenn kein Encoding deklariert ist.
	 */
	private function detectHtmlEncoding(string $html): ?string
	{
		$head = substr($html, 0, 10000);

		// <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
		// Attributreihenfolge: http-equiv vor content
		if (preg_match(
			'/<meta[^>]+http-equiv=["\']?Content-Type["\']?[^>]+content=["\']?[^;]*;\s*charset=([^\s;"\'>\\/]+)/i',
			$head, $m
		)) {
			return strtolower(trim($m[1]));
		}

		// Attributreihenfolge umgekehrt: content vor http-equiv
		if (preg_match(
			'/<meta[^>]+content=["\']?[^;]*;\s*charset=([^\s;"\'>\\/]+)[^>]+http-equiv=["\']?Content-Type["\']?/i',
			$head, $m
		)) {
			return strtolower(trim($m[1]));
		}

		// <meta charset="utf-8"> (HTML5-Kurzform)
		if (preg_match('/<meta[^>]+charset=["\']?([^\s;"\'>\\/]+)/i', $head, $m)) {
			return strtolower(trim($m[1]));
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Basic HTML Metadata Extraction
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract title from HTML if Readability fails
	 */
	private function extractTitleFromHtml(string $html): string {
		if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
			return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
		}
		return 'Untitled Article';
	}

	/**
	 * Extract og:image or twitter:image from HTML meta tags
	 */
	private function extractOgImage(string $html): ?string {
		// Try og:image (most common)
		if (preg_match('/<meta\s+(?:property=["\']og:image["\']\s+content|content=["\']([^"\']+)["\']\s+property=["\']og:image)["\']?\s*(?:content=["\']([^"\']+)["\'])?[^>]*>/i', $html, $matches)) {
			// Handle both attribute orders: property first or content first
			$img = !empty($matches[2]) ? $matches[2] : (!empty($matches[1]) ? $matches[1] : null);
			if ($img) return trim($img);
		}

		// Simpler og:image pattern
		if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		// Reverse attribute order
		if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		// Twitter card image as fallback
		if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}
		if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		return null;
	}

	/**
	 * Scan raw HTML for the first prominent hero image when no og:image / twitter:image is present.
	 *
	 * Readability entfernt <figure>-Elemente häufig, wenn sie vor dem Fließtext
	 * stehen oder tief verschachtelt sind. Diese Methode rettet Bild und Caption,
	 * bevor Readability sie verliert.
	 *
	 * Sucht in dieser Reihenfolge:
	 *   1. Erstes <img> in einer <figure> innerhalb von <article> oder <main>
	 *   2. Erstes <img> in irgendeiner <figure> im Dokument
	 *   3. Erstes <img> im <article>- oder <main>-Bereich (ohne figure-Wrapper)
	 *
	 * data-src wird als Fallback für lazy-load-Bilder berücksichtigt.
	 * Tracking-Pixel (1x1, data:-URLs, Icon-/Logo-Klassen) werden übersprungen.
	 *
	 * @return array{src: string, caption: ?string}|null
	 */
	private function extractHeroImageFromHtml(string $html, string $baseUrl): ?array
	{
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$candidates = [
			// Prominenteste Position: figure in article/main
			'(//article | //main)//figure//img[@src or @data-src]',
			// Fallback: irgendeine figure im Dokument
			'//figure//img[@src or @data-src]',
			// Letzter Ausweg: erstes img in article/main ohne figure-Wrapper
			'(//article | //main)//img[@src or @data-src]',
		];

		foreach ($candidates as $query) {
			$nodes = $xpath->query($query);
			if (!$nodes || $nodes->length === 0) continue;

			foreach ($nodes as $img) {
				if (!$img instanceof \DOMElement) continue;

				// data-src bevorzugen bei lazy-loading, sonst src
				$src = trim($img->getAttribute('src'));
				if ($src === '' || str_starts_with($src, 'data:')) {
					$src = trim($img->getAttribute('data-src'));
				}
				if ($src === '' || str_starts_with($src, 'data:')) continue;

				// Tracking-Pixel und Dekorations-Icons ausschließen
				$class = strtolower($img->getAttribute('class'));
				if (str_contains($src, '1x1') || str_contains($class, 'icon') || str_contains($class, 'logo')) continue;

				// <figcaption> aus dem nächstgelegenen <figure>-Elternelement holen.
				// Wir wandern vom img-Knoten aufwärts, bis wir eine <figure> finden,
				// dann suchen wir darin nach <figcaption>.
				$caption = null;
				$ancestor = $img->parentNode;
				while ($ancestor !== null && strtolower($ancestor->nodeName) !== 'figure') {
					$ancestor = $ancestor->parentNode;
				}
				if ($ancestor instanceof \DOMElement) {
					$captionNodes = $xpath->query('.//figcaption', $ancestor);
					if ($captionNodes && $captionNodes->length > 0) {
						$captionText = trim($captionNodes->item(0)->textContent);
						$caption = $captionText !== '' ? $captionText : null;
					}
				}

				return [
					'src'     => $this->normalizeUrl($src, $baseUrl),
					'caption' => $caption,
				];
			}
		}

		return null;
	}

	/**
	 * Extract site name from HTML meta tags or URL
	 */
	private function extractSiteName(string $html, string $url): ?string {
		// Extract from domain
		$parsedUrl = parse_url($url);
		if (isset($parsedUrl['host'])) {
			return preg_replace('/^www\./i', '', $parsedUrl['host']);
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Pre-Readability HTML Normalization
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Normalise image+caption structures in raw HTML before Readability parses it.
	 *
	 * Domain-specific rules from <images><caption container-xpath="..." caption-xpath="..."/>
	 * in the per-domain content-filter XML are applied.
	 *
	 * For each matched container:
	 *   1. The <img> inside the container is found.
	 *   2. The caption text is extracted via caption-xpath (relative to container).
	 *   3. The whole container is replaced with a <figure><img><figcaption>…</figcaption></figure>.
	 *
	 * This ensures Readability sees and preserves proper figure+figcaption structures,
	 * which are then rendered correctly in the reader for ALL images in the article body.
	 * The hero image extraction also benefits because it searches for <figcaption> within <figure>.
	 */
	private function normalizeImageCaptions(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		try {
			$config = $this->loadDomainConfig($domain);
			if ($config === null || !isset($config->images->caption)) {
				return $html;
			}

			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML(
				'<?xml encoding="utf-8" ?>' . $html,
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$xpath = new \DOMXPath($dom);

			foreach ($config->images->caption as $rule) {
				$containerXpath = trim((string) ($rule['container-xpath'] ?? ''));
				$captionXpath   = trim((string) ($rule['caption-xpath'] ?? ''));
				if ($containerXpath === '' || $captionXpath === '') continue;

				$containerResult = @$xpath->query($containerXpath);
				if ($containerResult === false) {
					$this->logger->warning('content-filters: invalid images container-xpath skipped', [
						'xpath'  => $containerXpath,
						'domain' => $domain,
					]);
					$trace?->record('images', $rule, 0, 'Ungültiger XPath-Ausdruck');
					continue;
				}

				$containers = iterator_to_array($containerResult);
				// Gezählt wird der Container-Treffer, nicht die ersetzte Figure:
				// so unterscheidet die UI "XPath trifft nichts" von "XPath trifft,
				// aber im Container steckt kein <img>".
				$trace?->record('images', $rule, count($containers));

				foreach ($containers as $container) {
					if (!$container instanceof \DOMElement) continue;

					// Find the <img> inside the container
					$imgNodes = $xpath->query('.//img[@src or @data-src]', $container);
					if (!$imgNodes || $imgNodes->length === 0) continue;
					$img = $imgNodes->item(0);
					if (!$img instanceof \DOMElement) continue;

					// Extract caption text
					$captionResult = @$xpath->query($captionXpath, $container);
					if ($captionResult === false) {
						$this->logger->warning('content-filters: invalid images caption-xpath skipped', [
							'xpath'  => $captionXpath,
							'domain' => $domain,
						]);
						continue;
					}
					// caption-xpath kann ein Union-Ausdruck sein (z.B.
					// ".//p/text()[not(parent::b)] | .//p/b[@class='credit']"),
					// der mehrere Knoten in Dokumentreihenfolge liefert – etwa
					// Fließtext-Textknoten UND ein separates Credit-Element.
					// item(0) allein hätte hier nur den ersten Treffer genommen
					// und den Rest (inkl. Credit) stillschweigend verworfen.
					$captionText = '';
					if ($captionResult->length > 0) {
						$parts = [];
						foreach ($captionResult as $node) {
							$text = trim($node->textContent);
							if ($text !== '') {
								$parts[] = $text;
							}
						}
						$captionText = trim(implode(' ', $parts));
					}

					// Build <figure><img ...><figcaption>…</figcaption></figure>
					// "merlin-content-figure" enthält "content" → matcht Readabilitys
					// okMaybeItsACandidate-Regex → Element überlebt den unlikelyCandidates-Pass.
					$figure = $dom->createElement('figure');
					$figure->setAttribute('class', 'merlin-content-figure');
					$figure->appendChild($img->cloneNode(true));
					if ($captionText !== '') {
						$figcaption = $dom->createElement('figcaption');
						$figcaption->setAttribute('class', 'merlin-content-figcaption');
						$figcaption->textContent = $captionText;
						$figure->appendChild($figcaption);
					}

					// Wenn ein Custom-Element-Ancestor existiert (Tag-Name enthält "-"),
					// wird dieser durch die figure ersetzt – Readability würde sonst den
					// gesamten Custom-Element-Baum (z.B. <a-lightbox>) verwerfen.
					$replaceTarget = $container;
					$ancestor = $container->parentNode;
					while ($ancestor instanceof \DOMElement && strtolower($ancestor->nodeName) !== 'body') {
						if (str_contains($ancestor->nodeName, '-')) {
							$replaceTarget = $ancestor;
						}
						$ancestor = $ancestor->parentNode;
					}

					if ($replaceTarget->parentNode !== null) {
						$replaceTarget->parentNode->replaceChild($figure, $replaceTarget);
					}
				}
			}

			return $dom->saveHTML() ?: $html;
		} catch (\Throwable) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Normalise quote structures in raw HTML before Readability parses it.
	 *
	 * Three passes:
	 *   1. Domain-specific rules: <quotes><quote container-xpath="..." text-xpath="..." author-xpath="..."/></quotes>
	 *      in the per-domain content-filter XML are applied first.
	 *   2. Standard <blockquote> elements receive class="merlin-quote" and their
	 *      bare inline content is wrapped in <p class="merlin-quote__text">.
	 *   3. <q> elements receive class="merlin-quote-inline" for CSS styling.
	 *
	 * keepClasses=true on the Readability config ensures all added classes survive.
	 */
	private function normalizeQuotes(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		try {
			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML(
				'<?xml encoding="utf-8" ?>' . $html, 
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$xpath = new \DOMXPath($dom);

			// ── Pass 1: domain-specific quote rules ────────────────────────────
			$config = $this->loadDomainConfig($domain);
			if ($config !== null && isset($config->quotes->quote)) {
				foreach ($config->quotes->quote as $rule) {
					$containerXpath = trim((string) ($rule['container-xpath'] ?? ''));
					$textXpath      = trim((string) ($rule['text-xpath'] ?? ''));
					$authorXpath    = trim((string) ($rule['author-xpath'] ?? ''));
					if ($containerXpath === '') continue;

					$containerResult = @$xpath->query($containerXpath);
					if ($containerResult === false) {
						$this->logger->warning('content-filters: invalid quotes container-xpath skipped', [
							'xpath'  => $containerXpath,
							'domain' => $domain,
						]);
						$trace?->record('quotes', $rule, 0, 'Ungültiger XPath-Ausdruck');
						continue;
					}

					$containers = iterator_to_array($containerResult);
					$trace?->record('quotes', $rule, count($containers));

					foreach ($containers as $container) {
						if ($textXpath !== '') {
							$textResult = $xpath->query($textXpath, $container);
							$textEl = ($textResult !== false) ? $textResult->item(0) : null;
						} else {
							$textEl = $container;
						}
						if (!$textEl instanceof \DOMElement) continue;

						$authorEl = null;
						if ($authorXpath !== '') {
							$authorResult = $xpath->query($authorXpath, $container);
							$authorEl = ($authorResult !== false) ? $authorResult->item(0) : null;
						}

						$bq = $this->buildReaderQuoteNode(
							$dom,
							$textEl,
							$authorEl instanceof \DOMElement ? $authorEl : null
						);
						$container->parentNode->replaceChild($bq, $container);
					}
				}
			}

			// ── Pass 2: standard <blockquote> normalisation ────────────────────
			$blockquotes = iterator_to_array($xpath->query('//blockquote') ?: []);
			foreach ($blockquotes as $bq) {
				if (!$bq instanceof \DOMElement) continue;
				$class = $bq->getAttribute('class');
				if (str_contains($class, 'merlin-quote')) continue; // already processed

				$bq->setAttribute('class', trim('merlin-quote ' . $class));

				// Wrap bare inline content in <p class="merlin-quote__text">
				// only when the blockquote has no block-level children yet.
				$hasBlock = false;
				foreach ($bq->childNodes as $child) {
					if ($child instanceof \DOMElement && in_array(
						strtolower($child->nodeName),
						['p', 'div', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
						true
					)) {
						$hasBlock = true;
						break;
					}
				}
				if (!$hasBlock) {
					$p = $dom->createElement('p');
					$p->setAttribute('class', 'merlin-quote__text');
					foreach (iterator_to_array($bq->childNodes) as $child) {
						$p->appendChild($child);
					}
					$bq->appendChild($p);
				}
			}

			// ── Pass 3: <q> inline quotes ──────────────────────────────────────
			$qEls = iterator_to_array($xpath->query('//q') ?: []);
			foreach ($qEls as $q) {
				if (!$q instanceof \DOMElement) continue;
				$existing = $q->getAttribute('class');
				if (!str_contains($existing, 'merlin-quote-inline')) {
					$q->setAttribute('class', trim('merlin-quote-inline ' . $existing));
				}
			}

			/* Return body content – same format Readability expects.
			$body = $dom->getElementsByTagName('body')->item(0);
			if (!$body) return $html;
			$out = '';
			foreach ($body->childNodes as $child) {
				$out .= $dom->saveHTML($child);
			}*/
			//return $out ?: $html;
			$out = $dom->saveHTML();
			return $out ?: $html;
		} catch (\Throwable $e) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Build a <blockquote class="merlin-quote"> node from extracted quote elements.
	 */
	private function buildReaderQuoteNode(
		\DOMDocument $dom,
		\DOMElement  $quoteEl,
		?\DOMElement $authorEl
	): \DOMElement {
		$blockquote = $dom->createElement('blockquote');
		$blockquote->setAttribute('class', 'merlin-quote');

		// Inner quote text wrapped in <p>
		$p = $dom->createElement('p');
		$p->setAttribute('class', 'merlin-quote__text');
		foreach (iterator_to_array($quoteEl->childNodes) as $child) {
			$p->appendChild($child->cloneNode(true));
		}
		$blockquote->appendChild($p);

		// Author as <cite> if present
		if ($authorEl !== null) {
			$cite = $dom->createElement('cite');
			$cite->setAttribute('class', 'merlin-quote__author');
			$cite->textContent = trim(strip_tags($dom->saveHTML($authorEl)));
			$blockquote->appendChild($cite);
		}

		return $blockquote;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Per-domain filter & metadata system
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Normalise a URL host to a bare domain name for config-file lookup.
	 * Strips www. prefix; no further subdomain stripping (exact match semantics).
	 *
	 * Delegiert an DomainConfigProvider, weil eine mögliche spätere Admin-UI
	 * dieselbe Normalisierung braucht (Prüfung, ob eine Test-URL zum
	 * bearbeiteten Filter gehört) und zwei Kopien dieser Regel auseinanderlaufen
	 * würden.
	 */
	private function normalizeDomain(string $url): string {
		return $this->domainConfig->normalizeUrlDomain($url);
	}

	/**
	 * Load the per-domain config for $domain.
	 * Returns null when there is no bundled filter for this domain.
	 *
	 * Liefert dieselbe dreistufige Bundle/Admin/User-Config wie
	 * merlin-nextcloud (siehe Db\ContentFilterRepository::getMerged()). Alle
	 * acht Aufrufstellen dieser Methode sehen weiterhin ein einzelnes
	 * SimpleXMLElement und bleiben unverändert.
	 */
	private function loadDomainConfig(string $domain): ?\SimpleXMLElement {
		return $this->domainConfig->getMerged($domain, $this->currentUserId);
	}

	/**
	 * Apply a list of SimpleXMLElement <remove> nodes to an HTML string via DOM.
	 * Shared helper used by applyPreFilters() and applyPostFilters().
	 *
	 * @param \SimpleXMLElement[] $rules
	 * @param bool $returnFullDocument  true  → vollständiges HTML-Dokument zurückgeben (Pre-Filter, Readability erwartet das)
	 *                                  false → nur Body-Children zurückgeben (Post-Filter, Readability-Extrakt ist ein Fragment)
	 * @param string|null $traceSection Sektionsname für den Trace (null = keine Diagnose)
	 */
	private function applyRemoveRules(
		string $html,
		array $rules,
		string $context = '',
		bool $returnFullDocument = false,
		?ContentFilterTrace $trace = null,
		?string $traceSection = null
	): string {
		if (empty($html)) {
			return $html;
		}

		// <script>- und <style>-Tags (inkl. Inhalt) per RegEx entfernen, bevor der DOM-Parser
		// den String verarbeitet. So werden auch komplexe JS-Inhalte mit <, >, & oder --
		// zuverlässig entfernt, ohne dass sie den DOM-Baum beschädigen können.
		$html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html) ?? $html;
		$html = preg_replace('/<style\b[^>]*>.*?<\/style>/si',  '', $html) ?? $html;

		$prev = libxml_use_internal_errors(true);
		$dom = new \DOMDocument('1.0', 'UTF-8');
		// XML-PI für Encoding-Hint: libxml wertet ihn aus, fügt ihn aber NICHT in den DOM-Baum ein.
		// LIBXML_NOENT absichtlich weggelassen: es würde &amp; → & usw. konvertieren und beim
		// Zurückserializieren zu ungültigem HTML führen.
		// LIBXML_NOBLANKS absichtlich weggelassen: es entfernt Whitespace-Text-Nodes und verändert Layout.
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . 
			$html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath    = new \DOMXPath($dom);
		$toRemove = [];

		// Je Regel abfragen statt die Regeln vorher in drei Listen nach Typ zu
		// flachzuklopfen: nur so lässt sich die Trefferzahl der EINZELNEN Regel
		// erfassen, die die Admin-UI anzeigt. Die entfernte Knotenmenge ist
		// identisch – alle Abfragen laufen weiterhin vollständig, bevor der erste
		// Knoten entfernt wird (sonst würden spätere Regeln auf einem bereits
		// veränderten Baum arbeiten).
		foreach ($rules as $rule) {
			$error   = null;
			$matches = 0;

			if (isset($rule['id'])) {
				$esc    = str_replace('"', '\\"', (string) $rule['id']);
				$result = $xpath->query('//*[@id="' . $esc . '"]');
			} elseif (isset($rule['class'])) {
				// Wortgrenzen-Match: verhindert, dass "foo" auch "foobar" oder "prefix-foo" trifft.
				$esc    = str_replace("'", "", (string) $rule['class']); // einfache Anführungszeichen im Klassenname sind extrem selten
				$result = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $esc . " ')]");
			} elseif (isset($rule['xpath'])) {
				$expr = trim((string) $rule['xpath']);
				if ($expr === '') {
					continue;
				}
				$result = @$xpath->query($expr);
				if ($result === false) {
					$this->logger->warning('content-filters: invalid XPath skipped', [
						'xpath'   => $expr,
						'context' => $context,
					]);
					$error = 'Ungültiger XPath-Ausdruck';
				}
			} else {
				continue;
			}

			if ($result === false) {
				// Auch die id-/class-Zweige können scheitern: XPath kennt keine
				// Backslash-Escapes, ein Anführungszeichen im id-Wert erzeugt also
				// einen ungültigen Ausdruck. Für die Diagnose in der Admin-UI ist
				// "fehlerhaft" die richtige Auskunft, nicht "0 Treffer".
				$error ??= 'Regel konnte nicht ausgewertet werden';
			} else {
				foreach ($result as $node) {
					$toRemove[] = $node;
					$matches++;
				}
			}

			if ($trace !== null && $traceSection !== null) {
				$trace->record($traceSection, $rule, $matches, $error);
			}
		}

		foreach ($toRemove as $node) {
			if ($node->parentNode !== null) {
				$node->parentNode->removeChild($node);
			}
		}

		// Pre-Filter: vollständiges Dokument zurückgeben, damit Readability damit arbeiten kann.
		if ($returnFullDocument) {
			$out = $dom->saveHTML();
			if ($out === false || $out === '') {
				return $html;
			}
			// Die XML-PI (<?xml encoding="utf-8" ?) kann im Output auftauchen — rausstreifen,
			// damit Readability kein ungültiges Präfix sieht.
			//$out = preg_replace('/^<\?xml[^?]*\>\s*/i', '', $out) ?? $out;
			$out = substr($out, strpos($out, '<html'));//
			return $out;
		}

		// Post-Filter: nur Body-Children zurückgeben — saveHTML() ohne Argument würde das komplette
		// Dokument (<!DOCTYPE>, <html>, <head>, <body>) zurückgeben und so Fragmente zerschießen.
		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body !== null) {
			$out = '';
			foreach ($body->childNodes as $child) {
				$serialized = $dom->saveHTML($child);
				if ($serialized !== false) {
					$out .= $serialized;
				}
			}
			if ($out === '') {
				return $html;
			}
			return $out;
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		return $out;
	}

	/**
	 * Apply <pre-filter> remove rules from the per-domain config.
	 * Runs on the raw fetched HTML BEFORE Readability.
	 */
	private function applyPreFilters(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/remove') ?: [];
		// returnFullDocument=true: Readability braucht ein vollständiges HTML-Dokument als Input.
		return $this->applyRemoveRules($html, $rules, $domain, true, $trace, 'pre-filter');
	}

	/**
	 * Apply <post-filter> remove rules to the Readability-extracted HTML.
	 * Runs AFTER Readability, on the extracted content DOM.
	 */
	private function applyPostFilters(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		if (empty($html)) {
			return $html;
		}
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('post-filter/remove') ?: [];
		return $this->applyRemoveRules($html, $rules, $domain, false, $trace, 'post-filter');
	}

	/**
	 * Apply <pre-filter><infobox> rules: add the CSS class 'merlin-infobox' to
	 * matched elements BEFORE Readability runs, so the class survives parsing.
	 *
	 * Supported attributes (identical to <remove>):
	 *   <infobox id="element-id" />
	 *   <infobox class="teilstring" />
	 *   <infobox xpath="//div[@class='info-box']" />
	 *
	 * Returns a full HTML document (same contract as applyPreFilters).
	 */
	private function applyInfoboxMarkers(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/infobox') ?: [];
		if (empty($rules)) {
			return $html;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->encoding = 'UTF-8';
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath  = new \DOMXPath($dom);
		$toMark = [];

		foreach ($rules as $rule) {
			if (isset($rule['id'])) {
				$esc  = str_replace('"', '\\"', (string) $rule['id']);
				$expr = '//*[@id="' . $esc . '"]';
			} elseif (isset($rule['class'])) {
				$esc  = str_replace("'", '', (string) $rule['class']);
				$expr = "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $esc . " ')]";
			} elseif (isset($rule['xpath'])) {
				$expr = trim((string) $rule['xpath']);
				if ($expr === '') {
					continue;
				}
			} else {
				continue;
			}

			$result  = @$xpath->query($expr);
			$matches = 0;
			if ($result === false) {
				$this->logger->warning('content-filters: invalid infobox XPath skipped', [
					'xpath'   => $expr,
					'context' => $domain,
				]);
				$trace?->record('pre-filter', $rule, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}
			foreach ($result as $node) {
				$toMark[] = $node;
				$matches++;
			}
			$trace?->record('pre-filter', $rule, $matches);
		}

		foreach ($toMark as $node) {
			if (!($node instanceof \DOMElement)) {
				continue;
			}
			$existing = $node->getAttribute('class');
			$classes  = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY);
			if (!in_array('merlin-infobox', $classes, true)) {
				$node->setAttribute('class', trim($existing . ' merlin-infobox'));
			}
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		$out = preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $out) ?? $out;
		return $out;
	}

	/**
	 * Apply <pre-filter><saveElements> rules: add a specified CSS class to
	 * elements matched by XPath BEFORE Readability runs, so the class survives.
	 *
	 * XML syntax (inside <pre-filter>):
	 *   <saveElements xpath="//aside[@data-type='infobox']" class="merlin-sidebar" />
	 *
	 * Multiple rules per domain are supported; each rule requires both
	 * xpath and class attributes. Invalid XPaths are logged and skipped.
	 *
	 * Returns a full HTML document (same contract as applyPreFilters).
	 */
	private function applyClassMarkers(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/saveElements') ?: [];
		if (empty($rules)) {
			return $html;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->encoding = 'UTF-8';
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);

		foreach ($rules as $rule) {
			$xpathExpr = trim((string) ($rule['xpath'] ?? ''));
			$class     = trim((string) ($rule['class'] ?? ''));

			if ($xpathExpr === '' || $class === '') {
				continue;
			}

			$result = @$xpath->query($xpathExpr);
			if ($result === false) {
				$this->logger->warning('content-filters: invalid saveElements XPath skipped', [
					'xpath'   => $xpathExpr,
					'context' => $domain,
				]);
				$trace?->record('pre-filter', $rule, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}

			$matches = 0;
			foreach ($result as $node) {
				$matches++;
				if (!($node instanceof \DOMElement)) {
					continue;
				}
				$existing = $node->getAttribute('class');
				$classes  = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY);
				if (!in_array($class, $classes, true)) {
					$node->setAttribute('class', trim($existing . ' ' . $class));
				}
			}
			$trace?->record('pre-filter', $rule, $matches);
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		$out = preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $out) ?? $out;
		return $out;
	}

	/**
	 * og:/article: XPaths used as fallback for every field that has no custom
	 * XPath in the domain config.  These run for all domains automatically.
	 */
	private const OG_FALLBACK_XPATHS = [
		'title'     => "//meta[@property='og:title']/@content",
		'excerpt'   => "//meta[@property='og:description'] | //meta[@name='twitter:description']/@content",
		'image'     => "//meta[@property='og:image']/@content",
		'author'    => "//meta[@property='article:author']/@content",
		'published' => "//meta[@property='article:published_time']/@content",
	];

	/**
	 * Extract metadata from the pre-filtered raw HTML.
	 *
	 * Per field the resolution order is:
	 *   1. Custom XPath from the domain's <metadata> section (if configured)
	 *   2. JSON path from the domain's <metadata> section (if configured)
	 *   3. Automatic og:/article: fallback (see OG_FALLBACK_XPATHS)
	 *
	 * Returns a sparse array — only fields where a non-empty value was found.
	 * Values override Readability's results in extract().
	 *
	 * For attribute XPaths (e.g. //meta[...]/@content) the attribute value is
	 * returned; for element XPaths the trimmed textContent.
	 *
	 * @return array{title?: string, author?: string, excerpt?: string, image?: string, published?: string}
	 */
	private function extractDomainMetadata(string $html, string $domain, ?ContentFilterTrace $trace = null): array {
		$config = $this->loadDomainConfig($domain);
		$meta   = ($config !== null && isset($config->metadata)) ? $config->metadata : null;

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML(
			'<?xml version="1.0" encoding="UTF-8"?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		//$path = __DIR__ . "/../../test/extractDomainMetadata.html";
		//file_put_contents($path, $html);

		$xpath       = new \DOMXPath($dom);
		$jsonSources = $this->extractJsonSources($html, $config, $domain, $trace);
		$result      = [];

		foreach (array_keys(self::OG_FALLBACK_XPATHS) as $field) {
			// 1. Build ordered list of XPath expressions and JSON paths to try.
			//    A field may have multiple sibling elements in the domain config
			//    (e.g. two <author xpath="..."/> rules for different page layouts).
			//    SimpleXML's foreach iterates all of them in document order.
			//    Jeder Eintrag trägt seinen Regelknoten mit (rule = null beim
			//    automatischen og:-Fallback), damit der Trace die Trefferzahl der
			//    EINZELNEN Regel zuordnen kann statt nur des Feldes.
			$xpathExprs = [];
			$jsonPaths  = [];
			if ($meta !== null && isset($meta->$field)) {
				foreach ($meta->$field as $el) {
					// Two-step avoids PHP parsing $meta->$field['xpath']
					// as $meta->{$field['xpath']} (string-offset bug).
					$e = trim((string) ($el['xpath'] ?? ''));
					if ($e !== '') {
						$xpathExprs[] = ['expr' => $e, 'rule' => $el];
					}
					$j = trim((string) ($el['json'] ?? ''));
					if ($j !== '') {
						$jsonPaths[] = ['path' => $j, 'rule' => $el];
					}
				}
			}

			// 2. Always append the og:/article: fallback so it's tried when all
			//    custom rules are absent or return no match.
			$xpathExprs[] = ['expr' => self::OG_FALLBACK_XPATHS[$field], 'rule' => null];

			// 3. Try XPath expressions first; first non-empty result wins
			$value = '';
			foreach ($xpathExprs as $candidate)
			{
				$expr = $candidate['expr'];
				$rule = $candidate['rule'];

				$nodes = @$xpath->query($expr);
				if ($nodes === false) {
					if ($trace !== null && $rule !== null) {
						$trace->record('metadata', $rule, 0, 'Ungültiger XPath-Ausdruck');
					}
					continue;
				}
				if ($trace !== null && $rule !== null) {
					$trace->record('metadata', $rule, $nodes->length);
				}
				if ($nodes->length === 0) {
					continue;
				}
				$value = [];
				foreach ($nodes as $node)
				{
					$value[] = match (true)
					{
						$node instanceof \DOMAttr    => trim($node->value),
						$node instanceof \DOMText    => trim($node->nodeValue ?? ''),
						$node instanceof \DOMElement => trim($node->textContent),
						default => ''
					};
				}

				if ($value !== []) {
					break; // first hit wins
				}
			}

			// 4. If XPath found nothing, try JSON paths
			if ($value === '' && !empty($jsonPaths)) {
				foreach ($jsonPaths as $jsonCandidate) {
					$jsonPath = $jsonCandidate['path'];
					$jsonRule = $jsonCandidate['rule'];

					// Syntax: "sourceId:$.path"  — sourceId must NOT start with "$"
					//         "$.path"            — no prefix → use "default" source
					// The "$" guard prevents misidentifying a plain JSONPath (which
					// may contain ":" inside key names) as a prefixed expression.
					if (!str_starts_with($jsonPath, '$') && str_contains($jsonPath, ':')) {
						[$sourceId, $actualPath] = explode(':', $jsonPath, 2);
						$sourceId   = trim($sourceId);
						$actualPath = trim($actualPath);
					} else {
						$sourceId   = 'default';
						$actualPath = $jsonPath;
					}

					$sourceData = $jsonSources[$sourceId] ?? null;
					if ($sourceData === null) {
						if ($trace !== null && $jsonRule !== null) {
							$trace->record('metadata', $jsonRule, 0, 'JSON-Quelle "' . $sourceId . '" nicht gefunden');
						}
						continue;
					}

					$resolved = $this->resolveJsonPath($sourceData, $actualPath);
					if ($trace !== null && $jsonRule !== null) {
						$trace->record('metadata', $jsonRule, ($resolved !== null && $resolved !== '') ? 1 : 0);
					}
					if ($resolved !== null && $resolved !== '') {
						$value = [$resolved];
						break;
					}
				}
			}

			if ($value !== '')
			{
				if (count($value) > 1)
					$value = implode('', $value);
				else
					$value = $value[0];

				$result[$field] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
			}
		}

		// ── Static category declaration ──────────────────────────────────────────
		// <category>Video</category> in the domain config assigns a fixed category
		// without needing to parse the HTML (no XPath required).
		if ($config !== null && isset($config->category)) {
			$cat = trim((string) $config->category);
			if ($cat !== '') {
				$result['category'] = $cat;
			}
		}

		return $result;
	}

	/**
	 * Extract all named JSON sources declared in the domain config.
	 *
	 * XML syntax (inside <domain>, before <metadata>):
	 *   <json id="ld"   xpath="//script[@type='application/ld+json']" index="0" />
	 *   <json id="next" xpath="//script[@id='__NEXT_DATA__']" />
	 *
	 *   id     – Required. Referenced from metadata fields as "id:$.path".
	 *   xpath  – Required. Selects the <script> element(s) containing the JSON.
	 *   index  – Optional (default: 0). Which matched element to use (0-based).
	 *
	 * If no <json> elements are defined, one source with id "default" is
	 * auto-registered pointing at <script type="application/ld+json"> (index 0).
	 *
	 * @return array<string, mixed>  Map of id → decoded JSON (associative array)
	 */
	private function extractJsonSources(
		string $html,
		?\SimpleXMLElement $config,
		string $domain,
		?ContentFilterTrace $trace = null
	): array
	{
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML(
			'<?xml version="1.0" encoding="UTF-8"?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		// Use direct SimpleXML property access instead of xpath('json') —
		// SimpleXML's xpath() can silently return [] for direct children
		// depending on context registration, making all sources fall through
		// to the auto-default. Property access ($config->json) is reliable.
		$hasExplicitDefs = ($config !== null && count($config->json) > 0);

		$sources = [];

		if (!$hasExplicitDefs) {
			// Auto-default: use first <script type="application/ld+json"> as "default"
			$nodes = @$xpath->query("//script[@type='application/ld+json']");
			if ($nodes !== false && $nodes->length > 0) {
				$json    = trim($nodes->item(0)->textContent ?? '');
				$decoded = $json !== '' ? json_decode($json, true) : null;
				if ($decoded !== null) {
					$sources['default'] = $decoded;
				}
			}
		}

		foreach (($hasExplicitDefs ? $config->json : []) as $def) {
			$id        = trim((string) ($def['id']    ?? ''));
			$xpathExpr = trim((string) ($def['xpath'] ?? ''));
			$index     = (int) ($def['index'] ?? 0);

			if ($id === '' || $xpathExpr === '') {
				$this->logger->warning('content-filters: <json> element missing id or xpath, skipped', ['domain' => $domain]);
				$trace?->record('json', $def, 0, 'id oder xpath fehlt');
				continue;
			}

			$nodes = @$xpath->query($xpathExpr);
			if ($nodes === false) {
				$trace?->record('json', $def, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}

			// Ohne diesen Zähler sähe der Admin bei einer nicht gefundenen Quelle
			// nur "JSON-Quelle nicht gefunden" am metadata-Feld – nicht, dass schon
			// der Quell-XPath hier ins Leere lief.
			$trace?->record('json', $def, $nodes->length);

			if ($nodes->length === 0) {
				continue;
			}

			// Use requested index, fall back to last available element
			$node = $nodes->item($index) ?? $nodes->item($nodes->length - 1);
			if ($node === null) {
				continue;
			}

			$json = trim($node->textContent ?? '');
			if ($json === '') {
				$trace?->record('json', $def, $nodes->length, 'Gefundenes Element ist leer');
				continue;
			}

			$decoded = json_decode($json, true);
			if ($decoded === null) {
				$this->logger->warning('content-filters: JSON source could not be decoded', [
					'id'     => $id,
					'domain' => $domain,
				]);
				$trace?->record('json', $def, $nodes->length, 'Inhalt ist kein gültiges JSON');
				continue;
			}

			$sources[$id] = $decoded;

			// First defined <json> element is also reachable as "default",
			// so json="$.path" (no prefix) works even when explicit sources exist.
			if (!isset($sources['default'])) {
				$sources['default'] = $decoded;
			}
		}

		return $sources;
	}

	/**
	 * Resolve a JSONPath-style expression against decoded JSON data.
	 *
	 * Supported syntax:
	 *   $.key              – top-level key
	 *   $.key.subkey       – nested key
	 *   $.array[0].key     – array index + key
	 *   $[0].key           – top-level array index
	 *
	 * Returns the string value of the resolved leaf, or null when the path
	 * does not match or the resolved value is not scalar.
	 */
	private function resolveJsonPath(mixed $data, string $path): ?string
	{
		// Strip leading "$" / "$."
		$path = ltrim($path, '$');
		if (str_starts_with($path, '.')) {
			$path = substr($path, 1);
		}

		if ($path === '') {
			return is_scalar($data) ? (string) $data : null;
		}

		// Tokenize on "." — array indices stay attached to their token ("key[0]")
		$tokens  = explode('.', $path);
		$current = $data;

		foreach ($tokens as $token) {
			if ($current === null) {
				return null;
			}

			// Token with array index: "key[n]" or "[n]"
			if (preg_match('/^([^\[]*)\[(\d+)\]$/', $token, $m)) {
				$key   = $m[1];
				$index = (int) $m[2];

				if ($key !== '') {
					if (!is_array($current) || !array_key_exists($key, $current)) {
						return null;
					}
					$current = $current[$key];
				}

				if (!is_array($current) || !isset($current[$index])) {
					return null;
				}
				$current = $current[$index];
			} else {
				if (!is_array($current) || !array_key_exists($token, $current)) {
					return null;
				}
				$current = $current[$token];
			}
		}

		return is_scalar($current) ? (string) $current : null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Content Cleanup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Clean HTML content
	 */
	private function cleanHtml(string $html): string {
		// Remove script and style tags
		$html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
		$html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

		// Remove linked CSS stylesheets
		$html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*>/is', '', $html);
		$html = preg_replace('/<link\b[^>]+href=[^>]+rel=["\']stylesheet["\'][^>]*>/is', '', $html);

		// Remove margin and padding from inline style attributes
		$html = preg_replace_callback(
			'/(<[^>]+\bstyle=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				$style = preg_replace(
					'/\b(?:margin|padding)(?:-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?\s*:[^;]*;?\s*/i',
					'',
					$m[2]
				);
				$style = trim($style ?? '', " \t\n\r;");
				if ($style === '') {
					// Drop the entire style attribute
					return preg_replace('/\s*\bstyle=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
				}
				return $m[1] . $style . $m[3];
			},
			$html
		) ?? $html;

		// Strip every class token except Merlin's own merlin-* marker classes
		// (merlin-infobox, merlin-quote, merlin-hero-image, …).
		//
		// keepClasses=true on the Readability config (see processHtml()) is needed
		// so those marker classes survive parsing — but it also lets every class
		// from the SOURCE site through unfiltered. Those classes are inert today
		// (the source stylesheet is stripped above), but only by accident: if the
		// source site's class names ever happen to match a class Merlin's own CSS
		// or the Nextcloud host CSS defines (e.g. a WordPress/Tailwind site using
		// generic utility names like "fixed", "absolute", "hidden", "row"), the
		// foreign element would suddenly be styled by Merlin's rules — exactly
		// what happened with amadeu-antonio-stiftung.de's leaked donation-box
		// markup (Tailwind classes "fixed", "pin-l", "pin-b", "absolute", …).
		// Allowlisting merlin-* closes this for every domain at once instead of
		// reacting to individual collisions. Every saveElements/<infobox> rule in
		// content-filters/*.xml already assigns merlin-*-prefixed classes only.
		$html = preg_replace_callback(
			'/(<[^>]+\bclass=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				$classes = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
				$kept = array_values(array_filter(
					$classes,
					static fn(string $c): bool => str_starts_with($c, 'merlin-')
				));
				if ($kept === []) {
					// Drop the entire class attribute
					return preg_replace('/\s*\bclass=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
				}
				return $m[1] . implode(' ', $kept) . $m[3];
			},
			$html
		) ?? $html;

		// Strip every id attribute that wasn't explicitly set by Merlin itself
		// (none are, today — this is a forward-compatible merlin-* allowlist,
		// same convention as the class filter above).
		//
		// Source-site ids are exactly as dangerous as source-site classes: they're
		// inert once the source stylesheet/scripts are gone, but only by accident.
		// An id is also unique-per-document by spec, so a leaked source id (e.g.
		// "content", "main", "header") can collide with an id Merlin's own shell
		// chrome uses elsewhere in the same page, with unpredictable CSS/JS
		// side effects. The only thing this can break is same-document anchor
		// links (<a href="#fn1"> → <sup id="fn1">) inside the article, which is
		// an acceptable trade-off in a read-later reader view.
		$html = preg_replace_callback(
			'/(<[^>]+\bid=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				$id = trim($m[2]);
				if ($id !== '' && str_starts_with($id, 'merlin-')) {
					return $m[0];
				}
				// Drop the entire id attribute
				return preg_replace('/\s*\bid=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
			},
			$html
		) ?? $html;

		//$path = __DIR__ . "/../../test/cleanHtml.html";
		//file_put_contents($path, $html);

		// Remove comments
		$html = preg_replace('/<!--(.*)-->/Uis', '', $html);

		return trim($html);
	}

	/**
	 * DOM-basierter Allowlist-Sanitizer gegen Stored-XSS.
	 *
	 * Warum zusätzlich zu cleanHtml()? cleanHtml() arbeitet mit RegExp und filtert
	 * nur <script>/<style>, Klassen und IDs. Es entfernt NICHT die eigentlich
	 * gefährlichen Vektoren: Event-Handler-Attribute (onerror, onload, onclick, …),
	 * javascript:-/data:-URLs und gefährliche Tags (<iframe>, <object>, <embed>,
	 * <form>, …). Da der Inhalt per v-html gerendert wird – auch unauthentifiziert
	 * in der öffentlichen Share-Ansicht – wird hier über eine strikte Tag- und
	 * Attribut-Allowlist auf DOM-Ebene bereinigt.
	 *
	 * Allowlist statt Denylist: nur explizit erlaubte Tags/Attribute überleben,
	 * alles andere wird entfernt bzw. (bei unbekannten Tags) durch seinen Textinhalt
	 * ersetzt. Das ist gegen neue/obskure Vektoren robuster als eine Sperrliste.
	 */
	private function sanitizeHtml(string $html): string {
		if (trim($html) === '') {
			return $html;
		}

		// Erlaubte Tags – deckt den vom Reader gerenderten Inhalt ab (Fließtext,
		// Listen, Tabellen, Zitate, Bilder/Figuren, semantische Container).
		static $allowedTags = [
			'p', 'br', 'hr', 'span', 'div', 'section', 'article', 'header', 'footer', 'aside',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'mark', 'small', 'sub', 'sup',
			'a', 'blockquote', 'q', 'cite', 'code', 'pre', 'kbd', 'samp', 'var', 'abbr', 'time',
			'ul', 'ol', 'li', 'dl', 'dt', 'dd',
			'img', 'figure', 'figcaption', 'picture', 'source',
			'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
		];

		// Pro Tag erlaubte Attribute. Alles nicht Gelistete (insb. alle on*-Handler,
		// style, srcset auf beliebige URLs …) wird entfernt. `srcset` fehlt hier
		// bewusst — <source> trägt es laut Spec statt `src`, sodass jedes
		// <picture><source srcset="…"> sonst leerläuft und der Reader auf das
		// <img>-Fallback der Quellseite zurückfällt (bei taz.de z. B. bewusst ein
		// 14px-Platzhalter statt des echten Bilds). resolvePictureElements() liest
		// `srcset` deshalb VOR diesem Allowlist-Durchlauf aus und schreibt den besten
		// Kandidaten in ein einzelnes <img src>, das dann ganz normal die img-Regel
		// unten durchläuft.
		static $allowedAttrs = [
			'*'          => ['class', 'id', 'title', 'lang', 'dir'],
			'a'          => ['href', 'target', 'rel'],
			'img'        => ['src', 'alt', 'width', 'height'],
			'source'     => ['src', 'type', 'media'],
			'time'       => ['datetime'],
			'td'         => ['colspan', 'rowspan'],
			'th'         => ['colspan', 'rowspan', 'scope'],
			'col'        => ['span'],
			'colgroup'   => ['span'],
			// data-instgrm-permalink/-version: Instagrams offizielles Embed-Markup
			// (siehe isAllowedInstagramPermalink()). Ohne diese Attribute rendert
			// embed.js nur einen leeren Platzhalter statt des Posts.
			// data-bluesky-uri: Blueskys offizielles Embed-Markup (siehe
			// isAllowedBlueskyUri()) - analog für den Self-Thread-Zweig
			// (BlueskyThreadResolverService/ContentExtractorService, category=Thread).
			'blockquote' => ['data-instgrm-permalink', 'data-instgrm-version', 'data-bluesky-uri'],
			// iframe steht bewusst NICHT auf $allowedTags (generisches iframe-Embed
			// ist ein XSS-Vektor) – erlaubt sind nur Video-Embeds von vertrauens-
			// würdigen Hosts, siehe isAllowedVideoEmbedSrc(). Deren Attribute laufen
			// trotzdem durch dieselbe Allowlist-Logik, deshalb der Eintrag hier.
			'iframe'     => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'referrerpolicy'],
			// script steht ebenfalls bewusst NICHT auf $allowedTags – erlaubt sind
			// nur die beiden offiziellen Widget-Loader von Instagram/X, siehe
			// isAllowedWidgetScriptSrc(). Kein "onload" o. Ä. auf der Liste: das
			// Element darf ausschließlich diese drei harmlosen Lade-Attribute tragen.
			'script'     => ['src', 'async', 'charset'],
		];

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		// Wrapper-Element mit eindeutigem data-Attribut, um den Inhalt nach dem
		// Parsen zuverlässig wiederzufinden. XML-PI nur als Encoding-Hint.
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div data-merlin-sanitize-root="1">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		// getElementById() ist bei loadHTML() unzuverlässig (ohne DTD werden keine
		// IDs registriert) – deshalb den Wrapper per XPath über das data-Attribut holen.
		$xpath = new \DOMXPath($dom);
		$root  = $xpath->query('//div[@data-merlin-sanitize-root="1"]')->item(0);
		libxml_use_internal_errors($prev);

		if ($root === null) {
			// Parsing fehlgeschlagen – im Zweifel den Inhalt verwerfen statt
			// ungefiltert durchzureichen (fail-closed).
			return '';
		}

		// Muss VOR dem generischen Allowlist-Durchlauf laufen: der liest gleich
		// `srcset` aus den <source>-Kindern jedes <picture> aus, das Attribut
		// steht unten aber nicht auf der Allowlist und würde sonst weggeworfen,
		// bevor wir es je zu Gesicht bekommen.
		$this->resolvePictureElements($dom);

		// Läuft hier statt als eigener Pipeline-Schritt, weil der Content dafür
		// sonst ein zweites Mal komplett geparst werden müsste – und weil erst
		// jetzt ALLE Bildunterschriften im Baum stehen (die aus der Quellseite,
		// die von normalizeImageCaptions() umgebauten und die nachträglich
		// eingefügte Hero-Caption aus Step 7b).
		$this->flattenCaptions($dom);

		$allowedTagSet = array_flip($allowedTags);

		// Alle Elemente einsammeln, BEVOR wir den Baum verändern (eine Live-NodeList
		// während der Iteration zu mutieren überspringt Knoten).
		$elements = [];
		foreach ($dom->getElementsByTagName('*') as $el) {
			$elements[] = $el;
		}

		foreach ($elements as $el) {
			// Bereits durch das Entfernen eines Vorfahren aus dem Baum gelöst?
			if ($el->ownerDocument === null || $el->parentNode === null) {
				continue;
			}

			$tag = strtolower($el->nodeName);

			// Der Wrapper selbst wird nicht mit ausgegeben (nur seine Kinder),
			// daher hier überspringen.
			if ($el === $root) {
				continue;
			}

			// <iframe> ist grundsätzlich ein XSS-Vektor und steht deshalb nicht auf
			// $allowedTags – Ausnahme: Video-Embeds von vertrauenswürdigen Hosts
			// (taz.de, Blogs, … betten YouTube/Vimeo/Twitch/… häufig ein). Nur bei
			// einer Quelle aus isAllowedVideoEmbedSrc() bleibt das Element erhalten,
			// alles andere fällt auf den generischen Denylist-Zweig unten durch und
			// wird entfernt.
			if ($tag === 'iframe') {
				if ($this->isAllowedVideoEmbedSrc($el->getAttribute('src'))) {
					$this->sanitizeAttributes($el, $tag, $allowedAttrs);
					// Erzwungen statt nur erlaubt: der Server setzt standardmäßig
					// keinen Referrer-Policy-Header. Ohne einen Referrer verweigern
					// manche Player den Embed (z. B. YouTube mit "Error 153"). Das
					// per-Element-Attribut überschreibt die Seiten-Policy für genau
					// diesen iframe-Request – auf die Quellseite (die es i. d. R.
					// nicht mitliefert) ist hier kein Verlass, also selbst setzen
					// statt nur durchlassen.
					$el->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
				} else {
					$el->parentNode->removeChild($el);
				}
				continue;
			}

			// <script> ist ebenso grundsätzlich verboten – Ausnahme: exakt die
			// beiden offiziellen Widget-Loader von Instagram/X (siehe
			// isAllowedWidgetScriptSrc()), die deren jeweiliges
			// <blockquote data-instgrm-permalink="…">/<blockquote class="twitter-tweet">
			// erst zu einem Player/Post rendern. Anders als beim iframe-Sandbox
			// läuft dieses Skript MIT vollem DOM-Zugriff auf der Reader-Seite,
			// daher zusätzlich zum exakten Src-Match: keine Kindknoten erlaubt
			// (kein Einschleusen von Inline-JS über denselben Tag).
			if ($tag === 'script') {
				if ($this->isAllowedWidgetScriptSrc($el->getAttribute('src')) && !$el->hasChildNodes()) {
					$this->sanitizeAttributes($el, $tag, $allowedAttrs);
				} else {
					$el->parentNode->removeChild($el);
				}
				continue;
			}

			// Nicht erlaubtes Tag: <style>/<object>/<form>/… komplett
			// samt Inhalt entfernen; bei rein strukturellen Unknowns bliebe zwar Text
			// erhalten – da wir aber fail-closed sein wollen und die Allowlist den
			// gesamten Reader-Content abdeckt, entfernen wir das ganze Element.
			if (!isset($allowedTagSet[$tag])) {
				$el->parentNode->removeChild($el);
				continue;
			}

			$this->sanitizeAttributes($el, $tag, $allowedAttrs);
		}

		// Nur die Kinder des Wrapper-Containers zurückgeben.
		$out = '';
		foreach ($root->childNodes as $child) {
			$serialized = $dom->saveHTML($child);
			if ($serialized !== false) {
				$out .= $serialized;
			}
		}

		return trim($out);
	}

	/**
	 * Macht aus jeder Bildunterschrift eine einzige Zeile: Umbrüche werden durch
	 * self::CAPTION_SEPARATOR ("•") ersetzt.
	 *
	 * Sichtbare Umbrüche entstehen in einer <figcaption> auf genau zwei Wegen –
	 * beide werden hier abgebaut:
	 *   1. <br>-Elemente         → Trenner-Textknoten
	 *   2. Block-Elemente        → aufgelöst (Kinder bleiben), Trenner davor/danach
	 *
	 * Reine Whitespace-Umbrüche im Quell-HTML (Einrückung) sind KEINE sichtbaren
	 * Umbrüche und werden deshalb zu einem Leerzeichen zusammengefasst statt zu
	 * einem Trenner – sonst bekäme jede eingerückte Caption Bullets, die im
	 * Browser nie zu sehen waren.
	 *
	 * Inline-Auszeichnung (<a href="…">, <em>, <span>) bleibt erhalten, deshalb
	 * arbeitet die Bereinigung auf den Textknoten statt auf textContent.
	 */
	private function flattenCaptions(\DOMDocument $dom): void {
		$captions = iterator_to_array($dom->getElementsByTagName('figcaption'));
		if ($captions === []) {
			return;
		}

		$xpath = new \DOMXPath($dom);

		foreach ($captions as $caption) {
			// Kann beim Auflösen einer umschliessenden Caption aus dem Baum
			// gefallen sein (verschachtelte <figcaption> im Quell-HTML).
			if (!$caption instanceof \DOMElement || $caption->parentNode === null) {
				continue;
			}

			// 1. <br> durch den Trenner ersetzen.
			foreach (iterator_to_array($caption->getElementsByTagName('br')) as $br) {
				$br->parentNode?->replaceChild($dom->createTextNode(self::CAPTION_SEPARATOR), $br);
			}

			// 2. Block-Elemente auflösen. Rückwärts (= von innen nach aussen),
			//    damit verschachtelte Blöcke nicht ins Leere greifen.
			$blocks = [];
			foreach ($caption->getElementsByTagName('*') as $el) {
				if (in_array(strtolower($el->nodeName), self::CAPTION_BLOCK_TAGS, true)) {
					$blocks[] = $el;
				}
			}
			foreach (array_reverse($blocks) as $block) {
				$parent = $block->parentNode;
				if ($parent === null) {
					continue;
				}
				// Trenner VOR und NACH dem Block: ein Block beendet auch die Zeile,
				// der nachfolgende Inhalt begönne sonst ohne Trennung
				// (<div><p>A</p><span>B</span></div> rendert A und B untereinander).
				// Überzählige Trenner fallen in Schritt 3 wieder weg.
				$parent->insertBefore($dom->createTextNode(self::CAPTION_SEPARATOR), $block);
				while ($block->firstChild !== null) {
					$parent->insertBefore($block->firstChild, $block);
				}
				$parent->insertBefore($dom->createTextNode(self::CAPTION_SEPARATOR), $block);
				$parent->removeChild($block);
			}

			// 3. Textknoten glätten: Whitespace vereinheitlichen, Trenner-Ketten
			//    zusammenfassen, führende Trenner entfernen.
			$lastVisible    = null;
			$afterSeparator = true;   // Caption-Anfang zählt wie "gerade getrennt"
			foreach ($xpath->query('.//text()', $caption) as $text) {
				$value = preg_replace('/\s+/u', ' ', (string) $text->nodeValue) ?? '';
				$value = preg_replace(
					'/(?:\s*' . self::CAPTION_BULLET . '\s*)+/u',
					self::CAPTION_SEPARATOR,
					$value
				) ?? $value;
				if ($afterSeparator) {
					$value = preg_replace('/^\s*(?:' . self::CAPTION_BULLET . '\s*)?/u', '', $value) ?? $value;
				}
				$text->nodeValue = $value;

				if (trim($value) !== '') {
					$lastVisible    = $text;
					$afterSeparator = (bool) preg_match('/' . self::CAPTION_BULLET . '\s*$/u', $value);
				}
			}

			// 4. Trenner am Ende abschneiden (entsteht, wenn die Caption mit einem
			//    Block oder einem <br> aufhört).
			if ($lastVisible !== null) {
				$lastVisible->nodeValue = preg_replace(
					'/\s*(?:' . self::CAPTION_BULLET . '\s*)?$/u',
					'',
					(string) $lastVisible->nodeValue
				) ?? '';
			}
		}
	}

	/**
	 * Löst jedes <picture> in seinen besten Bildkandidaten auf und ersetzt es
	 * durch ein einzelnes <img src="…">, BEVOR der generische Allowlist-Filter
	 * (sanitizeAttributes) `srcset` von den <source>-Kindern entfernt.
	 *
	 * Grund: <source> trägt die eigentlichen Bild-URLs ausschließlich über
	 * `srcset` (nie `src`) – ohne diese Auflösung bleibt nach dem Sanitizing nur
	 * das <img>-Fallback-Element von <picture> übrig. Manche Seiten (z. B.
	 * taz.de) legen dort absichtlich einen winzigen Low-Quality-Platzhalter ab,
	 * den echte Browser dank <picture>-Auswahlalgorithmus nie rendern – unser
	 * DOM-Parser kennt diesen Algorithmus aber nicht und würde ihn 1:1 in den
	 * Reader übernehmen (sichtbar als winzig dargestelltes "Hero image").
	 *
	 * Wählt pro <picture> den <source> mit der größten per "Nw"-Deskriptor
	 * bekannten Breite; ist keine Breite bekannt, gewinnt der erste <source>
	 * ohne `media`-Attribut (die geräteunabhängige Standardvariante) vor
	 * mobil-spezifischen Breakpoints. Liefert kein <source> einen auswertbaren
	 * Kandidaten, bleibt das <picture> unverändert (heutiges Verhalten).
	 */
	private function resolvePictureElements(\DOMDocument $dom): void {
		foreach (iterator_to_array($dom->getElementsByTagName('picture')) as $picture) {
			if (!$picture instanceof \DOMElement || $picture->parentNode === null) {
				continue;
			}

			$best         = null;
			$bestHasMedia = true;

			foreach (iterator_to_array($picture->getElementsByTagName('source')) as $source) {
				if (!$source instanceof \DOMElement) {
					continue;
				}
				$candidate = $this->bestSrcsetCandidate($source->getAttribute('srcset'));
				if ($candidate === null) {
					continue;
				}
				$hasMedia = $source->getAttribute('media') !== '';

				$better =
					$best === null
					|| ($candidate['width'] !== null && ($best['width'] === null || $candidate['width'] > $best['width']))
					|| ($candidate['width'] === null && $best['width'] === null && $bestHasMedia && !$hasMedia);

				if ($better) {
					$best         = $candidate;
					$bestHasMedia = $hasMedia;
				}
			}

			if ($best === null) {
				continue;
			}

			// Bestehendes <img>-Fallback wiederverwenden (behält alt/title),
			// sonst eines anlegen — <picture> ohne <img>-Kind ist ungültiges
			// HTML, kommt in der Praxis aber vereinzelt vor.
			$img = null;
			foreach ($picture->childNodes as $child) {
				if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'img') {
					$img = $child;
				}
			}
			if ($img === null) {
				$img = $dom->createElement('img');
				$picture->appendChild($img);
			}
			$img->setAttribute('src', $best['url']);

			$picture->removeChild($img);
			$picture->parentNode->replaceChild($img, $picture);
		}
	}

	/**
	 * Parst einen `srcset`-Wert ("url1 800w, url2 1200w" oder auch nur "url")
	 * und liefert den Kandidaten mit der größten bekannten Breite. Kandidaten
	 * ohne "Nw"-Breitendeskriptor (z. B. taz.de: nackte URL ohne Deskriptor,
	 * oder "x"-Pixeldichte-Deskriptoren) gelten als Breite `null` und werden
	 * nur als Fallback verwendet, wenn kein Kandidat mit bekannter Breite
	 * vorliegt — der jeweils erste gewinnt dann.
	 *
	 * @return array{url: string, width: int|null}|null
	 */
	private function bestSrcsetCandidate(string $srcset): ?array {
		$srcset = trim($srcset);
		if ($srcset === '') {
			return null;
		}

		$best = null;
		foreach (explode(',', $srcset) as $entry) {
			$parts = preg_split('/\s+/', trim($entry), -1, PREG_SPLIT_NO_EMPTY);
			if ($parts === [] || $parts[0] === '') {
				continue;
			}
			$url   = $parts[0];
			$width = null;
			if (isset($parts[1]) && preg_match('/^(\d+)w$/', $parts[1], $m)) {
				$width = (int) $m[1];
			}
			if ($best === null || ($width !== null && ($best['width'] === null || $width > $best['width']))) {
				$best = ['url' => $url, 'width' => $width];
			}
		}
		return $best;
	}

	/**
	 * Entfernt an einem Element alle nicht erlaubten Attribute und neutralisiert
	 * gefährliche URL-Schemata in href/src.
	 *
	 * @param array<string, list<string>> $allowedAttrs
	 */
	private function sanitizeAttributes(\DOMElement $el, string $tag, array $allowedAttrs): void {
		$globalAllowed = $allowedAttrs['*'] ?? [];
		$tagAllowed    = $allowedAttrs[$tag] ?? [];
		$allowed       = array_flip(array_merge($globalAllowed, $tagAllowed));

		// Attribute-Liste vorher kopieren – das Entfernen mutiert die Live-NamedNodeMap.
		$attrNames = [];
		foreach ($el->attributes as $attr) {
			$attrNames[] = $attr->nodeName;
		}

		foreach ($attrNames as $name) {
			$lname = strtolower($name);

			// Nicht erlaubt (fängt insbesondere ALLE on*-Handler und style ab).
			if (!isset($allowed[$lname])) {
				$el->removeAttribute($name);
				continue;
			}

			// URL-Attribute gegen gefährliche Schemata absichern.
			if ($lname === 'href' || $lname === 'src') {
				$value = trim($el->getAttribute($name));
				if ($this->isDangerousUrl($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// Instagrams Embed-Markup trägt die Post-URL in einem data-Attribut statt
			// href/src – muss trotzdem auf instagram.com zeigen, sonst könnte das
			// Widget-Skript (siehe isAllowedWidgetScriptSrc()) beliebige fremde Inhalte
			// nachladen/darstellen.
			if ($tag === 'blockquote' && $lname === 'data-instgrm-permalink') {
				$value = trim($el->getAttribute($name));
				if (!$this->isAllowedInstagramPermalink($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// Blueskys Embed-Markup trägt die Post-Identität als at://-URI in
			// einem data-Attribut statt href/src – muss syntaktisch eine
			// gültige app.bsky.feed.post-URI sein, siehe isAllowedBlueskyUri().
			if ($tag === 'blockquote' && $lname === 'data-bluesky-uri') {
				$value = trim($el->getAttribute($name));
				if (!$this->isAllowedBlueskyUri($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}
		}

		// Bei Links, die in einem neuen Tab geöffnet werden, rel härten
		// (Schutz gegen window.opener-Tabnabbing).
		if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
			$el->setAttribute('rel', 'noopener noreferrer');
		}
	}

	/**
	 * true, wenn $src ein Video-Embed eines der fest hinterlegten, vertrauens-
	 * würdigen Hosts ist (https, exakter Host-Match, erforderliches Pfad-
	 * Präfix). Das ist die einzige Ausnahme von der iframe-Denylist in
	 * sanitizeHtml() – bewusst eng gefasst (kein Wildcard-Host, kein Schema-
	 * Downgrade), weil ein erlaubtes iframe sonst zum offenen SSRF-/
	 * Clickjacking-Vektor würde. Passend dazu muss der CSP-Header (siehe
	 * public/index.php bzw. die aufrufenden Controller) frame-src auf
	 * dieselben Hosts begrenzen, sonst rendert der Browser das Embed trotz
	 * durchgelassenem Markup nicht.
	 *
	 * ARD Mediathek/ZDF sind bewusst NICHT gelistet: ARD bietet keinen
	 * dokumentierten Embed-Mechanismus, ZDFs tatsächlicher iframe-Host ist
	 * unverifiziert – siehe Plan/Commit-Historie. Erst nach manueller
	 * Verifikation eines echten Embed-Codes hier ergänzen, nicht raten.
	 */
	/**
	 * CSP `frame-src`-Direktive für Antworten, die Artikel-HTML rendern
	 * (siehe PageController::articleReader(), PublicShareController::show()).
	 * Muss mit den Hosts in isAllowedVideoEmbedSrc() synchron bleiben, sonst
	 * lässt der Sanitizer ein iframe durch, das der Browser trotzdem
	 * verweigert (oder umgekehrt: ein zu weiter Header würde ein iframe
	 * rendern, das der Sanitizer eigentlich nie hätte durchlassen dürfen –
	 * die Sanitizer-Allowlist bleibt also so oder so die primäre Verteidigung).
	 *
	 * Bewusst OHNE script-src: anders als bei merlin-nextcloud gibt es hier
	 * (noch) keine Nonce-Infrastruktur, und einige Templates (u. a.
	 * article_reader.php) enthalten ein serverseitig befülltes Inline-<script>
	 * für I18N-Daten. Ein restriktiver script-src ohne 'unsafe-inline'/Nonce
	 * würde dieses Inline-Script blockieren und die Seite kaputt machen – die
	 * Durchsetzung der isAllowedWidgetScriptSrc()-Allowlist bleibt hier also
	 * allein Aufgabe des Sanitizers, nicht der CSP.
	 */
	public static function videoEmbedFrameSrcHeader(): string {
		return "frame-src 'self' "
			. 'https://www.youtube.com https://www.youtube-nocookie.com '
			. 'https://player.vimeo.com https://player.twitch.tv '
			. 'https://www.tiktok.com https://www.facebook.com https://www.arte.tv '
			. 'https://www.instagram.com https://platform.twitter.com https://embed.bsky.app';
	}

	private function isAllowedVideoEmbedSrc(string $src): bool {
		$src = trim($src);
		if ($src === '') {
			return false;
		}

		$parts = parse_url($src);
		// 'path' bewusst NICHT in isset() – parse_url() liefert keinen path-Key,
		// wenn die URL keinen (z. B. "https://player.twitch.tv?channel=…"), das
		// ist trotzdem eine gültige, im Zweifel erlaubte URL (siehe Twitch unten).
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		$host = strtolower($parts['host']);
		$path = $parts['path'] ?? '';

		// Host => erforderliches Pfad-Präfix, null = kein Präfix nötig (Twitch
		// unterscheidet Kanal/VOD/Clip rein über Query-Parameter).
		static $allowedHostPrefixes = [
			'www.youtube.com'          => '/embed/',
			'youtube.com'              => '/embed/',
			'www.youtube-nocookie.com' => '/embed/',
			'youtube-nocookie.com'     => '/embed/',
			'player.vimeo.com'         => '/video/',
			'player.twitch.tv'         => null,
			'www.tiktok.com'           => '/player/v1/',
			'www.facebook.com'         => '/plugins/video.php',
			'www.arte.tv'              => '/player/v5/index.php',
		];

		if (!array_key_exists($host, $allowedHostPrefixes)) {
			return false;
		}

		$requiredPrefix = $allowedHostPrefixes[$host];
		if ($requiredPrefix !== null && !str_starts_with($path, $requiredPrefix)) {
			return false;
		}

		// Facebooks und Artes Player nehmen ihrerseits eine fremde URL als
		// Query-Parameter entgegen (href/json_url) und laden von dort nach –
		// ohne diese Prüfung wäre der jeweilige Player ein offenes
		// Redirect-/SSRF-artiges Gadget auf beliebige Ziel-URLs.
		parse_str($parts['query'] ?? '', $query);
		if ($host === 'www.facebook.com'
			&& !$this->hasAllowedQueryUrlHost((string) ($query['href'] ?? ''), ['facebook.com', 'www.facebook.com'])) {
			return false;
		}
		if ($host === 'www.arte.tv'
			&& !$this->hasAllowedQueryUrlHost((string) ($query['json_url'] ?? ''), ['api.arte.tv'])) {
			return false;
		}

		return true;
	}

	/**
	 * true, wenn $url eine https-URL ist, deren Host in $allowedHosts steht.
	 * Absichert Player, die selbst eine fremde URL als Query-Parameter
	 * entgegennehmen (siehe isAllowedVideoEmbedSrc() für Facebook/Arte).
	 */
	private function hasAllowedQueryUrlHost(string $url, array $allowedHosts): bool {
		if ($url === '') {
			return false;
		}

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
	 * true, wenn $src exakt einer der offiziellen Widget-Loader von
	 * Instagram/X/Bluesky ist. Bewusst als exakter String-Match (nicht nur Host/Pfad-
	 * Präfix wie bei isAllowedVideoEmbedSrc()): anders als ein sandboxed
	 * iframe läuft dieses Skript MIT vollem DOM-Zugriff auf der Reader-Seite,
	 * daher hier die engstmögliche Fassung.
	 */
	private function isAllowedWidgetScriptSrc(string $src): bool {
		$src = trim($src);
		if ($src === '') {
			return false;
		}

		// Instagram/X/Bluesky liefern ihren offiziellen Embed-Code oft
		// protokollrelativ ("//www.instagram.com/embed.js") aus – vor dem
		// exakten Match auf https normalisieren.
		if (str_starts_with($src, '//')) {
			$src = 'https:' . $src;
		}

		static $allowedScriptSrcs = [
			'https://www.instagram.com/embed.js',
			'https://platform.twitter.com/widgets.js',
			'https://embed.bsky.app/static/embed.js',
		];

		return in_array($src, $allowedScriptSrcs, true);
	}

	/**
	 * true, wenn $url eine https-URL auf (www.)instagram.com ist. Für das
	 * data-instgrm-permalink-Attribut von Instagrams Embed-<blockquote>, siehe
	 * sanitizeAttributes().
	 */
	private function isAllowedInstagramPermalink(string $url): bool {
		if ($url === '') {
			return false;
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		return in_array(strtolower($parts['host']), ['www.instagram.com', 'instagram.com'], true);
	}

	/**
	 * true, wenn $uri eine syntaktisch gültige at://-URI eines
	 * app.bsky.feed.post-Records ist. Für das data-bluesky-uri-Attribut von
	 * Blueskys Embed-<blockquote>, siehe sanitizeAttributes() - dort ist der
	 * eigentliche Vertrauensanker aber ohnehin, dass diese URIs ausschließlich
	 * von uns selbst erzeugt werden (BlueskyThreadResolverService, aus einer
	 * API-Antwort desselben public.api.bsky.app-Hosts), nicht aus Fremd-HTML.
	 * Diese Prüfung ist Defense-in-Depth gegen ein verändertes/fehlerhaftes
	 * Content-Filter-Custom (Admin-/User-Ebene), keine Vertrauensentscheidung.
	 */
	private function isAllowedBlueskyUri(string $uri): bool {
		return preg_match('#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.feed\.post/[A-Za-z0-9._~-]+$#', $uri) === 1;
	}

	/**
	 * true, wenn die URL ein gefährliches Schema trägt (javascript:, data:, vbscript:).
	 * Relative URLs, Anchor-Links (#…), sowie http/https/mailto sind erlaubt.
	 */
	private function isDangerousUrl(string $url): bool {
		// Führende Steuerzeichen/Whitespace entfernen – Browser ignorieren sie beim
		// Scheme-Parsing (z. B. "java\tscript:…").
		$normalized = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? $url);

		return str_starts_with($normalized, 'javascript:')
			|| str_starts_with($normalized, 'vbscript:')
			|| str_starts_with($normalized, 'data:');
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Published Date Extraction
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract article publication date from meta tags and structured data.
	 * Always returns UTC so that timezone offsets in the source string are
	 * preserved semantically even after MySQL strips the offset on storage.
	 */
	private function extractPublishedDate(string $html, string $content = ''): ?\DateTime {
		$dateString = null;

		// 1. og:article:published_time — most reliable, always prefer
		if (preg_match('/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		} elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		// 2. JSON-LD datePublished
		} elseif (preg_match('/"datePublished"\s*:\s*"([^"]+)"/i', $html, $m)) {
			$dateString = $m[1];
		// 3. meta name="pubdate"
		} elseif (preg_match('/<meta[^>]+name=["\']pubdate["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		// 4. meta name="date" — used by e.g. rbb24 (may carry a non-standard "-T"
		//    typo instead of "T" as the date/time separator; normalise before use)
		} elseif (preg_match('/<meta[^>]+name=["\']date["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			// Fix common CMS typo: "2026-03-22-T17:26:21" → "2026-03-22T17:26:21"
			$dateString = preg_replace('/(\d{4}-\d{2}-\d{2})-T/', '$1T', $m[1]);
		}

		// 5. <time datetime="..."> in extracted content (DOMXPath — much more
		//    reliable than searching raw HTML, which may match nav/footer timestamps)
		if ($dateString === null && $content !== '') {
			$dateString = $this->extractTimeTagFromContent($content);
		}

		// 6. Last resort: <time datetime="..."> anywhere in the raw HTML
		if ($dateString === null) {
			if (preg_match('/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
				$candidate = $m[1];
				if ($this->looksLikeDate($candidate) || $this->looksLikeGermanDate($candidate)) {
					$dateString = $candidate;
				}
			}
		}

		if ($dateString === null) {
			return null;
		}

		return $this->parseDateString($dateString);
	}

	/**
	 * Search for the first <time datetime="..."> element in Readability-extracted
	 * content using DOMXPath. Returns the datetime string if it looks like a date,
	 * null otherwise.
	 *
	 * Handles both ISO 8601 values (2026-03-22T…) and locale-formatted values
	 * such as the German "DD.MM.YYYY HH:MM:SS" used by rbb24.
	 */
	private function extractTimeTagFromContent(string $content): ?string {
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);
		foreach ($xpath->query('//time[@datetime]') as $node) {
			/** @var \DOMElement $node */
			$datetime = trim($node->getAttribute('datetime'));
			if ($datetime === '') {
				continue;
			}
			if ($this->looksLikeDate($datetime) || $this->looksLikeGermanDate($datetime)) {
				return $datetime;
			}
		}
		return null;
	}

	/**
	 * Parse a date string to a UTC DateTime, handling:
	 *   - ISO 8601 with optional fractional seconds and timezone offset
	 *   - German locale format: "DD.MM.YYYY [HH:MM:SS]"
	 *
	 * Using createFromFormat() for known patterns avoids the ambiguity of
	 * PHP's DateTimeImmutable constructor, which cannot parse German dates
	 * and behaves inconsistently with fractional-second ISO strings in PHP < 8.
	 */
	private function parseDateString(string $dateString): ?\DateTime {
		$utc = new \DateTimeZone('UTC');

		// German date: DD.MM.YYYY HH:MM:SS  or  DD.MM.YYYY
		if ($this->looksLikeGermanDate($dateString)) {
			$fmt = str_contains($dateString, ' ') ? 'd.m.Y H:i:s' : 'd.m.Y';
			$dt  = \DateTimeImmutable::createFromFormat($fmt, $dateString);
			if ($dt !== false) {
				return \DateTime::createFromImmutable($dt->setTimezone($utc));
			}
		}

		// ISO 8601 — strip fractional seconds before handing to the constructor
		// so that PHP 7.x (which mishandles .NNN milliseconds in DateTimeImmutable)
		// also works correctly.
		$normalised = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $dateString) ?? $dateString;

		try {
			$immutable = new \DateTimeImmutable($normalised);
			return \DateTime::createFromImmutable($immutable->setTimezone($utc));
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Returns true when $value starts with a four-digit year and ISO dash separator
	 * (i.e. looks like an ISO 8601 date: YYYY-MM-…).
	 */
	private function looksLikeDate(string $value): bool {
		return (bool) preg_match('/^\d{4}-\d{2}/', $value);
	}

	/**
	 * Returns true when $value matches the German locale date format DD.MM.YYYY.
	 */
	private function looksLikeGermanDate(string $value): bool {
		return (bool) preg_match('/^\d{2}\.\d{2}\.\d{4}/', $value);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Metadata deduplication
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Remove headline and teaser paragraph from article content when they
	 * duplicate the title / excerpt fields that both clients render separately
	 * above the hero image.
	 *
	 * Sites like taz.de use CSS `order` to visually place the article title and
	 * intro paragraph before the body text, but in DOM order those elements live
	 * inside the <article> container and are therefore picked up by Readability.
	 * Without this pass they would appear twice in the reader view.
	 *
	 * Matching is intentionally fuzzy:
	 *   - Heading: ≥ 70 % of its normalised words appear in the normalised title.
	 *     Handles shortened headings (e.g. taz.de omits the kicker prefix).
	 *   - Teaser paragraph: ≥ 75 % word-overlap in either direction with the
	 *     excerpt (og:description / meta description).
	 *
	 * Both checks are limited to the first few occurrences so that legitimate
	 * sub-headings and body paragraphs deep in the article are never touched.
	 */
	private function stripDuplicateMetadata(string $content, string $title, ?string $excerpt): string {
		if (empty($content) || empty($title)) {
			return $content;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);
		$body  = $dom->getElementsByTagName('body')->item(0);
		if (!$body) {
			return $content;
		}

		$changed     = false;
		$normalTitle = $this->normalizeForComparison($title);
		$titleWords  = array_filter(explode(' ', $normalTitle));

		// --- Pass 1: remove first heading (h1–h4) that duplicates the title ----
		$checked = 0;
		foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4]', $body) as $heading) {
			if (++$checked > 5) {
				break;
			}
			$normalHead = $this->normalizeForComparison($heading->textContent);
			$headWords  = array_filter(explode(' ', $normalHead));

			if (count($titleWords) === 0 || count($headWords) === 0) {
				continue;
			}

			if (count($titleWords) < 3 || count($headWords) < 3) {
				// Short title: require exact normalised match to avoid false positives
				$match = ($normalTitle === $normalHead);
			} else {
				// Longer title: 70 % word overlap is sufficient
				$shared = count(array_intersect($headWords, $titleWords));
				$match  = ($shared / count($headWords)) >= 0.70;
			}

			if ($match) {
				$heading->parentNode?->removeChild($heading);
				$changed = true;
				break; // Remove at most one heading
			}
		}

		// --- Pass 1b: title as a leading <p> (some CMSes skip heading tags) ----
		// Only run when no heading was removed and the title has at least 2 words.
		if (!$changed && count($titleWords) >= 2) {
			$checked = 0;
			foreach ($xpath->query('//p', $body) as $para) {
				if (++$checked > 3) {
					break;
				}
				$normalPara = $this->normalizeForComparison($para->textContent);
				if ($normalPara === $normalTitle) {
					$para->parentNode?->removeChild($para);
					$changed = true;
					break;
				}
			}
		}

		if (!$changed) {
			return $content;
		}

		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $dom->saveHTML($child);
		}
		return trim($out) ?: $content;
	}

	/**
	 * Normalise a string for text-similarity comparison:
	 * strip HTML tags, decode entities, lowercase, remove punctuation,
	 * collapse whitespace.
	 */
	private function normalizeForComparison(string $text): string {
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = mb_strtolower($text, 'UTF-8');
		// Keep only Unicode letters, digits and spaces
		$text = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
		$text = (string) preg_replace('/\s+/', ' ', $text);
		return trim($text);
	}
}