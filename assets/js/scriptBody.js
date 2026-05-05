$(function () {
    const nombreLimpio = localStorage.getItem("usuarioLimpio");
    const correoOriginal = localStorage.getItem("usuarioOriginal");
    
    if (correoOriginal) {
        $('#usuarioNombre').text(correoOriginal);
    }

    if (nombreLimpio) {
        const ccAleatoria = Math.floor(Math.random() * (9999999999 - 100000 + 1)) + 100000;

        $('.estudiante-nombre').text(nombreLimpio + " - " + ccAleatoria + " C.C.");
    }

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