<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Models\DocumentAnalysis;
use App\Models\DocumentHistory;
use App\Models\DocumentObservation;
use App\Models\DocumentType;
use App\Models\DocumentStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DocumentIntelligenceService;

class AdminController extends Controller
{
    private function authorize(): ?array
    {
        $user = Session::get('user');
        $roleSlug = $user['role_slug'] ?? null;
        $roleName = $user['role_name'] ?? null;
        if (!$user || ($roleSlug !== 'administrador' && $roleName !== 'Administrador')) {
            $this->json(['error' => 'Acceso denegado'], 403);
            return null;
        }
        return $user;
    }

    public function dashboard(): void
    {
        if (!$this->authorize()) {
            return;
        }

        $analysisModel = new DocumentAnalysis();
        $stats = $analysisModel->dashboardStats();
        $total = max(1, (int)($stats['total_documents'] ?? 0));
        $valid = (int)($stats['valid_documents'] ?? 0);
        $documentStatusModel = new DocumentStatus();

        $this->json([
            'stats' => $stats,
            'valid_percentage' => round(($valid / $total) * 100, 2),
            'documents' => $analysisModel->listForAdmin([
                'query' => $_GET['query'] ?? null,
                'state_id' => $_GET['state_id'] ?? null,
            ]),
            'states' => $documentStatusModel->all(),
            'users_total' => count((new User())->all()),
        ]);
    }

    public function listUsers(): void
    {
        if (!$this->authorize()) {
            return;
        }
        $model = new User();
        $this->json(['users' => $model->all()]);
    }

    public function createUser(Request $request): void
    {
        if (!$this->authorize()) {
            return;
        }

        $data = $request->all();
        $validator = new Validator();
        $validator->required('email', $data['email'] ?? '', 'El correo es obligatorio')
                  ->required('password', $data['password'] ?? '', 'La contraseña es obligatoria')
                  ->required('role_id', $data['role_id'] ?? '', 'El rol es obligatorio');

        if ($validator->fails()) {
            $this->json(['errors' => $validator->getErrors()], 422);
            return;
        }

        $userModel = new User();
        $userId = $userModel->create([
            'role_id' => (int)$data['role_id'],
            'email' => $data['email'],
            'username' => $data['username'] ?? '',
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'] ?? '',
            'document_number' => $data['document_number'] ?? '',
            'program' => $data['program'] ?? '',
            'status' => $data['status'] ?? 'activo',
        ]);

        $this->json(['message' => 'Usuario creado', 'id' => $userId], 201);
    }

    public function listDocuments(Request $request): void
    {
        if (!$this->authorize()) {
            return;
        }

        $analysisModel = new DocumentAnalysis();
        $this->json([
            'documents' => $analysisModel->listForAdmin([
                'query' => $request->input('query'),
                'state_id' => $request->input('state_id'),
            ]),
        ]);
    }

    public function documentDetail(Request $request, array $params): void
    {
        if (!$this->authorize()) {
            return;
        }

        $documentId = (int)$params['id'];
        $documentModel = new StudentDocument();
        $analysisModel = new DocumentAnalysis();
        $historyModel = new DocumentHistory();
        $observationModel = new DocumentObservation();

        $document = $documentModel->find($documentId);
        if (!$document) {
            $this->json(['error' => 'Documento no encontrado'], 404);
            return;
        }

        $analysis = $analysisModel->latestByDocument($documentId);
        $fields = $analysisModel->fieldsByDocument($documentId);

        $this->json([
            'document' => $document,
            'analysis' => $analysis,
            'fields' => $fields,
            'history' => $historyModel->findByDocument($documentId),
            'observations' => $observationModel->findByDocument($documentId),
        ]);
    }

    public function reviewDocument(Request $request, array $params): void
    {
        if (!$this->authorize()) {
            return;
        }

        $documentId = (int)$params['id'];
        $payload = $request->json();
        $stateId = (int)($payload['state_id'] ?? 0);
        $comment = trim((string)($payload['comment'] ?? ''));

        $statusModel = new DocumentStatus();
        $status = $statusModel->find($stateId);
        if (!$status) {
            $this->json(['error' => 'Estado inválido'], 422);
            return;
        }

        $documentModel = new StudentDocument();
        $document = $documentModel->find($documentId);
        if (!$document) {
            $this->json(['error' => 'Documento no encontrado'], 404);
            return;
        }

        $documentModel->setReviewOutcome($documentId, $stateId, Session::get('user')['id']);

        $historyModel = new DocumentHistory();
        $historyModel->create([
            'document_id' => $documentId,
            'state_id' => $stateId,
            'changed_by' => Session::get('user')['id'],
            'comment' => $comment !== '' ? $comment : 'Revisión manual administrativa',
        ]);

        if ($comment !== '') {
            (new DocumentObservation())->create([
                'document_id' => $documentId,
                'user_id' => Session::get('user')['id'],
                'comment' => $comment,
            ]);
        }

        $this->json([
            'message' => 'Documento actualizado',
            'document_id' => $documentId,
            'state' => $status,
        ]);
    }

