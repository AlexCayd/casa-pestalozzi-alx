/**
 * Anuncio del restaurante al entrar al sitio, en dos presentaciones.
 *
 * `modal` (eventos y avisos operativos): bloquea y espera a que lo cierren,
 * porque cambia el plan de quien iba a venir.
 * `discreto` (promociones y novedades de la carta): aparece en la esquina, no
 * toca el scroll ni el foco, y se retira solo. Un 2×1 no justifica interrumpir
 * la visita con un diálogo.
 *
 * Cuál de las dos usa cada tipo lo decide AnuncioConfig y llega en
 * data-announcement-presentacion; aquí no se vuelve a decidir.
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
  var esModal = dialogo.getAttribute('data-announcement-presentacion') !== 'discreto';
  var duracion = parseInt(dialogo.getAttribute('data-announcement-duracion'), 10) || 8000;
  var overflowPrevio = '';
  var ultimoFoco = null;
  var abierto = false;
  var lenisDetenido = false;
  var temporizador = null;

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

  // ── Retirada automática del aviso discreto ──────────────────
  function detenerCuentaAtras() {
    if (temporizador) {
      window.clearTimeout(temporizador);
      temporizador = null;
    }
  }

  function arrancarCuentaAtras() {
    detenerCuentaAtras();
    temporizador = window.setTimeout(cerrar, duracion);
  }

  function cerrar() {
    if (!abierto) return;
    abierto = false;
    detenerCuentaAtras();
    // También al vencer solo: si se retiró sin que nadie lo tocara, ya cumplió
    // su trabajo y repetirlo en cada página sería un parpadeo constante.
    recordarCierre();

    if (esModal) {
      document.removeEventListener('keydown', alPulsarTecla);
      document.removeEventListener('focusin', focoDentro);
      document.body.style.overflow = overflowPrevio;
      // Sólo se reanuda si fuimos nosotros quienes lo paramos: si el visitante
      // tiene el scroll suave desactivado en los ajustes, no se le enciende.
      if (lenisDetenido && window.startLenis) {
        window.startLenis();
        lenisDetenido = false;
      }
    }

    function ocultar() {
      dialogo.classList.remove('is-open');
      dialogo.classList.remove('is-gsap');
      dialogo.hidden = true;
      // El panel queda con los transform de la línea de tiempo puestos; sin
      // limpiarlos, un segundo abrir() partiría desde donde acabó el cierre.
      if (window.gsap && panel) gsap.set(panel, { clearProps: 'all' });
    }

    salida(ocultar);

    // Devolver el foco sólo tiene sentido si se lo habíamos quitado.
    if (esModal && ultimoFoco && typeof ultimoFoco.focus === 'function') {
      ultimoFoco.focus();
    }
  }

  /* La entrada: "la comanda que se posa en el mantel".
     ------------------------------------------------------------------
     La tarjeta no aparece, se DEJA: sube desde abajo a la derecha —de donde
     viene quien trae algo a la mesa—, entra ligeramente ladeada y se asienta
     recta, como un papel que se suelta y encuentra su sitio. El filete de
     acento se traza después, de arriba abajo: el mismo gesto que la rayita de
     los rótulos de sección, que es lo que la ata al resto de la página.

     Todo esto es un extra. La clase .is-open sigue siendo el contrato y el CSS
     conserva su transición: sin GSAP, o con movimiento reducido, el aviso entra
     igual con el fundido de siempre. */
  function entrada() {
    if (reducedMotion || !window.gsap || !panel) return;

    // Le dice al CSS que se aparte: su propia transición de entrada se sumaría
    // a ésta y el aviso llegaría desde más abajo de lo que se diseñó.
    dialogo.classList.add('is-gsap');

    var filete = dialogo.querySelector('.hero-announcement__indicator');
    var tl = gsap.timeline();

    tl.fromTo(panel,
      { yPercent: 14, rotate: 1.6, opacity: 0, transformOrigin: '100% 100%' },
      { yPercent: 0, rotate: 0, opacity: 1, duration: 0.72, ease: 'power3.out' });

    if (filete) {
      tl.fromTo(filete,
        { scaleY: 0, transformOrigin: 'top center' },
        { scaleY: 1, duration: 0.5, ease: 'power2.out' }, '-=0.34');
    }
  }

  /* Tres caminos, y ninguno puede dejar la tarjeta a medias:
     con GSAP se retira hacia abajo; sin GSAP se le deja a la transición CSS sus
     220 ms quitando .is-open antes; y con movimiento reducido se oculta en seco. */
  function salida(alTerminar) {
    if (reducedMotion) { alTerminar(); return; }

    if (!window.gsap || !panel) {
      dialogo.classList.remove('is-open');
      window.setTimeout(alTerminar, 220);
      return;
    }

    gsap.to(panel, {
      yPercent: 12,
      opacity: 0,
      duration: 0.32,
      ease: 'power2.in',
      onComplete: alTerminar,
    });
  }

  function abrir() {
    if (abierto) return;
    abierto = true;

    if (esModal) {
      ultimoFoco = document.activeElement;
      overflowPrevio = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      // `overflow: hidden` no frena a Lenis, que desplaza por código: sin
      // pararlo, la rueda seguiría moviendo la portada por detrás del diálogo.
      if (window.CP_TWEAKS && window.CP_TWEAKS.smooth && window.stopLenis) {
        window.stopLenis();
        lenisDetenido = true;
      }
    }

    dialogo.hidden = false;
    window.requestAnimationFrame(function () {
      dialogo.classList.add('is-open');
      entrada();
      // El discreto no roba el foco: el visitante puede estar escribiendo en el
      // formulario de reservación cuando aparece.
      if (esModal && panel) panel.focus();
    });

    if (esModal) {
      document.addEventListener('keydown', alPulsarTecla);
      // Trampa de foco sencilla: el diálogo tiene dos o tres elementos
      // enfocables, así que basta con devolver el foco al panel si se escapa.
      document.addEventListener('focusin', focoDentro);
      return;
    }

    arrancarCuentaAtras();

    // Nadie debería perder el aviso por haber ido a leerlo: mientras el puntero
    // o el foco estén encima, la cuenta atrás se detiene y se reinicia al salir.
    dialogo.addEventListener('mouseenter', detenerCuentaAtras);
    dialogo.addEventListener('mouseleave', arrancarCuentaAtras);
    dialogo.addEventListener('focusin', detenerCuentaAtras);
    dialogo.addEventListener('focusout', arrancarCuentaAtras);
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
