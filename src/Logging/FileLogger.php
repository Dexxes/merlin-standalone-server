<?php

declare(strict_types=1);

namespace Merlin\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimaler PSR-3-Logger für den Standalone-Server: schreibt eine Zeile pro
 * Log-Eintrag in eine Datei (kein Log-Rotation/Aggregations-Framework nötig,
 * das kann bei Bedarf per logrotate auf dem Server erledigt werden).
 */
final class FileLogger extends AbstractLogger {
    public function __construct(private readonly string $logFile) {
    }

    public function log($level, string|Stringable $message, array $context = []): void {
        $line = sprintf(
            '[%s] %s: %s%s' . PHP_EOL,
            gmdate('c'),
            strtoupper((string) $level),
            (string) $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
