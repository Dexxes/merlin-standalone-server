# NOTICE

Merlin Server (standalone read-it-later backend)
Copyright (C) 2026 Julian von Bülow

This program is licensed under the GNU Affero General Public License v3.0
or later (AGPL-3.0-or-later). See the [LICENSE](LICENSE) file for the full
text.

## Third-party dependencies

merlin-server ships no built frontend (no Vue/Vite bundle) - it is server-side
PHP rendering plain templates. All PHP dependencies pulled in via Composer are
permissively licensed and do not require AGPL relicensing of code that merely
uses them:

- [fivefilters/readability.php](https://github.com/fivefilters/readability.php) (Apache-2.0) - content extraction
- [patrickschur/language-detection](https://github.com/patrickschur/language-detection) (MIT) - article language detection
- [psr/log](https://github.com/php-fig/log) (MIT) - PSR-3 logging interface

## Bundled content filters

`content-filters/` contains per-domain extraction rules maintained as part of
this project. Some are informed by, or reference, site configs from the
[FiveFilters ftr-site-config](https://github.com/fivefilters/ftr-site-config)
project (see comments in the individual filter files); the rule format itself
is Merlin-specific and unrelated to that project's file format.

## Speech synthesis

Text-to-speech is provided via an external [Piper TTS](https://github.com/rhasspy/piper)
daemon that merlin-server proxies to; Piper is not bundled with this
repository.
