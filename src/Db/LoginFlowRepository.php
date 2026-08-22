<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

/**
 * Persistenz für den Login-Flow-v2-Klon (siehe LoginFlowController) - bildet
 * Nextclouds Login-Flow-v2-Protokoll nach, damit Clients (iOS/Android/
 * Browser-Erweiterungen) denselben Polling-Code gegen merlin-server nutzen
 * können. Jede Zeile ist ein einzelner, zeitlich begrenzter Anmeldeversuch.
 */
final class LoginFlowRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function create(string $flowToken, string $pollToken, string $expiresAt): void {
        $stmt = $this->db->prepare(
            'INSERT INTO login_flow_tokens (flow_token, poll_token, created_at, expires_at)
             VALUES (:flow_token, :poll_token, :created_at, :expires_at)'
        );
        $stmt->execute([
            'flow_token' => $flowToken,
            'poll_token' => $pollToken,
            'created_at' => gmdate('c'),
            'expires_at' => $expiresAt,
        ]);
    }

    public function findByFlowToken(string $flowToken): ?array {
        $stmt = $this->db->prepare('SELECT * FROM login_flow_tokens WHERE flow_token = :flow_token');
        $stmt->execute(['flow_token' => $flowToken]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByPollToken(string $pollToken): ?array {
        $stmt = $this->db->prepare('SELECT * FROM login_flow_tokens WHERE poll_token = :poll_token');
        $stmt->execute(['poll_token' => $pollToken]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Bindet den Flow an den erfolgreich angemeldeten Nutzer und das dabei erzeugte API-Token. */
    public function complete(int $id, int $userId, string $apiTokenPlaintext): void {
        $stmt = $this->db->prepare(
            'UPDATE login_flow_tokens SET user_id = :user_id, api_token_plaintext = :api_token_plaintext
             WHERE id = :id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'api_token_plaintext' => $apiTokenPlaintext,
            'id' => $id,
        ]);
    }

    /** Single-Use wie Nextclouds Poll-Endpunkt: nach erfolgreichem Abholen wird die Zeile gelöscht. */
    public function delete(int $id): void {
        $stmt = $this->db->prepare('DELETE FROM login_flow_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Räumt abgelaufene Flows auf (analog zu ArticleRepository::
     * clearStuckProcessing()) - läuft lazy bei jedem neuen start(), kein
     * Cronjob nötig.
     */
    public function deleteExpired(): void {
        $stmt = $this->db->prepare('DELETE FROM login_flow_tokens WHERE expires_at < :now');
        $stmt->execute(['now' => gmdate('c')]);
    }
}
