# Struktur – merlin-server

Standalone-Server für Merlin, unabhängig von Nextcloud. SQLite als Datenbank,
eigene Account-Verwaltung (Admin/User/Passwort-Reset). Reines PHP 8.4, kein
Framework, kein Vue-Build. Details und Scope-Entscheidungen: siehe Plan in
`tasks/` bzw. die Konversation, die diesen Server angelegt hat.

## Backend (PHP)

```
merlin-server/
├── public/
│   ├── index.php              # Front-Controller: baut App + Router, dispatcht
│   └── .htaccess               # Rewrite auf index.php (Apache)
├── src/
│   ├── App.php                  # Manueller DI-Container (lazy Getter)
│   ├── Http/
│   │   ├── Router.php            # Routentabelle + Dispatch, {param}-Syntax
│   │   ├── Request.php           # $_SERVER/$_GET/JSON-Body/Basic-Auth-Header
│   │   ├── Response.php          # json()/html()/redirect()/noContent()
│   │   └── Middleware/
│   │       ├── AuthMiddleware.php       # Basic Auth (User+API-Token) ODER Session-Cookie
│   │       └── AdminOnlyMiddleware.php
│   ├── Auth/
│   │   ├── PasswordHasher.php     # password_hash()/password_verify()
│   │   ├── ApiTokenService.php    # Klartext-Token ⇄ SHA-256-Hash in api_tokens
│   │   ├── SessionService.php     # PHP-Session für die HTML-Seiten
│   │   └── PasswordResetService.php
│   ├── Mail/
│   │   ├── MailerInterface.php
│   │   └── PhpMailMailer.php      # mail(), austauschbar (später SmtpMailer)
│   ├── Logging/
│   │   └── FileLogger.php         # PSR-3, schreibt nach data/merlin.log
│   ├── Controller/
│   │   ├── PageController.php     # HTML: Login/Register/Passwort/Konto/Admin
│   │   ├── AccountController.php  # JSON: eigene API-Tokens verwalten
│   │   ├── AdminController.php    # JSON: User- und Instanz-Settings-Verwaltung
│   │   ├── ArticleController.php
│   │   ├── TagController.php
│   │   ├── HighlightController.php  # API; PageController.php rendert die Leseansicht (s.u.)
│   │   ├── LoginFlowController.php  # JSON-Teil des Login-Flow-v2-Klons (s.u.), HTML-Teil in PageController
│   │   ├── UserSettingsController.php # JSON: persönliche Einstellungen (Settings-Sync), Port von SettingsController
│   │   ├── ShareController.php      # JSON, authentifiziert: eigene Public-Share-Links verwalten
│   │   ├── PublicShareController.php # HTML+JSON, unauthentifiziert: öffentliche Share-Ansicht (s.u.)
│   │   ├── TtsController.php        # TTS-Proxy (authentifiziert), delegiert an TtsStreamService
│   │   ├── ContentFilterController.php     # JSON: Admin-Custom-Ebene der Content-Filter (instanzweit)
│   │   └── UserContentFilterController.php # JSON: persönliche Content-Filter-Overrides
│   ├── Service/
│   │   ├── ContentExtractorService.php     # Port aus merlin-nextcloud, kaum verändert
│   │   ├── DomainConfigProvider.php        # Interface, das ContentExtractorService braucht; implementiert von Db\ContentFilterRepository
│   │   ├── ExportService.php               # Port aus merlin-nextcloud: Artikel als Standalone-HTML exportieren
│   │   ├── ContentFilterMerger.php         # Port aus merlin-nextcloud, unverändert
│   │   ├── ContentFilterSchema.php         # Port aus merlin-nextcloud, unverändert
│   │   ├── ContentFilterValidator.php      # Port aus merlin-nextcloud, unverändert (Prüfung vor dem Speichern)
│   │   ├── ContentFilterTrace.php          # Port aus merlin-nextcloud, unverändert (Trefferzähler für den Testlauf)
│   │   └── TtsStreamService.php            # Port aus merlin-nextcloud: HTML→Plaintext→Piper-Daemon-Proxy, geteilt von TtsController + PublicShareController
│   ├── Db/
│   │   ├── Database.php            # PDO-SQLite-Singleton, PRAGMA foreign_keys=ON
│   │   ├── UserRepository.php
│   │   ├── ApiTokenRepository.php
│   │   ├── PasswordResetRepository.php
│   │   ├── SettingsRepository.php  # Key/Value, u.a. allow_self_registration (instanzweit)
│   │   ├── UserSettingsRepository.php # Key/Value pro Nutzer (Settings-Sync), getrennter Scope von SettingsRepository
│   │   ├── ArticleRepository.php   # PDO-Port von merlin-nextclouds ArticleMapper
│   │   ├── TagRepository.php       # PDO-Port von TagMapper
│   │   ├── HighlightRepository.php # PDO-Port von HighlightMapper
│   │   ├── ArticleShareRepository.php # PDO-Port von ArticleShareMapper (Public-Share-Links)
│   │   ├── ContentFilterRepository.php # PDO-Port von merlin-nextclouds ContentFilterRepository: Bundle (Datei) + Admin-/User-Custom (DB, Tabelle content_filters), implementiert Service\DomainConfigProvider
│   │   └── LoginFlowRepository.php # Login-Flow-v2-Klon: flow_token/poll_token, TTL 10 Min, Single-Use
│   └── Migration/
│       ├── MigrationRunner.php     # führt migrations/*.sql aus, trackt schema_migrations
│       └── migrations/001_initial.sql, 002_login_flow.sql, 003_share_and_settings.sql, 004_content_filters.sql
├── content-filters/            # Kopie der Bundle-Filter aus merlin-nextcloud (nur lesend, kein Sync-Tooling)
├── templates/                  # Server-seitige PHP-Templates, kein Vue
│   ├── partials/header.php, footer.php
│   ├── login.php, register.php, password_forgot.php, password_reset.php
│   ├── login_flow.php          # Login-Flow-v2-Klon: Formular/Erfolg/Ungültig-Zustand eines /login/v2/flow/{token}-Aufrufs
│   ├── account.php             # Token-Verwaltung, JS ruft /account/tokens per fetch()
│   ├── admin_users.php         # User-/Settings-Verwaltung, JS ruft /admin/* per fetch()
│   ├── admin_content_filters.php    # Admin-Custom-Ebene der Content-Filter (einfacher XML-Editor, kein Vue-Regel-Builder), JS ruft /api/admin/content-filters* per fetch()
│   ├── personal_content_filters.php # Persönliche Content-Filter-Overrides, JS ruft /api/user/content-filters* per fetch()
│   ├── library.php             # Leseliste (Startseite): Filter/Suche/Hinzufügen, JS ruft /api/articles* per fetch()
│   ├── article_reader.php      # Leseansicht eines Artikels, JS ruft /api/articles/{id} + /api/tags per fetch(); inkl. Teilen-Popover, <audio>-TTS-Player + HTML-Export-Link
│   └── public_share.php        # Öffentliche Share-Ansicht (kein Login), JS ruft /s/{token}/data|unlock|tts per fetch()
├── tools/
│   ├── migrate.php             # CLI: Migrationen anwenden
│   ├── create-admin.php        # CLI: Admin-Account anlegen
│   └── test-standalone-server.php  # Smoke-Test gegen temporäre SQLite-DB
├── config/config.sample.php    # Vorlage, nach config.php kopieren (gitignored); u.a. tts.daemon_url (Default http://127.0.0.1:5051)
└── data/                       # merlin.sqlite + merlin.log (gitignored)
```

