<?php
$title = $t->t('articleReader.pageTitle');
$layout = 'reader';
include __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/icons.php';
?>

<div id="reader-progress-bar" class="reader-progress-bar" style="display:none;"></div>

<div class="reader-dock" id="toolbar" style="display:none;">
    <a href="<?= url('/library'); ?>" class="dock-btn" title="<?= htmlspecialchars($t->t('articleReader.backToLibrary'), ENT_QUOTES, 'UTF-8') ?>"><?= icon('arrow-left') ?></a>

    <div class="dock-divider"></div>

    <button type="button" id="btn-archive" class="dock-btn" title="<?= htmlspecialchars($t->t('articleReader.archive'), ENT_QUOTES, 'UTF-8') ?>"><?= icon('archive') ?></button>
    <button type="button" id="btn-favorite" class="dock-btn" title="<?= htmlspecialchars($t->t('articleReader.addFavorite'), ENT_QUOTES, 'UTF-8') ?>"><?= icon('star') ?></button>

    <div class="dock-item">
        <details id="share-details">
            <summary class="dock-btn" title="<?= htmlspecialchars($t->t('articleReader.share'), ENT_QUOTES, 'UTF-8') ?>"><?= icon('share') ?></summary>
            <div id="share-popover-panel" class="dock-menu">
                <p id="share-status" class="muted"><?= htmlspecialchars($t->t('articleReader.loading'), ENT_QUOTES, 'UTF-8') ?></p>
                <div id="share-link-row" style="display:none;">
                    <input type="text" id="share-link-input" readonly>
                    <button type="button" id="share-copy-btn"><?= htmlspecialchars($t->t('articleReader.copy'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
                <div id="share-manage" style="display:none;">
                    <form id="share-password-form">
                        <input type="password" id="share-password-input" placeholder="<?= htmlspecialchars($t->t('articleReader.passwordNoProtectionPlaceholder'), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit"><?= htmlspecialchars($t->t('articleReader.set'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                    <form id="share-expiry-form">
                        <input type="date" id="share-expiry-input">
                        <button type="submit"><?= htmlspecialchars($t->t('articleReader.set'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" id="share-expiry-clear">✕</button>
                    </form>
                    <div class="share-actions-row">
                        <button type="button" id="share-regenerate-btn"><?= htmlspecialchars($t->t('articleReader.regenerate'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" id="share-revoke-btn"><?= htmlspecialchars($t->t('articleReader.revoke'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </div>
                <button type="button" id="share-create-btn" style="display:none;"><?= htmlspecialchars($t->t('articleReader.createLink'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </details>
    </div>

    <div class="dock-divider"></div>

    <div class="dock-item">
        <details id="more-details">
            <summary class="dock-btn" title="<?= htmlspecialchars($t->t('articleReader.more'), ENT_QUOTES, 'UTF-8') ?>"><?= icon('more-horizontal') ?></summary>
            <div class="dock-menu dock-menu--list">
                <button type="button" id="btn-read" class="dock-menu-item"><?= icon('check-circle') ?><span id="btn-read-label"></span></button>
                <button type="button" id="btn-open-tags" class="dock-menu-item"><?= icon('tag') ?><span><?= htmlspecialchars($t->t('articleReader.tags'), ENT_QUOTES, 'UTF-8') ?></span></button>
                <button type="button" id="btn-font" class="dock-menu-item"><?= icon('type') ?><span><?= htmlspecialchars($t->t('articleReader.changeFontSize'), ENT_QUOTES, 'UTF-8') ?></span></button>
                <button type="button" id="btn-tts" class="dock-menu-item"><?= icon('volume') ?><span><?= htmlspecialchars($t->t('articleReader.listen'), ENT_QUOTES, 'UTF-8') ?></span></button>
                <a href="<?= url('/api/articles/' . (int) $articleId . '/export/html') ?>" class="dock-menu-item"><?= icon('download') ?><span><?= htmlspecialchars($t->t('articleReader.export'), ENT_QUOTES, 'UTF-8') ?></span></a>
                <button type="button" id="btn-delete" class="dock-menu-item dock-menu-item--danger"><?= icon('trash') ?><span><?= htmlspecialchars($t->t('articleReader.delete'), ENT_QUOTES, 'UTF-8') ?></span></button>
            </div>
        </details>
    </div>

    <!-- Eigenständiges details-Element ohne sichtbaren Dock-Button - wird per
         Klick auf "Tags" im Mehr-Menü programmatisch geöffnet und teilt sich
         so den Dock-Anker (siehe ArticleReader.vue: "shares the desktop dock anchor"). -->
    <div class="dock-item dock-item--hidden-trigger">
        <details id="tag-details">
            <summary class="dock-visually-hidden"><?= htmlspecialchars($t->t('articleReader.tags'), ENT_QUOTES, 'UTF-8') ?></summary>
            <div id="tag-popover-panel" class="dock-menu">
                <div id="tag-checkboxes"></div>
                <div class="new-tag-row">
                    <input type="text" id="new-tag-name" placeholder="<?= htmlspecialchars($t->t('articleReader.newTagPlaceholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="color" id="new-tag-color" value="#0082c9">
                    <button type="button" id="new-tag-submit">+</button>
                </div>
            </div>
        </details>
    </div>
</div>
<audio id="tts-audio" controls></audio>
<p class="muted" id="highlight-hint" style="display:none;font-size:0.82em;margin-top:0.4em;"><?= htmlspecialchars($t->t('articleReader.highlightHint'), ENT_QUOTES, 'UTF-8') ?></p>

<p id="reader-status"><?= htmlspecialchars($t->t('articleReader.loading'), ENT_QUOTES, 'UTF-8') ?></p>

<article id="article-root" style="display:none;">
    <h1 id="a-title"></h1>
    <p id="a-excerpt" class="article-excerpt" style="display:none;"></p>
    <div id="a-meta" class="article-meta"></div>
    <div id="a-tags" class="article-tags"></div>
    <div id="video-player" data-hl-exclude style="display:none;">
        <video id="video-player-el" controls playsinline></video>
        <select id="video-player-variant" class="video-player-variant" style="display:none;"></select>
    </div>
    <div id="article-body"></div>
</article>

<!-- Lokal vendored (kein Laufzeit-CDN), siehe public/js/vendor/README.md -->
<script src="<?= url('/js/vendor/hls.min.js') ?>"></script>
<script>
const I18N = <?= json_encode($t->forJs([
    'articleReader.removeHighlight',
    'articleReader.untitledArticle',
    'articleReader.minutesShort',
    'articleReader.markAsUnread',
    'articleReader.markAsRead',
    'articleReader.removeFavorite',
    'articleReader.addFavorite',
    'articleReader.restore',
    'articleReader.archive',
    'articleReader.confirmDeleteArticle',
    'articleReader.noTagsYet',
    'articleReader.passwordChangePlaceholder',
    'articleReader.passwordNoProtectionPlaceholder',
    'articleReader.confirmRevokeLink',
    'articleReader.notFoundOrForbidden',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const articleId = <?= (int) $articleId ?>;
const FONT_SIZE_STEPS = [15, 17, 19, 21, 24];
const FONT_SIZE_KEY = 'merlin_reader_font_size';
let article = null;
let allTags = [];
let highlightEngine = null;
let userSettings = {};

// ── Highlights ─────────────────────────────────────────────────────────
// Portiert aus merlin-nextcloud/src/highlight-engine.js - die Engine ist
// bereits reines Vanilla-JS/DOM ohne Vue-Abhängigkeit (nur der Aufrufer,
// ArticleReader.vue, ist Vue-spezifisch), lässt sich also fast unverändert
// übernehmen. Backend-Endpunkte (HighlightController/HighlightRepository)
// existieren in merlin-server bereits unverändert.

const HIGHLIGHT_COLORS = [
    { id: 'yellow', hex: '#fde68a' },
    { id: 'green',  hex: '#bbf7d0' },
    { id: 'blue',   hex: '#bfdbfe' },
    { id: 'pink',   hex: '#fbcfe8' },
    { id: 'orange', hex: '#fed7aa' },
];

if (!document.getElementById('merlin-hl-style')) {
    const hlStyle = document.createElement('style');
    hlStyle.id = 'merlin-hl-style';
    hlStyle.textContent = `
        @keyframes merlinHlMenuIn {
            from { opacity:0; transform:scale(0.92) translateY(-4px); }
            to   { opacity:1; transform:scale(1)    translateY(0); }
        }
        mark.merlin-highlight {
            border-radius: 2px !important;
            padding: 0 1px !important;
            cursor: pointer !important;
            box-decoration-break: clone !important;
            -webkit-box-decoration-break: clone !important;
            display: inline !important;
        }
        mark.merlin-highlight[data-highlight-color="yellow"] { background-color: #fde68a !important; color: #1c1c1e !important; }
        mark.merlin-highlight[data-highlight-color="green"]  { background-color: #bbf7d0 !important; color: #1c1c1e !important; }
        mark.merlin-highlight[data-highlight-color="blue"]   { background-color: #bfdbfe !important; color: #1c1c1e !important; }
        mark.merlin-highlight[data-highlight-color="pink"]   { background-color: #fbcfe8 !important; color: #1c1c1e !important; }
        mark.merlin-highlight[data-highlight-color="orange"] { background-color: #fed7aa !important; color: #1c1c1e !important; }
    `;
    document.head.appendChild(hlStyle);
}

// data-hl-exclude: siehe #video-player weiter unten - setupVideoPlayer()
// verschiebt dieses Element bei nativ abspielbaren Artikeln (ARD/ZDF/Arte)
// live in #article-body hinein (direkt hinter das Hero-Bild), obwohl es nicht
// Teil des rohen article.content ist, gegen den XPaths plattformübergreifend
// (iOS/nextcloud) berechnet werden. Ohne diesen Ausschluss würde es bei
// Content mit Top-Level-<div>-Blöcken (z. B. .merlin-infobox) hinter dem
// Hero-Bild die div[n]-Zählung verschieben - siehe merlin-nextclouds
// highlight-engine.js für dieselbe Problemklasse (dort per data-hl-flatten/
// data-hl-exclude gelöst, hier reicht ein reiner Ausschluss, da es keinen
// Hero/Rest-Split gibt).
function isExcluded(el) {
    return el.nodeType === Node.ELEMENT_NODE && el.hasAttribute('data-hl-exclude');
}

function getXPathForNode(node, root) {
    if (node === root) return '.';
    const parts = [];
    let current = node;
    while (current && current !== root) {
        if (current.nodeType === Node.TEXT_NODE) {
            let index = 0;
            let sib = current.previousSibling;
            while (sib) {
                if (sib.nodeType === Node.TEXT_NODE) index++;
                sib = sib.previousSibling;
            }
            parts.unshift('text()[' + (index + 1) + ']');
        } else {
            const tag = current.nodeName.toLowerCase();
            let index = 1;
            let sib = current.previousElementSibling;
            while (sib) {
                if (sib.nodeName.toLowerCase() === tag && !isExcluded(sib)) index++;
                sib = sib.previousElementSibling;
            }
            parts.unshift(tag + '[' + index + ']');
        }
        current = current.parentNode;
    }
    if (!current) return null;
    return parts.join('/');
}

function resolveXPath(xpath, root) {
    if (xpath === '.') return root;
    const parts = xpath.split('/');
    let node = root;
    for (const part of parts) {
        if (!node) return null;
        const textMatch = part.match(/^text\(\)\[(\d+)\]$/);
        if (textMatch) {
            const targetIdx = parseInt(textMatch[1], 10) - 1;
            let count = 0;
            let found = null;
            for (const child of node.childNodes) {
                if (child.nodeType === Node.TEXT_NODE) {
                    if (count === targetIdx) { found = child; break; }
                    count++;
                }
            }
            node = found;
        } else {
            const elemMatch = part.match(/^([a-z0-9]+)\[(\d+)\]$/i);
            if (!elemMatch) return null;
            const tag = elemMatch[1].toLowerCase();
            const idx = parseInt(elemMatch[2], 10) - 1;
            let count = 0;
            let found = null;
            for (const child of node.children) {
                if (child.nodeName.toLowerCase() === tag && !isExcluded(child)) {
                    if (count === idx) { found = child; break; }
                    count++;
                }
            }
            node = found;
        }
    }
    return node || null;
}

function createMarkEl(color, highlightId) {
    const mark = document.createElement('mark');
    mark.className = 'merlin-highlight';
    mark.dataset.highlightId = String(highlightId);
    mark.dataset.highlightColor = color;
    mark.style.backgroundColor = (HIGHLIGHT_COLORS.find(c => c.id === color) || {}).hex || '#fde68a';
    mark.style.color = '#1c1c1e';
    return mark;
}

function wrapRange(range, color, highlightId) {
    const marks = [];
    if (range.collapsed) return marks;

    const root = range.commonAncestorContainer.nodeType === Node.TEXT_NODE
        ? range.commonAncestorContainer.parentNode
        : range.commonAncestorContainer;

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    let n;
    while ((n = walker.nextNode())) {
        if (range.intersectsNode(n)) textNodes.push(n);
    }

    for (let i = 0; i < textNodes.length; i++) {
        let tn = textNodes[i];
        const isFirst = i === 0;
        const isLast = i === textNodes.length - 1;

        const startOff = isFirst && tn === range.startContainer ? range.startOffset : 0;
        const endOff = isLast && tn === range.endContainer ? range.endOffset : tn.length;

        if (startOff >= endOff) continue;

        if (endOff < tn.length) tn.splitText(endOff);
        const slice = startOff > 0 ? tn.splitText(startOff) : tn;

        const mark = createMarkEl(color, highlightId);
        slice.parentNode.insertBefore(mark, slice);
        mark.appendChild(slice);
        marks.push(mark);
    }

    return marks;
}

class HighlightEngine {
    constructor(container, { onCreate, onDelete }) {
        this._container = container;
        this._onCreate = onCreate;
        this._onDelete = onDelete;

        this._toolbar = null;
        this._pendingRange = null;

        this._onMouseUp = this._handleMouseUp.bind(this);
        this._onContextMenu = this._handleContextMenu.bind(this);
        this._onDocMouseDown = this._handleDocMouseDown.bind(this);

        document.addEventListener('mouseup', this._onMouseUp);
        container.addEventListener('contextmenu', this._onContextMenu);
        document.addEventListener('mousedown', this._onDocMouseDown, true);
    }

    destroy() {
        document.removeEventListener('mouseup', this._onMouseUp);
        this._container.removeEventListener('contextmenu', this._onContextMenu);
        document.removeEventListener('mousedown', this._onDocMouseDown, true);
        this._removeToolbar();
    }

    applyHighlights(highlights) {
        for (const h of highlights) {
            this._restoreHighlight(h);
        }
    }

    updateTempId(tempId, realId) {
        document.querySelectorAll('mark.merlin-highlight[data-highlight-id="' + tempId + '"]')
            .forEach(el => { el.dataset.highlightId = String(realId); });
    }

    _handleMouseUp(e) {
        if (this._toolbar && this._toolbar.contains(e.target)) return;
        if (e.button !== 0) return;

        const clickedMark = e.target.closest && e.target.closest('mark.merlin-highlight');
        if (clickedMark) {
            this._removeToolbar();
            this._showDeleteMenu(e.clientX, e.clientY, parseInt(clickedMark.dataset.highlightId, 10));
            return;
        }

        const sel = window.getSelection();
        if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!this._container.contains(range.commonAncestorContainer)) return;

        const cloned = range.cloneRange();
        this._showColorToolbar(range);
        this._pendingRange = cloned;
    }

    _handleContextMenu(e) {
        const clickedMark = e.target.closest && e.target.closest('mark.merlin-highlight');
        if (clickedMark) {
            e.preventDefault();
            this._removeToolbar();
            this._showDeleteMenu(e.clientX, e.clientY, parseInt(clickedMark.dataset.highlightId, 10));
        }
    }

    _handleDocMouseDown(e) {
        if (this._toolbar && !this._toolbar.contains(e.target)) {
            this._removeToolbar();
        }
    }

    _showColorToolbar(range) {
        this._removeToolbar();

        const toolbar = document.createElement('div');
        toolbar.className = 'merlin-highlight-toolbar';
        toolbar.style.cssText =
            'position: fixed; display: flex; align-items: center; gap: 4px; padding: 5px 8px;' +
            'background: #fff; border: 1px solid #e0e0e0; border-radius: 20px;' +
            'box-shadow: 0 2px 12px rgba(0,0,0,.18); z-index: 99999;' +
            'animation: merlinHlMenuIn .12s ease; pointer-events: all;';

        for (const color of HIGHLIGHT_COLORS) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.title = color.id;
            btn.style.cssText =
                'width: 22px; height: 22px; border-radius: 50%; border: 2px solid transparent;' +
                'background: ' + color.hex + '; cursor: pointer; padding: 0; flex-shrink: 0;' +
                'transition: transform .1s, border-color .1s;';
            btn.addEventListener('mouseenter', () => { btn.style.transform = 'scale(1.25)'; btn.style.borderColor = '#666'; });
            btn.addEventListener('mouseleave', () => { btn.style.transform = ''; btn.style.borderColor = 'transparent'; });
            btn.addEventListener('mousedown', (ev) => { ev.preventDefault(); ev.stopPropagation(); });
            btn.addEventListener('click', (ev) => { ev.stopPropagation(); this._createHighlight(color.id); });
            toolbar.appendChild(btn);
        }

        document.body.appendChild(toolbar);
        this._toolbar = toolbar;
        this._positionToolbar(toolbar, range);
    }

    _positionToolbar(toolbar, range) {
        const rect = range.getBoundingClientRect();
        const tbRect = toolbar.getBoundingClientRect();

        let left = rect.left + (rect.width / 2) - (tbRect.width / 2);
        let top = rect.top - tbRect.height - 8;
        if (top < 8) top = rect.bottom + 8;
        left = Math.max(8, Math.min(left, window.innerWidth - tbRect.width - 8));

        toolbar.style.left = left + 'px';
        toolbar.style.top = top + 'px';
    }

    _showDeleteMenu(x, y, highlightId) {
        this._removeToolbar();

        const menu = document.createElement('div');
        menu.className = 'merlin-highlight-toolbar';
        menu.style.cssText =
            'position: fixed; left: ' + x + 'px; top: ' + y + 'px;' +
            'background: #fff; border: 1px solid #e0e0e0; border-radius: 10px;' +
            'box-shadow: 0 4px 16px rgba(0,0,0,.15); z-index: 99999; overflow: hidden;' +
            'animation: merlinHlMenuIn .1s ease;';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = I18N['articleReader.removeHighlight'];
        btn.style.cssText =
            'display: block; width: 100%; padding: 8px 14px; border: none; background: none;' +
            'cursor: pointer; text-align: left; font-size: 14px; color: #c00; white-space: nowrap;';
        btn.addEventListener('mouseenter', () => { btn.style.background = '#fee2e2'; });
        btn.addEventListener('mouseleave', () => { btn.style.background = 'none'; });
        btn.addEventListener('click', () => {
            this._onDelete(highlightId);
            document.querySelectorAll('mark.merlin-highlight[data-highlight-id="' + highlightId + '"]')
                .forEach(el => {
                    const parent = el.parentNode;
                    while (el.firstChild) parent.insertBefore(el.firstChild, el);
                    parent.removeChild(el);
                    parent.normalize();
                });
            this._removeToolbar();
        });

        menu.appendChild(btn);
        document.body.appendChild(menu);
        this._toolbar = menu;

        requestAnimationFrame(() => {
            const r = menu.getBoundingClientRect();
            if (r.right > window.innerWidth - 8) menu.style.left = (x - r.width) + 'px';
            if (r.bottom > window.innerHeight - 8) menu.style.top = (y - r.height) + 'px';
        });
    }

    _removeToolbar() {
        if (this._toolbar) {
            this._toolbar.remove();
            this._toolbar = null;
        }
        this._pendingRange = null;
    }

    _createHighlight(color) {
        const range = this._pendingRange;
        this._removeToolbar();
        if (!range || range.collapsed) return;

        const startXpath = getXPathForNode(range.startContainer, this._container);
        const endXpath = getXPathForNode(range.endContainer, this._container);
        if (!startXpath || !endXpath) return;

        const highlightedText = range.toString().trim();
        if (!highlightedText) return;

        const startOffset = range.startOffset;
        const endOffset = range.endOffset;

        const tempId = Date.now();
        wrapRange(range, color, tempId);
        const sel = window.getSelection();
        if (sel) sel.removeAllRanges();

        this._onCreate({ highlightedText, startXpath, startOffset, endXpath, endOffset, color, tempId });
    }

    _restoreHighlight(h) {
        const startNode = resolveXPath(h.startXpath, this._container);
        const endNode = resolveXPath(h.endXpath, this._container);
        if (!startNode || !endNode) return;

        try {
            const range = document.createRange();
            range.setStart(startNode, h.startOffset);
            range.setEnd(endNode, h.endOffset);
            if (!range.collapsed) {
                wrapRange(range, h.color, h.id);
            }
        } catch (err) {
            // Stale highlight (Artikeltext hat sich geändert) - überspringen
        }
    }
}

async function initHighlights() {
    const container = document.getElementById('article-body');
    highlightEngine = new HighlightEngine(container, {
        onCreate: async ({ highlightedText, startXpath, startOffset, endXpath, endOffset, color, tempId }) => {
            try {
                const res = await fetch(basePath + '/api/articles/' + articleId + '/highlights', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ highlightedText, startXpath, startOffset, endXpath, endOffset, color }),
                });
                if (res.ok) {
                    const saved = await res.json();
                    highlightEngine.updateTempId(tempId, saved.id);
                }
            } catch (err) {
                console.error('Failed to save highlight:', err);
            }
        },
        onDelete: async (highlightId) => {
            try {
                await fetch(basePath + '/api/highlights/' + highlightId, { method: 'DELETE', credentials: 'same-origin' });
            } catch (err) {
                console.error('Failed to delete highlight:', err);
            }
        },
    });

    try {
        const res = await fetch(basePath + '/api/articles/' + articleId + '/highlights', { credentials: 'same-origin' });
        if (res.ok) {
            highlightEngine.applyHighlights(await res.json());
        }
    } catch (err) {
        console.error('Failed to load highlights:', err);
    }
}

// Portiert aus ArticleReader.vue (sanitizeHref): lässt nur http(s)-Schemata
// als href zu, damit ein javascript:/data:-Schema (z.B. bei fehlgeschlagener
// Extraktion in article.url verblieben) nicht per Klick ausgeführt wird.
function sanitizeHref(url) {
    if (typeof url !== 'string') return null;
    const normalized = url.replace(/[\x00-\x20]+/g, '').toLowerCase();
    if (normalized.startsWith('javascript:') || normalized.startsWith('vbscript:') || normalized.startsWith('data:')) {
        return null;
    }
    return url;
}

function contrastColor(hex) {
    if (typeof hex !== 'string') return '#fff';
    const normalized = hex.replace('#', '');
    const full = normalized.length === 3 ? normalized.split('').map(c => c + c).join('') : normalized;
    if (!/^[0-9a-f]{6}$/i.test(full)) return '#fff';
    const r = parseInt(full.substring(0, 2), 16);
    const g = parseInt(full.substring(2, 4), 16);
    const b = parseInt(full.substring(4, 6), 16);
    const luma = (r * 299 + g * 587 + b * 114) / 1000;
    return luma > 170 ? '#1d1d1f' : '#fff';
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('default', { year: '2-digit', month: '2-digit', day: '2-digit' }).format(date);
}

function applyFontSize() {
    const size = parseInt(localStorage.getItem(FONT_SIZE_KEY), 10) || FONT_SIZE_STEPS[1];
    document.getElementById('article-body').style.fontSize = size + 'px';
}

// Über innerHTML eingefügte <script>-Tags werden vom Browser NIE ausgeführt
// (Standardverhalten, unabhängig vom Framework - dasselbe gilt für v-html in
// merlin-nextclouds ArticleReader.vue). Der Sanitizer lässt aber genau zwei
// <script>-Tags durch (isAllowedWidgetScriptSrc() im Backend: Instagrams
// embed.js, X' widgets.js), die das zugehörige <blockquote> erst zum
// Post/Reel rendern - ohne diesen Schritt bliebe für immer nur der
// Zitat-Fallback stehen. Jedes gefundene <script> wird deshalb durch eine
// neu erzeugte Kopie ersetzt; nur DAS bringt den Browser dazu, es
// auszuführen.
// Native ARD-/ZDF-/Arte-Wiedergabe über deren interne Stream-APIs, siehe
// VideoStreamResolverService-Docblock (Backend) für den Hintergrund - das
// ist bewusst kein offizieller Embed-Weg. Bleibt bei jedem Fehlschlag
// (nicht auflösbar, Netzwerkfehler, Wiedergabefehler) einfach versteckt;
// der Artikeltext darunter ist davon nie betroffen.
const NATIVE_VIDEO_HOSTS = ['ardmediathek.de', 'zdf.de', 'arte.tv'];
let activeHls = null;
let currentVariants = [];

function hasNativeVideoHost(articleUrl) {
    let host;
    try {
        host = new URL(articleUrl).hostname.toLowerCase();
    } catch {
        return false;
    }
    return NATIVE_VIDEO_HOSTS.some(domain => host === domain || host.endsWith('.' + domain));
}

// #video-player lebt statisch als Geschwister VOR #article-body im Template
// (siehe article-root oben), NICHT als dessen Kind - würde es dort drinstehen,
// risse das nächste "article-body.innerHTML = ..." (neuer Artikel) es beim
// Reset mit weg, und getElementById('video-player') liefe danach ins Leere.
// resetVideoPlayerPosition() garantiert deshalb VOR jedem innerHTML-Reset
// diese sichere Ausgangsposition; positionVideoPlayer() darf ihn danach
// beliebig innerhalb von #article-body verschieben (siehe renderArticle()).
function resetVideoPlayerPosition() {
    const player = document.getElementById('video-player');
    const body = document.getElementById('article-body');
    if (player.nextElementSibling !== body) {
        body.parentElement.insertBefore(player, body);
    }
}

// Das Hero-Bild (siehe ContentExtractorService Step 12, .merlin-hero-image)
// wird redundant, sobald es als Video-Poster dient statt separat über dem
// Player zu stehen - siehe positionVideoPlayer()/teardownVideoPlayer().
function setHeroImageVisible(visible) {
    const hero = document.querySelector('#article-body > .merlin-hero-image');
    if (hero) hero.style.display = visible ? '' : 'none';
}

// Platziert den Player direkt hinter dem Hero-Bild (siehe
// ContentExtractorService Step 12, .merlin-hero-image), falls vorhanden -
// sonst bleibt er an seiner statischen Position vor #article-body. Das
// Hero-Bild selbst wird dabei zum Video-Poster statt zusätzlich separat
// angezeigt zu werden.
function positionVideoPlayer() {
    const player = document.getElementById('video-player');
    const video = document.getElementById('video-player-el');
    const body = document.getElementById('article-body');
    const hero = body.firstElementChild;
    if (hero && hero.classList.contains('merlin-hero-image')) {
        video.poster = hero.querySelector('img')?.src || '';
        setHeroImageVisible(false);
        hero.insertAdjacentElement('afterend', player);
    } else {
        video.poster = '';
        body.parentElement.insertBefore(player, body);
    }
}

// Der "Zum Video"-Fallback-Link (siehe ContentExtractorService, Video-Zweig)
// wird redundant, sobald der native Player erfolgreich lädt.
function setFallbackLinkVisible(visible) {
    document.querySelectorAll('#article-body .merlin-video-fallback-link').forEach(el => {
        el.style.display = visible ? '' : 'none';
    });
}

function teardownVideoPlayer() {
    if (activeHls) {
        activeHls.destroy();
        activeHls = null;
    }
    document.getElementById('video-player').style.display = 'none';
    document.getElementById('video-player-variant').style.display = 'none';
    document.getElementById('video-player-el').poster = '';
    currentVariants = [];
    setFallbackLinkVisible(true);
    setHeroImageVisible(true);
}

// Baut die Dropdown-Optionen aus den vom Backend gelieferten Varianten (z. B.
// Standard vs. Gebärdensprache/Audiodeskription bei ARD/ZDF) - nur sichtbar,
// wenn es tatsächlich mehr als eine gibt, sonst wäre die Auswahl bedeutungslos.
function populateVideoVariantSelect(variants, selectedIndex) {
    const select = document.getElementById('video-player-variant');
    select.innerHTML = '';
    if (variants.length <= 1) {
        select.style.display = 'none';
        return;
    }
    variants.forEach((variant, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        option.textContent = variant.label;
        select.appendChild(option);
    });
    select.value = String(selectedIndex);
    select.style.display = 'block';
}

// Pendant zur hls.subtitleTrack-Steuerung in attachVideoStream() unten,
// für den Safari-Zweig ohne hls.js: hier gibt es keinen
// SubtitleTrackController, der direkte Änderungen an video.textTracks
// überschreiben könnte, also reicht das Setzen von .mode direkt.
function enforceNativeSubtitleLanguage(video, subtitleLanguage) {
    if (subtitleLanguage === undefined) return;
    const apply = () => {
        for (let i = 0; i < video.textTracks.length; i++) {
            const track = video.textTracks[i];
            if (track.kind !== 'subtitles' && track.kind !== 'captions') continue;
            track.mode = subtitleLanguage && track.language === subtitleLanguage ? 'showing' : 'disabled';
        }
    };
    apply();
    video.textTracks.addEventListener('addtrack', apply);
}

function attachVideoStream(variant, { resumeAt = 0, autoplay = false } = {}) {
    const video = document.getElementById('video-player-el');
    const streamUrl = variant.url;

    const seekAndPlay = () => {
        if (resumeAt > 0) video.currentTime = resumeAt;
        if (autoplay) video.play().catch(() => {});
    };

    // Safari unterstützt HLS nativ über <video src>, alle anderen gängigen
    // Browser brauchen hls.js (MediaSource-basiert).
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = streamUrl;
        if (resumeAt > 0 || autoplay) {
            video.addEventListener('loadedmetadata', seekAndPlay, { once: true });
        }
        // Kein hls.js hier (natives Safari-HLS) - die Untertitelspur direkt
        // am <video>-Element erzwingen, siehe enforceNativeSubtitleLanguage().
        enforceNativeSubtitleLanguage(video, variant.subtitleLanguage);
        return;
    }

    if (typeof Hls === 'undefined' || !Hls.isSupported()) {
        teardownVideoPlayer();
        return;
    }

    const hls = new Hls();
    activeHls = hls;
    hls.on(Hls.Events.ERROR, (event, errData) => {
        if (errData.fatal) teardownVideoPlayer();
    });
    if (resumeAt > 0 || autoplay) {
        hls.on(Hls.Events.MANIFEST_PARSED, seekAndPlay);
    }
    // Jedes Arte-Versions-Manifest bettet trotzdem mehrere Untertitel-Spuren
    // ein statt nur die zur gewählten Version passende - hls.js wählt sonst
    // selbstständig eine davon (u. a. nach Systemsprache), unabhängig von
    // der im Dropdown gewählten Version. Über hls.js' eigene
    // subtitleTrack-API statt direkt am <video>-Element setzen, da hls.js'
    // SubtitleTrackController eine direkte DOM-Manipulation sonst wieder
    // überschreiben würde. "und"/kein Wert (siehe
    // VideoStreamResolverService::resolveArte()) bedeutet "keine Untertitel
    // für diese Version" - bei ARD/ZDF fehlt das Feld (undefined) und hier
    // passiert bewusst nichts, um deren bisheriges Verhalten nicht zu
    // verändern.
    if (variant.subtitleLanguage !== undefined) {
        hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => {
            const match = hls.subtitleTracks.findIndex(track => track.lang === variant.subtitleLanguage);
            hls.subtitleTrack = match;
        });
    }
    hls.loadSource(streamUrl);
    hls.attachMedia(video);

    video.addEventListener('error', teardownVideoPlayer, { once: true });
}

function selectVideoVariant(index) {
    const variant = currentVariants[index];
    if (!variant) return;

    // Abspielposition beim Varianten-Wechsel beibehalten (z. B. von
    // Gebärdensprache auf Normal mitten im Video umschalten), statt wieder
    // bei 0 zu beginnen.
    const video = document.getElementById('video-player-el');
    const resumeAt = video.currentTime || 0;
    const wasPlaying = !video.paused;

    if (activeHls) {
        activeHls.destroy();
        activeHls = null;
    }
    attachVideoStream(variant, { resumeAt, autoplay: wasPlaying });
}

document.getElementById('video-player-variant').addEventListener('change', event => {
    selectVideoVariant(Number(event.target.value));
});

async function setupVideoPlayer(articleId, articleUrl) {
    teardownVideoPlayer();
    if (!hasNativeVideoHost(articleUrl)) return;

    let data;
    try {
        const res = await fetch(basePath + '/api/articles/' + articleId + '/video-stream', { credentials: 'same-origin' });
        data = await res.json();
    } catch {
        return;
    }
    if (!data?.available || data.type !== 'hls' || !Array.isArray(data.variants) || data.variants.length === 0) return;

    currentVariants = data.variants;
    const selectedIndex = Number.isInteger(data.defaultIndex) && data.variants[data.defaultIndex] ? data.defaultIndex : 0;

    positionVideoPlayer();
    document.getElementById('video-player').style.display = 'block';
    setFallbackLinkVisible(false);
    populateVideoVariantSelect(currentVariants, selectedIndex);

    attachVideoStream(currentVariants[selectedIndex]);
}

function executeEmbedScripts() {
    document.getElementById('article-body').querySelectorAll('script').forEach(oldScript => {
        const newScript = document.createElement('script');
        for (const attr of oldScript.attributes) {
            newScript.setAttribute(attr.name, attr.value);
        }
        oldScript.replaceWith(newScript);
    });
}

document.getElementById('btn-font').addEventListener('click', () => {
    const current = parseInt(localStorage.getItem(FONT_SIZE_KEY), 10) || FONT_SIZE_STEPS[1];
    const idx = FONT_SIZE_STEPS.indexOf(current);
    const next = FONT_SIZE_STEPS[(idx + 1) % FONT_SIZE_STEPS.length];
    localStorage.setItem(FONT_SIZE_KEY, String(next));
    applyFontSize();
});

// ── Rendering: Textfelder ausschließlich über textContent/DOM-Erzeugung,
// nie per innerHTML-Stringkonkatenation - Artikeldaten stammen von
// Drittseiten und sind damit nicht vertrauenswürdig. Einzige Ausnahme ist
// article-body weiter unten (siehe Kommentar dort). ──────────────────────
function renderArticle() {
    document.title = (article.title || I18N['articleReader.untitledArticle']) + ' – Merlin';
    document.getElementById('a-title').textContent = article.title || article.url;

    const excerptEl = document.getElementById('a-excerpt');
    if (article.excerpt) {
        excerptEl.textContent = article.excerpt;
        excerptEl.style.display = 'block';
    }

    const meta = document.getElementById('a-meta');
    meta.innerHTML = '';
    if (article.author) {
        const span = document.createElement('span');
        span.textContent = article.author;
        meta.appendChild(span);
    }
    const safeUrl = sanitizeHref(article.url);
    if (article.siteName) {
        if (safeUrl) {
            const a = document.createElement('a');
            a.href = safeUrl;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = article.siteName;
            meta.appendChild(a);
        } else {
            const span = document.createElement('span');
            span.textContent = article.siteName;
            meta.appendChild(span);
        }
    }
    if (article.publishedAt) {
        const span = document.createElement('span');
        span.textContent = formatDate(article.publishedAt);
        meta.appendChild(span);
    }
    if (article.readingTime) {
        const span = document.createElement('span');
        span.textContent = I18N['articleReader.minutesShort'].replace('{minutes}', article.readingTime);
        meta.appendChild(span);
    }

    renderTagChips();

    // #video-player muss VOR dem innerHTML-Reset wieder an seine sichere
    // Ausgangsposition (Geschwister vor #article-body) - siehe
    // resetVideoPlayerPosition()-Docblock.
    resetVideoPlayerPosition();

    // Bewusst der einzige innerHTML-Einsatz mit ungeschütztem HTML: der Inhalt
    // wurde bereits serverseitig durch ContentExtractorService::sanitizeHtml()
    // bereinigt (allowlist-basiert, nur bestimmte iframe-Hosts und exakt zwei
    // <script>-Quellen bleiben erhalten) - dieselbe Vertrauensgrenze wie
    // v-html in merlin-nextclouds ArticleReader.vue.
    document.getElementById('article-body').innerHTML = article.content || '';
    executeEmbedScripts();
    setupVideoPlayer(articleId, article.url);
    applyFontSize();

    document.getElementById('reader-status').style.display = 'none';
    document.getElementById('article-root').style.display = 'block';
    document.getElementById('toolbar').style.display = 'flex';
    document.getElementById('highlight-hint').style.display = 'block';

    updateToolbar();
    applyDockAccent();
    initProgressBar();
    restoreScrollPosition();
    initHighlights();
    loadShare();
}

function renderTagChips() {
    const row = document.getElementById('a-tags');
    row.innerHTML = '';
    for (const tag of (article.tags || [])) {
        const chip = document.createElement('span');
        chip.className = 'tag-chip';
        chip.textContent = tag.name;
        chip.style.backgroundColor = tag.color || '#6b7280';
        chip.style.color = contrastColor(tag.color || '#6b7280');
        row.appendChild(chip);
    }
}

function updateToolbar() {
    document.getElementById('btn-read-label').textContent = article.isRead ? I18N['articleReader.markAsUnread'] : I18N['articleReader.markAsRead'];

    const favBtn = document.getElementById('btn-favorite');
    favBtn.title = article.isFavorite ? I18N['articleReader.removeFavorite'] : I18N['articleReader.addFavorite'];
    favBtn.classList.toggle('is-favorite', !!article.isFavorite);

    const archBtn = document.getElementById('btn-archive');
    archBtn.title = article.isArchived ? I18N['articleReader.restore'] : I18N['articleReader.archive'];
}

async function apiPut(path) {
    const res = await fetch(basePath + path, { method: 'PUT', credentials: 'same-origin' });
    return res.ok ? res.json() : null;
}

document.getElementById('btn-read').addEventListener('click', async () => {
    const updated = await apiPut('/api/articles/' + articleId + '/read');
    if (updated) { article = updated; updateToolbar(); renderTagChips(); }
});

document.getElementById('btn-favorite').addEventListener('click', async () => {
    const updated = await apiPut('/api/articles/' + articleId + '/favorite');
    if (updated) { article = updated; updateToolbar(); renderTagChips(); }
});

document.getElementById('btn-archive').addEventListener('click', async () => {
    const updated = await apiPut('/api/articles/' + articleId + '/archive');
    if (updated) { article = updated; updateToolbar(); renderTagChips(); }
});

document.getElementById('btn-delete').addEventListener('click', async () => {
    if (!confirm(I18N['articleReader.confirmDeleteArticle'])) return;
    await fetch(basePath + '/api/articles/' + articleId, { method: 'DELETE', credentials: 'same-origin' });
    location.href = basePath + '/library';
});

// ── Tags verwalten ─────────────────────────────────────────────────────
function articleHasTag(tagId) {
    return (article.tags || []).some(t => t.id === tagId);
}

function renderTagCheckboxes() {
    const container = document.getElementById('tag-checkboxes');
    container.innerHTML = '';
    if (allTags.length === 0) {
        const p = document.createElement('p');
        p.className = 'muted';
        p.textContent = I18N['articleReader.noTagsYet'];
        container.appendChild(p);
        return;
    }
    for (const tag of allTags) {
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = articleHasTag(tag.id);
        checkbox.addEventListener('change', () => toggleTag(tag.id, checkbox.checked));
        label.appendChild(checkbox);
        const span = document.createElement('span');
        span.textContent = tag.name;
        label.appendChild(span);
        container.appendChild(label);
    }
}

async function toggleTag(tagId, shouldHave) {
    const method = shouldHave ? 'POST' : 'DELETE';
    await fetch(basePath + '/api/articles/' + articleId + '/tags/' + tagId, { method, credentials: 'same-origin' });
    const res = await fetch(basePath + '/api/articles/' + articleId, { credentials: 'same-origin' });
    if (res.ok) {
        article = await res.json();
        renderTagChips();
    }
}

document.getElementById('new-tag-submit').addEventListener('click', async () => {
    const nameInput = document.getElementById('new-tag-name');
    const colorInput = document.getElementById('new-tag-color');
    const name = nameInput.value.trim();
    if (!name) return;
    const res = await fetch(basePath + '/api/tags', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, color: colorInput.value }),
    });
    if (res.ok) {
        const tag = await res.json();
        allTags.push(tag);
        nameInput.value = '';
        renderTagCheckboxes();
        await toggleTag(tag.id, true);
        renderTagCheckboxes();
    }
});

// ── Lesefortschritt ────────────────────────────────────────────────────
// Best-effort, ohne localStorage-Last-Write-Wins wie in ArticleReader.vue -
// der Web-Reader ist hier die einzige Quelle für den Fortschritt.
let scrollTimer = null;
let articleLoaded = false;

function currentScrollFraction() {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    return max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
}

function persistProgress() {
    if (!articleLoaded) return;
    // Respektiert die `saveProgress`-Einstellung, genau wie
    // ArticleReaderView.swift (iOS) und ArticleReader.vue.
    if (userSettings.saveProgress === false) return;
    fetch(basePath + '/api/articles/' + articleId + '/progress', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scrollProgress: currentScrollFraction(), scrollUpdatedAt: Date.now() }),
    }).catch(() => {});
}

