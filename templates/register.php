<?php $title = 'Registrieren – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<h1>Konto erstellen</h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/register'); ?>">
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" required autofocus>

    <label for="email">E-Mail</label>
    <input type="email" id="email" name="email" required>

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required minlength="8">

    <button type="submit">Konto erstellen</button>
</form>
<p class="muted"><a href="<?= url('/login'); ?>">Zurück zur Anmeldung</a></p>
<?php include __DIR__ . '/partials/footer.php'; ?>
