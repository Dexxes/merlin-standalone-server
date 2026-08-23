<?php $title = $t->t('passwordReset.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('passwordReset.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/password/reset'); ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <label for="password"><?= htmlspecialchars($t->t('passwordReset.newPassword'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="password" id="password" name="password" required minlength="8" autofocus>
    <button type="submit"><?= htmlspecialchars($t->t('passwordReset.submit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<?php include __DIR__ . '/partials/footer.php'; ?>
