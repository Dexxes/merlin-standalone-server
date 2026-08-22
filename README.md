# Merlin Standalone Server

A standalone read-it-later backend for Merlin - no Nextcloud installation
required. Plain PHP 8.4, no framework, SQLite storage, and its own account
management (registration, login, password reset, API tokens).

Merlin is a cross-platform read-it-later app. Articles land in a clean,
ad-free reading view via browser extension, mobile apps, or a direct link -
synced across all your devices. This repository (`merlin-server`) is one of
two interchangeable backends; the other is `merlin-nextcloud`, which runs as
a Nextcloud app instead. Both expose a similar REST API but are separate
codebases with no shared data.

## Platforms

| Directory | Platform | Stack |
|---|---|---|
| [`merlin-nextcloud`](https://github.com/Dexxes/merlin-nextcloud) | Nextcloud app (backend + web UI) | PHP 8.0-8.4, Nextcloud 30-35, Vue 3, OCP framework |
| [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server) | Standalone server (backend, no Nextcloud) | PHP 8.4, no framework, PDO/SQLite |
| `merlin-ios` (unreleased) | iOS 18+ | Swift 6, SwiftUI, AVFoundation, SPM |
| `merlin-ipad` (unreleased) | iPadOS 15+ | like iOS (own, lower deployment target) |
| `merlin-android` (unreleased) | Android 6.0+ (minSdk 23, target 34) | Kotlin, Jetpack Compose  |
| `merlin-firefox` (unreleased) | Firefox (Manifest V3) | JS/WebExtension |
| `merlin-chrome` (unreleased) | Chrome/Edge (Manifest V3) | JS/WebExtension |
| [`merlin-thunderbird`](https://github.com/Dexxes/merlin-thunderbird) | Thunderbird 115+ | JS |

## Features

### Core features
- **Save articles**: Add via URL, automatic content extraction (~60 bundled
  content filters, shared with merlin-nextcloud)
- **Distraction-free reading**: Text and images; no ads, no clutter
- **Tags & organization**: Categorize and filter articles
- **Full-text search**: Across title, content, and author
- **Favorites** and **archive**
- **Text highlights**: Select text in the reading view, highlight it in a
  choice of colors, remove it again with a click

### Accounts & access
- **Self-contained accounts**: Registration, login, password reset - no
  external identity provider needed
- **API tokens**: Per-client tokens for native apps and browser extensions,
  managed from the account page
- **Login Flow v2 clone**: Native clients (iOS/Android/browser extensions/
  Thunderbird) can obtain a token via an in-browser login instead of typing
  credentials into the app
- **Admin area**: User management and instance-wide settings

### Content filters
Per-domain extraction rules in three layers - bundled, instance-wide admin
overrides, and private per-user overrides - merged at request time. Edited
as raw XML (no visual rule builder, unlike merlin-nextcloud's Vue-based one).

### Sharing & export
- **Public share links**, optionally password-protected
- **HTML export** of a saved article

### Text-to-speech (TTS)
Articles can be read aloud via a local Piper TTS pipeline. The server
extracts the plain text and streams the synthesized audio directly to the
client (browser `<audio>` element or native app).

## Requirements

PHP 8.4 with `pdo_sqlite`, `curl`, `dom`, `mbstring`; Composer; a local mail
transport (`sendmail`/Postfix) for password-reset mails; a webserver (Apache
with `mod_rewrite`, or nginx) with `public/` as document root.

Full setup steps (dependencies, configuration, database migrations, admin
account, webserver config) are in **[INSTALLATION.md](INSTALLATION.md)**,
architecture and design decisions in **[STRUCTURE.md](STRUCTURE.md)**.

## Credits

- Content extraction with [fivefilters/readability.php](https://github.com/fivefilters/readability.php)
- Language detection with [patrickschur/language-detection](https://github.com/patrickschur/language-detection)
- Speech synthesis with [Piper TTS](https://github.com/rhasspy/piper)

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md).