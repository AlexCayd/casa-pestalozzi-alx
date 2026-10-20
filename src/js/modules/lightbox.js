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
  if (reduce || !document.startViewTransition) { fn(); return null; }
  soltarNombres();
  var t = marcarVigente(silenciarDescartes(document.startViewTransition(fn)));
  t.finished.then(cerrarSiVigente(t), cerrarSiVigente(t));
  return t;
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

/* Los nombres de transición y las marcas que los acompañan tienen UN dueño: la
   transición vigente. Una que se descarta porque empezó otra sigue
   ejecutando su cambio de DOM, y si además limpiara o pusiera nombres al
   terminar los quitaría de debajo de la nueva —pasar dos fotos seguidas
   dejaba la segunda sin su par— o dejaría dos piezas con el mismo nombre, y
   con un nombre repetido el navegador descarta la transición entera. Así que:
   cada transición empieza soltando lo que hubiera quedado, sólo la vigente
   pone el nombre de llegada, y sólo la vigente limpia al terminar. */
var transicionVigente = null;
var nombrados = [];
function marcarVigente(t) { transicionVigente = t; return t; }
function esVigente(t) { return !!t && t === transicionVigente; }
function nombrar(el, nombre) {
  if (!el) return;
  el.style.viewTransitionName = nombre;
  if (nombrados.indexOf(el) === -1) nombrados.push(el);
}
function soltarNombres() {
  nombrados.forEach(function(el) { el.style.viewTransitionName = ""; });
  nombrados = [];
  $$(".is-morfo").forEach(function(el) { el.classList.remove("is-morfo"); });
  var lb = $("#lightbox");
  if (lb) lb.classList.remove("lightbox--en-vista");
  document.documentElement.removeAttribute("data-lb-sentido");
}
function cerrarSiVigente(t) {
  return function() { if (esVigente(t)) { soltarNombres(); transicionVigente = null; } };
}

/* Lo que se morfa del lado de la página no es el <img>: es la CAJA que lo
   recorta. Las fotos de la landing viven en marcos con arco, con parallax y
   con el acercamiento de las portadas, así que el <img> casi siempre es más
   grande que lo que se ve —escalado al 122 % o desplazado un 8 %— y además
   no lleva el arco, que es del marco. Capturado a pelo, el primer fotograma
   del morfo enseñaba la foto entera y cuadrada saliéndose de su sitio: un
   respingo antes de crecer. La caja (el primer ancestro que recorta, hasta el
   propio [data-zoom]) es exactamente lo que el ojo tenía delante. */
function cajaDeMorfo(origen) {
  if (!origen) return null;
  var img = origen.tagName === "IMG"
    ? origen
    // El hueco de la carta guarda varias fotos apiladas; la que se ve es la
    // de `.show`.
    : (origen.querySelector("img.show") || origen.querySelector("img"));
  if (!img) return null;
  if (img === origen) return img;
  var caja = img.parentElement;
  while (caja && caja !== origen && getComputedStyle(caja).overflowX === "visible") caja = caja.parentElement;
  return caja || origen;
}

