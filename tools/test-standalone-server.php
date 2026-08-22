<?php

declare(strict_types=1);

/**
 * Smoke-Test für den Standalone-Server: läuft komplett gegen eine temporäre
 * SQLite-Datei, ohne HTTP-Layer (Router/Middleware werden hier nicht
 * mitgetestet - siehe Plan für den manuellen curl-Testlauf gegen den
 * PHP-Built-in-Server). Reines PHP, kein PHPUnit nötig, analog zu
 * tools/test-content-filter-merge.php in merlin-nextcloud.
 *
 * Aufruf: php tools/test-standalone-server.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Merlin\Auth\ApiTokenService;
use Merlin\Auth\PasswordHasher;
use Merlin\Db\ApiTokenRepository;
use Merlin\Db\ArticleRepository;
use Merlin\Db\ArticleShareRepository;
use Merlin\Db\HighlightRepository;
use Merlin\Db\LoginFlowRepository;
use Merlin\Db\TagRepository;
use Merlin\Db\UserRepository;
use Merlin\Db\UserSettingsRepository;
use Merlin\Migration\MigrationRunner;

$failures = 0;
function check(string $label, bool $condition): void {
    global $failures;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        echo "  FAIL {$label}\n";
        $failures++;
    }
}

$dbPath = tempnam(sys_get_temp_dir(), 'merlin-test-') . '.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

echo "== Migrationen ==\n";
$runner = new MigrationRunner($pdo);
$applied = $runner->migrate();
check('mindestens eine Migration angewandt', count($applied) > 0);
check('erneuter Lauf wendet nichts mehr an', $runner->migrate() === []);

echo "== Users & Passwort-Hashing ==\n";
$users = new UserRepository($pdo);
$hasher = new PasswordHasher();
$admin = $users->create('admin', 'admin@example.com', $hasher->hash('supersecret123'), 'admin');
check('Admin wurde angelegt', $admin !== null && $admin['role'] === 'admin');
check('Passwort verifiziert korrekt', $hasher->verify('supersecret123', $admin['password_hash']));
check('falsches Passwort schlägt fehl', !$hasher->verify('wrong-password', $admin['password_hash']));

$user = $users->create('reader', 'reader@example.com', $hasher->hash('anotherpass123'), 'user');
check('zweiter User wurde angelegt', $user !== null);
check('countAdmins liefert 1', $users->countAdmins() === 1);

echo "== API-Tokens ==\n";
$tokenRepo = new ApiTokenRepository($pdo);
$tokenService = new ApiTokenService($tokenRepo);
$created = $tokenService->create((int) $user['id'], 'Test-Gerät');
check('Token wurde erzeugt', isset($created['plainText']) && strlen($created['plainText']) === 64);

$verified = $tokenService->verify($created['plainText']);
check('gültiges Token wird erkannt', $verified !== null && (int) $verified['user_id'] === (int) $user['id']);
check('falsches Token wird abgelehnt', $tokenService->verify('not-a-real-token') === null);

$tokenRepo->revoke((int) $created['token']['id'], (int) $user['id']);
check('widerrufenes Token wird nicht mehr erkannt', $tokenService->verify($created['plainText']) === null);

echo "== Artikel, Tags, Highlights (Datenisolation zwischen Usern) ==\n";
$articles = new ArticleRepository($pdo);
$tags = new TagRepository($pdo);
$highlights = new HighlightRepository($pdo);

$articleId = $articles->insertPlaceholder((int) $user['id'], 'https://example.com/a', 'example.com', 'example.com');
$otherArticleId = $articles->insertPlaceholder((int) $admin['id'], 'https://example.com/b', 'example.com', 'example.com');

check('Artikel gehört dem erstellenden User', $articles->find($articleId, (int) $user['id']) !== null);
check('fremder User sieht den Artikel nicht', $articles->find($articleId, (int) $admin['id']) === null);

$tag = $tags->create((int) $user['id'], 'tech', '#0082c9');
$tags->addToArticle($articleId, (int) $tag['id']);
$articleTags = $tags->findByArticleId($articleId);
check('Tag wurde am Artikel verknüpft', count($articleTags) === 1 && $articleTags[0]['name'] === 'tech');

$articles->applyExtractionResult($articleId, [
    'url' => 'https://example.com/a',
    'title' => 'Ein Testartikel',
    'content' => '<p>Inhalt</p>',
    'excerpt' => 'Inhalt',
    'author' => null,
    'siteName' => 'Example',
    'imageUrl' => null,
    'readingTime' => 3,
    'publishedAt' => null,
    'category' => null,
]);
$extracted = $articles->find($articleId, (int) $user['id']);
check('Extraktion setzt isProcessing zurück', ((int) $extracted['is_processing']) === 0);
check('Extraktion setzt den Titel', $extracted['title'] === 'Ein Testartikel');

$counts = $articles->getCounts((int) $user['id']);
check('Counts zählen nur eigene Artikel', $counts['total'] === 1);

$highlight = $highlights->create($articleId, (int) $user['id'], [
    'highlightedText' => 'wichtiger Satz',
    'startXpath' => '/p[1]',
    'startOffset' => 0,
    'endXpath' => '/p[1]',
    'endOffset' => 14,
    'color' => '#ffeb3b',
]);
check('Highlight wurde angelegt', $highlight['highlighted_text'] === 'wichtiger Satz');
check('Highlight fremden Users bleibt leer', $highlights->findByArticleId($otherArticleId, (int) $user['id']) === []);

echo "== Login-Flow-v2-Klon ==\n";
$loginFlows = new LoginFlowRepository($pdo);

$flowToken = bin2hex(random_bytes(32));
$pollToken = bin2hex(random_bytes(32));
$loginFlows->create($flowToken, $pollToken, gmdate('c', time() + 600));

$flow = $loginFlows->findByFlowToken($flowToken);
check('Flow ist per Flow-Token auffindbar', $flow !== null && $flow['user_id'] === null);

$pollBeforeCompletion = $loginFlows->findByPollToken($pollToken);
check('Poll-Token ist vor Abschluss auffindbar, aber ohne User', $pollBeforeCompletion !== null && $pollBeforeCompletion['user_id'] === null);

$loginFlows->complete((int) $flow['id'], (int) $user['id'], 'plaintext-token-abc');
$completed = $loginFlows->findByPollToken($pollToken);
check('Nach complete() ist der User gebunden', $completed !== null && (int) $completed['user_id'] === (int) $user['id']);
check('Klartext-Token wurde gespeichert', $completed['api_token_plaintext'] === 'plaintext-token-abc');

$loginFlows->delete((int) $completed['id']);
check('Nach delete() ist der Flow weg (Single-Use)', $loginFlows->findByPollToken($pollToken) === null);

$expiredFlowToken = bin2hex(random_bytes(32));
$expiredPollToken = bin2hex(random_bytes(32));
$loginFlows->create($expiredFlowToken, $expiredPollToken, gmdate('c', time() - 60));
$loginFlows->deleteExpired();
check('deleteExpired() räumt abgelaufene Flows auf', $loginFlows->findByFlowToken($expiredFlowToken) === null);

echo "== Settings-Sync ==\n";
$userSettings = new UserSettingsRepository($pdo);

check('Noch keine Einstellungen gespeichert', $userSettings->getAllForUser((int) $user['id']) === []);

$userSettings->setForUser((int) $user['id'], 'theme', 'dark');
$userSettings->setForUser((int) $user['id'], 'fontSize', '19');
$stored = $userSettings->getAllForUser((int) $user['id']);
check('Werte wurden gespeichert', $stored['theme'] === 'dark' && $stored['fontSize'] === '19');

$userSettings->setForUser((int) $user['id'], 'theme', 'light');
check('Erneutes Speichern überschreibt (kein Duplikat)', $userSettings->getAllForUser((int) $user['id'])['theme'] === 'light');

check('Andere User sehen fremde Einstellungen nicht', $userSettings->getAllForUser((int) $admin['id']) === []);

echo "== Public-Share-Links ==\n";
$shares = new ArticleShareRepository($pdo);

$share = $shares->create($articleId, (int) $user['id'], 'test-token-abc', null, null);
check('Share wurde angelegt', $share['token'] === 'test-token-abc');
check('Share ohne Passwort: hasPassword=false', ArticleShareRepository::hasPassword($share) === false);
check('Share ohne Ablaufdatum: isExpired=false', ArticleShareRepository::isExpired($share) === false);

$byToken = $shares->findByToken('test-token-abc');
check('Share ist per Token auffindbar', $byToken !== null && (int) $byToken['article_id'] === $articleId);

$byArticle = $shares->findByArticleId($articleId, (int) $user['id']);
check('Share ist per Artikel+User auffindbar', $byArticle !== null && $byArticle['id'] === $share['id']);

$shares->registerFailedUnlock((int) $share['id']);
$shares->registerFailedUnlock((int) $share['id']);
$afterFails = $shares->findByToken('test-token-abc');
check('Fehlversuche werden gezählt', (int) $afterFails['failed_unlock_attempts'] === 2);

$shares->resetFailedUnlock((int) $share['id']);
check('Fehlversuche-Zähler wird zurückgesetzt', (int) $shares->findByToken('test-token-abc')['failed_unlock_attempts'] === 0);

$regenerated = $shares->update((int) $share['id'], ['token' => 'test-token-xyz']);
check('Regenerieren tauscht den Token', $regenerated['token'] === 'test-token-xyz');
check('Alter Token ist ungültig', $shares->findByToken('test-token-abc') === null);

$expiredShare = $shares->create($otherArticleId, (int) $admin['id'], 'expired-token', null, gmdate('c', time() - 60));
check('Abgelaufener Share wird als expired erkannt', ArticleShareRepository::isExpired($expiredShare) === true);

$publicArray = ArticleShareRepository::toPublicArray($regenerated, 'https://example.com/s/test-token-xyz');
check('toPublicArray enthält nie den Passwort-Hash', !array_key_exists('password_hash', $publicArray) && !array_key_exists('passwordHash', $publicArray));

$shares->deleteByArticleId($articleId, (int) $user['id']);
check('deleteByArticleId entfernt den Share', $shares->findByArticleId($articleId, (int) $user['id']) === null);

unlink($dbPath);

echo "\n" . ($failures === 0 ? "Alle Checks bestanden.\n" : "{$failures} Check(s) fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
