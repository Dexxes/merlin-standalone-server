<?php $title = $t->t('register.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('register.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<?php if (!empty($error)): ?>
    <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="post" action="<?= url('/register'); ?>">
    <label for="username"><?= htmlspecialchars($t->t('common.username'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" id="username" name="username" required autofocus>

    <label for="email"><?= htmlspecialchars($t->t('common.email'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="email" id="email" name="email" required>

    <label for="password"><?= htmlspecialchars($t->t('common.password'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="password" id="password" name="password" required minlength="8">

    <button type="submit"><?= htmlspecialchars($t->t('register.submit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<p class="muted"><a href="<?= url('/login'); ?>"><?= htmlspecialchars($t->t('common.backToLogin'), ENT_QUOTES, 'UTF-8') ?></a></p>
<?php include __DIR__ . '/partials/footer.php'; ?>
