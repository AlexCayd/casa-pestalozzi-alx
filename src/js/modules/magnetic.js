/* Imán — los botones atraen al puntero
   ------------------------------------------------------------------
   El único imán de la landing: lo usan la barra del hero (los accesos rápidos
   y «Reservar mesa»), el botón de menú, la marca fija y los CTA marcados con
   [data-magnetic] en cada sección. Un valor numérico en el atributo cambia la
   fuerza (`data-magnetic="0.2"`); vacío vale 0.3.

   Con el puntero encima, el botón se desplaza hacia él y su etiqueta
   ([data-magnetic-label]) le sigue con menos recorrido y algo de retraso:
   dos planos, el contenido flota dentro de su caja. Al salir vuelven con un
   rebote corto.

   Antes cada botón lanzaba un tween de GSAP en cada mousemove, sin mirar si
   GSAP había llegado —sin las libs de movimiento la primera pasada del ratón
   lanzaba un ReferenceError—, sólo se apagaba con `pointer: coarse` y movía
   `transform`, que pisaba la transición de transform del botón de menú y
   las de sus barras al abrirse. Ahora:

   · Es un muelle propio en un solo bucle de rAF que duerme en cuanto todo
     está en reposo. No depende de GSAP.
   · Mueve la propiedad `translate`, que se compone con `transform` sin
     tocarlo: la X del menú, la flecha de los .btn-line y cualquier hover que
     escale siguen siendo suyos.
   · Sólo con `(hover: hover) and (pointer: fine)`: en un portátil táctil con
     ratón funciona con el ratón, en una tablet no existe. Se apaga en
     caliente con movimiento reducido o con «Animaciones» en tweaks.
   · No toca el foco ni el clic: el botón se desplaza HACIA el puntero, así
     que la zona pulsable sigue debajo de él. Con teclado no hay imán. */
