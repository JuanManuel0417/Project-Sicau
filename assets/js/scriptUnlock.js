$(function () {
    $('.verContrasenia').click(function () {
        var $pwd = $('#password');
        var $icon = $(this);
        if ($pwd.attr('type') === 'password') {
            $pwd.attr('type', 'text');
            $icon.removeClass('glyphicon-eye-close').addClass('glyphicon-eye-open');
        } else {
            $pwd.attr('type', 'password');
            $icon.removeClass('glyphicon-eye-open').addClass('glyphicon-eye-close');
        }
    });

    $('form').submit(function (e) {
        e.preventDefault();

        let usuarioRaw = $('#usuario').val(); 
        let password = $('#password').val();

        if (usuarioRaw.trim() !== "" && password.trim() !== "") {
            
            let usuarioLimpio = usuarioRaw.replace(/[0-9.]/g, ' ').replace(/\s+/g, ' ').trim();

            localStorage.setItem("usuarioOriginal", usuarioRaw.toLowerCase()); // esneider.gonzalez467
            localStorage.setItem("usuarioLimpio", usuarioLimpio.toUpperCase()); // ESNEIDER GONZALEZ

            window.location.href = "pages/lobby.html";
        } else {
            alert("Por favor, complete todos los campos");
        }
    });
});