## Wichtige Entwurfsentscheidungen

- **Kein Framework**: `Http/Router.php` ist ein ~70-Zeilen-Regex-Router,
  Middleware sind einfache `callable(Request): ?Response`.
- **Client-Auth**: API-Clients (iOS/Android/Extensions) senden HTTP Basic Auth
  mit Username + selbst erzeugtem API-Token (`AccountController`/`account.php`)
  statt eines Nextcloud-App-Passworts. Web-UI nutzt eine PHP-Session.
  `AuthMiddleware` akzeptiert beides.
- **Content-Extraktion**: `ArticleController::create()` legt sofort einen
  Platzhalter-Artikel an und plant die Extraktion per
  `register_shutdown_function` + `fastcgi_finish_request()` nach dem Response
  ein (Vorbild: `merlin-nextcloud/lib/Controller/ArticleController.php`).
  Kein SSE in v1 - Clients pollen das `isProcessing`-Feld.
- **Content-Filter-Verwaltung (Admin-Custom + persönliche User-Custom-Ebene)**:
  `Db\ContentFilterRepository` ist der PDO-Port von merlin-nextclouds
  gleichnamiger Klasse und implementiert `Service\DomainConfigProvider` für
  `ContentExtractorService` - dieselbe dreistufige Merge-Pipeline (Bundle <
  Admin-Custom < User-Custom, Fail-open bei kaputtem Custom-Filter,
  Request-lokaler Merge-Cache). Die beiden Custom-Ebenen liegen in der Tabelle
  `content_filters` (Migration `004_content_filters.sql`, Spalte `scope` =
  `'admin'`/`'user'`); `user_id = 0` ist der Sentinel für Admin-Zeilen (Pendant
  zu merlin-nextclouds `ADMIN_SENTINEL_USER_ID`). Bewusst **kein** FK auf
  `users(id)`: ein Admin-Filter soll beim Löschen des zuletzt speichernden
  Admins nicht mitgelöscht werden, und der Sentinel referenziert ohnehin
  keinen echten Nutzer. Private User-Overrides räumt stattdessen
  `AdminController::deleteUser()` explizit ab
  (`ContentFilterRepository::deleteAllUserCustom()`) - Pendant zu
  merlin-nextclouds `UserDeletedListener`.

  Verwaltet wird über `ContentFilterController` (Admin-API,
  `/api/admin/content-filters*`) und `UserContentFilterController`
  (Personal-API, `/api/user/content-filters*`) samt je einer eigenen
  HTML-Seite (`admin_content_filters.php`/`personal_content_filters.php`).
  Anders als merlin-nextcloud gibt es **keinen** visuellen Regel-Builder (kein
  Vue/Vite in merlin-server): Custom-Filter werden direkt als rohes XML in
  einer Textarea bearbeitet, Bundle bzw. Bundle+Admin-Referenz daneben
  read-only. `ContentFilterSerializer` (JSON⇄XML für den Builder) wurde
  deshalb bewusst nicht portiert; Validator und Merger laufen unverändert.
  Ein separater `import`-Endpunkt entfällt ebenfalls - eine neue Domain
  entsteht einfach über `update()` mit noch nicht existierender Domain.