// Sichtbarer Fortschrittsbalken am Bildschirmrand während des Lesens, analog
// zu ArticleReader.vues .reader-progress-bar (Position/Farbe aus den
// Nutzereinstellungen progressEdge/accentColor).
function initProgressBar() {
    const bar = document.getElementById('reader-progress-bar');
    const edge = userSettings.progressEdge || 'left';
    if (edge === 'off') {
        bar.style.display = 'none';
        return;
    }
    bar.className = 'reader-progress-bar reader-progress-bar--' + edge;
    bar.style.background = userSettings.accentColor || '#3865f5';
    bar.style.display = 'block';
    updateProgressBar();
}

function updateProgressBar() {
    const bar = document.getElementById('reader-progress-bar');
    if (bar.style.display === 'none') return;
    const pct = (currentScrollFraction() * 100) + '%';
    if (bar.classList.contains('reader-progress-bar--left') || bar.classList.contains('reader-progress-bar--right')) {
        bar.style.height = pct;
    } else {
        bar.style.width = pct;
    }
}

window.addEventListener('scroll', () => {
    updateProgressBar();
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(persistProgress, 600);
});
window.addEventListener('pagehide', persistProgress);

function restoreScrollPosition() {
    // Respektiert die `resumeOnOpen`-Einstellung, genau wie ArticleListView.swift
    // (iOS): die gespeicherte Position bleibt erhalten, wird aber nicht angesprungen.
    if (!article.scrollProgress || userSettings.resumeOnOpen === false) {
        articleLoaded = true;
        return;
    }
    requestAnimationFrame(() => {
        const max = document.documentElement.scrollHeight - window.innerHeight;
        if (max > 0) {
            window.scrollTo(0, article.scrollProgress * max);
        }
        articleLoaded = true;
        updateProgressBar();
    });
}

