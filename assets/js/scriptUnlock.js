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

    $('form').submit(async function (e) {
        e.preventDefault();

        let usuarioRaw = $('#usuario').val();
        let password = $('#password').val();

        if (usuarioRaw.trim() === "" || password.trim() === "") {
            alert("Por favor, complete todos los campos");
            return;
        }

        let email = usuarioRaw.toLowerCase();
        if (!email.includes('@')) {
            email = `${email}@pascualbravo.edu.co`;
        }

        try {
            const response = await fetch('/Project-Sicau/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ email, password }),
                credentials: 'include'
            });
            const result = await response.json();

            if (!response.ok) {
                alert(result.error || 'Credenciales inválidas');
                return;
            }

            let usuarioLimpio = usuarioRaw.replace(/[0-9.]/g, ' ').replace(/\s+/g, ' ').trim();
            localStorage.setItem("usuarioOriginal", email);
            localStorage.setItem("usuarioLimpio", usuarioLimpio.toUpperCase());
            localStorage.setItem("roleName", result.user.role_name ?? 'Estudiante');
            localStorage.setItem("roleSlug", result.user.role_slug ?? 'estudiante');

            if (result.user.role_slug === 'administrador' || result.user.role_name === 'Administrador') {
                window.location.href = "pages/admin.html";
            } else {
                window.location.href = "pages/body.html";
            }
        } catch (error) {
            alert('Error de conexión con el servidor');
        }
    });
});