- **Leseansicht ohne Vue**: `/library` (Leseliste) und `/articles/{id}`
  (Artikelansicht) sind reine Template-Shells nach dem Vorbild von
  `account.php`/`admin_users.php` - `PageController` liefert nur ein leeres
  Grundgerüst nach Session-Check, sämtliche Daten lädt Vanilla-JS per
  `fetch()` von der bereits bestehenden JSON-API (`ArticleController`,
  `TagController`). Keine neue Backend-Logik nötig. Artikeldaten (Titel,
  Excerpt, Tags, …) werden im Browser ausschließlich über `textContent`/
  DOM-Erzeugung gesetzt, nie per `innerHTML`-Stringkonkatenation - anders als
  bei `admin_users.php` sind das Daten von Drittseiten, keine eigenen
  Account-Daten. Einzige bewusste Ausnahme ist der Artikeltext selbst
  (`article.content` per `innerHTML`), weil `ContentExtractorService::
  sanitizeHtml()` ihn schon serverseitig bereinigt hat - dieselbe
  Vertrauensgrenze wie `v-html` in merlin-nextclouds `ArticleReader.vue`.
  Text-Highlights sind enthalten: `HighlightEngine` aus
  `merlin-nextcloud/src/highlight-engine.js` ist reines Vanilla-JS/DOM (nur
  der Aufrufer dort ist Vue-spezifisch) und wurde 1:1 inline in
  `article_reader.php` portiert (XPath-basierte Ranges, Farb-Toolbar bei
  Textauswahl, Löschen per Klick auf eine Markierung) - Backend
  (`HighlightController`/`HighlightRepository`, Migration) existierte in
  merlin-server bereits unverändert seit v1, nur das Frontend fehlte. Aus der
  Vue-Reader-Vorlage bewusst NICHT übernommen: serverseitig synchronisiertes
  Erscheinungsbild (Dark/Sepia/Schriftart - hier rein clientseitig über
  `prefers-color-scheme` + `localStorage`), Share-Menü.
- **Login-Flow-v2-Klon**: merlin-server kannte Tokens bisher nur, nachdem sich
  ein Nutzer im Browser unter `/account` eingeloggt und dort manuell eins
  erzeugt hat - für native Clients (iOS/Android/Firefox/Chrome/Thunderbird)
  fehlte damit ein Äquivalent zu Nextclouds Login Flow v2. `LoginFlowController`
  (`start()`/`poll()`) + `PageController::loginFlowForm()`/`loginFlowSubmit()`
  bilden dessen JSON-Protokoll exakt nach (`POST /login/v2` → `{login, poll:
  {token, endpoint}}`; `POST /login/v2/poll` → 404 bis abgeschlossen, danach
  `{server, loginName, appPassword}`, Zeile danach gelöscht), damit
  Client-seitiger Login-Flow-Code unverändert bleibt und nur die Start-URL
  je Backend-Typ variiert. `GET/POST /login/v2/flow/{token}` rendert ein
  gewöhnliches Login-Formular (`login_flow.php`) und mintet bei Erfolg über
  das bereits vorhandene `ApiTokenService::create()` ein normales API-Token -
  keine neue Auth-Logik, nur neue Verpackung. Flow-/Poll-Token sind
  Bearer-Secrets in der URL mit 10-Minuten-TTL, Single-Use (Zeile wird beim
  ersten erfolgreichen Poll gelöscht), Cleanup abgelaufener Zeilen läuft lazy
  bei jedem neuen `POST /login/v2` (`LoginFlowRepository::deleteExpired()`,
  gleiches Muster wie `ArticleRepository::clearStuckProcessing()`).
