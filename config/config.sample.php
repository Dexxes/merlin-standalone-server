<?php

declare(strict_types=1);

// Kopieren nach config.php und anpassen. config.php ist gitignored.
return [
    'db_path' => __DIR__ . '/../data/merlin.sqlite',
    'log_path' => __DIR__ . '/../data/merlin.log',

    // Basis-URL des Servers, ohne trailing slash - wird für Links in
    // Passwort-Reset-Mails gebraucht (mail() kennt den Request-Host nicht
    // zuverlässig, z.B. bei Cron/CLI-Aufrufen).
    'base_url' => 'https://merlin.example.com',

    // Basis-Pfad, falls der Server unter einem Subpath deployed ist
    // (z.B. '/tools/merlin' statt unter Root). Der Router zieht diesen Pfad
    // von REQUEST_URI ab, bevor er Routes matched - ohne diesen Eintrag wird
    // '/' angenommen (Server unter Root).
    'base_path' => '/',

    'mail' => [
        'from_address' => 'merlin@example.com',
        'from_name' => 'Merlin',
    ],

    // TTL für Passwort-Reset-Tokens in Sekunden.
    'password_reset_ttl' => 3600,

    // Schlüssel für Auth\CredentialCipher (Paywall-Abo-Zugangsdaten, z. B.
    // Tagesspiegel Plus): 32 Byte, base64-kodiert. Erzeugen z. B. per
    // `php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"`.
    // Ohne gesetzten Schlüssel funktioniert der Rest von Merlin unverändert -
    // nur das Speichern/Nutzen von Paywall-Zugangsdaten schlägt dann fehl.
    // WICHTIG: nach dem ersten Einsatz nicht mehr ändern, sonst werden
    // bereits gespeicherte Zugangsdaten unlesbar (Nutzer müsste sie neu eingeben).
    'credential_cipher_key' => '',

    // Piper-TTS-Daemon (Vorlesefunktion) - lokal per Standard, aber
    // konfigurierbar, falls merlin-server nicht auf derselben Maschine läuft
    // wie der Daemon (z.B. Container-Setup). Ohne erreichbaren Daemon liefert
    // der TTS-Endpunkt sauber HTTP 503 statt eines Timeouts.
    'tts' => [
        'daemon_url' => 'http://127.0.0.1:5051',
    ],
];
