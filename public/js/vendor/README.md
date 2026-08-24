# Vendored: hls.js

Version 1.7.1, minified browser build, vendored from the merlin-nextcloud
repo's `node_modules/hls.js/dist/hls.min.js` (itself installed from npm) so
the standalone server (no JS build step) can load HLS video without a
runtime CDN dependency - keeps the CSP tight (no external script-src needed)
and avoids depending on a third party at request time.

License: Apache-2.0, see hls.js.LICENSE. To update: bump the `hls.js`
dependency in merlin-nextcloud's package.json, `npm install`, then copy
`node_modules/hls.js/dist/hls.min.js` here again.
