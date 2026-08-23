<?php $title = $t->t('contentFilters.pageTitle'); $layout = 'wide'; include __DIR__ . '/partials/header.php'; ?>

<h1><?= htmlspecialchars($t->t('adminCf.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/admin') ?>"><?= htmlspecialchars($t->t('adminCf.navUserManagement'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/library') ?>"><?= htmlspecialchars($t->t('adminCf.navLibrary'), ENT_QUOTES, 'UTF-8') ?></a></p>
<p class="muted"><?= htmlspecialchars($t->t('adminCf.intro'), ENT_QUOTES, 'UTF-8') ?></p>

<div id="cf-layout">
    <div id="cf-list">
        <table><tbody id="cf-list-body"></tbody></table>
        <div id="cf-new">
            <input type="text" id="cf-new-domain" placeholder="<?= htmlspecialchars($t->t('contentFilters.domainPlaceholder'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" id="cf-new-btn"><?= htmlspecialchars($t->t('contentFilters.editButton'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>

    <div id="cf-detail" style="display:none;">
        <h2 id="cf-detail-domain" style="font-size:1.1em;"></h2>

        <label><?= htmlspecialchars($t->t('adminCf.bundleLabel'), ENT_QUOTES, 'UTF-8') ?></label>
        <pre id="cf-bundle">–</pre>

        <label for="cf-custom"><?= htmlspecialchars($t->t('adminCf.customRulesLabel'), ENT_QUOTES, 'UTF-8') ?></label>
        <textarea id="cf-custom" spellcheck="false"></textarea>
        <p id="cf-errors" class="error" style="display:none;"></p>

        <div id="cf-actions">
            <button type="button" id="cf-save"><?= htmlspecialchars($t->t('contentFilters.save'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" id="cf-delete"><?= htmlspecialchars($t->t('contentFilters.delete'), ENT_QUOTES, 'UTF-8') ?></button>
            <a id="cf-export" href="#"><?= htmlspecialchars($t->t('adminCf.exportLink'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <h3 style="font-size:1em;margin-top:1.5em;"><?= htmlspecialchars($t->t('contentFilters.testRunHeading'), ENT_QUOTES, 'UTF-8') ?></h3>
        <form id="cf-test-form" style="display:flex;gap:0.4em;">
            <input type="url" id="cf-test-url" placeholder="https://…" required style="flex:1;">
            <button type="submit"><?= htmlspecialchars($t->t('contentFilters.testSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <div id="cf-test-result" style="margin-top:1em;"></div>
    </div>
</div>

<script>
const I18N = <?= json_encode($t->forJs([
    'contentFilters.badgeBundle',
    'adminCf.badgeCustom',
    'adminCf.userOverrideBadge',
    'adminCf.noBundle',
    'adminCf.confirmDeleteCustom',
    'contentFilters.lineLabel',
    'contentFilters.testError',
    'contentFilters.noTitle',
    'contentFilters.testSummary',
    'contentFilters.colSection',
    'contentFilters.colElement',
    'contentFilters.colOrigin',
    'contentFilters.colAttributes',
    'contentFilters.colMatches',
    'contentFilters.colError',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

let filters = [];
let currentDomain = null;

async function loadFilters() {
    const res = await fetch(basePath + '/api/admin/content-filters', { credentials: 'same-origin' });
    const data = await res.json();
    filters = data.filters || [];
    renderList();
}

function renderList() {
    const body = document.getElementById('cf-list-body');
    body.innerHTML = '';
    for (const f of filters) {
        const tr = document.createElement('tr');
        tr.className = f.domain === currentDomain ? 'active' : '';
        tr.dataset.domain = f.domain;
        const badges = [
            f.hasBundle ? `<span class="badge">${I18N['contentFilters.badgeBundle']}</span>` : '',
            f.hasCustom ? `<span class="badge">${I18N['adminCf.badgeCustom']}</span>` : '',
            f.userOverrideCount > 0 ? `<span class="badge">${I18N['adminCf.userOverrideBadge'].replace('{count}', f.userOverrideCount)}</span>` : '',
        ].join('');
        tr.innerHTML = `<td>${f.domain}${badges}</td>`;
        tr.addEventListener('click', () => selectDomain(f.domain));
        body.appendChild(tr);
    }
}

async function selectDomain(domain) {
    currentDomain = domain;
    renderList();

    const res = await fetch(basePath + '/api/admin/content-filters/' + encodeURIComponent(domain), { credentials: 'same-origin' });
    const detail = document.getElementById('cf-detail');
    detail.style.display = 'block';
    document.getElementById('cf-detail-domain').textContent = domain;
    document.getElementById('cf-test-result').innerHTML = '';
    document.getElementById('cf-errors').style.display = 'none';
    document.getElementById('cf-export').href = basePath + '/api/admin/content-filters/' + encodeURIComponent(domain) + '/export';

    if (res.status === 404) {
        document.getElementById('cf-bundle').textContent = I18N['adminCf.noBundle'];
        document.getElementById('cf-custom').value = `<domain name="${domain}">\n\n</domain>\n`;
        return;
    }

    const data = await res.json();
    document.getElementById('cf-bundle').textContent = data.bundle || I18N['adminCf.noBundle'];
    document.getElementById('cf-custom').value = data.custom || `<domain name="${domain}">\n\n</domain>\n`;
}

document.getElementById('cf-new-btn').addEventListener('click', () => {
    const domain = document.getElementById('cf-new-domain').value.trim().toLowerCase();
    if (!domain) return;
    selectDomain(domain);
});

document.getElementById('cf-save').addEventListener('click', async () => {
    const xml = document.getElementById('cf-custom').value;
    const res = await fetch(basePath + '/api/admin/content-filters/' + encodeURIComponent(currentDomain), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ xml }),
    });
    const data = await res.json();
    const errEl = document.getElementById('cf-errors');
    if (!res.ok) {
        errEl.style.display = 'block';
        errEl.textContent = (data.errors || []).map(e => (e.line ? `${I18N['contentFilters.lineLabel']} ${e.line}: ` : '') + e.message).join(' / ') || data.message;
        return;
    }
    errEl.style.display = 'none';
    await loadFilters();
    renderList();
});

document.getElementById('cf-delete').addEventListener('click', async () => {
    if (!currentDomain || !confirm(I18N['adminCf.confirmDeleteCustom'].replace('{domain}', currentDomain))) return;
    await fetch(basePath + '/api/admin/content-filters/' + encodeURIComponent(currentDomain), {
        method: 'DELETE',
        credentials: 'same-origin',
    });
    await loadFilters();
    selectDomain(currentDomain);
});

document.getElementById('cf-test-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = document.getElementById('cf-test-url').value;
    const xml = document.getElementById('cf-custom').value;
    const res = await fetch(basePath + '/api/admin/content-filters/' + encodeURIComponent(currentDomain) + '/test', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url, xml }),
    });
    const data = await res.json();
    const out = document.getElementById('cf-test-result');

    if (!res.ok) {
        out.innerHTML = `<p class="error">${data.message || I18N['contentFilters.testError']}</p>`;
        return;
    }

    const rows = (data.trace || []).map(t => `<tr>
        <td>${t.section}</td><td>${t.element}</td><td class="muted">${t.origin || 'bundle'}</td>
        <td>${Object.entries(t.attributes || {}).map(([k, v]) => `${k}="${v}"`).join(' ')}</td>
        <td>${t.matches}</td><td class="error">${t.error || ''}</td>
    </tr>`).join('');

    const summary = I18N['contentFilters.testSummary']
        .replace('{rules}', data.summary.rules)
        .replace('{misses}', data.summary.misses)
        .replace('{errors}', data.summary.errors);

    out.innerHTML = `
        <p><strong>${data.result.title || I18N['contentFilters.noTitle']}</strong> – ${summary}</p>
        <table><thead><tr><th>${I18N['contentFilters.colSection']}</th><th>${I18N['contentFilters.colElement']}</th><th>${I18N['contentFilters.colOrigin']}</th><th>${I18N['contentFilters.colAttributes']}</th><th>${I18N['contentFilters.colMatches']}</th><th>${I18N['contentFilters.colError']}</th></tr></thead>
        <tbody id="cf-trace">${rows}</tbody></table>
    `;
});

loadFilters();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
