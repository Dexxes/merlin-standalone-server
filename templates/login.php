<?php $title = $t->t('login.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('login.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/login'); ?>">
    <label for="username"><?= htmlspecialchars($t->t('common.username'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" id="username" name="username" required autofocus>

    <label for="password"><?= htmlspecialchars($t->t('common.password'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="password" id="password" name="password" required>

    <button type="submit"><?= htmlspecialchars($t->t('login.submit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<p class="muted">
    <a href="<?= url('/password/forgot'); ?>"><?= htmlspecialchars($t->t('login.forgotPassword'), ENT_QUOTES, 'UTF-8') ?></a>
    <?php if (!empty($allowSelfRegistration)): ?>
        · <a href="<?= url('/register'); ?>"><?= htmlspecialchars($t->t('login.createAccount'), ENT_QUOTES, 'UTF-8') ?></a>
    <?php endif; ?>
</p>
<?php include __DIR__ . '/partials/footer.php'; ?>
