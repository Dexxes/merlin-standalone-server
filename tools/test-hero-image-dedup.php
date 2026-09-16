<?php

declare(strict_types=1);

/**
 * Testharness für die Hero-Bild-Behandlung in Step 12 von
 * ContentExtractorService::processHtml(): stripLeadingImages(),
 * scanForLeadingImages(), resolveLeadingImage(), isSkippableLeadIn(),
 * firstNonWhitespaceElementChild(), firstImageInPicture(), imagesMatchForDedup().
 *
 * Aufruf (im Repo-Root):
 *   php tools/test-hero-image-dedup.php
 *
 * Portiert aus merlin-nextclouds gleichnamigem Testwerkzeug. Hintergrund:
 * Step 12 prüfte hier bisher nur grob, ob in den ersten 2000 Zeichen des
 * Contents überhaupt ein <img> vorkam - unabhängig davon, ob es sich dabei
 * um dasselbe Bild wie die ermittelte imageUrl handelte. Das konnte sowohl
 * zu doppelt angezeigten Hero-Bildern (Bild sitzt weiter hinten im Content)
 * als auch zu komplett fehlenden Hero-Bildern führen (irgendein früheres,
 * andersartiges Bild unterdrückte das Voranstellen).
 *
 * stripLeadingImages() ersetzt diese Heuristik durch bedingungsloses
 * Entfernen aller Leitbilder bis zum ersten substantiellen Absatz - das
 * Hero-Bild wird in Step 12 danach IMMER separat eingefügt.
 * imagesMatchForDedup() entscheidet nur noch, welche der dabei gesammelten
 * Captions zum Hero-Bild passt; ein Fehltreffer kostet dadurch höchstens
 * eine fehlende statt einer falschen oder sichtbar doppelten Bild-Anzeige.
 *
 * Der Service wird ohne Konstruktor instanziiert (newInstanceWithoutConstructor):
 * die geprüften Methoden nutzen weder Logger noch Repository. Composer-
 * Autoload wird von ContentExtractorService.php selbst geladen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../src/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();

$stripLeadingImages = new ReflectionMethod(ContentExtractorService::class, 'stripLeadingImages');
$stripLeadingImages->setAccessible(true);

$imagesMatchForDedup = new ReflectionMethod(ContentExtractorService::class, 'imagesMatchForDedup');
$imagesMatchForDedup->setAccessible(true);

$passed   = 0;
$failures = [];

$baseUrl = 'https://example.com/blog/some-article/';

/**
 * Prüft stripLeadingImages(): $expectedRemovedCount Bilder sollen aus $html
 * entfernt werden. Optional: Teilstring, der im verbleibenden Content stehen
 * bleiben bzw. fehlen muss, sowie src/Caption des ERSTEN entfernten Bildes.
 * $expectedFirstCaption=false bedeutet "nicht geprüft" (Caption selbst ist
 * ?string, daher kein string/null-Wert als Sentinel verwendbar).
 */
