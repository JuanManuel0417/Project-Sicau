<?php

namespace App\Models;

use App\Core\Model;

class ValidationRule extends Model
{
    public function activeByDocumentType(int $documentTypeId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM validation_rules WHERE document_type_id = :document_type_id AND active = 1 ORDER BY id ASC');
        $stmt->execute(['document_type_id' => $documentTypeId]);
        return $stmt->fetchAll();
    }
}