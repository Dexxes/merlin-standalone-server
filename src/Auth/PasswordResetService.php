<?php

declare(strict_types=1);

namespace Merlin\Auth;

use Merlin\Db\ApiTokenRepository;
use Merlin\Db\PasswordResetRepository;
use Merlin\Db\UserRepository;
use Merlin\Mail\MailerInterface;

final class PasswordResetService {
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resetTokens,
        private readonly ApiTokenRepository $apiTokens,
        private readonly PasswordHasher $hasher,
        private readonly MailerInterface $mailer,
        private readonly string $baseUrl,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Verschickt (falls der User existiert und aktiv ist) eine Reset-Mail.
     * Gibt bewusst kein Ergebnis zurück, das verrät, ob die E-Mail bekannt
     * war - der Aufrufer antwortet dem Client immer identisch, damit sich
     * die Existenz von Accounts nicht per Timing/Response erraten lässt.
     */
    public function requestReset(string $email): void {
        $user = $this->users->findByEmail($email);
        if ($user === null || !(bool) $user['is_active']) {
            return;
        }

        $plainText = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + $this->ttlSeconds);
        $this->resetTokens->create((int) $user['id'], hash('sha256', $plainText), $expiresAt);

        $link = rtrim($this->baseUrl, '/') . '/password/reset?token=' . $plainText;
        $this->mailer->send(
            $user['email'],
            'Merlin – Passwort zurücksetzen',
            "Hallo {$user['username']},\n\n"
            . "über folgenden Link kannst du dein Passwort zurücksetzen:\n{$link}\n\n"
            . "Der Link ist " . (int) ($this->ttlSeconds / 60) . " Minuten gültig. "
            . "Falls du das nicht angefordert hast, ignoriere diese E-Mail.\n"
        );
    }

    /**
     * @throws \RuntimeException wenn das Token ungültig/abgelaufen/bereits benutzt ist
     */
    public function resetPassword(string $plainTextToken, string $newPassword): void {
        $tokenHash = hash('sha256', $plainTextToken);
        $resetToken = $this->resetTokens->findValidByHash($tokenHash);
        if ($resetToken === null) {
            throw new \RuntimeException('Invalid or expired token');
        }

        $userId = (int) $resetToken['user_id'];
        $this->users->updatePassword($userId, $this->hasher->hash($newPassword));
        $this->resetTokens->markUsed((int) $resetToken['id']);
        // Ein zurückgesetztes Passwort deutet auf ein kompromittiertes Konto
        // hin - alle bestehenden API-Tokens (Basic-Auth-Zugänge der Clients)
        // werden deshalb ebenfalls entwertet, nicht nur die Web-Session.
        $this->apiTokens->revokeAllForUser($userId);
    }
}
