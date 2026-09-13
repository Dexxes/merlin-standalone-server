<?php

declare(strict_types=1);

/**
 * Testharness für die Hero-Bild-Deduplizierung in Step 12 von
 * ContentExtractorService::processHtml() (contentStartsWithMatchingImage(),
 * firstNonWhitespaceElementChild(), firstImageInPicture(), imagesMatchForDedup()).
 *
 * Aufruf: php tools/test-hero-image-dedup.php
 *
 * Hintergrund: Diese Logik ist aus merlin-nextcloud portiert (dort entstanden
 * über mehrere Fixes, siehe dortige ContentExtractorService.php-Historie).
 * Step 12 prüfte hier bisher nur, ob irgendwo in den ersten 2000 Zeichen des
 * Contents ein <img> vorkommt - das unterdrückte das Voranstellen fälschlich
 * schon bei einem früh im Fließtext sitzenden, andersartigen Bild, UND
 * erkannte ein am Anfang in <p>/<a>/<div>/<span>/<figure>/<picture> verpacktes
 * Hero-Bild (z. B. WordPress-typisch <p><a href="…"><img src="…"></a></p>,
 * oder ein Gutenberg-Bildblock, oder responsive <picture>-Markup) gar nicht
 * erst als "bereits vorhanden", sodass ein zweites, redundantes
 * merlin-hero-image vorangestellt wurde.
 *
 * Der Bild-Abgleich lief zudem, wo er überhaupt existierte, per exaktem
 * String-Vergleich der (normalisierten) src-URL gegen die per og:image
 * ermittelte imageUrl. In der Praxis unterscheiden sich beide URLs aber sehr
 * häufig NUR durch eine von WordPress automatisch angehängte Größenvariante
 * (z. B. "foto-1024x576.jpg" im Content vs. "foto.jpg" als og:image), durch
 * CDN-Resize-Parameter in der Query-String, oder durch AEM-Bildserver-
 * Renditions-Pfadsegmente (z. B. bei ARD/rbb-Quellen: "/size=1280x720.jpg"
 * bzw. "/quality=160/size=1376x774.jpg"). Dieses Script deckt alle Fälle ab
 * und prüft zugleich, dass die Sicherheitseigenschaft erhalten bleibt: ein
 * echtes, andersartiges Bild darf das Voranstellen weiterhin nicht verhindern.
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
$method  = new ReflectionMethod(ContentExtractorService::class, 'contentStartsWithMatchingImage');
$method->setAccessible(true);
$normalizeUrl = new ReflectionMethod(ContentExtractorService::class, 'normalizeUrl');
$normalizeUrl->setAccessible(true);

$passed   = 0;
$failures = [];

$baseUrl = 'https://example.com/blog/some-article/';

/**
 * @param string $html       Content, wie es nach cleanHtml() an Step 12 ankommt (ohne führenden Leerraum).
 * @param string $imageUrl   Roh-imageUrl, wie sie z. B. aus og:image stammt (wird wie in processHtml() normalisiert).
 */