$checkStrip = function (
	string $label,
	string $html,
	int $expectedRemovedCount,
	?string $expectedRestContains = null,
	?string $expectedRestNotContains = null,
	?string $expectedFirstSrc = null,
	string|false|null $expectedFirstCaption = false
) use ($service, $stripLeadingImages, $baseUrl, &$passed, &$failures): void {
	$result      = $stripLeadingImages->invoke($service, $html, $baseUrl);
	$actualCount = count($result['images']);

	$ok = $actualCount === $expectedRemovedCount;
	if ($ok && $expectedRestContains !== null) {
		$ok = str_contains($result['content'], $expectedRestContains);
	}
	if ($ok && $expectedRestNotContains !== null) {
		$ok = !str_contains($result['content'], $expectedRestNotContains);
	}
	if ($ok && $expectedFirstSrc !== null) {
		$ok = ($result['images'][0]['src'] ?? null) === $expectedFirstSrc;
	}
	if ($ok && $expectedFirstCaption !== false) {
		$ok = ($result['images'][0]['caption'] ?? null) === $expectedFirstCaption;
	}

	if ($ok) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: removedCount=' . $expectedRemovedCount
		. ($expectedRestContains !== null ? ', restContains=' . var_export($expectedRestContains, true) : '')
		. ($expectedRestNotContains !== null ? ', restNotContains=' . var_export($expectedRestNotContains, true) : '')
		. ($expectedFirstSrc !== null ? ', firstSrc=' . var_export($expectedFirstSrc, true) : '')
		. ($expectedFirstCaption !== false ? ', firstCaption=' . var_export($expectedFirstCaption, true) : '')
		. "\n";
	echo '      erhalten: ' . json_encode($result['images'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	echo '      verbleibender Content: ' . $result['content'] . "\n";
};

/**
 * Prüft imagesMatchForDedup() direkt mit zwei bereits normalisierten URLs -
 * genau die Fälle, die jetzt über die Caption-Zuordnung in Step 12 statt über
 * die (frühere) Entfernungs-Entscheidung laufen.
 */
$checkMatch = function (string $label, string $urlA, string $urlB, bool $expected) use (
	$service, $imagesMatchForDedup, &$passed, &$failures
): void {
	$actual = $imagesMatchForDedup->invoke($service, $urlA, $urlB);

	if ($actual === $expected) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: ' . var_export($expected, true) . "\n";
	echo '      erhalten: ' . var_export($actual, true) . "\n";
};

echo "\n\033[1mstripLeadingImages(): erkannte Wrapper-Formen (Bild wird entfernt)\033[0m\n";

$checkStrip(
	'Einfacher WordPress-Wrapper <p><a><img>',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	1,
	'<p>Text.</p>',
	'<img'
);

$checkStrip(
	'Readability-Wrapper-Div mit mehreren Geschwister-Absätzen bleibt bis auf das Bild erhalten',
	'<div><p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Absatz 2</p><p>Absatz 3</p></div>',
	1,
	'<p>Absatz 2</p><p>Absatz 3</p>',
	'<img'
);

$checkStrip(
	'Gutenberg-Bildblock <figure> verschachtelt in Readability-Wrapper-Div',
	'<div><figure><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></figure><p>Text.</p></div>',
	1,
	'<p>Text.</p>',
	'<img'
);

$checkStrip(
	'Altes WP-[caption]-Shortcode-Div: <a><img> plus Geschwister-<p>',
	'<div><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a><p>Bildunterschrift</p></div><p>Text.</p>',
	1
);

$checkStrip(
	'Geschütztes Leerzeichen (&nbsp;) vor dem verpackten Bild',
	"<div>\u{00A0}<p><a href=\"x\"><img src=\"https://example.com/wp-content/uploads/2024/foto.jpg\"></a></p></div>",
	1
);

$checkStrip(
	'<picture>-Wrapper mit mehreren <source>-Geschwistern vor <img> (responsive Bilder)',
	'<div><picture><source srcset="foto.webp" type="image/webp"><source srcset="foto.avif" type="image/avif"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></picture></div>',
	1
);

$checkStrip(
	'<picture> mit NICHT selbst-schließenden <source>-Tags (libxml2-Verschachtelung, siehe firstImageInPicture())',
	'<figure><picture>
 <source srcset="a.webp" type="image/webp" width="960" height="540" media="(max-width:768px)">
 <source srcset="b.jpg" type="image/jpeg" width="960" height="540" media="(max-width:768px)">
 <img src="https://example.com/wp-content/uploads/2024/foto.jpg" width="1376" height="774">
</picture></figure>',
	1
);

$checkStrip(
	'Protokoll-relative src wird beim Einsammeln absolut gemacht',
	'<p><a href="x"><img src="//example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	1,
	null,
	null,
	'https://example.com/wp-content/uploads/2024/foto.jpg'
);

$checkStrip(
	'Figcaption wird zusammen mit dem Bild eingesammelt',
	'<figure><img src="https://example.com/foto.jpg"><figcaption>Ein schönes Foto</figcaption></figure><p>Text.</p>',
	1,
	null,
	null,
	null,
	'Ein schönes Foto'
);

echo "\n\033[1mByline/Überschrift vor dem Bild wird übersprungen, nicht entfernt\033[0m\n";

$checkStrip(
	'Kurze Autorenzeile vor dem Bild blockiert die Suche nicht',
	'<p>Von Max Mustermann</p><figure><img src="https://example.com/foto.jpg"></figure><p>' . str_repeat('Echter Fließtext. ', 6) . '</p>',
	1,
	'Von Max Mustermann',
	'<img'
);

$checkStrip(
	'Zwischenüberschrift vor dem Bild blockiert die Suche nicht, unabhängig von der Länge',
	'<h2>Eine ziemlich lange Zwischenüberschrift, länger als achtzig Zeichen sein könnte</h2><figure><img src="https://example.com/foto.jpg"></figure><p>' . str_repeat('Echter Fließtext. ', 6) . '</p>',
	1,
	'<h2>Eine ziemlich lange Zwischenüberschrift'
);

$checkStrip(
	'Mehrere Leitbilder vor dem ersten Absatz werden alle entfernt',
	'<figure><img src="https://example.com/a.jpg"></figure><figure><img src="https://example.com/b.jpg"></figure><p>' . str_repeat('Echter Fließtext. ', 6) . '</p>',
	2
);

echo "\n\033[1mSubstanzieller Absatz vor dem Bild beendet die Suche (Bild bleibt im Content)\033[0m\n";

$checkStrip(
	'Ausreichend langer erster Absatz verhindert das Entfernen eines späteren Bildes',
	'<p>' . str_repeat('Echter Fließtext. ', 6) . '</p><figure><img src="https://example.com/foto.jpg"></figure>',
	0,
	'<img'
);

$checkStrip(
	'Text vor dem Bild innerhalb desselben Wrapper-Elements wird nicht entfernt',
	'<p>Ein Foto: <img src="https://example.com/wp-content/uploads/2024/foto.jpg"></p>',
	0
);

$checkStrip(
	'Nicht unterstützter Wrapper-Tag (z. B. <aside>) wird nicht entpackt',
	'<aside><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></aside><p>Text.</p>',
	0
);

$checkStrip(
	'<section> mit einem <img> als einzigem Kind wird transparent durchstiegen',
	'<section><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></section><p>Text.</p>',
	1,
	'<p>Text.</p>',
	'<img'
);

$checkStrip(
	'<article> mit langer Bildunterschrift blockiert die Suche nicht (rbb24.de-Regression)',
	'<div><article><figure><img src="https://example.com/foto.jpg"><figcaption>' . str_repeat('Lange Bildunterschrift. ', 5) . '</figcaption></figure></article></div>'
		. '<div><p>' . str_repeat('Echter Fließtext. ', 6) . '</p></div>',
	1,
	null,
	null,
	null,
	trim(str_repeat('Lange Bildunterschrift. ', 5))
);

$checkStrip(
	'Leerer src wird nicht als Bild gewertet',
	'<p><a href="x"><img src=""></a></p><p>Text.</p>',
	0
);

$checkStrip(
	'Kein Bild vorhanden - nichts zu entfernen',
	'<p>' . str_repeat('Echter Fließtext. ', 6) . '</p>',
	0
);

$checkStrip(
	'Mehr als MAX_LEAD_IN_NODES kurze Absätze in Folge brechen die Suche sicher ab',
	str_repeat('<p>Kurz.</p>', 10) . '<figure><img src="https://example.com/foto.jpg"></figure>',
	0
);

echo "\n\033[1mimagesMatchForDedup(): URL-Ähnlichkeit für die Caption-Zuordnung in Step 12\033[0m\n";

$checkMatch(
	'WordPress-Größenvariante im Content ("-1024x576") vs. Originaldatei als og:image',
	'https://example.com/wp-content/uploads/2024/foto-1024x576.jpg',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$checkMatch(
	'CDN-Resize-Query-String (Jetpack-Photon-Stil) unterscheidet sich nur per "?"',
	'https://example.com/wp-content/uploads/2024/foto.jpg?resize=780%2C439&ssl=1',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$checkMatch(
	'AEM-Bildserver-Renditions (ARD/rbb-Stil): unterschiedliche quality=/size=-Segmente, gleiches Basis-Bild',
	'https://example.com/content/dam/foto.jpg.jpg/quality=160/size=1376x774.jpg',
	'https://example.com/content/dam/foto.jpg.jpg/size=1280x720.jpg',
	true
);

$checkMatch(
	'Domain-Alias derselben Redaktion (rbb24.de vs. altes rbb-online.de), identischer Pfad → Pfad-Fallback greift',
	'https://www.rbb24.de/content/dam/rbb/rbb/rbb24/2026/2026_09/dpa-account/foto.jpg.jpg/quality=160/size=1376x774.jpg',
	'https://www.rbb-online.de/content/dam/rbb/rbb/rbb24/2026/2026_09/dpa-account/foto.jpg.jpg/size=1280x720.jpg',
	true
);

$checkMatch(
	'Komplett anderes Bild (unterschiedlicher Basis-Dateiname) matcht nicht',
	'https://example.com/wp-content/uploads/2024/anderes-foto.jpg',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$checkMatch(
	'Unterschiedlicher Host UND unterschiedlicher Pfad matcht nicht (Pfad-Fallback ist kein Freifahrtschein)',
	'https://cdn-a.example.com/wp-content/uploads/2024/foto.jpg',
	'https://cdn-b.example.com/assets/2024/anderes-foto.jpg',
	false
);

$checkMatch(
	'Größenvariante eines ANDEREN Bildes matcht nicht',
	'https://example.com/wp-content/uploads/2024/anderes-foto-1024x576.jpg',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

echo "\n" . str_repeat('─', 72) . "\n";
if ($failures === []) {
	echo "\033[32mAlle " . $passed . " Prüfungen bestanden.\033[0m\n";
	exit(0);
}
echo "\033[31m" . count($failures) . ' von ' . ($passed + count($failures)) . " Prüfungen fehlgeschlagen:\033[0m\n";
foreach ($failures as $failure) {
	echo '  · ' . $failure . "\n";
}
exit(1);