// ── TTS (Vorlesen) ────────────────────────────────────────────────────
// Schlicht ein <audio>-Element statt eines Nachbaus von PiperAudioServices
// AVPlayer-Pufferlogik - der Browser übernimmt Streaming/Steuerung nativ.
// src wird lazy erst beim ersten Klick gesetzt, damit nicht bei jedem
// Artikel-Öffnen unnötig TTS-Traffic anfällt.
document.getElementById('btn-tts').addEventListener('click', () => {
    const audio = document.getElementById('tts-audio');
    if (!audio.src) {
        audio.src = basePath + '/api/articles/' + articleId + '/tts?lang=de';
    }
    audio.style.display = 'block';
    audio.play();
});

// ── Teilen (Public-Share-Link) ───────────────────────────────────────────
let currentShare = null;

async function loadShare() {
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share', { credentials: 'same-origin' });
    currentShare = res.ok ? await res.json() : { enabled: false };
    renderShare();
}

function renderShare() {
    const statusEl = document.getElementById('share-status');
    const linkRow = document.getElementById('share-link-row');
    const manage = document.getElementById('share-manage');
    const createBtn = document.getElementById('share-create-btn');

    if (!currentShare || !currentShare.enabled) {
        statusEl.style.display = 'none';
        linkRow.style.display = 'none';
        manage.style.display = 'none';
        createBtn.style.display = 'block';
        return;
    }

    statusEl.style.display = 'none';
    createBtn.style.display = 'none';
    linkRow.style.display = 'flex';
    manage.style.display = 'block';

    document.getElementById('share-link-input').value = currentShare.url || '';
    document.getElementById('share-password-input').value = '';
    document.getElementById('share-password-input').placeholder = currentShare.hasPassword
        ? I18N['articleReader.passwordChangePlaceholder']
        : I18N['articleReader.passwordNoProtectionPlaceholder'];
    document.getElementById('share-expiry-input').value = currentShare.expiresAt
        ? currentShare.expiresAt.slice(0, 10)
        : '';
}

