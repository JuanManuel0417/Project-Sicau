# SICAU - Validación documental inteligente

## Objetivo

Agregar una capa de análisis documental para que el sistema identifique, valide y clasifique automáticamente los archivos subidos por estudiantes antes de la revisión administrativa.

## Arquitectura propuesta

- `app/Services/DocumentIntelligenceService.php`: clasificador heurístico con OCR opcional.
- `app/Models/DocumentAnalysis.php`: persistencia de ejecuciones de análisis.
- `app/Models/DocumentObservation.php`: observaciones automáticas y manuales.
- `app/Models/StudentDocument.php`: documento principal con estado, confianza y trazabilidad.
- `app/Controllers/DocumentController.php`: dispara el análisis al momento de subir.
- `app/Controllers/AdminController.php`: expone el panel y las APIs de revisión.

## Tablas nuevas

- `document_analyses`: ejecución completa del análisis por documento.
- `document_extracted_fields`: campos extraídos por OCR o heurística.
- `student_documents`: columnas de apoyo para estado automático, confianza y resumen.

## Estados automáticos

- `pendiente`
- `validado_automaticamente`
- `requiere_revision`
- `documento_incorrecto`
- `documento_ilegible`
- `aprobado`
- `rechazado`

## Flujo

1. El estudiante sube el archivo.
2. El sistema intenta leer el PDF o la imagen.
3. Se clasifica el documento por palabras clave y metadatos.
4. Se extraen campos relevantes según el tipo detectado.
5. Se calcula una confianza y una calidad de archivo.
6. Se guarda el resultado y se genera historial.
7. El administrador revisa desde el panel HTML dedicado.

## Nota de implementación

El motor está preparado para usar OCR local si existe `pdftotext` o `tesseract` en el servidor. Si no están disponibles, el sistema sigue funcionando con heurísticas y marca el documento para revisión cuando no puede confirmar el contenido con suficiente confianza.