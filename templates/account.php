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
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
