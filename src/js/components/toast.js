/**
 * Avisos transitorios con estilo (reemplazo de alert()).
 *
 * Se carga tanto en bundle.min.js como en admin.js; el guardia evita que la
 * segunda copia pise el stack ya montado y sus temporizadores.
 *
 * Anatomía: chip de estado (icono) + rótulo + mensaje, una columna de cierre a
 * toda altura y, abajo, la línea que se consume con el tiempo que le queda.
 * El estado vive SÓLO en el chip y el rótulo; la lámina es neutra. Antes el
 * color iba en el contorno entero y el aviso se leía como una caja de alarma
 * pegada encima de la pantalla, no como una pieza de ella.
 */
(function () {
  if (window.AppNotice) return;

  var VARIANTES = ['error', 'warning', 'success', 'info'];
  var MAX_VISIBLES = 3;
  var stack = null;

  /* El rótulo dice el estado en PALABRAS: el color del chip no llega a quien
     no distingue el rojo del verde, y la forma del icono sola tampoco basta
     para un lector de pantalla. Va dentro del texto anunciado a propósito. */
  var ROTULOS = {
    error: 'Error',
    warning: 'Atención',
    success: 'Hecho',
    info: 'Aviso'
  };

  /* Nombres del catálogo (core/icons.js). Cada variante con una FORMA propia,
     no sólo un color: círculo con aspa, triángulo, paloma, círculo con «i». */
  var ICONOS = {
    error: 'error',
    warning: 'alerta',
    success: 'check',
    info: 'info'
  };

  /* El catálogo se resuelve al pintar, no al cargar: en el POS este archivo
     llega en bundle.min.js, que corre ANTES que map.js. Un nombre que falte
     devuelve cadena vacía, igual que en el catálogo. */
  function icono(nombre, tamano) {
    var catalogo = window.AdminIcons;
    if (!catalogo || typeof catalogo.get !== 'function') return '';
    return catalogo.get(nombre, tamano);
  }

  // Lee la duración de salida del CSS para no tener el número en dos sitios.
  function duracionSalida(nodo) {
    var declarada = 0;
    try {
      declarada = parseFloat(window.getComputedStyle(nodo).getPropertyValue('--app-notice-out')) || 0;
    } catch (error) {
      declarada = 0;
    }
    return declarada > 0 ? (declarada < 20 ? declarada * 1000 : declarada) : 160;
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

  /*
   * La pila cuelga bajo la barra de la pantalla si la hay (ver la cabecera de
   * _app-notice.scss). Se mide en cada aviso y no una vez: el header del POS
   * cambia de alto al girar la tablet o al partir en dos filas. Si la barra no
   * está a la vista —o es algo raro que ocupa media pantalla—, arriba.
   */
  var ANCLAS = '.operational-header, .admin-topbar';
  var HUECO = 10;

  function situar(contenedor) {
    var ancla = document.querySelector(ANCLAS);
    var borde = ancla ? ancla.getBoundingClientRect().bottom : 0;
    if (borde > 0 && borde < window.innerHeight / 2) {
      contenedor.style.setProperty('--app-notice-top', Math.round(borde + HUECO) + 'px');
    } else {
      contenedor.style.removeProperty('--app-notice-top');
    }
  }

  function limpiarTemporizador(nodo) {
    if (nodo.temporizador) {
      clearTimeout(nodo.temporizador);
      nodo.temporizador = null;
    }
  }

  function retirar(nodo) {
    if (!nodo || !nodo.parentNode) return;
    if (nodo.dataset.cerrando === '1') return;
    nodo.dataset.cerrando = '1';
    limpiarTemporizador(nodo);

    // Si el foco estaba dentro (en el botón de cierre), no se pierde en el <body>.
    var devolverFoco = nodo.contains(document.activeElement) ? nodo.focoPrevio : null;

    // También con movimiento reducido: el CSS cambia el desplazamiento por un
    // fundido corto, pero la salida sigue siendo visible.
    var salida = duracionSalida(nodo);
    nodo.classList.remove('is-open');
    setTimeout(function () {
      if (nodo.parentNode) nodo.parentNode.removeChild(nodo);
    }, salida + 30);

    if (devolverFoco && devolverFoco.isConnected !== false && typeof devolverFoco.focus === 'function') {
      try { devolverFoco.focus({ preventScroll: true }); } catch (error) { devolverFoco.focus(); }
    }
  }

  /*
   * Pausa mientras se mira. Un error que se va a los ocho segundos mientras
   * el mesero aún lo está leyendo obliga a repetir la acción para volver a
   * verlo; con el puntero o el foco encima, el reloj se detiene y la línea de
   * tiempo se congela con él (WCAG 2.2.1).
   */
  function pausar(nodo) {
    if (!nodo.temporizador || nodo.dataset.cerrando === '1') return;
    limpiarTemporizador(nodo);
    nodo.restante = Math.max(0, nodo.restante - (Date.now() - nodo.inicio));
    nodo.classList.add('is-paused');
  }

  function reanudar(nodo) {
    if (nodo.temporizador || nodo.dataset.cerrando === '1' || !(nodo.restante > 0)) return;
    if (nodo.matches(':hover') || nodo.contains(document.activeElement)) return;
    nodo.classList.remove('is-paused');
    nodo.inicio = Date.now();
    nodo.temporizador = setTimeout(function () {
      retirar(nodo);
    }, nodo.restante);
  }

  function podar(contenedor) {
    var vivos = contenedor.querySelectorAll('.app-notice:not([data-cerrando="1"])');
    for (var i = 0; i < vivos.length - MAX_VISIBLES + 1; i += 1) {
      retirar(vivos[i]);
    }
  }

  function show(opciones) {
    opciones = opciones || {};
    // Un aviso sin texto nunca se pinta: sería una caja en blanco con un rótulo.
    var texto = String(opciones.text || '').trim();
    if (!texto) return null;

    var variante = VARIANTES.indexOf(opciones.variant) !== -1 ? opciones.variant : 'info';
    var contenedor = obtenerStack();
    situar(contenedor);
    podar(contenedor);

    var aviso = document.createElement('div');
    aviso.className = 'app-notice app-notice--' + variante;
    // Los errores son los únicos que interrumpen la lectura en curso.
    aviso.setAttribute('role', variante === 'error' ? 'alert' : 'status');
    aviso.focoPrevio = document.activeElement;

    var chip = document.createElement('span');
    chip.className = 'app-notice__icon';
    chip.setAttribute('aria-hidden', 'true');
    chip.innerHTML = icono(ICONOS[variante], 18);

    var cuerpo = document.createElement('div');
    cuerpo.className = 'app-notice__body';

    // <span> y no <p>: `.admin-body p` (0,1,1) le impone --admin-muted al
    // rótulo y al mensaje y le gana a la clase del componente (0,1,0).

    var rotulo = document.createElement('span');
    rotulo.className = 'app-notice__label';
    rotulo.textContent = ROTULOS[variante];

    var mensaje = document.createElement('span');
    mensaje.className = 'app-notice__text';
    mensaje.textContent = texto;

    cuerpo.appendChild(rotulo);
    cuerpo.appendChild(mensaje);

    var cerrar = document.createElement('button');
    cerrar.type = 'button';
    cerrar.className = 'app-notice__close';
    cerrar.setAttribute('aria-label', 'Descartar aviso');
    var aspa = icono('cerrar', 18);
    if (aspa) {
      cerrar.innerHTML = aspa;
    } else {
      // Sin catálogo, la palabra: nunca un glifo haciendo de icono.
      cerrar.textContent = 'Cerrar';
      cerrar.classList.add('app-notice__close--texto');
    }
    cerrar.addEventListener('click', function () {
      retirar(aviso);
    });

    aviso.appendChild(chip);
    aviso.appendChild(cuerpo);
    aviso.appendChild(cerrar);

    var espera = parseInt(opciones.timeout, 10);
    if (isNaN(espera)) espera = variante === 'error' ? 8000 : 6000;

    if (espera > 0) {
      // La línea de tiempo dura lo mismo que el temporizador: un solo número.
      var linea = document.createElement('span');
      linea.className = 'app-notice__timer';
      linea.setAttribute('aria-hidden', 'true');
      aviso.style.setProperty('--app-notice-espera', espera + 'ms');
      aviso.appendChild(linea);
    }

    contenedor.appendChild(aviso);

    // Se fija el estado inicial (leer offsetWidth fuerza el cálculo) y se abre
    // en el frame siguiente: así la transición de entrada tiene de dónde salir.
    void aviso.offsetWidth;
    window.requestAnimationFrame(function () {
      aviso.classList.add('is-open');
    });

    if (espera > 0) {
      aviso.restante = espera;
      aviso.inicio = Date.now();
      aviso.temporizador = setTimeout(function () {
        retirar(aviso);
      }, espera);

      aviso.addEventListener('mouseenter', function () { pausar(aviso); });
      aviso.addEventListener('mouseleave', function () {
        // :hover todavía es verdadero dentro de mouseleave en algunos motores.
        setTimeout(function () { reanudar(aviso); }, 0);
      });
      aviso.addEventListener('focusin', function () { pausar(aviso); });
      aviso.addEventListener('focusout', function () {
        setTimeout(function () { reanudar(aviso); }, 0);
      });
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
