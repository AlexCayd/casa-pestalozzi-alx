/**
 * Tickets: la franja de cuatro filtros sin recargar la página.
 *
 * Sin este archivo la franja es un formulario GET y funciona igual (estado y
 * periodo en la URL, el servidor filtra). Lo que se gana aquí es no pagar una
 * recarga completa —con el reveal de entrada del panel incluido— cada vez que
 * se cambia de estado: en una pantalla que se consulta a diario, la quinta vez
 * que se ve entrar la página entera ya es una tasa.
 *
 * Sólo se sustituye la LISTA. Las cifras de las tarjetas no dependen del estado
 * elegido —dependen del periodo, y cambiar de periodo sí recarga—, así que las
 * tarjetas se quedan en su sitio y sólo cambia cuál está pulsada.
 *
 * Contrato con el servidor: AdminController::tickets() devuelve sólo
 * views/admin/tickets/_lista.php cuando la petición lleva X-Requested-With.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-tk-filtros]');
  var lista = document.querySelector('[data-tk-lista]');
  if (!form || !lista) {
    return;
  }
  // Sin fetch o sin history no hay forma de mantener la URL al día: se deja el
  // formulario tal cual, que es el respaldo completo.
  if (typeof window.fetch !== 'function' || !window.history || typeof window.history.pushState !== 'function') {
    return;
  }

  var ESTADOS = ['', 'abierto', 'cerrado', 'cancelado'];
  var anuncio = document.querySelector('[data-tk-anuncio]');
  var botones = Array.prototype.slice.call(form.querySelectorAll('[data-tk-estado]'));
  var secuencia = 0;
  var controlador = null;

  function estadoDe(url) {
    var valor = url.searchParams.get('estado') || '';
    return ESTADOS.indexOf(valor) !== -1 ? valor : '';
  }

  /* El periodo sale de los campos ocultos del propio formulario: son los que
     el servidor escribió para esta carga, así que no hay que reconstruirlo. */
  function urlPara(estado) {
    var url = new URL(form.getAttribute('action') || window.location.pathname, window.location.origin);
    Array.prototype.forEach.call(form.querySelectorAll('input[type="hidden"]'), function (campo) {
      if (campo.name && campo.value !== '') {
        url.searchParams.set(campo.name, campo.value);
      }
    });
    if (estado) {
      url.searchParams.set('estado', estado);
    }
    return url;
  }

  function marcar(estado) {
    botones.forEach(function (boton) {
      var suyo = boton.getAttribute('data-tk-estado') || '';
      boton.setAttribute('aria-pressed', suyo === estado ? 'true' : 'false');
    });
  }

  function botonDe(estado) {
    for (var i = 0; i < botones.length; i++) {
      if ((botones[i].getAttribute('data-tk-estado') || '') === estado) {
        return botones[i];
      }
    }
    return null;
  }

  /* La animación de entrada la pone el CSS (y la reduce con
     prefers-reduced-motion); aquí sólo se reinicia la clase. */
  function entrar() {
    lista.classList.remove('is-entrando');
    void lista.offsetWidth;
    lista.classList.add('is-entrando');
  }

  function anunciar() {
    if (!anuncio) {
      return;
    }
    var titulo = lista.querySelector('#tk-lista-titulo');
    var resumen = lista.querySelector('[data-tk-resumen]');
    anuncio.textContent = [
      titulo ? titulo.textContent.trim() : '',
      resumen ? resumen.textContent.trim() : ''
    ].join('. ');
  }

  /* Tras paginar desde abajo, la lista nueva empieza fuera de la vista. Se
     sube lo justo para ver su cabecera (debajo de la topbar fija) y nada más
     si ya está a la vista. */
  function llevarALaCabecera() {
    var panel = lista.closest('.tk-panel') || lista;
    var arriba = panel.getBoundingClientRect().top;
    var topbar = 96;
    if (arriba < topbar) {
      window.scrollTo(0, Math.max(0, window.pageYOffset + arriba - topbar));
    }
  }

  function cargar(url, opciones) {
    opciones = opciones || {};
    var mia = ++secuencia;

    if (controlador) {
      controlador.abort();
    }
    controlador = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    lista.setAttribute('aria-busy', 'true');

    return window.fetch(url.toString(), {
      headers: {
        'Accept': 'text/html',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      signal: controlador ? controlador.signal : undefined
    })
      .then(function (respuesta) {
        if (!respuesta.ok) {
          throw new Error('HTTP ' + respuesta.status);
        }
        return respuesta.text();
      })
      .then(function (html) {
        if (mia !== secuencia) {
          return;
        }
        lista.innerHTML = html;
        lista.setAttribute('aria-busy', 'false');
        entrar();

        if (opciones.empujar) {
          window.history.pushState({ tickets: true }, '', url.pathname + url.search);
        }
        if (opciones.aLaCabecera) {
          llevarALaCabecera();
        }
        if (opciones.foco) {
          opciones.foco();
        }
        anunciar();

        // El mismo evento que emiten los filtros reactivos: table-rows,
        // table-sort y motion.js (que vuelve a medir el documento para Lenis)
        // ya saben qué hacer con él.
        document.dispatchEvent(new CustomEvent('admin:reactive-updated', {
          detail: { target: lista, url: url.toString() }
        }));
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        if (mia !== secuencia) {
          return;
        }
        // Si la petición parcial falla, la navegación normal hace lo mismo
        // que el formulario sin JS: el filtro nunca se queda a medias.
        window.location.assign(url.toString());
      });
  }

  form.addEventListener('click', function (evento) {
    var boton = evento.target.closest('[data-tk-estado]');
    if (!boton || !form.contains(boton)) {
      return;
    }
    evento.preventDefault();

    if (boton.getAttribute('aria-pressed') === 'true') {
      return;
    }
    var estado = boton.getAttribute('data-tk-estado') || '';
    // La tarjeta responde al instante; la lista llega detrás.
    marcar(estado);
    cargar(urlPara(estado), { empujar: true });
  });

  lista.addEventListener('click', function (evento) {
    var enlace = evento.target.closest('a[href]');
    if (!enlace || evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
      return;
    }
    var pagina = !!enlace.closest('.admin-pagination');
    var quitar = enlace.hasAttribute('data-tk-estado');
    if (!pagina && !quitar) {
      return;
    }
    evento.preventDefault();

    var url = new URL(enlace.href, window.location.origin);
    var estado = estadoDe(url);
    marcar(estado);

    cargar(url, {
      empujar: true,
      aLaCabecera: pagina,
      // El enlace que se pulsó desaparece con el repintado. El foco va a donde
      // el teclado puede seguir: la cabecera de la lista nueva al paginar, la
      // tarjeta que quedó pulsada al quitar el filtro.
      foco: function () {
        var destino = pagina ? lista.querySelector('#tk-lista-titulo') : botonDe(estado);
        if (destino && typeof destino.focus === 'function') {
          try {
            destino.focus({ preventScroll: true });
          } catch (e) {
            destino.focus();
          }
        }
      }
    });
  });

  // Atrás y adelante recorren los filtros como si fueran páginas.
  window.addEventListener('popstate', function () {
    var url = new URL(window.location.href);
    if (url.pathname !== new URL(form.getAttribute('action') || url.pathname, window.location.origin).pathname) {
      return;
    }
    marcar(estadoDe(url));
    cargar(url, {});
  });
})();
