<!DOCTYPE html>
<html lang="<?= htmlspecialchars($t->locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title ?? 'Merlin', ENT_QUOTES, 'UTF-8') ?></title>
<?php

if (!function_exists('url')) {
    function url(string $path): string {
        $basePath = \Merlin\Http\Response::getBasePath();
        return str_starts_with($path, '/') ? $basePath . $path : $path;
    }
}
?>
<link rel="stylesheet" href="<?= url('/css/merlin.css') ?>">
<script>
    // JS-Pendant für die fetch()-Aufrufe in account.php/admin_users.php/library.php/article_reader.php
    const basePath = '<?= Merlin\Http\Response::getBasePath(); ?>';
</script>
</head>
<body class="<?= htmlspecialchars($layout ?? 'narrow', ENT_QUOTES, 'UTF-8') ?>">