document.getElementById('share-create-btn').addEventListener('click', async () => {
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    if (res.ok) {
        currentShare = await res.json();
        renderShare();
    }
});

document.getElementById('share-copy-btn').addEventListener('click', async () => {
    const input = document.getElementById('share-link-input');
    try {
        await navigator.clipboard.writeText(input.value);
    } catch {
        input.select();
        document.execCommand('copy');
    }
});

document.getElementById('share-password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('share-password-input').value;
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: password || null }),
    });
    if (res.ok) {
        currentShare = await res.json();
        renderShare();
    }
});

document.getElementById('share-expiry-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const value = document.getElementById('share-expiry-input').value;
    if (!value) return;
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ expiresAt: value }),
    });
    if (res.ok) {
        currentShare = await res.json();
        renderShare();
    }
});

document.getElementById('share-expiry-clear').addEventListener('click', async () => {
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ expiresAt: null }),
    });
    if (res.ok) {
        currentShare = await res.json();
        renderShare();
    }
});

document.getElementById('share-regenerate-btn').addEventListener('click', async () => {
    const res = await fetch(basePath + '/api/articles/' + articleId + '/share/regenerate', {
        method: 'POST',
        credentials: 'same-origin',
    });
    if (res.ok) {
        currentShare = await res.json();
        renderShare();
    }
});

