# SICAU - Sistema de Control y Administración Universitaria/Académica

Este proyecto implementa la base del módulo SICAU para gestión documental académica con PHP, MySQL y arquitectura MVC.

## Qué incluye

- Backend PHP MVC con rutas REST.
- Modelo relacional MySQL para usuarios, roles, permisos, documentos, estados e historial.
- Controladores para autenticación, gestión documental y administración.
- Capa de validación documental inteligente con OCR, clasificación y trazabilidad.
- Almacenamiento seguro de archivos en `storage/uploads`.
- Diseño de APIs para los flujos de estudiante, funcionario y administrador.

## Cómo iniciar

1. Copia el proyecto a tu carpeta `htdocs` de XAMPP.
2. Importa `database/sicau_schema.sql` en phpMyAdmin.
3. Ajusta `config/config.php` si tu usuario/contraseña MySQL difieren.
4. Asegúrate de que `storage/uploads` sea escribible.
5. Abre `http://localhost/Project-Sicau/` en tu navegador.

## Usuarios iniciales

- Administrador:
  - Correo: `admin@cas.edu`
  - Contraseña: `Admin123!`
- Estudiante de ejemplo:
  - Correo: `estudiante@cas.edu`
  - Contraseña: `Admin123!`

## Endpoints clave

- `POST /login`
- `POST /logout`
- `GET /me`
- `GET /api/documents`
- `POST /api/documents/upload`
- `GET /api/documents/{id}/history`
- `PUT /api/documents/{id}/status`
- `GET /api/admin/users`
- `POST /api/admin/users`
- `GET /api/admin/document-types`
- `GET /api/admin/document-states`
- `GET /api/admin/permissions`
- `GET /api/admin/dashboard`
- `GET /api/admin/documents`
- `GET /api/admin/documents/{id}`
- `PUT /api/admin/documents/{id}/review`

## Panel administrativo

- Acceso directo: `http://localhost/Project-Sicau/pages/admin.html`
- Permite filtrar documentos, revisar el OCR, comparar campos extraídos y aprobar o rechazar manualmente.
- El sistema guarda el análisis en tablas de auditoría y mantiene historial de validaciones.

## Flujo inteligente

1. El estudiante sube el archivo desde el módulo de documentos.
2. El backend intenta extraer texto con OCR o herramientas locales disponibles.
3. Se identifica el tipo documental esperado y el detectado.
4. Se calculan calidad, confianza, observaciones y estado automático.
5. Se almacenan análisis, campos extraídos e historial de trazabilidad.
6. El administrador revisa el resultado desde el panel y toma una decisión manual si hace falta.

## Notas

- El proyecto está preparado para ampliarse con vistas front-end y paneles específicos.
- El backend usa sesiones PHP y PDO con consultas parametrizadas.
- El flujo documental está normalizado y permite trazabilidad completa.
