/* ============================================================
   CASA PESTALOZZI — Entry point JS
   Gulp concatena src/js/**‌/*.js en orden alfabético de ruta:
   1. app.js (este archivo) — define estado compartido + boot()
   2. data/menu-data.js      — window.CP_MENU
   3. modules/*.js            — funciones init*, split*, etc.
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

  initCursor();
  initMagnetic();
  initNav();
  initRail();
  initFab();
  initMenu();
  initGallery();
  initLightbox();
  initHours();
  initForm();
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