// El morfo de la miniatura al visor necesita que las DOS lleven el mismo
// view-transition-name, pero nunca a la vez: el nombre tiene que ser único en
// cada captura. Por eso se pone en la pieza de ANTES justo antes de empezar, y
// dentro del cambio se le retira y se le pone a la de DESPUÉS. (Estaban puestos
// en las dos desde el principio; con el visor oculto el navegador lo toleraba
// unas veces y descartaba la transición entera otras.)
//
// Y el fundido CSS del visor se congela mientras dura: la instantánea «nueva»
// se toma al principio, con el visor a opacidad 0 porque su `transition` acaba
// de arrancar. Era lo que hacía invisible el morfo —la foto volaba hacia un
// fondo que todavía no existía y el visor aparecía después, de golpe—. Con la
// transición congelada, la captura ve el estado final y el fundido lo hace la
// transición de vista. Sin la API, el fundido CSS sigue haciendo su trabajo.
//
// `haciaVisor`: true al abrir (miniatura → visor), false al cerrar.
function conMorfo(origen, haciaVisor, fn) {
  var visor = $("#lbVisor");
  var lb = $("#lightbox");
  if (reduce || !document.startViewTransition) { fn(); return null; }
  var caja = cajaDeMorfo(origen);
  if (!caja || !visor) return conTransicion(fn);
  // Un morfo desde (o hacia) una foto fuera de pantalla entra volando desde el
  // borde y no se entiende de dónde viene: en ese caso, fundido sin morfo.
  var r = caja.getBoundingClientRect();
  if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth || !r.width) return conTransicion(fn);

  soltarNombres();
  var antes = haciaVisor ? caja : visor;
  var despues = haciaVisor ? visor : caja;
  nombrar(antes, "zoom-origen");
  // La chapa «Ampliar» no viaja con la foto: se apaga mientras dura.
  if (origen) origen.classList.add("is-morfo");
  if (lb) lb.classList.add("lightbox--en-vista");

  var t = null;
  t = marcarVigente(silenciarDescartes(document.startViewTransition(function() {
    antes.style.viewTransitionName = "";
    var espera = fn();
    if (esVigente(t)) nombrar(despues, "zoom-origen");
    return espera;
  })));
  // Con `finished` y no con `ready`: el nombre debe seguir puesto mientras dura
  // la animación, y hay que retirarlo tanto si acaba como si se descarta.
  t.finished.then(cerrarSiVigente(t), cerrarSiVigente(t));
  return t;
}

// Pasar de foto: la que se va sale hacia el lado contrario y la nueva entra
// desde el lado al que se avanza (las reglas, en _lightbox.scss). El visor
// cambia de proporción entre una foto y otra, y como es la misma pieza con el
// mismo nombre en las dos capturas, el navegador también anima su tamaño.
function conDeslizado(sentido, fn) {
  var visor = $("#lbVisor");
  if (reduce || !document.startViewTransition || !visor) { fn(); return null; }
  soltarNombres();
  nombrar(visor, "lb-foto");
  document.documentElement.setAttribute("data-lb-sentido", sentido > 0 ? "sig" : "ant");
  var t = marcarVigente(silenciarDescartes(document.startViewTransition(fn)));
  t.finished.then(cerrarSiVigente(t), cerrarSiVigente(t));
  return t;
}

function injectBadges() {
  $$("[data-zoom]").forEach(function(el) {
    // Las fotos ampliables son <div>: con ratón se abrían, con teclado no
    // había forma de llegar a ellas. Pasan a ser botones a efectos de foco y
    // de lector de pantalla (Intro y Espacio los atiende initLightbox).
    // Lo que vive dentro de un bloque aria-hidden (la foto grande de la carta,
    // que repite la miniatura de la lista) no entra: un control enfocable que
    // el lector de pantalla no anuncia es peor que ninguno.
    if (!/^(BUTTON|A)$/.test(el.tagName) && !el.hasAttribute("tabindex") && !el.closest('[aria-hidden="true"]')) {
      el.setAttribute("tabindex", "0");
      el.setAttribute("role", "button");
      var nombre = el.getAttribute("data-zoom-name") || (el.querySelector("img") || {}).alt || "";
      el.setAttribute("aria-label", nombre ? "Ampliar foto: " + nombre : "Ampliar foto");
    }
    if (el.classList.contains("dish__thumb")) return;
    if (el.classList.contains("gcard")) return;
    if (el.querySelector(":scope > .zoom-badge")) return;
    var b = document.createElement("span");
    b.className = "zoom-badge";
    // Icono dibujado y no el glifo «⤢»: el glifo lo pinta la fuente del
    // sistema, no hereda el color y en algunas caras ni existe.
    b.innerHTML = '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9.5 2.5h4v4M13.5 2.5 9 7M6.5 13.5h-4v-4M2.5 13.5 7 9"/></svg>Ampliar';
    el.appendChild(b);
    if (!el.hasAttribute("data-cursor")) el.setAttribute("data-cursor", "Ampliar");
  });
}

/* Las dos fuentes de una foto.
   `completa` es la del atributo src (o data-zoom-src): la original, la que
   aguanta el acercamiento. `vista` es la que la miniatura YA tiene cargada
   (currentSrc, que con srcset suele ser la variante de 720 px): está en
   memoria, así que el visor la pinta en el mismo fotograma y el morfo tiene
   imagen de llegada. Se abre con `vista` y se cambia a `completa` en cuanto
   llega; tienen la misma proporción, y como el tamaño en pantalla lo fija el
   visor y no la resolución, el cambio no mueve nada. */
