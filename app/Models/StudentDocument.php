<?php

namespace App\Models;

use App\Core\Model;

class StudentDocument extends Model
{
    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT sd.*, dt.name AS document_type, ds.name AS state_name, ds.code AS state_code, detected.name AS detected_document_type, detected.code AS detected_document_type_code, da.summary AS analysis_summary, da.confidence AS analysis_confidence, da.quality_score AS analysis_quality_score, da.engine_name AS analysis_engine, da.ocr_text AS analysis_ocr_text FROM student_documents sd JOIN document_types dt ON sd.document_type_id = dt.id JOIN document_statuses ds ON sd.state_id = ds.id LEFT JOIN document_types detected ON sd.detected_document_type_id = detected.id LEFT JOIN document_analyses da ON da.id = (SELECT da2.id FROM document_analyses da2 WHERE da2.document_id = sd.id ORDER BY da2.created_at DESC, da2.id DESC LIMIT 1) WHERE sd.user_id = :user_id ORDER BY dt.sort_order');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT sd.*, u.full_name AS student_name, u.email AS student_email, u.document_number AS student_document_number, u.program AS student_program, dt.name AS document_type, dt.name AS document_type_name, ds.name AS state_name, ds.code AS state_code, detected.name AS detected_document_type, detected.name AS detected_document_type_name, detected.code AS detected_document_type_code FROM student_documents sd JOIN users u ON sd.user_id = u.id JOIN document_types dt ON sd.document_type_id = dt.id JOIN document_statuses ds ON sd.state_id = ds.id LEFT JOIN document_types detected ON sd.detected_document_type_id = detected.id WHERE sd.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO student_documents (user_id, document_type_id, original_filename, storage_path, file_type, file_size, state_id, uploaded_at, updated_at, auto_classified) VALUES (:user_id, :document_type_id, :original_filename, :storage_path, :file_type, :file_size, :state_id, NOW(), NOW(), :auto_classified)');
        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    public function updateState(int $id, int $stateId, ?string $comment = null, int $processedBy = null): bool
    {
        $stmt = $this->db->prepare('UPDATE student_documents SET state_id = :state_id, updated_at = NOW(), processed_at = NOW(), processed_by = :processed_by WHERE id = :id');
        return $stmt->execute(['state_id' => $stateId, 'processed_by' => $processedBy, 'id' => $id]);
    }

    public function replaceFile(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('UPDATE student_documents SET original_filename = :original_filename, storage_path = :storage_path, file_type = :file_type, file_size = :file_size, updated_at = NOW(), auto_classified = :auto_classified WHERE id = :id');
        return $stmt->execute(array_merge($data, ['id' => $id]));
    }

    public function updateAnalysisResult(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('UPDATE student_documents SET state_id = :state_id, detected_document_type_id = :detected_document_type_id, analysis_status = :analysis_status, analysis_confidence = :analysis_confidence, analysis_engine = :analysis_engine, analysis_summary = :analysis_summary, analysis_payload = :analysis_payload, ocr_text = :ocr_text, quality_score = :quality_score, processed_at = NOW(), processed_by = :processed_by, auto_classified = :auto_classified, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([
            'state_id' => $data['state_id'],
            'detected_document_type_id' => $data['detected_document_type_id'] ?? null,
            'analysis_status' => $data['analysis_status'],
            'analysis_confidence' => $data['analysis_confidence'] ?? null,
            'analysis_engine' => $data['analysis_engine'] ?? null,
            'analysis_summary' => $data['analysis_summary'] ?? null,
            'analysis_payload' => isset($data['analysis_payload']) ? json_encode($data['analysis_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ocr_text' => $data['ocr_text'] ?? null,
            'quality_score' => $data['quality_score'] ?? null,
            'processed_by' => $data['processed_by'] ?? null,
            'auto_classified' => $data['auto_classified'] ?? 0,
            'id' => $id,
        ]);
    }

    public function setReviewOutcome(int $id, int $stateId, int $reviewedBy, ?string $comment = null): bool
    {
        $stmt = $this->db->prepare('UPDATE student_documents SET state_id = :state_id, reviewed_at = NOW(), reviewed_by = :reviewed_by, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([
            'state_id' => $stateId,
            'reviewed_by' => $reviewedBy,
            'id' => $id,
        ]);
    }
}
