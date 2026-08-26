/* Cursor personalizado
   ------------------------------------------------------------------
   Todo se escribe UNA vez por fotograma dentro del rAF. Antes el punto se
   escribía dentro del propio `mousemove`: un ratón de 1000 Hz dispara varios
   eventos por fotograma y cada uno tocaba el estilo, así que el navegador
   recomponía de más para pintar exactamente lo mismo. El listener sólo anota
   la posición; quien dibuja es el bucle.

   El anillo interpola, pero la constante se NORMALIZA por delta de tiempo: con
   un lerp fijo la respuesta depende de la tasa de refresco —a 60 Hz un valor y
   a 144 Hz otro— y en un monitor lento el anillo se arrastraba de verdad. */
function initCursor() {
  if (isTouch) return;
  var dot = $(".cursor-dot"), ring = $(".cursor-ring"), label = $(".clabel");
  if (!dot || !ring || !label) return;

  var mx = innerWidth / 2, my = innerHeight / 2, rx = mx, ry = my;
  // El tamaño del anillo se resuelve por `scale` sobre una caja fija de 42px y
  // no animando width/height: son propiedades de LAYOUT, y sobre un elemento
  // fijo en mix-blend-mode obligan a recomponer el viewport entero durante los
  // 300ms de la transición. Justo el tirón que se notaba al entrar en un enlace.
  var escala = 1, escalaFin = 1;

  window.addEventListener("mousemove", function(e) {
    mx = e.clientX; my = e.clientY;
  }, { passive: true });

  var anterior = 0;

  function raf(ahora) {
    // Acotado: al volver de una pestaña en segundo plano el delta es enorme y
    // sin tope el anillo pegaría un salto en vez de alcanzar al puntero.
    var dt = anterior ? Math.min(64, ahora - anterior) : 16.67;
    anterior = ahora;

    var k = 1 - Math.pow(1 - 0.32, dt / 16.67);
    rx += (mx - rx) * k;
    ry += (my - ry) * k;
    escala += (escalaFin - escala) * (1 - Math.pow(1 - 0.22, dt / 16.67));

    // translate3d y no translate: promueve cada nodo a su propia capa y el
    // seguimiento deja de repintar en el hilo principal con el resto de la
    // página.
    dot.style.transform = "translate3d(" + mx + "px," + my + "px,0) translate(-50%,-50%)";
    ring.style.transform = "translate3d(" + rx + "px," + ry + "px,0) translate(-50%,-50%) scale(" + escala + ")";
    // El rótulo vive DENTRO del anillo, así que hereda su escala: sin la
    // contraescala el texto crecería con el círculo.
    if (escala > 1.01) label.style.transform = "scale(" + (1 / escala) + ")";

    requestAnimationFrame(raf);
  }
  requestAnimationFrame(raf);

  var hoverSel = "a, button, .dish, .gcard, [data-magnetic], [data-zoom], [data-cursor], input, select, textarea, .pill, .tw-opt, .tw-swatch, .tw-switch";

  document.addEventListener("mouseover", function(e) {
    var t = e.target.closest(hoverSel);
    if (!t) return;
    ring.classList.add("hover");
    escalaFin = 72 / 42;
    var cl = t.getAttribute("data-cursor");
    if (cl) { ring.classList.add("labeled"); label.textContent = cl; escalaFin = 86 / 42; }
  });

  document.addEventListener("mouseout", function(e) {
    var t = e.target.closest(hoverSel);
    if (!t) return;
    ring.classList.remove("hover", "labeled");
    label.textContent = "";
    escalaFin = 1;
  });
}

function setCursorEnabled(on) {
  body.classList.toggle("no-cursor", !on);
  var dot  = document.querySelector(".cursor-dot");
  var ring = document.querySelector(".cursor-ring");
  if (dot)  dot.style.display  = on && !isTouch ? "" : "none";
  if (ring) ring.style.display = on && !isTouch ? "" : "none";
  document.documentElement.style.setProperty("cursor", on && !isTouch ? "none" : "auto");
  body.style.cursor = on && !isTouch ? "none" : "auto";
}
