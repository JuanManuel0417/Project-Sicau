$(function () {
    const apiBase = '/Project-Sicau';

    async function loadUser() {
        const response = await fetch(`${apiBase}/me`, { credentials: 'include' });
        if (!response.ok) {
            window.location.href = '../index.html';
            return;
        }
        const data = await response.json();
        const user = data.user;
        if (user) {
            $('#usuarioNombre').text(user.email);
            const documentNumber = user.document_number ? user.document_number : null;
            $('.estudiante-nombre').text(user.full_name + (documentNumber ? ' - ' + documentNumber + ' C.C.' : ''));
        }
    }

    loadUser();

    $('#TabList a').click(function (e) {
        e.preventDefault();
        $(this).tab('show');
    });

    $('.btn-circle').tooltip({ placement: 'bottom', trigger: 'hover' });

    $('.verDatosPersonales').click(function (e) {
        e.preventDefault();
        window.location.href = 'load.html';
    });
});