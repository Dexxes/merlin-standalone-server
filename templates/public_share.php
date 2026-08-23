<?php $title = $t->t('publicShare.pageTitle'); $layout = 'reader'; include __DIR__ . '/partials/header.php'; ?>

<div class="reader-toolbar" id="toolbar" style="display:none;">
    <button type="button" id="btn-tts"><?= htmlspecialchars($t->t('publicShare.listen'), ENT_QUOTES, 'UTF-8') ?></button>
</div>
<audio id="tts-audio" controls style="display:none;width:100%;margin-top:0.5em;"></audio>

<p id="reader-status"><?= htmlspecialchars($t->t('publicShare.loading'), ENT_QUOTES, 'UTF-8') ?></p>

<div id="password-gate" style="display:none;">
    <h1><?= htmlspecialchars($t->t('publicShare.passwordRequiredHeading'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted"><?= htmlspecialchars($t->t('publicShare.passwordProtectedHint'), ENT_QUOTES, 'UTF-8') ?></p>
    <form id="unlock-form">
        <label for="share-password"><?= htmlspecialchars($t->t('common.password'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="password" id="share-password" required autofocus>
        <button type="submit"><?= htmlspecialchars($t->t('publicShare.unlockSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="unlock-error" class="error" style="display:none;"></p>
</div>

<article id="article-root" style="display:none;">
    <h1 id="a-title"></h1>
    <p id="a-excerpt" class="article-excerpt" style="display:none;"></p>
    <div id="a-meta" class="article-meta"></div>
    <div id="article-body"></div>
</article>

<script>
const I18N = <?= json_encode($t->forJs([
    'publicShare.untitledArticle',
    'publicShare.linkExpired',
    'publicShare.articleNotFound',
    'publicShare.wrongPassword',
    'publicShare.loading',
    'publicShare.minutesShort',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const token = <?= json_encode($token) ?>;

// Portiert aus ArticleReader.vue (sanitizeHref): lässt nur http(s)-Schemata
// als href zu.
function sanitizeHref(url) {
    if (typeof url !== 'string') return null;
    const normalized = url.replace(/[\x00-\x20]+/g, '').toLowerCase();
    if (normalized.startsWith('javascript:') || normalized.startsWith('vbscript:') || normalized.startsWith('data:')) {
        return null;
    }
    return url;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('default', { year: '2-digit', month: '2-digit', day: '2-digit' }).format(date);
}

// ── Read-only Highlight-Rendering ─────────────────────────────────────────
// Teilmenge von article_reader.php's HighlightEngine: nur Anwenden
// gespeicherter Highlights, kein Erstellen/Löschen (öffentliche Besucher
// dürfen den Artikel nicht verändern) - daher auch keine Mouseup-/
// Contextmenu-Listener.

if (!document.getElementById('merlin-hl-style')) {
    const hlStyle = document.createElement('style');
    hlStyle.id = 'merlin-hl-style';
    hlStyle.textContent = `
        mark.merlin-highlight {
            border-radius: 2px !important;
            padding: 0 1px !important;
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
                if (child.nodeName.toLowerCase() === tag) {
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
    return mark;
}

function wrapRange(range, color, highlightId) {
    if (range.collapsed) return;

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
    }
}

function renderHighlightsReadOnly(container, highlights) {
    for (const h of highlights) {
        try {
            const startNode = resolveXPath(h.startXpath, container);
            const endNode = resolveXPath(h.endXpath, container);
            if (!startNode || !endNode) continue;

            const range = document.createRange();
            range.setStart(startNode, h.startOffset);
            range.setEnd(endNode, h.endOffset);
            if (!range.collapsed) {
                wrapRange(range, h.color, h.id);
            }
        } catch (err) {
            // Stale Highlight (Artikeltext hat sich geändert) - überspringen
        }
    }
}

// ── Laden ──────────────────────────────────────────────────────────────
function renderArticle(article) {
    document.title = (article.title || I18N['publicShare.untitledArticle']) + ' – Merlin';
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
        span.textContent = I18N['publicShare.minutesShort'].replace('{minutes}', article.readingTime);
        meta.appendChild(span);
    }

    // Bewusst der einzige innerHTML-Einsatz mit ungeschütztem HTML: der Inhalt
    // wurde bereits serverseitig durch ContentExtractorService::sanitizeHtml()
    // bereinigt.
    document.getElementById('article-body').innerHTML = article.content || '';

    if (article.highlights && article.highlights.length) {
        renderHighlightsReadOnly(document.getElementById('article-body'), article.highlights);
    }

    document.getElementById('reader-status').style.display = 'none';
    document.getElementById('password-gate').style.display = 'none';
    document.getElementById('article-root').style.display = 'block';
    document.getElementById('toolbar').style.display = 'flex';

    document.getElementById('btn-tts').addEventListener('click', () => {
        const audio = document.getElementById('tts-audio');
        if (!audio.src) {
            audio.src = basePath + '/s/' + encodeURIComponent(token) + '/tts?lang=de';
        }
        audio.style.display = 'block';
        audio.play();
    }, { once: true });
}

async function load() {
    const res = await fetch(basePath + '/s/' + encodeURIComponent(token) + '/data', { credentials: 'same-origin' });

    if (res.status === 401) {
        document.getElementById('reader-status').style.display = 'none';
        document.getElementById('password-gate').style.display = 'block';
        return;
    }
    if (res.status === 410) {
        document.getElementById('reader-status').textContent = I18N['publicShare.linkExpired'];
        return;
    }
    if (!res.ok) {
        document.getElementById('reader-status').textContent = I18N['publicShare.articleNotFound'];
        return;
    }

    renderArticle(await res.json());
}

document.getElementById('unlock-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('share-password').value;
    const errorEl = document.getElementById('unlock-error');
    errorEl.style.display = 'none';

    const res = await fetch(basePath + '/s/' + encodeURIComponent(token) + '/unlock', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password }),
    });

    if (!res.ok) {
        errorEl.textContent = I18N['publicShare.wrongPassword'];
        errorEl.style.display = 'block';
        return;
    }

    document.getElementById('password-gate').style.display = 'none';
    document.getElementById('reader-status').style.display = 'block';
    document.getElementById('reader-status').textContent = I18N['publicShare.loading'];
    load();
});

load();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
