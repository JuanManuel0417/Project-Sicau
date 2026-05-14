<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class DatabaseSeeder
{
    public static function seed(array $config): void
    {
        $pdo = Database::connect($config['db']);

        $statusCount = (int)$pdo->query('SELECT COUNT(*) FROM document_statuses')->fetchColumn();
        $typeCount = (int)$pdo->query('SELECT COUNT(*) FROM document_types')->fetchColumn();

        if ($statusCount === 0) {
            self::seedDocumentStatuses($pdo);
        }

        if ($typeCount === 0) {
            self::seedDocumentTypes($pdo);
        }
    }

    private static function seedDocumentStatuses(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO document_statuses (code, name, description, sort_order, is_final, created_at, updated_at) VALUES (:code, :name, :description, :sort_order, :is_final, NOW(), NOW())');

        $statuses = [
            ['pendiente', 'Pendiente', 'El documento fue cargado y está esperando revisión', 1, 0],
            ['en_revision', 'En revisión', 'El documento está siendo revisado por el área administrativa', 2, 0],
            ['aprobado', 'Aprobado', 'El documento fue aprobado y aceptado', 3, 1],
            ['rechazado', 'Rechazado', 'El documento fue rechazado por inconsistencias', 4, 1],
            ['requiere_correccion', 'Requiere corrección', 'El documento necesita correcciones del estudiante', 5, 0],
            ['validado_automaticamente', 'Validado automáticamente', 'La validación automática confirmó el documento', 6, 0],
            ['requiere_revision', 'Requiere revisión', 'El motor automático no pudo confirmar totalmente el documento', 7, 0],
            ['documento_incorrecto', 'Documento incorrecto', 'El archivo no corresponde al tipo esperado', 8, 1],
            ['documento_ilegible', 'Documento ilegible', 'El archivo no se pudo leer con suficiente calidad', 9, 1],
        ];

        foreach ($statuses as $status) {
            $stmt->execute([
                'code' => $status[0],
                'name' => $status[1],
                'description' => $status[2],
                'sort_order' => $status[3],
                'is_final' => $status[4],
            ]);
        }
    }

    private static function seedDocumentTypes(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO document_types (name, code, description, required_for_role, max_size_mb, allowed_extensions, sort_order, created_at, updated_at) VALUES (:name, :code, :description, :required_for_role, :max_size_mb, :allowed_extensions, :sort_order, NOW(), NOW())');

        $types = [
            ['Documento de identidad', 'identity_document', 'Cédula de ciudadanía, Tarjeta de identidad o documento de identificación oficial', 'estudiante', 5, 'pdf', 1],
            ['Acta de grados', 'graduation_act', 'Acta de grado o diploma de bachiller', 'estudiante', 5, 'pdf', 2],
            ['Prueba Saber 11', 'saber_11', 'Certificado de pruebas ICFES Saber 11', 'estudiante', 5, 'pdf', 3],
            ['Foto tipo carnet', 'portrait_photo', 'Foto carnet, fondo blanco', 'estudiante', 2, 'jpg,jpeg,png', 4],
            ['Certificado SISBÉN', 'sisben_certificate', 'Certificado SISBÉN actualizado', 'estudiante', 5, 'pdf', 5],
            ['Servicios públicos', 'utility_bill', 'Última factura de servicios públicos', 'estudiante', 5, 'pdf', 6],
        ];

        foreach ($types as $type) {
            $stmt->execute([
                'name' => $type[0],
                'code' => $type[1],
                'description' => $type[2],
                'required_for_role' => $type[3],
                'max_size_mb' => $type[4],
                'allowed_extensions' => $type[5],
                'sort_order' => $type[6],
            ]);
        }
    }
}
