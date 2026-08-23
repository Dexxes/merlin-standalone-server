# Changelog

All notable changes to merlin-server are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning based on
[SemVer](https://semver.org/).

## v1.0

### Added
- Standalone read-it-later server: no Nextcloud dependency, SQLite storage,
  own account management (registration, login, password reset, admin/user
  roles)
- Save articles via URL with automatic content extraction (same filter
  bundle as merlin-nextcloud, ~60 domains)
- Distraction-free reading view with tags, favorites, archive, full-text
  search, and text highlights
- Content-filter management: instance-wide admin overrides and private
  per-user overrides on top of the bundled filters, edited as raw XML
- Login Flow v2 clone for native clients (iOS/Android/browser extensions/
  Thunderbird) to obtain an API token without a browser-based OAuth dance
- Settings sync, public share links (optional password protection), and
  HTML export, ported from merlin-nextcloud
- Text-to-speech (TTS) via a local Piper pipeline, proxied per-request to a
  configurable daemon URL
- Paywall subscription login (e.g. Tagesspiegel Plus): encrypted per-user
  credentials, automatic login and session-cookie injection when fetching
  articles, plus a "Paywall-Abos" section on the account page to
  connect/disconnect a site login
- Localization (German/English): all HTML templates and the user-facing
  messages of the content-filter and paywall-subscription JSON APIs are now
  translated via a new `Merlin\I18n\Translator`, driven by the project's
  central `localization/strings/{en,de}.json` source of truth (new
  `merlinServer.*` namespace, exported to `src/I18n/lang/{en,de}.php` by
  `tools/i18n/export.py --platform merlin-server`). Language is resolved per
  request (logged-in user's saved preference > session > `Accept-Language`
  header > German default) and can be switched explicitly via a link in the
  page footer.