/**
 * Caja de mostrador de Panadería (/panaderia/caja).
 *
 * La pantalla es el mostrador: a la izquierda la vitrina —una tecla por pan—,
 * a la derecha el ticket. Levantar una venta es tocar panes y cobrar:
 *
 *  · Cada toque suma una pieza. Mantener presionado ofrece 2 · 3 · 6 · 12.
 *  · Un pan con un solo grupo de 2 a 4 opciones parte su tecla en gajos: el
 *    gajo ES la opción, sin abrir nada. Con más grupos u opciones, la tecla
 *    crece en su sitio y las vecinas se apartan.
 *  · Cada tecla dice cuántas piezas quedan en la vitrina y lo dibuja con un
 *    filete que se vacía. En cero se ve agotada pero se puede vender: el pan
 *    está en la mano del cliente; la venta avisa y Almacenes lo marca.
 *  · Efectivo y Tarjeta son dos botones, no un paso previo. El cambio se
 *    calcula al tocar un billete.
 *
 * El navegador no decide precios ni piezas: manda ofertas, cantidades y
 * opciones, y pinta lo que el servidor confirma (Services\Pos\CajaMostrador).
 */
(function (window, document) {
  'use strict';

  var U = window.CajaUtil;
  var raiz = document.querySelector('[data-caja]');
  if (!U || !raiz) return;

  var datos = window.CP_CAJA || {};
  var usuario = window.CP_USER || { id: 0 };
  var BASE = '/api/panaderia/caja';
  var ATAJOS = [2, 3, 6, 12];
  var PRESION_MS = 420;
  var compacto = window.matchMedia('(max-width: 959px)');

  var estado = {
    permisos: datos.permisos || {},
    productos: [],
    porId: {},
    categorias: [],
    vitrina: {},
    impresora: !!datos.impresora,
    motivos: datos.motivos || {},
    familia: 'todo',
    busqueda: '',
    lineas: [],
    multiplicador: '',
    abierta: null,      // oferta con la tecla extendida
    pago: null,         // {metodo, recibido, clave, enviando, error}
    vendido: null,      // última venta confirmada, mientras se muestra
    deshacer: null      // {lineas, texto, timer}
  };

  var el = {
    familias: raiz.querySelector('[data-caja-familias]'),
    teclas: raiz.querySelector('[data-caja-teclas]'),
    buscar: raiz.querySelector('[data-caja-buscar]'),
    sinResultados: raiz.querySelector('[data-caja-sin-resultados]'),
    ticket: raiz.querySelector('[data-caja-ticket]'),
    lineas: raiz.querySelector('[data-caja-lineas]'),
    vacio: raiz.querySelector('[data-caja-ticket-vacio]'),
    pie: raiz.querySelector('[data-caja-pie]'),
    piezas: raiz.querySelector('[data-caja-piezas]'),
    total: raiz.querySelector('[data-caja-total]'),
    vaciar: raiz.querySelector('[data-caja-vaciar]'),
    pago: raiz.querySelector('[data-caja-pago]'),
    barra: raiz.querySelector('[data-caja-abrir-ticket]'),
    barraPiezas: raiz.querySelector('[data-caja-barra-piezas]'),
    barraTotal: raiz.querySelector('[data-caja-barra-total]'),
    velo: document.querySelector('[data-caja-velo]'),
    pulso: document.querySelector('[data-caja-pulso-ventas]'),
    anuncio: raiz.querySelector('[data-caja-anuncio]'),
    vitrina: raiz.querySelector('.caja-vitrina')
  };

  // Un solo portavoz para el lector de pantalla. El ticket y el cobro se
  // repintan enteros en cada toque: con aria-live en la lista se leía la
  // venta completa cada vez, y una <output> recién insertada no se anuncia.
  // Aquí se dice sólo lo que cambió. Vaciar antes de escribir hace que el
  // mismo texto dos veces seguidas («Croissant, 2 piezas…») también suene.
  // Con una hoja abierta (aria-modal) se usa el portavoz de la hoja: algunos
  // lectores callan lo que vive fuera del diálogo.
  var anuncioTimer = null;
  function anunciar(texto) {
    var destino = (hojaAbierta && hojaAbierta.querySelector('[data-caja-hoja-anuncio]')) || el.anuncio;
    if (!destino) return;
    clearTimeout(anuncioTimer);
    destino.textContent = '';
    anuncioTimer = setTimeout(function () { destino.textContent = texto; }, 60);
  }

  // ── Datos ─────────────────────────────────────────────────────

  function cargarCatalogo(catalogo) {
    estado.categorias = (catalogo && catalogo.categorias) || [];
    estado.productos = (catalogo && catalogo.productos) || [];
    estado.porId = {};
    estado.productos.forEach(function (p) {
      p.precio = U.centavos(p.p);
      (p.g || []).forEach(function (g) {
        g.o.forEach(function (o) { o.delta = U.centavos(o.d); });
      });
      estado.porId[p.id] = p;
    });
  }

  function cargarVitrina(lista, parcial) {
    if (!parcial) estado.vitrina = {};
    (lista || []).forEach(function (v) {
      estado.vitrina[v.clave] = { saldo: U.piezas(v.saldo), minimo: U.piezas(v.minimo), nombre: v.nombre, almacen: v.almacen_id, articulo: v.articulo_id };
    });
  }

  /** Grupo que se ofrece partido en gajos, o null si la tecla debe crecer. */
  function grupoGajos(p) {
    if (!p.g || p.g.length !== 1) return null;
    var g = p.g[0];
    return g.o.length >= 2 && g.o.length <= 4 ? g : null;
  }

  function predeterminada(g) {
    for (var i = 0; i < g.o.length; i++) if (g.o[i].def) return g.o[i];
    return null;
  }

  /** Opciones resueltas: la elegida en cada grupo o su predeterminada. */
  function resolver(p, elegidas) {
    var ops = [];
    for (var i = 0; i < (p.g || []).length; i++) {
      var g = p.g[i];
      var op = null;
      for (var j = 0; j < g.o.length; j++) if (elegidas.indexOf(g.o[j].id) !== -1) op = g.o[j];
      op = op || predeterminada(g);
      if (!op) return null; // grupo obligatorio sin elegir
      ops.push(op);
    }
    return ops;
  }

  /** Pieza que descuenta una venta con esas opciones: {clave, q}. */
  function piezaDe(p, ops) {
    for (var i = 0; i < ops.length; i++) if (ops[i].pz) return { clave: ops[i].pz, q: U.piezas(ops[i].q) || 1 };
    return p.pz ? { clave: p.pz, q: U.piezas(p.q) || 1 } : null;
  }

  /** Cuántas ventas caben en la vitrina con esa pieza (null: no es de vitrina). */
  function disponibles(pieza) {
    if (!pieza || !estado.vitrina[pieza.clave]) return null;
    return Math.floor((estado.vitrina[pieza.clave].saldo + 1e-9) / pieza.q);
  }

  function nivel(pieza) {
    var v = pieza && estado.vitrina[pieza.clave];
    if (!v) return null;
    var tope = Math.max(v.minimo * 3, 1);
    return { valor: Math.max(0, Math.min(1, v.saldo / tope)), pocas: v.saldo > 0 && v.saldo <= v.minimo, agotada: v.saldo <= 0 };
  }

  function enTicket(ofertaId) {
    return estado.lineas.reduce(function (n, l) { return n + (l.oferta === ofertaId ? l.cantidad : 0); }, 0);
  }

  // ── Vitrina: familias y teclas ────────────────────────────────

  function pintarFamilias() {
    if (!el.familias) return;
    var familias = [{ id: 'todo', nombre: 'Todo' }].concat(estado.categorias.map(function (c) { return { id: String(c.id), nombre: c.nombre }; }));
    el.familias.innerHTML = familias.map(function (f) {
      var activa = f.id === estado.familia;
      return '<button type="button" role="tab" class="caja-familia' + (activa ? ' is-activa' : '') + '" data-familia="' + U.esc(f.id) + '" aria-selected="' + activa + '" aria-controls="caja-teclas" tabindex="' + (activa ? '0' : '-1') + '">' + U.esc(f.nombre) + '</button>';
    }).join('');
  }

  function normalizar(t) {
    return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  function visibles() {
    var q = normalizar(estado.busqueda.trim());
    return estado.productos.filter(function (p) {
      if (q) return normalizar(p.n).indexOf(q) !== -1 || (p.g || []).some(function (g) { return g.o.some(function (o) { return normalizar(o.n).indexOf(q) !== -1; }); });
      return estado.familia === 'todo' || String(p.c) === estado.familia;
    });
  }

  function cifraPiezas(n) {
    if (n === null) return '';
    return '<span class="caja-piezas' + (n <= 0 ? ' is-agotada' : '') + '"><span class="caja-piezas__n">' + U.textoPiezas(n) + '</span><span class="operational-visually-hidden"> en vitrina</span></span>';
  }

  function teclaHtml(p) {
    var g = grupoGajos(p);
    var compleja = !g && p.g && p.g.length > 0;
    var def = g ? predeterminada(g) : null;
    var opsBase = resolver(p, []) || [];
    var piezaBase = piezaDe(p, opsBase.length ? opsBase : []);
    var nv = nivel(piezaBase);
    var cuenta = enTicket(p.id);
    var clases = ['caja-tecla'];
    if (g) clases.push('is-gajos');
    if (compleja) clases.push('is-compleja');
    if (nv && nv.pocas) clases.push('is-pocas');
    if (nv && nv.agotada && !g) clases.push('is-agotada');
    if (cuenta) clases.push('is-en-ticket');
    if (estado.abierta === p.id) clases.push('is-abierta');

    var precio = p.precio + (def ? def.delta : 0);
    var nBase = g ? null : disponibles(piezaBase);
    // El disco «×2» de la esquina es aria-hidden: lo que lleva la venta se
    // dice aquí, para que el lector no pierda lo que la vista sí muestra.
    var principalEtiqueta = p.n + (def ? ', ' + def.n : '') + ', ' + U.dinero(precio) +
      (nBase === null ? '' : nBase > 0 ? ', ' + U.textoPiezas(nBase) + ' en vitrina' : ', agotado en vitrina') +
      (cuenta ? ', ' + cuenta + ' en la venta' : '');
    var html = '<div class="' + clases.join(' ') + '" role="listitem" data-tecla="' + p.id + '" style="--nivel:' + (nv ? nv.valor.toFixed(3) : 0) + '">';
    html += '<button type="button" class="caja-tecla__principal" data-agregar="' + p.id + '"' + (def ? ' data-opcion="' + def.id + '"' : '') +
      (compleja ? ' data-crecer aria-expanded="' + (estado.abierta === p.id) + '"' + (estado.abierta === p.id ? ' aria-controls="caja-ficha-' + p.id + '"' : '') : '') +
      (g && !def ? ' data-elegir' : '') +
      ' aria-label="' + U.esc(compleja ? p.n + ', elegir opciones' : (g && !def ? p.n + ', elige ' + g.n.toLowerCase() : 'Agregar ' + principalEtiqueta)) + '">';
    html += '<span class="caja-tecla__nombre">' + U.esc(p.n) + '</span>';
    html += '<span class="caja-tecla__linea"><span class="caja-tecla__precio">' + (compleja ? 'desde ' : '') + U.dinero(p.precio, true) + '</span>' + (g ? '' : cifraPiezas(disponibles(piezaBase))) + '</span>';
    if (nv) html += '<span class="caja-tecla__medidor" aria-hidden="true"></span>';
    html += '</button>';
    html += '<span class="caja-tecla__cuenta" aria-hidden="true">' + (cuenta ? '×' + cuenta : '') + '</span>';
    if (g) {
      html += '<div class="caja-gajos" role="group" aria-label="' + U.esc(g.n) + ' de ' + U.esc(p.n) + '" style="--gajos:' + g.o.length + '">';
      g.o.forEach(function (o) {
        var pz = piezaDe(p, [o]);
        var n = disponibles(pz);
        var nvo = nivel(pz);
        html += '<button type="button" class="caja-gajo' + (o.def ? ' is-def' : '') + (nvo && nvo.agotada ? ' is-agotada' : '') + (nvo && nvo.pocas ? ' is-pocas' : '') + '" data-agregar="' + p.id + '" data-opcion="' + o.id + '"' +
          ' aria-label="Agregar ' + U.esc(p.n + ', ' + o.n + ', ' + U.dinero(p.precio + o.delta) + (n !== null ? ', ' + U.textoPiezas(n) + ' en vitrina' : '')) + '">' +
          '<span class="caja-gajo__nombre">' + U.esc(o.n) + '</span>' +
          '<span class="caja-gajo__dato">' + (o.delta ? '<span class="caja-gajo__delta">' + (o.delta > 0 ? '+' : '−') + U.dinero(Math.abs(o.delta), true) + '</span>' : '') + cifraPiezas(n) + '</span>' +
          '</button>';
      });
      html += '</div>';
    }
    if (compleja && estado.abierta === p.id) html += fichaHtml(p);
    html += '</div>';
    return html;
  }

  // La ficha de una tecla extendida: grupos como muestras, cantidad y agregar.
  var ficha = { oferta: null, elegidas: [], cantidad: 1 };

  function fichaHtml(p) {
    var ops = resolver(p, ficha.elegidas);
    var precio = ops ? ops.reduce(function (s, o) { return s + o.delta; }, p.precio) : null;
    var pz = ops ? piezaDe(p, ops) : null;
    var n = disponibles(pz);
    var html = '<div class="caja-ficha" id="caja-ficha-' + p.id + '" data-ficha="' + p.id + '">';
    if (p.img) html += '<img class="caja-ficha__foto" src="' + U.esc(p.img) + '" alt="" loading="lazy" decoding="async">';
    html += '<div class="caja-ficha__cuerpo">';
    p.g.forEach(function (g, gi) {
      // Grupo por grupo, no con `ops`: si a OTRO grupo le falta elegir, ops es
      // null y aquí se perdía la marca de la predeterminada de éste.
      var sel = null;
      g.o.forEach(function (o) { if (ficha.elegidas.indexOf(o.id) !== -1) sel = o; });
      sel = sel || predeterminada(g);
      html += '<div class="caja-ficha__grupo" role="radiogroup" aria-label="' + U.esc(g.n) + '"><span class="caja-ficha__rotulo">' + U.esc(g.n) + (!predeterminada(g) ? ' · elige una' : '') + '</span><div class="caja-ficha__opciones">';
      g.o.forEach(function (o, oi) {
        var on = !!sel && sel.id === o.id;
        // Tabulación itinerante: sin nada marcado, la primera recibe el Tab;
        // con todas en -1 el grupo «elige una» era inalcanzable con teclado.
        var enTab = on || (!sel && oi === 0);
        var npz = o.pz ? disponibles(piezaDe(p, [o])) : null;
        html += '<button type="button" role="radio" class="caja-muestra' + (on ? ' is-on' : '') + '" aria-checked="' + on + '" tabindex="' + (enTab ? '0' : '-1') + '" data-muestra="' + o.id + '" data-grupo="' + gi + '">' +
          '<span class="caja-muestra__nombre">' + U.esc(o.n) + '</span>' +
          (o.delta ? '<span class="caja-muestra__delta">' + (o.delta > 0 ? '+' : '−') + U.dinero(Math.abs(o.delta), true) + '</span>' : '') +
          (npz !== null ? cifraPiezas(npz) : '') + '</button>';
      });
      html += '</div></div>';
    });
    html += '<div class="caja-ficha__acciones">' +
      '<div class="caja-paso" role="group" aria-label="Cantidad">' +
      '<button type="button" class="caja-paso__btn" data-ficha-cantidad="-1" aria-label="Una menos"' + (ficha.cantidad <= 1 ? ' disabled' : '') + '>' + U.icono('menos') + '</button>' +
      '<span class="caja-paso__n">' + ficha.cantidad + '</span>' +
      '<button type="button" class="caja-paso__btn" data-ficha-cantidad="1" aria-label="Una más">' + U.icono('mas') + '</button></div>' +
      '<button type="button" class="caja-ficha__agregar" data-ficha-agregar' + (ops ? '' : ' disabled') + '>' +
      (ops ? 'Agregar · ' + U.dinero(precio * ficha.cantidad) : 'Elige las opciones') + '</button>' +
      '<button type="button" class="caja-ficha__cerrar" data-ficha-cerrar aria-label="Cerrar opciones de ' + U.esc(p.n) + '">' + U.icono('cerrar') + '</button>' +
      '</div>';
    if (n !== null) html += '<p class="caja-ficha__vitrina">' + (n > 0 ? U.textoPiezas(n) + ' en vitrina con esta opción' : 'Agotado en vitrina: se puede vender igual y quedará el aviso') + '</p>';
    html += '</div></div>';
    return html;
  }

  function columnas() {
    return getComputedStyle(el.teclas).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
  }

  var columnasPintadas = 0;

  function pintarTeclas() {
    if (!el.teclas) return;
    var lista = visibles();
    columnasPintadas = columnas();
    // La tecla abierta ocupa una fila entera. Si es la primera de su fila,
    // crece ahí mismo y sus compañeras bajan. Si no, se coloca al final de
    // su fila: las de su izquierda y derecha cierran el hueco y todo lo de
    // abajo baja en línea recta. Así el orden del DOM (Tab, lectores,
    // flechas) es el orden que se ve; con `grid-auto-flow: dense` una vecina
    // subía al hueco y quedaba antes en pantalla que después en el teclado.
    if (estado.abierta !== null) {
      var i = -1;
      for (var k = 0; k < lista.length; k++) if (lista[k].id === estado.abierta) { i = k; break; }
      if (i > 0 && i % columnasPintadas !== 0) {
        var fin = Math.min(lista.length - 1, i - (i % columnasPintadas) + columnasPintadas - 1);
        if (fin > i) lista.splice(fin, 0, lista.splice(i, 1)[0]);
      }
    }
    el.teclas.innerHTML = lista.map(teclaHtml).join('');
    if (el.sinResultados) el.sinResultados.hidden = lista.length > 0;
  }

  /** Repinta toda la vitrina sin tirar el foco de teclado de la tecla en la que estaba. */
  function pintarTeclasConFoco() {
    if (!el.teclas) return;
    var activo = document.activeElement;
    var tecla = activo && el.teclas.contains(activo) ? activo.closest('[data-tecla]') : null;
    var sel = tecla ? selectorDe(activo) : null;
    var id = tecla ? tecla.getAttribute('data-tecla') : null;
    pintarTeclas();
    if (!id) return;
    var nueva = el.teclas.querySelector('[data-tecla="' + id + '"]');
    var f = nueva && (nueva.querySelector(sel) || nueva.querySelector('.caja-tecla__principal'));
    if (f) f.focus({ preventScroll: true });
  }

  /** Repinta una sola tecla (cuenta, vitrina) sin perder el foco de las demás. */
  function repintarTecla(id) {
    var actual = el.teclas && el.teclas.querySelector('[data-tecla="' + id + '"]');
    var p = estado.porId[id];
    if (!actual || !p) return;
    var foco = document.activeElement && actual.contains(document.activeElement) ? selectorDe(document.activeElement) : null;
    var tmp = document.createElement('div');
    tmp.innerHTML = teclaHtml(p);
    var nueva = tmp.firstChild;
    actual.replaceWith(nueva);
    if (foco) { var f = nueva.querySelector(foco); if (f) f.focus({ preventScroll: true }); }
  }

  function selectorDe(nodo) {
    if (nodo.hasAttribute('data-muestra')) return '[data-muestra="' + nodo.getAttribute('data-muestra') + '"]';
    if (nodo.hasAttribute('data-ficha-agregar')) return '[data-ficha-agregar]';
    if (nodo.hasAttribute('data-ficha-cantidad')) return '[data-ficha-cantidad="' + nodo.getAttribute('data-ficha-cantidad') + '"]';
    if (nodo.hasAttribute('data-opcion') && nodo.classList.contains('caja-gajo')) return '.caja-gajo[data-opcion="' + nodo.getAttribute('data-opcion') + '"]';
    return '.caja-tecla__principal';
  }

  // También con movimiento reducido: ahí el CSS cambia el salto por un
  // destello de fondo. Sin ninguna señal, tocar la tecla de un pan que pide
  // elegir gajo no hacía nada visible.
  function pulsar(nodo) {
    if (!nodo) return;
    nodo.classList.remove('is-pulso');
    void nodo.offsetWidth;
    nodo.classList.add('is-pulso');
  }

  // FLIP: la tecla crece en su sitio y las vecinas se apartan con suavidad.
  function conMovimiento(cambio) {
    if (U.reduce.matches || !el.teclas || !el.teclas.animate) { cambio(); return; }
    var antes = {};
    Array.prototype.forEach.call(el.teclas.children, function (t) { antes[t.getAttribute('data-tecla')] = t.getBoundingClientRect(); });
    cambio();
    Array.prototype.forEach.call(el.teclas.children, function (t) {
      var a = antes[t.getAttribute('data-tecla')];
      if (!a) return;
      var b = t.getBoundingClientRect();
      var dx = a.left - b.left, dy = a.top - b.top, sx = a.width / b.width, sy = a.height / b.height;
      if (Math.abs(dx) < 1 && Math.abs(dy) < 1 && Math.abs(sx - 1) < .01 && Math.abs(sy - 1) < .01) return;
      t.animate([
        { transformOrigin: 'top left', transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + sx + ',' + sy + ')' },
        { transformOrigin: 'top left', transform: 'none' }
      ], { duration: 280, easing: 'cubic-bezier(.16,1,.3,1)' });
    });
  }

  function abrirFicha(id) {
    var p = estado.porId[id];
    if (!p) return;
    ficha = { oferta: id, elegidas: [], cantidad: tomarMultiplicador() || 1 };
    conMovimiento(function () { estado.abierta = id; pintarTeclas(); });
    var t = el.teclas.querySelector('[data-tecla="' + id + '"]');
    var primero = t && (t.querySelector('.caja-muestra.is-on') || t.querySelector('.caja-muestra'));
    if (primero) primero.focus({ preventScroll: true });
    if (t) asomar(t);
  }

  /**
   * Desplaza la vitrina para que la tecla extendida se vea entera (o, si no
   * cabe, desde arriba). Medida con offsetTop y no con getBoundingClientRect:
   * el FLIP acaba de ponerle un transform y el rectángulo era el de ANTES de
   * crecer, así que el pie de la ficha quedaba cortado. En teléfono descuenta
   * la barra «Cobrar», que flota sobre la vitrina.
   */
  function asomar(t) {
    var tapa = el.barra && !el.barra.hidden && compacto.matches ? el.barra.offsetHeight + 24 : 0;
    var arriba = el.teclas.scrollTop;
    var alto = el.teclas.clientHeight - tapa;
    var top = t.offsetTop, bottom = top + t.offsetHeight;
    var destino = null;
    if (bottom > arriba + alto - 12) destino = Math.min(top - 12, bottom - alto + 12);
    else if (top < arriba + 12) destino = top - 12;
    if (destino !== null) el.teclas.scrollTo({ top: Math.max(0, destino), behavior: U.reduce.matches ? 'auto' : 'smooth' });
  }

  function cerrarFicha(devolverFoco) {
    if (estado.abierta === null) return;
    var id = estado.abierta;
    conMovimiento(function () { estado.abierta = null; pintarTeclas(); });
    if (devolverFoco) { var b = el.teclas.querySelector('[data-tecla="' + id + '"] .caja-tecla__principal'); if (b) b.focus({ preventScroll: true }); }
  }

  // ── Ticket ────────────────────────────────────────────────────

  function claveLinea(oferta, ids) { return oferta + ':' + ids.slice().sort(function (a, b) { return a - b; }).join(','); }

  /** Suma `cantidad` piezas de un pan con sus opciones. Devuelve la línea. */
  function agregar(ofertaId, elegidas, cantidad) {
    var p = estado.porId[ofertaId];
    if (!p) return null;
    var ops = resolver(p, elegidas || []);
    if (!ops) return null;
    salirDeVendido();
    cantidad = Math.max(1, Math.min(255, cantidad || 1));
    var ids = ops.map(function (o) { return o.id; });
    var clave = claveLinea(p.id, ids);
    var linea = null;
    for (var i = 0; i < estado.lineas.length; i++) if (estado.lineas[i].clave === clave) linea = estado.lineas[i];
    if (linea) {
      linea.cantidad = Math.min(255, linea.cantidad + cantidad);
      estado.lineas.splice(estado.lineas.indexOf(linea), 1);
      estado.lineas.push(linea); // la última tocada va abajo, junto al total
    } else {
      linea = {
        clave: clave, oferta: p.id, ids: ids, nombre: p.n,
        etiqueta: ops.filter(function (o) { return !o.def; }).map(function (o) { return o.n; }).join(' · '),
        precio: ops.reduce(function (s, o) { return s + o.delta; }, p.precio),
        cantidad: cantidad, nueva: true
      };
      estado.lineas.push(linea);
    }
    linea.tocada = true;
    U.vibrar(6);
    cambioTicket([p.id]);
    anunciarLinea(linea);
    return linea;
  }

  function anunciarLinea(linea) {
    anunciar(linea.nombre + (linea.etiqueta ? ', ' + linea.etiqueta : '') + ', ' + textoPiezasVenta(linea.cantidad) + '. Total ' + U.dinero(totalCentavos()));
  }

  function cambiarCantidad(clave, delta) {
    var linea = buscarLinea(clave);
    if (!linea) return;
    var nueva = linea.cantidad + delta;
    if (nueva <= 0) { quitar(clave); return; }
    linea.cantidad = Math.min(255, nueva);
    linea.tocada = true;
    cambioTicket([linea.oferta]);
    anunciarLinea(linea);
  }

  function quitar(clave) {
    var linea = buscarLinea(clave);
    if (!linea) return;
    var texto = 'Quitaste ' + linea.cantidad + ' × ' + linea.nombre + (linea.etiqueta ? ' (' + linea.etiqueta + ')' : '');
    guardarDeshacer(texto);
    estado.lineas.splice(estado.lineas.indexOf(linea), 1);
    cambioTicket([linea.oferta]);
    anunciar(texto + '. Total ' + U.dinero(totalCentavos()) + '. Puedes deshacer.');
  }

  function vaciar() {
    if (!estado.lineas.length) return;
    var ofertas = estado.lineas.map(function (l) { return l.oferta; });
    var texto = 'Vaciaste la venta (' + textoPiezasVenta(totalPiezas()) + ')';
    guardarDeshacer(texto);
    estado.lineas = [];
    cambioTicket(ofertas);
    anunciar(texto + '. Puedes deshacer.');
  }

  function buscarLinea(clave) {
    for (var i = 0; i < estado.lineas.length; i++) if (estado.lineas[i].clave === clave) return estado.lineas[i];
    return null;
  }

  function guardarDeshacer(texto) {
    if (estado.deshacer && estado.deshacer.timer) clearTimeout(estado.deshacer.timer);
    estado.deshacer = {
      lineas: estado.lineas.map(function (l) { return Object.assign({}, l, { nueva: false, tocada: false }); }),
      texto: texto,
      timer: setTimeout(function () { estado.deshacer = null; pintarTicket(); }, 6000)
    };
  }

  function deshacer() {
    if (!estado.deshacer) return;
    var antes = estado.lineas.map(function (l) { return l.oferta; });
    estado.lineas = estado.deshacer.lineas;
    clearTimeout(estado.deshacer.timer);
    estado.deshacer = null;
    cambioTicket(antes.concat(estado.lineas.map(function (l) { return l.oferta; })));
    anunciar('Venta restaurada: ' + textoPiezasVenta(totalPiezas()) + '. Total ' + U.dinero(totalCentavos()));
    // El botón Deshacer acaba de desaparecer: el foco no se queda en el vacío.
    var f = el.lineas.querySelector('.caja-linea:last-child [data-linea-cantidad="1"]');
    if (f) f.focus({ preventScroll: true });
  }

  function totalCentavos() { return estado.lineas.reduce(function (s, l) { return s + l.precio * l.cantidad; }, 0); }
  function totalPiezas() { return estado.lineas.reduce(function (s, l) { return s + l.cantidad; }, 0); }
  function textoPiezasVenta(n) { return n === 1 ? '1 pieza' : n + ' piezas'; }

  function cambioTicket(ofertas) {
    if (estado.pago && !estado.pago.enviando) cerrarPago(false);
    pintarTicket();
    var vistos = {};
    (ofertas || []).forEach(function (id) { if (!vistos[id]) { vistos[id] = 1; repintarTecla(id); } });
    guardarBorrador();
  }

  function lineaHtml(l) {
    var clases = 'caja-linea' + (l.nueva ? ' is-nueva' : '') + (l.tocada && !l.nueva ? ' is-tocada' : '');
    return '<li class="' + clases + '" data-linea="' + U.esc(l.clave) + '">' +
      '<div class="caja-linea__info"><span class="caja-linea__nombre">' + U.esc(l.nombre) + '</span>' +
      (l.etiqueta ? '<span class="caja-linea__variante">' + U.esc(l.etiqueta) + '</span>' : '') +
      '<span class="caja-linea__unitario">' + U.dinero(l.precio, true) + ' c/u</span></div>' +
      '<div class="caja-paso caja-paso--linea" role="group" aria-label="Cantidad de ' + U.esc(l.nombre) + '">' +
      '<button type="button" class="caja-paso__btn" data-linea-cantidad="-1" aria-label="' + (l.cantidad === 1 ? 'Quitar ' : 'Una menos de ') + U.esc(l.nombre) + '">' + U.icono(l.cantidad === 1 ? 'basura' : 'menos') + '</button>' +
      '<span class="caja-paso__n">' + l.cantidad + '</span>' +
      '<button type="button" class="caja-paso__btn" data-linea-cantidad="1" aria-label="Una más de ' + U.esc(l.nombre) + '">' + U.icono('mas') + '</button>' +
      '</div>' +
      '<span class="caja-linea__importe">' + U.dinero(l.precio * l.cantidad) + '</span>' +
      '</li>';
  }

  function pintarTicket() {
    if (!el.lineas) return;
    var hay = estado.lineas.length > 0;
    el.lineas.innerHTML = estado.lineas.map(lineaHtml).join('');
    estado.lineas.forEach(function (l) { l.nueva = false; l.tocada = false; });
    // Al fondo, junto al total. Sin scrollIntoView: también desplaza el documento.
    el.lineas.scrollTop = el.lineas.scrollHeight;
    el.vacio.hidden = hay || !!estado.vendido;
    el.vaciar.hidden = !hay;
    var total = totalCentavos();
    var piezas = totalPiezas();
    el.piezas.textContent = textoPiezasVenta(piezas);
    el.total.textContent = U.dinero(total);
    raiz.querySelectorAll('[data-caja-cobrar]').forEach(function (b) { b.disabled = !hay; });
    if (el.barra) {
      el.barra.hidden = !hay;
      el.barraPiezas.textContent = textoPiezasVenta(piezas);
      el.barraTotal.textContent = U.dinero(total);
    }
    raiz.classList.toggle('tiene-venta', hay);
    pintarDeshacer();
    pintarVendido();
  }

  function pintarDeshacer() {
    var tira = el.ticket.querySelector('[data-caja-deshacer]');
    if (!estado.deshacer) { if (tira) tira.remove(); return; }
    if (!tira) {
      // Sin role=status: lo que quitaste ya lo dice el portavoz, y aquí
      // sonaba dos veces.
      tira = document.createElement('div');
      tira.className = 'caja-deshacer';
      tira.setAttribute('data-caja-deshacer', '');
      el.pie.parentNode.insertBefore(tira, el.pie);
    }
    tira.innerHTML = '<span class="caja-deshacer__texto">' + U.esc(estado.deshacer.texto) + '</span><button type="button" class="caja-deshacer__btn" data-deshacer>Deshacer</button>';
  }

  // Deslizar una línea a la izquierda la quita (con Deshacer a la vista).
  var arrastre = null;
  function iniciarArrastre(e) {
    var li = e.target.closest('.caja-linea');
    if (!li || e.target.closest('button') || e.pointerType === 'mouse') return;
    arrastre = { li: li, x: e.clientX, y: e.clientY, dx: 0, activo: false, id: e.pointerId };
  }
  function moverArrastre(e) {
    if (!arrastre || e.pointerId !== arrastre.id) return;
    var dx = e.clientX - arrastre.x, dy = e.clientY - arrastre.y;
    if (!arrastre.activo) {
      if (Math.abs(dy) > 10) { arrastre = null; return; }
      if (dx < -12) { arrastre.activo = true; arrastre.li.classList.add('is-arrastre'); try { arrastre.li.setPointerCapture(e.pointerId); } catch (x) { /* sin captura */ } }
      else return;
    }
    arrastre.dx = Math.min(0, dx);
    arrastre.li.style.setProperty('--arrastre', arrastre.dx + 'px');
  }
  function soltarArrastre() {
    if (!arrastre) return;
    var a = arrastre;
    arrastre = null;
    if (!a.activo) return;
    a.li.classList.remove('is-arrastre');
    if (a.dx < -Math.min(110, a.li.offsetWidth * 0.35)) {
      a.li.classList.add('is-saliendo');
      setTimeout(function () { quitar(a.li.getAttribute('data-linea')); }, U.reduce.matches ? 0 : 160);
    } else {
      a.li.style.removeProperty('--arrastre');
    }
  }

  // ── Multiplicador de teclado y atajos de presión larga ────────

  var chipMultiplicador = null;
  function pintarMultiplicador() {
    if (!chipMultiplicador) {
      chipMultiplicador = document.createElement('div');
      chipMultiplicador.className = 'caja-multiplicador';
      chipMultiplicador.setAttribute('role', 'status');
      raiz.appendChild(chipMultiplicador);
    }
    chipMultiplicador.hidden = !estado.multiplicador;
    chipMultiplicador.innerHTML = estado.multiplicador ? '<span class="caja-multiplicador__x">×</span>' + U.esc(estado.multiplicador) + '<span class="caja-multiplicador__ayuda">Toca un pan · Esc cancela</span>' : '';
  }
  function tomarMultiplicador() {
    var n = parseInt(estado.multiplicador, 10);
    estado.multiplicador = '';
    pintarMultiplicador();
    return n > 0 ? Math.min(255, n) : 0;
  }

  var presion = null;
  var atajos = null;

  function abrirAtajos(boton) {
    cerrarAtajos();
    var ofertaId = parseInt(boton.getAttribute('data-agregar'), 10);
    var opcion = boton.getAttribute('data-opcion');
    var p = estado.porId[ofertaId];
    if (!p) return;
    var r = boton.getBoundingClientRect();
    atajos = document.createElement('div');
    atajos.className = 'caja-atajos';
    atajos.setAttribute('role', 'menu');
    atajos.setAttribute('aria-label', 'Cuántas piezas de ' + p.n);
    atajos.innerHTML = '<span class="caja-atajos__rotulo">' + U.esc(p.n) + '</span>' + ATAJOS.map(function (n) {
      return '<button type="button" role="menuitem" class="caja-atajos__btn" data-atajo="' + n + '">' + n + '</button>';
    }).join('');
    atajos.dataset.oferta = String(ofertaId);
    atajos.dataset.opcion = opcion || '';
    document.body.appendChild(atajos);
    var w = atajos.offsetWidth, h = atajos.offsetHeight;
    var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left + r.width / 2 - w / 2));
    var top = r.top - h - 10;
    if (top < 72) top = r.bottom + 10;
    atajos.style.left = left + 'px';
    atajos.style.top = top + 'px';
    requestAnimationFrame(function () { if (atajos) atajos.classList.add('is-abierto'); });
    atajos.querySelector('button').focus({ preventScroll: true });
  }
  function cerrarAtajos() {
    if (atajos) { atajos.remove(); atajos = null; }
  }

  // ── Cobro ─────────────────────────────────────────────────────

  function sugerenciasBilletes(total) {
    var pesos = Math.ceil(total / 100);
    var lista = [];
    [10, 20, 50, 100, 200, 500, 1000].forEach(function (m) {
      var v = Math.ceil(pesos / m) * m;
      if (v * 100 > total && lista.indexOf(v) === -1) lista.push(v);
    });
    return lista.filter(function (v) { return v <= 1000 || v === lista[0]; }).slice(0, 4);
  }

  function abrirPago(metodo) {
    if (!estado.lineas.length || (estado.pago && estado.pago.enviando)) return;
    cerrarFicha(false);
    cerrarAtajos();
    if (compacto.matches) abrirTicketHoja();
    estado.pago = { metodo: metodo, recibido: metodo === 'tarjeta' ? totalCentavos() : 0, tecleado: '', teclado: false, clave: U.clave('venta'), enviando: false, error: '' };
    pintarPago();
    var foco = el.pago.querySelector(metodo === 'tarjeta' ? '[data-pago-confirmar]' : '[data-billete]');
    if (foco) foco.focus({ preventScroll: true });
  }

  // Mientras se cobra, el cobro TAPA el ticket: lo de debajo queda inerte para
  // que Tab no se pierda en botones −/+ que no se ven.
  function taparTicket(tapar) {
    Array.prototype.forEach.call(el.ticket.children, function (hijo) {
      if (hijo === el.pago) return;
      hijo.inert = !!tapar;
    });
  }

  function cerrarPago(devolverFoco) {
    if (!estado.pago || estado.pago.enviando) return;
    var metodo = estado.pago.metodo;
    estado.pago = null;
    el.pago.hidden = true;
    el.pago.innerHTML = '';
    taparTicket(false);
    raiz.classList.remove('esta-cobrando');
    if (devolverFoco) { var b = raiz.querySelector('[data-caja-cobrar="' + metodo + '"]'); if (b) b.focus(); }
  }

  function pintarPago() {
    var pg = estado.pago;
    if (!pg) return;
    var total = totalCentavos();
    var falta = pg.metodo === 'efectivo' && pg.recibido < total;
    var cambio = pg.metodo === 'efectivo' ? Math.max(0, pg.recibido - total) : 0;
    // Cada tecla repinta el cobro: conservar el desplazamiento del cuerpo
    // evita que el teclado salte arriba a cada cifra.
    var cuerpoAntes = el.pago.querySelector('.caja-pago__cuerpo');
    var desplazado = cuerpoAntes ? cuerpoAntes.scrollTop : 0;
    var html = '<header class="caja-pago__cabeza"><h3 class="caja-pago__titulo" id="caja-pago-titulo">' + (pg.metodo === 'efectivo' ? 'Efectivo' : 'Tarjeta') + '</h3>' +
      '<button type="button" class="caja-pago__cerrar" data-pago-cerrar aria-label="Volver al ticket"' + (pg.enviando ? ' disabled' : '') + '>' + U.icono('cerrar') + '</button></header>';
    // Cuerpo: lo que se elige (billetes, teclado). Desplaza si no cabe.
    html += '<div class="caja-pago__cuerpo">';
    html += '<div class="caja-pago__total"><span class="caja-pago__rotulo">Total · ' + textoPiezasVenta(totalPiezas()) + '</span><span class="caja-pago__cifra">' + U.dinero(total) + '</span></div>';
    if (pg.metodo === 'efectivo') {
      html += '<div class="caja-pago__recibido"><span class="caja-pago__rotulo">Recibido</span><span class="caja-pago__monto' + (pg.recibido ? '' : ' is-vacio') + '">' + (pg.recibido ? U.dinero(pg.recibido) : 'Toca un billete') + '</span></div>';
      html += '<div class="caja-billetes" role="group" aria-label="Billetes rápidos">' +
        '<button type="button" class="caja-billete' + (pg.recibido === total ? ' is-on' : '') + '" data-billete="' + total + '" aria-pressed="' + (pg.recibido === total) + '">Exacto</button>' +
        sugerenciasBilletes(total).map(function (v) {
          var on = pg.recibido === v * 100;
          return '<button type="button" class="caja-billete' + (on ? ' is-on' : '') + '" data-billete="' + (v * 100) + '" aria-pressed="' + on + '">' + U.dinero(v * 100, true) + '</button>';
        }).join('') + '</div>';
      // El teclado en pantalla es para el billete raro: plegado deja a la vista
      // el cambio y el botón de cobrar. Con teclado físico se escribe igual.
      html += pg.teclado ? '' : '<button type="button" class="caja-pago__otro" data-pago-teclado aria-expanded="false">Otro monto</button>';
      if (pg.teclado) html += '<div class="caja-teclado" role="group" aria-label="Otro monto">' +
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '00', '0', 'borrar'].map(function (t) {
          return t === 'borrar'
            ? '<button type="button" class="caja-teclado__tecla caja-teclado__tecla--borrar" data-teclado="borrar" aria-label="Borrar">' + U.icono('cerrar') + '</button>'
            : '<button type="button" class="caja-teclado__tecla" data-teclado="' + t + '">' + t + '</button>';
        }).join('') + '</div>';
    } else {
      html += '<p class="caja-pago__instruccion">Cobra <strong>' + U.dinero(total) + '</strong> en la terminal y confirma cuando pase la tarjeta.</p>';
    }
    html += '</div>';
    // Pie: el cambio y el cobro, fijos. Es lo que se lee y se toca al final.
    html += '<div class="caja-pago__pie">';
    if (pg.metodo === 'efectivo') {
      html += '<div class="caja-pago__cambio' + (pg.recibido && !falta ? ' is-listo' : '') + '"><span class="caja-pago__rotulo">' + (falta && pg.recibido ? 'Faltan' : 'Cambio') + '</span><span class="caja-pago__cifra-cambio">' +
        (pg.recibido ? U.dinero(falta ? total - pg.recibido : cambio) : '—') + '</span></div>';
    }
    if (pg.error) html += '<p class="caja-pago__error" role="alert">' + U.icono('alerta') + '<span>' + U.esc(pg.error) + '</span></p>';
    var listo = pg.metodo === 'tarjeta' || (pg.recibido && !falta);
    html += '<button type="button" class="caja-pago__confirmar" data-pago-confirmar' + (!listo || pg.enviando ? ' disabled' : '') + '>' +
      (pg.enviando ? '<span class="caja-giro" aria-hidden="true"></span>Cobrando…' :
        pg.metodo === 'tarjeta' ? 'Ya pasó la tarjeta' :
        (cambio ? 'Cobrar y dar ' + U.dinero(cambio) : 'Cobrar ' + U.dinero(total))) +
      '<kbd class="caja-kbd" aria-hidden="true">Enter</kbd></button>';
    html += '</div>';
    el.pago.innerHTML = html;
    el.pago.hidden = false;
    var cuerpo = el.pago.querySelector('.caja-pago__cuerpo');
    if (cuerpo) cuerpo.scrollTop = desplazado;
    taparTicket(true);
    raiz.classList.add('esta-cobrando');
  }

  /** Lo recibido y su consecuencia, en palabras, tras tocar un billete o teclear. */
  function anunciarRecibido() {
    var pg = estado.pago;
    if (!pg || pg.metodo !== 'efectivo') return;
    var total = totalCentavos();
    if (!pg.recibido) { anunciar('Recibido: nada'); return; }
    anunciar('Recibido ' + U.dinero(pg.recibido) + '. ' + (pg.recibido < total ? 'Faltan ' + U.dinero(total - pg.recibido) : 'Cambio ' + U.dinero(pg.recibido - total)));
  }

  /** Abre el teclado de «Otro monto» y lo desplaza a la vista dentro del cobro. */
  function abrirTecladoMonto() {
    if (!estado.pago) return;
    estado.pago.teclado = true;
    pintarPago();
    var cuerpo = el.pago.querySelector('.caja-pago__cuerpo');
    if (cuerpo) cuerpo.scrollTop = cuerpo.scrollHeight;
    var t1 = el.pago.querySelector('[data-teclado="1"]');
    if (t1) t1.focus({ preventScroll: true });
  }

  function teclearMonto(t) {
    var pg = estado.pago;
    if (!pg || pg.metodo !== 'efectivo' || pg.enviando) return;
    var abria = !pg.teclado;
    pg.teclado = true;
    if (t === 'borrar') pg.tecleado = pg.tecleado.slice(0, -1);
    else if ((pg.tecleado + t).length <= 6) pg.tecleado = (pg.tecleado + t).replace(/^0+(?=\d)/, '');
    pg.recibido = (parseInt(pg.tecleado || '0', 10) || 0) * 100;
    pg.error = '';
    pintarPago();
    if (abria) { var cuerpo = el.pago.querySelector('.caja-pago__cuerpo'); if (cuerpo) cuerpo.scrollTop = cuerpo.scrollHeight; }
    var foco = el.pago.querySelector('[data-teclado="' + t + '"]');
    if (foco) foco.focus({ preventScroll: true });
    anunciarRecibido();
  }

  function confirmarPago() {
    var pg = estado.pago;
    if (!pg || pg.enviando) return;
    var total = totalCentavos();
    if (pg.metodo === 'efectivo' && pg.recibido < total) return;
    pg.enviando = true;
    pg.error = '';
    pintarPago();
    var cuerpo = {
      clave: pg.clave,
      items: estado.lineas.map(function (l) { return { oferta_id: l.oferta, cantidad: l.cantidad, variantes: l.ids }; }),
      pago: pg.metodo === 'efectivo' ? { metodo: 'efectivo', recibido: U.decimal(pg.recibido) } : { metodo: 'tarjeta' }
    };
    U.api('POST', BASE + '/vender', cuerpo).then(function (r) {
      pg.enviando = false;
      if (r.ok) { ventaConfirmada(r.datos); return; }
      var d = r.datos || {};
      if (r.red) pg.error = 'Sin conexión con la caja. Vuelve a intentar: no se cobrará dos veces.';
      else if (r.status === 419) pg.error = 'La sesión venció. Recarga la pantalla; la venta sigue aquí.';
      else pg.error = d.mensaje || 'No se pudo cobrar.';
      // Un cambio de carta o de opciones invalida la venta tal como está:
      // se refresca la vitrina y la clave se renueva para el siguiente intento.
      if (['OFERTA_NO_DISPONIBLE', 'VARIANTE_INVALIDA', 'PRECIO_INVALIDO'].indexOf(d.codigo) !== -1) { pg.clave = U.clave('venta'); recargarEstado(); }
      if (d.codigo === 'CONFLICTO_IDEMPOTENCIA') pg.clave = U.clave('venta');
      pintarPago();
    });
  }

  function ventaConfirmada(d) {
    var ofertas = estado.lineas.map(function (l) { return l.oferta; });
    estado.vendido = d.ticket || {};
    estado.lineas = [];
    estado.pago = null;
    el.pago.hidden = true;
    el.pago.innerHTML = '';
    taparTicket(false);
    raiz.classList.remove('esta-cobrando');
    if (estado.deshacer) { clearTimeout(estado.deshacer.timer); estado.deshacer = null; }
    cargarVitrina(d.vitrina, true);
    cambioTicket(ofertas);
    var v = estado.vendido;
    anunciar('Cobrado. Ticket ' + (v.id || '') + '. ' + (v.metodo === 'efectivo' ? 'Cambio ' + U.dinero(U.centavos(v.cambio)) : 'Con tarjeta') + '.');
    (d.avisos || []).forEach(function (a) {
      if (a.codigo === 'VITRINA_NEGATIVA' && window.AppNotice) {
        window.AppNotice.warning(a.pieza + ' quedó en ' + U.textoPiezas(U.piezas(a.saldo)) + ' en la vitrina. Registra la horneada o avisa para ajustar el conteo.', { timeout: 9000 });
      }
    });
    actualizarPulso();
    var nueva = el.ticket.querySelector('[data-vendido-nueva]');
    if (nueva) nueva.focus({ preventScroll: true });
  }

  function pintarVendido() {
    var tarjeta = el.ticket.querySelector('[data-caja-vendido]');
    if (!estado.vendido) { if (tarjeta) tarjeta.remove(); raiz.classList.remove('esta-vendido'); return; }
    var v = estado.vendido;
    if (!tarjeta) {
      // Sin role=status: una región viva que nace ya llena no se anuncia
      // de forma fiable. «Cobrado» lo dice el portavoz (ventaConfirmada).
      tarjeta = document.createElement('div');
      tarjeta.className = 'caja-vendido';
      tarjeta.setAttribute('data-caja-vendido', '');
      el.lineas.parentNode.insertBefore(tarjeta, el.lineas);
    }
    var cambio = U.centavos(v.cambio);
    tarjeta.innerHTML =
      '<span class="caja-vendido__marca" aria-hidden="true">' + U.icono('check') + '</span>' +
      '<p class="caja-vendido__titulo">Cobrado</p>' +
      '<p class="caja-vendido__dato">Ticket ' + U.esc(v.id) + ' · ' + U.esc(v.hora || '') + ' · ' + (v.metodo === 'tarjeta' ? 'Tarjeta' : 'Efectivo') + ' · ' + U.dinero(U.centavos(v.total)) + '</p>' +
      (v.metodo === 'efectivo' ? '<p class="caja-vendido__cambio"><span class="caja-vendido__rotulo">Cambio</span><span class="caja-vendido__cifra">' + U.dinero(cambio) + '</span></p>' : '') +
      '<div class="caja-vendido__acciones">' +
      (estado.impresora ? '<button type="button" class="caja-vendido__btn" data-imprimir="' + U.esc(v.id) + '">' + U.icono('imprimir') + '<span>Imprimir ticket</span></button>' : '') +
      '<button type="button" class="caja-vendido__btn caja-vendido__btn--nueva" data-vendido-nueva>Nueva venta<kbd class="caja-kbd" aria-hidden="true">Enter</kbd></button>' +
      '</div>';
    raiz.classList.add('esta-vendido');
  }

  function salirDeVendido() {
    if (!estado.vendido) return;
    estado.vendido = null;
    pintarVendido();
  }

  function imprimir(ticketId, boton) {
    if (boton) { boton.disabled = true; boton.classList.add('is-enviando'); }
    U.api('POST', BASE + '/imprimir', { ticket_id: parseInt(ticketId, 10) }).then(function (r) {
      if (boton) { boton.disabled = false; boton.classList.remove('is-enviando'); }
      if (!window.AppNotice) return;
      if (r.ok) window.AppNotice.success('Ticket ' + ticketId + ' enviado a la impresora.');
      else window.AppNotice.error((r.datos && r.datos.mensaje) || 'No se pudo imprimir.');
    });
  }

  // ── Ticket en hoja (teléfono y tableta vertical) ──────────────

  // Abierta, la hoja tapa la vitrina: queda inerte para que Tab y el lector
  // no recorran teclas que están debajo del velo.
  function abrirTicketHoja() {
    if (!compacto.matches) return;
    raiz.classList.add('ticket-abierto');
    el.velo.hidden = false;
    el.velo.setAttribute('data-velo', 'ticket');
    if (el.vitrina) el.vitrina.inert = true;
    if (el.barra) el.barra.inert = true;
  }
  function cerrarTicketHoja() {
    if (estado.pago && estado.pago.enviando) return;
    cerrarPago(false);
    raiz.classList.remove('ticket-abierto');
    if (el.vitrina) el.vitrina.inert = false;
    if (el.barra) el.barra.inert = false;
    if (el.velo.getAttribute('data-velo') === 'ticket') { el.velo.hidden = true; el.velo.removeAttribute('data-velo'); }
  }

  // ── Hojas: horneada, tickets, corte, atajos ───────────────────

  var hojaAbierta = null;
  var focoPrevio = null;

  function abrirHoja(nombre, origen) {
    var hoja = document.querySelector('[data-caja-hoja="' + nombre + '"]');
    if (!hoja) return;
    cerrarHoja(false);
    cerrarTicketHoja();
    focoPrevio = origen || document.activeElement;
    hojaAbierta = hoja;
    hoja.hidden = false;
    el.velo.hidden = false;
    el.velo.setAttribute('data-velo', 'hoja');
    requestAnimationFrame(function () { hoja.classList.add('is-abierta'); });
    document.body.classList.add('caja-hoja-abierta');
    var pintor = { horneada: pintarHorneada, tickets: cargarTickets, corte: cargarCorte, atajos: pintarAtajosTeclado }[nombre];
    if (pintor) pintor(hoja);
    var cerrar = hoja.querySelector('[data-caja-cerrar-hoja]');
    if (cerrar) cerrar.focus({ preventScroll: true });
  }

  function cerrarHoja(devolverFoco) {
    if (!hojaAbierta) return;
    var hoja = hojaAbierta;
    hojaAbierta = null;
    hoja.classList.remove('is-abierta');
    setTimeout(function () { if (hojaAbierta !== hoja) hoja.hidden = true; }, U.reduce.matches ? 0 : 260);
    el.velo.hidden = true;
    el.velo.removeAttribute('data-velo');
    document.body.classList.remove('caja-hoja-abierta');
    if (devolverFoco !== false && focoPrevio && focoPrevio.focus) focoPrevio.focus({ preventScroll: true });
  }

  function cuerpoDe(hoja) { return hoja.querySelector('[data-caja-hoja-cuerpo]'); }
  function pieDe(hoja) { return hoja.querySelector('[data-caja-hoja-pie]'); }

  // Horneada: lo que sale del horno entra a la vitrina.
  var horno = { cantidades: {}, filtro: '', clave: null, enviando: false };

  function piezasVitrina() {
    return Object.keys(estado.vitrina).map(function (k) { return Object.assign({ clave: k }, estado.vitrina[k]); })
      .sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
  }

  function nombrePieza(n) { return String(n || '').replace(/\s·\s(pieza|rebanada)$/i, ''); }

  function pintarHorneada(hoja) {
    horno = { cantidades: {}, filtro: '', clave: U.clave('horneada'), enviando: false };
    var cuerpo = cuerpoDe(hoja);
    cuerpo.innerHTML = '<p class="caja-hoja__ayuda">Suma lo que salió del horno. Entra a la vitrina en cuanto lo registras; no descuenta insumos.</p>' +
      '<label class="caja-buscar caja-buscar--hoja"><span class="caja-buscar__icono" aria-hidden="true"></span><span class="operational-visually-hidden">Buscar pieza</span>' +
      '<input type="search" class="caja-buscar__campo" data-horno-buscar placeholder="Buscar pieza" autocomplete="off" spellcheck="false"></label>' +
      '<ul class="caja-horno" data-horno-lista></ul>';
    var icono = cuerpo.querySelector('.caja-buscar__icono');
    var plantilla = document.querySelector('.caja-vitrina__barra .caja-buscar__icono');
    if (icono && plantilla) icono.innerHTML = plantilla.innerHTML;
    pintarListaHorno(hoja);
    pintarPieHorno(hoja);
  }

  function pintarListaHorno(hoja) {
    var lista = hoja.querySelector('[data-horno-lista]');
    var q = normalizar(horno.filtro);
    var piezas = piezasVitrina().filter(function (p) { return !q || normalizar(p.nombre).indexOf(q) !== -1; });
    if (!piezas.length) { lista.innerHTML = '<li class="caja-vacio">' + (q ? 'Ninguna pieza con ese nombre.' : 'Aún no hay piezas de vitrina. Ligalas desde Menú.') + '</li>'; return; }
    lista.innerHTML = piezas.map(function (p) {
      var n = horno.cantidades[p.clave] || 0;
      var nombre = nombrePieza(p.nombre);
      return '<li class="caja-horno__fila' + (p.saldo <= p.minimo ? ' is-pocas' : '') + (n ? ' is-con' : '') + '" data-horno="' + U.esc(p.clave) + '">' +
        '<div class="caja-horno__info"><span class="caja-horno__nombre">' + U.esc(nombre) + '</span>' +
        '<span class="caja-horno__saldo">' + U.textoPiezas(p.saldo) + ' en vitrina' + (p.saldo <= p.minimo ? ' · pocas' : '') + '</span></div>' +
        '<div class="caja-horno__rapidos">' + [6, 12, 24].map(function (m) {
          return '<button type="button" class="caja-horno__rapido" data-horno-suma="' + m + '" aria-label="Sumar ' + m + ' de ' + U.esc(nombre) + '">+' + m + '</button>';
        }).join('') + '</div>' +
        '<div class="caja-paso" role="group" aria-label="Piezas de ' + U.esc(nombre) + '">' +
        '<button type="button" class="caja-paso__btn" data-horno-suma="-1" aria-label="Una menos"' + (n ? '' : ' disabled') + '>' + U.icono('menos') + '</button>' +
        '<input class="caja-paso__campo" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" value="' + (n || '') + '" placeholder="0" data-horno-campo aria-label="Piezas horneadas de ' + U.esc(nombre) + '">' +
        '<button type="button" class="caja-paso__btn" data-horno-suma="1" aria-label="Una más">' + U.icono('mas') + '</button></div>' +
        '</li>';
    }).join('');
  }

  function totalHorno() { return Object.keys(horno.cantidades).reduce(function (s, k) { return s + (horno.cantidades[k] || 0); }, 0); }

  function pintarPieHorno(hoja) {
    var pie = pieDe(hoja);
    var total = totalHorno();
    pie.hidden = false;
    var panes = Object.keys(horno.cantidades).filter(function (k) { return horno.cantidades[k]; }).length;
    pie.innerHTML = '<span class="caja-hoja__resumen">' + (total ? textoPiezasVenta(total) + ' de ' + panes + (panes === 1 ? ' pan' : ' panes') : 'Nada por registrar') + '</span>' +
      '<button type="button" class="caja-hoja__primario" data-horno-registrar' + (total && !horno.enviando ? '' : ' disabled') + '>' + (horno.enviando ? '<span class="caja-giro" aria-hidden="true"></span>Registrando…' : 'Registrar horneada') + '</button>';
  }

  function sumarHorno(hoja, claveP, delta, absoluto) {
    var actual = horno.cantidades[claveP] || 0;
    var nuevo = absoluto ? delta : actual + delta;
    horno.cantidades[claveP] = Math.max(0, Math.min(9999, nuevo || 0));
    var fila = hoja.querySelector('[data-horno="' + claveP + '"]');
    if (fila) {
      var campo = fila.querySelector('[data-horno-campo]');
      if (campo && document.activeElement !== campo) campo.value = horno.cantidades[claveP] || '';
      fila.classList.toggle('is-con', horno.cantidades[claveP] > 0);
      var menos = fila.querySelector('[data-horno-suma="-1"]');
      if (menos) menos.disabled = !horno.cantidades[claveP];
    }
    pintarPieHorno(hoja);
  }

  function registrarHorneada(hoja) {
    if (horno.enviando) return;
    var lineas = Object.keys(horno.cantidades).filter(function (k) { return horno.cantidades[k] > 0; }).map(function (k) {
      var v = estado.vitrina[k];
      return { almacen_id: v.almacen, articulo_id: v.articulo, cantidad: String(horno.cantidades[k]) };
    });
    if (!lineas.length) return;
    horno.enviando = true;
    pintarPieHorno(hoja);
    var total = totalHorno();
    U.api('POST', BASE + '/horneada', { clave: horno.clave, lineas: lineas }).then(function (r) {
      horno.enviando = false;
      if (r.ok) {
        cargarVitrina(r.datos.vitrina, true);
        pintarTeclas();
        cerrarHoja();
        if (window.AppNotice) window.AppNotice.success('Horneada registrada: ' + textoPiezasVenta(total) + ' a la vitrina.');
        return;
      }
      pintarPieHorno(hoja);
      if (window.AppNotice) window.AppNotice.error(r.red ? 'Sin conexión. Vuelve a intentar: no se sumará dos veces.' : ((r.datos && r.datos.mensaje) || 'No se pudo registrar la horneada.'));
    });
  }

  // Tickets de hoy: reimprimir y anular.
  var anulacion = null;

  function cargarTickets(hoja) {
    var cuerpo = cuerpoDe(hoja);
    pieDe(hoja).hidden = true;
    cuerpo.innerHTML = '<p class="caja-hoja__cargando"><span class="caja-giro" aria-hidden="true"></span>Cargando tickets…</p>';
    U.api('GET', BASE + '/tickets').then(function (r) {
      if (hojaAbierta !== hoja) return;
      if (!r.ok) { cuerpo.innerHTML = '<p class="caja-hoja__error" role="alert">' + U.esc((r.datos && r.datos.mensaje) || 'No se pudieron cargar los tickets.') + '</p>'; return; }
      var t = r.datos.tickets || [];
      if (!t.length) { cuerpo.innerHTML = '<p class="caja-vacio">Todavía no hay ventas hoy.</p>'; return; }
      cuerpo.innerHTML = '<ol class="caja-tks">' + t.map(ticketHtml).join('') + '</ol>';
    });
  }

  function ticketHtml(t) {
    var anulado = t.estado === 'anulado';
    var piezas = t.lineas.reduce(function (s, l) { return s + l.cantidad; }, 0);
    return '<li class="caja-tk' + (anulado ? ' is-anulado' : '') + '" data-tk="' + t.id + '">' +
      '<button type="button" class="caja-tk__fila" data-tk-abrir aria-expanded="false" aria-controls="caja-tk-' + t.id + '">' +
      '<span class="caja-tk__hora">' + U.esc(t.hora) + '</span>' +
      '<span class="caja-tk__folio">Ticket ' + t.id + '</span>' +
      '<span class="caja-tk__meta">' + textoPiezasVenta(piezas) + ' · ' + (t.metodo === 'tarjeta' ? 'Tarjeta' : 'Efectivo') + '</span>' +
      (anulado ? '<span class="caja-tk__estado">Anulado</span>' : '') +
      '<span class="caja-tk__total">' + U.dinero(U.centavos(t.total)) + '</span></button>' +
      '<div class="caja-tk__detalle" id="caja-tk-' + t.id + '" hidden>' +
      '<ul class="caja-tk__lineas">' + t.lineas.map(function (l) {
        return '<li><span>' + l.cantidad + ' × ' + U.esc(l.nombre) + (l.variante ? ' <em>' + U.esc(l.variante) + '</em>' : '') + '</span><span>' + U.dinero(U.centavos(l.importe)) + '</span></li>';
      }).join('') + '</ul>' +
      '<p class="caja-tk__quien">Cobró ' + U.esc(t.cajero || '—') + '</p>' +
      (anulado ? '<p class="caja-tk__anulacion">Anulado a las ' + U.esc(t.anulacion.hora) + ' · ' + U.esc(t.anulacion.motivo) + (t.anulacion.nota ? ' — ' + U.esc(t.anulacion.nota) : '') + ' · autorizó ' + U.esc(t.anulacion.autorizador) + '</p>' :
        '<div class="caja-tk__acciones">' +
        (estado.impresora ? '<button type="button" class="caja-tk__btn" data-imprimir="' + t.id + '">' + U.icono('imprimir') + '<span>Imprimir</span></button>' : '') +
        '<button type="button" class="caja-tk__btn caja-tk__btn--peligro" data-anular="' + t.id + '" data-total="' + U.esc(t.total) + '">Anular</button></div>') +
      '<div class="caja-anular" data-anular-form hidden></div>' +
      '</div></li>';
  }

  function abrirAnulacion(boton) {
    var detalle = boton.closest('.caja-tk__detalle');
    var form = detalle.querySelector('[data-anular-form]');
    anulacion = { ticket: parseInt(boton.getAttribute('data-anular'), 10), total: U.centavos(boton.getAttribute('data-total')), motivo: '', nota: '', nip: '', nipDicho: 0, clave: U.clave('anulacion'), enviando: false, error: '', form: form };
    boton.closest('.caja-tk__acciones').hidden = true;
    pintarAnulacion();
    var primero = form.querySelector('[data-motivo]');
    if (primero) primero.focus({ preventScroll: true });
  }

  function pintarAnulacion() {
    var a = anulacion;
    if (!a) return;
    var pideNip = !estado.permisos.anular;
    var listo = a.motivo && (a.motivo !== 'otro' || a.nota.trim()) && (!pideNip || a.nip.length === 4);
    var html = '<p class="caja-anular__titulo">Anular ticket ' + a.ticket + ' · ' + U.dinero(a.total) + '</p>' +
      '<p class="caja-anular__ayuda">Las piezas vuelven a la vitrina y la venta deja de contar en el corte. Devuelve el dinero al cliente.</p>' +
      '<div class="caja-anular__motivos" role="radiogroup" aria-label="Motivo">' + Object.keys(estado.motivos).map(function (k) {
        var on = a.motivo === k;
        return '<button type="button" role="radio" aria-checked="' + on + '" class="caja-muestra' + (on ? ' is-on' : '') + '" data-motivo="' + k + '">' + U.esc(estado.motivos[k]) + '</button>';
      }).join('') + '</div>' +
      '<label class="caja-anular__nota"><span class="caja-anular__rotulo">Nota' + (a.motivo === 'otro' ? ' (obligatoria)' : ' (opcional)') + '</span>' +
      '<input type="text" maxlength="160" data-anular-nota value="' + U.esc(a.nota) + '" autocomplete="off"></label>';
    if (pideNip) {
      html += '<div class="caja-nip"><span class="caja-anular__rotulo">Autoriza con su NIP quien puede anular</span>' +
        '<div class="caja-nip__puntos" aria-hidden="true">' + [0, 1, 2, 3].map(function (i) { return '<span class="caja-nip__punto' + (i < a.nip.length ? ' is-lleno' : '') + '"></span>'; }).join('') + '</div>' +
        '<div class="caja-teclado caja-teclado--nip">' + ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'borrar'].map(function (t) {
          if (!t) return '<span></span>';
          return t === 'borrar'
            ? '<button type="button" class="caja-teclado__tecla caja-teclado__tecla--borrar" data-nip="borrar" aria-label="Borrar">' + U.icono('cerrar') + '</button>'
            : '<button type="button" class="caja-teclado__tecla" data-nip="' + t + '">' + t + '</button>';
        }).join('') + '</div></div>';
    }
    if (a.error) html += '<p class="caja-pago__error" role="alert">' + U.icono('alerta') + '<span>' + U.esc(a.error) + '</span></p>';
    html += '<div class="caja-anular__acciones"><button type="button" class="caja-tk__btn" data-anular-cancelar>No anular</button>' +
      '<button type="button" class="caja-tk__btn caja-tk__btn--peligro-solido" data-anular-confirmar' + (listo && !a.enviando ? '' : ' disabled') + '>' +
      (a.enviando ? '<span class="caja-giro" aria-hidden="true"></span>Anulando…' : 'Anular ' + U.dinero(a.total)) + '</button></div>';
    a.form.innerHTML = html;
    a.form.hidden = false;
    // Los puntos del NIP son aria-hidden: cuántos van se dice en palabras,
    // sólo cuando cambia (no al elegir motivo ni al escribir la nota).
    if (pideNip && a.nip.length !== a.nipDicho) { a.nipDicho = a.nip.length; anunciar(a.nip.length + ' de 4 dígitos'); }
  }

  function confirmarAnulacion() {
    var a = anulacion;
    if (!a || a.enviando) return;
    a.enviando = true;
    a.error = '';
    pintarAnulacion();
    U.api('POST', BASE + '/anular', { ticket_id: a.ticket, motivo: a.motivo, nota: a.nota.trim() || null, clave: a.clave, nip: estado.permisos.anular ? null : a.nip }).then(function (r) {
      a.enviando = false;
      if (r.ok) {
        anulacion = null;
        cargarVitrina(r.datos.vitrina, true);
        pintarTeclas();
        actualizarPulso();
        if (window.AppNotice) window.AppNotice.success('Ticket ' + r.datos.ticket_id + ' anulado. Devuelve ' + U.dinero(U.centavos(r.datos.total)) + (r.datos.metodo === 'tarjeta' ? ' a la tarjeta.' : ' en efectivo.'));
        if (hojaAbierta) cargarTickets(hojaAbierta);
        return;
      }
      var d = r.datos || {};
      a.error = r.red ? 'Sin conexión. Vuelve a intentar: no se anulará dos veces.' : (d.mensaje || 'No se pudo anular.');
      if (d.codigo === 'AUTORIZACION_INVALIDA' || d.codigo === 'DEMASIADOS_INTENTOS') { a.nip = ''; a.clave = U.clave('anulacion'); }
      pintarAnulacion();
    });
  }

  // Corte de caja.
  var corte = { turno: null };

  function cargarCorte(hoja, turno) {
    var cuerpo = cuerpoDe(hoja);
    pieDe(hoja).hidden = true;
    cuerpo.innerHTML = '<p class="caja-hoja__cargando"><span class="caja-giro" aria-hidden="true"></span>Calculando el corte…</p>';
    var url = BASE + '/corte' + (turno !== undefined && turno !== null ? '?turno=' + encodeURIComponent(turno) : '');
    U.api('GET', url).then(function (r) {
      if (hojaAbierta !== hoja) return;
      if (!r.ok) { cuerpo.innerHTML = '<p class="caja-hoja__error" role="alert">' + U.esc((r.datos && r.datos.mensaje) || 'No se pudo calcular el corte.') + '</p>'; return; }
      cuerpo.innerHTML = corteHtml(r.datos);
    });
  }

  function corteHtml(c) {
    var res = c.resumen || {};
    var html = '';
    if ((c.turnos || []).length) {
      html += '<div class="caja-corte__turnos" role="group" aria-label="Ventana del corte">' + c.turnos.map(function (t, i) {
        var on = c.turno === i;
        return '<button type="button" class="caja-muestra' + (on ? ' is-on' : '') + '" aria-pressed="' + on + '" data-corte-turno="' + i + '">' + U.esc(t.nombre) + '</button>';
      }).join('') + '<button type="button" class="caja-muestra' + (c.turno === null ? ' is-on' : '') + '" aria-pressed="' + (c.turno === null) + '" data-corte-turno="dia">Día completo</button></div>';
    }
    var fecha = new Date(c.fecha + 'T12:00:00');
    html += '<p class="caja-corte__fecha">' + U.esc(fecha.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })) +
      (c.en_curso ? ' · <span class="caja-corte__vivo">en curso</span>' : '') + '</p>';
    html += '<div class="caja-corte__ventas"><span class="caja-corte__rotulo">Ventas</span><span class="caja-corte__cifra">' + U.dinero(Math.round((res.ventas || 0) * 100)) + '</span></div>';
    html += '<dl class="caja-corte__datos">' +
      dato('Tickets', String(res.tickets || 0)) +
      dato('Ticket promedio', U.dinero(Math.round((res.promedio || 0) * 100))) +
      dato('Piezas', String(c.piezas || 0)) +
      dato('Efectivo', U.dinero(Math.round(((c.metodos || {}).efectivo || 0) * 100))) +
      dato('Tarjeta', U.dinero(Math.round(((c.metodos || {}).tarjeta || 0) * 100))) +
      dato('Anulados', (c.anulaciones || {}).tickets ? c.anulaciones.tickets + ' · ' + U.dinero(U.centavos(c.anulaciones.total)) : 'Ninguno') +
      '</dl>';
    if ((c.top || []).length) {
      html += '<h3 class="caja-corte__subtitulo">Lo más vendido</h3><ol class="caja-corte__top">' + c.top.map(function (t) {
        return '<li><span class="caja-corte__top-nombre">' + U.esc(t.nombre) + '</span><span class="caja-corte__top-n">' + t.unidades + '</span><span class="caja-corte__top-importe">' + U.dinero(Math.round(t.importe * 100)) + '</span></li>';
      }).join('') + '</ol>';
    }
    html += '<p class="caja-hoja__ayuda">Consulta, no arqueo: las cifras salen de los tickets cobrados en la ventana.</p>';
    return html;
  }

  function dato(t, v) { return '<div class="caja-corte__dato"><dt>' + U.esc(t) + '</dt><dd>' + U.esc(v) + '</dd></div>'; }

  function pintarAtajosTeclado(hoja) {
    pieDe(hoja).hidden = true;
    var filas = [
      ['/', 'Buscar un pan (o empieza a escribir)'],
      ['← → ↑ ↓', 'Moverse entre teclas'],
      ['Enter', 'Agregar el pan con el foco'],
      ['2 … 99 y Enter', 'Agregar varias piezas de golpe'],
      ['Alt + 1 … 4', 'Agregar el gajo 1 a 4 de la tecla'],
      ['+ / −', 'Una pieza más o menos de la última línea'],
      ['Supr', 'Quitar la última línea (con Deshacer)'],
      ['F2 · F3', 'Cobrar en efectivo · con tarjeta'],
      ['Números y Enter', 'En efectivo: escribir lo recibido y cobrar'],
      ['Esc', 'Cerrar lo que esté abierto']
    ];
    cuerpoDe(hoja).innerHTML = '<dl class="caja-atajos-lista">' + filas.map(function (f) {
      return '<div><dt>' + f[0].split(' · ').map(function (k) { return '<kbd class="caja-kbd">' + U.esc(k) + '</kbd>'; }).join(' · ') + '</dt><dd>' + U.esc(f[1]) + '</dd></div>';
    }).join('') + '</dl>';
  }

  // ── Pulso del día y vitrina en vivo ───────────────────────────

  function actualizarPulso() {
    if (!el.pulso || !estado.permisos.vender) return;
    U.api('GET', BASE + '/corte?turno=dia').then(function (r) {
      if (!r.ok) return;
      var res = r.datos.resumen || {};
      var n = res.tickets || 0;
      el.pulso.textContent = n + (n === 1 ? ' venta' : ' ventas') + ' · ' + U.dinero(Math.round((res.ventas || 0) * 100), true);
    });
  }

  function refrescarVitrina() {
    if (document.hidden) return;
    U.api('GET', BASE + '/vitrina').then(function (r) {
      if (!r.ok) return;
      cargarVitrina(r.datos.vitrina, false);
      // Con foco: cada 45 s el repintado dejaba a quien usa teclado sin tecla.
      if (estado.abierta === null) pintarTeclasConFoco();
    });
  }

  function recargarEstado() {
    U.api('GET', BASE).then(function (r) {
      if (!r.ok) return;
      estado.permisos = r.datos.permisos || estado.permisos;
      estado.impresora = !!r.datos.impresora;
      cargarCatalogo(r.datos.catalogo);
      cargarVitrina(r.datos.vitrina, false);
      // Las líneas cuya oferta ya no se vende salen del ticket, con aviso.
      var antes = estado.lineas.length;
      estado.lineas = estado.lineas.filter(function (l) { return !!estado.porId[l.oferta]; });
      if (estado.lineas.length !== antes && window.AppNotice) window.AppNotice.warning('Un pan dejó de estar a la venta y salió del ticket.');
      pintarFamilias();
      pintarTeclasConFoco();
      pintarTicket();
    });
  }

  // ── Borrador: una recarga accidental no pierde la venta ───────

  var CLAVE_BORRADOR = 'cp-caja-borrador:' + (usuario.id || 0);
  function guardarBorrador() {
    try {
      if (estado.lineas.length) window.sessionStorage.setItem(CLAVE_BORRADOR, JSON.stringify(estado.lineas.map(function (l) { return { o: l.oferta, i: l.ids, c: l.cantidad }; })));
      else window.sessionStorage.removeItem(CLAVE_BORRADOR);
    } catch (e) { /* sin almacenamiento: la venta vive sólo en memoria */ }
  }
  function restaurarBorrador() {
    var lista = [];
    try { lista = JSON.parse(window.sessionStorage.getItem(CLAVE_BORRADOR) || '[]') || []; } catch (e) { lista = []; }
    lista.forEach(function (b) {
      if (!estado.porId[b.o]) return;
      var l = agregarSilencioso(b.o, b.i || [], b.c || 1);
      if (l) l.nueva = false;
    });
  }
  function agregarSilencioso(oferta, ids, cantidad) {
    var p = estado.porId[oferta];
    var ops = p && resolver(p, ids);
    if (!ops) return null;
    var l = {
      clave: claveLinea(p.id, ops.map(function (o) { return o.id; })), oferta: p.id, ids: ops.map(function (o) { return o.id; }), nombre: p.n,
      etiqueta: ops.filter(function (o) { return !o.def; }).map(function (o) { return o.n; }).join(' · '),
      precio: ops.reduce(function (s, o) { return s + o.delta; }, p.precio), cantidad: Math.min(255, cantidad)
    };
    estado.lineas.push(l);
    return l;
  }

  // ── Eventos ───────────────────────────────────────────────────

  function enCampo(t) { return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable); }

  if (el.familias) {
    el.familias.addEventListener('click', function (e) {
      var b = e.target.closest('[data-familia]');
      if (!b) return;
      estado.familia = b.getAttribute('data-familia');
      estado.busqueda = '';
      if (el.buscar) el.buscar.value = '';
      cerrarFicha(false);
      pintarFamilias();
      pintarTeclas();
      var activa = el.familias.querySelector('.is-activa');
      if (activa) activa.focus({ preventScroll: true });
    });
    el.familias.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var tabs = Array.prototype.slice.call(el.familias.querySelectorAll('[data-familia]'));
      var i = tabs.indexOf(document.activeElement);
      if (i === -1) return;
      e.preventDefault();
      var sig = tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
      sig.click();
    });
  }

  if (el.buscar) {
    el.buscar.addEventListener('input', function () {
      estado.busqueda = el.buscar.value;
      cerrarFicha(false);
      pintarTeclas();
    });
    el.buscar.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { el.buscar.value = ''; estado.busqueda = ''; pintarTeclas(); el.buscar.blur(); }
      if (e.key === 'Enter' || e.key === 'ArrowDown') {
        var primero = el.teclas.querySelector('.caja-tecla__principal');
        if (!primero) return;
        e.preventDefault();
        if (e.key === 'Enter' && visibles().length === 1) primero.click();
        else primero.focus();
      }
    });
  }

  if (el.teclas) {
    el.teclas.addEventListener('pointerdown', function (e) {
      var b = e.target.closest('[data-agregar]');
      if (!b || e.button > 0 || b.hasAttribute('data-crecer') || b.hasAttribute('data-elegir')) return;
      if (presion) clearTimeout(presion.timer);
      presion = { b: b, x: e.clientX, y: e.clientY, larga: false };
      presion.timer = setTimeout(function () { if (!presion) return; presion.larga = true; U.vibrar(12); abrirAtajos(b); }, PRESION_MS);
    });
    el.teclas.addEventListener('pointermove', function (e) {
      if (presion && !presion.larga && (Math.abs(e.clientX - presion.x) > 10 || Math.abs(e.clientY - presion.y) > 10)) { clearTimeout(presion.timer); presion = null; }
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (tipo) {
      el.teclas.addEventListener(tipo, function () { if (presion && !presion.larga) { clearTimeout(presion.timer); } });
    });
    el.teclas.addEventListener('contextmenu', function (e) { if (e.target.closest('[data-agregar]')) e.preventDefault(); });

    el.teclas.addEventListener('click', function (e) {
      var muestra = e.target.closest('[data-muestra]');
      if (muestra) { elegirMuestra(muestra); return; }
      if (e.target.closest('[data-ficha-cerrar]')) { cerrarFicha(true); return; }
      var paso = e.target.closest('[data-ficha-cantidad]');
      if (paso) {
        ficha.cantidad = Math.max(1, Math.min(255, ficha.cantidad + parseInt(paso.getAttribute('data-ficha-cantidad'), 10)));
        repintarTecla(ficha.oferta);
        anunciar('Cantidad: ' + ficha.cantidad);
        // En 1 el «−» se deshabilita y el foco se iría al body: pasa al «+».
        if (!el.teclas.contains(document.activeElement)) { var mas = el.teclas.querySelector('[data-ficha-cantidad="1"]'); if (mas) mas.focus({ preventScroll: true }); }
        return;
      }
      if (e.target.closest('[data-ficha-agregar]')) {
        var linea = agregar(ficha.oferta, ficha.elegidas, ficha.cantidad);
        if (linea) cerrarFicha(true);
        return;
      }
      var b = e.target.closest('[data-agregar]');
      if (!b) return;
      // El click que sigue a soltar una presión larga no suma, y tampoco debe
      // llegar al listener del documento: ése cierra los atajos al tocar
      // fuera, y el menú 2·3·6·12 desaparecía en cuanto se levantaba el dedo.
      if (presion && presion.larga) { presion = null; e.stopPropagation(); return; }
      presion = null;
      var id = parseInt(b.getAttribute('data-agregar'), 10);
      if (b.hasAttribute('data-crecer')) { if (estado.abierta === id) cerrarFicha(true); else abrirFicha(id); return; }
      if (b.hasAttribute('data-elegir')) {
        var gajos = b.parentNode.querySelector('.caja-gajos');
        pulsar(gajos);
        if (gajos && gajos.firstElementChild) gajos.firstElementChild.focus();
        return;
      }
      var opcion = b.getAttribute('data-opcion');
      var n = tomarMultiplicador() || 1;
      if (estado.abierta !== null) cerrarFicha(false);
      var tecla = b.closest('[data-tecla]');
      agregar(id, opcion ? [parseInt(opcion, 10)] : [], n);
      var nueva = el.teclas.querySelector('[data-tecla="' + id + '"]');
      pulsar(nueva && nueva.querySelector('.caja-tecla__cuenta'));
      if (tecla && !document.activeElement.closest('[data-tecla]')) {
        var foco = nueva && nueva.querySelector(opcion && b.classList.contains('caja-gajo') ? '.caja-gajo[data-opcion="' + opcion + '"]' : '.caja-tecla__principal');
        if (foco && e.detail === 0) foco.focus({ preventScroll: true });
      }
    });

    el.teclas.addEventListener('keydown', function (e) {
      var t = e.target;
      // Radios de la ficha: las cuatro flechas recorren el grupo, como en
      // un radiogroup nativo.
      if (t.hasAttribute && t.hasAttribute('data-muestra') && ['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].indexOf(e.key) !== -1) {
        var grupo = Array.prototype.slice.call(t.parentNode.querySelectorAll('[data-muestra]'));
        var i = grupo.indexOf(t);
        var adelante = e.key === 'ArrowRight' || e.key === 'ArrowDown';
        e.preventDefault();
        elegirMuestra(grupo[(i + (adelante ? 1 : -1) + grupo.length) % grupo.length]);
        return;
      }
      if (e.key === 'Escape' && estado.abierta !== null) { e.preventDefault(); cerrarFicha(true); return; }
      if (!t.classList || !(t.classList.contains('caja-tecla__principal') || t.classList.contains('caja-gajo'))) return;
      if (e.altKey && /^[1-4]$/.test(e.key)) {
        var gajo = t.closest('[data-tecla]').querySelectorAll('.caja-gajo')[parseInt(e.key, 10) - 1];
        if (gajo) { e.preventDefault(); gajo.click(); }
        return;
      }
      if (['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].indexOf(e.key) !== -1) {
        e.preventDefault();
        moverFoco(t.closest('[data-tecla]'), e.key);
      }
    });
  }

  function elegirMuestra(boton) {
    var p = estado.porId[ficha.oferta];
    if (!p) return;
    var gi = parseInt(boton.getAttribute('data-grupo'), 10);
    var id = parseInt(boton.getAttribute('data-muestra'), 10);
    var delGrupo = p.g[gi].o.map(function (o) { return o.id; });
    ficha.elegidas = ficha.elegidas.filter(function (x) { return delGrupo.indexOf(x) === -1; }).concat([id]);
    repintarTecla(ficha.oferta);
    var nuevo = el.teclas.querySelector('[data-muestra="' + id + '"]');
    if (nuevo) nuevo.focus({ preventScroll: true });
  }

  /**
   * Flechas en la rejilla. Izquierda/derecha siguen el orden (que ya es el
   * visual). Arriba/abajo van por geometría y no por «índice ± columnas»: la
   * tecla extendida ocupa una fila entera y deja hueco al final de la suya,
   * y la cuenta caía en la tecla equivocada. offset* y no getBoundingClientRect
   * para no medir a mitad de un FLIP.
   */
  function moverFoco(tecla, direccion) {
    var lista = Array.prototype.slice.call(el.teclas.querySelectorAll('[data-tecla]'));
    var i = lista.indexOf(tecla);
    if (i === -1) return;
    var destino = null;
    if (direccion === 'ArrowRight' || direccion === 'ArrowLeft') {
      destino = lista[i + (direccion === 'ArrowRight' ? 1 : -1)] || null;
    } else {
      var abajo = direccion === 'ArrowDown';
      // Desde la tecla extendida se sale por su columna de la izquierda,
      // que es donde está su botón.
      var x = tecla.classList.contains('is-abierta') ? tecla.offsetLeft + 1 : tecla.offsetLeft + tecla.offsetWidth / 2;
      var mejor = null;
      lista.forEach(function (t) {
        if (t === tecla) return;
        var dy = abajo ? t.offsetTop - (tecla.offsetTop + tecla.offsetHeight) : tecla.offsetTop - (t.offsetTop + t.offsetHeight);
        if (dy < -1) return;
        var dx = Math.max(0, Math.abs(t.offsetLeft + t.offsetWidth / 2 - x) - t.offsetWidth / 2);
        var puntos = dy * 1000 + dx; // primero la fila más cercana; luego la columna
        if (!mejor || puntos < mejor.p) mejor = { t: t, p: puntos };
      });
      destino = mejor ? mejor.t : null;
    }
    var b = destino && destino.querySelector('.caja-tecla__principal');
    if (b) b.focus();
  }

  if (el.lineas) {
    el.lineas.addEventListener('click', function (e) {
      var b = e.target.closest('[data-linea-cantidad]');
      if (!b) return;
      var li = b.closest('[data-linea]');
      var clave = li.getAttribute('data-linea');
      var delta = parseInt(b.getAttribute('data-linea-cantidad'), 10);
      cambiarCantidad(clave, delta);
      var mismo = el.lineas.querySelector('[data-linea="' + CSS.escape(clave) + '"] [data-linea-cantidad="' + delta + '"]');
      if (mismo) mismo.focus({ preventScroll: true });
      else { var otro = el.lineas.querySelector('[data-linea-cantidad="1"]'); if (otro) otro.focus({ preventScroll: true }); }
    });
    el.lineas.addEventListener('pointerdown', iniciarArrastre);
    el.lineas.addEventListener('pointermove', moverArrastre);
    el.lineas.addEventListener('pointerup', soltarArrastre);
    el.lineas.addEventListener('pointercancel', soltarArrastre);
  }

  el.ticket && el.ticket.addEventListener('click', function (e) {
    if (e.target.closest('[data-deshacer]')) { deshacer(); return; }
    if (e.target.closest('[data-caja-vaciar]')) { vaciar(); return; }
    if (e.target.closest('[data-caja-cerrar-ticket]')) { cerrarTicketHoja(); if (el.barra) el.barra.focus(); return; }
    var cobrar = e.target.closest('[data-caja-cobrar]');
    if (cobrar) { abrirPago(cobrar.getAttribute('data-caja-cobrar')); return; }
    if (e.target.closest('[data-pago-cerrar]')) { cerrarPago(true); return; }
    var billete = e.target.closest('[data-billete]');
    if (billete && estado.pago) { estado.pago.recibido = parseInt(billete.getAttribute('data-billete'), 10); estado.pago.tecleado = ''; estado.pago.error = ''; pintarPago(); var c = el.pago.querySelector('[data-pago-confirmar]'); if (c) c.focus({ preventScroll: true }); anunciarRecibido(); return; }
    var tecla = e.target.closest('[data-teclado]');
    if (tecla) { teclearMonto(tecla.getAttribute('data-teclado')); return; }
    if (e.target.closest('[data-pago-confirmar]')) { confirmarPago(); return; }
    if (e.target.closest('[data-pago-teclado]') && estado.pago) { abrirTecladoMonto(); return; }
    var imp = e.target.closest('[data-imprimir]');
    if (imp) { imprimir(imp.getAttribute('data-imprimir'), imp); return; }
    if (e.target.closest('[data-vendido-nueva]')) {
      salirDeVendido();
      pintarTicket();
      cerrarTicketHoja();
      var buscar = el.buscar || el.teclas.querySelector('.caja-tecla__principal');
      if (buscar && !compacto.matches) buscar.focus({ preventScroll: true });
    }
  });

  if (el.barra) el.barra.addEventListener('click', function () { abrirTicketHoja(); var f = el.ticket.querySelector('[data-caja-cobrar]:not([disabled])'); if (f) f.focus({ preventScroll: true }); });

  el.velo && el.velo.addEventListener('click', function () {
    if (el.velo.getAttribute('data-velo') === 'ticket') cerrarTicketHoja();
    else cerrarHoja();
  });

  document.addEventListener('click', function (e) {
    var abrir = e.target.closest('[data-caja-abrir]');
    if (abrir) { abrirHoja(abrir.getAttribute('data-caja-abrir'), abrir); return; }
    if (e.target.closest('[data-caja-cerrar-hoja]')) { cerrarHoja(); return; }
    if (atajos) {
      var a = e.target.closest('[data-atajo]');
      if (a) {
        var n = parseInt(a.getAttribute('data-atajo'), 10);
        var oferta = parseInt(atajos.dataset.oferta, 10);
        var opcion = atajos.dataset.opcion;
        cerrarAtajos();
        agregar(oferta, opcion ? [parseInt(opcion, 10)] : [], n);
        var t = el.teclas.querySelector('[data-tecla="' + oferta + '"]');
        pulsar(t && t.querySelector('.caja-tecla__cuenta'));
        return;
      }
      if (!e.target.closest('.caja-atajos')) cerrarAtajos();
    }
    if (estado.abierta !== null && !e.target.closest('[data-tecla="' + estado.abierta + '"]') && el.teclas && el.teclas.contains(e.target) === false && !e.target.closest('.caja-ticket')) {
      cerrarFicha(false);
    }
  });

  // Dentro de las hojas: horneada, tickets y corte.
  document.addEventListener('click', function (e) {
    if (!hojaAbierta || !hojaAbierta.contains(e.target)) return;
    var hoja = hojaAbierta;
    var suma = e.target.closest('[data-horno-suma]');
    if (suma) { sumarHorno(hoja, suma.closest('[data-horno]').getAttribute('data-horno'), parseInt(suma.getAttribute('data-horno-suma'), 10)); return; }
    if (e.target.closest('[data-horno-registrar]')) { registrarHorneada(hoja); return; }
    var fila = e.target.closest('[data-tk-abrir]');
    if (fila) {
      var det = document.getElementById(fila.getAttribute('aria-controls'));
      var abierto = fila.getAttribute('aria-expanded') === 'true';
      fila.setAttribute('aria-expanded', String(!abierto));
      det.hidden = abierto;
      return;
    }
    var imp = e.target.closest('[data-imprimir]');
    if (imp) { imprimir(imp.getAttribute('data-imprimir'), imp); return; }
    var an = e.target.closest('[data-anular]');
    if (an) { abrirAnulacion(an); return; }
    var mot = e.target.closest('[data-motivo]');
    if (mot && anulacion) { anulacion.motivo = mot.getAttribute('data-motivo'); anulacion.error = ''; pintarAnulacion(); var m = anulacion.form.querySelector('[data-motivo="' + anulacion.motivo + '"]'); if (m) m.focus({ preventScroll: true }); return; }
    var nip = e.target.closest('[data-nip]');
    if (nip && anulacion) {
      var v = nip.getAttribute('data-nip');
      anulacion.nip = v === 'borrar' ? anulacion.nip.slice(0, -1) : (anulacion.nip + v).slice(0, 4);
      anulacion.error = '';
      pintarAnulacion();
      var f = anulacion.form.querySelector('[data-nip="' + v + '"]');
      if (f) f.focus({ preventScroll: true });
      return;
    }
    if (e.target.closest('[data-anular-cancelar]') && anulacion) {
      var acciones = anulacion.form.parentNode.querySelector('.caja-tk__acciones');
      anulacion.form.hidden = true;
      anulacion.form.innerHTML = '';
      if (acciones) acciones.hidden = false;
      anulacion = null;
      return;
    }
    if (e.target.closest('[data-anular-confirmar]')) { confirmarAnulacion(); return; }
    var turno = e.target.closest('[data-corte-turno]');
    if (turno) { cargarCorte(hoja, turno.getAttribute('data-corte-turno')); }
  });

  document.addEventListener('input', function (e) {
    if (!hojaAbierta) return;
    if (e.target.matches('[data-horno-buscar]')) { horno.filtro = e.target.value; pintarListaHorno(hojaAbierta); return; }
    if (e.target.matches('[data-horno-campo]')) {
      e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
      sumarHorno(hojaAbierta, e.target.closest('[data-horno]').getAttribute('data-horno'), parseInt(e.target.value || '0', 10), true);
      return;
    }
    if (e.target.matches('[data-anular-nota]') && anulacion) {
      anulacion.nota = e.target.value;
      var boton = anulacion.form.querySelector('[data-anular-confirmar]');
      var pideNip = !estado.permisos.anular;
      if (boton) boton.disabled = !(anulacion.motivo && (anulacion.motivo !== 'otro' || anulacion.nota.trim()) && (!pideNip || anulacion.nip.length === 4));
    }
  });

  // Teclado de toda la pantalla.
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented) return;
    if (hojaAbierta) {
      if (e.key === 'Escape') { e.preventDefault(); if (anulacion) { anulacion = null; cargarTickets(hojaAbierta); } else cerrarHoja(); return; }
      if (anulacion && !estado.permisos.anular && /^[0-9]$/.test(e.key) && !enCampo(e.target)) { e.preventDefault(); anulacion.nip = (anulacion.nip + e.key).slice(0, 4); pintarAnulacion(); return; }
      U.atraparFoco(hojaAbierta, e);
      return;
    }
    if (atajos) {
      if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); var origen = atajos.dataset.oferta; cerrarAtajos(); var b = el.teclas.querySelector('[data-tecla="' + origen + '"] .caja-tecla__principal'); if (b) b.focus(); return; }
      if (/^[1-4]$/.test(e.key)) { var bt = atajos.querySelectorAll('[data-atajo]')[parseInt(e.key, 10) - 1]; if (bt) { e.preventDefault(); bt.click(); } return; }
      // Es un role=menu: las flechas recorren sus opciones.
      if (['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(e.key) !== -1) {
        var opciones = Array.prototype.slice.call(atajos.querySelectorAll('[data-atajo]'));
        var k = opciones.indexOf(document.activeElement);
        var sig = e.key === 'Home' ? 0 : e.key === 'End' ? opciones.length - 1 :
          (k + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1) + opciones.length) % opciones.length;
        e.preventDefault();
        opciones[sig].focus();
      }
      return;
    }
    if (estado.pago) {
      if (e.key === 'Escape') { e.preventDefault(); cerrarPago(true); return; }
      if (e.key === 'Enter' && !e.target.closest('button')) { e.preventDefault(); confirmarPago(); return; }
      if (estado.pago.metodo === 'efectivo' && !enCampo(e.target)) {
        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); teclearMonto(e.key); return; }
        if (e.key === 'Backspace') { e.preventDefault(); teclearMonto('borrar'); return; }
      }
      return;
    }
    // F2/F3 antes del filtro de campos: no escriben nada, y quien acaba de
    // buscar «conch» tiene el foco en la búsqueda cuando quiere cobrar.
    if ((e.key === 'F2' || e.key === 'F3') && estado.permisos.vender) { e.preventDefault(); abrirPago(e.key === 'F2' ? 'efectivo' : 'tarjeta'); return; }
    if (enCampo(e.target)) return;
    if (estado.vendido && (e.key === 'Enter' || e.key.toLowerCase() === 'n') && !e.target.closest('button')) {
      e.preventDefault();
      salirDeVendido(); pintarTicket();
      if (el.buscar) el.buscar.focus();
      return;
    }
    if (e.key === 'Escape') {
      if (estado.multiplicador) { tomarMultiplicador(); return; }
      if (raiz.classList.contains('ticket-abierto')) { cerrarTicketHoja(); return; }
      if (estado.abierta !== null) { cerrarFicha(true); return; }
      return;
    }
    var ultima = estado.lineas[estado.lineas.length - 1];
    if ((e.key === '+' || e.key === '=') && ultima) { e.preventDefault(); cambiarCantidad(ultima.clave, 1); return; }
    if ((e.key === '-' || e.key === '_') && ultima) { e.preventDefault(); cambiarCantidad(ultima.clave, -1); return; }
    if (e.key === 'Delete' && ultima) { e.preventDefault(); quitar(ultima.clave); return; }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (/^[0-9]$/.test(e.key) && estado.permisos.vender) {
      if (e.key === '0' && !estado.multiplicador) return;
      e.preventDefault();
      estado.multiplicador = (estado.multiplicador + e.key).slice(0, 3);
      if (parseInt(estado.multiplicador, 10) > 255) estado.multiplicador = '255';
      pintarMultiplicador();
      return;
    }
    if (el.buscar && (e.key === '/' || (e.key.length === 1 && /\p{L}/u.test(e.key)))) {
      e.preventDefault();
      el.buscar.focus();
      if (e.key !== '/') { el.buscar.value += e.key; el.buscar.dispatchEvent(new Event('input')); }
    }
  });

  compacto.addEventListener && compacto.addEventListener('change', function () { if (!compacto.matches) cerrarTicketHoja(); });

  // Con la tecla extendida, su sitio depende de cuántas columnas hay (va al
  // final de su fila). Si al girar la tableta o abrir el ticket cambian las
  // columnas, se recoloca; si no, no se toca nada.
  var reacomodo = 0;
  window.addEventListener('resize', function () {
    if (estado.abierta === null || reacomodo) return;
    reacomodo = requestAnimationFrame(function () {
      reacomodo = 0;
      if (estado.abierta !== null && el.teclas && columnas() !== columnasPintadas) pintarTeclasConFoco();
    });
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refrescarVitrina(); actualizarPulso(); } });

  // En tablet un toque accidental no debe sacar del turno: el header marca su
  // formulario con [data-confirm-logout] y aquí se confirma, como en el POS.
  (function confirmarSalida() {
    var form = document.querySelector('[data-confirm-logout]');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      if (form.dataset.logoutConfirmed === '1') return;
      e.preventDefault();
      if (!window.ConfirmationModal) { form.dataset.logoutConfirmed = '1'; form.submit(); return; }
      window.ConfirmationModal.get().open({
        variant: 'warning',
        eyebrow: 'Sesión',
        title: '¿Cerrar sesión?',
        description: 'Saldrás de la caja y volverás a la pantalla de acceso.',
        consequence: estado.lineas.length ? 'La venta sin cobrar de esta pantalla se descarta.' : 'Lo cobrado ya está guardado.',
        secondaryLabel: 'Seguir aquí',
        primaryLabel: 'Cerrar sesión',
        onPrimary: function () {
          try { window.sessionStorage.removeItem(CLAVE_BORRADOR); } catch (x) { /* sin almacenamiento */ }
          form.dataset.logoutConfirmed = '1';
          form.submit();
        }
      });
    });
  }());

  // ── Arranque ──────────────────────────────────────────────────

  cargarCatalogo(datos.catalogo);
  cargarVitrina(datos.vitrina, false);
  if (estado.permisos.vender) {
    restaurarBorrador();
    pintarFamilias();
    pintarTeclas();
    pintarTicket();
    actualizarPulso();
  }
  setInterval(refrescarVitrina, 45000);
}(window, document));
