/* =============================================
   SICAU — Panel Administrativo | scriptAdmin.js
   ============================================= */
$(function () {
    const apiBase = '/Project-Sicau';
    let stateCatalog = [];
    let selectedDocumentId = null;
    let allDocuments = [];
    let isSubmitting = false;  // Guard anti-doble-submit

    /* ─── Loader de página ─── */
    const $loader = $('<div class="page-loader"></div>').appendTo('body');
    function showLoader() { $loader.addClass('active'); }
    function hideLoader() { $loader.removeClass('active'); }

    /* ─── Toast Notifications ─── */
    if (!$('#toast-container').length) $('<div id="toast-container"></div>').appendTo('body');

    function toast(msg, type = 'info', duration = 3500) {
        const icons = { success: 'fa-check-circle', error: 'fa-triangle-exclamation', info: 'fa-circle-info', warning: 'fa-circle-exclamation' };
        const $t = $(`
            <div class="toast-msg toast-${type}">
                <i class="fa-solid ${icons[type] || icons.info}"></i>
                <span>${msg}</span>
            </div>
        `).appendTo('#toast-container');

        setTimeout(() => {
            $t.addClass('toast-out');
            setTimeout(() => $t.remove(), 350);
        }, duration);
    }

    /* ─── Modal de confirmación ─── */
    function confirm(title, msg) {
        return new Promise(resolve => {
            const $overlay = $(`
                <div class="confirm-overlay">
                    <div class="confirm-box">
                        <h3>${title}</h3>
                        <p>${msg}</p>
                        <div class="confirm-actions">
                            <button class="btn btn-ghost" id="confirmCancel">Cancelar</button>
                            <button class="btn btn-danger" id="confirmOk">Confirmar</button>
                        </div>
                    </div>
                </div>
            `).appendTo('body');

            $overlay.find('#confirmOk').on('click', () => { $overlay.remove(); resolve(true); });
            $overlay.find('#confirmCancel').on('click', () => { $overlay.remove(); resolve(false); });
            $overlay.on('click', function (e) { if (e.target === this) { $overlay.remove(); resolve(false); } });
        });
    }

    /* ─── Animación de contadores ─── */
    function animateCount($el, targetRaw) {
        const isPercent = String(targetRaw).includes('%');
        const target = parseFloat(String(targetRaw).replace('%', '').replace(/\./g, '').replace(',', '.')) || 0;
        const decimals = isPercent ? 2 : 0;
        const duration = 900;
        const start = performance.now();
        const from = 0;

        function tick(now) {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            const current = from + (target - from) * eased;
            const formatted = new Intl.NumberFormat('es-CO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(current);
            $el.text(isPercent ? formatted + '%' : formatted);
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    /* ─── Skeleton rows ─── */
    function renderSkeletonTable() {
        const rows = Array.from({ length: 5 }, () =>
            `<tr><td colspan="5"><span class="skeleton skeleton-row"></span></td></tr>`
        ).join('');
        $('#adminDocumentsBody').html(rows);
    }

    /* ─── Helpers ─── */
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

    /* ─── Validación de sesión / rol ─── */
    async function fetchCurrentUser() {
        try {
            const response = await fetch(`${apiBase}/me`, { credentials: 'include' });
            if (!response.ok) {
                toast('Sesión expirada. Redirigiendo…', 'warning');
                setTimeout(() => { window.location.href = '/Project-Sicau/index.html'; }, 1500);
                return null;
            }
            const data = await response.json();
            const role = (data.user?.role_slug || '').toLowerCase();
            const roleName = data.user?.role_name || '';
            if (role !== 'administrador' && roleName !== 'Administrador') {
                toast('Acceso denegado. Solo administradores.', 'error');
                setTimeout(() => { window.location.href = '/Project-Sicau/index.html'; }, 2000);
                return null;
            }
            return data.user;
        } catch (err) {
            toast('Error de red al verificar sesión.', 'error');
            return null;
        }
    }

    /* ─── Dashboard ─── */
    async function loadDashboard() {
        showLoader();
        try {
            const response = await fetch(`${apiBase}/api/admin/dashboard`, { credentials: 'include' });
            const data = await response.json();
            if (!response.ok) {
                toast(data.error || 'No fue posible cargar el tablero.', 'error');
                return;
            }

            const stats = data.stats || {};
            stateCatalog = data.states || [];

            // Animar contadores
            animateCount($('#statTotalDocuments'), stats.total_documents || 0);
            animateCount($('#statAutoValidated'), stats.automatic_validations || 0);
            animateCount($('#statNeedsReview'), stats.needs_review || 0);
            animateCount($('#statInvalid'), stats.incorrect_documents || 0);
            animateCount($('#statValidPercent'), `${Number(data.valid_percentage || 0).toFixed(2)}%`);

            const stateSummary = (stats.by_state || []).map(item =>
                `<div class="state-summary-item">
                    <span>${item.state_name}</span>
                    <strong>${formatNumber(item.total)}</strong>
                </div>`
            ).join('');
            $('#stateSummaryList').html(stateSummary || '<div class="empty-state">No hay distribución de estados todavía.</div>');

            $('#stateFilter').empty().append('<option value="">Todos los estados</option>');
            $('#reviewStateSelect').empty();
            stateCatalog.forEach(state => {
                $('#stateFilter').append(`<option value="${state.id}">${state.name}</option>`);
                $('#reviewStateSelect').append(`<option value="${state.id}">${state.name}</option>`);
            });

            renderDocuments(data.documents || []);
        } catch (err) {
            toast('Error de red al cargar el tablero.', 'error');
        } finally {
            hideLoader();
        }
    }

    /* ─── Documentos ─── */
    async function loadDocuments() {
        const query = $('#searchDocuments').val() || '';
        const stateId = $('#stateFilter').val() || '';
        const params = new URLSearchParams();
        if (query) params.set('query', query);
        if (stateId) params.set('state_id', stateId);

        renderSkeletonTable();
        showLoader();
        try {
            const response = await fetch(`${apiBase}/api/admin/documents?${params.toString()}`, { credentials: 'include' });
            const data = await response.json();
            if (!response.ok) {
                toast(data.error || 'No fue posible cargar los documentos.', 'error');
                return;
            }
            allDocuments = data.documents || [];
            renderDocuments(allDocuments);
        } catch (err) {
            toast('Error de red al cargar documentos.', 'error');
        } finally {
            hideLoader();
        }
    }

    function renderDocuments(documents) {
        const tbody = $('#adminDocumentsBody');
        tbody.empty();

        if (!documents.length) {
            tbody.append('<tr><td colspan="5" class="text-center muted-row">No hay documentos para mostrar.</td></tr>');
            return;
        }

        documents.forEach((doc, i) => {
            const confidence = doc.last_confidence !== null && doc.last_confidence !== undefined
                ? `${Number(doc.last_confidence).toFixed(2)}%` : 'N/D';
            const isSelected = selectedDocumentId && Number(doc.id) === Number(selectedDocumentId);
            const row = $(`
                <tr data-id="${doc.id}" style="animation-delay:${i * 0.03}s" class="${isSelected ? 'row-selected' : ''}">
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
                </tr>`);
            tbody.append(row);
        });

        // Eventos de click
        $('.btn-view-detail').off('click').on('click', function (e) {
            e.stopPropagation();
            loadDocumentDetail($(this).data('id'));
        });
        $('#adminDocumentsBody tr[data-id]').off('click').on('click', function () {
            loadDocumentDetail($(this).data('id'));
        });
    }

    /* ─── Detalle de documento ─── */
    function renderComparison(documentData, fields) {
        const items = [];
        const documentName = documentData.student_name || '';
        const documentNumber = documentData.student_document_number || '';

        if (documentName || fields.some(f => ['full_name', 'student_name', 'beneficiary_name'].includes(f.key))) {
            const ocrName = fields.find(f => ['full_name', 'student_name', 'beneficiary_name'].includes(f.key))?.value || 'N/D';
            const match = documentName && ocrName !== 'N/D' && documentName.toLowerCase() === ocrName.toLowerCase();
            items.push(`
                <div class="comparison-item">
                    <strong>Nombre ${match ? '<span style="color:#36d399;font-size:11px;">✔ Coincide</span>' : ''}</strong>
                    <span>Usuario: ${documentName || 'N/D'}<br>OCR: ${ocrName}</span>
                </div>`);
        }
        if (documentNumber || fields.some(f => f.key === 'document_number')) {
            const ocrNum = fields.find(f => f.key === 'document_number')?.value || 'N/D';
            const match = documentNumber && ocrNum !== 'N/D' && documentNumber === ocrNum;
            items.push(`
                <div class="comparison-item">
                    <strong>Número de documento ${match ? '<span style="color:#36d399;font-size:11px;">✔ Coincide</span>' : ''}</strong>
                    <span>Usuario: ${documentNumber || 'N/D'}<br>OCR: ${ocrNum}</span>
                </div>`);
        }
        if (!items.length) items.push('<div class="empty-state">No hay datos suficientes para comparar.</div>');
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

        // Marcar fila seleccionada
        $('#adminDocumentsBody tr').removeClass('row-selected');
        $(`#adminDocumentsBody tr[data-id="${id}"]`).addClass('row-selected');

        // Skeleton en el panel derecho
        $('#detailStudentName').html('<span class="skeleton skeleton-title"></span>');
        $('#detailDocumentMeta').html('<span class="skeleton skeleton-text" style="width:80%"></span>');
        $('#detailSummary').html('<span class="skeleton skeleton-text"></span><span class="skeleton skeleton-text" style="width:70%"></span>');
        $('#detailStateBadge').text('Cargando…');
        $('#documentPreview').removeClass('loaded').attr('src', '');

        showLoader();
        try {
            const response = await fetch(`${apiBase}/api/admin/documents/${id}`, { credentials: 'include' });
            const data = await response.json();
            if (!response.ok) {
                toast(data.error || 'No fue posible cargar el detalle.', 'error');
                return;
            }

            const documentData = data.document || {};
            const analysis = data.analysis || {};
            const fields = data.fields || [];

            $('#detailStudentName').text(documentData.student_name || 'Documento sin estudiante');
            $('#detailDocumentMeta').text(`${documentData.document_type_name || ''} · ${documentData.student_email || ''} · ${documentData.original_filename || ''}`);
            $('#detailSummary').text(analysis.summary || documentData.analysis_summary || 'Sin resumen disponible');
            $('#detailStateBadge').html(badgeForState(documentData.state_code || analysis.status_code, documentData.state_name || analysis.state_name));

            // Cargar iframe con fade
            const $iframe = $('#documentPreview');
            $iframe.off('load').on('load', function () {
                $(this).addClass('loaded');
            });
            $iframe.attr('src', `${apiBase}/api/documents/${id}/file`);

            $('#reviewStateSelect').val(stateIdByCode('aprobado') || $('#reviewStateSelect option:first').val());

            renderComparison(documentData, fields);
            renderFields(fields);
            renderHistory(data.history || []);
            renderObservations(data.observations || []);

            // Scroll suave al panel derecho en mobile
            if (window.innerWidth < 1180) {
                $('.panel-right')[0]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } catch (err) {
            toast('Error de red al cargar el detalle.', 'error');
        } finally {
            hideLoader();
        }
    }

    /* ─── Submit con validaciones y anti-doble-click ─── */
    async function submitReview(stateId) {
        // Restricción: debe haber documento seleccionado
        if (!selectedDocumentId) {
            toast('Selecciona primero un documento de la lista.', 'warning');
            return;
        }
        // Restricción: debe haber estado seleccionado
        if (!stateId) {
            toast('Selecciona un estado para aplicar.', 'warning');
            return;
        }
        // Anti-doble-submit
        if (isSubmitting) {
            toast('Ya se está guardando la revisión. Espera un momento.', 'info');
            return;
        }

        isSubmitting = true;
        const $btn = $('#btnApplyReview').addClass('is-loading').prop('disabled', true);
        showLoader();

        const payload = {
            state_id: Number(stateId),
            comment: ($('#reviewComment').val() || '').trim()
        };

        try {
            const response = await fetch(`${apiBase}/api/admin/documents/${selectedDocumentId}/review`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'include'
            });
            const result = await response.json();
            if (!response.ok) {
                toast(result.error || 'No fue posible guardar la revisión.', 'error');
                return;
            }

            toast('Revisión guardada exitosamente.', 'success');
            $('#reviewComment').val('');
            await loadDashboard();
            await loadDocumentDetail(selectedDocumentId);
            await loadDocuments();
        } catch (err) {
            toast('Error de red al guardar la revisión.', 'error');
        } finally {
            isSubmitting = false;
            $btn.removeClass('is-loading').prop('disabled', false);
            hideLoader();
        }
    }

    /* ─── Reanalizar ─── */
    async function reanalyzeSelectedDocument() {
        if (!selectedDocumentId) {
            toast('Selecciona primero un documento.', 'warning');
            return;
        }
        if (isSubmitting) return;

        const ok = await confirm('Reanalizar documento', '¿Querés que el motor inteligente reanalice este documento? Se sobrescribirá el análisis anterior.');
        if (!ok) return;

        isSubmitting = true;
        const $btn = $('#btnReanalyzeDocument').addClass('is-loading').prop('disabled', true);
        showLoader();

        try {
            const response = await fetch(`${apiBase}/api/admin/documents/${selectedDocumentId}/reanalyze`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include'
            });
            const result = await response.json();
            if (!response.ok) {
                toast(result.error || 'No fue posible reanalizar el documento.', 'error');
                return;
            }
            toast('Documento reanálizado correctamente.', 'success');
            await loadDashboard();
            await loadDocumentDetail(selectedDocumentId);
            await loadDocuments();
        } catch (err) {
            toast('Error de red al reanalizar.', 'error');
        } finally {
            isSubmitting = false;
            $btn.removeClass('is-loading').prop('disabled', false);
            hideLoader();
        }
    }

    /* ─── Logout ─── */
    async function logout() {
        const ok = await confirm('Cerrar sesión', '¿Estás seguro que querés salir del panel administrativo?');
        if (!ok) return;
        showLoader();
        try {
            await fetch(`${apiBase}/logout`, { method: 'POST', credentials: 'include' });
        } finally {
            window.location.href = '/Project-Sicau/index.html';
        }
    }

    /* ─── Aprobar / Rechazar con confirmación ─── */
    async function approveDocument() {
        if (!selectedDocumentId) { toast('Selecciona un documento primero.', 'warning'); return; }
        const ok = await confirm('Aprobar documento', '¿Confirmas la aprobación de este documento? Se registrará en el historial.');
        if (!ok) return;
        const stateId = stateIdByCode('aprobado') || $('#reviewStateSelect').val();
        await submitReview(stateId);
    }

    async function rejectDocument() {
        if (!selectedDocumentId) { toast('Selecciona un documento primero.', 'warning'); return; }
        const ok = await confirm('Rechazar documento', '¿Confirmas el rechazo? El estudiante deberá volver a cargar el documento.');
        if (!ok) return;
        const stateId = stateIdByCode('rechazado') || $('#reviewStateSelect').val();
        await submitReview(stateId);
    }

    /* ─── Eventos ─── */
    // Búsqueda con debounce
    let searchTimer;
    $('#searchDocuments').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadDocuments, 280);
    });

    // Prevenir submit con Enter en el campo de búsqueda
    $('#searchDocuments').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); loadDocuments(); }
    });

    $('#stateFilter').on('change', loadDocuments);
    $('#btnReloadAdmin').on('click', async function () {
        $(this).addClass('is-loading').prop('disabled', true);
        await loadDashboard();
        $(this).removeClass('is-loading').prop('disabled', false);
        toast('Panel actualizado.', 'info');
    });
    $('#btnLogoutAdmin').on('click', logout);
    $('#btnReanalyzeDocument').on('click', reanalyzeSelectedDocument);
    $('#btnApproveDocument').on('click', approveDocument);
    $('#btnRejectDocument').on('click', rejectDocument);
    $('#btnApplyReview').on('click', function () { submitReview($('#reviewStateSelect').val()); });

    // Confirmar antes de salir si hay documento seleccionado con cambios pendientes
    window.addEventListener('beforeunload', function (e) {
        if (selectedDocumentId && $('#reviewComment').val().trim()) {
            e.preventDefault();
            e.returnValue = 'Tenés un comentario sin guardar. ¿Querés salir?';
        }
    });

    // Atajos de teclado: Escape cierra modales
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.confirm-overlay').remove();
        }
        // Ctrl+R recarga el dashboard (sin recargar la página)
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            $('#btnReloadAdmin').trigger('click');
        }
    });

    /* ─── Init ─── */
    (async function init() {
        // Skeleton inicial en stats
        $('.stat-card strong').each(function () { $(this).html('<span class="skeleton skeleton-text" style="height:30px;width:60px;display:inline-block;"></span>'); });

        const user = await fetchCurrentUser();
        if (!user) return;

        await loadDashboard();
        await loadDocuments();
    })();
});