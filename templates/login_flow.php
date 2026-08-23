<?php $title = $t->t('login.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<?php if (($state ?? 'form') === 'invalid'): ?>
    <h1><?= htmlspecialchars($t->t('loginFlow.invalidHeading'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="error"><?= htmlspecialchars($t->t('loginFlow.invalidBody'), ENT_QUOTES, 'UTF-8') ?></p>
<?php elseif ($state === 'done'): ?>
    <h1><?= htmlspecialchars($t->t('loginFlow.doneHeading'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="success"><?= htmlspecialchars($t->t('loginFlow.doneBody'), ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
    <h1><?= htmlspecialchars($t->t('loginFlow.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted"><?= htmlspecialchars($t->t('loginFlow.hint'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= url('/login/v2/flow/' . rawurlencode($flowToken)); ?>">
        <label for="username"><?= htmlspecialchars($t->t('common.username'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="username" name="username" required autofocus>

        <label for="password"><?= htmlspecialchars($t->t('common.password'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="password" id="password" name="password" required>

        <button type="submit"><?= htmlspecialchars($t->t('loginFlow.submit'), ENT_QUOTES, 'UTF-8') ?></button>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
