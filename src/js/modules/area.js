/* ── KDS — Área de Producción · Casa Pestalozzi ─────────────── */

function initArea() {
  if (!window.CP_AREA) return;
  if (initArea._done) return;
  initArea._done = true;

  var AREA_ID     = window.CP_AREA.id;
  var AREA_COLOR  = window.CP_AREA.color;
  var pollTimer   = null;

  var listEnv     = document.getElementById('list-enviados');
  var listPrep    = document.getElementById('list-prep');
  var listListo   = document.getElementById('list-listo');
  var countEnv    = document.getElementById('count-enviados');
  var countPrep   = document.getElementById('count-prep');
  var countListo  = document.getElementById('count-listo');
  var refreshInfo = document.getElementById('area-refresh-info');

  if (!listEnv || !listPrep || !listListo) return;

  // Último payload pintado y su firma. El tablero sólo se reconstruye cuando
  // algo cambió: repintarlo cada segundo destruía el nodo del botón entre el
  // mousedown y el mouseup, y el click nunca llegaba a dispararse.
  var items = [];
  var lastSignature = null;
  // Ítems con una transición en vuelo: mientras haya alguno, la respuesta del
  // poll no pisa el estado local porque el servidor todavía no la conoce.
  var pending = {};
  var pendingCount = 0;
  // Descarta respuestas fuera de orden.
  var loadSequence = 0;
  var renderedSequence = 0;

  var TRANSICIONES = {
    fwd:  { enviado: 'en_preparacion', en_preparacion: 'listo' },
    back: { listo: 'en_preparacion', en_preparacion: 'enviado' }
  };

  // ── Helpers ────────────────────────────────────────────────
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function minutosDesde(timestamp) {
    var d = new Date(timestamp.replace(' ', 'T'));
    var diff = Math.floor((Date.now() - d.getTime()) / 60000);
    return diff < 0 ? 0 : diff;
  }

  function firmaDe(lista) {
    var partes = [];
    for (var i = 0; i < lista.length; i++) {
      partes.push(lista[i].id + ':' + lista[i].estado);
    }
    return partes.join('|');
  }

  // ── Carga y renderizado ────────────────────────────────────
  function loadItems() {
    loadSequence++;
    var sequence = loadSequence;

    fetch('/api/area-items?area_id=' + AREA_ID)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (sequence < renderedSequence) return;
        if (!data.ok) return;
        renderedSequence = sequence;

        // Con una transición en vuelo el servidor va por detrás del estado
        // local: pintar su respuesta devolvería la tarjeta a la columna previa.
        if (pendingCount === 0) {
          items = data.items || [];
          renderBoard();
        }

        var now = new Date();
        if (refreshInfo) {
          refreshInfo.textContent = 'Actualizado ' +
            now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        }
      })
      .catch(function() {
        if (refreshInfo) refreshInfo.textContent = 'Error al cargar';
      });
  }

  function renderBoard() {
    var firma = firmaDe(items);
    if (firma === lastSignature) return;
    lastSignature = firma;

    // Agrupar por ticket_id
    var byTicket = {};
    var ticketOrder = [];
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (!byTicket[it.ticket_id]) {
        byTicket[it.ticket_id] = {
          ticket_id:     it.ticket_id,
          mesa_nombre:   it.mesa_nombre,
          ticket_nombre: it.ticket_nombre,
          enviados:      [],
          prep:          [],
          listos:        []
        };
        ticketOrder.push(it.ticket_id);
      }
      if (it.estado === 'enviado')          byTicket[it.ticket_id].enviados.push(it);
      else if (it.estado === 'en_preparacion') byTicket[it.ticket_id].prep.push(it);
      // Lo entregado por el mesero también vive en Listos: el registro no debe
      // desaparecer del tablero solo porque se lo llevaron de la barra.
      else if (it.estado === 'listo' || it.estado === 'entregado') byTicket[it.ticket_id].listos.push(it);
    }

    var envCards   = [];
    var prepCards  = [];
    var listoCards = [];
    var envCount = 0, prepCount = 0, listoCount = 0;

    for (var ti = 0; ti < ticketOrder.length; ti++) {
      var group = byTicket[ticketOrder[ti]];
      if (group.enviados.length) {
        envCards.push(buildCard(group, group.enviados, 'enviado'));
        envCount += group.enviados.length;
      }
      if (group.prep.length) {
        prepCards.push(buildCard(group, group.prep, 'prep'));
        prepCount += group.prep.length;
      }
      if (group.listos.length) {
        listoCards.push(buildCard(group, group.listos, 'listo'));
        // El contador mide trabajo por recoger, no filas visibles: lo ya
        // entregado no cuenta, así que a propósito no coincide con lo pintado.
        for (var li = 0; li < group.listos.length; li++) {
          if (group.listos[li].estado === 'listo') listoCount++;
        }
      }
    }

    var emptyEnv   = '<div class="area-empty"><span class="area-empty__icon">◌</span><span>Sin pedidos</span></div>';
    var emptyOther = '<div class="area-empty"><span class="area-empty__icon">◌</span><span>—</span></div>';

    listEnv.innerHTML   = envCards.length   ? envCards.join('')   : emptyEnv;
    listPrep.innerHTML  = prepCards.length  ? prepCards.join('')  : emptyOther;
    listListo.innerHTML = listoCards.length ? listoCards.join('') : emptyOther;

    if (countEnv)   countEnv.textContent   = envCount;
    if (countPrep)  countPrep.textContent  = prepCount;
    if (countListo) countListo.textContent = listoCount;
  }

  function buildCard(group, itemList, colType) {
    // La antigüedad se mide sobre lo que sigue pendiente. Con ORDER BY
    // created_at ASC el más viejo suele ser justo el ya entregado, y tomarlo
    // pintaría la tarjeta en rojo por trabajo que ya está hecho.
    var pendiente = null;
    for (var p = 0; p < itemList.length; p++) {
      if (itemList[p].estado !== 'entregado') { pendiente = itemList[p]; break; }
    }

    var min      = minutosDesde((pendiente || itemList[0]).created_at);
    var urgClass = !pendiente ? ''
                 : min >= 10 ? ' area-card--urgente'
                 : min >= 5  ? ' area-card--alerta' : '';

    var mesaTxt = escHtml(group.mesa_nombre);
    var clienteTxt = group.ticket_nombre
      ? ' <span class="area-card__cliente">— ' + escHtml(group.ticket_nombre) + '</span>'
      : '';

    var minTxt = min === 0 ? 'ahora' : 'hace ' + min + ' min';

    var h = '<div class="area-card' + urgClass + '">';
    h += '<div class="area-card__head">';
    h += '<span class="area-card__mesa">' + mesaTxt + clienteTxt + '</span>';
    h += '<span class="area-card__time">' + minTxt + '</span>';
    h += '</div>';
    h += '<div class="area-card__items">';

    for (var i = 0; i < itemList.length; i++) {
      var it       = itemList[i];
      var comLabel = it.comensal !== null ? 'C.' + it.comensal : 'GL';
      // La columna de Listos es mixta, así que el estado se decide por ítem y
      // no por columna: lo entregado ya no admite ninguna acción.
      var entregado  = it.estado === 'entregado';
      var hasBack    = !entregado && (colType === 'prep' || colType === 'listo');
      var hasForward = !entregado && (colType === 'enviado' || colType === 'prep');

      h += '<div class="area-card__item' + (entregado ? ' area-card__item--entregado' : '') + '">';
      var notaHtml = it.nota
        ? '<span class="area-card__nota">' + escHtml(it.nota) + '</span>'
        : '';
      h += '<div class="area-card__item-info">';
      h += '<span class="area-card__qty">×' + it.cantidad + '</span>';
      h += '<div class="area-card__name-wrap">';
      h += '<span class="area-card__name">' + escHtml(it.nombre) + '</span>';
      h += notaHtml;
      h += '</div>';
      h += '<span class="area-card__com">' + comLabel + '</span>';
      h += '</div>';

      if (entregado) {
        h += '<div class="area-card__item-btns">';
        h += '<span class="area-card__badge area-card__badge--entregado">Entregado ✓</span>';
        h += '</div>';
      } else if (hasBack || hasForward) {
        h += '<div class="area-card__item-btns">';
        if (hasBack) {
          h += '<button class="area-card__btn area-card__btn--back" data-id="' +
               it.id + '" data-dir="back">← Devolver</button>';
        }
        if (colType === 'enviado') {
          h += '<button class="area-card__btn area-card__btn--prep" data-id="' +
               it.id + '" data-dir="fwd">→ Prep</button>';
        } else if (colType === 'prep') {
          h += '<button class="area-card__btn area-card__btn--listo" data-id="' +
               it.id + '" data-dir="fwd">✓ Listo</button>';
        }
        h += '</div>';
      }

      h += '</div>';
    }

    h += '</div>';
    h += '</div>';
    return h;
  }

  // ── Avanzar estado ─────────────────────────────────────────
  function aviso(texto) {
    if (window.AppNotice && typeof window.AppNotice.show === 'function') {
      window.AppNotice.show({ text: texto, variant: 'error' });
    }
  }

  function itemPorId(itemId) {
    for (var i = 0; i < items.length; i++) {
      if (parseInt(items[i].id, 10) === itemId) return items[i];
    }
    return null;
  }

  /**
   * Mueve el ítem en el modelo local y repinta antes de que responda el
   * servidor. El tablero se usa con las manos ocupadas: la tarjeta tiene que
   * reaccionar al toque, no medio segundo después.
   */
  function transicionar(itemId, direccion) {
    var item = itemPorId(itemId);
    if (!item || pending[itemId]) return;

    var destino = TRANSICIONES[direccion][item.estado];
    if (!destino) return;

    var estadoPrevio = item.estado;
    item.estado = destino;
    pending[itemId] = true;
    pendingCount++;
    renderBoard();

    fetch(direccion === 'back' ? '/api/retroceder-item' : '/api/avanzar-item', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ item_id: itemId })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (!result.ok) throw new Error(result.mensaje || '');
    })
    .catch(function(error) {
      var vigente = itemPorId(itemId);
      if (vigente) vigente.estado = estadoPrevio;
      aviso((error && error.message) || 'No se pudo cambiar el estado del platillo.');
    })
    .then(function() {
      delete pending[itemId];
      pendingCount = Math.max(0, pendingCount - 1);
      renderBoard();
      if (pendingCount === 0) loadItems();
    });
  }

  // Delegación: los listeners viven en las columnas, que nunca se reemplazan.
  // Atarlos a cada botón obligaba a re-atarlos en cada repintado y dejaba
  // huecos donde el click se perdía.
  [listEnv, listPrep, listListo].forEach(function(columna) {
    columna.addEventListener('click', function(event) {
      var btn = event.target.closest('.area-card__btn[data-id]');
      if (!btn || !columna.contains(btn)) return;
      transicionar(parseInt(btn.dataset.id, 10), btn.dataset.dir);
    });
  });

  // ── Init + polling ─────────────────────────────────────────
  loadItems();
  // 3 s en vez de 1 s: con el diff de firma el repintado sólo ocurre cuando
  // algo cambia, y el tablero no necesita resolución de segundo.
  pollTimer = setInterval(loadItems, 3000);
}

(function() {
  function tryInitArea() {
    if (document.body && document.body.dataset.page === 'area') initArea();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryInitArea);
  } else {
    tryInitArea();
  }
})();