$check = function (string $label, string $html, string $imageUrl, bool $expected) use (
	$service, $method, $normalizeUrl, $baseUrl, &$passed, &$failures
): void {
	$normalizedImageUrl = $normalizeUrl->invoke($service, $imageUrl, $baseUrl);
	$actual = $method->invoke($service, ltrim($html), $normalizedImageUrl, $baseUrl);

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

echo "\n\033[1mErkannte Wrapper-Fälle (kein zweites Hero-Bild voranstellen)\033[0m\n";

$check(
	'Einfacher WordPress-Wrapper <p><a><img>, exakt gleiche URL',
	'<p><a href="https://example.com/x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Readability-Wrapper-Div mit vielen Geschwister-Absätzen',
	'<div><p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Absatz 2</p><p>Absatz 3</p></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'WordPress-Größenvariante im Content ("-1024x576") vs. Originaldatei als og:image',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto-1024x576.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Umgekehrt: og:image trägt die Größenvariante, Content die Originaldatei',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto-780x439.jpg',
	true
);

$check(
	'CDN-Resize-Query-String (Jetpack-Photon-Stil) unterscheidet sich nur per "?"',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg?resize=780%2C439&ssl=1"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Gutenberg-Bildblock <figure> verschachtelt in Readability-Wrapper-Div',
	'<div><figure><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></figure><p>Text.</p></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Altes WP-[caption]-Shortcode-Div: <a><img> plus Geschwister-<p class=wp-caption-text>',
	'<div><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a><p>Bildunterschrift</p></div><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Geschütztes Leerzeichen (&nbsp;) vor dem verpackten Bild',
	"<div>\u{00A0}<p><a href=\"x\"><img src=\"https://example.com/wp-content/uploads/2024/foto.jpg\"></a></p></div>",
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'<picture>-Wrapper mit mehreren <source>-Geschwistern vor <img> (responsive Bilder)',
	'<div><picture><source srcset="foto.webp" type="image/webp"><source srcset="foto.avif" type="image/avif"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></picture></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'<picture> mit NICHT selbst-schließenden <source>-Tags (libxml2 2.9.14 verschachtelt diese ineinander statt sie als Void-Element zu behandeln, siehe firstImageInPicture())',
	'<figure><picture>
 <source srcset="a.webp" type="image/webp" width="960" height="540" media="(max-width:768px)">
 <source srcset="b.jpg" type="image/jpeg" width="960" height="540" media="(max-width:768px)">
 <source srcset="c.webp" type="image/webp" width="1536" height="864" media="(max-width:860px)">
 <img src="https://example.com/wp-content/uploads/2024/foto.jpg" width="1376" height="774">
</picture></figure>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'AEM-Bildserver-Renditions (ARD/rbb-Stil): "/size=WxH.jpg" bzw. "/quality=N/size=WxH.jpg"-Pfadsegmente statt WordPress-Suffix oder Query-String',
	'<figure><picture><source srcset="x"><img src="https://example.com/content/dam/foto.jpg.jpg/quality=160/size=1376x774.jpg"></picture></figure>',
	'https://example.com/content/dam/foto.jpg.jpg/size=1280x720.jpg',
	true
);

$check(
	'Protokoll-relative src ("//…") vs. https-imageUrl',
	'<p><a href="x"><img src="//example.com/wp-content/uploads/2024/foto.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Bildserver-Parameterkette im Dateinamen (spiegel.de-Stil): "_w960_r1.5_fpx54_fpy43" vs. "_w1200_r1.778_fpx54_fpy43"',
	'<figure><picture><source srcset="x"><img src="https://cdn.prod.www.spiegel.de/images/2d8f3fcc-c7fa-4404-a47a-c275a8a7a05a_w960_r1.5_fpx54_fpy43.jpg"></picture></figure>',
	'https://cdn.prod.www.spiegel.de/images/2d8f3fcc-c7fa-4404-a47a-c275a8a7a05a_w1200_r1.778_fpx54_fpy43.jpg',
	true
);

$check(
	'Tief verschachteltes, rein layoutbedingtes Wrapping (spiegel.de-Stil: 2 Divs vor <figure>, 2 weitere darin für Positionierung, dann erst <picture>) - Regression für die zu knappe alte Tiefenbegrenzung',
	'<div><div><figure><div><div><picture><source srcset="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></picture></div></div></figure></div></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

echo "\n\033[1mWeiterhin korrekt NICHT erkannt (Hero-Bild muss vorangestellt werden)\033[0m\n";

$check(
	'Früh im Fließtext sitzendes, andersartiges Bild (komplett anderer Dateiname)',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/anderes-foto.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Größenvariante eines ANDEREN Bildes (unterschiedlicher Basis-Dateiname)',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/anderes-foto-1024x576.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'AEM-Bildserver-Rendition eines ANDEREN Bildes (unterschiedlicher Basis-Pfad vor "/size=…")',
	'<p><a href="x"><img src="https://example.com/content/dam/anderes-foto.jpg.jpg/quality=160/size=1376x774.jpg"></a></p>',
	'https://example.com/content/dam/foto.jpg.jpg/size=1280x720.jpg',
	false
);

$check(
	'<picture> mit einem tatsächlich ANDEREN Bild (Fallback-<img> zeigt auf anderen Dateinamen)',
	'<figure><picture><source srcset="x"><img src="https://example.com/wp-content/uploads/2024/anderes-foto.jpg"></picture></figure>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Fortlaufende Kamera-Nummerierung OHNE Buchstaben-Key ("IMG_1234" vs. "IMG_1235") - zwei tatsächlich verschiedene Fotos, keine Bildserver-Variante',
	'<p><a href="x"><img src="https://example.com/photos/IMG_1234.jpg"></a></p>',
	'https://example.com/photos/IMG_1235.jpg',
	false
);

$check(
	'Text vor dem Bild innerhalb desselben Wrapper-Elements',
	'<p>Ein Foto: <img src="https://example.com/wp-content/uploads/2024/foto.jpg"></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Nicht unterstützter Wrapper-Tag (z. B. <section>) bricht die Entpackung sicher ab',
	'<section><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></section>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Leerer src am Ende der Wrapper-Kette',
	'<p><a href="x"><img src=""></a></p>',
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
