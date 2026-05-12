<?php

namespace App\Models;

use App\Core\Model;

class DocumentStatus extends Model
{
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM document_statuses ORDER BY sort_order');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM document_statuses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM document_statuses WHERE code = :code');
        $stmt->execute(['code' => $code]);
        return $stmt->fetch() ?: null;
    }
}
