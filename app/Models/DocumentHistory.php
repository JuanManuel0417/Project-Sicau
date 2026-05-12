<?php

namespace App\Models;

use App\Core\Model;

class DocumentHistory extends Model
{
    public function findByDocument(int $documentId): array
    {
        $stmt = $this->db->prepare('SELECT dh.*, u.full_name AS actor_name, ds.name AS state_name FROM document_histories dh LEFT JOIN users u ON dh.changed_by = u.id LEFT JOIN document_statuses ds ON dh.state_id = ds.id WHERE dh.document_id = :document_id ORDER BY dh.created_at DESC');
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare('INSERT INTO document_histories (document_id, state_id, changed_by, comment, created_at) VALUES (:document_id, :state_id, :changed_by, :comment, NOW())');
        return $stmt->execute($data);
    }
}
