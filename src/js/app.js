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
var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
var isTouch = window.matchMedia("(pointer: coarse)").matches;
var T = Object.assign({ hero: "cinema", accent: "oro", cursor: true, smooth: true, anim: true }, window.CP_TWEAKS || {});
var suppressClick = false;

// Acento intercambiable
var ACCENTS = {
  oro:       ["#cca352", "#e0c184"],
  terracota: ["#c06a36", "#dca072"],
  salvia:    ["#88a37b", "#a9c19d"]
};

function applyAccent(key) {
  var a = ACCENTS[key] || ACCENTS.oro;
  document.documentElement.style.setProperty("--accent", a[0]);
  document.documentElement.style.setProperty("--accent-soft", a[1]);
}

// ── Boot ──────────────────────────────────────────────────────
function boot() {
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();
  if (window.gsap && window.ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

  applyAccent(T.accent);
  body.setAttribute("data-hero", T.hero);
  setCursorEnabled(T.cursor);
  if (!T.anim) body.classList.add("no-anim");

  // Lo de abajo depende del marcado de la home (nav, hero, menú, rail…).
  // /punto-de-venta y /area cargan este mismo bundle y se inicializan por su cuenta.
  if (body.dataset.page !== "home") return;

  initCursor();
  initMagnetic();
  initNav();
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

  if (window.gsap && window.ScrollTrigger) {
    heroIntro();
    initReveals();
    initCounters();
  } else {
    $$("[data-reveal]").forEach(function(el) { el.style.opacity = 1; el.style.transform = "none"; });
  }

  if (T.smooth) startLenis();
  setTimeout(function() { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, 600);
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
else boot();
