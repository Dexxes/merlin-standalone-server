<?php $title = $t->t('passwordForgot.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('passwordForgot.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<?php if (!empty($message)): ?>
    <p class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <form method="post" action="<?= url('/password/forgot'); ?>">
        <label for="email"><?= htmlspecialchars($t->t('common.email'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="email" id="email" name="email" required autofocus>
        <button type="submit"><?= htmlspecialchars($t->t('passwordForgot.submit'), ENT_QUOTES, 'UTF-8') ?></button>
    </form>
<?php endif; ?>
<p class="muted"><a href="<?= url('/login'); ?>"><?= htmlspecialchars($t->t('common.backToLogin'), ENT_QUOTES, 'UTF-8') ?></a></p>
<?php include __DIR__ . '/partials/footer.php'; ?>
