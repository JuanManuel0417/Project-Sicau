let slides = document.querySelectorAll(".slide");
let dots = document.querySelectorAll(".dot");
let current = 0;

function showSlide(index) {
    if(!slides.length) return;
    slides.forEach((slide) => slide.classList.remove("active"));
    dots.forEach((dot) => dot.classList.remove("active"));
    slides[index].classList.add("active");
    dots[index].classList.add("active");
}

function nextSlide() {
    current++;
    if (current >= slides.length) current = 0;
    showSlide(current);
}

if(slides.length > 0) setInterval(nextSlide, 5000);

document.addEventListener("DOMContentLoaded", function () {

    const original = localStorage.getItem("usuarioOriginal");
    const limpio = localStorage.getItem("usuarioLimpio");

    if (original && limpio) {
        if(document.getElementById("usuarioNombre")) {
            document.getElementById("usuarioNombre").textContent = original;
        }

        let primerNombre = limpio.split(" ")[0]; 
        let bienvenida = document.querySelector(".mensajeBienvenida");
        if(bienvenida) {
            bienvenida.textContent = "Hola " + primerNombre + ", Bienvenido a SICAU!!!";
        }

        const ccAleatoria = Math.floor(Math.random() * (9999999999 - 100000 + 1)) + 100000;
        $('.estudiante-nombre').text(limpio + " - " + ccAleatoria + " C.C.");

    } else {
        // Si no hay datos, seguridad: al index
        window.location.href = "../index.html";
    }

    let logoutBtn = document.querySelector(".logout-line a");
    if(logoutBtn) {
        logoutBtn.addEventListener("click", function (e) {
            e.preventDefault();
            localStorage.clear();
            window.location.href = "../index.html";
        });
    }

    // Tooltips y Tabs
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