function fuentesDeFoto(el) {
  var img = el.tagName === "IMG" ? el : (el.querySelector("img.show") || el.querySelector("img"));
  var completa = el.getAttribute("data-zoom-src") || (img && img.getAttribute("src")) || "";
  var cargada = !!(img && img.complete && img.naturalWidth);
  return {
    completa: completa,
    vista: cargada ? (img.currentSrc || completa) : "",
    ratio: cargada ? img.naturalWidth / img.naturalHeight : 0
  };
}

function getZoomList() {
  var list = [];
  $$("[data-zoom]").forEach(function(el) {
    var f = fuentesDeFoto(el);
    if (!f.completa) return;
    var img = el.tagName === "IMG" ? el : el.querySelector("img");
    list.push({ n: el.getAttribute("data-zoom-name") || (img && img.alt) || "", t: el.getAttribute("data-zoom-cat") || "", el: el });
  });
  return list;
}

/* Carga una imagen y espera a que esté decodificada, con tope: si la red va
   lenta, la transición no se queda esperando y la foto entra cuando llegue.
   Resuelve con la imagen o con null, nunca rechaza. */
function cargarFoto(src, tope) {
  return new Promise(function(resolver) {
    var im = new Image(), hecho = false;
    function fin(ok) {
      if (hecho) return;
      hecho = true;
      resolver(ok ? im : null);
    }
    im.onload = function() {
      if (im.decode) im.decode().then(function() { fin(true); }, function() { fin(true); });
      else fin(true);
    };
    im.onerror = function() { fin(false); };
    if (tope) setTimeout(function() { fin(im.complete && im.naturalWidth > 0); }, tope);
    im.src = src;
  });
}

function absoluta(src) {
  try { return new URL(src, location.href).href; } catch (e) { return src; }
}

