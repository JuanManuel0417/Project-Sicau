-- SICAU: Modelo relacional de base de datos
-- MySQL / phpMyAdmin / XAMPP

CREATE DATABASE IF NOT EXISTS sicau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sicau;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(60) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    document_number VARCHAR(50) NOT NULL,
    program VARCHAR(150) DEFAULT NULL,
    status ENUM('activo','inactivo','suspendido') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    required_for_role ENUM('estudiante','funcionario','administrador','todos') DEFAULT 'estudiante',
    max_size_mb TINYINT UNSIGNED DEFAULT 5,
    allowed_extensions VARCHAR(100) DEFAULT 'pdf,jpg,jpeg,png',
    sort_order TINYINT UNSIGNED DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    sort_order TINYINT UNSIGNED DEFAULT 1,
    is_final BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    document_type_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(80) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    state_id INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    processed_by INT NULL DEFAULT NULL,
    auto_classified TINYINT(1) DEFAULT 0,
    detected_document_type_id INT NULL DEFAULT NULL,
    analysis_status VARCHAR(60) DEFAULT 'pendiente',
    analysis_confidence DECIMAL(5,2) DEFAULT NULL,
    analysis_engine VARCHAR(80) DEFAULT NULL,
    analysis_summary TEXT DEFAULT NULL,
    analysis_payload JSON DEFAULT NULL,
    ocr_text LONGTEXT DEFAULT NULL,
    quality_score TINYINT UNSIGNED DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by INT NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (state_id) REFERENCES document_statuses(id) ON DELETE RESTRICT,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (detected_document_type_id) REFERENCES document_types(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    expected_document_type_id INT NOT NULL,
    detected_document_type_id INT NULL DEFAULT NULL,
    state_id INT NOT NULL,
    status_code VARCHAR(60) NOT NULL,
    engine_name VARCHAR(80) NOT NULL,
    confidence DECIMAL(5,2) DEFAULT NULL,
    quality_score TINYINT UNSIGNED DEFAULT NULL,
    ocr_text LONGTEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    analysis_payload JSON DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES student_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (expected_document_type_id) REFERENCES document_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (detected_document_type_id) REFERENCES document_types(id) ON DELETE SET NULL,
    FOREIGN KEY (state_id) REFERENCES document_statuses(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_extracted_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_id INT NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    field_label VARCHAR(120) NOT NULL,
    field_value VARCHAR(255) DEFAULT NULL,
    confidence DECIMAL(5,2) DEFAULT NULL,
    source VARCHAR(80) DEFAULT 'ocr',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (analysis_id) REFERENCES document_analyses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_histories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    state_id INT NOT NULL,
    changed_by INT NULL DEFAULT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES student_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (state_id) REFERENCES document_statuses(id) ON DELETE RESTRICT,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_observations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    user_id INT NULL DEFAULT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES student_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS validation_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_id INT NOT NULL,
    rule_type VARCHAR(60) NOT NULL,
    rule_value VARCHAR(255) NOT NULL,
    message VARCHAR(255) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name, slug, description) VALUES
('Estudiante', 'estudiante', 'Usuario con permisos para subir y consultar documentos'),
('Funcionario Administrativo', 'funcionario', 'Usuario que revisa, valida y gestiona documentos'),
('Administrador', 'administrador', 'Usuario con permisos de configuración y gestión de usuarios');

INSERT IGNORE INTO permissions (name, slug, description) VALUES
('Ver documentos', 'view_documents', 'Consultar documentos cargados'),
('Subir documentos', 'upload_documents', 'Subir o reemplazar documentos'),
('Revisar documentos', 'review_documents', 'Aprobar o rechazar documentos'),
('Administrar usuarios', 'manage_users', 'Crear y editar usuarios'),
('Administrar tipos de documento', 'manage_document_types', 'Configurar tipos de documento'),
('Ver historial', 'view_history', 'Visualizar historial de cambios');

INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES
(1, 1),(1,2),(1,6),
(2,1),(2,3),(2,6),
(3,1),(3,3),(3,4),(3,5),(3,6);

INSERT IGNORE INTO document_statuses (code, name, description, sort_order, is_final) VALUES
('pendiente','Pendiente','El documento fue cargado y está esperando revisión',1,0),
('en_revision','En revisión','El documento está siendo revisado por el área administrativa',2,0),
('aprobado','Aprobado','El documento fue aprobado y aceptado',3,1),
('rechazado','Rechazado','El documento fue rechazado por inconsistencias',4,1),
('requiere_correccion','Requiere corrección','El documento necesita correcciones del estudiante',5,0),
('validado_automaticamente','Validado automáticamente','La validación automática confirmó el documento',6,0),
('requiere_revision','Requiere revisión','El motor automático no pudo confirmar totalmente el documento',7,0),
('documento_incorrecto','Documento incorrecto','El archivo no corresponde al tipo esperado',8,1),
('documento_ilegible','Documento ilegible','El archivo no se pudo leer con suficiente calidad',9,1);

INSERT IGNORE INTO document_types (name, code, description, required_for_role, max_size_mb, allowed_extensions, sort_order) VALUES
('Documento de identidad','identity_document','Cédula de ciudadanía, Tarjeta de identidad o documento de identificación oficial','estudiante',5,'pdf',1),
('Acta de grados','graduation_act','Acta de grado o diploma de bachiller','estudiante',5,'pdf',2),
('Prueba Saber 11','saber_11','Certificado de pruebas ICFES Saber 11','estudiante',5,'pdf',3),
('Foto tipo carnet','portrait_photo','Foto carnet, fondo blanco','estudiante',2,'jpg,jpeg,png',4),
('Certificado SISBÉN','sisben_certificate','Certificado SISBÉN actualizado','estudiante',5,'pdf',5),
('Servicios públicos','utility_bill','Última factura de servicios públicos','estudiante',5,'pdf',6);

INSERT IGNORE INTO users (role_id, email, username, password_hash, full_name, document_number, program, status) VALUES
(3, 'admin@cas.edu', 'admin', '$2y$10$ZIrkrghQO1bZ0yx6DXW6aOBk7mw4uBLcATgh1mGvGbKscL72Z8uWy', 'Administrador SICAU', '0000000000', 'Gestión Documental', 'activo'),
(1, 'estudiante@cas.edu', 'estudiante', '$2y$10$ZIrkrghQO1bZ0yx6DXW6aOBk7mw4uBLcATgh1mGvGbKscL72Z8uWy', 'Estudiante Ejemplo', '1234567890', 'Ingeniería de Software', 'activo'),
(1, 'juan.gonzalez308@pascualbravo.edu.co', 'juan.gonzalez308', '$2y$10$oSTZA629U/V01aD7cTi8iuGyR5ToNG2dV4psHMEsVMRvGJYWs4wBK', 'Juan González', '1114153308', 'Ingeniería de Software', 'activo'),
(3, 'administrador@pascualbravo.edu.co', 'administrador', '$2y$10$oSTZA629U/V01aD7cTi8iuGyR5ToNG2dV4psHMEsVMRvGJYWs4wBK', 'Administrador SICAU', '0000000001', 'Gestión Documental', 'activo');

INSERT IGNORE INTO validation_rules (document_type_id, rule_type, rule_value, message, active) VALUES
(1, 'extension', 'pdf', 'Documento de identidad debe ser PDF', 1),
(4, 'extension', 'jpg,jpeg,png', 'Foto tipo carnet debe ser JPG, JPEG o PNG', 1),
(1, 'max_size_mb', '5', 'Documento de identidad no puede pesar más de 5MB', 1);

-- Consultas de referencia
SELECT u.id, u.email, u.full_name, r.name AS role, u.status FROM users u JOIN roles r ON u.role_id = r.id;
SELECT sd.id, u.full_name, dt.name AS document_type, ds.name AS state, sd.uploaded_at FROM student_documents sd JOIN users u ON sd.user_id = u.id JOIN document_types dt ON sd.document_type_id = dt.id JOIN document_statuses ds ON sd.state_id = ds.id;
SELECT dh.document_id, dh.comment, dh.created_at, u.full_name AS actor FROM document_histories dh LEFT JOIN users u ON dh.changed_by = u.id ORDER BY dh.created_at DESC;
