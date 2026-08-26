/* ============================================================
   CASA PESTALOZZI — Entry point JS
   Gulp concatena src/js/**‌/*.js en orden alfabético de ruta:
   1. app.js (este archivo) — define estado compartido + boot()
   2. modules/*.js            — funciones init*, split*, etc.
   window.CP_MENU / CP_AREAS ya no viven aquí: el punto de venta las emite en
   línea desde la BD (ver Services\Carta y views/punto-de-venta/index.php), y
   la landing pide su carta a /menu.
   boot() se llama en DOMContentLoaded, cuando todo ya está definido.
   ============================================================ */

// ── Estado y utilidades compartidos ──────────────────────────
var $ = function(s, c) { return (c || document).querySelector(s); };
var $$ = function(s, c) { return Array.from((c || document).querySelectorAll(s)); };
var body = document.body;
var consultaReduce = window.matchMedia("(prefers-reduced-motion: reduce)");
var reduce = consultaReduce.matches;
var isTouch = window.matchMedia("(pointer: coarse)").matches;

// La preferencia se leía UNA sola vez al cargar, así que quien la activaba a
// media visita —lo normal si algo le está mareando— tenía que recargar para
// que la página le hiciera caso. Ahora se escucha el cambio.
//
// Al activarla se apaga todo en caliente: .no-anim neutraliza los reveals y
// los bucles CSS, y se para Lenis. Al desactivarla NO se rearma la escena por
// su cuenta: media landing entra por líneas de tiempo que ya se consumieron, y
// reconstruirlas en vivo daría un resultado peor que el de una recarga.
if (consultaReduce.addEventListener) {
  consultaReduce.addEventListener("change", function(e) {
    reduce = e.matches;
    if (!reduce) return;
    body.classList.add("no-anim");
    if (typeof stopLenis === "function") stopLenis();
    if (window.ScrollTrigger) ScrollTrigger.refresh();
  });
}
var T = Object.assign({ hero: "cinema", cursor: true, smooth: true, anim: true }, window.CP_TWEAKS || {});

// `suppressClick` salió de aquí con el arrastre de la galería: era la bandera
// que distinguía "he soltado tras arrastrar" de "he hecho clic". Sin arrastre,
// un clic en una tarjeta es siempre un clic.

// El acento intercambiable (oro / terracota / salvia) vivía aquí como una
// tercera copia de la paleta y pisaba --accent en línea al arrancar, con lo
// que cualquier cambio en los tokens quedaba anulado. La paleta la fija el
// manual de marca en src/scss/layout/_reset.scss y no se conmuta en runtime.

// ── Boot ──────────────────────────────────────────────────────
function boot() {
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();
  if (window.gsap && window.ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

  body.setAttribute("data-hero", T.hero);
  setCursorEnabled(T.cursor);
  if (!T.anim) body.classList.add("no-anim");

  // Lo de abajo depende del marcado de la home (nav, hero, menú, rail…).
  // /punto-de-venta y /area cargan este mismo bundle y se inicializan por su cuenta.
  if (body.dataset.page !== "home") return;

  // Lenis arranca ANTES que los diálogos, y ese orden importa. Lenis desplaza
  // por código, así que un `overflow: hidden` en el <body> no lo frena: el
  // anuncio y el aviso de privacidad lo paran a mano al abrirse. Arrancándolo
  // al final de boot() —como estaba— el anuncio, que se abre en el acto,
  // encontraba `lenis` todavía en null, su stopLenis() no hacía nada, y la
  // rueda seguía recorriendo la portada por detrás del diálogo.
  if (T.smooth) startLenis();

  initCursor();
  initMagnetic();
  initNav();
  // Fuera de la rama de GSAP a propósito: el negativo es lo único que mantiene
  // legibles el nombre del restaurante y el índice de secciones al cruzar de
  // banda, y sin las libs de movimiento se quedaban en crema fija sobre las
  // secciones claras.
  tonoBajoLaMarca();
  tonoBajoElRail();
  initRail();
  initFab();
  initMenu();
  initGallery();
  initLightbox();
  initForm();
  initReservationAccess();
  initAnnouncementDismiss();
  initPrivacidad();
  initTweaks();

  // El lienzo del hero se monta antes que la intro: heroIntro() abre el velo
  // sobre él, y si el shader llegara después el fundido se vería en dos pasos.
  initHeroWebgl();

  if (window.gsap && window.ScrollTrigger) {
    heroIntro();
    initScrollArt();
    initReveals();
    initCounters();
  } else {
    $$("[data-reveal]").forEach(function(el) { el.style.opacity = 1; el.style.transform = "none"; });
  }

  setTimeout(function() { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, 600);
}

// El arranque NUNCA puede ocurrir en el mismo turno en que se evalúa este
// archivo, y la razón es cómo se construye el bundle: es un concat por orden
// alfabético de ruta, así que app.js va PRIMERO y los módulos se evalúan
// después. Las funciones no dan problema —se izan—, pero las constantes de
// nivel superior (GALLERY en gallery.js, HERO_VERT/HERO_FRAG en hero-webgl.js)
// sólo existen cuando su archivo ya corrió. Un boot() síncrono aquí llama a
// initGallery() con GALLERY todavía en undefined y revienta la página entera.
//
// Antes esto se sostenía por accidente: el <script> del bundle no llevaba
// defer, así que al ejecutarse readyState era "loading" y boot() se aplazaba
// hasta DOMContentLoaded. Al pasar el bundle a defer —necesario para que las
// libs vendorizadas carguen antes— readyState pasó a "interactive" y el
// arranque cayó en la rama síncrona. De ahí el setTimeout: empuja boot() al
// siguiente turno, cuando el bundle entero ya se evaluó, sin depender de qué
// valor tenga readyState.
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
else setTimeout(boot, 0);
