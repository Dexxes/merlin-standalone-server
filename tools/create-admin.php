<?php

declare(strict_types=1);

/**
 * Legt den ersten (oder einen weiteren) Admin-Account an.
 * Aufruf: php tools/create-admin.php --username=jvb --email=jvb@example.com [--password=...]
 * Ohne --password wird interaktiv danach gefragt (Eingabe ist NICHT maskiert -
 * für ein einmaliges Server-Setup per SSH ausreichend).
 */

use Merlin\App;

require_once __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['username:', 'email:', 'password::']);

$username = trim((string) ($options['username'] ?? ''));
$email = trim((string) ($options['email'] ?? ''));
$password = isset($options['password']) ? (string) $options['password'] : null;

if ($username === '' || $email === '') {
    fwrite(STDERR, "Usage: php tools/create-admin.php --username=<name> --email=<email> [--password=<password>]\n");
    exit(1);
}

if ($password === null) {
    fwrite(STDOUT, "Passwort (mind. 8 Zeichen): ");
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Das Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}

$app = new App();
$users = $app->users();

if ($users->findByUsername($username) !== null) {
    fwrite(STDERR, "Benutzername '{$username}' ist bereits vergeben.\n");
    exit(1);
}
if ($users->findByEmail($email) !== null) {
    fwrite(STDERR, "E-Mail '{$email}' wird bereits verwendet.\n");
    exit(1);
}

$user = $users->create($username, $email, $app->passwordHasher()->hash($password), 'admin');

fwrite(STDOUT, "Admin-Account '{$user['username']}' (ID {$user['id']}) wurde angelegt.\n");
