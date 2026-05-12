<?php

namespace App\Models;

use App\Core\Model;

class DocumentAnalysis extends Model
{
    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO document_analyses (document_id, expected_document_type_id, detected_document_type_id, state_id, status_code, engine_name, confidence, quality_score, ocr_text, summary, analysis_payload, created_by, created_at) VALUES (:document_id, :expected_document_type_id, :detected_document_type_id, :state_id, :status_code, :engine_name, :confidence, :quality_score, :ocr_text, :summary, :analysis_payload, :created_by, NOW())');
        $stmt->execute([
            'document_id' => $data['document_id'],
            'expected_document_type_id' => $data['expected_document_type_id'],
            'detected_document_type_id' => $data['detected_document_type_id'] ?? null,
            'state_id' => $data['state_id'],
            'status_code' => $data['status_code'],
            'engine_name' => $data['engine_name'],
            'confidence' => $data['confidence'] ?? null,
            'quality_score' => $data['quality_score'] ?? null,
            'ocr_text' => $data['ocr_text'] ?? null,
            'summary' => $data['summary'] ?? null,
            'analysis_payload' => isset($data['analysis_payload']) ? json_encode($data['analysis_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function addFields(int $analysisId, array $fields): bool
    {
        if (!$fields) {
            return true;
        }

        $stmt = $this->db->prepare('INSERT INTO document_extracted_fields (analysis_id, field_key, field_label, field_value, confidence, source, created_at) VALUES (:analysis_id, :field_key, :field_label, :field_value, :confidence, :source, NOW())');
        foreach ($fields as $field) {
            $stmt->execute([
                'analysis_id' => $analysisId,
                'field_key' => $field['key'],
                'field_label' => $field['label'],
                'field_value' => $field['value'] ?? null,
                'confidence' => $field['confidence'] ?? null,
                'source' => $field['source'] ?? 'ocr',
            ]);
        }

        return true;
    }

    public function latestByDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare('SELECT da.*, dt_expected.name AS expected_document_type_name, dt_detected.name AS detected_document_type_name, ds.name AS state_name, u.full_name AS creator_name FROM document_analyses da LEFT JOIN document_types dt_expected ON da.expected_document_type_id = dt_expected.id LEFT JOIN document_types dt_detected ON da.detected_document_type_id = dt_detected.id LEFT JOIN document_statuses ds ON da.state_id = ds.id LEFT JOIN users u ON da.created_by = u.id WHERE da.document_id = :document_id ORDER BY da.created_at DESC, da.id DESC LIMIT 1');
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetch() ?: null;
    }

    public function fieldsByDocument(int $documentId): array
    {
        $stmt = $this->db->prepare('SELECT def.* FROM document_extracted_fields def WHERE def.analysis_id = (SELECT da.id FROM document_analyses da WHERE da.document_id = :document_id ORDER BY da.created_at DESC, da.id DESC LIMIT 1) ORDER BY def.id ASC');
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }

    public function dashboardStats(): array
    {
        $stats = [];
        $queries = [
            'total_documents' => 'SELECT COUNT(*) AS value FROM student_documents',
            'automatic_validations' => "SELECT COUNT(*) AS value FROM student_documents WHERE analysis_status = 'validado_automaticamente'",
            'needs_review' => "SELECT COUNT(*) AS value FROM student_documents WHERE analysis_status = 'requiere_revision'",
            'incorrect_documents' => "SELECT COUNT(*) AS value FROM student_documents WHERE analysis_status IN ('documento_incorrecto','documento_ilegible','rechazado')",
            'valid_documents' => "SELECT COUNT(*) AS value FROM student_documents WHERE state_id IN (SELECT id FROM document_statuses WHERE code IN ('aprobado','validado_automaticamente'))",
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $this->db->query($sql);
            $stats[$key] = (int)($stmt->fetch()['value'] ?? 0);
        }

        $stmt = $this->db->query("SELECT ds.name AS state_name, COUNT(*) AS total FROM student_documents sd JOIN document_statuses ds ON sd.state_id = ds.id GROUP BY ds.id, ds.name ORDER BY total DESC");
        $stats['by_state'] = $stmt->fetchAll();

        return $stats;
    }

    public function listForAdmin(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['state_id'])) {
            $where[] = 'sd.state_id = :state_id';
            $params['state_id'] = (int)$filters['state_id'];
        }

        if (!empty($filters['query'])) {
            $where[] = '(u.full_name LIKE :query OR u.email LIKE :query OR u.document_number LIKE :query OR dt.name LIKE :query)';
            $params['query'] = '%' . $filters['query'] . '%';
        }

        $sql = 'SELECT sd.*, u.full_name AS student_name, u.email AS student_email, u.document_number AS student_document_number, u.program AS student_program, dt.name AS document_type_name, dt.code AS document_type_code, ds.name AS state_name, ds.code AS state_code, detected.name AS detected_document_type_name, detected.code AS detected_document_type_code, da.summary AS analysis_summary, da.confidence AS last_confidence, da.quality_score AS last_quality_score, da.engine_name AS last_engine_name, da.ocr_text AS last_ocr_text, da.analysis_payload AS last_payload, da.created_at AS analyzed_at FROM student_documents sd LEFT JOIN users u ON sd.user_id = u.id LEFT JOIN document_types dt ON sd.document_type_id = dt.id LEFT JOIN document_statuses ds ON sd.state_id = ds.id LEFT JOIN document_types detected ON sd.detected_document_type_id = detected.id LEFT JOIN document_analyses da ON da.id = (SELECT da2.id FROM document_analyses da2 WHERE da2.document_id = sd.id ORDER BY da2.created_at DESC, da2.id DESC LIMIT 1)';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY sd.updated_at DESC, sd.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}