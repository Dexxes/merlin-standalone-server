<?php $title = 'Anmelden – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<h1>Anmelden</h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/login'); ?>">
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" required autofocus>

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Anmelden</button>
</form>
<p class="muted">
    <a href="<?= url('/password/forgot'); ?>">Passwort vergessen?</a>
    <?php if (!empty($allowSelfRegistration)): ?>
        · <a href="<?= url('/register'); ?>">Konto erstellen</a>
    <?php endif; ?>
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
