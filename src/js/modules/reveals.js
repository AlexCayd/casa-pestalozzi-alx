/* Animaciones de reveal + parallax imágenes
   ------------------------------------------------------------------
   Los reveals van por ScrollTrigger.batch() y no por un trigger propio para
   cada elemento. Dos motivos, y el segundo importa más que el primero:

     · Coste: la landing tiene del orden de un centenar de [data-reveal] y
       antes cada uno creaba su ScrollTrigger, con su propio cálculo de
       posición en cada refresh.
     · Composición: batch() agrupa los elementos que entran en el mismo
       fotograma, así que el stagger recorre una fila de verdad. Con un trigger
       por elemento cada uno arrancaba su cuenta por separado y lo que se veía
       era un fundido plano, no una entrada escalonada.

   Es el mismo patrón que ya usa el panel en src/js/admin/core/motion.js. */

function initReveals() {
  if (reduce) { body.classList.add("no-anim"); return; }

  revelarPorLotes();
  parallaxDeImagenes();
}

function revelarPorLotes() {
  // Los del hero los anima heroIntro() en su propia línea de tiempo.
  var elementos = $$("[data-reveal]").filter(function(el) {
    return !el.closest("#hero");
  });
  if (!elementos.length) return;

  ScrollTrigger.batch(elementos, {
    start: "top 88%",
    // once: el reveal es una entrada, no un estado. Sin esto el elemento se
    // vuelve a animar al subir y la página parece parpadear al recorrerla en
    // los dos sentidos.
    once: true,
    onEnter: function(lote) {
      gsap.to(lote, {
        opacity: 1,
        y: 0,
        duration: 1,
        ease: "power3.out",
        stagger: 0.08,
        // will-change se pone al entrar y se retira al terminar. En el marcado
        // vive .reveal-ready sobre el <body>, que lo dejaba declarado de forma
        // permanente sobre todos los [data-reveal]: eso obliga al navegador a
        // mantener una capa por elemento durante toda la visita, que es justo
        // lo contrario de para lo que sirve la propiedad.
        onStart: function() { aplicarWillChange(lote, "opacity, transform"); },
        onComplete: function() { aplicarWillChange(lote, "auto"); },
      });
    },
  });
}

function aplicarWillChange(elementos, valor) {
  elementos.forEach(function(el) { el.style.willChange = valor; });
}

// ── Parallax de imágenes ─────────────────────────────────────
//
// La profundidad la fija [data-depth] (1 = el recorrido de siempre). Un
// mosaico donde todas las piezas se mueven a la misma velocidad se lee como
// una sola lámina; dando a cada pieza su factor, el bloque gana planos.
function parallaxDeImagenes() {
  $$("[data-parallax-img] img, [data-parallax-img]").forEach(function(wrap) {
    var img = wrap.tagName === "IMG" ? wrap : wrap.querySelector("img");
    if (!img) return;

    var marco = wrap.tagName === "IMG" ? wrap.parentNode : wrap;
    var profundidad = parseFloat(marco.getAttribute("data-depth")) || 1;
    var recorrido = 8 * profundidad;

    gsap.fromTo(img,
      { yPercent: -recorrido },
      {
        yPercent: recorrido,
        ease: "none",
        scrollTrigger: {
          trigger: wrap,
          start: "top bottom",
          end: "bottom top",
          scrub: true,
        },
      });
  });
}
