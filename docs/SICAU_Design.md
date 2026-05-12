# SICAU - Diseño de Arquitectura y Flujo

## Arquitectura general

- Arquitectura: MVC clásico en PHP.
- Backend: PHP 8+ con PDO y rutas REST.
- Base de datos: MySQL / phpMyAdmin.
- Frontend: HTML/CSS/JavaScript con APIs REST.
- Almacenamiento de archivos: `storage/uploads`.

## Estructura de carpetas

- `/app/Core`: núcleo MVC, router, request, response, validación y sesión.
- `/app/Controllers`: controladores de autenticación, documentos y administración.
- `/app/Models`: modelos de datos y acceso a MySQL.
- `/config`: configuración de aplicación y base de datos.
- `/database`: scripts SQL de creación e inserción inicial.
- `/storage/uploads`: almacenamiento de documentos subidos.
- `/docs`: diseño funcional, diagramas y flujo.
- `/assets`: recursos visuales existentes.

## Modelo relacional

### Entidades principales

- `users`: usuarios del sistema.
- `roles`: roles de `Estudiante`, `Funcionario Administrativo`, `Administrador`.
- `permissions`: permisos para roles.
- `role_permissions`: asignación de permisos.
- `document_types`: tipos de documentos académicos.
- `document_statuses`: estados del flujo documental.
- `student_documents`: documentos subidos por estudiantes.
- `document_histories`: trazabilidad e historial de cambios.
- `validation_rules`: reglas activas de validación automática.
- `document_observations`: observaciones administrativas.

### Relaciones clave

- Un `user` tiene un `role`.
- Un `student_document` pertenece a un `user` y a un `document_type`.
- Un `student_document` avanza por `document_statuses`.
- Un `document_history` registra cada cambio de estado.
- `role_permissions` define permisos por rol.

## Flujo de documentos

1. El estudiante inicia sesión y accede a su módulo.
2. Selecciona el tipo de documento requerido.
3. Sube el documento en PDF o imagen según el tipo.
4. El backend guarda la metadata en `student_documents` y el archivo en `storage/uploads`.
5. Se genera un registro inicial en `document_histories`.
6. Los funcionarios revisan, comentan y actualizan estado.
7. El sistema guarda el nuevo estado y actualiza la trazabilidad.
8. El estudiante puede reemplazar el archivo y revisar observaciones.

## Endpoints REST principales

- `POST /login`: autenticación.
- `POST /logout`: cerrar sesión.
- `GET /me`: datos del usuario autenticado.
- `GET /api/documents`: lista de documentos y tipos requeridos.
- `POST /api/documents/upload`: subir/reemplazar documento.
- `GET /api/documents/{id}/history`: historial de un documento.
- `PUT /api/documents/{id}/status`: cambiar estado.
- `GET /api/admin/users`: listar usuarios.
- `POST /api/admin/users`: crear usuario.
- `GET /api/admin/document-types`: tipos de documento.
- `GET /api/admin/document-states`: estados del flujo.
- `GET /api/admin/permissions`: lista de permisos.

## Roles y permisos

### Estudiante

- Subir / reemplazar documentos.
- Consultar estado.
- Ver historial.
- Visualizar requerimientos.

### Funcionario Administrativo

- Revisar documentos.
- Aprobar / rechazar.
- Cambiar estados.
- Agregar observaciones.
- Filtrar por estado.

### Administrador

- Gestionar usuarios.
- Configurar permisos.
- Configurar tipos de documento.
- Supervisar el sistema.

## Diseño funcional

- Login seguro con sesiones.
- Dashboard centralizado por rol.
- Panel de seguimiento documental.
- Flujo de estados controlado.
- Validación inicial de archivos por tipo y tamaño.
- Historial de cambios para trazabilidad.
- Módulo administrador para configuración.

## Notas de implementación

- Se utiliza `PDO` para evitar inyecciones SQL.
- Se usa `Session` PHP para persistir usuario autenticado.
- El enrutamiento permite URLs limpias y extensibles.
- El módulo puede escalar con nuevas APIs y páginas de administración.
