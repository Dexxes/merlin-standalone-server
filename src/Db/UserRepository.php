<?php

declare(strict_types=1);

namespace Merlin\Db;

use PDO;

final class UserRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByUsername(string $username): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findAll(): array {
        return $this->db->query('SELECT * FROM users ORDER BY username')->fetchAll();
    }

    public function create(string $username, string $email, string $passwordHash, string $role): array {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:username, :email, :password_hash, :role, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    public function updatePassword(int $userId, string $passwordHash): void {
        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'hash' => $passwordHash,
            'updated_at' => gmdate('c'),
            'id' => $userId,
        ]);
    }

    public function updateRoleAndStatus(int $userId, string $role, bool $isActive): void {
        $stmt = $this->db->prepare(
            'UPDATE users SET role = :role, is_active = :is_active, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => gmdate('c'),
            'id' => $userId,
        ]);
    }

    public function delete(int $userId): void {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function countAdmins(): int {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    public static function toPublicArray(array $user): array {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'isActive' => (bool) $user['is_active'],
            'createdAt' => $user['created_at'],
            'updatedAt' => $user['updated_at'],
        ];
    }
}
