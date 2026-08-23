<?php

declare(strict_types=1);

namespace Merlin\Http;

final class Response {
    private static string $basePath = '/';

    public static function setBasePath(string $basePath): void {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function getBasePath(): string {
        return self::$basePath;
    }

    private function __construct(
        private readonly int $status,
        private readonly string $contentType,
        private readonly string $body,
        private readonly array $extraHeaders = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self {
        return new self($status, 'application/json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    public static function html(string $html, int $status = 200, array $extraHeaders = []): self {
        return new self($status, 'text/html; charset=UTF-8', $html, $extraHeaders);
    }

    public static function redirect(string $location, int $status = 303): self {
        // Wenn location mit '/' startet, base_path voranstellen
        if (str_starts_with($location, '/') && self::$basePath !== '') {
            $location = self::$basePath . $location;
        }
        return new self($status, 'text/plain', '', ['Location' => $location]);
    }

    public static function noContent(): self {
        return new self(204, 'text/plain', '');
    }

    /**
     * Datei-Download über Content-Disposition: attachment - genutzt vom
     * HTML-Export (ArticleController) und vom Content-Filter-Export
     * (ContentFilterController).
     */
    public static function download(string $content, string $filename, string $contentType): self {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        return new self(200, $contentType, $content, [
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
        ]);
    }

    public function status(): int {
        return $this->status;
    }

    public function body(): string {
        return $this->body;
    }

    public function send(): void {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->extraHeaders as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
