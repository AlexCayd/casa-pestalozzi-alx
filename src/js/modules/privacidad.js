/**
 * Aviso de privacidad: diálogo que se abre desde el pie y desde el formulario
 * de reservación.
 *
 * Mismo contrato que el anuncio (alternar [hidden] y .is-open) y mismas dos
 * cautelas: `overflow:hidden` no frena a Lenis, que desplaza por código, y sólo
 * se le reanuda si fuimos nosotros quienes lo paramos.
 */
function initPrivacidad() {
  var dialogo = document.querySelector("[data-privacidad]");
  if (!dialogo) return;

  var panel = dialogo.querySelector("[data-privacidad-panel]");
  var abridores = document.querySelectorAll("[data-privacidad-open]");
  var cierres = dialogo.querySelectorAll("[data-privacidad-close]");
  var reducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  var abierto = false;
  var overflowPrevio = "";
  var ultimoFoco = null;
  var lenisDetenido = false;

  function focoDentro(evento) {
    if (!abierto || dialogo.contains(evento.target)) return;
    if (panel) panel.focus();
  }

  function alPulsarTecla(evento) {
    if (evento.key === "Escape") {
      evento.preventDefault();
      cerrar();
    }
  }

  function abrir() {
    if (abierto) return;
    abierto = true;
    ultimoFoco = document.activeElement;

    overflowPrevio = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    if (window.CP_TWEAKS && window.CP_TWEAKS.smooth && window.stopLenis) {
      window.stopLenis();
      lenisDetenido = true;
    }

    dialogo.hidden = false;
    window.requestAnimationFrame(function () {
      dialogo.classList.add("is-open");
      if (panel) panel.focus();
    });

    document.addEventListener("keydown", alPulsarTecla);
    document.addEventListener("focusin", focoDentro);
  }

  function cerrar() {
    if (!abierto) return;
    abierto = false;

    document.removeEventListener("keydown", alPulsarTecla);
    document.removeEventListener("focusin", focoDentro);
    document.body.style.overflow = overflowPrevio;
    if (lenisDetenido && window.startLenis) {
      window.startLenis();
      lenisDetenido = false;
    }

    dialogo.classList.remove("is-open");

    function ocultar() {
      dialogo.hidden = true;
    }

    if (reducedMotion) {
      ocultar();
    } else {
      window.setTimeout(ocultar, 220);
    }

    // Devolver el foco al enlace que lo abrió: si no, quien navega con teclado
    // reaparece al principio de la página.
    if (ultimoFoco && typeof ultimoFoco.focus === "function") {
      ultimoFoco.focus();
    }
  }

  for (var i = 0; i < abridores.length; i++) {
    abridores[i].addEventListener("click", abrir);
  }
  for (var j = 0; j < cierres.length; j++) {
    cierres[j].addEventListener("click", cerrar);
  }
}
