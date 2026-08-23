<?php $title = 'Konto – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<h1>Konto: <?= htmlspecialchars($username ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/library'); ?>">Meine Leseliste</a> · <a href="<?= url('/account/content-filters'); ?>">Content-Filter</a> · <a href="<?= url('/logout'); ?>">Abmelden</a><?php if (!empty($isAdmin)): ?> · <a href="<?= url('/admin'); ?>">Admin-Bereich</a><?php endif; ?></p>

<h2 style="font-size:1.1em;margin-top:2em;">API-Tokens</h2>
<p class="muted">Für iOS/Android/Browser-Erweiterungen: Benutzername + Token statt Passwort verwenden.</p>
<table id="tokens"><tbody></tbody></table>

<form id="create-token" style="margin-top:1.5em;">
    <label for="token-name">Gerätename</label>
    <input type="text" id="token-name" name="name" placeholder="z.B. iPhone von Julian" required>
    <button type="submit">Token erzeugen</button>
</form>
<p id="new-token" class="success" style="display:none;"></p>

<h2 style="font-size:1.1em;margin-top:2em;">Paywall-Abos</h2>
<p class="muted">Für Websites mit Abo-Paywall (z. B. Tagesspiegel Plus): eigene Zugangsdaten hinterlegen, damit Merlin auch bezahlpflichtige Artikel vollständig speichern kann. Das Passwort wird verschlüsselt gespeichert und nur zum Einloggen verwendet.</p>
<table id="site-credentials"><tbody></tbody></table>
<p id="site-credentials-empty" class="muted" style="display:none;">Keine Websites mit Paywall-Login-Unterstützung verfügbar.</p>

<form id="credential-form" style="margin-top:1.5em;display:none;">
    <input type="hidden" id="credential-domain">
    <label id="credential-form-label" for="credential-username"></label>
    <input type="text" id="credential-username" name="username" placeholder="E-Mail oder Benutzername" autocomplete="username" required>
    <label for="credential-password">Passwort</label>
    <input type="password" id="credential-password" name="password" autocomplete="current-password" required>
    <button type="submit">Speichern und einloggen</button>
    <button type="button" id="credential-cancel">Abbrechen</button>
</form>
<p id="credential-status" class="muted" style="display:none;"></p>

<script>
async function loadTokens() {
    const res = await fetch(basePath + '/account/tokens', { credentials: 'same-origin' });
    const tokens = await res.json();
    const body = document.querySelector('#tokens tbody');
    body.innerHTML = '';
    for (const t of tokens) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${t.name}</td><td class="muted">${t.lastUsedAt ?? 'nie benutzt'}</td><td class="row-actions"><button data-id="${t.id}" class="revoke">Widerrufen</button></td>`;
        body.appendChild(tr);
    }
    body.querySelectorAll('.revoke').forEach(btn => btn.addEventListener('click', async () => {
        await fetch(basePath + '/account/tokens/' + btn.dataset.id, { method: 'DELETE', credentials: 'same-origin' });
        loadTokens();
    }));
}

document.getElementById('create-token').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('token-name').value;
    const res = await fetch(basePath + '/account/tokens', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
    });
    const data = await res.json();
    const el = document.getElementById('new-token');
    el.style.display = 'block';
    el.textContent = 'Neues Token (nur jetzt sichtbar): ' + data.plainText;
    document.getElementById('token-name').value = '';
    loadTokens();
});

loadTokens();

const STATUS_LABELS = {
    ok: 'Verbunden',
    invalid_credentials: 'Login fehlgeschlagen – Passwort prüfen',
    login_flow_broken: 'Login vorübergehend nicht verfügbar',
    pending: 'Noch nicht geprüft',
};

async function loadSiteCredentials() {
    const res = await fetch(basePath + '/api/user/site-credentials', { credentials: 'same-origin' });
    const data = await res.json();
    const body = document.querySelector('#site-credentials tbody');
    body.innerHTML = '';

    const byDomain = new Map((data.credentials || []).map(c => [c.domain, c]));
    const domains = [...(data.availableDomains || [])].sort((a, b) => a.localeCompare(b));

    document.getElementById('site-credentials-empty').style.display = domains.length ? 'none' : 'block';
    document.getElementById('site-credentials').style.display = domains.length ? 'table' : 'none';

    for (const domain of domains) {
        const cred = byDomain.get(domain) || null;
        const statusLabel = cred ? (STATUS_LABELS[cred.status] || cred.status) : 'Nicht verbunden';
        const statusClass = cred && cred.status === 'ok' ? 'badge badge--ok' : (cred ? 'badge badge--error' : 'badge');
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${domain}</td><td><span class="${statusClass}">${statusLabel}</span></td>` +
            `<td class="row-actions">` +
            `<button data-domain="${domain}" class="connect">${cred ? 'Login aktualisieren' : 'Verbinden'}</button>` +
            (cred ? `<button data-domain="${domain}" class="remove-credential">Entfernen</button>` : '') +
            `</td>`;
        body.appendChild(tr);
    }

    body.querySelectorAll('.connect').forEach(btn => btn.addEventListener('click', () => openCredentialForm(btn.dataset.domain)));
    body.querySelectorAll('.remove-credential').forEach(btn => btn.addEventListener('click', async () => {
        await fetch(basePath + '/api/user/site-credentials/' + encodeURIComponent(btn.dataset.domain), {
            method: 'DELETE',
            credentials: 'same-origin',
        });
        loadSiteCredentials();
    }));
}

function openCredentialForm(domain) {
    document.getElementById('credential-domain').value = domain;
    document.getElementById('credential-form-label').textContent = 'E-Mail oder Benutzername für ' + domain;
    document.getElementById('credential-username').value = '';
    document.getElementById('credential-password').value = '';
    document.getElementById('credential-status').style.display = 'none';
    document.getElementById('credential-form').style.display = 'block';
}

document.getElementById('credential-cancel').addEventListener('click', () => {
    document.getElementById('credential-form').style.display = 'none';
});

document.getElementById('credential-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const domain = document.getElementById('credential-domain').value;
    const username = document.getElementById('credential-username').value;
    const password = document.getElementById('credential-password').value;
    const status = document.getElementById('credential-status');

    const res = await fetch(basePath + '/api/user/site-credentials/' + encodeURIComponent(domain), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password }),
    });
    const data = await res.json();

    status.style.display = 'block';
    if (res.ok) {
        status.className = 'success';
        status.textContent = 'Verbunden. Merlin verwendet diesen Login ab jetzt für ' + domain + '.';
        document.getElementById('credential-form').style.display = 'none';
    } else {
        status.className = 'error';
        status.textContent = data.message || 'Login fehlgeschlagen.';
    }
    loadSiteCredentials();
});

loadSiteCredentials();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
