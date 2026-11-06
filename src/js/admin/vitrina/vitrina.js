/**
 * Vitrina de la caja (Panadería · Menú).
 *
 * Una tarjeta por pan: qué pieza descuenta y sus opciones. «Editar» abre el
 * editor en la propia tarjeta —sólo uno a la vez— y «Guardar» manda pieza y
 * opciones juntas: el servidor guarda todo o nada (Services\Menu\VitrinaCaja).
 * Las opciones son las mismas variantes del POS; aquí se añade la pieza que
 * sale de la vitrina con cada una.
 *
 * Una opción con «La del pan» sale de la pieza del pan. Si además cambia la
 * cantidad (media docena = 6, media hogaza = 0.5) se guarda como pieza propia
 * con la clave del pan: así no hay que volver a elegir la misma pieza en la
 * lista para poder escribir otra cantidad. Mientras el campo no se toca, sigue
 * a la cantidad del pan (`data-sigue`).
 */
(function (window, document) {
  'use strict';

  var raiz = document.querySelector('[data-vitrina]');
  var fuente = raiz && raiz.querySelector('[data-vitrina-datos]');
  var lista = raiz && raiz.querySelector('[data-vitrina-lista]');
  if (!raiz || !fuente || !lista) return;

  var datos = {};
  try { datos = JSON.parse(fuente.textContent || '{}') || {}; } catch (e) { datos = {}; }
  var puedeEditar = raiz.getAttribute('data-puede-editar') === '1' && !!datos.puedeEditar;
  var buscar = raiz.querySelector('[data-vitrina-buscar]');
  var conteo = raiz.querySelector('[data-vitrina-conteo]');
  var resultados = raiz.querySelector('[data-vitrina-resultados]');
  var filtroSin = raiz.querySelector('[data-vitrina-sin]');
  var productos = datos.productos || [];
  var piezas = datos.piezas || [];
  var LIMITE_GRUPOS = 6;

  var porClave = {};
  var almacenes = [];
  var porAlmacen = {};
  piezas.forEach(function (p) {
    porClave[p.clave] = p;
    if (!porAlmacen[p.almacen]) { porAlmacen[p.almacen] = []; almacenes.push(p.almacen); }
    porAlmacen[p.almacen].push(p);
  });

  var abierto = null;   // id del pan en edición
  var borrador = null;  // copia editable de ese pan
  var inicial = '';     // borrador recién abierto, para saber si hay cambios
  var enviando = false;
  var error = '';
  var soloSin = false;
  var ultimoAnuncio = '';

  // ── Utilidades ─────────────────────────────────────────────

  function icono(nombre) {
    var t = raiz.querySelector('template[data-vitrina-icono="' + nombre + '"]');
    return t ? t.innerHTML.trim() : '';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  var formatoDinero = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  function dinero(v) { return formatoDinero.format(Number(v) || 0); }
  function delta(v) {
    var n = Number(v) || 0;
    if (!n) return 'Sin cambio';
    return (n > 0 ? '+' : '−') + dinero(Math.abs(n));
  }
  function cantidad(q) { var n = parseFloat(q); return isFinite(n) ? String(Math.round(n * 1000) / 1000) : '1'; }
  function normalizar(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function producto(id) { return productos.filter(function (x) { return x.id === id; })[0] || null; }
  function copia(p) { return JSON.parse(JSON.stringify({ pieza: p.pieza, grupos: p.grupos || [] })); }

  function enfocar(selector) {
    var f = selector && lista.querySelector(selector);
    if (f) { f.focus(); return true; }
    return false;
  }

  // ── Lista ──────────────────────────────────────────────────

  function resumenPieza(pz) {
    var html = '<span class="admin-vitrina__estado-rotulo">Descuenta</span>';
    if (!pz) return html + '<span class="admin-vitrina__sin">Nada · se vende sin vitrina</span>';
    var info = porClave[pz.clave];
    if (!info) return html + '<span class="admin-vitrina__sin">Pieza retirada del almacén</span>';
    var q = cantidad(pz.cantidad);
    return html + '<span class="admin-vitrina__pieza">' +
      (q !== '1' ? '<span class="admin-num">' + esc(q) + ' ×</span> ' : '') +
      esc(info.nombre) + ' <span class="admin-vitrina__almacen">· ' + esc(info.almacen) + '</span></span>';
  }

  function resumenOpciones(p) {
    if (!p.grupos || !p.grupos.length) return '';
    return '<ul class="admin-vitrina__grupos-resumen">' + p.grupos.map(function (g) {
      return '<li><span class="admin-vitrina__grupo-nombre">' + esc(g.nombre) + '</span>' + g.opciones.map(function (o) {
        var extra = [];
        if (Number(o.precio_delta)) extra.push(delta(o.precio_delta));
        if (o.pieza) {
          var q = cantidad(o.pieza.cantidad);
          extra.push((q !== '1' ? q + ' × ' : '') + (porClave[o.pieza.clave] ? porClave[o.pieza.clave].nombre : 'pieza retirada'));
        }
        return '<span class="admin-vitrina__opcion' + (o.predeterminada ? ' is-def' : '') + (o.activo === false ? ' is-apagada' : '') + '">' +
          esc(o.nombre) +
          (o.predeterminada ? '<span class="admin-visually-hidden"> (base)</span>' : '') +
          (o.activo === false ? '<span class="admin-visually-hidden"> (apagada)</span>' : '') +
          (extra.length ? ' <em>' + esc(extra.join(' · ')) + '</em>' : '') + '</span>';
      }).join('') + '</li>';
    }).join('') + '</ul>';
  }

  function filaHtml(p) {
    var editando = abierto === p.id;
    return '<li class="admin-vitrina__item admin-card' + (editando ? ' is-editando' : '') + (p.activo ? '' : ' is-retirado') + '" data-producto="' + p.id + '">' +
      '<div class="admin-vitrina__cabeza">' +
      '<div class="admin-vitrina__titulo"><h2 class="admin-vitrina__nombre">' + esc(p.nombre) + '</h2>' +
      '<p class="admin-vitrina__meta">' + esc(p.categoria) + ' · <span class="admin-num">' + dinero(p.precio) + '</span>' +
      (p.activo ? '' : ' · <span class="admin-vitrina__retirado">Retirado del menú</span>') + '</p></div>' +
      '<p class="admin-vitrina__estado">' + resumenPieza(p.pieza) + '</p>' +
      (puedeEditar && !editando
        ? '<button type="button" class="admin-btn admin-btn--secondary admin-btn--small admin-vitrina__editar" data-accion="editar" aria-label="Editar vitrina de ' + esc(p.nombre) + '">' + icono('editar') + '<span>Editar</span></button>'
        : '') +
      '</div>' +
      (editando ? editorHtml(p) : resumenOpciones(p)) +
      '</li>';
  }

  function pintarConteo(visibles, q) {
    var con = productos.filter(function (p) { return p.pieza; }).length;
    var sin = productos.length - con;
    if (conteo) {
      conteo.innerHTML = productos.length
        ? '<span class="admin-num">' + con + '</span> de <span class="admin-num">' + productos.length + '</span> panes se venden de vitrina'
        : '';
    }
    if (filtroSin) {
      filtroSin.hidden = !sin && !soloSin;
      filtroSin.setAttribute('aria-pressed', soloSin ? 'true' : 'false');
      var n = filtroSin.querySelector('[data-vitrina-sin-n]');
      if (n) n.textContent = String(sin);
    }
    // El recuento de la búsqueda va a una región viva aparte: el conteo de
    // arriba no cambia al escribir y anunciarlo en cada tecla sólo estorba.
    var anuncio = q || soloSin ? (visibles === 1 ? '1 pan en la lista' : visibles + ' panes en la lista') : '';
    if (resultados && anuncio !== ultimoAnuncio) { resultados.textContent = anuncio; ultimoAnuncio = anuncio; }
  }

  function pintar() {
    var q = normalizar(buscar ? buscar.value.trim() : '');
    // El pan que se está editando no desaparece al filtrar: perderlo de vista
    // a media edición se leería como cambios perdidos.
    var visibles = productos.filter(function (p) {
      if (p.id === abierto) return true;
      if (soloSin && p.pieza) return false;
      return !q || normalizar(p.nombre).indexOf(q) !== -1 || normalizar(p.categoria).indexOf(q) !== -1;
    });
    var vacio = !productos.length ? 'La Panadería todavía no tiene panes en su menú.'
      : q ? 'Ningún pan coincide con la búsqueda.'
      : 'Todos los panes ya descuentan una pieza.';
    lista.innerHTML = visibles.length
      ? visibles.map(filaHtml).join('')
      : '<li class="admin-vitrina__vacio">' + vacio + '</li>';
    pintarConteo(visibles.length, q);
  }

  function repintar(foco) {
    pintar();
    enfocar(foco);
  }

  // ── Editor ─────────────────────────────────────────────────

  function selectorPieza(campo, actual, vacio, apagado) {
    // Una pieza guardada que ya no está activa se muestra tal cual: si el
    // select cayera a «vacío», guardar la borraría sin avisar.
    var huerfana = actual && !porClave[actual]
      ? '<option value="' + esc(actual) + '" selected>Pieza retirada del almacén: elige otra</option>'
      : '';
    return '<select data-native data-campo="' + campo + '"' + (apagado ? ' disabled' : '') + '>' +
      '<option value="">' + esc(vacio) + '</option>' + huerfana +
      almacenes.map(function (a) {
        return '<optgroup label="' + esc(a) + '">' + porAlmacen[a].map(function (p) {
          return '<option value="' + esc(p.clave) + '"' + (actual === p.clave ? ' selected' : '') + '>' + esc(p.nombre) + ' (' + esc(p.unidad) + ')</option>';
        }).join('') + '</optgroup>';
      }).join('') + '</select>';
  }

  function ayudaPieza(conPieza) {
    return conPieza
      ? 'Cada venta resta esta pieza de su almacén. Si no quedan, la caja vende igual y avisa.'
      : 'Sin pieza, la caja vende este pan sin tocar la vitrina.';
  }

  function rotuloOpcion(o, oi) { return 'Opción ' + (oi + 1) + (o.nombre ? ': ' + o.nombre : '') + (o.activo === false ? ' (apagada)' : ''); }
  function rotuloGrupo(g, gi) { return 'Grupo ' + (gi + 1) + (g.nombre ? ': ' + g.nombre : ''); }

  function filaOpcion(p, gi, o, oi, editables, base) {
    var rotulo = rotuloOpcion(o, oi);
    // La pieza propia que coincide con la del pan se pinta como «La del pan»
    // con su cantidad: es la media docena o la media hogaza.
    var propia = o.pieza && !(base && o.pieza.clave === base.clave) ? o.pieza.clave : '';
    var q = base ? cantidad(o.pieza ? o.pieza.cantidad : base.cantidad) : '';
    var sigue = base && !o.pieza;
    var apagada = o.activo === false;
    var h = '<div class="admin-vitrina__opcion-fila' + (apagada ? ' is-apagada' : '') + '" role="group" aria-label="' + esc(rotulo) + '" data-opcion="' + oi + '">';

    h += editables
      ? '<label class="admin-vitrina__campo admin-vitrina__campo--nombre"><span class="admin-vitrina__etq">Nombre</span>' +
        '<input type="text" maxlength="60" data-campo="op-nombre" value="' + esc(o.nombre) + '" placeholder="Nata"></label>'
      : '<p class="admin-vitrina__fijo admin-vitrina__campo--nombre"><span class="admin-vitrina__etq">Nombre</span><span>' + esc(o.nombre) + '</span></p>';

    h += editables
      ? '<label class="admin-vitrina__campo admin-vitrina__campo--precio"><span class="admin-vitrina__etq">Cambio de precio</span>' +
        '<span class="admin-vitrina__con-signo"><span class="admin-vitrina__signo" aria-hidden="true">$</span>' +
        '<input type="number" step="0.01" data-campo="op-delta" value="' + esc(Number(o.precio_delta) || 0) + '"></span></label>'
      : '<p class="admin-vitrina__fijo admin-vitrina__campo--precio"><span class="admin-vitrina__etq">Cambio de precio</span><span' + (Number(o.precio_delta) ? ' class="admin-num"' : '') + '>' + esc(delta(o.precio_delta)) + '</span></p>';

    h += '<label class="admin-vitrina__campo admin-vitrina__campo--sale"><span class="admin-vitrina__etq">Sale de</span>' +
      selectorPieza('op-pieza', propia, 'La del pan', !base) + '</label>';

    h += '<label class="admin-vitrina__campo admin-vitrina__campo--cant"><span class="admin-vitrina__etq">Piezas<span class="admin-visually-hidden"> por venta</span></span>' +
      '<input type="number" inputmode="decimal" min="0.001" max="9999" step="0.001" data-campo="op-cantidad" value="' + esc(q) + '"' +
      (base ? ' placeholder="' + esc(cantidad(base.cantidad)) + '"' : ' placeholder="—" disabled') + (sigue ? ' data-sigue' : '') + '></label>';

    // Una opción apagada no sale en la caja y no puede ser la base: en su
    // celda va la palabra, no el radio.
    h += apagada
      ? '<p class="admin-vitrina__def" title="Apagada: no aparece en la caja"><span class="admin-vitrina__apagada">Apagada</span></p>'
      : editables
      ? '<label class="admin-vitrina__def"><input type="radio" name="def-' + p.id + '-' + gi + '" data-campo="op-def"' + (o.predeterminada ? ' checked' : '') + '>' +
        '<span class="admin-vitrina__etq">Base</span></label>'
      : '<p class="admin-vitrina__def">' + (o.predeterminada ? icono('check') + '<span class="admin-visually-hidden">Base</span>' : '') + '</p>';

    h += editables
      ? '<button type="button" class="admin-vitrina__quitar" data-accion="quitar-opcion" aria-label="Quitar ' + esc(rotulo.charAt(0).toLowerCase() + rotulo.slice(1)) + '">' + icono('basura') + '</button>'
      : '<span class="admin-vitrina__quitar" aria-hidden="true"></span>';

    return h + '</div>';
  }

  function editorHtml(p) {
    var b = borrador;
    var editables = !!p.opcionesEditables;
    var base = b.pieza && b.pieza.clave ? b.pieza : null;
    var html = '<div class="admin-vitrina__editor" data-editor>';

    html += '<fieldset class="admin-vitrina__bloque"><legend class="admin-vitrina__rotulo">Pieza que descuenta</legend>' +
      '<div class="admin-vitrina__fila-pieza">' +
      '<label class="admin-vitrina__campo"><span class="admin-vitrina__mini">Sale de</span>' +
      selectorPieza('pieza', base ? base.clave : '', 'No se vende de vitrina', false) + '</label>' +
      '<label class="admin-vitrina__campo"><span class="admin-vitrina__mini">Piezas por venta</span>' +
      '<input type="number" inputmode="decimal" min="0.001" max="9999" step="0.001" data-campo="pieza-cantidad" value="' + esc(base ? cantidad(base.cantidad) : '1') + '"' + (base ? '' : ' disabled') + '></label>' +
      '</div>' +
      '<p class="admin-vitrina__ayuda" data-ayuda-pieza>' + ayudaPieza(!!base) + '</p></fieldset>';

    html += '<fieldset class="admin-vitrina__bloque"><legend class="admin-vitrina__rotulo">Opciones en la caja</legend>';
    if (!editables) {
      html += '<p class="admin-vitrina__protegido">' + icono('info') +
        '<span>Global protege el precio o la receta de este pan: sus opciones no se editan aquí, pero sí de qué pieza sale cada una.</span></p>';
    }
    if (b.grupos.length) {
      html += '<p class="admin-vitrina__ayuda">Cada opción sale de la pieza del pan salvo que elijas otra. Para media docena deja «La del pan» y escribe 6 piezas; para media hogaza, 0.5. Sólo las opciones de un mismo grupo pueden cambiar la pieza.</p>';
      html += '<p class="admin-vitrina__ayuda admin-vitrina__ayuda--pendiente" data-ayuda-opciones' + (base ? ' hidden' : '') + '>Elige primero la pieza del pan: las opciones sólo la cambian.</p>';
    } else {
      html += '<p class="admin-vitrina__ayuda">Sin opciones: en la caja es una tecla de un toque.</p>';
    }

    b.grupos.forEach(function (g, gi) {
      html += '<div class="admin-vitrina__grupo" role="group" aria-label="' + esc(rotuloGrupo(g, gi)) + '" data-grupo="' + gi + '">' +
        '<div class="admin-vitrina__grupo-cabeza">';
      if (editables) {
        html += '<label class="admin-vitrina__campo admin-vitrina__campo--grupo"><span class="admin-vitrina__mini">Nombre del grupo</span>' +
          '<input type="text" maxlength="40" data-campo="grupo-nombre" value="' + esc(g.nombre) + '" placeholder="Relleno, tamaño…"></label>' +
          '<button type="button" class="admin-btn admin-btn--ghost admin-btn--small admin-vitrina__quitar-grupo" data-accion="quitar-grupo">' + icono('basura') + '<span>Quitar grupo</span></button>';
      } else {
        html += '<p class="admin-vitrina__grupo-fijo">' + esc(g.nombre) + '</p>';
      }
      html += '</div><div class="admin-vitrina__opciones">' +
        '<div class="admin-vitrina__opcion-cabeza" aria-hidden="true"><span>Opción</span><span>± Precio</span><span>Sale de</span><span>Piezas</span><span>Base</span><span></span></div>';
      g.opciones.forEach(function (o, oi) { html += filaOpcion(p, gi, o, oi, editables, base); });
      html += '</div>';
      if (editables) html += '<button type="button" class="admin-vitrina__agregar" data-accion="agregar-opcion">' + icono('mas') + '<span>Agregar opción</span></button>';
      html += '</div>';
    });

    if (editables && b.grupos.length < LIMITE_GRUPOS) {
      html += '<button type="button" class="admin-vitrina__agregar" data-accion="agregar-grupo">' + icono('mas') + '<span>Agregar grupo de opciones</span></button>';
    }
    if (b.grupos.length) {
      html += '<p class="admin-vitrina__ayuda">Con un grupo de 2 a 4 opciones la tecla de la caja se parte en gajos; con más, crece al tocarla.</p>';
    }
    html += '</fieldset>';

    if (error) html += '<p class="admin-vitrina__error" role="alert">' + esc(error) + '</p>';
    html += '<div class="admin-vitrina__acciones">' +
      '<button type="button" class="admin-btn admin-btn--ghost" data-accion="cancelar">Cancelar</button>' +
      '<button type="button" class="admin-btn admin-btn--primary" data-accion="guardar" aria-keyshortcuts="Control+S">' +
      '<span data-guardar-texto>Guardar</span><kbd class="admin-vitrina__kbd" aria-hidden="true">Ctrl S</kbd></button>' +
      '</div>';
    return html + '</div>';
  }

  // «La del pan» sólo se guarda como pieza propia si la cantidad cambió.
  function piezaDeOpcion(clave, campo, base) {
    if (!base) return null;
    var q = campo && campo.value.trim() ? campo.value.trim() : '';
    if (clave) return { clave: clave, cantidad: q || '1' };
    if (!campo || campo.hasAttribute('data-sigue') || !q || cantidad(q) === cantidad(base.cantidad)) return null;
    return { clave: base.clave, cantidad: q };
  }

  function leerEditor() {
    var ed = lista.querySelector('[data-editor]');
    if (!ed || !borrador) return;
    var sel = ed.querySelector('[data-campo="pieza"]');
    var q = ed.querySelector('[data-campo="pieza-cantidad"]');
    borrador.pieza = sel && sel.value ? { clave: sel.value, cantidad: (q && q.value.trim()) || '1' } : null;
    var base = borrador.pieza;
    ed.querySelectorAll('[data-grupo]').forEach(function (gEl) {
      var g = borrador.grupos[parseInt(gEl.getAttribute('data-grupo'), 10)];
      if (!g) return;
      var gn = gEl.querySelector('input[data-campo="grupo-nombre"]');
      if (gn) g.nombre = gn.value;
      gEl.querySelectorAll('[data-opcion]').forEach(function (oEl) {
        var o = g.opciones[parseInt(oEl.getAttribute('data-opcion'), 10)];
        if (!o) return;
        // Con las opciones protegidas por Global sólo existen los campos de
        // pieza: nombre, precio y base se quedan como llegaron.
        var nom = oEl.querySelector('input[data-campo="op-nombre"]');
        var del = oEl.querySelector('input[data-campo="op-delta"]');
        var def = oEl.querySelector('input[data-campo="op-def"]');
        if (nom) o.nombre = nom.value;
        if (del) o.precio_delta = parseFloat(del.value) || 0;
        if (def) o.predeterminada = def.checked;
        var ps = oEl.querySelector('[data-campo="op-pieza"]');
        o.pieza = piezaDeOpcion(ps ? ps.value : '', oEl.querySelector('[data-campo="op-cantidad"]'), base);
      });
    });
  }

  function sucio() {
    if (abierto === null || !borrador) return false;
    leerEditor();
    return JSON.stringify(borrador) !== inicial;
  }

  function abrir(id) {
    var p = producto(id);
    if (!p) return;
    abierto = id; error = '';
    borrador = copia(p);
    repintar('[data-producto="' + id + '"] [data-campo="pieza"]');
    // La foto de partida pasa por la misma lectura que el guardado, para que
    // «hay cambios» no dependa de cómo vino formateada cada cifra.
    leerEditor();
    inicial = JSON.stringify(borrador);
  }

  function cerrar() {
    var prev = abierto;
    abierto = null; borrador = null; inicial = ''; error = '';
    repintar('[data-producto="' + prev + '"] [data-accion="editar"]');
  }

  // Descartar sin preguntar sólo si no hay nada que perder.
  function confirmarDescarte() {
    if (!sucio()) return Promise.resolve(true);
    if (!(window.ConfirmationModal && window.ConfirmationModal.get)) return Promise.resolve(true);
    var p = producto(abierto);
    return window.ConfirmationModal.get().open({
      eyebrow: 'Vitrina de la caja',
      title: '¿Descartar los cambios de ' + (p ? p.nombre : 'este pan') + '?',
      description: 'La pieza y las opciones vuelven a como estaban guardadas.',
      primaryLabel: 'Descartar cambios',
      secondaryLabel: 'Seguir editando',
      variant: 'danger'
    }).then(function (r) { return !!(r && r.action === 'primary'); });
  }

  function piezaPayload(p) {
    if (!p) return null;
    var partes = String(p.clave).split(':');
    return { almacen_id: parseInt(partes[0], 10), articulo_id: parseInt(partes[1], 10), cantidad: String(p.cantidad || '1') };
  }

  function guardar() {
    if (enviando || abierto === null) return;
    leerEditor();
    var p = producto(abierto);
    if (!p) return;
    enviando = true; error = '';
    // Sin repintar: el foco se queda en el botón. aria-disabled y no disabled,
    // porque un botón deshabilitado suelta el foco al <body>.
    var ed = lista.querySelector('[data-editor]');
    var btn = ed && ed.querySelector('[data-accion="guardar"]');
    var previo = ed && ed.querySelector('.admin-vitrina__error');
    if (previo) previo.remove();
    if (ed) ed.setAttribute('aria-busy', 'true');
    if (btn) {
      btn.setAttribute('aria-disabled', 'true');
      var t = btn.querySelector('[data-guardar-texto]');
      if (t) t.textContent = 'Guardando…';
    }
    var cuerpo = {
      admin_csrf: raiz.getAttribute('data-admin-csrf'),
      oferta_id: p.id,
      pieza: piezaPayload(borrador.pieza),
      grupos: borrador.grupos.map(function (g) {
        return { nombre: g.nombre, opciones: g.opciones.map(function (o) {
          // icono viaja tal cual: un ícono elegido en Restaurante no se vuelve automático al guardar aquí.
          return { id: o.id || 0, nombre: o.nombre, precio_delta: o.precio_delta, predeterminada: !!o.predeterminada, activo: o.activo !== false, ajustes: o.ajustes || [], icono: o.icono, pieza: piezaPayload(o.pieza) };
        }) };
      })
    };
    fetch(window.location.pathname, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(cuerpo)
    }).then(function (r) { return r.json().catch(function () { return {}; }); }, function () { return { ok: false, mensaje: 'Sin conexión. Tus cambios siguen en pantalla; vuelve a intentar.' }; })
      .then(function (d) {
        enviando = false;
        if (d && d.ok && d.producto) {
          productos = productos.map(function (x) { return x.id === d.producto.id ? d.producto : x; });
          abierto = null; borrador = null; inicial = '';
          pintar();
          // Acuse en la propia tarjeta (vitrina.scss, is-guardado): el aviso
          // dice qué se guardó; el anillo, dónde.
          var hecha = lista.querySelector('[data-producto="' + d.producto.id + '"]');
          if (hecha) hecha.classList.add('is-guardado');
          if (!enfocar('[data-producto="' + d.producto.id + '"] [data-accion="editar"]') && buscar) buscar.focus();
          if (window.AppNotice) window.AppNotice.success('Vitrina de ' + d.producto.nombre + ' guardada. La caja la usa desde la siguiente venta.');
          return;
        }
        error = (d && d.mensaje) || 'No se pudo guardar. Intenta de nuevo.';
        repintar('[data-accion="guardar"]');
      });
  }

  // ── Eventos ────────────────────────────────────────────────

  lista.addEventListener('click', function (e) {
    var b = e.target.closest('[data-accion]');
    if (!b || !lista.contains(b)) return;
    var accion = b.getAttribute('data-accion');

    if (accion === 'editar') {
      var id = parseInt(b.closest('[data-producto]').getAttribute('data-producto'), 10);
      // Abrir otro pan cierra éste: si tenía cambios, se pregunta antes.
      if (abierto !== null && abierto !== id) {
        confirmarDescarte().then(function (si) { if (si) abrir(id); });
        return;
      }
      abrir(id);
      return;
    }
    if (accion === 'cancelar') { cerrar(); return; }
    if (accion === 'guardar') { guardar(); return; }
    if (!borrador) return;

    leerEditor();
    var gEl = b.closest('[data-grupo]');
    var gi = gEl ? parseInt(gEl.getAttribute('data-grupo'), 10) : -1;
    var nueva = function (def) { return { id: 0, nombre: '', precio_delta: 0, predeterminada: def, activo: true, ajustes: [], pieza: null }; };

    if (accion === 'agregar-grupo') {
      borrador.grupos.push({ nombre: '', opciones: [nueva(true), nueva(false)] });
      repintar('[data-grupo="' + (borrador.grupos.length - 1) + '"] [data-campo="grupo-nombre"]');
      return;
    }
    if (accion === 'quitar-grupo') {
      borrador.grupos.splice(gi, 1);
      repintar('[data-accion="agregar-grupo"]');
      return;
    }
    if (accion === 'agregar-opcion') {
      borrador.grupos[gi].opciones.push(nueva(false));
      repintar('[data-grupo="' + gi + '"] [data-opcion="' + (borrador.grupos[gi].opciones.length - 1) + '"] [data-campo="op-nombre"]');
      return;
    }
    if (accion === 'quitar-opcion') {
      var ops = borrador.grupos[gi].opciones;
      var oi = parseInt(b.closest('[data-opcion]').getAttribute('data-opcion'), 10);
      var eraBase = ops[oi] && ops[oi].predeterminada;
      ops.splice(oi, 1);
      // Un grupo no se queda sin base por quitar la que lo era.
      if (eraBase && ops.length) ops[0].predeterminada = true;
      repintar('[data-grupo="' + gi + '"] [data-accion="agregar-opcion"]');
    }
  });

  // La pieza del pan manda sobre las de sus opciones: sin ella no hay nada
  // que cambiar, y su cantidad la siguen las opciones con «La del pan».
  function aplicarPiezaBase(ed) {
    var sel = ed.querySelector('[data-campo="pieza"]');
    var q = ed.querySelector('[data-campo="pieza-cantidad"]');
    var hay = !!(sel && sel.value);
    var qBase = hay && q && q.value.trim() ? cantidad(q.value) : '1';
    if (q) q.disabled = !hay;
    var ayuda = ed.querySelector('[data-ayuda-pieza]');
    if (ayuda) ayuda.textContent = ayudaPieza(hay);
    var pendiente = ed.querySelector('[data-ayuda-opciones]');
    if (pendiente) pendiente.hidden = hay;
    ed.querySelectorAll('[data-campo="op-pieza"]').forEach(function (s) { s.disabled = !hay; });
    ed.querySelectorAll('[data-campo="op-cantidad"]').forEach(function (c) {
      c.disabled = !hay;
      c.placeholder = hay ? qBase : '—';
      if (hay && (!c.value || c.hasAttribute('data-sigue'))) { c.value = qBase; c.setAttribute('data-sigue', ''); }
    });
  }

  lista.addEventListener('change', function (e) {
    var t = e.target;
    var ed = t.closest('[data-editor]');
    if (!ed) return;
    if (t.matches('[data-campo="pieza"]')) { aplicarPiezaBase(ed); return; }
    if (t.matches('[data-campo="op-pieza"]')) {
      var oq = t.closest('[data-opcion]').querySelector('[data-campo="op-cantidad"]');
      if (!oq) return;
      if (t.value) { oq.removeAttribute('data-sigue'); return; }
      // Volver a «La del pan» es volver a su cantidad.
      var q = ed.querySelector('[data-campo="pieza-cantidad"]');
      oq.value = q && q.value.trim() ? cantidad(q.value) : '1';
      oq.setAttribute('data-sigue', '');
    }
  });

  lista.addEventListener('input', function (e) {
    var t = e.target;
    var ed = t.closest('[data-editor]');
    if (!ed) return;
    if (t.matches('[data-campo="pieza-cantidad"]')) {
      var v = t.value.trim();
      ed.querySelectorAll('[data-campo="op-cantidad"][data-sigue]').forEach(function (c) { c.value = v; });
      ed.querySelectorAll('[data-campo="op-cantidad"]').forEach(function (c) { c.placeholder = v || '1'; });
      return;
    }
    if (t.matches('[data-campo="op-cantidad"]')) { t.removeAttribute('data-sigue'); return; }
    // Los nombres accesibles de la fila y su botón siguen a lo que se escribe.
    if (t.matches('[data-campo="op-nombre"]')) {
      var fila = t.closest('[data-opcion]');
      var r = rotuloOpcion({ nombre: t.value.trim(), activo: !fila.classList.contains('is-apagada') }, parseInt(fila.getAttribute('data-opcion'), 10));
      fila.setAttribute('aria-label', r);
      var quitar = fila.querySelector('[data-accion="quitar-opcion"]');
      if (quitar) quitar.setAttribute('aria-label', 'Quitar ' + r.charAt(0).toLowerCase() + r.slice(1));
      return;
    }
    if (t.matches('[data-campo="grupo-nombre"]')) {
      var gEl = t.closest('[data-grupo]');
      gEl.setAttribute('aria-label', rotuloGrupo({ nombre: t.value.trim() }, parseInt(gEl.getAttribute('data-grupo'), 10)));
    }
  });

  lista.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || abierto === null || e.defaultPrevented) return;
    e.preventDefault();
    confirmarDescarte().then(function (si) { if (si) cerrar(); });
  });

  // En el documento y no en la lista: con el foco fuera de un campo, Ctrl+S
  // abría el «Guardar página» del navegador.
  document.addEventListener('keydown', function (e) {
    if (abierto === null || !(e.ctrlKey || e.metaKey) || (e.key !== 's' && e.key !== 'S')) return;
    e.preventDefault();
    guardar();
  });

  if (buscar) {
    buscar.addEventListener('input', function () {
      leerEditor();  // lo escrito en el editor abierto sobrevive al repintado
      pintar();
    });
  }

  if (filtroSin) {
    filtroSin.addEventListener('click', function () {
      soloSin = !soloSin;
      leerEditor();
      pintar();
    });
  }

  pintar();
}(window, document));