function initLightbox() {
  injectBadges();
  var lb = $("#lightbox"), lbImg = $("#lbImg"), visor = $("#lbVisor");
  var lbN = $("#lbN"), lbT = $("#lbT"), lbCur = $("#lbCur"), lbTotal = $("#lbTotal");
  if (!lb || !lbImg || !visor) return;
  var bAcercar = $("#lbAcercar"), bAlejar = $("#lbAlejar"), bNivel = $("#lbNivel");
  var nivelTxt = $("#lbNivelTxt"), anuncio = $("#lbAnuncio");
  var list = [], idx = 0;
  var abierto = false, abriendo = false;
  // Cada petición de foto lleva su número: si mientras carga se pide otra
  // (pasar rápido, cerrar), la respuesta vieja se descarta al llegar.
  var pedido = 0;
  var ratioActual = 1.5;

  // ═══ Acercamiento ═══════════════════════════════════════════
  //
  // La foto se escala y se desplaza DENTRO del visor, que la recorta:
  // translate(x, y) scale(s) con origen en la esquina, así un punto p de la
  // foto cae en x + s·p. Sólo transform: va al compositor y no toca el layout.
  // Sin will-change a propósito: con la capa fijada, Chrome no vuelve a
  // rasterizar al nuevo tamaño y la foto ampliada se ve borrosa.
  var ZOOM_MAX = 4, ZOOM_DOBLE = 2.5, PASO = 1.5;
  var z = { s: 1, x: 0, y: 0 };

  // Con decimales: clientWidth redondea al entero, y en un visor de 366.6 px el
  // límite dejaba asomar medio píxel de fondo por el borde de la foto.
  function medida() {
    var r = visor.getBoundingClientRect();
    return { w: r.width || 1, h: r.height || 1 };
  }
  function acotar() {
    var m = medida();
    z.s = Math.max(1, Math.min(ZOOM_MAX, z.s));
    // La foto nunca deja ver el fondo por un lado: el borde de la imagen
    // ampliada no pasa del borde del visor.
    z.x = Math.max(m.w - m.w * z.s, Math.min(0, z.x));
    z.y = Math.max(m.h - m.h * z.s, Math.min(0, z.y));
  }

  // modo: "animar" (saltos: botones, teclas, doble toque), "suave" (rueda,
  // pasos cortos y seguidos) o nada (arrastre y pellizco, que siguen al dedo).
  function pintarZoom(modo) {
    visor.classList.toggle("is-animando", modo === "animar");
    visor.classList.toggle("is-suave", modo === "suave");
    var ampliada = z.s > 1.001;
    lbImg.style.transform = ampliada
      ? "translate(" + z.x.toFixed(2) + "px," + z.y.toFixed(2) + "px) scale(" + z.s.toFixed(4) + ")"
      : "";
    visor.classList.toggle("is-ampliada", ampliada);
    lb.classList.toggle("is-ampliada", ampliada);
    var pct = Math.round(z.s * 100);
    if (nivelTxt) nivelTxt.textContent = pct + " %";
    if (bAlejar) bAlejar.setAttribute("aria-disabled", ampliada ? "false" : "true");
    if (bNivel) bNivel.setAttribute("aria-disabled", ampliada ? "false" : "true");
    if (bAcercar) bAcercar.setAttribute("aria-disabled", z.s >= ZOOM_MAX - 0.001 ? "true" : "false");
  }

  // Acerca o aleja manteniendo quieto el punto (px, py) del visor: lo que
  // estaba bajo el puntero o el dedo sigue ahí.
  function zoomEn(nuevo, px, py, modo) {
    nuevo = Math.max(1, Math.min(ZOOM_MAX, nuevo));
    // Por debajo de un 2 % es ruido de la rueda: se vuelve al tamaño exacto.
    if (nuevo < 1.02) nuevo = 1;
    var f = nuevo / z.s;
    z.x = px - (px - z.x) * f;
    z.y = py - (py - z.y) * f;
    z.s = nuevo;
    if (nuevo === 1) { z.x = 0; z.y = 0; }
    acotar();
    pintarZoom(modo);
  }

  function restablecer(modo) {
    z.s = 1; z.x = 0; z.y = 0;
    pintarZoom(modo);
  }

  function centro() { var m = medida(); return { x: m.w / 2, y: m.h / 2 }; }
  function puntoEnVisor(cx, cy) {
    var r = visor.getBoundingClientRect();
    return { x: Math.max(0, Math.min(r.width, cx - r.left)), y: Math.max(0, Math.min(r.height, cy - r.top)) };
  }

  function anunciar(texto) {
    if (!anuncio) return;
    anuncio.textContent = "";
    // En otro turno: el mismo texto dos veces seguidas no se volvería a leer.
    setTimeout(function() { anuncio.textContent = texto; }, 30);
  }
  function anunciarNivel() {
    anunciar(z.s > 1.001 ? "Foto ampliada al " + Math.round(z.s * 100) + " %" : "Foto a su tamaño");
  }

  function acercar(factor) {
    var c = centro();
    zoomEn(z.s * factor, c.x, c.y, "animar");
    anunciarNivel();
  }

  // ═══ La foto en el visor ════════════════════════════════════

  // Prepara la foto antes de pedir la transición: con la de la miniatura (ya
  // en memoria) no hay espera; sin ella, se carga la completa con un tope de
  // 400 ms para que el morfo tenga a qué llegar.
  function preparar(g) {
    var f = fuentesDeFoto(g.el);
    if (f.vista) return Promise.resolve({ src: f.vista, completa: f.completa, ratio: f.ratio });
    return cargarFoto(f.completa, 400).then(function(im) {
      return { src: f.completa, completa: f.completa, ratio: im && im.naturalWidth ? im.naturalWidth / im.naturalHeight : 0 };
    });
  }

  // `render` cambia el DOM y devuelve la promesa de que la foto ya se puede
  // pintar: la transición de vista espera a ella antes de tomar la captura
  // «nueva» (con tope, por si el navegador tardara en decodificar).
  function render(info) {
    var g = list[idx]; if (!g) return null;
    restablecer();
    if (info.ratio) ratioActual = info.ratio;
    visor.style.setProperty("--lb-ratio", ratioActual.toFixed(4));
    lbImg.src = info.src;
    lbImg.alt = g.n;
    lbN.textContent = g.n; lbT.textContent = g.t;
    lbCur.textContent = idx + 1; lbTotal.textContent = list.length;
    // Una sola foto en el grupo (el retrato de la cocina): sin flechas ni
    // contador, que prometían un «siguiente» que no existe.
    lb.classList.toggle("lightbox--sola", list.length < 2);
    if (!lbImg.decode) return null;
    return Promise.race([
      lbImg.decode().catch(function() {}),
      new Promise(function(r) { setTimeout(r, 250); })
    ]);
  }

  // Después de pintar: la versión completa en cuanto llegue, y las dos
  // vecinas pedidas por adelantado, que es lo que hace que pasar de foto no
  // espere a la red.
  function mejorar(info, n) {
    if (info.completa && absoluta(info.completa) !== absoluta(info.src)) {
      cargarFoto(info.completa).then(function(im) {
        if (!im || n !== pedido || !abierto) return;
        lbImg.src = info.completa;
        if (!info.ratio && im.naturalWidth) {
          ratioActual = im.naturalWidth / im.naturalHeight;
          visor.style.setProperty("--lb-ratio", ratioActual.toFixed(4));
        }
      });
    } else if (!info.ratio && lbImg.naturalWidth) {
      ratioActual = lbImg.naturalWidth / lbImg.naturalHeight;
      visor.style.setProperty("--lb-ratio", ratioActual.toFixed(4));
    }
    [1, -1].forEach(function(d) {
      var v = list[(idx + d + list.length) % list.length];
      if (v && list.length > 1) { var f = fuentesDeFoto(v.el); if (f.completa) cargarFoto(f.completa); }
    });
  }

  function groupId(el) { var s = el.closest("section, header"); return s ? s.id : ""; }

  var origenFoco = null, focoFijo = false;
  var soltarFondo = null;

  // `devolverA`: a quién vuelve el foco al cerrar, cuando no es la propia foto.
  // La carta abre el visor desde la foto grande, que vive en un bloque
  // aria-hidden y no es enfocable; el foco tiene que volver al platillo.
  function open(el, devolverA) {
    if (abierto || abriendo) return;
    var g = groupId(el);
    list = getZoomList().filter(function(z) { return groupId(z.el) === g; });
    if (!list.length) list = getZoomList();
    idx = Math.max(0, list.findIndex(function(z) { return z.el === el; }));
    if (!list[idx]) return;
    abriendo = true;
    // Al cerrar, el foco vuelve a la foto que se amplió (ya es enfocable).
    origenFoco = devolverA || el;
    focoFijo = !!devolverA;

    // El scroll se para FUERA de la transición: Lenis escribe sobre el scroll
    // del documento y hacerlo dentro de la captura mueve el fondo a mitad del
    // fundido.
    document.body.style.overflow = "hidden";
    if (lenis) lenis.stop();
    // aria-modal no encierra el foco: sin esto el tabulador salía del visor a
    // la página de detrás, invisible bajo el velo.
    if (!soltarFondo) soltarFondo = inertarFondo([lb]);
    // El cursor de la casa se retira encima del visor (ver _cursor.scss).
    body.classList.add("lightbox-abierto");

    var n = ++pedido;
    preparar(list[idx]).then(function(info) {
      abriendo = false;
      if (n !== pedido) return;
      abierto = true;
      conMorfo(el, true, function() {
        var listo = render(info);
        lb.classList.add("open");
        lb.setAttribute("aria-hidden", "false");
        // El foco entra al visor dentro del cambio y no después: hasta que
        // `.open` llega el visor es `visibility: hidden` y no admite foco.
        var cerrar = $("#lbClose");
        if (cerrar) cerrar.focus({ preventScroll: true });
        return listo;
      });
      mejorar(info, n);
    });
  }

  function close() {
    if (!abierto && !abriendo) return;
    var estabaAbierto = abierto;
    abierto = false;
    abriendo = false;
    pedido++;
    var actual = list[idx];
    if (estabaAbierto) {
      conMorfo(actual && actual.el, false, function() {
        restablecer();
        lb.classList.remove("open");
        lb.setAttribute("aria-hidden", "true");
      });
    }
    document.body.style.overflow = "";
    if (lenis) lenis.start();
    body.classList.remove("lightbox-abierto");
    // El fondo deja de ser inerte ANTES de devolver el foco: sobre un nodo
    // inerte, focus() no hace nada y el foco caería al <body>.
    if (soltarFondo) { soltarFondo(); soltarFondo = null; }
    // Si se pasó de foto, el foco vuelve a la que se estaba viendo —que es
    // donde aterriza el morfo—, no a la primera. Salvo que quien abrió pidiera
    // un destino propio (la carta: su foto grande no es enfocable).
    var destino = origenFoco;
    if (!focoFijo && actual && actual.el && actual.el.getAttribute("role") === "button") destino = actual.el;
    if (destino && destino.focus) destino.focus({ preventScroll: true });
    origenFoco = null;
  }

  // Pasar de foto restablece el acercamiento. Se puede pulsar seguido: cada
  // pulsación avanza el índice al momento y sólo la última llega a pintarse.
  function nav(d) {
    if (!abierto || list.length < 2) return;
    idx = (idx + d + list.length) % list.length;
    var n = ++pedido;
    preparar(list[idx]).then(function(info) {
      if (n !== pedido || !abierto) return;
      // Sin API que deslice las dos capturas, la entrada la sigue haciendo GSAP.
      if (reduce || !document.startViewTransition) {
        render(info);
        if (!reduce && window.gsap) gsap.fromTo(lbImg, { opacity: 0, x: d * 18 }, { opacity: 1, x: 0, duration: 0.4, ease: "power2.out", clearProps: "opacity,x" });
      } else {
        conDeslizado(d, function() { return render(info); });
      }
      mejorar(info, n);
    });
  }

  // ═══ Eventos ════════════════════════════════════════════════

  document.addEventListener("click", function(e) {
    var zoom = e.target.closest("[data-zoom]");
    if (!zoom || lb.contains(zoom)) return;
    e.preventDefault();
    open(zoom);
  });

  document.addEventListener("keydown", function(e) {
    if (e.key !== "Enter" && e.key !== " ") return;
    var zoom = e.target.closest && e.target.closest('[data-zoom][role="button"]');
    if (!zoom || e.target !== zoom) return;
    e.preventDefault();
    open(zoom);
  });

  $("#lbClose").addEventListener("click", function(e) { e.stopPropagation(); close(); });
  $("#lbPrev").addEventListener("click", function(e) { e.stopPropagation(); nav(-1); });
  $("#lbNext").addEventListener("click", function(e) { e.stopPropagation(); nav(1); });

  function deshabilitado(b) { return b && b.getAttribute("aria-disabled") === "true"; }
  if (bAcercar) bAcercar.addEventListener("click", function(e) { e.stopPropagation(); if (!deshabilitado(bAcercar)) acercar(PASO); });
  if (bAlejar) bAlejar.addEventListener("click", function(e) { e.stopPropagation(); if (!deshabilitado(bAlejar)) acercar(1 / PASO); });
  if (bNivel) bNivel.addEventListener("click", function(e) { e.stopPropagation(); if (!deshabilitado(bNivel)) { restablecer("animar"); anunciarNivel(); } });

  // Un clic en el fondo cierra. Tras un arrastre o un pellizco el navegador
  // dispara un clic al soltar, y ése no es «pulsar el fondo»: se descarta.
  var sinClic = false;
  lb.addEventListener("click", function(e) {
    if (!sinClic) return;
    sinClic = false;
    e.stopPropagation();
    e.preventDefault();
  }, true);
  lb.addEventListener("click", function(e) {
    if (!e.target.closest(".lightbox__img, button, .lightbox__cap, .lightbox__zoom")) close();
  });

  // Doble clic: acerca al punto señalado, o vuelve al tamaño si ya estaba
  // ampliada. Sólo ratón: el doble toque lo resuelve el gesto (abajo), y
  // algunos navegadores táctiles también disparan dblclick.
  var ultimoTipo = "mouse";
  visor.addEventListener("dblclick", function(e) {
    if (ultimoTipo !== "mouse") return;
    e.preventDefault();
    var p = puntoEnVisor(e.clientX, e.clientY);
    if (z.s > 1.001) restablecer("animar");
    else zoomEn(ZOOM_DOBLE, p.x, p.y, "animar");
    anunciarNivel();
  });

  // La rueda gradúa el acercamiento alrededor del puntero; el pellizco del
  // trackpad llega como rueda con ctrlKey y pasos finos. Lenis está parado
  // mientras el visor está abierto, pero se cancela igual: la página de
  // detrás no debe moverse con nada de lo que pase aquí.
  lb.addEventListener("wheel", function(e) {
    if (!abierto) return;
    e.preventDefault();
    var dx = e.deltaX, dy = e.deltaY;
    if (e.deltaMode === 1) { dx *= 16; dy *= 16; }
    else if (e.deltaMode === 2) { dx *= 400; dy *= 400; }
    if (Math.abs(dx) > Math.abs(dy) && !e.ctrlKey) {
      // El desplazamiento lateral del trackpad recorre la foto ampliada.
      if (z.s <= 1.001) return;
      z.x -= dx;
      acotar();
      pintarZoom();
      return;
    }
    var p = puntoEnVisor(e.clientX, e.clientY);
    zoomEn(z.s * Math.exp(-dy * (e.ctrlKey ? 0.01 : 0.0015)), p.x, p.y, "suave");
  }, { passive: false });

  // ── Gestos de puntero ──
  // Un dedo (o el ratón): con la foto ampliada, la recorre; sin ampliar, un
  // barrido horizontal pasa de foto (sólo táctil y lápiz: con ratón para eso
  // están las flechas). Dos dedos: pellizco alrededor de su punto medio, que
  // además arrastra. Doble toque: como el doble clic.
  //
  // La captura del puntero se pide sólo cuando el gesto ya es un arrastre:
  // capturado, el clic de un toque simple iría al visor entero y no a la
  // foto, y el visor lo tomaría como un clic en el fondo.
  var punteros = {}, gesto = null, ultimoToque = null;

  function listaPunteros() {
    return Object.keys(punteros).map(function(k) { return punteros[k]; });
  }

  function empezarGesto() {
    var ps = listaPunteros();
    if (ps.length >= 2) {
      var a = ps[0], b = ps[1];
      var m = puntoEnVisor((a.x + b.x) / 2, (a.y + b.y) / 2);
      gesto = {
        tipo: "pellizco",
        d0: Math.max(10, Math.hypot(a.x - b.x, a.y - b.y)),
        s0: z.s,
        // El punto de la foto que queda bajo los dedos al empezar.
        fx: (m.x - z.x) / z.s,
        fy: (m.y - z.y) / z.s,
        movido: true
      };
      ps.forEach(function(p) { try { lb.setPointerCapture(p.id); } catch (err) { /* ya soltado */ } });
      visor.classList.add("is-moviendo");
    } else if (ps.length === 1) {
      gesto = { tipo: "uno", id: ps[0].id, x0: ps[0].x, y0: ps[0].y, zx: z.x, zy: z.y, movido: false };
    } else {
      gesto = null;
    }
  }

  lb.addEventListener("pointerdown", function(e) {
    if (!abierto) return;
    ultimoTipo = e.pointerType;
    if (e.target.closest("button")) return;
    if (e.pointerType === "mouse" && e.button !== 0) return;
    punteros[e.pointerId] = { id: e.pointerId, x: e.clientX, y: e.clientY, tipo: e.pointerType };
    empezarGesto();
  });

  lb.addEventListener("pointermove", function(e) {
    var p = punteros[e.pointerId];
    if (!p || !gesto) return;
    p.x = e.clientX; p.y = e.clientY;

    if (gesto.tipo === "pellizco") {
      var ps = listaPunteros();
      if (ps.length < 2) return;
      var a = ps[0], b = ps[1];
      var d = Math.hypot(a.x - b.x, a.y - b.y);
      var m = puntoEnVisor((a.x + b.x) / 2, (a.y + b.y) / 2);
      z.s = Math.max(1, Math.min(ZOOM_MAX, gesto.s0 * d / gesto.d0));
      z.x = m.x - gesto.fx * z.s;
      z.y = m.y - gesto.fy * z.s;
      acotar();
      pintarZoom();
      return;
    }

    if (e.pointerId !== gesto.id) return;
    var dx = e.clientX - gesto.x0, dy = e.clientY - gesto.y0;
    if (!gesto.movido) {
      if (Math.hypot(dx, dy) < 6) return;
      gesto.movido = true;
      try { lb.setPointerCapture(e.pointerId); } catch (err) { /* ya soltado */ }
      if (z.s > 1.001) visor.classList.add("is-moviendo");
    }
    if (z.s > 1.001) {
      z.x = gesto.zx + dx;
      z.y = gesto.zy + dy;
      acotar();
      pintarZoom();
    }
  });

  function soltarPuntero(e) {
    var p = punteros[e.pointerId];
    if (!p) return;
    delete punteros[e.pointerId];
    var g = gesto;
    if (!g) return;

    if (g.tipo === "pellizco") {
      sinClic = true;
      if (z.s < 1.03) restablecer("animar");
      // Queda un dedo: sigue arrastrando desde donde está, sin salto.
      empezarGesto();
      if (gesto) { gesto.movido = true; gesto.trasPellizco = true; }
      if (!gesto) visor.classList.remove("is-moviendo");
      return;
    }

    gesto = null;
    visor.classList.remove("is-moviendo");
    if (e.type === "pointercancel") return;
    var dx = e.clientX - g.x0, dy = e.clientY - g.y0;

    if (g.movido) {
      sinClic = true;
      // Un clic que nunca llega (soltar fuera de la ventana) no debe dejar
      // la bandera puesta para el siguiente.
      setTimeout(function() { sinClic = false; }, 0);
      if (z.s <= 1.001 && p.tipo !== "mouse" && !g.trasPellizco && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.2) nav(dx < 0 ? 1 : -1);
      return;
    }

    // Un toque sin moverse. Dos seguidos y cerca son un doble toque.
    if (p.tipo === "mouse") return;
    var ahora = e.timeStamp;
    if (ultimoToque && ahora - ultimoToque.t < 320 && Math.hypot(e.clientX - ultimoToque.x, e.clientY - ultimoToque.y) < 30) {
      ultimoToque = null;
      sinClic = true;
      setTimeout(function() { sinClic = false; }, 350);
      var pt = puntoEnVisor(e.clientX, e.clientY);
      if (z.s > 1.001) restablecer("animar");
      else zoomEn(ZOOM_DOBLE, pt.x, pt.y, "animar");
      anunciarNivel();
    } else {
      ultimoToque = { t: ahora, x: e.clientX, y: e.clientY };
    }
  }
  lb.addEventListener("pointerup", soltarPuntero);
  lb.addEventListener("pointercancel", soltarPuntero);

  // ── Teclado ──
  // Flechas: con la foto ampliada la recorren; sin ampliar, pasan de foto.
  // + y − gradúan (también = y _, que es donde caen sin Mayúsculas), 0 vuelve
  // al tamaño. Con Ctrl o Cmd se dejan pasar: ése es el zoom del navegador.
  document.addEventListener("keydown", function(e) {
    if (!abierto) return;
    if (e.key === "Escape") { close(); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    var ampliada = z.s > 1.001, paso = 80;
    switch (e.key) {
      case "ArrowRight":
        if (ampliada) { z.x -= paso; acotar(); pintarZoom("animar"); } else nav(1);
        break;
      case "ArrowLeft":
        if (ampliada) { z.x += paso; acotar(); pintarZoom("animar"); } else nav(-1);
        break;
      case "ArrowDown":
        if (!ampliada) return;
        z.y -= paso; acotar(); pintarZoom("animar");
        break;
      case "ArrowUp":
        if (!ampliada) return;
        z.y += paso; acotar(); pintarZoom("animar");
        break;
      case "+": case "=": case "Add":
        acercar(PASO);
        break;
      case "-": case "_": case "Subtract":
        acercar(1 / PASO);
        break;
      case "0":
        restablecer("animar");
        anunciarNivel();
        break;
      default:
        return;
    }
    e.preventDefault();
  });

  // Si la ventana cambia de tamaño con la foto ampliada, los límites cambian
  // con el visor: se recalculan para que no quede un borde de fondo a la vista.
  window.addEventListener("resize", function() {
    if (!abierto || z.s <= 1.001) return;
    acotar();
    pintarZoom();
  });

  window.__openZoom = open;
}
