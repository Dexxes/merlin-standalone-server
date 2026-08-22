<?php

declare(strict_types=1);

namespace Merlin\Http;

final class Request {
    /** @var array<string, string> */
    private array $routeParams = [];

    private readonly array $jsonBody;

    /** Vom jeweiligen Auth-Middleware gesetzt: die users-Zeile des angemeldeten Accounts. */
    private ?array $authUser = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly bool $isHttps = false,
    ) {
        $decoded = json_decode($this->rawBody, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
    }

    public static function fromGlobals(string $basePath = '/'): self {
        $fullPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Entfernt den Base-Path vom Anfang der Request-URL, falls vorhanden
        // (z.B. '/tools/merlin/login' → '/login' wenn basePath='/tools/merlin').
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($fullPath, $basePath . '/')) {
            $path = substr($fullPath, strlen($basePath));
        } elseif ($basePath === $fullPath) {
            $path = '/';
        } else {
            $path = $fullPath;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $headers['php-auth-user'] = $_SERVER['PHP_AUTH_USER'];
            $headers['php-auth-pw'] = $_SERVER['PHP_AUTH_PW'] ?? '';
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            // Gleiche Erkennung wie SessionService::start() (cookie_secure): ein
            // Synology-Reverse-Proxy terminiert TLS typischerweise und reicht
            // intern per HTTP weiter, daher zählt X-Forwarded-Proto mit.
            ($_SERVER['HTTPS'] ?? '') !== ''
                || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        );
    }

    public function method(): string {
        return $this->method;
    }

    public function isHttps(): bool {
        return $this->isHttps;
    }

    public function host(): ?string {
        return $this->header('host');
    }

    public function path(): string {
        return $this->path;
    }

    public function setRouteParams(array $params): void {
        $this->routeParams = $params;
    }

    public function routeParam(string $name): ?string {
        return $this->routeParams[$name] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string {
        return isset($this->query[$name]) ? (string) $this->query[$name] : $default;
    }

    public function queryBool(string $name): ?bool {
        $value = $this->query($name);
        if ($value === null) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function queryInt(string $name, ?int $default = null): ?int {
        $value = $this->query($name);
        return $value === null ? $default : (int) $value;
    }

    /** Body-Feld: unterstützt sowohl JSON- als auch Form-Requests (letzteres für einfache HTML-Formulare). */
    public function input(string $name, ?string $default = null): ?string {
        if (array_key_exists($name, $this->jsonBody)) {
            return (string) $this->jsonBody[$name];
        }
        if (isset($_POST[$name])) {
            return (string) $_POST[$name];
        }
        return $default;
    }

    public function inputArray(string $name): array {
        if (isset($this->jsonBody[$name]) && is_array($this->jsonBody[$name])) {
            return $this->jsonBody[$name];
        }
        return [];
    }

    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function setAuthUser(array $user): void {
        $this->authUser = $user;
    }

    public function authUser(): ?array {
        return $this->authUser;
    }

    public function authUserId(): int {
        if ($this->authUser === null) {
            throw new \LogicException('No authenticated user on this request');
        }
        return (int) $this->authUser['id'];
    }

    public function basicAuthCredentials(): ?array {
        $user = $this->header('php-auth-user');
        $pass = $this->header('php-auth-pw');
        if ($user !== null && $pass !== null) {
            return [$user, $pass];
        }

        $authHeader = $this->header('authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                return [$user, $pass];
            }
        }

        return null;
    }
}
