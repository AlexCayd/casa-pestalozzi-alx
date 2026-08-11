/**
 * Avisos transitorios con estilo (reemplazo de alert()).
 *
 * Se carga tanto en bundle.min.js como en admin.js; el guardia evita que la
 * segunda copia pise el stack ya montado y sus temporizadores.
 */
(function () {
  if (window.AppNotice) return;

  var VARIANTES = ['error', 'warning', 'success', 'info'];
  var MAX_VISIBLES = 3;
  var stack = null;

  function reduceMovimiento() {
    return Boolean(
      window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
  }

  function obtenerStack() {
    if (stack && stack.isConnected !== false && document.body.contains(stack)) return stack;
    stack = document.createElement('div');
    stack.className = 'app-notice-stack';
    stack.setAttribute('role', 'region');
    stack.setAttribute('aria-live', 'polite');
    stack.setAttribute('aria-label', 'Avisos');
    document.body.appendChild(stack);
    return stack;
  }

  function retirar(nodo) {
    if (!nodo || !nodo.parentNode) return;
    if (nodo.dataset.cerrando === '1') return;
    nodo.dataset.cerrando = '1';
    if (nodo.temporizador) {
      clearTimeout(nodo.temporizador);
      nodo.temporizador = null;
    }
    if (reduceMovimiento()) {
      nodo.parentNode.removeChild(nodo);
      return;
    }
    nodo.classList.remove('is-open');
    // Debe coincidir con la transición de _app-notice.scss.
    setTimeout(function () {
      if (nodo.parentNode) nodo.parentNode.removeChild(nodo);
    }, 200);
  }

  function podar(contenedor) {
    var vivos = contenedor.querySelectorAll('.app-notice:not([data-cerrando="1"])');
    for (var i = 0; i < vivos.length - MAX_VISIBLES + 1; i += 1) {
      retirar(vivos[i]);
    }
  }

  function show(opciones) {
    opciones = opciones || {};
    var texto = String(opciones.text || '').trim();
    if (!texto) return null;

    var variante = VARIANTES.indexOf(opciones.variant) !== -1 ? opciones.variant : 'info';
    var contenedor = obtenerStack();
    podar(contenedor);

    var aviso = document.createElement('div');
    aviso.className = 'app-notice app-notice--' + variante;
    // Los errores son los únicos que interrumpen la lectura en curso.
    aviso.setAttribute('role', variante === 'error' ? 'alert' : 'status');

    var cuerpo = document.createElement('p');
    cuerpo.className = 'app-notice__text';
    cuerpo.textContent = texto;

    var cerrar = document.createElement('button');
    cerrar.type = 'button';
    cerrar.className = 'app-notice__close';
    cerrar.setAttribute('aria-label', 'Descartar aviso');
    cerrar.textContent = '×';
    cerrar.addEventListener('click', function () {
      retirar(aviso);
    });

    aviso.appendChild(cuerpo);
    aviso.appendChild(cerrar);
    contenedor.appendChild(aviso);

    if (reduceMovimiento()) {
      aviso.classList.add('is-open');
    } else {
      window.requestAnimationFrame(function () {
        aviso.classList.add('is-open');
      });
    }

    var espera = parseInt(opciones.timeout, 10);
    if (isNaN(espera)) espera = variante === 'error' ? 8000 : 6000;
    if (espera > 0) {
      aviso.temporizador = setTimeout(function () {
        retirar(aviso);
      }, espera);
    }

    return {
      close: function () {
        retirar(aviso);
      }
    };
  }

  function clear() {
    if (!stack) return;
    Array.prototype.slice.call(stack.querySelectorAll('.app-notice')).forEach(retirar);
  }

  window.AppNotice = {
    show: show,
    error: function (texto, opciones) {
      return show(Object.assign({}, opciones || {}, { text: texto, variant: 'error' }));
    },
    warning: function (texto, opciones) {
      return show(Object.assign({}, opciones || {}, { text: texto, variant: 'warning' }));
    },
    success: function (texto, opciones) {
      return show(Object.assign({}, opciones || {}, { text: texto, variant: 'success' }));
    },
    clear: clear
  };
})();
