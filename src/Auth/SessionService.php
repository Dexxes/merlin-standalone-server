<?php

declare(strict_types=1);

namespace Merlin\Auth;

/**
 * PHP-Session für die HTML-Seiten (Login, Account, Admin-UI). Die JSON-API
 * unter /api/* nutzt ausschließlich Basic Auth mit API-Tokens (siehe
 * ApiTokenService) - Sessions spielen dort keine Rolle.
 */
final class SessionService {
    public function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // Secure-Flag nur setzen, wenn der Request tatsächlich über HTTPS
                // kam - sonst würde das Cookie in einem reinen HTTP-Dev-Setup
                // (php -S) nie gesendet und der Login liefe endlos ins Leere.
                // X-Forwarded-Proto zählt mit, weil Synology-Reverse-Proxies TLS
                // typischerweise terminieren und intern per HTTP weiterreichen.
                'cookie_secure' => ($_SERVER['HTTPS'] ?? '') !== ''
                    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            ]);
        }
    }

    public function login(int $userId): void {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public function logout(): void {
        $this->start();
        $_SESSION = [];
        session_destroy();
    }

    public function currentUserId(): ?int {
        $this->start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Merkt sich pro Browser-Session, welche passwortgeschützten Share-Links
     * bereits entsperrt wurden (siehe PublicShareController::unlock()) -
     * analog zu Nextclouds eigenem Datei-Freigabe-Passwortschutz.
     */
    public function hasUnlockedShareToken(string $token): bool {
        $this->start();
        $unlocked = $_SESSION['unlocked_share_tokens'] ?? [];
        return is_array($unlocked) && in_array($token, $unlocked, true);
    }

    public function markShareTokenUnlocked(string $token): void {
        $this->start();
        $unlocked = $_SESSION['unlocked_share_tokens'] ?? [];
        if (!is_array($unlocked)) {
            $unlocked = [];
        }
        $unlocked[] = $token;
        $_SESSION['unlocked_share_tokens'] = array_values(array_unique($unlocked));
    }
}
