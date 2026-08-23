<p class="muted lang-switch">
    <a href="<?= url('/lang/de?return=' . rawurlencode($requestPath)) ?>"<?= $t->locale() === 'de' ? ' class="lang-switch__active"' : '' ?>>Deutsch</a>
    ·
    <a href="<?= url('/lang/en?return=' . rawurlencode($requestPath)) ?>"<?= $t->locale() === 'en' ? ' class="lang-switch__active"' : '' ?>>English</a>
</p>
</body>
</html>
