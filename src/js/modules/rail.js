/* Rail de secciones (estado activo)
   ------------------------------------------------------------------
   Se resolvía con un IntersectionObserver de `rootMargin: -45% 0px -45%`, es
   decir por FLANCOS sobre una franja del 10% del viewport. Dos huecos, y los dos
   se veían: mientras cruzaba una insegna —que no tiene id y por tanto no se
   observa— ninguna sección intersecaba y el activo se quedaba congelado en la
   anterior; y el estado inicial se fijaba antes de que asentaran las alturas
   (imágenes lazy, la carta y la galería las pinta el JS) sin que nada lo
   corrigiera hasta el siguiente cruce.

   Ahora va por GEOMETRÍA, igual que el negativo de la marca: en cada evaluación
   se busca qué sección cubre la línea media del viewport. Es determinista en
   cualquier instante y no depende de haber presenciado el cruce.
   getBoundingClientRect() funciona igual dentro del .pin-spacer que ScrollTrigger
   monta alrededor de #firma, así que fijar una sección no la saca del rail.

   La TINTA del rail no se decide aquí: la publica tonoBajoElRail() en
   body[data-tono-rail] y la elige el CSS (ver layout/_rail.scss). */
function initRail() {
  var links = $$("#rail a");
  if (!links.length) return;

  // El par enlace–sección se resuelve una sola vez: los ids del rail son fijos.
  var entradas = [];
  links.forEach(function(l) {
    var seccion = document.getElementById(l.getAttribute("data-rail"));
    if (seccion) entradas.push({ link: l, seccion: seccion });
  });
  if (!entradas.length) return;

  var activa = null;

  function resolver() {
    var linea = innerHeight / 2;
    for (var i = 0; i < entradas.length; i++) {
      var r = entradas[i].seccion.getBoundingClientRect();
      if (r.top <= linea && r.bottom > linea) return entradas[i].link;
    }
    return null; // entre dos secciones: se conserva la última válida
  }

  var pendiente = false;

  function aplicar() {
    pendiente = false;
    var link = resolver();
    if (!link || link === activa) return;
    if (activa) activa.classList.remove("active");
    link.classList.add("active");
    activa = link;
  }

  function pedir() {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(aplicar);
  }

  // Síncrono en el arranque: el primer pintado ya sale con la sección correcta.
  aplicar();

  // Lenis desplaza la ventana de verdad (wrapper = window), así que el `scroll`
  // nativo cubre tanto el suave como el normal.
  window.addEventListener("scroll", pedir, { passive: true });
  window.addEventListener("resize", pedir);
  // Las imágenes lazy cambian el alto de media página al llegar.
  window.addEventListener("load", pedir);
  if (window.ScrollTrigger) ScrollTrigger.addEventListener("refresh", pedir);
}
