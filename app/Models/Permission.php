<?php

namespace App\Models;

use App\Core\Model;

class Permission extends Model
{
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM permissions ORDER BY id');
        return $stmt->fetchAll();
    }

    public function findByRole(int $roleId): array
    {
        $stmt = $this->db->prepare('SELECT p.* FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll();
    }
}
