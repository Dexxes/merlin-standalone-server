<?php

declare(strict_types=1);

namespace Merlin\Auth;

use Merlin\Db\ApiTokenRepository;

/**
 * Ersetzt Nextclouds App-Passwörter: ein zufälliges Token wird dem Client
 * einmalig im Klartext gezeigt, der Server speichert nur den SHA-256-Hash.
 * Übertragung erfolgt wie bisher per HTTP Basic Auth (Username + Token statt
 * Username + NC-App-Passwort), damit sich am Client-seitigen Auth-Schema
 * möglichst wenig ändert.
 */
final class ApiTokenService {
    public function __construct(private readonly ApiTokenRepository $tokens) {
    }

    /**
     * @return array{token: array, plainText: string}
     */
    public function create(int $userId, string $name): array {
        $plainText = bin2hex(random_bytes(32));
        $token = $this->tokens->create($userId, $name, $this->hash($plainText));
        return ['token' => $token, 'plainText' => $plainText];
    }

    /**
     * Prüft Username + Klartext-Token gegen die gespeicherten Hashes und
     * liefert die zugehörige Token-Zeile zurück, oder null.
     */
    public function verify(string $plainText): ?array {
        return $this->tokens->findActiveByHash($this->hash($plainText));
    }

    private function hash(string $plainText): string {
        return hash('sha256', $plainText);
    }
}