document.getElementById('share-revoke-btn').addEventListener('click', async () => {
    if (!confirm(I18N['articleReader.confirmRevokeLink'])) return;
    await fetch(basePath + '/api/articles/' + articleId + '/share', { method: 'DELETE', credentials: 'same-origin' });
    currentShare = { enabled: false };
    renderShare();
});

// ── Laden ──────────────────────────────────────────────────────────────
async function load() {
    const [articleRes, tagsRes, settingsRes] = await Promise.all([
        fetch(basePath + '/api/articles/' + articleId, { credentials: 'same-origin' }),
        fetch(basePath + '/api/tags', { credentials: 'same-origin' }),
        fetch(basePath + '/api/settings', { credentials: 'same-origin' }),
    ]);

    if (!articleRes.ok) {
        document.getElementById('reader-status').textContent = I18N['articleReader.notFoundOrForbidden'];
        return;
    }

    article = await articleRes.json();
    allTags = tagsRes.ok ? await tagsRes.json() : [];
    userSettings = settingsRes.ok ? await settingsRes.json() : {};
    renderTagCheckboxes();
    renderArticle();
}

document.getElementById('tag-details').addEventListener('toggle', (e) => {
    if (e.target.open) renderTagCheckboxes();
});

// ── Dock-Menüs (Teilen/Mehr/Tags) ──────────────────────────────────────
// Native <details>-Elemente statt eigenem Open-State: schließen sich
// gegenseitig beim Öffnen und bei Klick außerhalb des Docks (entspricht dem
// Backdrop-Verhalten der export-menu-Dropdowns in ArticleReader.vue).
const dockDetailsEls = ['share-details', 'more-details', 'tag-details'].map(id => document.getElementById(id));
dockDetailsEls.forEach(d => {
    d.addEventListener('toggle', () => {
        if (d.open) {
            dockDetailsEls.forEach(other => { if (other !== d && other.open) other.open = false; });
        }
    });
});
document.addEventListener('click', (e) => {
    if (e.target.closest('#toolbar')) return;
    dockDetailsEls.forEach(d => { if (d.open) d.open = false; });
});

