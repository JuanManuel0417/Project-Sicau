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
            const ccAleatoria = Math.floor(Math.random() * (9999999999 - 100000 + 1)) + 100000;
            $('.estudiante-nombre').text(user.full_name + " - " + ccAleatoria + " C.C.");
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