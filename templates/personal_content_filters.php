<?php $title = 'Content-Filter – Merlin'; $layout = 'wide'; include __DIR__ . '/partials/header.php'; ?>

<h1>Content-Filter (persönlich)</h1>
<p class="muted"><a href="<?= url('/account') ?>">Mein Konto</a> · <a href="<?= url('/library') ?>">Leseliste</a></p>
<p class="muted">Eigene, private Regeln oben auf Bundle- und (falls vorhanden) instanzweiten Admin-Regeln je Domain. Diese Overrides sind nur für dein eigenes Konto wirksam.</p>

<div id="cf-layout">
    <div id="cf-list">
        <table><tbody id="cf-list-body"></tbody></table>
        <div id="cf-new">
            <input type="text" id="cf-new-domain" placeholder="z.B. example.com">
            <button type="button" id="cf-new-btn">Bearbeiten</button>
        </div>
    </div>

    <div id="cf-detail" style="display:none;">
        <h2 id="cf-detail-domain" style="font-size:1.1em;"></h2>

        <label>Referenz (Bundle + instanzweite Admin-Regeln, read-only)</label>
        <pre id="cf-reference">–</pre>

        <label for="cf-own">Eigene Regeln (nur für dich, überschreibt/ergänzt die Referenz)</label>
        <textarea id="cf-own" spellcheck="false"></textarea>
        <p id="cf-errors" class="error" style="display:none;"></p>

        <div id="cf-actions">
            <button type="button" id="cf-save">Speichern</button>
            <button type="button" id="cf-delete">Löschen</button>
        </div>

        <h3 style="font-size:1em;margin-top:1.5em;">Testlauf</h3>
        <form id="cf-test-form" style="display:flex;gap:0.4em;">
            <input type="url" id="cf-test-url" placeholder="https://…" required style="flex:1;">
            <button type="submit">Testen (ungespeicherter Stand)</button>
        </form>
        <div id="cf-test-result" style="margin-top:1em;"></div>
    </div>
</div>

<script>
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
            d.hasBundle ? '<span class="badge">Bundle</span>' : '',
            d.hasAdminCustom ? '<span class="badge">Admin</span>' : '',
            d.hasOwnOverride ? '<span class="badge">Eigener Override</span>' : '',
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
        document.getElementById('cf-reference').textContent = '(kein Bundle-/Admin-Filter für diese Domain)';
        document.getElementById('cf-own').value = `<domain name="${domain}">\n\n</domain>\n`;
        return;
    }

    const data = await res.json();
    document.getElementById('cf-reference').textContent = data.reference || '(kein Bundle-/Admin-Filter für diese Domain)';
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
        errEl.textContent = (data.errors || []).map(e => (e.line ? `Zeile ${e.line}: ` : '') + e.message).join(' / ') || data.message;
        return;
    }
    errEl.style.display = 'none';
    await loadDomains();
    renderList();
});

document.getElementById('cf-delete').addEventListener('click', async () => {
    if (!currentDomain || !confirm('Eigenen Override für "' + currentDomain + '" wirklich löschen?')) return;
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
        out.innerHTML = `<p class="error">${data.message || 'Fehler beim Testlauf'}</p>`;
        return;
    }

    const rows = (data.trace || []).map(t => `<tr>
        <td>${t.section}</td><td>${t.element}</td><td class="muted">${t.origin || 'bundle'}</td>
        <td>${Object.entries(t.attributes || {}).map(([k, v]) => `${k}="${v}"`).join(' ')}</td>
        <td>${t.matches}</td><td class="error">${t.error || ''}</td>
    </tr>`).join('');

    out.innerHTML = `
        <p><strong>${data.result.title || '(kein Titel)'}</strong> – ${data.summary.rules} Regeln,
        ${data.summary.misses} ohne Treffer, ${data.summary.errors} Fehler</p>
        <table><thead><tr><th>Sektion</th><th>Element</th><th>Herkunft</th><th>Attribute</th><th>Treffer</th><th>Fehler</th></tr></thead>
        <tbody>${rows}</tbody></table>
    `;
});

loadDomains();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
