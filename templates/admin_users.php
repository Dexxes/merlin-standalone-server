<?php $title = $t->t('adminUsers.pageTitle'); $layout = 'wide'; include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('adminUsers.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/library'); ?>"><?= htmlspecialchars($t->t('adminUsers.navLibrary'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/account'); ?>"><?= htmlspecialchars($t->t('adminUsers.navMyAccount'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/admin/content-filters'); ?>"><?= htmlspecialchars($t->t('adminUsers.navContentFilters'), ENT_QUOTES, 'UTF-8') ?></a></p>

<label style="margin-top:1.5em;">
    <input type="checkbox" id="allow-registration" style="width:auto;display:inline;margin-right:0.5em;">
    <?= htmlspecialchars($t->t('adminUsers.allowSelfRegistration'), ENT_QUOTES, 'UTF-8') ?>
</label>

<table id="users"><tbody></tbody></table>

<h2 style="font-size:1.1em;margin-top:2em;"><?= htmlspecialchars($t->t('adminUsers.createUserHeading'), ENT_QUOTES, 'UTF-8') ?></h2>
<form id="create-user">
    <label for="new-username"><?= htmlspecialchars($t->t('common.username'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" id="new-username" required>
    <label for="new-email"><?= htmlspecialchars($t->t('common.email'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="email" id="new-email" required>
    <label for="new-password"><?= htmlspecialchars($t->t('adminUsers.initialPassword'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="password" id="new-password" required minlength="8">
    <label for="new-role"><?= htmlspecialchars($t->t('adminUsers.role'), ENT_QUOTES, 'UTF-8') ?></label>
    <select id="new-role">
        <option value="user"><?= htmlspecialchars($t->t('adminUsers.roleUser'), ENT_QUOTES, 'UTF-8') ?></option>
        <option value="admin"><?= htmlspecialchars($t->t('adminUsers.roleAdmin'), ENT_QUOTES, 'UTF-8') ?></option>
    </select>
    <button type="submit"><?= htmlspecialchars($t->t('adminUsers.createSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>

<script>
const I18N = <?= json_encode($t->forJs([
    'adminUsers.statusActive',
    'adminUsers.statusDisabled',
    'adminUsers.disable',
    'adminUsers.enable',
    'adminUsers.delete',
    'adminUsers.confirmDeleteUser',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

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
            `<td>${u.isActive ? I18N['adminUsers.statusActive'] : I18N['adminUsers.statusDisabled']}</td>` +
            `<td class="row-actions">
                <button data-id="${u.id}" data-active="${u.isActive ? 0 : 1}" class="toggle">${u.isActive ? I18N['adminUsers.disable'] : I18N['adminUsers.enable']}</button>
                <button data-id="${u.id}" class="delete">${I18N['adminUsers.delete']}</button>
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
        if (!confirm(I18N['adminUsers.confirmDeleteUser'])) return;
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