// "Tags" im Mehr-Menü öffnet das eigenständige tag-details-Element am selben
// Dock-Anker, statt selbst ein Panel zu enthalten (siehe Markup-Kommentar oben).
document.getElementById('btn-open-tags').addEventListener('click', () => {
    document.getElementById('more-details').open = false;
    document.getElementById('tag-details').open = true;
});

// Dock-Hintergrund folgt der Akzentfarbe aus den Nutzereinstellungen (gleiche
// Quelle wie in ArticleReader.vue dockStyle), geladen einmalig in load()
// zusammen mit Artikel/Tags (siehe userSettings) statt eigenem Fetch.
function applyDockAccent() {
    const accent = userSettings.accentColor || '#3865f5';
    const fg = contrastColor(accent);
    const isLightFg = fg === '#1d1d1f';
    const dock = document.getElementById('toolbar');
    dock.style.background = accent;
    dock.style.setProperty('--dock-fg', fg);
    dock.style.setProperty('--dock-overlay', isLightFg ? 'rgba(0, 0, 0, 0.12)' : 'rgba(255, 255, 255, 0.18)');
    dock.style.setProperty('--dock-overlay-active', isLightFg ? 'rgba(0, 0, 0, 0.20)' : 'rgba(255, 255, 255, 0.28)');
}

load();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
