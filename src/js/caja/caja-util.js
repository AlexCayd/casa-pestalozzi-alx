/**
 * Caja de Panadería — utilidades sin estado.
 *
 * El dinero viaja como texto exacto («24.00») y aquí se maneja en centavos
 * enteros: sumar 0.1 + 0.2 en flotante no cuadra con el corte. Las piezas de
 * vitrina llegan como DECIMAL(12,3) en texto; para pintarlas sí basta un
 * número, porque no se suman ni se cobran en el navegador.
 */
(function (window, document) {
  'use strict';

  var formato = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 });
  var formatoCorto = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0, maximumFractionDigits: 2 });

  function centavos(texto) {
    var m = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(String(texto == null ? '' : texto).trim());
    if (!m) return 0;
    var dec = ((m[3] || '') + '00').slice(0, 2);
    var v = parseInt(m[2], 10) * 100 + parseInt(dec, 10);
    return m[1] === '-' ? -v : v;
  }

  /** «$1,234.50». Con corto, sin «.00» en importes redondos: «$24». */
  function dinero(c, corto) {
    return (corto ? formatoCorto : formato).format(c / 100);
  }

  /** Texto exacto para el servidor: «200.00». */
  function decimal(c) {
    var signo = c < 0 ? '-' : '';
    c = Math.abs(c);
    return signo + Math.floor(c / 100) + '.' + String(c % 100).padStart(2, '0');
  }

  function piezas(texto) {
    var n = parseFloat(texto);
    return isFinite(n) ? n : 0;
  }

  /** «24», «5.5», «−1»: sin ceros de relleno y con signo menos tipográfico. */
  function textoPiezas(n) {
    var r = Math.round(n * 1000) / 1000;
    var t = (Number.isInteger(r) ? String(Math.abs(r)) : String(Math.abs(r)).replace(/0+$/, ''));
    return (r < 0 ? '−' : '') + t;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function clave(prefijo) {
    var id = window.crypto && window.crypto.randomUUID
      ? window.crypto.randomUUID()
      : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    return (prefijo || 'caja') + '-' + id;
  }

  var iconos = {};
  function icono(nombre) {
    if (!(nombre in iconos)) {
      var t = document.querySelector('template[data-caja-icono="' + nombre + '"]');
      iconos[nombre] = t ? t.innerHTML.trim() : '';
    }
    return iconos[nombre];
  }

  /**
   * Petición a la API de la caja. Nunca lanza: devuelve {ok, status, datos,
   * red}. `red` es un fallo de conexión: el llamador conserva su clave de
   * idempotencia y reintenta sin riesgo de cobrar dos veces.
   */
  function api(metodo, url, cuerpo) {
    var opciones = {
      method: metodo,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.CP_STAFF_CSRF_TOKEN || '' }
    };
    if (cuerpo !== undefined) {
      opciones.headers['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(cuerpo);
    }
    return fetch(url, opciones).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (datos) {
        return { ok: r.ok && datos && datos.ok === true, status: r.status, datos: datos || {}, red: false };
      });
    }, function () {
      return { ok: false, status: 0, datos: {}, red: true };
    });
  }

  var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

  function enfocables(raiz) {
    return Array.prototype.filter.call(
      raiz.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null || el === document.activeElement; }
    );
  }

  /** Tab y Shift+Tab dan la vuelta dentro de la hoja abierta. */
  function atraparFoco(raiz, evento) {
    if (evento.key !== 'Tab') return;
    var lista = enfocables(raiz);
    if (!lista.length) return;
    var primero = lista[0];
    var ultimo = lista[lista.length - 1];
    if (evento.shiftKey && document.activeElement === primero) { evento.preventDefault(); ultimo.focus(); }
    else if (!evento.shiftKey && document.activeElement === ultimo) { evento.preventDefault(); primero.focus(); }
  }

  /** Un toque háptico corto donde el equipo lo admite; nunca imprescindible. */
  function vibrar(ms) {
    if (navigator.vibrate && !reduce.matches) { try { navigator.vibrate(ms || 8); } catch (e) { /* sin háptica */ } }
  }

  window.CajaUtil = {
    centavos: centavos,
    dinero: dinero,
    decimal: decimal,
    piezas: piezas,
    textoPiezas: textoPiezas,
    esc: esc,
    clave: clave,
    icono: icono,
    api: api,
    reduce: reduce,
    enfocables: enfocables,
    atraparFoco: atraparFoco,
    vibrar: vibrar
  };
}(window, document));
