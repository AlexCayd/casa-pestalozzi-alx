/* ┌──────────────────────────────────────────────────────────────┐
   │  PANADERÍA — arranque de la landing /panaderia.              │
   └──────────────────────────────────────────────────────────────┘

   Va en su propio bundle (panaderia.min.js, paths.panaderiaJs en
   gulpfile.js), detrás de la base de marca: app.js —el estado compartido:
   $, $$, body, reduce, T— y los módulos que la panadería usa de la portada
   (Lenis, cursor, imán, menú, visor, revelados, negativo de la marca y
   pestañas). La lista del bundle es la declaración de qué toma de la marca.

   El boot() de app.js corre también aquí y sale solo en su guarda
   `data-page !== "home"`; lo que hace antes de esa línea (el año del pie, el
   registro de ScrollTrigger, el cursor) también le sirve a esta página. */

/* La marca fija y el botón de menú se retiran mientras la portada pasa bajo
   ellos (body.marca-en-hero, la misma clase de la portada del restaurante):
   el isologo grande ya dice el nombre y saldría dos veces. La clase nace en
   el marcado; aquí sólo se corrige al hacer scroll.

   El mismo observador pausa la corona del medallón cuando la portada no se
   ve (.pan-portada--quieta): es transform sobre SVG, que no va al compositor. */
function initPortadaPanaderia() {
  var portada = document.getElementById("portada");
  if (!portada || !("IntersectionObserver" in window)) {
    body.classList.remove("marca-en-hero");
    return;
  }
  new IntersectionObserver(function(entradas) {
    entradas.forEach(function(e) {
      body.classList.toggle("marca-en-hero", e.isIntersecting);
    });
  }, { threshold: 0, rootMargin: "-96px 0px 0px 0px" }).observe(portada);

  new IntersectionObserver(function(entradas) {
    entradas.forEach(function(e) {
      portada.classList.toggle("pan-portada--quieta", !e.isIntersecting);
    });
  }).observe(portada);
}

function bootPanaderia() {
  if (body.dataset.page !== "panaderia") return;

  // Lenis antes que el resto, como en la portada: el menú lo detiene al abrirse.
  if (T.smooth) startLenis();

  initCursor();
  initMagnetic();
  initNav();
  // Fuera de la rama de GSAP: el negativo de la marca tiene que funcionar
  // aunque las libs de movimiento no lleguen.
  tonoBajoLaMarca();
  initPortadaPanaderia();

  var vitrina = $("[data-vitrina]");
  if (vitrina) initPestanasImpresas(vitrina, vitrina.querySelector(".pan-vitrina__pestanas"));

  initLightbox();

  if (window.gsap && window.ScrollTrigger) {
    initScrollArt();
    initReveals();
  } else {
    $$("[data-reveal]").forEach(function(el) { el.style.opacity = 1; el.style.transform = "none"; });
  }

  setTimeout(function() { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, 600);
}

// Nunca en el mismo turno en que se evalúa el bundle (ver el final de app.js).
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bootPanaderia);
else setTimeout(bootPanaderia, 0);
