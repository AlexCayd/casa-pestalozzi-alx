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

  // ── Carga y renderizado ────────────────────────────────────
  function loadItems() {
    fetch('/api/area-items?area_id=' + AREA_ID)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) return;
        renderBoard(data.items);
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

  function renderBoard(items) {
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
      else if (it.estado === 'listo')       byTicket[it.ticket_id].listos.push(it);
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
        listoCount += group.listos.length;
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

    bindAvanzar();
  }

  function buildCard(group, itemList, colType) {
    var min     = minutosDesde(itemList[0].created_at);
    var urgClass = min >= 10 ? ' area-card--urgente'
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
      var hasBack    = colType === 'prep' || colType === 'listo';
      var hasForward = colType === 'enviado' || colType === 'prep';

      h += '<div class="area-card__item">';
      h += '<div class="area-card__item-info">';
      h += '<span class="area-card__qty">×' + it.cantidad + '</span>';
      h += '<span class="area-card__name">' + escHtml(it.nombre) + '</span>';
      h += '<span class="area-card__com">' + comLabel + '</span>';
      h += '</div>';

      if (hasBack || hasForward) {
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
  function bindAvanzar() {
    var btns = document.querySelectorAll('.area-card__btn[data-id]');
    for (var i = 0; i < btns.length; i++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          btn.disabled = true;
          btn.classList.add('area-card__btn--loading');
          var url = btn.dataset.dir === 'back'
            ? '/api/retroceder-item'
            : '/api/avanzar-item';
          fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ item_id: parseInt(btn.dataset.id, 10) })
          })
          .then(function(r) { return r.json(); })
          .then(function(result) {
            if (result.ok) {
              loadItems();
            } else {
              btn.disabled = false;
              btn.classList.remove('area-card__btn--loading');
            }
          })
          .catch(function() {
            btn.disabled = false;
            btn.classList.remove('area-card__btn--loading');
          });
        });
      })(btns[i]);
    }
  }

  // ── Init + polling ─────────────────────────────────────────
  loadItems();
  pollTimer = setInterval(loadItems, 10000);
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
