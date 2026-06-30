/* ── Mapa de Mesas — Casa Pestalozzi ───────────────────────── */

function initMapa() {
  if (initMapa._done) return;
  initMapa._done = true;

  var canvas = $('#mapa-canvas');
  if (!canvas) return;

  // ── Estado ────────────────────────────────────────────────
  var mesas         = [];
  var reservaciones = [];
  var tickets       = [];
  var commandaItems    = []; // { n, p, area, area_id, categoria, comensal, qty }
  var selectedComensal = 0;  // 0 = General
  var SUGERENCIAS         = []; // se llenan al abrir cada ticket (ver buildModalContent)
  var sugComensalesCount  = 0;
  var sliderMin     = 0;
  var isLive        = false;
  var liveInterval  = null;
  var pollTimer     = null;

  // ── Refs DOM ──────────────────────────────────────────────
  var slider        = $('#mapa-time-slider');
  var sliderProg    = $('#mapa-slider-progress');
  var sliderTip     = $('#mapa-slider-tooltip');
  var fechaInput    = $('#mapa-fecha');
  var reservasList  = $('#mapa-reservas-list');
  var ahoraBtn      = $('#mapa-ahora-btn');
  var currentTimeEl = $('#mapa-current-time');
  var liveBadge     = $('#mapa-live-badge');
  var reservaCount  = $('#mapa-reserva-count');
  var loadingEl     = $('#mapa-loading');
  var modal         = $('#mesa-modal');
  var modalContent  = $('#mesa-modal-content');
  var modalBd       = $('#mesa-modal-bd');
  var modalClose    = $('#mesa-modal-close');

  var DURACION = 90;
  var BLOQUEO  = 30;

  // Labels de estado temporal para el sidebar
  var TEMPORAL_LABELS = {
    'vencida':  'Vencida',
    'en-curso': 'En curso',
    'proxima':  'Próxima',
    'cancelada':'Cancelada'
  };

  // ── Helpers ───────────────────────────────────────────────
  function minutos(hora) {
    var p = hora.split(':');
    return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
  }

  function formatTime(min) {
    var h = Math.floor(min / 60);
    var m = min % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }

  function snapTo30(min) { return Math.round(min / 30) * 30; }

  function mesaPorId(id) {
    for (var i = 0; i < mesas.length; i++) {
      if (mesas[i].id === id) return mesas[i];
    }
    return null;
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Pool de sugerencias (aleatorio desde el menú, de momento) ──
  function buildSuggestionPool() {
    var pool = [];
    if (!window.CP_MENU) return pool;
    for (var ci = 0; ci < window.CP_MENU.length; ci++) {
      var cat = window.CP_MENU[ci];
      for (var di = 0; di < cat.dishes.length; di++) {
        var dish     = cat.dishes[di];
        var areaSlug = dish.area || 'cocina';
        var areaInfo = window.CP_AREAS ? window.CP_AREAS[areaSlug] : null;
        pool.push({
          n: dish.n, p: dish.p, area: areaSlug,
          area_id: areaInfo ? areaInfo.id : 3,
          categoria: cat.label,
          areaNombre: areaInfo ? areaInfo.label : ''
        });
      }
    }
    return pool;
  }

  function pickRandomSuggestion(excludeNames) {
    var pool = buildSuggestionPool().filter(function(s) {
      return excludeNames.indexOf(s.n) === -1;
    });
    if (!pool.length) return null;
    return pool[Math.floor(Math.random() * pool.length)];
  }

  // Estado temporal de una reserva respecto al slider
  function temporalEstadoReserva(r) {
    if (r.estado === 'cancelada') return 'cancelada';
    var rMin = minutos(r.hora);
    if (sliderMin >= rMin + DURACION)                     return 'vencida';
    if (sliderMin >= rMin && sliderMin < rMin + DURACION) return 'en-curso';
    if (sliderMin >= rMin - BLOQUEO && sliderMin < rMin)  return 'proxima';
    return 'futura';
  }

  // ── Lookups de ticket y reserva ───────────────────────────
  function isLlevar(mesa) {
    return mesa && mesa.tipo === 'especial' && mesa.nombre === 'Llevar';
  }

  function ticketActual(mesaId) {
    for (var i = 0; i < tickets.length; i++) {
      var t = tickets[i];
      if (t.mesa_id === mesaId || t.mesa_secundaria_id === mesaId) return t;
    }
    return null;
  }

  function ticketsParaMesa(mesaId) {
    var result = [];
    for (var i = 0; i < tickets.length; i++) {
      var t = tickets[i];
      if (t.mesa_id === mesaId || t.mesa_secundaria_id === mesaId) result.push(t);
    }
    return result;
  }

  function reservaParaModal(mesaId) {
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (r.estado === 'cancelada') continue;
      if (r.mesa_id !== mesaId && r.mesa_secundaria_id !== mesaId) continue;
      return r;
    }
    return null;
  }

  // ── Estado de mesa según slider (incluye tickets abiertos) ─
  function estadoMesa(mesaId, minActual) {
    if (ticketActual(mesaId)) return 'con-ticket';
    var estado = 'libre';
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (r.estado === 'cancelada') continue;
      if (r.mesa_id !== mesaId && r.mesa_secundaria_id !== mesaId) continue;
      var rMin = minutos(r.hora);
      var rIni = rMin - BLOQUEO;
      var rFin = rMin + DURACION;
      if (minActual >= rMin && minActual < rFin) return 'ocupada';
      if (minActual >= rIni && minActual < rMin) return 'bloqueada';
      if (minActual >= rIni - 30 && minActual < rIni && estado === 'libre') estado = 'proxima';
    }
    return estado;
  }

  // Estado con tiempo real (para el modal)
  function estadoMesaActual(mesaId) {
    var mesa = mesaPorId(mesaId);
    if (!isLlevar(mesa) && ticketActual(mesaId)) return 'con-ticket';
    var now    = new Date();
    var minNow = now.getHours() * 60 + now.getMinutes();
    var estado = 'libre';
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (r.estado === 'cancelada') continue;
      if (r.mesa_id !== mesaId && r.mesa_secundaria_id !== mesaId) continue;
      var rMin = minutos(r.hora);
      var rIni = rMin - BLOQUEO;
      var rFin = rMin + DURACION;
      if (minNow >= rMin && minNow < rFin) return 'ocupada';
      if (minNow >= rIni && minNow < rMin) return 'bloqueada';
      if (minNow >= rIni - 30 && minNow < rIni && estado === 'libre') estado = 'proxima';
    }
    return estado;
  }

  // ── Render: estados de todos los pines ────────────────────
  function renderEstados() {
    var pins = $$('.mesa-pin[data-id]', canvas);
    for (var i = 0; i < pins.length; i++) {
      var pin = pins[i];
      if (pin.dataset.ticketable !== '1') continue;
      var id     = parseInt(pin.dataset.id, 10);
      var estado = estadoMesa(id, sliderMin);
      pin.classList.remove(
        'mesa-pin--libre', 'mesa-pin--proxima', 'mesa-pin--bloqueada',
        'mesa-pin--ocupada', 'mesa-pin--con-ticket'
      );
      pin.classList.add('mesa-pin--' + estado);
      pin.dataset.estado = estado;
    }
  }

  // ── Render: pines en el canvas ────────────────────────────
  function renderMesas() {
    canvas.innerHTML = '';
    for (var i = 0; i < mesas.length; i++) {
      var m   = mesas[i];
      var ticketable = m.reservable || m.tipo === 'barra'
                       || (m.tipo === 'especial' && m.nombre !== 'Caja');
      var pin = document.createElement('div');
      pin.className = 'mesa-pin mesa-pin--tipo-' + m.tipo;
      pin.className += m.reservable ? ' mesa-pin--libre' : ' mesa-pin--zona';
      pin.dataset.id         = m.id;
      pin.dataset.numero     = m.numero;
      pin.dataset.reservable = m.reservable;
      pin.dataset.ticketable = ticketable ? '1' : '0';
      pin.style.left = m.pos_x + '%';
      pin.style.top  = m.pos_y + '%';
      pin.title      = m.nombre + (m.capacidad ? ' · Cap. ' + m.capacidad : '');
      var label = document.createElement('span');
      label.className   = 'mesa-pin__label';
      label.textContent = m.nombre;
      pin.appendChild(label);
      if (ticketable) {
        (function(mesaId) {
          pin.addEventListener('click', function() { onMesaClick(mesaId); });
        })(m.id);
      }
      canvas.appendChild(pin);
    }
  }

  // ── Render: sidebar de reservaciones ──────────────────────
  function renderSidebar() {
    if (!reservaciones.length) {
      reservasList.innerHTML =
        '<div class="mapa-empty-state">' +
          '<span class="mapa-empty-icon">◌</span>' +
          '<span>Sin reservaciones para este día</span>' +
        '</div>';
      reservaCount.textContent = '0';
      return;
    }
    reservaCount.textContent = reservaciones.length;
    reservasList.innerHTML   = '';
    for (var i = 0; i < reservaciones.length; i++) {
      var r          = reservaciones[i];
      var tempEstado = temporalEstadoReserva(r);
      var card       = document.createElement('div');
      card.className  = 'reserva-card';
      card.dataset.id = r.id;

      if (r.estado === 'cancelada')       card.classList.add('reserva-card--pasada');
      else if (tempEstado === 'vencida')  card.classList.add('reserva-card--pasada');
      else if (tempEstado === 'en-curso') card.classList.add('reserva-card--activa');
      else if (tempEstado === 'proxima')  card.classList.add('reserva-card--proxima');

      var mesaNombre = '';
      if (r.mesa_id) {
        var m1 = mesaPorId(r.mesa_id);
        if (m1) mesaNombre = m1.nombre;
        if (r.mesa_secundaria_id) {
          var m2 = mesaPorId(r.mesa_secundaria_id);
          if (m2) mesaNombre += ' + ' + m2.nombre;
        }
      }

      var temporalBadge = TEMPORAL_LABELS[tempEstado]
        ? '<span class="reserva-card__temporal reserva-card__temporal--' + tempEstado + '">' +
          TEMPORAL_LABELS[tempEstado] + '</span>'
        : '';

      card.innerHTML =
        '<div class="reserva-card__hora">' + r.hora.substring(0, 5) + '</div>' +
        '<div class="reserva-card__info">' +
          '<span class="reserva-card__nombre">' + escHtml(r.nombre) + '</span>' +
          '<span class="reserva-card__meta">' +
            '<span class="reserva-card__comensales">&#x1F465; ' + r.comensales + '</span>' +
            (mesaNombre ? '<span class="reserva-card__mesa">' + escHtml(mesaNombre) + '</span>' : '') +
          '</span>' +
          temporalBadge +
        '</div>' +
        '<div class="reserva-card__estado reserva-card__estado--' + r.estado + '">' + r.estado + '</div>';

      (function(rid, mid, mid2) {
        card.addEventListener('click', function() { onCardClick(rid, mid, mid2); });
      })(r.id, r.mesa_id, r.mesa_secundaria_id);
      reservasList.appendChild(card);
    }
  }

  // ── Click en canvas / sidebar ─────────────────────────────
  function clearHighlight() {
    var pins  = $$('.mesa-pin', canvas);
    var cards = $$('.reserva-card', reservasList);
    for (var i = 0; i < pins.length;  i++) pins[i].classList.remove('mesa-pin--highlight');
    for (var i = 0; i < cards.length; i++) cards[i].classList.remove('reserva-card--selected');
  }

  function onMesaClick(mesaId) {
    var mesa = mesaPorId(mesaId);
    var canOpen = mesa && (mesa.reservable || mesa.tipo === 'barra'
                  || (mesa.tipo === 'especial' && mesa.nombre !== 'Caja'));
    if (!canOpen) return;
    if (isLlevar(mesa)) {
      showLlevarModal(mesa);
    } else {
      showModal(mesa, estadoMesaActual(mesaId));
    }
  }

  // ── Modal Llevar ──────────────────────────────────────────
  function showLlevarModal(mesa) {
    if (!modal || !modalContent) return;
    var llevarTickets = ticketsParaMesa(mesa.id);
    commandaItems    = [];
    selectedComensal = 0;
    if (llevarTickets.length === 0) {
      modalContent.innerHTML = buildModalContent(mesa, 'libre', null, null);
    } else {
      modalContent.innerHTML = buildLlevarList(mesa, llevarTickets);
    }
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';
    if (llevarTickets.length === 0) {
      bindModalActions(mesa, null, null);
    } else {
      bindLlevarList(mesa, llevarTickets);
    }
  }

  function buildLlevarList(mesa, llevarTickets) {
    var h = '';
    h += '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">Pedidos para Llevar</span>';
    h += '<span class="mmodal-llevar-count">' + llevarTickets.length + ' activos</span>';
    h += '</div></div>';

    h += '<div class="mmodal-llevar-list">';
    for (var i = 0; i < llevarTickets.length; i++) {
      var t = llevarTickets[i];
      var horaAp = t.hora_apertura ? String(t.hora_apertura).substring(11, 16) : '--:--';
      var nombreLabel = t.nombre ? escHtml(t.nombre) : '<em style="opacity:.55">Sin nombre</em>';
      h += '<div class="mmodal-llevar-row" data-tid="' + t.id + '">';
      h += '<div class="mmodal-llevar-row__info">';
      h += '<span class="mmodal-llevar-row__nombre">' + nombreLabel + '</span>';
      h += '<span class="mmodal-llevar-row__meta">🕐 ' + horaAp + ' &nbsp;·&nbsp; 👥 ' + t.comensales + '</span>';
      h += '</div>';
      h += '<span class="mmodal-llevar-row__arrow">→</span>';
      h += '</div>';
    }
    h += '</div>';

    h += '<div class="mmodal-actions">';
    h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-llevar-nuevo">+ Nuevo pedido</button>';
    h += '</div>';
    return h;
  }

  function bindLlevarList(mesa, llevarTickets) {
    var rows = modalContent.querySelectorAll('.mmodal-llevar-row[data-tid]');
    for (var i = 0; i < rows.length; i++) {
      (function(row) {
        row.addEventListener('click', function() {
          var tid = parseInt(row.dataset.tid, 10);
          var ticket = null;
          for (var j = 0; j < llevarTickets.length; j++) {
            if (llevarTickets[j].id === tid) { ticket = llevarTickets[j]; break; }
          }
          if (!ticket) return;
          commandaItems    = [];
          selectedComensal = 0;
          modalContent.innerHTML = buildModalContent(mesa, 'con-ticket', null, ticket);
          bindModalActions(mesa, null, ticket);
        });
      })(rows[i]);
    }

    var nuevoBtn = modalContent.querySelector('#mmodal-llevar-nuevo');
    if (nuevoBtn) {
      nuevoBtn.addEventListener('click', function() {
        commandaItems    = [];
        selectedComensal = 0;
        modalContent.innerHTML = buildModalContent(mesa, 'libre', null, null);
        bindModalActions(mesa, null, null);
      });
    }
  }

  function onCardClick(reservaId, mesaId, mesa2Id) {
    clearHighlight();
    highlightReserva(reservaId, mesaId, mesa2Id);
  }

  function highlightReserva(reservaId, mesaId, mesa2Id) {
    var card = reservasList.querySelector('[data-id="' + reservaId + '"]');
    if (card) {
      card.classList.add('reserva-card--selected');
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (mesaId) {
      var pin = canvas.querySelector('[data-id="' + mesaId + '"]');
      if (pin) pin.classList.add('mesa-pin--highlight');
    }
    if (mesa2Id) {
      var pin2 = canvas.querySelector('[data-id="' + mesa2Id + '"]');
      if (pin2) pin2.classList.add('mesa-pin--highlight');
    }
  }

  // ── Modal ─────────────────────────────────────────────────
  function showModal(mesa, estado) {
    if (!modal || !modalContent) return;
    commandaItems    = [];
    selectedComensal = 0;
    var reserva = reservaParaModal(mesa.id);
    var ticket  = ticketActual(mesa.id);
    modalContent.innerHTML = buildModalContent(mesa, estado, reserva, ticket);
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';
    bindModalActions(mesa, reserva, ticket);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('mesa-modal--open');
    document.body.style.overflow = '';
  }

  function buildModalContent(mesa, estado, reserva, ticket) {
    var h = '';

    // Header: nombre de mesa + nombre del cliente agrupados a la izquierda
    h += '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(mesa.nombre) + '</span>';
    if (ticket && ticket.nombre) {
      h += '<span class="mmodal-title-cliente">— ' + escHtml(ticket.nombre) + '</span>';
    }
    h += '</div></div>';

    if (estado === 'con-ticket' && ticket) {
      // ── Vista de ticket abierto con sistema de comandas ────

      // Poblar sugerencias aleatorias (2 ítems) para este ticket
      sugComensalesCount = ticket.comensales;
      SUGERENCIAS = [];
      var sugUsed = [];
      var sgPick = pickRandomSuggestion(sugUsed);
      if (sgPick) { SUGERENCIAS.push(sgPick); }

      var horaAp = ticket.hora_apertura
        ? String(ticket.hora_apertura).substring(11, 16)
        : '--:--';

      h += '<div class="mmodal-ticket-meta">';
      h += '<span>👥 ' + ticket.comensales + ' com.</span>';
      h += '<span>🕐 ' + horaAp + '</span>';
      h += '</div>';

      // Tabs principales (mobile: 3 tabs; desktop: ocultos)
      h += '<div class="mmodal-tabs" id="mmodal-tabs">';
      h += '<button class="mmodal-tab mmodal-tab--active" data-tab="menu">Menú</button>';
      h += '<button class="mmodal-tab" data-tab="cart">Pedido';
      h += ' <span class="mmodal-tab-badge" id="mmodal-cart-badge" style="display:none">0</span>';
      h += '</button>';
      h += '<button class="mmodal-tab" data-tab="resumen">Ticket';
      h += ' <span class="mmodal-tab-badge" id="mmodal-resumen-badge" style="display:none">0</span>';
      h += '</button>';
      h += '<button class="mmodal-tab" data-tab="sugerencias">Sugerencias</button>';
      h += '</div>';

      // ── Panels wrapper (3 cols en desktop) ────────────────
      h += '<div class="mmodal-panels">';

      // ── Panel 1: Menú ──────────────────────────────────────
      h += '<div id="mmodal-panel-menu" class="mmodal-tab-panel mmodal-tab-panel--active">';
      h += '<div class="mmodal-panel-label">Menú</div>';
      h += '<div class="mmodal-panel-scroll">';

      // Bloques de comensal (grid)
      h += '<div class="mmodal-section-label">Comensal</div>';
      h += '<div class="mmodal-comensal-grid" id="mmodal-comensales">';
      h += '<button class="mmodal-comensal-block mmodal-comensal-block--active" data-c="0">Gral</button>';
      for (var ci = 1; ci <= ticket.comensales; ci++) {
        h += '<button class="mmodal-comensal-block" data-c="' + ci + '">C.' + ci + '</button>';
      }
      h += '</div>';

      // Bloques de categoría (grid)
      h += '<div class="mmodal-section-label">Categoría</div>';
      h += '<div class="mmodal-cat-grid" id="mmodal-cats">';
      if (window.CP_MENU && window.CP_MENU.length) {
        for (var mi = 0; mi < window.CP_MENU.length; mi++) {
          var mcat = window.CP_MENU[mi];
          h += '<button class="mmodal-cat-block' + (mi === 0 ? ' mmodal-cat-block--active' : '') +
               '" data-idx="' + mi + '">' + escHtml(mcat.label) + '</button>';
        }
      }
      h += '</div>';
      h += '<div class="mmodal-dishes" id="mmodal-dishes"></div>';

      h += '</div>'; // fin panel-scroll
      h += '</div>'; // fin panel-menu

      // ── Panel 2: Pedido (carrito staging) ─────────────────
      h += '<div id="mmodal-panel-cart" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Pedido</div>';
      h += '<div class="mmodal-panel-scroll">';
      h += '<div class="mmodal-cart" id="mmodal-cart">';
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">☰</span>' +
           '<span>Selecciona platos en el Menú</span></div>';
      h += '</div>';
      h += '</div>'; // fin panel-scroll
      h += '<div class="mmodal-panel-actions">';
      h += '<div class="mmodal-total-row" id="mmodal-cart-total" style="display:none">';
      h += '<span class="mmodal-total-label">Total</span>';
      h += '<span class="mmodal-total-amount" id="mmodal-total-val">$0</span>';
      h += '</div>';
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-enviar" disabled>';
      h += 'Confirmar y enviar (0) →';
      h += '</button>';
      h += '</div>'; // fin panel-actions
      h += '</div>'; // fin panel-cart

      // ── Panel 3: Estado del ticket ─────────────────────────
      h += '<div id="mmodal-panel-resumen" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Estado del ticket</div>';
      h += '<div class="mmodal-panel-scroll">';
      h += '<div id="mmodal-resumen-content">';
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">◎</span>' +
           '<span>Sin comandas enviadas aún</span></div>';
      h += '</div>';
      h += '</div>'; // fin panel-scroll
      h += '<div class="mmodal-panel-actions">';
      h += '<button class="mmodal-btn mmodal-btn--danger" id="mmodal-cerrar">Cerrar ticket</button>';
      h += '</div>'; // fin panel-actions
      h += '</div>'; // fin panel-resumen

      // ── Panel 4: Sugerencias ─────────────────────────────────
      h += '<div id="mmodal-panel-sugerencias" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Sugerencias</div>';
      h += '<div class="mmodal-panel-scroll" id="mmodal-sug-list">';
      for (var si = 0; si < SUGERENCIAS.length; si++) {
        h += buildSuggestionCardHtml(si, SUGERENCIAS[si]);
      }
      h += '</div>'; // fin panel-scroll
      h += '</div>'; // fin panel-sugerencias

      h += '</div>'; // fin mmodal-panels

    } else if (reserva) {
      // ── Vista de reservación activa ────────────────────────
      var chipLabel = estado === 'bloqueada' ? '¡Próxima a llegar!'
        : estado === 'ocupada'              ? 'En ventana de reserva'
        : estado === 'proxima'              ? 'Próxima reservación'
        : 'Mesa reservada';

      h += '<div class="mmodal-reserva-grid">';

      // Col 1 — cliente
      h += '<div class="mmodal-reserva-col">';
      h += '<div class="mmodal-reserva-col-head">';
      h += '<span class="mmodal-reserva-col__label">Cliente</span>';
      h += '<span class="mmodal-chip mmodal-chip--' + estado + '">' + chipLabel + '</span>';
      h += '</div>';
      h += '<div class="mmodal-reserva-nombre">' + escHtml(reserva.nombre) + '</div>';
      h += '</div>';

      // Col 2 — detalles
      h += '<div class="mmodal-reserva-col">';
      h += '<span class="mmodal-reserva-col__label">Reservación</span>';
      h += '<div class="mmodal-reserva-stats">';
      h += '<div class="mmodal-reserva-stat mmodal-reserva-stat--hora"><span class="mmodal-reserva-stat__label">Hora</span>' +
           '<span class="mmodal-reserva-stat__val">' + reserva.hora.substring(0, 5) + '</span></div>';
      h += '<div class="mmodal-reserva-stat mmodal-reserva-stat--pax"><span class="mmodal-reserva-stat__label">Comensales</span>' +
           '<span class="mmodal-reserva-stat__val">' + reserva.comensales + '</span></div>';
      h += '</div>';
      if (reserva.nota) {
        h += '<div class="mmodal-reserva-nota"><span class="mmodal-reserva-nota__label">Nota</span>' + escHtml(reserva.nota) + '</div>';
      }
      h += '</div>';

      // Col 3 — acciones
      h += '<div class="mmodal-reserva-col mmodal-reserva-col--actions">';
      h += '<span class="mmodal-reserva-col__label">Acción</span>';
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-confirmar">✓ Confirmar llegada</button>';
      h += '<button class="mmodal-btn mmodal-btn--ghost" id="mmodal-liberar">Liberar mesa</button>';
      h += '</div>';

      h += '</div>';

    } else {
      // ── Vista de mesa libre: abrir ticket ─────────────────
      var defaultCom = isLlevar(mesa) ? 1 : 2;
      h += '<div class="mmodal-name-wrap">';
      h += '<div class="mmodal-label">Nombre</div>';
      h += '<input type="text" class="mmodal-name-input" id="mmodal-nombre"';
      h += ' placeholder="Nombre del comensal" autocomplete="off" maxlength="80">';
      h += '</div>';
      h += '<div class="mmodal-stepper-wrap">';
      h += '<div class="mmodal-label">Comensales</div>';
      h += '<div class="mmodal-stepper">';
      h += '<button class="mmodal-step" id="mmodal-dec">−</button>';
      h += '<span class="mmodal-step-val" id="mmodal-cval">' + defaultCom + '</span>';
      h += '<button class="mmodal-step" id="mmodal-inc">+</button>';
      h += '</div>';
      h += '</div>';
      h += '<div class="mmodal-actions">';
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-abrir">Abrir ticket</button>';
      h += '</div>';
    }

    return h;
  }

  // ── Sistema de productos en ticket ────────────────────────
  function renderCategoryDishes(cat) {
    var dishesEl = modalContent.querySelector('#mmodal-dishes');
    if (!dishesEl || !cat || !cat.dishes) return;
    dishesEl.innerHTML = '';
    for (var i = 0; i < cat.dishes.length; i++) {
      var dish     = cat.dishes[i];
      var areaInfo = (window.CP_AREAS && dish.area) ? window.CP_AREAS[dish.area] : null;
      var row      = document.createElement('div');
      row.className = 'mmodal-dish-row';

      var areaHtml = '';
      if (areaInfo) {
        areaHtml = '<span class="mmodal-area-badge" style="background:' + areaInfo.color + '22;' +
                   'color:' + areaInfo.color + ';border-color:' + areaInfo.color + '55">' +
                   escHtml(areaInfo.label) + '</span>';
      }

      row.innerHTML =
        '<div class="mmodal-dish-info">' +
          '<span class="mmodal-dish-name">' + escHtml(dish.n) + '</span>' +
          areaHtml +
        '</div>' +
        '<span class="mmodal-dish-price">$' + dish.p + '</span>';

      var addBtn = document.createElement('button');
      addBtn.className   = 'mmodal-dish-add';
      addBtn.textContent = '+';
      addBtn.setAttribute('aria-label', 'Agregar ' + dish.n);
      (function(d, catLabel) {
        addBtn.addEventListener('click', function() {
          addToComanda(d.n, d.p, d.area || 'cocina', catLabel);
        });
      })(dish, cat.label);
      row.appendChild(addBtn);
      dishesEl.appendChild(row);
    }
  }

  function addToComanda(name, price, areaSlug, categoria) {
    var areaId = (window.CP_AREAS && window.CP_AREAS[areaSlug])
                 ? window.CP_AREAS[areaSlug].id : 3;
    var found = false;
    for (var i = 0; i < commandaItems.length; i++) {
      var ci = commandaItems[i];
      if (ci.n === name && ci.comensal === selectedComensal) {
        ci.qty++;
        found = true;
        break;
      }
    }
    if (!found) {
      commandaItems.push({
        n: name, p: price, area: areaSlug, area_id: areaId,
        categoria: categoria, comensal: selectedComensal, qty: 1, nota: ''
      });
    }
    renderComandaCart();
    updateEnviarBtn();
  }

  function addSuggestionItem(sug, comensal) {
    for (var i = 0; i < commandaItems.length; i++) {
      if (commandaItems[i].n === sug.n && commandaItems[i].comensal === comensal) {
        commandaItems[i].qty++;
        renderComandaCart();
        return;
      }
    }
    commandaItems.push({
      n: sug.n, p: sug.p, area: sug.area, area_id: sug.area_id,
      categoria: sug.categoria, comensal: comensal, qty: 1, nota: ''
    });
    renderComandaCart();
  }

  function removeSuggestionItem(nombre, comensal) {
    for (var i = 0; i < commandaItems.length; i++) {
      if (commandaItems[i].n === nombre && commandaItems[i].comensal === comensal) {
        commandaItems.splice(i, 1);
        renderComandaCart();
        return;
      }
    }
  }

  function buildSuggestionCardHtml(idx, sug) {
    var h = '<div class="mmodal-sug-card" data-sug-card="' + idx + '">';
    h += '<div class="mmodal-sug-header">';
    h += '<span class="mmodal-sug-name">' + escHtml(sug.n) + '</span>';
    h += '<span class="mmodal-sug-price">$' + sug.p + '</span>';
    h += '</div>';
    h += '<div class="mmodal-sug-area">' + escHtml(sug.areaNombre) + '</div>';
    h += '<div class="mmodal-sug-divider"></div>';
    h += '<div class="mmodal-sug-question">¿Quién acepta?</div>';
    h += '<div class="mmodal-sug-chips">';
    h += '<button class="mmodal-sug-chip" data-sug="' + idx + '" data-c="0">Gral</button>';
    for (var sc = 1; sc <= sugComensalesCount; sc++) {
      h += '<button class="mmodal-sug-chip" data-sug="' + idx + '" data-c="' + sc + '">C.' + sc + '</button>';
    }
    h += '</div>';
    h += '<div class="mmodal-sug-footer">';
    h += '<button class="mmodal-sug-swap" data-swap="' + idx + '">↻ Otra sugerencia</button>';
    h += '</div>';
    h += '</div>';
    return h;
  }

  function swapSuggestion(idx) {
    var oldSug = SUGERENCIAS[idx];
    // Limpiar del carrito si se había aceptado antes
    for (var i = commandaItems.length - 1; i >= 0; i--) {
      if (commandaItems[i].n === oldSug.n) commandaItems.splice(i, 1);
    }
    var used  = SUGERENCIAS.map(function(s) { return s.n; });
    var nueva = pickRandomSuggestion(used);
    if (!nueva) return;
    SUGERENCIAS[idx] = nueva;

    var card = modalContent && modalContent.querySelector('.mmodal-sug-card[data-sug-card="' + idx + '"]');
    if (card) {
      var tmp = document.createElement('div');
      tmp.innerHTML = buildSuggestionCardHtml(idx, nueva);
      card.parentNode.replaceChild(tmp.firstChild, card);
      bindSuggestionCard(idx);
    }
    renderComandaCart();
    updateEnviarBtn();
  }

  function bindSuggestionCard(idx) {
    var card = modalContent && modalContent.querySelector('.mmodal-sug-card[data-sug-card="' + idx + '"]');
    if (!card) return;

    var chips = card.querySelectorAll('.mmodal-sug-chip');
    for (var c = 0; c < chips.length; c++) {
      (function(chip) {
        chip.addEventListener('click', function() {
          var isOn     = chip.classList.contains('mmodal-sug-chip--on');
          var comensal = parseInt(chip.dataset.c, 10);
          var sug      = SUGERENCIAS[idx];
          if (isOn) {
            chip.classList.remove('mmodal-sug-chip--on');
            removeSuggestionItem(sug.n, comensal);
          } else {
            chip.classList.add('mmodal-sug-chip--on');
            addSuggestionItem(sug, comensal);
          }
          updateEnviarBtn();
        });
      })(chips[c]);
    }

    var swapBtn = card.querySelector('.mmodal-sug-swap');
    if (swapBtn) {
      (function(btn) {
        btn.addEventListener('click', function() {
          swapSuggestion(idx);
        });
      })(swapBtn);
    }
  }

  function renderComandaCart() {
    var cartEl = modalContent.querySelector('#mmodal-cart');
    if (!cartEl) return;

    if (!commandaItems.length) {
      cartEl.innerHTML = '<div class="mmodal-cart-empty">Sin productos</div>';
      return;
    }

    cartEl.innerHTML = '';
    for (var i = 0; i < commandaItems.length; i++) {
      (function(item, idx) {
        var comLabel = item.comensal === 0 ? 'G' : 'C.' + item.comensal;
        var row = document.createElement('div');
        row.className = 'mmodal-cart-row';
        row.innerHTML =
          '<span class="mmodal-cart-name">' + escHtml(item.n) + '</span>' +
          '<span class="mmodal-cart-comensal">' + comLabel + '</span>' +
          '<div class="mmodal-cart-controls">' +
            '<button data-idx="' + idx + '" data-op="dec">−</button>' +
            '<span>' + item.qty + '</span>' +
            '<button data-idx="' + idx + '" data-op="inc">+</button>' +
          '</div>' +
          '<span class="mmodal-cart-subtotal">$' + (item.p * item.qty) + '</span>';

        // Nota toggle
        var noteToggle = document.createElement('button');
        noteToggle.className = 'mmodal-cart-nota-btn' + (item.nota ? ' mmodal-cart-nota-btn--active' : '');
        noteToggle.type = 'button';
        noteToggle.textContent = item.nota ? '✎ Nota ✓' : '✎ Nota';
        row.appendChild(noteToggle);

        var noteWrap = document.createElement('div');
        noteWrap.className = 'mmodal-cart-nota-wrap';
        if (!item.nota) noteWrap.style.display = 'none';
        var noteTA = document.createElement('textarea');
        noteTA.className = 'mmodal-cart-nota-input';
        noteTA.placeholder = 'Ej: sin cebolla, término medio…';
        noteTA.maxLength = 280;
        noteTA.rows = 2;
        noteTA.value = item.nota || '';
        (function(capturedIdx, ta, toggle) {
          ta.addEventListener('input', function() { commandaItems[capturedIdx].nota = ta.value; });
          toggle.addEventListener('click', function() {
            var open = noteWrap.style.display !== 'none';
            noteWrap.style.display = open ? 'none' : 'block';
            toggle.textContent = !open ? '✎ Nota ↑' : (commandaItems[capturedIdx].nota ? '✎ Nota ✓' : '✎ Nota');
          });
        })(idx, noteTA, noteToggle);
        noteWrap.appendChild(noteTA);
        row.appendChild(noteWrap);

        cartEl.appendChild(row);
      })(commandaItems[i], i);
    }

    // Rebind +/- en carrito
    var ctrlBtns = cartEl.querySelectorAll('button[data-op]');
    for (var j = 0; j < ctrlBtns.length; j++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var idx = parseInt(btn.dataset.idx, 10);
          if (btn.dataset.op === 'dec') {
            if (commandaItems[idx].qty > 1) commandaItems[idx].qty--;
            else commandaItems.splice(idx, 1);
          } else {
            commandaItems[idx].qty++;
          }
          renderComandaCart();
          updateEnviarBtn();
        });
      })(ctrlBtns[j]);
    }
  }

  function updateEnviarBtn() {
    var btn      = modalContent.querySelector('#mmodal-enviar');
    var badge    = modalContent.querySelector('#mmodal-cart-badge');
    var totalRow = modalContent.querySelector('#mmodal-cart-total');
    var totalVal = modalContent.querySelector('#mmodal-total-val');
    var total = 0, amount = 0;
    for (var i = 0; i < commandaItems.length; i++) {
      total  += commandaItems[i].qty;
      amount += commandaItems[i].p * commandaItems[i].qty;
    }
    if (btn) {
      btn.textContent = 'Confirmar y enviar (' + total + ') →';
      btn.disabled    = (total === 0);
    }
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? 'inline' : 'none'; }
    if (totalRow) totalRow.style.display = total > 0 ? 'flex' : 'none';
    if (totalVal) totalVal.textContent = '$' + amount;
  }

  function renderResumen(ticketId) {
    var resumenEl = modalContent.querySelector('#mmodal-resumen-content');
    if (!resumenEl) return;
    resumenEl.innerHTML = '<div class="mmodal-cart-empty">Cargando…</div>';

    fetch('/api/ticket-items?ticket_id=' + ticketId)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok || !data.items || !data.items.length) {
          resumenEl.innerHTML = '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">◎</span><span>Sin comandas enviadas aún</span></div>';
          var badge = modalContent.querySelector('#mmodal-resumen-badge');
          if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
          return;
        }

        // Agrupar por área
        var byArea     = {};
        var grandTotal = 0;
        for (var i = 0; i < data.items.length; i++) {
          var it  = data.items[i];
          var key = it.area_slug;
          if (!byArea[key]) {
            byArea[key] = { label: it.area_nombre, color: it.area_color, items: [] };
          }
          byArea[key].items.push(it);
          if (it.estado !== 'cancelado') grandTotal += it.precio * it.cantidad;
        }

        var html = '';
        for (var slug in byArea) {
          if (!byArea.hasOwnProperty(slug)) continue;
          var ag = byArea[slug];
          html += '<div class="mmodal-confirm-area-header" style="border-left-color:' + ag.color + '">' +
                  escHtml(ag.label) + '</div>';
          for (var j = 0; j < ag.items.length; j++) {
            var row = ag.items[j];
            var statusColor = row.estado === 'cancelado'      ? '#555'
                            : row.estado === 'entregado'      ? '#5ba4cf'
                            : row.estado === 'listo'          ? '#8bbf7e'
                            : row.estado === 'en_preparacion' ? '#e8a920' : '#9a9a9a';
            var statusLabel = row.estado === 'cancelado'      ? 'Cancelado'
                            : row.estado === 'entregado'      ? 'Entregado ✓'
                            : row.estado === 'listo'          ? 'Listo'
                            : row.estado === 'en_preparacion' ? 'En preparación' : 'Enviado';
            var com = row.comensal !== null ? 'C.' + row.comensal : 'Gral';
            var entBtn = row.estado === 'listo'
              ? '<button class="mmodal-entregar-btn" data-id="' + row.id + '">✓ Entregar</button>'
              : '';
            var cancelBtn = (row.estado !== 'entregado' && row.estado !== 'cancelado')
              ? '<button class="mmodal-cancel-btn" data-id="' + row.id +
                '" data-nombre="' + escHtml(row.nombre) + '">×</button>'
              : '';
            var notaHtml = row.nota
              ? '<span class="mmodal-resumen-nota">' + escHtml(row.nota) + '</span>'
              : '';
            var itemClass = row.estado === 'cancelado'
              ? 'mmodal-confirm-item mmodal-confirm-item--cancelado'
              : 'mmodal-confirm-item';
            html += '<div class="' + itemClass + '">' +
                    '<span class="mmodal-status-dot" style="background:' + statusColor + '" title="' + statusLabel + '"></span>' +
                    '<span class="mmodal-confirm-item-name">' + escHtml(row.nombre) +
                    ' <span class="mmodal-cart-comensal">' + com + '</span>' +
                    notaHtml + '</span>' +
                    '<span class="mmodal-confirm-item-qty">\xD7' + row.cantidad + '</span>' +
                    '<span class="mmodal-confirm-item-price">$' + Math.round(row.precio * row.cantidad) + '</span>' +
                    cancelBtn + entBtn +
                    '</div>';
          }
        }

        html += '<div class="mmodal-total-row" style="margin-top:.6rem">' +
                '<span class="mmodal-total-label">Acumulado</span>' +
                '<span class="mmodal-total-amount">$' + grandTotal + '</span>' +
                '</div>';

        resumenEl.innerHTML = html;

        // Bind botones "Entregar"
        var entBtns = resumenEl.querySelectorAll('.mmodal-entregar-btn');
        for (var eb = 0; eb < entBtns.length; eb++) {
          (function(btn) {
            btn.addEventListener('click', function() {
              apiEntregarItem(parseInt(btn.dataset.id, 10), ticketId);
            });
          })(entBtns[eb]);
        }

        // Bind botones "× Cancelar"
        var cancelBtns = resumenEl.querySelectorAll('.mmodal-cancel-btn');
        for (var cb = 0; cb < cancelBtns.length; cb++) {
          (function(btn) {
            btn.addEventListener('click', function() {
              showCancelItemConfirm(parseInt(btn.dataset.id, 10), btn.dataset.nombre, ticketId);
            });
          })(cancelBtns[cb]);
        }

        // Actualizar badge en tab
        var badge = modalContent.querySelector('#mmodal-resumen-badge');
        if (badge) {
          var activeCount = data.items.filter(function(x) { return x.estado !== 'cancelado'; }).length;
          badge.textContent   = activeCount;
          badge.style.display = 'inline';
        }
      })
      .catch(function() {
        resumenEl.innerHTML = '<div class="mmodal-cart-empty mmodal-empty--error">Error al cargar</div>';
      });
  }

  function bindTabsAndComanda(mesa, ticket) {
    // Bloques de categoría (grid)
    var catTabs = modalContent.querySelectorAll('.mmodal-cat-block');
    if (catTabs.length && window.CP_MENU && window.CP_MENU.length) {
      renderCategoryDishes(window.CP_MENU[0]);
      for (var i = 0; i < catTabs.length; i++) {
        (function(tab) {
          tab.addEventListener('click', function() {
            for (var k = 0; k < catTabs.length; k++) catTabs[k].classList.remove('mmodal-cat-block--active');
            tab.classList.add('mmodal-cat-block--active');
            renderCategoryDishes(window.CP_MENU[parseInt(tab.dataset.idx, 10)]);
          });
        })(catTabs[i]);
      }
    }

    // Bloques de comensal (grid)
    var chips = modalContent.querySelectorAll('.mmodal-comensal-block');
    for (var ci = 0; ci < chips.length; ci++) {
      (function(chip) {
        chip.addEventListener('click', function() {
          for (var k = 0; k < chips.length; k++) chips[k].classList.remove('mmodal-comensal-block--active');
          chip.classList.add('mmodal-comensal-block--active');
          selectedComensal = parseInt(chip.dataset.c, 10);
        });
      })(chips[ci]);
    }

    // Tabs principales: Menú / Pedido / Ticket / Sugerencias
    var mainTabs          = modalContent.querySelectorAll('.mmodal-tab');
    var panelMenu         = modalContent.querySelector('#mmodal-panel-menu');
    var panelCart         = modalContent.querySelector('#mmodal-panel-cart');
    var panelResumen      = modalContent.querySelector('#mmodal-panel-resumen');
    var panelSugerencias  = modalContent.querySelector('#mmodal-panel-sugerencias');
    var allPanels         = [panelMenu, panelCart, panelResumen, panelSugerencias];

    function activatePanel(targetTab) {
      for (var k = 0; k < mainTabs.length; k++) mainTabs[k].classList.remove('mmodal-tab--active');
      targetTab.classList.add('mmodal-tab--active');
      for (var p = 0; p < allPanels.length; p++) {
        if (allPanels[p]) allPanels[p].classList.remove('mmodal-tab-panel--active');
      }
      var panel = null;
      if (targetTab.dataset.tab === 'menu')         panel = panelMenu;
      if (targetTab.dataset.tab === 'cart')         panel = panelCart;
      if (targetTab.dataset.tab === 'resumen')      { panel = panelResumen; renderResumen(ticket.id); }
      if (targetTab.dataset.tab === 'sugerencias')  panel = panelSugerencias;
      if (panel) panel.classList.add('mmodal-tab-panel--active');
    }

    for (var ti = 0; ti < mainTabs.length; ti++) {
      (function(tab) {
        tab.addEventListener('click', function() { activatePanel(tab); });
      })(mainTabs[ti]);
    }

    // Botón "Confirmar y enviar" → envío directo (col 2 ya es el preview)
    var enviarBtn = modalContent.querySelector('#mmodal-enviar');
    if (enviarBtn) {
      (function(tid) {
        enviarBtn.addEventListener('click', function() {
          if (commandaItems.length === 0) return;
          apiEnviarComanda(tid);
        });
      })(ticket.id);
    }

    // En desktop las 4 columnas son visibles desde el inicio
    if (window.innerWidth >= 768) {
      renderResumen(ticket.id);
    }

    // Sugerencias: bind inicial de todas las cards
    for (var sgIdx = 0; sgIdx < SUGERENCIAS.length; sgIdx++) {
      bindSuggestionCard(sgIdx);
    }
  }

  // ── Bind de acciones del modal ────────────────────────────
  function bindModalActions(mesa, reserva, ticket) {
    var decBtn = modalContent.querySelector('#mmodal-dec');
    var incBtn = modalContent.querySelector('#mmodal-inc');
    var cval   = modalContent.querySelector('#mmodal-cval');

    if (decBtn && cval) {
      decBtn.addEventListener('click', function() {
        var v = parseInt(cval.textContent, 10);
        if (v > 1) cval.textContent = v - 1;
      });
    }
    if (incBtn && cval) {
      incBtn.addEventListener('click', function() {
        cval.textContent = parseInt(cval.textContent, 10) + 1;
      });
    }

    var abrirBtn = modalContent.querySelector('#mmodal-abrir');
    if (abrirBtn) {
      abrirBtn.addEventListener('click', function() {
        var comensales = cval ? parseInt(cval.textContent, 10) : 2;
        var nombreEl   = modalContent.querySelector('#mmodal-nombre');
        var nombre     = nombreEl ? nombreEl.value.trim() : '';
        if (isLlevar(mesa)) {
          apiAbrirLlevarTicket(mesa, comensales, nombre || null);
        } else {
          apiAbrirTicket(mesa.id, null, comensales, null, nombre || null);
        }
      });
    }

    var confirmarBtn = modalContent.querySelector('#mmodal-confirmar');
    if (confirmarBtn && reserva) {
      confirmarBtn.addEventListener('click', function() {
        apiAbrirTicket(mesa.id, reserva.mesa_secundaria_id, reserva.comensales, reserva.id, reserva.nombre || null);
      });
    }

    var liberarBtn = modalContent.querySelector('#mmodal-liberar');
    if (liberarBtn && reserva) {
      liberarBtn.addEventListener('click', function() {
        if (confirm('¿Cancelar la reservación de ' + reserva.nombre + '?')) {
          apiLiberarReservacion(reserva.id);
        }
      });
    }

    var cerrarBtn = modalContent.querySelector('#mmodal-cerrar');
    if (cerrarBtn && ticket) {
      cerrarBtn.addEventListener('click', function() {
        showCerrarConfirm(mesa, ticket);
      });
    }

    // Si hay bloques de categoría (modal de ticket con productos)
    if (modalContent.querySelector('#mmodal-cats')) {
      bindTabsAndComanda(mesa, ticket);
    }
  }

  // ── Confirmación estilizada de cierre ────────────────────
  function buildCerrarHeader(mesa, ticket) {
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(mesa.nombre) + '</span>';
    if (ticket.nombre) {
      h += '<span class="mmodal-title-cliente">— ' + escHtml(ticket.nombre) + '</span>';
    }
    h += '</div></div>';
    return h;
  }

  function showCerrarConfirm(mesa, ticket) {
    showPagoSelect(mesa, ticket);
  }

  function showPagoSelect(mesa, ticket) {
    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cerrar-confirm">';
    h += '<p class="mmodal-cerrar-confirm__msg">¿Cómo pagó el cliente?</p>';
    h += '<p class="mmodal-cerrar-confirm__sub">Selecciona el método de pago para cerrar el ticket.</p>';
    h += '<div class="mmodal-pago-btns">';
    h += '<button class="mmodal-pago-btn" id="pago-efectivo">';
    h += '<span class="mmodal-pago-btn__icon">💵</span>';
    h += '<span class="mmodal-pago-btn__label">Efectivo</span>';
    h += '</button>';
    h += '<button class="mmodal-pago-btn" id="pago-tarjeta">';
    h += '<span class="mmodal-pago-btn__icon">💳</span>';
    h += '<span class="mmodal-pago-btn__label">Tarjeta</span>';
    h += '</button>';
    h += '</div>';
    h += '<div class="mmodal-cerrar-confirm__btns" style="margin-top:0">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="cc-volver-ticket">← Volver</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    modalContent.querySelector('#pago-efectivo').addEventListener('click', function() {
      showPagoConfirm(mesa, ticket, 'efectivo');
    });
    modalContent.querySelector('#pago-tarjeta').addEventListener('click', function() {
      showPagoConfirm(mesa, ticket, 'tarjeta');
    });
    modalContent.querySelector('#cc-volver-ticket').addEventListener('click', function() {
      commandaItems    = [];
      selectedComensal = 0;
      modalContent.innerHTML = buildModalContent(mesa, 'con-ticket', null, ticket);
      bindModalActions(mesa, null, ticket);
    });
  }

  function showPagoConfirm(mesa, ticket, metodoPago) {
    var label = metodoPago === 'efectivo' ? 'Efectivo' : 'Tarjeta';
    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cerrar-confirm">';
    h += '<div class="mmodal-cerrar-confirm__icon">⚠</div>';
    h += '<p class="mmodal-cerrar-confirm__msg">¿Cerrar el ticket de <strong>' +
         escHtml(mesa.nombre) + '</strong>?</p>';
    h += '<p class="mmodal-cerrar-confirm__sub">Método de pago: <span class="mmodal-pago-badge">' +
         escHtml(label) + '</span></p>';
    h += '<p class="mmodal-cerrar-confirm__sub" style="margin-top:4px">Esta acción no se puede deshacer.</p>';
    h += '<div class="mmodal-cerrar-confirm__btns">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="cc-volver-pago">← Volver</button>';
    h += '<button class="mmodal-btn mmodal-btn--danger" id="cc-confirm">Cerrar ticket</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    modalContent.querySelector('#cc-volver-pago').addEventListener('click', function() {
      showPagoSelect(mesa, ticket);
    });

    (function(tid, mp, m) {
      modalContent.querySelector('#cc-confirm').addEventListener('click', function() {
        apiCerrarTicket(tid, mp, m);
      });
    })(ticket.id, metodoPago, mesa);
  }

  // ── Pantalla QR de feedback ───────────────────────────────
  function showFeedbackQR(token, mesaNombre) {
    var url = window.location.origin + '/feedback?token=' + token;
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(mesaNombre) + '</span>';
    h += '<span class="mmodal-title-cliente">— Ticket cerrado</span>';
    h += '</div></div>';
    h += '<div class="mmodal-feedback-qr">';
    h += '<p class="mmodal-feedback-qr__title">Invita al comensal a dejar su reseña</p>';
    h += '<div class="mmodal-feedback-qr__canvas" id="qr-canvas"></div>';
    h += '<p class="mmodal-feedback-qr__url">' + escHtml(url) + '</p>';
    h += '<div class="mmodal-cerrar-confirm__btns">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="qr-cerrar">Cerrar</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    if (typeof qrcode === 'function') {
      var qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      var qrEl = document.getElementById('qr-canvas');
      if (qrEl) qrEl.innerHTML = qr.createImgTag(5, 8);
    }

    modalContent.querySelector('#qr-cerrar').addEventListener('click', function() {
      closeModal();
      silentRefresh();
    });
  }

  // ── Llamadas API ──────────────────────────────────────────
  function apiPost(url, data) {
    fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(data)
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        closeModal();
        silentRefresh();
      } else {
        alert(result.msg || 'Error al procesar la solicitud');
      }
    })
    .catch(function() { alert('Error de conexión'); });
  }

  function apiAbrirTicket(mesaId, mesa2Id, comensales, reservaId, nombre) {
    apiPost('/api/abrir-ticket', {
      mesa_id: mesaId, mesa2_id: mesa2Id,
      comensales: comensales, reservacion_id: reservaId,
      nombre: nombre || null
    });
  }

  // Abre un ticket de Llevar y va directo al POS sin cerrar el modal
  function apiAbrirLlevarTicket(mesa, comensales, nombre) {
    fetch('/api/abrir-ticket', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        mesa_id:        mesa.id,
        comensales:     comensales,
        nombre:         nombre || null,
        allow_multiple: true
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.ok) {
        var newTicket = {
          id:            result.id,
          mesa_id:       mesa.id,
          nombre:        nombre,
          comensales:    comensales,
          hora_apertura: new Date().toISOString().replace('T', ' ').substring(0, 19)
        };
        silentRefresh();
        commandaItems    = [];
        selectedComensal = 0;
        modalContent.innerHTML = buildModalContent(mesa, 'con-ticket', null, newTicket);
        bindModalActions(mesa, null, newTicket);
      } else {
        alert(result.msg || 'Error al crear el pedido');
      }
    })
    .catch(function() { alert('Error de conexión'); });
  }

  function apiLiberarReservacion(reservaId) {
    apiPost('/api/liberar-reservacion', { reservacion_id: reservaId });
  }

  function apiCerrarTicket(ticketId, metodoPago, mesa) {
    fetch('/api/cerrar-ticket', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ ticket_id: ticketId, metodo_pago: metodoPago })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        showFeedbackQR(result.token, mesa ? mesa.nombre : '');
      } else {
        alert(result.msg || 'Error al cerrar el ticket');
      }
    })
    .catch(function() { alert('Error de conexión'); });
  }

  function apiEnviarComanda(ticketId) {
    var payload = { ticket_id: ticketId, items: [] };
    for (var i = 0; i < commandaItems.length; i++) {
      var ci = commandaItems[i];
      payload.items.push({
        nombre:    ci.n,
        precio:    ci.p,
        categoria: ci.categoria,
        area_id:   ci.area_id,
        comensal:  ci.comensal === 0 ? null : ci.comensal,
        cantidad:  ci.qty,
        nota:      ci.nota && ci.nota.trim() ? ci.nota.trim() : null
      });
    }

    fetch('/api/enviar-comanda', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload)
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        commandaItems = [];
        renderComandaCart();
        updateEnviarBtn();
        // Cambiar a tab Ticket (col 3) para ver el estado
        var tabResumen = modalContent.querySelector('[data-tab="resumen"]');
        if (tabResumen) tabResumen.click();
        // En desktop: refrescar columna 3 directamente
        if (window.innerWidth >= 768) renderResumen(ticketId);
      } else {
        alert(result.msg || 'Error al enviar la comanda');
      }
    })
    .catch(function() { alert('Error de conexión'); });
  }

  function apiEntregarItem(itemId, ticketId) {
    fetch('/api/entregar-item', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ item_id: itemId })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) { if (result.ok) renderResumen(ticketId); });
  }

  function showCancelItemConfirm(itemId, nombre, ticketId) {
    var overlay = document.createElement('div');
    overlay.className = 'mmodal-cancel-confirm-overlay';
    overlay.innerHTML =
      '<div class="mmodal-cancel-confirm">' +
        '<p class="mmodal-cancel-confirm__msg">¿Cancelar <strong>' + escHtml(nombre) + '</strong>?</p>' +
        '<p class="mmodal-cancel-confirm__sub">El área dejará de prepararlo. Esta acción no se puede deshacer.</p>' +
        '<div class="mmodal-cancel-confirm__btns">' +
          '<button class="mmodal-btn mmodal-btn--ghost" id="cc-volver">No, conservar</button>' +
          '<button class="mmodal-btn mmodal-btn--danger" id="cc-confirm">Sí, cancelar</button>' +
        '</div>' +
      '</div>';
    var panel = modalContent.querySelector('#mmodal-panel-resumen');
    if (panel) panel.appendChild(overlay);
    overlay.querySelector('#cc-volver').addEventListener('click', function() { overlay.remove(); });
    overlay.querySelector('#cc-confirm').addEventListener('click', function() {
      overlay.remove();
      apiCancelarItem(itemId, ticketId);
    });
  }

  function apiCancelarItem(itemId, ticketId) {
    fetch('/api/cancelar-item', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ item_id: itemId })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.ok) renderResumen(ticketId);
      else alert(result.msg || 'No se pudo cancelar el platillo');
    })
    .catch(function() { alert('Error de conexión'); });
  }

  // ── Calendario personalizado de fecha ────────────────────
  function initMapaCalendar() {
    var picker     = document.getElementById('mapa-date-picker');
    var displayBtn = document.getElementById('mapa-date-display');
    var calEl      = document.getElementById('mapa-calendar');
    if (!picker || !displayBtn || !calEl) return;

    var labelEl = document.getElementById('mapa-cal-label');
    var gridEl  = document.getElementById('mapa-cal-grid');
    var prevBtn = document.getElementById('mapa-cal-prev');
    var nextBtn = document.getElementById('mapa-cal-next');

    var MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                  'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function toDisplay(d) {
      return pad(d.getDate()) + ' · ' + MONTHS[d.getMonth()].substring(0, 3) + ' ' + d.getFullYear();
    }

    var initVal   = fechaInput ? fechaInput.value : new Date().toISOString().slice(0, 10);
    var parts     = initVal.split('-');
    var selected  = new Date(+parts[0], +parts[1] - 1, +parts[2]);
    var curYear   = selected.getFullYear();
    var curMonth  = selected.getMonth();

    function renderGrid() {
      if (labelEl) labelEl.textContent = MONTHS[curMonth] + ' ' + curYear;
      if (!gridEl) return;
      gridEl.innerHTML = '';

      var first = new Date(curYear, curMonth, 1).getDay();
      var days  = new Date(curYear, curMonth + 1, 0).getDate();
      var today = new Date(); today.setHours(0, 0, 0, 0);

      for (var i = 0; i < first; i++) {
        var empty = document.createElement('span');
        empty.className = 'mapa-cal__day mapa-cal__day--empty';
        gridEl.appendChild(empty);
      }

      for (var d = 1; d <= days; d++) {
        (function(day) {
          var btn  = document.createElement('button');
          btn.type = 'button';
          btn.className = 'mapa-cal__day';
          btn.textContent = day;

          var date = new Date(curYear, curMonth, day);
          date.setHours(0, 0, 0, 0);

          if (date.getTime() === today.getTime())    btn.classList.add('mapa-cal__day--today');
          if (selected && date.getTime() === selected.getTime()) btn.classList.add('mapa-cal__day--selected');

          btn.addEventListener('click', function() {
            selected = date;
            var val  = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            if (fechaInput) fechaInput.value = val;
            displayBtn.textContent = toDisplay(date);
            closeCal();
            stopPolling();
            fetchData(val, false);
            startPolling();
          });

          gridEl.appendChild(btn);
        })(d);
      }
    }

    function openCal()  { calEl.classList.add('mapa-cal--open'); calEl.setAttribute('aria-hidden', 'false'); renderGrid(); }
    function closeCal() { calEl.classList.remove('mapa-cal--open'); calEl.setAttribute('aria-hidden', 'true'); }

    displayBtn.textContent = toDisplay(selected);
    displayBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      calEl.classList.contains('mapa-cal--open') ? closeCal() : openCal();
    });

    if (prevBtn) {
      prevBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        curMonth--;
        if (curMonth < 0) { curMonth = 11; curYear--; }
        renderGrid();
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        curMonth++;
        if (curMonth > 11) { curMonth = 0; curYear++; }
        renderGrid();
      });
    }

    document.addEventListener('click', function(e) {
      if (!picker.contains(e.target)) closeCal();
    });
  }

  // ── Fetch datos ───────────────────────────────────────────
  function fetchData(fecha, silent) {
    if (!silent) {
      if (loadingEl) loadingEl.classList.remove('hidden');
      reservasList.innerHTML =
        '<div class="mapa-empty-state"><span class="mapa-empty-icon">◌</span><span>Cargando…</span></div>';
    }
    fetch('/api/mapa?fecha=' + encodeURIComponent(fecha))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.ok === false) {
          if (!silent) {
            reservasList.innerHTML =
              '<div class="mapa-empty-state mapa-empty-state--error">' +
                '<span class="mapa-empty-icon">⚠</span>' +
                '<span>' + (data.hint || data.error || 'Error de servidor') + '</span>' +
              '</div>';
            if (loadingEl) loadingEl.classList.add('hidden');
          }
          return;
        }
        mesas         = data.mesas         || [];
        reservaciones = data.reservaciones  || [];
        tickets       = data.tickets        || [];
        if (!silent) renderMesas();
        renderEstados();
        renderSidebar();
        if (loadingEl) loadingEl.classList.add('hidden');
      })
      .catch(function() {
        if (!silent) {
          reservasList.innerHTML =
            '<div class="mapa-empty-state mapa-empty-state--error">' +
              '<span class="mapa-empty-icon">⚠</span><span>Error al cargar datos.</span>' +
            '</div>';
          if (loadingEl) loadingEl.classList.add('hidden');
        }
      });
  }

  function silentRefresh() {
    fetchData(fechaInput ? fechaInput.value : new Date().toISOString().slice(0, 10), true);
  }

  // ── Polling en tiempo real (cada 30 s) ────────────────────
  function startPolling() {
    stopPolling();
    pollTimer = setInterval(silentRefresh, 30000);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ── Modo en vivo ──────────────────────────────────────────
  function syncLive() {
    var min = snapTo30(new Date().getHours() * 60 + new Date().getMinutes());
    min = Math.max(510, Math.min(1320, min));
    sliderMin = min;
    renderEstados();
    renderSidebar();
  }

  function activateLive() {
    isLive = true;
    if (liveBadge) liveBadge.classList.add('mapa-live-badge--active');
    if (ahoraBtn)  ahoraBtn.classList.add('mapa-ahora-btn--active');
    syncLive();
    if (liveInterval) clearInterval(liveInterval);
    liveInterval = setInterval(syncLive, 60000);
    startPolling();
  }

  function deactivateLive() {
    isLive = false;
    if (liveBadge) liveBadge.classList.remove('mapa-live-badge--active');
    if (ahoraBtn)  ahoraBtn.classList.remove('mapa-ahora-btn--active');
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
  }

  // ── Eventos ───────────────────────────────────────────────
  initMapaCalendar();

  if (modalBd)    modalBd.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
  });

  // ── Init ──────────────────────────────────────────────────
  sliderMin = Math.max(510, Math.min(1320, snapTo30(new Date().getHours() * 60 + new Date().getMinutes())));
  fetchData(fechaInput ? fechaInput.value : new Date().toISOString().slice(0, 10), false);
  activateLive();
}

// Auto-inicializar independientemente de boot()
(function() {
  function tryInitMapa() {
    if (document.getElementById('mapa-canvas')) initMapa();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryInitMapa);
  } else {
    tryInitMapa();
  }
})();
