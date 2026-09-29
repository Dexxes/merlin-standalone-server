<?php

declare(strict_types=1);

/**
 * Testharness für Service\SupportBoxService (Support-Infobox), analog zu
 * tools/test-support-box.php in merlin-nextcloud: läuft komplett gegen eine
 * temporäre SQLite-Datei und die mitgelieferten Bundle-Filter, ohne
 * HTTP-Layer. Reines PHP, kein PHPUnit nötig.
 *
 * Aufruf: php tools/test-support-box.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Merlin\Auth\CredentialCipher;
use Merlin\Db\ArticleRepository;
use Merlin\Db\ContentFilterRepository;
use Merlin\Db\SiteCredential;
use Merlin\Db\SiteCredentialRepository;
use Merlin\Db\UserRepository;
use Merlin\Db\UserSettingsRepository;
use Merlin\Migration\MigrationRunner;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\ContentFilterMerger;
use Merlin\Service\SiteCredentialService;
use Merlin\Service\SupportBoxService;
use Psr\Log\NullLogger;

$failures = 0;
function check(string $label, bool $condition): void {
    global $failures;
    echo ($condition ? '  OK   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$dbPath = tempnam(sys_get_temp_dir(), 'merlin-sb-') . '.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
(new MigrationRunner($pdo))->migrate();

$logger = new NullLogger();
$filters = new ContentFilterRepository($pdo, $logger, new ContentFilterMerger($logger));
$credentialRepo = new SiteCredentialRepository($pdo);
$credentials = new SiteCredentialService($credentialRepo, $filters, new CredentialCipher(''), $logger);
$userSettings = new UserSettingsRepository($pdo);
$service = new SupportBoxService($filters, $credentials, $userSettings);

$users = new UserRepository($pdo);
$owner = $users->create('owner', 'owner@example.com', 'x', 'user');
$userId = (int) $owner['id'];

$articles = new ArticleRepository($pdo);
function article(ArticleRepository $repo, int $userId, string $url, string $siteName): array {
    $id = $repo->insertPlaceholder($userId, $url, 'Titel', $siteName);
    return $repo->find($id, $userId);
}

echo "== URL-Auswahl aus der Domain-Config ==\n";
$taz = article($articles, $userId, 'https://taz.de/Ein-Artikel/!123/', 'taz');
$box = $service->forReader($taz, $userId);
check('taz.de: Box vorhanden', $box !== null);
check('taz.de: Abo-URL aus <paywall><subscribe>', ($box['subscribeUrl'] ?? null) === 'https://taz.de/abo/');
check('taz.de: Spenden-URL aus <metadata><donations>', str_starts_with((string) ($box['donationsUrl'] ?? ''), 'https://taz.de/taz-zahl-ich/'));
check('taz.de: siteName aus dem Artikel', ($box['siteName'] ?? null) === 'taz');
check('Default-Akzentfarbe ohne Nutzereinstellung', ($box['accentColor'] ?? null) === SupportBoxService::DEFAULT_ACCENT);

$spiegel = article($articles, $userId, 'https://www.spiegel.de/politik/x-a-1.html', '');
$box = $service->forReader($spiegel, $userId);
check('spiegel.de (www.-Präfix): nur Abo-URL', $box !== null && $box['subscribeUrl'] === 'https://abo.spiegel.de/' && $box['donationsUrl'] === null);
check('leerer siteName fällt auf die Domain zurück', ($box['siteName'] ?? null) === 'spiegel.de');

$unknown = article($articles, $userId, 'https://example.com/a', 'Example');
check('Domain ohne Config: keine Box', $service->forReader($unknown, $userId) === null);
$heise = article($articles, $userId, 'https://heise.de/x', 'heise');
check('heise.de: Box aus <paywall><subscribe>', ($service->forReader($heise, $userId)['subscribeUrl'] ?? null) !== null);

echo "== Akzentfarbe ==\n";
$userSettings->setForUser($userId, 'accentColor', '#0082c9');
check('gültige Akzentfarbe des Nutzers', $service->forReader($taz, $userId)['accentColor'] === '#0082c9');
$userSettings->setForUser($userId, 'accentColor', 'red; background:url(x)');
check('ungültige Akzentfarbe fällt auf den Default zurück', $service->forReader($taz, $userId)['accentColor'] === SupportBoxService::DEFAULT_ACCENT);

echo "== Abo-Login blendet die Box im Reader aus, nicht im Share ==\n";
$credentialRepo->upsert($userId, 'taz.de', [
    'usernameEnc' => 'x', 'passwordEnc' => 'x', 'sessionCookiesEnc' => null, 'cookieExpiresAt' => null,
    'lastLoginStatus' => SiteCredential::STATUS_INVALID_CREDENTIALS, 'lastLoginAt' => gmdate('c'),
]);
check('fehlgeschlagener Login zählt nicht als Abo: Box bleibt', $service->forReader($taz, $userId) !== null);
$credentialRepo->upsert($userId, 'taz.de', [
    'usernameEnc' => 'x', 'passwordEnc' => 'x', 'sessionCookiesEnc' => null, 'cookieExpiresAt' => null,
    'lastLoginStatus' => SiteCredential::STATUS_OK, 'lastLoginAt' => gmdate('c'),
]);
check('aktiver Login: Box im Reader entfällt', $service->forReader($taz, $userId) === null);
check('aktiver Login: Share zeigt die Box trotzdem', $service->forShare($taz, $userId) !== null);

echo "== Unsichere URLs aus Custom-Filtern ==\n";
$other = $users->create('other', 'other@example.com', 'x', 'user');
$otherId = (int) $other['id'];
$filters->saveUserCustom($otherId, 'spiegel.de', '<domain name="spiegel.de"><paywall><subscribe url="javascript:alert(1)"/></paywall></domain>');
$spiegelOther = article($articles, $otherId, 'https://www.spiegel.de/politik/x-a-1.html', 'Spiegel');
check('javascript:-URL wird nie ausgeliefert', $service->forReader($spiegelOther, $otherId) === null);

echo "== Icon der konkreten Seite ==\n";
$extractor = new ContentExtractorService($logger, $filters, $credentials);
$iconOf = static function (string $html, string $url) use ($extractor): ?string {
    $m = new ReflectionMethod($extractor, 'extractSiteIconUrl');
    return $m->invoke($extractor, $html, $url);
};
$page = 'https://www.example.org/politik/artikel/x.html';
check('apple-touch-icon (größtes) schlägt rel=icon',
    $iconOf('<head><link rel="icon" href="/f.png" sizes="32x32"><link rel="apple-touch-icon" sizes="120x120" href="/a120.png"><link rel="apple-touch-icon" sizes="180x180" href="/a180.png"></head>', $page) === 'https://www.example.org/a180.png');
check('SVG-Icon schlägt PNG und ICO',
    $iconOf('<link rel="shortcut icon" href="/f.ico"><link rel="icon" href="/big.png" sizes="192x192"><link rel="icon" type="image/svg+xml" href="/i.svg">', $page) === 'https://www.example.org/i.svg');
check('größtes Bitmap gewinnt innerhalb der Klasse',
    $iconOf('<link rel="icon" href="/16.png" sizes="16x16"><link rel="icon" href="/96.png" sizes="96x96">', $page) === 'https://www.example.org/96.png');
check('relative URL ohne führenden Slash wird gegen die Seite aufgelöst',
    $iconOf('<link rel="icon" href="img/i.png">', $page) === 'https://www.example.org/politik/artikel/img/i.png');
check('<base href> beeinflusst relative Icon-URLs',
    $iconOf('<base href="https://cdn.example.org/assets/"><link rel="icon" href="i.png">', $page) === 'https://cdn.example.org/assets/i.png');
check('protokollrelative URL', $iconOf('<link rel="icon" href="//static.example.org/i.png">', $page) === 'https://static.example.org/i.png');
check('mask-icon wird ignoriert', $iconOf('<link rel="mask-icon" href="/m.svg">', $page) === 'https://www.example.org/favicon.ico');
check('data:-Icon wird ignoriert', $iconOf('<link rel="icon" href="data:image/png;base64,AAAA">', $page) === 'https://www.example.org/favicon.ico');
check('javascript:-Icon wird ignoriert', $iconOf('<link rel="icon" href="javascript:alert(1)">', $page) === 'https://www.example.org/favicon.ico');
check('msapplication-TileImage als Fallback', $iconOf('<meta name="msapplication-TileImage" content="/tile.png">', $page) === 'https://www.example.org/tile.png');
check('ohne Angabe: /favicon.ico der Origin', $iconOf('<html><head><title>x</title></head></html>', 'http://example.org:8080/a') === 'http://example.org:8080/favicon.ico');
check('og:image wird nie als Icon genommen', $iconOf('<meta property="og:image" content="https://x.org/banner.jpg">', $page) === 'https://www.example.org/favicon.ico');

$withIcon = $articles->insertPlaceholder($userId, 'https://www.spiegel.de/a', 'T', 'Spiegel');
$articles->applyExtractionResult($withIcon, [
    'url' => 'https://www.spiegel.de/a', 'title' => 'T', 'content' => '<p>x</p>', 'excerpt' => null, 'author' => null,
    'siteName' => 'Spiegel', 'imageUrl' => null, 'siteIconUrl' => 'https://www.spiegel.de/apple-touch-icon.png',
    'readingTime' => 1, 'publishedAt' => null, 'category' => null,
]);
$row = $articles->find($withIcon, $userId);
check('gespeichertes Seiten-Icon wird als iconUrl ausgeliefert', $service->forReader($row, $userId)['iconUrl'] === 'https://www.spiegel.de/apple-touch-icon.png');
$articles->applyExtractionResult($withIcon, [
    'url' => 'https://www.spiegel.de/a', 'title' => 'T', 'content' => '<p>x</p>', 'excerpt' => null, 'author' => null,
    'siteName' => 'Spiegel', 'imageUrl' => null, 'readingTime' => 1, 'publishedAt' => null, 'category' => null,
]);
check('Extraktion ohne Icon überschreibt ein vorhandenes nicht', $articles->find($withIcon, $userId)['site_icon_url'] === 'https://www.spiegel.de/apple-touch-icon.png');
check('Altartikel ohne Icon: /favicon.ico der Origin', $service->forReader($spiegel, $userId)['iconUrl'] === 'https://www.spiegel.de/favicon.ico');

unlink($dbPath);
echo "\n" . ($failures === 0 ? "Alle Checks bestanden.\n" : "{$failures} Check(s) fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
