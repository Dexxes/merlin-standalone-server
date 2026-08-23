<?php $title = $t->t('contentFilters.pageTitle'); $layout = 'wide'; include __DIR__ . '/partials/header.php'; ?>

<h1><?= htmlspecialchars($t->t('personalCf.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/account') ?>"><?= htmlspecialchars($t->t('personalCf.navMyAccount'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/library') ?>"><?= htmlspecialchars($t->t('personalCf.navLibrary'), ENT_QUOTES, 'UTF-8') ?></a></p>
<p class="muted"><?= htmlspecialchars($t->t('personalCf.intro'), ENT_QUOTES, 'UTF-8') ?></p>

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

        <label><?= htmlspecialchars($t->t('personalCf.referenceLabel'), ENT_QUOTES, 'UTF-8') ?></label>
        <pre id="cf-reference">–</pre>

        <label for="cf-own"><?= htmlspecialchars($t->t('personalCf.ownRulesLabel'), ENT_QUOTES, 'UTF-8') ?></label>
        <textarea id="cf-own" spellcheck="false"></textarea>
        <p id="cf-errors" class="error" style="display:none;"></p>

        <div id="cf-actions">
            <button type="button" id="cf-save"><?= htmlspecialchars($t->t('contentFilters.save'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" id="cf-delete"><?= htmlspecialchars($t->t('contentFilters.delete'), ENT_QUOTES, 'UTF-8') ?></button>
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
    'contentFilters.badgeAdmin',
    'personalCf.badgeOwnOverride',
    'personalCf.noReference',
    'personalCf.confirmDeleteOwn',
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

let domains = [];
let currentDomain = null;

async function loadDomains() {
    const res = await fetch(basePath + '/api/user/content-filters', { credentials: 'same-origin' });
    const data = await res.json();
    domains = data.domains || [];
    renderList();
}

function renderList() {
    const body = document.getElementById('cf-list-body');
    body.innerHTML = '';
    for (const d of domains) {
        const tr = document.createElement('tr');
        tr.className = d.domain === currentDomain ? 'active' : '';
        const badges = [
            d.hasBundle ? `<span class="badge">${I18N['contentFilters.badgeBundle']}</span>` : '',
            d.hasAdminCustom ? `<span class="badge">${I18N['contentFilters.badgeAdmin']}</span>` : '',
            d.hasOwnOverride ? `<span class="badge">${I18N['personalCf.badgeOwnOverride']}</span>` : '',
        ].join('');
        tr.innerHTML = `<td>${d.domain}${badges}</td>`;
        tr.addEventListener('click', () => selectDomain(d.domain));
        body.appendChild(tr);
    }
}

async function selectDomain(domain) {
    currentDomain = domain;
    renderList();

    const res = await fetch(basePath + '/api/user/content-filters/' + encodeURIComponent(domain), { credentials: 'same-origin' });
    const detail = document.getElementById('cf-detail');
    detail.style.display = 'block';
    document.getElementById('cf-detail-domain').textContent = domain;
    document.getElementById('cf-test-result').innerHTML = '';
    document.getElementById('cf-errors').style.display = 'none';

    if (res.status === 404) {
        document.getElementById('cf-reference').textContent = I18N['personalCf.noReference'];
        document.getElementById('cf-own').value = `<domain name="${domain}">\n\n</domain>\n`;
        return;
    }

    const data = await res.json();
    document.getElementById('cf-reference').textContent = data.reference || I18N['personalCf.noReference'];
    document.getElementById('cf-own').value = data.own || `<domain name="${domain}">\n\n</domain>\n`;
}

document.getElementById('cf-new-btn').addEventListener('click', () => {
    const domain = document.getElementById('cf-new-domain').value.trim().toLowerCase();
    if (!domain) return;
    selectDomain(domain);
});

document.getElementById('cf-save').addEventListener('click', async () => {
    const xml = document.getElementById('cf-own').value;
    const res = await fetch(basePath + '/api/user/content-filters/' + encodeURIComponent(currentDomain), {
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
    await loadDomains();
    renderList();
});

document.getElementById('cf-delete').addEventListener('click', async () => {
    if (!currentDomain || !confirm(I18N['personalCf.confirmDeleteOwn'].replace('{domain}', currentDomain))) return;
    await fetch(basePath + '/api/user/content-filters/' + encodeURIComponent(currentDomain), {
        method: 'DELETE',
        credentials: 'same-origin',
    });
    await loadDomains();
    selectDomain(currentDomain);
});

document.getElementById('cf-test-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = document.getElementById('cf-test-url').value;
    const xml = document.getElementById('cf-own').value;
    const res = await fetch(basePath + '/api/user/content-filters/' + encodeURIComponent(currentDomain) + '/test', {
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
        <tbody>${rows}</tbody></table>
    `;
});

loadDomains();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
