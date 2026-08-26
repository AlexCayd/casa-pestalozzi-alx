/* Smooth scroll — Lenis */
var lenis = null;

/* ¿Hay un diálogo cerrando el fondo?
   El lightbox, el anuncio y el aviso de privacidad bloquean la página con
   `document.body.style.overflow = "hidden"`. Se mira el estilo EN LÍNEA y no el
   calculado a propósito: la hoja ya trae `overflow-x: hidden` en el body y el
   calculado daría un falso positivo permanente. */
function paginaBloqueada() {
  return document.body.style.overflow === "hidden";
}

/* Arrancar con un diálogo abierto no es opción, y por eso la guarda vive aquí y
   no en el orden de boot(): a startLenis() lo llaman tres sitios —boot, el
   panel de tweaks y el cierre de cada diálogo— y basta con que uno caiga
   después de abrirse el anuncio para resucitarlo. Pasaba: el anuncio paraba
   Lenis y dos líneas más tarde initTweaks() lo volvía a montar, así que la
   rueda seguía recorriendo la portada por detrás del diálogo. `overflow:hidden`
   no frena a Lenis, que desplaza por código. */
function startLenis() {
  if (lenis || isTouch || reduce || !window.Lenis) return;
  if (paginaBloqueada()) return;
  lenis = new Lenis({ duration: 1.15, easing: function(t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); }, smoothWheel: true });
  lenis.on("scroll", function() { if (window.ScrollTrigger) ScrollTrigger.update(); });
  gsap.ticker.add(lenisRaf);
  gsap.ticker.lagSmoothing(0);
}

function lenisRaf(time) { if (lenis) lenis.raf(time * 1000); }

function stopLenis() {
  if (!lenis) return;
  gsap.ticker.remove(lenisRaf);
  lenis.destroy();
  lenis = null;
}

/* NO llamar a esto `scrollTo`.
   ------------------------------------------------------------------
   El bundle es un concat de scripts clásicos en scope global, así que una
   `function scrollTo()` de nivel superior no se queda en el módulo: PISA
   window.scrollTo, el método nativo del navegador.
   Y Lenis desplaza precisamente con `this.options.wrapper.scrollTo({top, …})`,
   que con wrapper = window es window.scrollTo. Es decir, cada vez que Lenis
   intentaba mover la página se llamaba a esta función con un objeto de
   opciones; `typeof target === "string"` daba false, `el` quedaba en ese objeto
   y la llamada terminaba en `lenis.scrollTo(objeto)`, que Lenis descarta por no
   ser ni número ni elemento.
   Resultado: Lenis capturaba la rueda, calculaba el destino y no escribía
   nunca la posición — la landing entera se quedaba clavada arriba. */
function irASeccion(target) {
  var el = typeof target === "string" ? $(target) : target;
  if (!el) return;
  if (lenis) lenis.scrollTo(el, { offset: 0, duration: 1.3 });
  else el.scrollIntoView({ behavior: reduce ? "auto" : "smooth" });
}
