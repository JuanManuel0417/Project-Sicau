<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class User extends Model
{
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT u.*, r.name AS role_name, r.slug AS role_slug FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.email = :email');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function all(): array
    {
        $stmt = $this->db->query('SELECT u.*, r.name AS role_name, r.slug AS role_slug FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.id');
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO users (role_id, email, username, password_hash, full_name, document_number, program, status, created_at, updated_at) VALUES (:role_id, :email, :username, :password_hash, :full_name, :document_number, :program, :status, NOW(), NOW())');
        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }
}
