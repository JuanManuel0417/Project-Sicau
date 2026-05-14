-- Script para limpiar el sistema, preservando únicamente los datos de usuarios

USE sicau;

-- Deshabilitar restricciones de claves foráneas temporalmente
SET FOREIGN_KEY_CHECKS = 0;

-- Limpiar únicamente datos de documentos subidos y su análisis.
-- Se preservan los tipos de documento, estados y reglas de validación.
DELETE FROM document_extracted_fields;
DELETE FROM document_analyses;
DELETE FROM document_histories;
DELETE FROM document_observations;
DELETE FROM student_documents;

-- Habilitar restricciones de claves foráneas nuevamente
SET FOREIGN_KEY_CHECKS = 1;

-- Confirmación
SELECT 'Sistema limpiado, usuarios preservados' AS resultado;