    public function reanalyzeDocument(Request $request, array $params): void
    {
        $adminUser = $this->authorize();
        if (!$adminUser) {
            return;
        }

        $documentId = (int)$params['id'];
        $documentModel = new StudentDocument();
        $document = $documentModel->find($documentId);
        if (!$document) {
            $this->json(['error' => 'Documento no encontrado'], 404);
            return;
        }

        if (empty($document['storage_path']) || !is_file($document['storage_path'])) {
            $this->json(['error' => 'Archivo no disponible para reanálisis'], 404);
            return;
        }

        $documentTypeModel = new DocumentType();
        $expectedType = $documentTypeModel->find((int)$document['document_type_id']);
        if (!$expectedType) {
            $this->json(['error' => 'Tipo de documento no válido'], 422);
            return;
        }

        $student = (new User())->find((int)$document['user_id']) ?: [];

        // Liberar el bloqueo de sesión durante el OCR para no bloquear otras acciones del backend.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $analysisService = new DocumentIntelligenceService($this->config());
        $analysisResult = $analysisService->analyze($document['storage_path'], $expectedType, $student);

        $statusModel = new DocumentStatus();
        $detectedTypeModel = new DocumentType();
        $status = $statusModel->findByCode($analysisResult['state_code']) ?? $statusModel->find((int)$document['state_id']) ?? $statusModel->find(1);
        $detectedType = $analysisResult['detected_document_type_code'] ? $detectedTypeModel->findByCode($analysisResult['detected_document_type_code']) : null;

        $documentModel->updateAnalysisResult($documentId, [
            'state_id' => $status['id'],
            'detected_document_type_id' => $detectedType['id'] ?? null,
            'analysis_status' => $analysisResult['state_code'],
            'analysis_confidence' => $analysisResult['confidence'],
            'analysis_engine' => $analysisResult['engine_name'],
            'analysis_summary' => $analysisResult['summary'],
            'analysis_payload' => $analysisResult['analysis_payload'],
            'ocr_text' => $analysisResult['ocr_text'],
            'quality_score' => $analysisResult['quality_score'],
            'processed_by' => $adminUser['id'],
            'auto_classified' => 1,
        ]);

        $analysisModel = new DocumentAnalysis();
        $analysisId = $analysisModel->create([
            'document_id' => $documentId,
            'expected_document_type_id' => (int)$document['document_type_id'],
            'detected_document_type_id' => $detectedType['id'] ?? null,
            'state_id' => $status['id'],
            'status_code' => $analysisResult['state_code'],
            'engine_name' => $analysisResult['engine_name'],
            'confidence' => $analysisResult['confidence'],
            'quality_score' => $analysisResult['quality_score'],
            'ocr_text' => $analysisResult['ocr_text'],
            'summary' => $analysisResult['summary'],
            'analysis_payload' => $analysisResult['analysis_payload'],
            'created_by' => $adminUser['id'],
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

        (new DocumentHistory())->create([
            'document_id' => $documentId,
            'state_id' => $status['id'],
            'changed_by' => $adminUser['id'],
            'comment' => 'Reanálisis automático: ' . $analysisResult['summary'],
        ]);

        $this->json([
            'message' => 'Reanálisis ejecutado correctamente',
            'document_id' => $documentId,
            'analysis' => $analysisResult,
            'state' => $status,
        ]);
    }

    public function listDocumentTypes(): void
    {
        if (!$this->authorize()) {
            return;
        }
        $model = new DocumentType();
        $this->json(['document_types' => $model->all()]);
    }

    public function listStates(): void
    {
        if (!$this->authorize()) {
            return;
        }
        $model = new DocumentStatus();
        $this->json(['states' => $model->all()]);
    }

    public function listPermissions(): void
    {
        if (!$this->authorize()) {
            return;
        }
        $model = new Permission();
        $this->json(['permissions' => $model->all()]);
    }
}
