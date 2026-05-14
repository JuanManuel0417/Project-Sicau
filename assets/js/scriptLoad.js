$(function () {
    const apiBase = '/Project-Sicau';
    let documentos = [];

    async function fetchCurrentUser() {
        const response = await fetch(`${apiBase}/me`, { credentials: 'include' });
        if (!response.ok) {
            window.location.href = '../index.html';
            return null;
        }
        const data = await response.json();
        return data.user;
    }

    async function loadDocuments() {
        const response = await fetch(`${apiBase}/api/documents`, { credentials: 'include' });
        if (!response.ok) {
            alert('No se pudo cargar los documentos. Por favor inicie sesión nuevamente.');
            window.location.href = '../index.html';
            return;
        }
        const data = await response.json();
        documentos = data.documents || [];
        renderizarTabla();
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function renderizarTabla() {
        const tbody = $('#listaDocumentos');
        tbody.empty();
        let completados = 0;

        if (documentos.length === 0) {
            tbody.append('<tr><td colspan="5" class="text-center muted-row">No hay tipos de documento configurados o no hay documentos disponibles para este usuario.</td></tr>');
        } else {
            documentos.forEach(doc => {
                const uploaded = doc.uploaded;
                const tiene = !!uploaded;
                if (tiene) completados++;
                const estadoHtml = tiene ? `<span class="status-badge status-valid"><i class="fa fa-check-circle"></i> ${uploaded.state_name}</span>` : `<span class="status-badge status-pending"><i class="fa fa-clock"></i> Pendiente</span>`;
                const observaciones = tiene ? `<span class="observaciones-text"><i class="fa fa-info-circle"></i> ${uploaded.state_name}</span>` : `<span class="observaciones-text"><i class="fa fa-info-circle"></i> Pendiente de carga</span>`;
                const archivoInfo = tiene ? `<div class="nombre-archivo-mostrar"><i class="fa fa-paperclip"></i> ${uploaded.original_filename}<br><span class="tamaño-archivo">(${formatBytes(uploaded.file_size)})</span></div>` : `<div class="nombre-archivo-mostrar text-muted">Ningún archivo seleccionado</div>`;
                const viewButton = tiene ? `<button class="btn btn-xs btn-info btn-view-doc" data-id="${uploaded.id}"><i class="fa fa-eye"></i> Ver</button>` : '';

                const acceptExtensions = (doc.allowed_extensions || '')
                    .split(',')
                    .map(ext => ext.trim())
                    .filter(Boolean)
                    .map(ext => ext.startsWith('.') ? ext : '.' + ext)
                    .join(',');

                const row = `<tr class="documento-row" data-id="${doc.id}">
                    <td><strong>${doc.name}</strong></td>
                    <td>${doc.description}</td>
                    <td>
                        <input type="file" class="file-input-hidden" id="file-${doc.id}" accept="${acceptExtensions}" style="display:none">
                        <button class="btn-subir-doc btn btn-sm btn-primary" data-id="${doc.id}"><i class="fa fa-upload"></i> Subir</button>
                        <button class="btn-buscar btn btn-sm btn-default" data-id="${doc.id}"><i class="fa fa-search"></i> Buscar...</button>
                        ${archivoInfo}
                    </td>
                    <td class="text-center">${estadoHtml}</td>
                    <td>${observaciones} ${viewButton}</td>
                </tr>`;
                tbody.append(row);
            });
        }

        const porcentaje = documentos.length ? (completados / documentos.length) * 100 : 0;
        $('#progressBar').css('width', porcentaje + '%').text(Math.round(porcentaje) + '%');
        reasignarEventos();
    }

    async function procesarCarga(file, tipoId) {
        if (!file) return;

        const formData = new FormData();
        formData.append('document_type_id', tipoId);
        formData.append('document', file);

        const response = await fetch(`${apiBase}/api/documents/upload`, {
            method: 'POST',
            body: formData,
            credentials: 'include'
        });
        const result = await response.json();
        if (!response.ok) {
            alert(result.error || 'Error al subir el documento');
            return;
        }
        await loadDocuments();
    }

    function reasignarEventos() {
        $('.btn-subir-doc, .btn-buscar').off('click').on('click', function () {
            const id = $(this).data('id');
            $(`#file-${id}`).click();
        });

        $('.file-input-hidden').off('change').on('change', async function () {
            const file = this.files[0];
            const id = parseInt($(this).attr('id').split('-')[1], 10);
            if (file) await procesarCarga(file, id);
            $(this).val('');
        });

        $('.btn-view-doc').off('click').on('click', function () {
            const id = $(this).data('id');
            window.open(`${apiBase}/api/documents/${id}/file`, '_blank');
        });
    }

    $('#TabList a').click(function (e) { e.preventDefault(); $(this).tab('show'); });

    (async function init() {
        const user = await fetchCurrentUser();
        if (!user) return;
        if (document.getElementById('usuarioNombre')) {
            $('#usuarioNombre').text(user.email);
        }
        const documentNumber = user.document_number ? user.document_number : null;
        $('.estudiante-nombre').text(user.full_name + (documentNumber ? ' - ' + documentNumber + ' C.C.' : ''));
        await loadDocuments();
    })();
});