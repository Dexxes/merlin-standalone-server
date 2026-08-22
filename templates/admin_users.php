<?php $title = 'Admin – Merlin'; $layout = 'wide'; include __DIR__ . '/partials/header.php'; ?>
<h1>Benutzerverwaltung</h1>
<p class="muted"><a href="<?= url('/library'); ?>">Leseliste</a> · <a href="<?= url('/account'); ?>">Mein Konto</a> · <a href="<?= url('/admin/content-filters'); ?>">Content-Filter</a></p>

<label style="margin-top:1.5em;">
    <input type="checkbox" id="allow-registration" style="width:auto;display:inline;margin-right:0.5em;">
    Offene Self-Registrierung erlauben
</label>

<table id="users"><tbody></tbody></table>

<h2 style="font-size:1.1em;margin-top:2em;">Neuen Benutzer anlegen</h2>
<form id="create-user">
    <label for="new-username">Benutzername</label>
    <input type="text" id="new-username" required>
    <label for="new-email">E-Mail</label>
    <input type="email" id="new-email" required>
    <label for="new-password">Initialpasswort</label>
    <input type="password" id="new-password" required minlength="8">
    <label for="new-role">Rolle</label>
    <select id="new-role">
        <option value="user">Normaler Benutzer</option>
        <option value="admin">Admin</option>
    </select>
    <button type="submit">Anlegen</button>
</form>

<script>
async function loadSettings() {
    const res = await fetch(basePath + '/admin/settings', { credentials: 'same-origin' });
    const settings = await res.json();
    document.getElementById('allow-registration').checked = !!settings.allowSelfRegistration;
}

document.getElementById('allow-registration').addEventListener('change', async (e) => {
    await fetch(basePath + '/admin/settings', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ allowSelfRegistration: e.target.checked }),
    });
});

async function loadUsers() {
    const res = await fetch(basePath + '/admin/users', { credentials: 'same-origin' });
    const users = await res.json();
    const body = document.querySelector('#users tbody');
    body.innerHTML = '';
    for (const u of users) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${u.username}</td><td class="muted">${u.email}</td><td>${u.role}</td>` +
            `<td>${u.isActive ? 'aktiv' : 'deaktiviert'}</td>` +
            `<td class="row-actions">
                <button data-id="${u.id}" data-active="${u.isActive ? 0 : 1}" class="toggle">${u.isActive ? 'Deaktivieren' : 'Aktivieren'}</button>
                <button data-id="${u.id}" class="delete">Löschen</button>
            </td>`;
        body.appendChild(tr);
    }
    body.querySelectorAll('.toggle').forEach(btn => btn.addEventListener('click', async () => {
        await fetch(basePath + '/admin/users/' + btn.dataset.id, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ isActive: btn.dataset.active === '1' }),
        });
        loadUsers();
    }));
    body.querySelectorAll('.delete').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Benutzer wirklich löschen? Alle Artikel gehen verloren.')) return;
        await fetch(basePath + '/admin/users/' + btn.dataset.id, { method: 'DELETE', credentials: 'same-origin' });
        loadUsers();
    }));
}

document.getElementById('create-user').addEventListener('submit', async (e) => {
    e.preventDefault();
    await fetch(basePath + '/admin/users', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            username: document.getElementById('new-username').value,
            email: document.getElementById('new-email').value,
            password: document.getElementById('new-password').value,
            role: document.getElementById('new-role').value,
        }),
    });
    e.target.reset();
    loadUsers();
});

loadSettings();
loadUsers();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
