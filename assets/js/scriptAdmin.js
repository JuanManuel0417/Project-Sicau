$(function () {
    const apiBase = '/Project-Sicau';
    let stateCatalog = [];
    let selectedDocumentId = null;

    function badgeForState(code, name) {
        const lower = (code || '').toLowerCase();
        const label = name || code || 'Sin estado';
        let cls = 'info';
        if (['aprobado', 'validado_automaticamente'].includes(lower)) cls = 'success';
        else if (['requiere_revision', 'pendiente', 'en_revision', 'requiere_correccion'].includes(lower)) cls = 'warning';
        else if (['rechazado', 'documento_incorrecto', 'documento_ilegible'].includes(lower)) cls = 'danger';
        return `<span class="badge-soft ${cls}"><i class="fa-solid fa-circle"></i> ${label}</span>`;
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('es-CO').format(Number(value || 0));
    }

    function stateIdByCode(code) {
        const state = stateCatalog.find(item => item.code === code);
        return state ? state.id : '';
    }

    async function fetchCurrentUser() {
        const response = await fetch(`${apiBase}/me`, { credentials: 'include' });
        if (!response.ok) {
            window.location.href = '/Project-Sicau/index.html';
            return null;
        }

        const data = await response.json();
        if ((data.user?.role_slug || '').toLowerCase() !== 'administrador' && (data.user?.role_name || '') !== 'Administrador') {
            window.location.href = '/Project-Sicau/index.html';
            return null;
        }

        return data.user;
    }

    async function loadDashboard() {
        const response = await fetch(`${apiBase}/api/admin/dashboard`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok) {
            alert(data.error || 'No fue posible cargar el tablero administrativo');
            return;
        }

        const stats = data.stats || {};
        stateCatalog = data.states || [];

        $('#statTotalDocuments').text(formatNumber(stats.total_documents));
        $('#statAutoValidated').text(formatNumber(stats.automatic_validations));
        $('#statNeedsReview').text(formatNumber(stats.needs_review));
        $('#statInvalid').text(formatNumber(stats.incorrect_documents));
        $('#statValidPercent').text(`${Number(data.valid_percentage || 0).toFixed(2)}%`);

        const stateSummary = (stats.by_state || []).map(item => {
            return `<div class="state-summary-item"><span>${item.state_name}</span><strong>${formatNumber(item.total)}</strong></div>`;
        }).join('');
        $('#stateSummaryList').html(stateSummary || '<div class="empty-state">No hay distribución de estados todavía.</div>');

        $('#stateFilter').empty().append('<option value="">Todos los estados</option>');
        $('#reviewStateSelect').empty();
        stateCatalog.forEach(state => {
            $('#stateFilter').append(`<option value="${state.id}">${state.name}</option>`);
            $('#reviewStateSelect').append(`<option value="${state.id}">${state.name}</option>`);
        });

        renderDocuments(data.documents || []);
    }

    async function loadDocuments() {
        const query = $('#searchDocuments').val() || '';
        const stateId = $('#stateFilter').val() || '';
        const params = new URLSearchParams();
        if (query) params.set('query', query);
        if (stateId) params.set('state_id', stateId);

        const response = await fetch(`${apiBase}/api/admin/documents?${params.toString()}`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok) {
            alert(data.error || 'No fue posible cargar los documentos');
            return;
        }

        renderDocuments(data.documents || []);
    }

    function renderDocuments(documents) {
        const tbody = $('#adminDocumentsBody');
        tbody.empty();

        if (!documents.length) {
            tbody.append('<tr><td colspan="5" class="text-center muted-row">No hay documentos para mostrar.</td></tr>');
            return;
        }

        documents.forEach(doc => {
            const confidence = doc.last_confidence !== null && doc.last_confidence !== undefined ? `${Number(doc.last_confidence).toFixed(2)}%` : 'N/D';
            const row = `
                <tr data-id="${doc.id}">
                    <td>
                        <strong>${doc.student_name || ''}</strong><br>
                        <small>${doc.student_email || ''}</small>
                    </td>
                    <td>
                        ${doc.document_type_name || ''}<br>
                        <small>${doc.detected_document_type_name ? `Detectado: ${doc.detected_document_type_name}` : 'Sin detección automática'}</small>
                    </td>
                    <td>${badgeForState(doc.state_code, doc.state_name)}</td>
                    <td>${confidence}</td>
                    <td><button class="btn btn-xs btn-info btn-view-detail" data-id="${doc.id}"><i class="fa-solid fa-magnifying-glass"></i> Ver</button></td>
                </tr>`;
            tbody.append(row);
        });

        $('.btn-view-detail').off('click').on('click', function (e) {
            e.stopPropagation();
            loadDocumentDetail($(this).data('id'));
        });

        $('#adminDocumentsBody tr[data-id]').off('click').on('click', function () {
            loadDocumentDetail($(this).data('id'));
        });
    }

    function renderComparison(documentData, fields) {
        const items = [];
        const documentName = documentData.student_name || '';
        const documentNumber = documentData.student_document_number || '';

        if (documentName || fields.some(field => ['full_name', 'student_name', 'beneficiary_name'].includes(field.key))) {
            items.push(`<div class="comparison-item"><strong>Nombre</strong><span>Usuario: ${documentName || 'N/D'}<br>OCR: ${fields.find(field => ['full_name', 'student_name', 'beneficiary_name'].includes(field.key))?.value || 'N/D'}</span></div>`);
        }

        if (documentNumber || fields.some(field => field.key === 'document_number')) {
            items.push(`<div class="comparison-item"><strong>Número de documento</strong><span>Usuario: ${documentNumber || 'N/D'}<br>OCR: ${fields.find(field => field.key === 'document_number')?.value || 'N/D'}</span></div>`);
        }

        if (!items.length) {
            items.push('<div class="empty-state">No hay datos suficientes para comparar.</div>');
        }

        $('#comparisonList').html(items.join(''));
    }

    function renderFields(fields) {
        if (!fields.length) {
            $('#fieldsList').html('<div class="empty-state">No se extrajeron campos.</div>');
            return;
        }

        const html = fields.map(field => `
            <div class="data-item">
                <strong>${field.field_label}</strong>
                <div>${field.field_value || 'No detectado'}</div>
                <small>Confianza: ${field.confidence !== null && field.confidence !== undefined ? Number(field.confidence).toFixed(2) + '%' : 'N/D'} · Origen: ${field.source || 'ocr'}</small>
            </div>`).join('');

        $('#fieldsList').html(html);
    }

    function renderHistory(history) {
        if (!history.length) {
            $('#historyList').html('<div class="empty-state">Sin historial de validaciones.</div>');
            return;
        }

        const html = history.map(item => `
            <div class="data-item timeline-item">
                <strong>${item.state_name || 'Cambio de estado'}</strong>
                <div>${item.comment || 'Sin comentario'}</div>
                <small>${item.actor_name || 'Sistema'} · ${item.created_at || ''}</small>
            </div>`).join('');

        $('#historyList').html(html);
    }

    function renderObservations(observations) {
        if (!observations.length) {
            $('#observationsList').html('<div class="empty-state">Sin observaciones registradas.</div>');
            return;
        }

        const html = observations.map(item => `
            <div class="data-item">
                <strong>${item.author_name || 'Sistema'}</strong>
                <div>${item.comment || ''}</div>
                <small>${item.created_at || ''}</small>
            </div>`).join('');

        $('#observationsList').html(html);
    }

    async function loadDocumentDetail(id) {
        selectedDocumentId = id;
        const response = await fetch(`${apiBase}/api/admin/documents/${id}`, { credentials: 'include' });
        const data = await response.json();
        if (!response.ok) {
            alert(data.error || 'No fue posible cargar el detalle');
            return;
        }

        const documentData = data.document || {};
        const analysis = data.analysis || {};
        const fields = data.fields || [];

        $('#detailStudentName').text(documentData.student_name || 'Documento sin estudiante');
        $('#detailDocumentMeta').text(`${documentData.document_type_name || ''} · ${documentData.student_email || ''} · ${documentData.original_filename || ''}`);
        $('#detailSummary').text(analysis.summary || documentData.analysis_summary || 'Sin resumen disponible');
        $('#detailStateBadge').html(badgeForState(documentData.state_code || analysis.status_code, documentData.state_name || analysis.state_name));
        $('#documentPreview').attr('src', `${apiBase}/api/documents/${id}/file`);

        $('#reviewStateSelect').val(stateIdByCode('aprobado') || $('#reviewStateSelect option:first').val());

        renderComparison(documentData, fields);
        renderFields(fields);
        renderHistory(data.history || []);
        renderObservations(data.observations || []);
    }

    async function submitReview(stateId) {
        if (!selectedDocumentId) {
            alert('Selecciona primero un documento');
            return;
        }

        const payload = {
            state_id: Number(stateId),
            comment: $('#reviewComment').val() || ''
        };

        const response = await fetch(`${apiBase}/api/admin/documents/${selectedDocumentId}/review`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'include'
        });
        const result = await response.json();
        if (!response.ok) {
            alert(result.error || 'No fue posible guardar la revisión');
            return;
        }

        $('#reviewComment').val('');
        await loadDashboard();
        await loadDocumentDetail(selectedDocumentId);
        await loadDocuments();
    }

    async function reanalyzeSelectedDocument() {
        if (!selectedDocumentId) {
            alert('Selecciona primero un documento');
            return;
        }

        const response = await fetch(`${apiBase}/api/admin/documents/${selectedDocumentId}/reanalyze`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include'
        });
        const result = await response.json();

        if (!response.ok) {
            alert(result.error || 'No fue posible reanalizar el documento');
            return;
        }

        await loadDashboard();
        await loadDocumentDetail(selectedDocumentId);
        await loadDocuments();
    }

    async function logout() {
        await fetch(`${apiBase}/logout`, { method: 'POST', credentials: 'include' });
        window.location.href = '/Project-Sicau/index.html';
    }

    $('#searchDocuments').on('input', function () {
        clearTimeout(window.__adminSearchTimer);
        window.__adminSearchTimer = setTimeout(loadDocuments, 250);
    });

    $('#stateFilter').on('change', loadDocuments);
    $('#btnReloadAdmin').on('click', async function () {
        await loadDashboard();
    });
    $('#btnLogoutAdmin').on('click', logout);
    $('#btnReanalyzeDocument').on('click', reanalyzeSelectedDocument);
    $('#btnApproveDocument').on('click', function () {
        const stateId = stateIdByCode('aprobado') || $('#reviewStateSelect').val();
        submitReview(stateId);
    });
    $('#btnRejectDocument').on('click', function () {
        const stateId = stateIdByCode('rechazado') || $('#reviewStateSelect').val();
        submitReview(stateId);
    });
    $('#btnApplyReview').on('click', function () {
        submitReview($('#reviewStateSelect').val());
    });

    (async function init() {
        const user = await fetchCurrentUser();
        if (!user) {
            return;
        }

        await loadDashboard();
        await loadDocuments();
    })();
});