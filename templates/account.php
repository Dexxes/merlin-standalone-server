<?php $title = $t->t('account.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('account.heading', ['username' => $username ?? '']), ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/library'); ?>"><?= htmlspecialchars($t->t('account.navLibrary'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/account/content-filters'); ?>"><?= htmlspecialchars($t->t('account.navContentFilters'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/logout'); ?>"><?= htmlspecialchars($t->t('account.navLogout'), ENT_QUOTES, 'UTF-8') ?></a><?php if (!empty($isAdmin)): ?> · <a href="<?= url('/admin'); ?>"><?= htmlspecialchars($t->t('account.navAdmin'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?></p>

<h2 style="font-size:1.1em;margin-top:2em;"><?= htmlspecialchars($t->t('account.apiTokensHeading'), ENT_QUOTES, 'UTF-8') ?></h2>
<p class="muted"><?= htmlspecialchars($t->t('account.apiTokensHint'), ENT_QUOTES, 'UTF-8') ?></p>
<table id="tokens"><tbody></tbody></table>

<form id="create-token" style="margin-top:1.5em;">
    <label for="token-name"><?= htmlspecialchars($t->t('account.deviceNameLabel'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" id="token-name" name="name" placeholder="<?= htmlspecialchars($t->t('account.deviceNamePlaceholder'), ENT_QUOTES, 'UTF-8') ?>" required>
    <button type="submit"><?= htmlspecialchars($t->t('account.createTokenSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<p id="new-token" class="success" style="display:none;"></p>

<h2 style="font-size:1.1em;margin-top:2em;"><?= htmlspecialchars($t->t('account.paywallHeading'), ENT_QUOTES, 'UTF-8') ?></h2>
<p class="muted"><?= htmlspecialchars($t->t('account.paywallHint'), ENT_QUOTES, 'UTF-8') ?></p>
<p id="site-credentials-cipher-error" class="error" style="display:none;"><?= $t->t('account.cipherNotConfigured') ?></p>
<table id="site-credentials"><tbody></tbody></table>
<p id="site-credentials-empty" class="muted" style="display:none;"><?= htmlspecialchars($t->t('account.noPaywallSites'), ENT_QUOTES, 'UTF-8') ?></p>

<form id="credential-form" style="margin-top:1.5em;display:none;">
    <input type="hidden" id="credential-domain">
    <label id="credential-form-label" for="credential-username"></label>
    <input type="text" id="credential-username" name="username" placeholder="<?= htmlspecialchars($t->t('account.emailOrUsernamePlaceholder'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
    <label for="credential-password"><?= htmlspecialchars($t->t('common.password'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="password" id="credential-password" name="password" autocomplete="current-password" required>
    <button type="submit"><?= htmlspecialchars($t->t('account.saveAndLogin'), ENT_QUOTES, 'UTF-8') ?></button>
    <button type="button" id="credential-cancel"><?= htmlspecialchars($t->t('account.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<p id="credential-status" class="muted" style="display:none;"></p>

<script>
const I18N = <?= json_encode($t->forJs([
    'account.neverUsed',
    'account.revoke',
    'account.newTokenPrefix',
    'account.statusOk',
    'account.statusInvalidCredentials',
    'account.statusLoginFlowBroken',
    'account.statusPending',
    'account.statusNotConnected',
    'account.updateLogin',
    'account.connect',
    'account.remove',
    'account.emailOrUsernameForLabel',
    'account.connectedNotice',
    'account.loginFailedGeneric',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

async function loadTokens() {
    const res = await fetch(basePath + '/account/tokens', { credentials: 'same-origin' });
    const tokens = await res.json();
    const body = document.querySelector('#tokens tbody');
    body.innerHTML = '';
    for (const t of tokens) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${t.name}</td><td class="muted">${t.lastUsedAt ?? I18N['account.neverUsed']}</td><td class="row-actions"><button data-id="${t.id}" class="revoke">${I18N['account.revoke']}</button></td>`;
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
    el.textContent = I18N['account.newTokenPrefix'].replace('{token}', data.plainText);
    document.getElementById('token-name').value = '';
    loadTokens();
});

loadTokens();

const STATUS_LABELS = {
    ok: I18N['account.statusOk'],
    invalid_credentials: I18N['account.statusInvalidCredentials'],
    login_flow_broken: I18N['account.statusLoginFlowBroken'],
    pending: I18N['account.statusPending'],
};

let cipherConfigured = true;

async function loadSiteCredentials() {
    const res = await fetch(basePath + '/api/user/site-credentials', { credentials: 'same-origin' });
    const data = await res.json();
    const body = document.querySelector('#site-credentials tbody');
    body.innerHTML = '';

    // Fehlt der credential_cipher_key auf dem Server, kann kein Login
    // gespeichert werden - klare Meldung statt eines Fehlers erst beim
    // Absenden des Formulars (siehe SiteCredentialController::update()).
    cipherConfigured = data.cipherConfigured !== false;
    document.getElementById('site-credentials-cipher-error').style.display = cipherConfigured ? 'none' : 'block';

    const byDomain = new Map((data.credentials || []).map(c => [c.domain, c]));
    const domains = [...(data.availableDomains || [])].sort((a, b) => a.localeCompare(b));

    document.getElementById('site-credentials-empty').style.display = domains.length ? 'none' : 'block';
    document.getElementById('site-credentials').style.display = domains.length ? 'table' : 'none';

    for (const domain of domains) {
        const cred = byDomain.get(domain) || null;
        const statusLabel = cred ? (STATUS_LABELS[cred.status] || cred.status) : I18N['account.statusNotConnected'];
        const statusClass = cred && cred.status === 'ok' ? 'badge badge--ok' : (cred ? 'badge badge--error' : 'badge');
        const connectDisabled = cipherConfigured ? '' : 'disabled';
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${domain}</td><td><span class="${statusClass}">${statusLabel}</span></td>` +
            `<td class="row-actions">` +
            `<button data-domain="${domain}" class="connect" ${connectDisabled}>${cred ? I18N['account.updateLogin'] : I18N['account.connect']}</button>` +
            (cred ? `<button data-domain="${domain}" class="remove-credential">${I18N['account.remove']}</button>` : '') +
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
    if (!cipherConfigured) {
        return;
    }
    document.getElementById('credential-domain').value = domain;
    document.getElementById('credential-form-label').textContent = I18N['account.emailOrUsernameForLabel'].replace('{domain}', domain);
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
        status.textContent = I18N['account.connectedNotice'].replace('{domain}', domain);
        document.getElementById('credential-form').style.display = 'none';
    } else {
        status.className = 'error';
        status.textContent = data.message || I18N['account.loginFailedGeneric'];
    }
    loadSiteCredentials();
});

loadSiteCredentials();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
