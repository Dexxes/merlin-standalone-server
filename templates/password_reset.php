<?php $title = 'Passwort zurücksetzen – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<h1>Neues Passwort setzen</h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/password/reset'); ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <label for="password">Neues Passwort</label>
    <input type="password" id="password" name="password" required minlength="8" autofocus>
    <button type="submit">Passwort setzen</button>
</form>
<?php include __DIR__ . '/partials/footer.php'; ?>