- **Settings-Sync, Public-Share-Links, TTS-Proxy**: alle drei sind Ports der
  jeweiligen merlin-nextcloud-Klassen (`SettingsController`, `ShareController`/
  `PublicShareController`, `TtsController`/`TtsStreamService`) - nur
  Framework-Spezifika (`IConfig`, `QBMapper`/`Entity`, `IThrottler`) sind gegen
  merlin-servers PDO/Array-Muster getauscht, die eigentliche Logik
  (Cast-Tabellen, Sentinel-Update-Pattern, curl-Proxy inkl. `exit()`-Verhalten,
  Plaintext-Extraktion) ist unverändert übernommen. Getrennte Themen, eine
  Migration (`003_share_and_settings.sql`):
  - **Settings-Sync** (`/api/settings`): eigene `user_settings`-Tabelle
    (Key/Value pro Nutzer) statt `IConfig::getUserValue()` - bewusst getrennt
    von der instanzweiten `SettingsRepository` (unterschiedlicher Scope).
  - **Public-Share-Links** (`/api/articles/{id}/share*` authentifiziert,
    `/s/{token}*` öffentlich): `article_shares`-Tabelle mit
    `failed_unlock_attempts`/`last_failed_unlock_at` als Backoff-Ersatz für
    Nextclouds `IThrottler`-Bordmittel (`PublicShareController::unlock()`
    schläft proportional zur Zahl vorheriger Fehlversuche, gedeckelt auf 5s).
    Passwort-Unlock wird pro Browser-Session gemerkt (`SessionService::
    hasUnlockedShareToken()`/`markShareTokenUnlocked()`). Öffentliche Ansicht
    (`public_share.php`) rendert read-only Highlights über eine Teilmenge der
    XPath-Helfer aus `article_reader.php` (kein Erstellen/Löschen, keine
    Mouseup-/Contextmenu-Listener).
  - **TTS-Proxy** (`/api/articles/{id}/tts`, geteilt mit
    `/s/{token}/tts` über dieselbe `TtsStreamService`-Instanz): Daemon-URL
    kommt aus `config.php` (`tts.daemon_url`) statt einer Konstante, damit
    Server und Piper-Daemon nicht zwingend auf derselben Maschine laufen
    müssen. `article_reader.php`/`public_share.php` nutzen dafür bewusst ein
    schlichtes `<audio controls>`-Element statt eines Nachbaus von
    `PiperAudioService`s AVPlayer-Pufferlogik - der Browser übernimmt
    Streaming/Steuerung nativ.
- **HTML-Export**: `ArticleController::exportHtml()` + `Service\ExportService`
  sind ein unveränderter Port aus merlin-nextcloud (`GET
  /api/articles/{id}/export/html`, `Content-Disposition: attachment` über die
  neue `Response::download()`-Hilfsmethode). Verlinkt aus
  `article_reader.php` neben Vorlesen/Teilen.
- **Bewusst nicht nachgebaut** (kein Client braucht sie - siehe Analyse in der
  Konversation, die diese Erweiterung angelegt hat): **SSE** (`/api/events`,
  in merlin-nextcloud selbst nirgends von einem Client aufgerufen - Android
  pollt unabhängig vom Backend-Typ, iOS' `articleUpdateStream()` ist
  totgelegter Code); **YouTube-Embed-Proxy** (iOS hat für Standalone-Backends
  bereits einen gleichwertigen Fallback direkt auf `youtube-nocookie.com`,
  siehe `YouTubePlayerView.swift`); **Pocket-kompatible Extension-API**
  (`/api/v1/add|get|send` - merlin-firefox/-chrome erkennen
  `backendKind === 'standalone'` und nutzen stattdessen den nativen
  `/api/articles`-Endpunkt, siehe `background.js`). RSS-Feeds gibt es
  inzwischen auch in merlin-nextcloud nicht mehr (kein `FeedController`/
  `FeedService` im Code).
