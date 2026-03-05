$(function(){

    // Mostrar / ocultar contraseña
    $('.verContrasenia').click(function(){

        var $pwd = $('#password');
        var $icon = $(this);

        if($pwd.attr('type') === 'password'){
            $pwd.attr('type','text');
            $icon.removeClass('glyphicon-eye-close')
                 .addClass('glyphicon-eye-open');
        }else{
            $pwd.attr('type','password');
            $icon.removeClass('glyphicon-eye-open')
                 .addClass('glyphicon-eye-close');
        }

    });

    // Login
    $('form').submit(function(e){
        e.preventDefault();

        let usuario = $('#usuario').val();
        let password = $('#password').val();

        if(usuario.trim() !== "" && password.trim() !== ""){

            // Guardar usuario
            localStorage.setItem("usuarioActivo", usuario);

            // Ir al lobby
            window.location.href = "pages/lobby.html";

        } else {
            alert("Complete todos los campos");
        }

    });

});