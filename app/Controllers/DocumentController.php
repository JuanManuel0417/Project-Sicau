<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\DocumentAnalysis;
use App\Models\DocumentHistory;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\DocumentObservation;
use App\Models\ValidationRule;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DocumentIntelligenceService;

class DocumentController extends Controller
{
    public function list(Request $request): void
    {
        $user = Session::get('user');
        if (!$user) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }

        $documentTypeModel = new DocumentType();
        $documents = $documentTypeModel->all();
        $studentModel = new StudentDocument();
        $userDocuments = $studentModel->findByUser($user['id']);

        $grouped = [];
        foreach ($documents as $docType) {
            $grouped[$docType['id']] = array_merge($docType, ['uploaded' => null]);
        }
        foreach ($userDocuments as $doc) {
            $grouped[$doc['document_type_id']]['uploaded'] = $doc;
        }

        $this->json(['documents' => array_values($grouped)]);
    }

    public function upload(Request $request): void
    {
        $user = Session::get('user');
        if (!$user) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }

        // Validar usuario persistente
        $persistedUser = (new User())->find((int)($user['id'] ?? 0));
        if (!$persistedUser) {
            Session::destroy();
            $this->json(['error' => 'Sesión inválida. Inicie sesión nuevamente'], 401);
            return;
        }

        $documentTypeId = (int)$request->input('document_type_id');
        $file = $request->file('document');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No se recibió ningún archivo válido'], 422);
            return;
        }

        // Cargar configuración de forma segura SIEMPRE como array
        $config = include __DIR__ . '/../../config/config.php';
        if (!is_array($config)) {
            $this->json(['error' => 'Error de configuración de la aplicación'], 500);
            return;
        }
        $allowed = $config['app']['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $this->json(['error' => 'Extensión no permitida'], 422);
            return;
        }

        if ($file['size'] > $config['app']['max_upload_size']) {
            $this->json(['error' => 'El archivo supera el límite de 5MB'], 422);
            return;
        }

        $documentTypeModel = new DocumentType();
        $documentType = $documentTypeModel->find($documentTypeId);
        if (!$documentType) {
            $this->json(['error' => 'Tipo de documento no válido'], 422);
            return;
        }

        $validationRuleModel = new ValidationRule();
        $activeRules = $validationRuleModel->activeByDocumentType($documentTypeId);
        $documentAllowedExtensions = array_filter(array_map('trim', explode(',', (string)($documentType['allowed_extensions'] ?? ''))));
        $ruleExtensions = [];
        $maxSizeMb = (int)($documentType['max_size_mb'] ?? 0);

        foreach ($activeRules as $rule) {
            if (($rule['rule_type'] ?? '') === 'extension') {
                $ruleExtensions = array_merge($ruleExtensions, array_map('trim', explode(',', (string)$rule['rule_value'])));
            }
            if (($rule['rule_type'] ?? '') === 'max_size_mb') {
                $maxSizeMb = min($maxSizeMb ?: (int)$rule['rule_value'], (int)$rule['rule_value']);
            }
        }

        $allowedExtensions = $documentAllowedExtensions ?: $ruleExtensions ?: $allowed;
        if ($documentAllowedExtensions && $ruleExtensions) {
            $allowedExtensions = array_values(array_intersect($documentAllowedExtensions, $ruleExtensions));
        } elseif ($ruleExtensions && !$documentAllowedExtensions) {
            $allowedExtensions = $ruleExtensions;
        }

        if ($allowedExtensions && !in_array($ext, $allowedExtensions, true)) {
            $this->json(['error' => 'La extensión no corresponde al tipo de documento solicitado'], 422);
            return;
        }

        if ($maxSizeMb > 0 && $file['size'] > ($maxSizeMb * 1024 * 1024)) {
            $this->json(['error' => 'El archivo supera el tamaño permitido para este documento'], 422);
            return;
        }

        $filename = sprintf('%s_%s_%s.%s', $user['id'], $documentTypeId, time(), $ext);
        $destination = rtrim($config['app']['upload_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->json(['error' => 'Error al guardar el archivo'], 500);
            return;
        }


        $studentDocument = new StudentDocument();
        $existing = $studentDocument->findByUser($user['id']);
        $replaceId = null;
        foreach ($existing as $doc) {
            if ($doc['document_type_id'] === $documentTypeId) {
                $replaceId = $doc['id'];
                break;
            }
        }

        // Obtener el ID real del estado 'pendiente'
        $statusModel = new \App\Models\DocumentStatus();
        $pendingStatus = $statusModel->findByCode('pendiente');
        $pendingId = $pendingStatus ? $pendingStatus['id'] : 1;

        if ($replaceId) {
            $studentDocument->replaceFile($replaceId, [
                'original_filename' => $file['name'],
                'storage_path' => $destination,
                'file_type' => $file['type'],
                'file_size' => $file['size'],
                'auto_classified' => 0,
            ]);
            $documentId = $replaceId;
        } else {
            $documentId = $studentDocument->create([
                'user_id' => $user['id'],
                'document_type_id' => $documentTypeId,
                'original_filename' => $file['name'],
                'storage_path' => $destination,
                'file_type' => $file['type'],
                'file_size' => $file['size'],
                'state_id' => $pendingId,
                'auto_classified' => 0,
            ]);
        }

        $history = new \App\Models\DocumentHistory();
        $history->create([
            'document_id' => $documentId,
            'state_id' => $pendingId,
            'changed_by' => $user['id'],
            'comment' => 'Carga inicial / reemplazo de documento',
        ]);

        // Liberar el bloqueo de sesión antes del OCR pesado para no congelar otras peticiones.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Permitir que el análisis OCR termine en documentos pesados sin cortar la petición
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        $analysisService = new \App\Services\DocumentIntelligenceService($this->config());
        $analysisResult = $analysisService->analyze($destination, $documentType, $user);
        $detectedTypeModel = new \App\Models\DocumentType();
        $status = $statusModel->findByCode($analysisResult['state_code']) ?? $pendingStatus;
        $detectedType = $analysisResult['detected_document_type_code'] ? $detectedTypeModel->findByCode($analysisResult['detected_document_type_code']) : null;

        $studentDocument->updateAnalysisResult($documentId, [
            'state_id' => $status['id'],
            'detected_document_type_id' => $detectedType['id'] ?? null,
            'analysis_status' => $analysisResult['state_code'],
            'analysis_confidence' => $analysisResult['confidence'],
            'analysis_engine' => $analysisResult['engine_name'],
            'analysis_summary' => $analysisResult['summary'],
            'analysis_payload' => $analysisResult['analysis_payload'],
            'ocr_text' => $analysisResult['ocr_text'],
            'quality_score' => $analysisResult['quality_score'],
            'processed_by' => null,
            'auto_classified' => 1,
        ]);

        $analysisModel = new DocumentAnalysis();
        $analysisId = $analysisModel->create([
            'document_id' => $documentId,
            'expected_document_type_id' => $documentTypeId,
            'detected_document_type_id' => $detectedType['id'] ?? null,
            'state_id' => $status['id'],
            'status_code' => $analysisResult['state_code'],
            'engine_name' => $analysisResult['engine_name'],
            'confidence' => $analysisResult['confidence'],
            'quality_score' => $analysisResult['quality_score'],
            'ocr_text' => $analysisResult['ocr_text'],
            'summary' => $analysisResult['summary'],
            'analysis_payload' => $analysisResult['analysis_payload'],
            'created_by' => null,
        ]);
        $analysisModel->addFields($analysisId, $analysisResult['extracted_fields']);

        if (!empty($analysisResult['observations'])) {
            $observationModel = new DocumentObservation();
            foreach ($analysisResult['observations'] as $observation) {
                $observationModel->create([
                    'document_id' => $documentId,
                    'user_id' => null,
                    'comment' => $observation,
                ]);
            }
        }

        $history->create([
            'document_id' => $documentId,
            'state_id' => $status['id'],
            'changed_by' => $user['id'],
            'comment' => $analysisResult['summary'],
        ]);

        $this->json([
            'message' => 'Documento cargado y analizado correctamente',
            'document_id' => $documentId,
            'analysis' => $analysisResult,
        ]);
    }

    public function history(Request $request, array $params): void
    {
        $user = Session::get('user');
        if (!$user) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }

        $documentId = (int)$params['id'];
        $historyModel = new DocumentHistory();
        $events = $historyModel->findByDocument($documentId);
        $this->json(['history' => $events]);
    }

    public function changeState(Request $request, array $params): void
    {
        $user = Session::get('user');
        if (!$user) {
            $this->json(['error' => 'No autenticado'], 401);
            return;
        }

        $documentId = (int)$params['id'];
        $data = $request->json();
        $newState = (int)($data['state_id'] ?? 0);
        $comment = $data['comment'] ?? null;

        $studentDocument = new StudentDocument();
        if (!$studentDocument->find($documentId)) {
            $this->json(['error' => 'Documento no encontrado'], 404);
            return;
        }

        $studentDocument->setReviewOutcome($documentId, $newState, $user['id'], $comment);
        $historyModel = new DocumentHistory();
        $historyModel->create([
            'document_id' => $documentId,
            'state_id' => $newState,
            'changed_by' => $user['id'],
            'comment' => $comment ?: 'Cambio de estado manual',
        ]);

        $this->json(['message' => 'Estado actualizado']);
    }

    public function download(Request $request, array $params): void
    {
        $user = Session::get('user');
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado']);
            return;
        }

        $documentId = (int)$params['id'];
        $studentDocument = new StudentDocument();
        $document = $studentDocument->find($documentId);

        $isAdmin = ($user['role_slug'] ?? '') === 'administrador' || ($user['role_name'] ?? '') === 'Administrador';

        if (!$document || (!$isAdmin && $document['user_id'] !== $user['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Documento no encontrado']);
            return;
        }

        if (!is_file($document['storage_path'])) {
            $this->json(['error' => 'Archivo no disponible'], 404);
            return;
        }

        $contentType = $document['file_type'] ?: (mime_content_type($document['storage_path']) ?: 'application/octet-stream');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . basename($document['original_filename']) . '"');
        header('Content-Length: ' . filesize($document['storage_path']));
        readfile($document['storage_path']);
        exit;
    }
}
