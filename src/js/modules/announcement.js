/**
 * Anuncio del restaurante: diálogo al entrar al sitio.
 *
 * Antes era una franja sobre el hero con cuenta atrás de 8 s. El aviso competía
 * con la portada y quien llegaba tarde ya no lo veía, así que ahora se presenta
 * como diálogo y espera a que lo cierren.
 *
 * Se conserva la memoria por sesión con la clave id:version: si cambia el
 * anuncio vuelve a salir, pero navegar por el sitio no lo repite.
 */
function initAnnouncementDismiss() {
  var dialogo = document.querySelector('[data-announcement]');
  if (!dialogo) return;

  var panel = dialogo.querySelector('[data-announcement-panel]');
  var cierres = dialogo.querySelectorAll('[data-announcement-close]');
  var announcementId = dialogo.getAttribute('data-announcement-id') || 'actual';
  var announcementVersion = dialogo.getAttribute('data-announcement-version') || 'sin-version';
  var storageKey = 'cp-announcement-hidden:' + announcementId + ':' + announcementVersion;
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var overflowPrevio = '';
  var ultimoFoco = null;
  var abierto = false;
  var lenisDetenido = false;

  function recordarCierre() {
    try {
      window.sessionStorage.setItem(storageKey, '1');
    } catch (error) {
      // El diálogo se cierra igual aunque el almacenamiento no esté disponible;
      // sólo se pierde el "no volver a mostrar" de esta sesión.
    }
  }

  function yaSeVio() {
    try {
      return window.sessionStorage.getItem(storageKey) === '1';
    } catch (error) {
      return false;
    }
  }

  function focoDentro(evento) {
    if (!abierto || dialogo.contains(evento.target)) return;
    if (panel) panel.focus();
  }

  function alPulsarTecla(evento) {
    if (evento.key === 'Escape') {
      evento.preventDefault();
      cerrar();
    }
  }

  function cerrar() {
    if (!abierto) return;
    abierto = false;
    recordarCierre();

    document.removeEventListener('keydown', alPulsarTecla);
    document.removeEventListener('focusin', focoDentro);
    document.body.style.overflow = overflowPrevio;
    // Sólo se reanuda si fuimos nosotros quienes lo paramos: si el visitante
    // tiene el scroll suave desactivado en los ajustes, no se le enciende.
    if (lenisDetenido && window.startLenis) {
      window.startLenis();
      lenisDetenido = false;
    }

    dialogo.classList.remove('is-open');

    function ocultar() {
      dialogo.hidden = true;
    }

    if (reducedMotion) {
      ocultar();
    } else {
      window.setTimeout(ocultar, 220);
    }

    if (ultimoFoco && typeof ultimoFoco.focus === 'function') {
      ultimoFoco.focus();
    }
  }

  function abrir() {
    if (abierto) return;
    abierto = true;
    ultimoFoco = document.activeElement;

    overflowPrevio = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    // `overflow: hidden` no frena a Lenis, que desplaza por código: sin pararlo,
    // la rueda seguiría moviendo la portada por detrás del diálogo.
    if (window.CP_TWEAKS && window.CP_TWEAKS.smooth && window.stopLenis) {
      window.stopLenis();
      lenisDetenido = true;
    }

    dialogo.hidden = false;
    window.requestAnimationFrame(function () {
      dialogo.classList.add('is-open');
      if (panel) panel.focus();
    });

    document.addEventListener('keydown', alPulsarTecla);
    // Trampa de foco sencilla: el diálogo tiene dos o tres elementos
    // enfocables, así que basta con devolver el foco al panel si se escapa.
    document.addEventListener('focusin', focoDentro);
  }

  if (yaSeVio()) {
    dialogo.hidden = true;
    return;
  }

  for (var i = 0; i < cierres.length; i++) {
    cierres[i].addEventListener('click', cerrar);
  }

  // Un enlace del anuncio lleva a otra parte del sitio: se da por visto.
  var enlace = dialogo.querySelector('.hero-announcement__link');
  if (enlace) {
    enlace.addEventListener('click', recordarCierre);
  }

  abrir();
}
