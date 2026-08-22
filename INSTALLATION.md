# Installation – merlin-server

Voraussetzungen: PHP 8.4 mit den Extensions `pdo_sqlite`, `curl`, `dom`,
`mbstring`; Composer; ein lokaler Mailversand (`sendmail`/Postfix) für
Passwort-Reset-Mails; ein Webserver (Apache mit `mod_rewrite` oder nginx), der
alle Requests auf `public/index.php` durchreicht - `public/` muss der
Document-Root sein.

## 1. Abhängigkeiten installieren

```bash
cd merlin-server
composer install --no-dev
```

## 2. Konfiguration anlegen

```bash
cp config/config.sample.php config/config.php
```

`config/config.php` anpassen: `base_url` (für Links in Reset-Mails),
`mail.from_address`/`mail.from_name`. `db_path` und `log_path` können
Default bleiben.

## 3. Datenbank initialisieren

```bash
php tools/migrate.php
```

Legt `data/merlin.sqlite` an und wendet alle Migrationen an. Erneuter Aufruf
ist idempotent (wendet nur neue Migrationen an).

## 4. Ersten Admin-Account anlegen

```bash
php tools/create-admin.php --username=admin --email=admin@example.com
```

Fragt interaktiv nach dem Passwort (mind. 8 Zeichen), falls `--password` nicht
mitgegeben wird.

## 5. Webserver konfigurieren

`public/` muss Document-Root sein, `data/` und `config/` sollten außerhalb des
öffentlich erreichbaren Verzeichnisbaums liegen (bei einer Struktur wie oben
sind sie das automatisch, solange nur `public/` als Document-Root
eingerichtet wird).

**Apache**: `.htaccess` in `public/` reicht (mod_rewrite muss aktiv sein).

**nginx** (Beispiel):

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**PHP-FPM-Timeout**: Wie bei merlin-nextcloud (siehe dortige
`Bekannte Limitierungen`) darf `request_terminate_timeout` die
Hintergrund-Extraktion nach `POST /api/articles` nicht abwürgen - auf einem
Synology-NAS mit Default 30s ggf. auf `0` setzen.

## 6. Smoke-Test (optional, vor dem produktiven Einsatz)

```bash
php tools/test-standalone-server.php
```

Läuft komplett gegen eine temporäre SQLite-Datei (Migrationen, User/Token-
Erzeugung, Artikel/Tag/Highlight-Isolation zwischen zwei Usern) und räumt sich
selbst auf.

## 7. Manueller End-to-End-Test

```bash
php -S localhost:8080 -t public
```

Dann z.B. per curl: Login → Token unter `/account/tokens` erzeugen → mit
`curl -u username:token http://localhost:8080/api/articles` prüfen. Siehe
Verification-Abschnitt im Plan für den vollständigen Testlauf.
