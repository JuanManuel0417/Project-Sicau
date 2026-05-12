# Historias Técnicas y Épicas

## Épica 1 — Carga de documentos

### Historia técnica 1.1
- Implementar login y autenticación segura.
- Crear formulario de carga que permita seleccionar tipo de documento.
- Validar extensión y tamaño antes de enviar el archivo.
- Guardar metadata en `student_documents` y archivo en `storage/uploads`.

### Historia técnica 1.2
- Definir catálogo de `document_types` en la base de datos.
- Mostrar lista de documentos requeridos con descripción y estado.
- Permitir reemplazar documento existente.
- Generar registro inicial en `document_histories`.

## Épica 2 — Procesamiento automático

### Historia técnica 2.1
- Implementar `validation_rules` para cada tipo de documento.
- Crear servicio de validación de archivos por extensión y tamaño.
- Preparar la base para procesamiento automático con carga de metadata.

### Historia técnica 2.2
- Generar estado inicial `pendiente` al cargar un documento.
- Permitir transición automática a `en_revision` o `requiere_correccion`.
- Registrar cada cambio en el historial para trazabilidad.

## Épica 3 — Gestión y visualización del estado

### Historia técnica 3.1
- Definir `document_statuses` y flujo de estados.
- Crear endpoints para listar documentos del estudiante y su estado actual.
- Crear endpoint de historial por documento.

### Historia técnica 3.2
- Implementar panel administrativo con filtros por estado.
- Permitir actualización de estado y adición de comentarios.
- Registrar los cambios con usuario que realiza la acción.

## Requerimientos funcionales cubiertos

- Registro e inicio de sesión.
- Roles y permisos.
- Gestión de usuarios.
- Subida de documentos PDF e imágenes.
- Reemplazo de archivos.
- Validación de formato y tamaño.
- Estados de documento: pendiente, en revisión, aprobado, rechazado, requiere corrección.
- Historial de cambios.
- Observaciones administrativas.
- Dashboard y filtros.
- Trazabilidad completa.
