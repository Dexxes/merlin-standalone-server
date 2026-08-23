<?php $title = $t->t('library.pageTitle'); $layout = 'library'; include __DIR__ . '/partials/header.php'; ?>

<div class="lib-shell">

    <nav class="lib-nav">
        <span class="lib-brand">MERLIN <span><?= htmlspecialchars($t->t('library.brand'), ENT_QUOTES, 'UTF-8') ?></span></span>
        <div class="lib-search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            <input type="search" id="search" class="lib-input" placeholder="<?= htmlspecialchars($t->t('library.searchPlaceholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($t->t('library.searchAriaLabel'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <button type="button" id="add-toggle" class="lib-btn lib-btn--primary" aria-expanded="false" aria-controls="add-article">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
            <?= htmlspecialchars($t->t('library.addArticle'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <a href="<?= url('/account'); ?>"><?= htmlspecialchars($t->t('library.navMyAccount'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php if (!empty($isAdmin)): ?><a href="<?= url('/admin'); ?>"><?= htmlspecialchars($t->t('library.navAdmin'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
        <a href="<?= url('/logout'); ?>"><?= htmlspecialchars($t->t('library.navLogout'), ENT_QUOTES, 'UTF-8') ?></a>
    </nav>

    <form id="add-article" class="lib-addrow">
        <input type="url" id="add-url" class="lib-input" placeholder="<?= htmlspecialchars($t->t('library.urlPlaceholder'), ENT_QUOTES, 'UTF-8') ?>" required>
        <button type="submit" class="lib-btn lib-btn--primary"><?= htmlspecialchars($t->t('library.addSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" id="add-cancel" class="lib-btn"><?= htmlspecialchars($t->t('library.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
        <p id="add-error" class="error" style="display:none;"></p>
    </form>

    <div class="lib-body">

        <section id="continue-section" style="display:none;">
            <div class="lib-sechead">
                <h2><?= htmlspecialchars($t->t('library.continueReading'), ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="lib-kicker" id="continue-count"></span>
                <span class="lib-rule"></span>
            </div>
            <div class="lib-strip" id="continue-strip"></div>
        </section>

        <div class="lib-columns">

            <aside class="lib-side">
                <span class="lib-kicker"><?= htmlspecialchars($t->t('library.viewLabel'), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="lib-facets" id="views">
                    <a class="lib-facet" data-view="unread" href="?view=unread"><span><?= htmlspecialchars($t->t('library.viewUnread'), ENT_QUOTES, 'UTF-8') ?></span><span data-count="unread"></span></a>
                    <a class="lib-facet" data-view="favorites" href="?view=favorites"><span><?= htmlspecialchars($t->t('library.viewFavorites'), ENT_QUOTES, 'UTF-8') ?></span><span data-count="favorites"></span></a>
                    <a class="lib-facet" data-view="archive" href="?view=archive"><span><?= htmlspecialchars($t->t('library.viewArchive'), ENT_QUOTES, 'UTF-8') ?></span><span data-count="archived"></span></a>
                    <a class="lib-facet" data-view="all" href="?view=all"><span><?= htmlspecialchars($t->t('library.viewAll'), ENT_QUOTES, 'UTF-8') ?></span><span data-count="total"></span></a>
                </div>

                <span class="lib-kicker"><?= htmlspecialchars($t->t('library.tagsLabel'), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="lib-tags" id="tags"></div>
            </aside>

            <div>
                <div class="lib-toolbar">
                    <span class="lib-spacer"></span>
                    <div class="lib-seg" id="sort">
                        <label><input type="radio" name="sort" value="newest" checked><?= htmlspecialchars($t->t('library.sortNewest'), ENT_QUOTES, 'UTF-8') ?></label>
                        <label><input type="radio" name="sort" value="shortest"><?= htmlspecialchars($t->t('library.sortShortest'), ENT_QUOTES, 'UTF-8') ?></label>
                        <label><input type="radio" name="sort" value="started"><?= htmlspecialchars($t->t('library.sortStarted'), ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                </div>

                <ul id="article-list" class="lib-list"></ul>
                <p id="empty-state" class="lib-empty" style="display:none;"><?= htmlspecialchars($t->t('library.emptyState'), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="lib-more"><button id="load-more" type="button" class="lib-btn" style="display:none;"><?= htmlspecialchars($t->t('library.loadMore'), ENT_QUOTES, 'UTF-8') ?></button></div>
            </div>
        </div>
    </div>
</div>

<!-- Lucide-Icons (stroke-width 1.5) als Templates: die Zeilen werden per DOM
     erzeugt, geklonte Templates halten das SVG-Markup trotzdem im HTML. -->
<template id="ico-star"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></template>
<template id="ico-archive"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="5" x="2" y="3" rx="1"></rect><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"></path><path d="M10 12h4"></path></svg></template>
<template id="ico-restore"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-15-6.7L3 13"></path></svg></template>
<template id="ico-trash"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></template>

<script>
const I18N = <?= json_encode($t->forJs([
    'library.removeFavorite',
    'library.addFavorite',
    'library.restore',
    'library.archive',
    'library.delete',
    'library.confirmDeleteArticle',
    'library.minutesShort',
    'library.remainingMinutes',
    'library.lastRead',
    'library.today',
    'library.yesterday',
    'library.processing',
    'library.addedMinutesAgo',
    'library.addedHoursAgo',
    'library.addedOn',
    'library.startedCount',
    'library.addArticleFailed',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
// Für Intl.*-Datumsformatierung (relativeDay) - unabhängig von der
// gettext-artigen I18N-Textübersetzung, aber an dieselbe UI-Sprache gekoppelt.
const dateLocale = document.documentElement.lang === 'en' ? 'en-US' : 'de-DE';

const params = new URLSearchParams(location.search);
let view = params.get('view') || 'unread';
let tagId = params.get('tagId') ? Number(params.get('tagId')) : null;
let sort = 'newest';
let searchTerm = '';
let offset = 0;
const LIMIT = 30;
let loading = false;
let hasMore = true;
let loaded = [];

function viewFilterParams(v) {
    switch (v) {
        case 'unread': return { isRead: 'false', isArchived: 'false' };
        case 'favorites': return { isFavorite: 'true' };
        case 'archive': return { isArchived: 'true' };
        case 'all':
        default: return { isArchived: 'false' };
    }
}

function icon(name) {
    return document.getElementById('ico-' + name).content.firstElementChild.cloneNode(true);
}

// Portiert aus ArticleReader.vue (contrastColor): wählt schwarzen oder weißen
// Text je nach wahrgenommener Helligkeit der frei wählbaren Tag-Farbe.
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

// Vorschaubild oder gezeichnete Platzhalter-Zelle. article.imageUrl kommt von
// Drittseiten, wird deshalb nur als src einer <img> gesetzt (kein innerHTML)
// und bei Ladefehler durch den Platzhalter ersetzt.
function buildThumb(article, className, brandPlaceholder) {
    if (article.imageUrl) {
        const img = document.createElement('img');
        img.className = 'lib-thumb ' + className;
        img.src = article.imageUrl;
        img.alt = '';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => { img.removeAttribute('src'); });
        return img;
    }
    const ph = document.createElement('span');
    ph.className = 'lib-thumb ' + className + (brandPlaceholder ? ' lib-thumb--brand' : '');
    return ph;
}

function buildCorners(host) {
    for (const pos of ['tl', 'tr', 'bl', 'br']) {
        const i = document.createElement('i');
        i.className = 'lib-corner ' + pos;
        host.appendChild(i);
    }
}

function progressPercent(article) {
    return Math.round((article.scrollProgress || 0) * 100);
}

// Nur begonnene, nicht abgeschlossene Artikel gelten als "angefangen" -
// gleiche Schwellenwerte wie der Fortschrittsbalken in ArticleCard.vue.
function isStarted(article) {
    return article.scrollProgress > 0.01 && article.scrollProgress < 0.99;
}

function buildProgress(article) {
    const wrap = document.createElement('div');
    wrap.className = 'lib-progress';

    const pct = document.createElement('span');
    pct.className = 'lib-progress-pct';
    pct.textContent = progressPercent(article) + ' %';
    wrap.appendChild(pct);

    const track = document.createElement('span');
    track.className = 'lib-progress-track';
    const fill = document.createElement('span');
    fill.className = 'lib-progress-fill';
    fill.style.width = progressPercent(article) + '%';
    track.appendChild(fill);
    wrap.appendChild(track);

    return wrap;
}

function remainingLabel(article) {
    const parts = [];
    if (article.readingTime) {
        const left = Math.max(1, Math.round(article.readingTime * (1 - (article.scrollProgress || 0))));
        parts.push(I18N['library.remainingMinutes'].replace('{minutes}', left));
    }
    if (article.scrollUpdatedAt) parts.push(I18N['library.lastRead'].replace('{when}', relativeDay(article.scrollUpdatedAt * 1000)));
    return parts.join(' · ');
}

function relativeDay(ms) {
    const then = new Date(ms);
    const days = Math.floor((Date.now() - then.getTime()) / 86400000);
    if (days <= 0) return I18N['library.today'];
    if (days === 1) return I18N['library.yesterday'];
    if (days < 7) return then.toLocaleDateString(dateLocale, { weekday: 'short' });
    return then.toLocaleDateString(dateLocale, { day: '2-digit', month: '2-digit' });
}

function addedLabel(article) {
    if (!article.createdAt) return '';
    const ms = Date.parse(article.createdAt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(ms)) return '';
    const mins = Math.floor((Date.now() - ms) / 60000);
    if (mins < 60) return I18N['library.addedMinutesAgo'].replace('{minutes}', Math.max(1, mins));
    if (mins < 1440) return I18N['library.addedHoursAgo'].replace('{hours}', Math.floor(mins / 60));
    return I18N['library.addedOn'].replace('{when}', relativeDay(ms));
}

function buildActions(article, size) {
    const acts = document.createElement('div');
    acts.className = 'lib-acts';

    const favBtn = document.createElement('button');
    favBtn.type = 'button';
    favBtn.className = 'lib-btn lib-btn--icon' + (size ? ' ' + size : '');
    favBtn.title = article.isFavorite ? I18N['library.removeFavorite'] : I18N['library.addFavorite'];
    favBtn.setAttribute('aria-label', favBtn.title);
    const star = icon('star');
    if (article.isFavorite) { star.setAttribute('fill', 'currentColor'); }
    favBtn.appendChild(star);
    favBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        await fetch(basePath + '/api/articles/' + article.id + '/favorite', { method: 'PUT', credentials: 'same-origin' });
        reloadAll();
    });
    acts.appendChild(favBtn);

    const archiveBtn = document.createElement('button');
    archiveBtn.type = 'button';
    archiveBtn.className = 'lib-btn lib-btn--icon' + (size ? ' ' + size : '');
    archiveBtn.title = article.isArchived ? I18N['library.restore'] : I18N['library.archive'];
    archiveBtn.setAttribute('aria-label', archiveBtn.title);
    archiveBtn.appendChild(icon(article.isArchived ? 'restore' : 'archive'));
    archiveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        await fetch(basePath + '/api/articles/' + article.id + '/archive', { method: 'PUT', credentials: 'same-origin' });
        reloadAll();
    });
    acts.appendChild(archiveBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'lib-btn lib-btn--icon lib-btn--danger' + (size ? ' ' + size : '');
    deleteBtn.title = I18N['library.delete'];
    deleteBtn.setAttribute('aria-label', I18N['library.delete']);
    deleteBtn.appendChild(icon('trash'));
    deleteBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(I18N['library.confirmDeleteArticle'])) return;
        await fetch(basePath + '/api/articles/' + article.id, { method: 'DELETE', credentials: 'same-origin' });
        reloadAll();
    });
    acts.appendChild(deleteBtn);

    return acts;
}

function articleHref(article) {
    return basePath + '/articles/' + encodeURIComponent(article.id);
}

// Alle Textfelder werden über textContent/DOM-Erzeugung gesetzt statt per
// innerHTML-Stringkonkatenation - Artikeldaten stammen von Drittseiten und
// sind damit nicht vertrauenswürdig (anders als z.B. die Account-Daten in
// admin_users.php, die der Nutzer selbst eingegeben hat).
function buildStripCard(article) {
    const card = document.createElement('div');
    card.className = 'lib-strip-card';
    buildCorners(card);
    card.addEventListener('click', () => { location.href = articleHref(article); });

    card.appendChild(buildThumb(article, '', true));

    const text = document.createElement('div');
    text.className = 'lib-strip-text';

    const source = document.createElement('div');
    source.className = 'lib-source';
    const sourceParts = [];
    if (article.siteName) sourceParts.push(article.siteName);
    if (article.readingTime) sourceParts.push(I18N['library.minutesShort'].replace('{minutes}', article.readingTime));
    source.textContent = sourceParts.join(' · ');
    text.appendChild(source);

    const titleLink = document.createElement('a');
    titleLink.className = 'lib-title';
    titleLink.href = articleHref(article);
    titleLink.textContent = article.title || article.url;
    text.appendChild(titleLink);

    text.appendChild(buildProgress(article));

    const remaining = document.createElement('div');
    remaining.className = 'lib-remaining';
    remaining.textContent = remainingLabel(article);
    text.appendChild(remaining);

    card.appendChild(text);
    card.appendChild(buildActions(article));
    return card;
}

function buildRow(article) {
    const li = document.createElement('li');
    li.className = 'lib-row';
    li.addEventListener('click', () => { location.href = articleHref(article); });

    li.appendChild(buildThumb(article, ''));

    const main = document.createElement('div');
    main.className = 'lib-row-main';

    const titleWrap = document.createElement('div');
    const titleLink = document.createElement('a');
    titleLink.className = 'lib-title';
    titleLink.href = articleHref(article);
    titleLink.textContent = article.title || article.url;
    titleWrap.appendChild(titleLink);

    if (article.isProcessing) {
        const badge = document.createElement('span');
        badge.className = 'lib-processing';
        badge.textContent = I18N['library.processing'];
        titleWrap.appendChild(badge);
    }
    main.appendChild(titleWrap);

    const metaParts = [];
    if (article.siteName) metaParts.push(article.siteName);
    if (article.readingTime) metaParts.push(I18N['library.minutesShort'].replace('{minutes}', article.readingTime));
    const added = addedLabel(article);
    if (added) metaParts.push(added);
    if (metaParts.length) {
        const meta = document.createElement('div');
        meta.className = 'lib-row-meta';
        meta.textContent = metaParts.join(' · ');
        main.appendChild(meta);
    }

    if (article.excerpt) {
        const excerpt = document.createElement('p');
        excerpt.className = 'lib-row-excerpt';
        excerpt.textContent = article.excerpt;
        main.appendChild(excerpt);
    }

    if (isStarted(article)) {
        main.appendChild(buildProgress(article));
    }

    if (article.tags && article.tags.length) {
        const tagsRow = document.createElement('div');
        tagsRow.className = 'lib-row-tags';
        for (const tag of article.tags) {
            const chip = document.createElement('span');
            chip.className = 'lib-tag';
            chip.textContent = tag.name;
            if (tag.color) {
                chip.style.backgroundColor = tag.color;
                chip.style.color = contrastColor(tag.color);
            }
            tagsRow.appendChild(chip);
        }
        main.appendChild(tagsRow);
    }

    li.appendChild(main);
    li.appendChild(buildActions(article));
    return li;
}

function sortLoaded(articles) {
    const copy = articles.slice();
    if (sort === 'shortest') {
        copy.sort((a, b) => (a.readingTime || 999) - (b.readingTime || 999));
    } else if (sort === 'started') {
        copy.sort((a, b) => (b.scrollProgress || 0) - (a.scrollProgress || 0));
    }
    return copy;
}

function renderList() {
    const list = document.getElementById('article-list');
    list.replaceChildren();
    for (const article of sortLoaded(loaded)) {
        list.appendChild(buildRow(article));
    }
    document.getElementById('empty-state').style.display = loaded.length === 0 ? 'block' : 'none';
    document.getElementById('load-more').style.display = hasMore ? 'inline-flex' : 'none';
}

function listUrl() {
    const term = searchTerm.trim();
    if (term) {
        return basePath + '/api/articles/search?term=' + encodeURIComponent(term) + '&limit=' + LIMIT + '&offset=' + offset;
    }
    const qp = new URLSearchParams(viewFilterParams(view));
    if (tagId) qp.set('tagId', String(tagId));
    qp.set('limit', String(LIMIT));
    qp.set('offset', String(offset));
    return basePath + '/api/articles?' + qp.toString();
}

async function fetchPage(reset) {
    if (loading) return;
    loading = true;
    if (reset) {
        offset = 0;
        loaded = [];
        hasMore = true;
    }

    const res = await fetch(listUrl(), { credentials: 'same-origin' });
    const articles = await res.json();
    loaded = loaded.concat(articles);
    offset += articles.length;
    hasMore = articles.length === LIMIT;
    renderList();
    loading = false;
}

// "Weiterlesen": der Server sortiert nicht nach Fortschritt, deshalb einmal
// die letzten 100 nicht archivierten Artikel holen und clientseitig auf
// angefangene filtern (zuletzt gelesene zuerst).
async function loadContinue() {
    const res = await fetch(basePath + '/api/articles?isArchived=false&limit=100&offset=0', { credentials: 'same-origin' });
    const articles = await res.json();
    const started = articles
        .filter(isStarted)
        .sort((a, b) => (b.scrollUpdatedAt || 0) - (a.scrollUpdatedAt || 0))
        .slice(0, 12);

    const strip = document.getElementById('continue-strip');
    strip.replaceChildren();
    for (const article of started) {
        strip.appendChild(buildStripCard(article));
    }
    document.getElementById('continue-section').style.display = started.length ? 'block' : 'none';
    document.getElementById('continue-count').textContent = I18N['library.startedCount'].replace('{count}', started.length);
}

async function loadCounts() {
    const res = await fetch(basePath + '/api/articles/counts', { credentials: 'same-origin' });
    const counts = await res.json();
    document.querySelectorAll('#views [data-count]').forEach(el => {
        const value = counts[el.dataset.count];
        el.textContent = value === undefined ? '' : String(value);
    });
}

async function loadTags() {
    const res = await fetch(basePath + '/api/tags', { credentials: 'same-origin' });
    const tags = await res.json();
    const host = document.getElementById('tags');
    host.replaceChildren();
    for (const tag of tags) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'lib-tag' + (tag.id === tagId ? ' is-active' : '');
        chip.textContent = tag.name;
        chip.addEventListener('click', () => setTag(tag.id === tagId ? null : tag.id));
        host.appendChild(chip);
    }
}

function syncUrl() {
    const url = new URL(location.href);
    url.searchParams.set('view', view);
    if (tagId) { url.searchParams.set('tagId', String(tagId)); } else { url.searchParams.delete('tagId'); }
    history.replaceState(null, '', url);
}

function setActiveView() {
    document.querySelectorAll('#views a').forEach(a => {
        a.classList.toggle('is-active', a.dataset.view === view);
    });
}

function setTag(next) {
    tagId = next;
    syncUrl();
    loadTags();
    fetchPage(true);
}

function reloadAll() {
    loadCounts();
    loadContinue();
    fetchPage(true);
}

document.querySelectorAll('#views a').forEach(a => {
    a.addEventListener('click', (e) => {
        e.preventDefault();
        view = a.dataset.view;
        document.getElementById('search').value = '';
        searchTerm = '';
        syncUrl();
        setActiveView();
        fetchPage(true);
    });
});

document.querySelectorAll('#sort input').forEach(input => {
    input.addEventListener('change', () => {
        sort = input.value;
        renderList();
    });
});

let searchTimer = null;
document.getElementById('search').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        searchTerm = e.target.value;
        fetchPage(true);
    }, 300);
});

document.getElementById('load-more').addEventListener('click', () => fetchPage(false));

const addForm = document.getElementById('add-article');
const addToggle = document.getElementById('add-toggle');
addToggle.addEventListener('click', () => {
    const open = addForm.classList.toggle('is-open');
    addToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) document.getElementById('add-url').focus();
});
document.getElementById('add-cancel').addEventListener('click', () => {
    addForm.classList.remove('is-open');
    addToggle.setAttribute('aria-expanded', 'false');
});

addForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const urlInput = document.getElementById('add-url');
    const errorEl = document.getElementById('add-error');
    errorEl.style.display = 'none';
    const res = await fetch(basePath + '/api/articles', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: urlInput.value }),
    });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        errorEl.textContent = data.error || I18N['library.addArticleFailed'];
        errorEl.style.display = 'block';
        return;
    }
    urlInput.value = '';
    addForm.classList.remove('is-open');
    addToggle.setAttribute('aria-expanded', 'false');
    reloadAll();
});

setActiveView();
loadTags();
reloadAll();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
