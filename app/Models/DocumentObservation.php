<?php

namespace App\Models;

use App\Core\Model;

class DocumentObservation extends Model
{
    public function create(array $data): bool
    {
        $stmt = $this->db->prepare('INSERT INTO document_observations (document_id, user_id, comment, created_at) VALUES (:document_id, :user_id, :comment, NOW())');
        return $stmt->execute($data);
    }

    public function findByDocument(int $documentId): array
    {
        $stmt = $this->db->prepare('SELECT dobs.*, u.full_name AS author_name FROM document_observations dobs LEFT JOIN users u ON dobs.user_id = u.id WHERE dobs.document_id = :document_id ORDER BY dobs.created_at DESC');
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }
}