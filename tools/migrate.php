<?php

declare(strict_types=1);

use Merlin\App;
use Merlin\Migration\MigrationRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new App();
$runner = new MigrationRunner($app->db());

$applied = $runner->migrate();

if ($applied === []) {
    fwrite(STDOUT, "Keine neuen Migrationen.\n");
    exit(0);
}

foreach ($applied as $version) {
    fwrite(STDOUT, "Angewandt: {$version}\n");
}
