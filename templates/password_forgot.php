<?php $title = 'Passwort vergessen – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<h1>Passwort vergessen</h1>
<?php if (!empty($message)): ?>
    <p class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <form method="post" action="<?= url('/password/forgot'); ?>">
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" required autofocus>
        <button type="submit">Link zusenden</button>
    </form>
<?php endif; ?>
<p class="muted"><a href="<?= url('/login'); ?>">Zurück zur Anmeldung</a></p>
<?php include __DIR__ . '/partials/footer.php'; ?>