function initMagnetic() {
  var elementos = $$("[data-magnetic]");
  if (!elementos.length || !window.matchMedia) return;
  var puntero = window.matchMedia("(hover: hover) and (pointer: fine)");

  function disponible() {
    return puntero.matches && !reduce && !body.classList.contains("no-anim");
  }

  // Dos muelles por imán (caja y etiqueta), cada uno con dos temples: siguiendo
  // al puntero casi sin rebote, y volviendo con un rebote corto (amortiguación
  // ~0.45: un vaivén que se nota y se apaga en medio segundo).
  var TEMPLE = {
    caja: { sigue: { k: 300, c: 30 }, vuelve: { k: 210, c: 13 } },
    etiqueta: { sigue: { k: 170, c: 22 }, vuelve: { k: 150, c: 11 } }
  };
  // El recorrido se topa: en un CTA ancho, el 30 % de media anchura sacaba
  // el botón 35 px de su sitio.
  var TOPE_X = 14, TOPE_Y = 9, FRACCION_ETIQUETA = 0.45;

  var imanes = elementos.map(function(el) {
    var fuerza = parseFloat(el.getAttribute("data-magnetic"));
    return {
      el: el,
      etiqueta: el.querySelector("[data-magnetic-label]"),
      fuerza: fuerza > 0 && fuerza < 1 ? fuerza : 0.3,
      dentro: false,
      centro: null,
      caja: { x: 0, y: 0, vx: 0, vy: 0, tx: 0, ty: 0 },
      eti: { x: 0, y: 0, vx: 0, vy: 0, tx: 0, ty: 0 }
    };
  });

  var bucle = null, anterior = 0;

  function muelle(m, temple, dt) {
    m.vx += (temple.k * (m.tx - m.x) - temple.c * m.vx) * dt;
    m.vy += (temple.k * (m.ty - m.y) - temple.c * m.vy) * dt;
    m.x += m.vx * dt;
    m.y += m.vy * dt;
    return Math.abs(m.x - m.tx) > 0.02 || Math.abs(m.y - m.ty) > 0.02 ||
      Math.abs(m.vx) > 0.02 || Math.abs(m.vy) > 0.02;
  }

  function escribir(el, m) {
    if (!el) return;
    el.style.translate = (Math.abs(m.x) < 0.01 && Math.abs(m.y) < 0.01)
      ? ""
      : m.x.toFixed(2) + "px " + m.y.toFixed(2) + "px";
  }

  function reposo(im) {
    [im.caja, im.eti].forEach(function(m) { m.x = m.y = m.vx = m.vy = m.tx = m.ty = 0; });
    im.dentro = false;
    escribir(im.el, im.caja);
    escribir(im.etiqueta, im.eti);
  }

  function paso(ahora) {
    // Pasos de muelle de 1/120 s como mucho: con el delta de un fotograma
    // largo (pestaña en segundo plano) la integración se dispararía.
    var dt = anterior ? Math.min(0.05, (ahora - anterior) / 1000) : 1 / 60;
    anterior = ahora;
    if (!disponible()) {
      imanes.forEach(reposo);
      bucle = null; anterior = 0;
      return;
    }
    var vivos = false;
    imanes.forEach(function(im) {
      var t = im.dentro ? "sigue" : "vuelve";
      var n = Math.max(1, Math.ceil(dt * 120)), h = dt / n, k, mueve = false, muevEti = false;
      for (k = 0; k < n; k++) {
        mueve = muelle(im.caja, TEMPLE.caja[t], h) || mueve;
        if (im.etiqueta) muevEti = muelle(im.eti, TEMPLE.etiqueta[t], h) || muevEti;
      }
      if (mueve || muevEti || im.dentro) vivos = true;
      escribir(im.el, im.caja);
      escribir(im.etiqueta, im.eti);
    });
    if (vivos) bucle = requestAnimationFrame(paso);
    else { bucle = null; anterior = 0; }
  }

  function despertar() {
    if (!bucle) bucle = requestAnimationFrame(paso);
  }

  imanes.forEach(function(im) {
    im.el.addEventListener("pointermove", function(e) {
      if (e.pointerType !== "mouse" && e.pointerType !== "pen") return;
      if (!disponible()) return;
      // La caja de referencia es la de REPOSO: el rect medido incluye el
      // desplazamiento actual, y medir contra él haría que el botón se
      // persiguiera a sí mismo. Se mide una vez por visita del puntero (y otra
      // si la página se desplaza), no en cada movimiento: leer el rect justo
      // después de escribir `translate` obliga a recalcular estilos en cada
      // evento.
      if (!im.centro) {
        var r = im.el.getBoundingClientRect();
        im.centro = { x: r.left - im.caja.x + r.width / 2, y: r.top - im.caja.y + r.height / 2 };
      }
      var cx = im.centro.x, cy = im.centro.y;
      var dx = (e.clientX - cx) * im.fuerza;
      var dy = (e.clientY - cy) * im.fuerza;
      im.caja.tx = Math.max(-TOPE_X, Math.min(TOPE_X, dx));
      im.caja.ty = Math.max(-TOPE_Y, Math.min(TOPE_Y, dy));
      im.eti.tx = im.caja.tx * FRACCION_ETIQUETA;
      im.eti.ty = im.caja.ty * FRACCION_ETIQUETA;
      im.dentro = true;
      despertar();
    });
    im.el.addEventListener("pointerleave", function() {
      im.centro = null;
      if (!im.dentro) return;
      im.dentro = false;
      im.caja.tx = im.caja.ty = im.eti.tx = im.eti.ty = 0;
      despertar();
    });
  });

  // Si la preferencia cambia a media visita (o se conecta un ratón a una
  // tablet), el bucle lo nota en su siguiente fotograma; aquí sólo se le
  // despierta para que deje todo en su sitio.
  window.addEventListener("scroll", function() {
    imanes.forEach(function(im) { im.centro = null; });
  }, { passive: true });
  if (puntero.addEventListener) puntero.addEventListener("change", despertar);
  if (consultaReduce.addEventListener) consultaReduce.addEventListener("change", despertar);
}
