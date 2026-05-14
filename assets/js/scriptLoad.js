$(function () {
    const original = localStorage.getItem("usuarioOriginal");
    const limpio = localStorage.getItem("usuarioLimpio");
    if (original && limpio) {
        if(document.getElementById("usuarioNombre")) $('#usuarioNombre').text(original);
        const ccAleatoria = Math.floor(Math.random() * (9999999999 - 100000 + 1)) + 100000;
        $('.estudiante-nombre').text(limpio + " - " + ccAleatoria + " C.C.");
    }

    $('#TabList a').click(function (e) { e.preventDefault(); $(this).tab('show'); });

    const documentos = [
        { id: 6, nombre: "ICFES Saber 11", descripcion: "Pruebas ICFES saber 11 /", tipo: "pdf", aceptar: ".pdf" },
        { id: 5, nombre: "Servicios Públicos", descripcion: "Última factura de servicios públicos /", tipo: "pdf", aceptar: ".pdf" },
        { id: 4, nombre: "Certificado SISBÉN", descripcion: "Certificado SISBÉN actualizado /", tipo: "pdf", aceptar: ".pdf" },
        { id: 3, nombre: "Foto", descripcion: "Foto tipo carnet, fondo blanco /", tipo: "imagen", aceptar: ".jpg,.jpeg,.png" },
        { id: 2, nombre: "Acta De Grados", descripcion: "Acta de grados o diploma de bachiller /", tipo: "pdf", aceptar: ".pdf" },
        { id: 1, nombre: "Documento de identidad", descripcion: "Cédula de ciudadanía, Tarjeta de identidad /", tipo: "pdf", aceptar: ".pdf" }
    ];

    let db = null;
    let archivosGuardados = {};

    function abrirDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open("SICAU_DocumentosDB", 3);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => { db = request.result; resolve(db); };
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains("documentos")) {
                    db.createObjectStore("documentos", { keyPath: "id" });
                }
            };
        });
    }

    function guardarEnDB(id, nombre, dataURL, tipo, nombreArchivo, tamaño) {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(["documentos"], "readwrite");
            const store = transaction.objectStore("documentos");
            store.put({ id, nombre, dataURL, tipo, nombreArchivo, tamaño, fecha: new Date().toISOString() });
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
        });
    }

    function cargarDocumentosDB() {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(["documentos"], "readonly");
            const store = transaction.objectStore("documentos");
            const request = store.getAll();
            request.onsuccess = () => {
                const docs = {};
                request.result.forEach(doc => { docs[doc.id] = doc; });
                resolve(docs);
            };
            request.onerror = () => reject(request.error);
        });
    }

    function eliminarDeDB(id) {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(["documentos"], "readwrite");
            const store = transaction.objectStore("documentos");
            store.delete(id);
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
        });
    }

    function validarArchivo(file, tipo) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (tipo === 'pdf' && ext !== 'pdf') return { valido: false, mensaje: "Solo PDF" };
        if (tipo === 'imagen' && !['jpg', 'jpeg', 'png'].includes(ext)) return { valido: false, mensaje: "Solo JPG, PNG" };
        if (file.size > 5 * 1024 * 1024) return { valido: false, mensaje: "Máximo 5MB" };
        return { valido: true, mensaje: "Documento válido" };
    }

    function archivoADataURL(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(file);
        });
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // NUEVA FUNCIÓN: Mostrar documento en modal
    function mostrarDocumentoEnModal(doc) {
        if (!doc) {
            alert('El documento no existe o no se pudo cargar');
            return;
        }
        
        const esPDF = doc.tipo === 'pdf' || (doc.nombreArchivo && doc.nombreArchivo.toLowerCase().endsWith('.pdf'));
        const visor = $('#visorDocumento');
        
        // Limpiar visor anterior
        visor.empty();
        
        try {
            if (esPDF) {
                // Para PDFs usar embed
                visor.html(`
                    <embed src="${doc.dataURL}" type="application/pdf" width="100%" height="550px" style="border: none;">
                `);
            } else {
                // Para imágenes
                visor.html(`
                    <img src="${doc.dataURL}" style="max-width: 100%; max-height: 550px; display: block; margin: 0 auto; border: 1px solid #ddd; border-radius: 4px;">
                `);
            }
            
            // Actualizar título del modal
            $('#modalDocumento .modal-title').html(`<i class="fa fa-file"></i> ${doc.nombre} - ${doc.nombreArchivo || 'Documento'}`);
            
            // Mostrar modal
            $('#modalDocumento').modal('show');
            
        } catch (error) {
            console.error('Error al mostrar documento:', error);
            alert('Error al abrir el documento. Intente nuevamente.');
        }
    }

    async function renderizarTabla() {
        if (!db) return;
        archivosGuardados = await cargarDocumentosDB();
        const tbody = $('#listaDocumentos');
        tbody.empty();
        let aprobados = 0;

        for (const doc of documentos) {
            const guardado = archivosGuardados[doc.id];
            const tiene = !!guardado;
            if (tiene) aprobados++;

            const verLink = tiene ? `<a href="#" class="ver-documento-link" data-id="${doc.id}"><i class="fa fa-eye"></i> Ver documento</a>` : '';
            let archivoInfo = '';
            if (tiene) {
                archivoInfo = `<div class="nombre-archivo-mostrar"><i class="fa fa-paperclip"></i> ${guardado.nombreArchivo}<br><span class="tamaño-archivo">(${formatBytes(guardado.tamaño)})</span><button class="btn-eliminar-doc" data-id="${doc.id}">Eliminar</button></div>`;
            } else {
                archivoInfo = `<div class="nombre-archivo-mostrar text-muted">Ningún archivo seleccionado</div>`;
            }

            const estadoHtml = tiene ? `<span class="status-badge status-valid"><i class="fa fa-check-circle"></i> SI</span>` : `<span class="status-badge status-pending"><i class="fa fa-clock"></i> NO</span>`;
            const observaciones = tiene ? `<span class="observaciones-ok"><i class="fa fa-check"></i> Archivo cargado correctamente</span>` : `<span class="observaciones-text"><i class="fa fa-info-circle"></i> Pendiente de carga</span>`;

            const row = `<tr class="documento-row" data-id="${doc.id}">
                            <td><strong>${doc.nombre}</strong></td>
                            <td>${doc.descripcion}<br>${verLink}</td>
                            <td>
                                <input type="file" class="file-input-hidden" id="file-${doc.id}" accept="${doc.aceptar}" style="display:none">
                                <button class="btn-subir-doc" data-id="${doc.id}"><i class="fa fa-upload"></i> Subir</button>
                                <button class="btn-buscar" data-id="${doc.id}"><i class="fa fa-search"></i> Buscar...</button>
                                ${archivoInfo}
                            </td>
                            <td class="text-center">${estadoHtml}</td>
                            <td>${observaciones}</td>
                         </tr>`;
            tbody.append(row);
        }
        const porcentaje = (aprobados / documentos.length) * 100;
        $('#progressBar').css('width', porcentaje + '%').text(Math.round(porcentaje) + '%');
        reasignarEventos();
    }

    async function procesarCarga(file, docId) {
        const doc = documentos.find(d => d.id === docId);
        if (!doc) return;
        const valid = validarArchivo(file, doc.tipo);
        if (!valid.valido) {
            alert(` ${valid.mensaje}`);
            return;
        }
        
        // Mostrar loading
        const btn = $(`.btn-subir-doc[data-id="${docId}"]`);
        const textoOriginal = btn.html();
        btn.html('<i class="fa fa-spinner fa-spin"></i> Cargando...').prop('disabled', true);
        
        try {
            const dataURL = await archivoADataURL(file);
            await guardarEnDB(docId, doc.nombre, dataURL, doc.tipo, file.name, file.size);
            await renderizarTabla();
            alert(' Documento guardado correctamente');
        } catch (error) {
            console.error('Error al guardar:', error);
            alert(' Error al guardar el documento');
        } finally {
            btn.html(textoOriginal).prop('disabled', false);
        }
    }

    function reasignarEventos() {
        // Eventos para subir documentos
        $('.btn-subir-doc, .btn-buscar').off('click').on('click', function () {
            const id = $(this).data('id');
            $(`#file-${id}`).click();
        });
        
        $('.file-input-hidden').off('change').on('change', async function () {
            const file = this.files[0];
            const id = parseInt($(this).attr('id').split('-')[1]);
            if (file) await procesarCarga(file, id);
            $(this).val('');
        });
        
        // Evento para eliminar
        $('.btn-eliminar-doc').off('click').on('click', async function (e) {
            e.preventDefault();
            if (confirm('¿Está seguro de eliminar este documento?')) {
                try {
                    await eliminarDeDB($(this).data('id'));
                    await renderizarTabla();
                    alert(' Documento eliminado correctamente');
                } catch (error) {
                    console.error('Error al eliminar:', error);
                    alert(' Error al eliminar el documento');
                }
            }
        });
        
        // EVENTO MODIFICADO: Ver documento en modal en lugar de ventana nueva
        $('.ver-documento-link').off('click').on('click', function (e) {
            e.preventDefault();
            const docId = $(this).data('id');
            const doc = archivosGuardados[docId];
            if (doc) {
                mostrarDocumentoEnModal(doc);
            } else {
                alert('El documento no está disponible');
            }
        });
    }

    // Inicializar
    abrirDB().then(() => renderizarTabla());
});