<?php $title = 'Anmelden – Merlin'; include __DIR__ . '/partials/header.php'; ?>
<?php if (($state ?? 'form') === 'invalid'): ?>
    <h1>Link ungültig</h1>
    <p class="error">Dieser Anmelde-Link ist abgelaufen oder wurde bereits verwendet. Bitte den Anmeldevorgang in der App bzw. Erweiterung erneut starten.</p>
<?php elseif ($state === 'done'): ?>
    <h1>Erfolgreich verbunden</h1>
    <p class="success">Merlin ist jetzt mit deinem Konto verbunden. Du kannst dieses Fenster jetzt schließen.</p>
<?php else: ?>
    <h1>Bei Merlin anmelden</h1>
    <p class="muted">Diese Anmeldung verbindet eine App oder Erweiterung mit deinem Merlin-Konto.</p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= url('/login/v2/flow/' . rawurlencode($flowToken)); ?>">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" required autofocus>

        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Verbinden</button>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
