/**
 * Estaciones de impresión (/admin/printers): el botón «Imprimir prueba» del
 * listado y los campos por tipo de conexión del formulario.
 *
 * Las dos piezas arrancan con su guarda de existencia: el mismo bundle se
 * carga en el listado y en el formulario, y cada página sólo tiene una.
 */
(function () {
  'use strict';

  /* ── Imprimir prueba ──────────────────────────────────────────
   *
   * Sin JS el botón es un POST que vuelve al listado con una alerta arriba:
   * funciona, pero recarga y manda la vista al principio, lejos de la fila
   * que se estaba probando. Aquí la misma ruta responde JSON
   * (AdminPrintersController::test) y el botón cuenta el resultado en su
   * sitio, con el aviso del panel para el detalle.
   *
   * Estados del botón (data-estado, que pinta el SCSS):
   *   listo → enviando → ok | error → listo
   * La prueba puede tardar: el conector de red espera hasta 2 s a una
   * impresora que no contesta. Por eso hay un estado de espera visible y el
   * botón no admite un segundo envío mientras dura.
   */
  var VUELTA_OK = 2200;
  var VUELTA_ERROR = 4000;

  function aviso(variante, texto) {
    if (window.AppNotice && typeof window.AppNotice.show === 'function') {
      window.AppNotice.show({ variant: variante, text: texto });
    }
  }

  function ponerEstado(boton, estado) {
    boton.setAttribute('data-estado', estado);
    // aria-disabled y no disabled: un botón deshabilitado pierde el foco, y
    // quien probó con el teclado se quedaría sin saber dónde está.
    if (estado === 'enviando') {
      boton.setAttribute('aria-disabled', 'true');
      boton.setAttribute('aria-busy', 'true');
    } else {
      boton.removeAttribute('aria-disabled');
      boton.removeAttribute('aria-busy');
    }
  }

  function probar(form, boton) {
    var datos = new FormData(form);

    if (boton.temporizador) {
      window.clearTimeout(boton.temporizador);
      boton.temporizador = null;
    }
    ponerEstado(boton, 'enviando');

    window.fetch(form.getAttribute('action') || '/admin/printers/test', {
      method: 'POST',
      body: datos,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (respuesta) {
        var tipo = respuesta.headers.get('Content-Type') || '';
        if (tipo.indexOf('application/json') === -1) {
          // Una sesión caducada responde con la página de acceso, no con
          // JSON. Se cae al envío normal, que el servidor sabe resolver.
          throw new Error('sin-json');
        }
        return respuesta.json();
      })
      .then(function (resultado) {
        var ok = !!(resultado && resultado.ok);
        var texto = resultado && resultado.mensaje ? String(resultado.mensaje) : '';
        ponerEstado(boton, ok ? 'ok' : 'error');
        // Siempre con texto de respaldo: un mensaje vacío del servidor no debe
        // producir una caja en blanco.
        aviso(ok ? 'success' : 'error', texto || (ok ? 'Ticket de prueba enviado.' : 'No se pudo imprimir la prueba.'));
        boton.temporizador = window.setTimeout(function () {
          ponerEstado(boton, 'listo');
          boton.temporizador = null;
        }, ok ? VUELTA_OK : VUELTA_ERROR);
      })
      .catch(function (error) {
        if (error && error.message === 'sin-json') {
          HTMLFormElement.prototype.submit.call(form);
          return;
        }
        ponerEstado(boton, 'error');
        aviso('error', 'No se pudo contactar con el servidor. Revisa la conexión e inténtalo de nuevo.');
        boton.temporizador = window.setTimeout(function () {
          ponerEstado(boton, 'listo');
          boton.temporizador = null;
        }, VUELTA_ERROR);
      });
  }

  function initPruebas() {
    if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
      return;
    }

    document.addEventListener('submit', function (evento) {
      var form = evento.target;
      if (!form || !form.matches || !form.matches('[data-prn-prueba]')) {
        return;
      }
      evento.preventDefault();

      var boton = form.querySelector('.prn-prueba__boton');
      if (!boton) {
        HTMLFormElement.prototype.submit.call(form);
        return;
      }
      if (boton.getAttribute('data-estado') === 'enviando') {
        return;
      }
      probar(form, boton);
    });
  }

  /* ── Campos por tipo de conexión (formulario) ─────────────────
   *
   * Muestra sólo los campos del tipo de conexión elegido (red / windows) y
   * deshabilita los del bloque oculto para que NO se envíen: el host de 'red'
   * o el dispositivo de 'windows' no deben arrastrarse al modo no elegido.
   *
   * El tipo de conexión es un grupo de radios, así que el valor se lee del
   * radio marcado y el listener va delegado en el fieldset: 'change' burbujea
   * desde cada radio, y con la delegación da igual cuántas opciones haya.
   */
  function initConexion() {
    var grupo = document.getElementById('conexion');
    if (!grupo) {
      return;
    }

    var bloques = document.querySelectorAll('.admin-menu__conexion');

    function valorActual() {
      var marcado = grupo.querySelector('input[name="conexion"]:checked');
      return marcado ? marcado.value : '';
    }

    function actualizar() {
      var valor = valorActual();

      Array.prototype.forEach.call(bloques, function (bloque) {
        var visible = (bloque.getAttribute('data-conexion') || '').split(' ').indexOf(valor) !== -1;
        bloque.hidden = !visible;
        Array.prototype.forEach.call(bloque.querySelectorAll('input, select'), function (campo) {
          campo.disabled = !visible;
        });
      });
    }

    grupo.addEventListener('change', actualizar);
    actualizar();
  }

  function boot() {
    initPruebas();
    initConexion();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
