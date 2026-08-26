/* Lightbox universal — cualquier [data-zoom] */

// ── View Transitions ─────────────────────────────────────────
//
// Abrir, cerrar y pasar de foto son cambios de estado del DOM, que es
// exactamente lo que la API sabe animar: el navegador toma una instantánea de
// antes y de después y funde entre las dos, sin que haya que orquestar nada.
//
// El envoltorio es obligatorio y no un adorno: startViewTransition sólo existe
// en parte de los navegadores, así que sin este respaldo el lightbox dejaría de
// abrirse en el resto. Con movimiento reducido también se ejecuta en seco.
function conTransicion(fn) {
  if (reduce || !document.startViewTransition) { fn(); return; }
  return silenciarDescartes(document.startViewTransition(fn));
}

/* Una transición de vista se DESCARTA con normalidad: basta con que empiece
   otra antes de que termine —pasar dos fotos seguidas— o que el documento
   pierda visibilidad. Cuando eso ocurre, `ready` rechaza con AbortError, y sin
   un manejador el navegador lo reporta como "Uncaught (in promise)". No es un
   fallo que haya que tratar: la transición se salta y el DOM ya quedó bien. */
function silenciarDescartes(t) {
  if (t && t.ready && t.ready.catch) t.ready.catch(function() {});
  if (t && t.updateCallbackDone && t.updateCallbackDone.catch) t.updateCallbackDone.catch(function() {});
  return t;
}

// El morph de la miniatura a la imagen grande necesita que las DOS lleven el
// mismo view-transition-name. El nombre tiene que ser único en el documento en
// el instante de la captura, así que se pone justo antes y se retira al acabar:
// si se dejara puesto, la siguiente foto encontraría el nombre ocupado y el
// navegador descartaría la transición entera.
function conMorfo(origen, fn) {
  var img = origen && (origen.tagName === "IMG" ? origen : origen.querySelector("img"));
  var lbImg = $("#lbImg");
  if (reduce || !document.startViewTransition || !img || !lbImg) { fn(); return; }

  img.style.viewTransitionName = "zoom-origen";
  lbImg.style.viewTransitionName = "zoom-origen";

  var t = silenciarDescartes(document.startViewTransition(fn));
  // Con `finished` y no con `ready`: el nombre debe seguir puesto mientras dura
  // la animación, y hay que retirarlo tanto si acaba como si se descarta.
  t.finished.then(limpiar, limpiar);
  function limpiar() {
    img.style.viewTransitionName = "";
    lbImg.style.viewTransitionName = "";
  }
  return t;
}

function injectBadges() {
  $$("[data-zoom]").forEach(function(el) {
    if (el.classList.contains("dish__thumb")) return;
    if (el.classList.contains("gcard")) return;
    if (el.querySelector(":scope > .zoom-badge")) return;
    var b = document.createElement("span");
    b.className = "zoom-badge";
    b.textContent = "⤢ Ampliar";
    el.appendChild(b);
    if (!el.hasAttribute("data-cursor")) el.setAttribute("data-cursor", "Ampliar");
  });
}

function getZoomList() {
  var list = [];
  $$("[data-zoom]").forEach(function(el) {
    var img = el.tagName === "IMG" ? el : el.querySelector("img");
    var src = el.getAttribute("data-zoom-src") || (img && (img.currentSrc || img.getAttribute("src")));
    if (!src) return;
    list.push({ src: src, n: el.getAttribute("data-zoom-name") || (img && img.alt) || "", t: el.getAttribute("data-zoom-cat") || "", el: el });
  });
  return list;
}

function initLightbox() {
  injectBadges();
  var lb = $("#lightbox"), lbImg = $("#lbImg"), lbN = $("#lbN"), lbT = $("#lbT"), lbCur = $("#lbCur"), lbTotal = $("#lbTotal");
  var list = [], idx = 0;

  // `animar` sólo lo pide la navegación sin transición disponible: cuando corre
  // una view transition el navegador ya está fundiendo entre las dos capturas y
  // un tween de GSAP encima produce dos aperturas superpuestas.
  function render(animar) {
    var g = list[idx]; if (!g) return;
    lbImg.src = g.src; lbImg.alt = g.n; lbN.textContent = g.n; lbT.textContent = g.t;
    lbCur.textContent = idx + 1; lbTotal.textContent = list.length;
    if (animar && !reduce && window.gsap) {
      gsap.fromTo(lbImg, { opacity: 0, scale: 0.97 }, { opacity: 1, scale: 1, duration: 0.45, ease: "power2.out" });
    }
  }

  function groupId(el) { var s = el.closest("section, header"); return s ? s.id : ""; }

  function open(el) {
    var g = groupId(el);
    list = getZoomList().filter(function(z) { return groupId(z.el) === g; });
    if (!list.length) list = getZoomList();
    idx = Math.max(0, list.findIndex(function(z) { return z.el === el; }));

    // El scroll se para FUERA de la transición: Lenis escribe sobre el scroll
    // del documento y hacerlo dentro de la captura mueve el fondo a mitad del
    // fundido.
    document.body.style.overflow = "hidden";
    if (lenis) lenis.stop();

    conMorfo(el, function() {
      render(false);
      lb.classList.add("open");
      lb.setAttribute("aria-hidden", "false");
    });
  }

  function close() {
    var actual = list[idx];
    conMorfo(actual && actual.el, function() {
      lb.classList.remove("open");
      lb.setAttribute("aria-hidden", "true");
    });
    document.body.style.overflow = "";
    if (lenis) lenis.start();
  }

  function nav(d) {
    if (!list.length) return;
    idx = (idx + d + list.length) % list.length;
    // Sin API que funda las dos capturas, la entrada la sigue haciendo GSAP.
    if (reduce || !document.startViewTransition) { render(true); return; }
    conTransicion(function() { render(false); });
  }

  document.addEventListener("click", function(e) {
    var z = e.target.closest("[data-zoom]");
    if (!z) return;
    e.preventDefault();
    open(z);
  });

  $("#lbClose").addEventListener("click", function(e) { e.stopPropagation(); close(); });
  $("#lbPrev").addEventListener("click", function(e) { e.stopPropagation(); nav(-1); });
  $("#lbNext").addEventListener("click", function(e) { e.stopPropagation(); nav(1); });
  lb.addEventListener("click", function(e) { if (!e.target.closest(".lightbox__img, button, .lightbox__cap")) close(); });

  document.addEventListener("keydown", function(e) {
    if (!lb.classList.contains("open")) return;
    if (e.key === "Escape") close();
    if (e.key === "ArrowRight") nav(1);
    if (e.key === "ArrowLeft") nav(-1);
  });

  var sx = 0;
  lb.addEventListener("touchstart", function(e) { sx = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener("touchend", function(e) { var dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) nav(dx < 0 ? 1 : -1); });

  window.__openZoom = open;
}
