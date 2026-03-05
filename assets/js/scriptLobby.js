let slides = document.querySelectorAll(".slide");
let dots = document.querySelectorAll(".dot");
let current = 0;

function showSlide(index) {
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

setInterval(nextSlide, 5000);

document.addEventListener("DOMContentLoaded", function () {
  let usuario = localStorage.getItem("usuarioActivo");

  if (usuario) {
    document.getElementById("usuarioNombre").textContent = usuario;
    let nombre = usuario.split(".")[0];
    nombre = nombre.charAt(0).toUpperCase() + nombre.slice(1);

    document.querySelector(".mensajeBienvenida").textContent =
      "Hola " + nombre + ", Bienvenido a SICAU!!!";
  } else {
    window.location.href = "../index.html";
  }

  document
    .querySelector(".logout-line a")
    .addEventListener("click", function (e) {
      e.preventDefault();
      localStorage.removeItem("usuarioActivo");
      window.location.href = "../index.html";
    });
});
