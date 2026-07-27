/* ── Punto de Venta — Casa Pestalozzi ──────────────────────── */

function initMapa() {
  if (initMapa._done) return;
  initMapa._done = true;

  var canvas = $('#mapa-canvas');
  if (!canvas) return;

  var mapVisual = window.MapaVisual && window.MapaVisual.crear({
    canvas: canvas,
    contexto: 'mapa-mesas',
    interactivo: true,
    seleccionMultiple: false,
    mostrarLeyenda: true
  });
  if (!mapVisual) return;

  canvas.addEventListener('mapa:mesa-click', function(event) {
    if (event.detail && event.detail.contexto === 'mapa-mesas') {
      onMesaClick(parseInt(event.detail.mesaId, 10));
    }
  });

  // ── Estado ────────────────────────────────────────────────
  var mesas         = [];
  var reservaciones = [];
  var tickets       = [];
  var meseros       = []; // usuarios con rol 'waiter' activos (para asignar al abrir mesa)
  var commandaItems    = []; // { n, p, area, area_id, categoria, comensal, qty }
  var selectedComensal = 0;  // 0 = General
  var SUGERENCIAS         = []; // tarjeta(s) visibles; las trae n8n al abrir la mesa
  var sugCola             = []; // resto del ranking de n8n, para "↻ Otra sugerencia"
  var sugVistos           = []; // producto_id ya ofrecidos en esta sesión del modal;
                                // nada se persiste, así que ésta es toda la memoria
  var sugTicket           = null; // ticket dueño de las sugerencias en pantalla
  var sugEtapa            = '';   // etapa de la comida que detectó n8n
  var sugTimer            = null;
  var sugComensalesCount  = 0;

  // n8n decide la etapa (ENTRADAS/DESARROLLO/CIERRE) con el tiempo de la mesa
  // y lo ya pedido; se re-consulta cada tanto por si la mesa cambió de etapa
  // con el modal abierto.
  var SUG_REFRESCO = 5 * 60 * 1000;
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
  var updateStatus  = $('#mapa-update-status');
  var reservaCount  = $('#mapa-reserva-count');
  var loadingEl     = $('#mapa-loading');
  var modal         = $('#mesa-modal');
  var modalContent  = $('#mesa-modal-content');
  var modalBd       = $('#mesa-modal-bd');
  var modalClose    = $('#mesa-modal-close');
  var manageReservationsBtn = $('#pdv-manage-reservations');

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
      if (parseInt(mesas[i].id, 10) === parseInt(id, 10)) return mesas[i];
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

  // ── Iconos SVG en línea (heredan currentColor) ────────────
  var SVG_PATHS = {
    search:  '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    receipt: '<path d="M5 3h14v18l-2.5-1.6L14 21l-2-1.6L10 21l-2.5-1.6L5 21Z"/><path d="M9 8h6"/><path d="M9 12h6"/>',
    users:   '<path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    cash:    '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    card:    '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'
  };

  function svgIcon(name, size) {
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" ' +
           'stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" ' +
           'aria-hidden="true">' + (SVG_PATHS[name] || '') + '</svg>';
  }

  // ── Select de meseros registrados (se asigna al abrir el ticket) ──
  function buildMeseroSelectHtml() {
    var h = '<div class="mmodal-name-wrap">';
    h += '<div class="mmodal-label">Mesero</div>';
    h += '<select class="mmodal-name-input" id="mmodal-mesero">';
    h += '<option value="">— Sin asignar —</option>';
    for (var i = 0; i < meseros.length; i++) {
      h += '<option value="' + meseros[i].id + '">' + escHtml(meseros[i].nombre) + '</option>';
    }
    h += '</select>';
    h += '</div>';
    return h;
  }

  function selectedMeseroId() {
    var sel = modalContent.querySelector('#mmodal-mesero');
    if (!sel || !sel.value) return null;
    return parseInt(sel.value, 10);
  }

  // ── Sugerencias de venta (flujo de n8n) ───────────────────
  function sugEstadoHtml(texto, icono) {
    return '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' +
           (icono || '✨') + '</span><span>' + escHtml(texto) + '</span></div>';
  }

  function sugListEl() {
    return modalContent && modalContent.querySelector('#mmodal-sug-list');
  }

  // Etiqueta de la etapa de la comida (la decide n8n, no el POS)
  var ETAPA_LABELS = {
    'ENTRADAS':   'Entradas',
    'DESARROLLO': 'Desarrollo',
    'CIERRE':     'Cierre'
  };

  function pintarEtapa(etapa) {
    var el = modalContent && modalContent.querySelector('#mmodal-etapa');
    if (!el) return;

    var label = ETAPA_LABELS[etapa];
    if (!label) { el.hidden = true; return; }

    el.textContent = '🍽 ' + label;
    el.className   = 'mmodal-etapa mmodal-etapa--' + etapa.toLowerCase();
    el.hidden      = false;
  }

  // Estado vacío o de error con su botón de acción (pedir más / reintentar)
  function sugAccionHtml(texto, icono, boton) {
    return sugEstadoHtml(texto, icono) +
           '<div class="mmodal-sug-more"><button class="mmodal-btn mmodal-btn--primary" ' +
           'id="mmodal-sug-more">' + escHtml(boton) + '</button></div>';
  }

  function bindSugMore() {
    var btn = modalContent && modalContent.querySelector('#mmodal-sug-more');
    if (!btn) return;
    btn.addEventListener('click', function() {
      if (!sugTicket) return;
      // Si no viene nada nuevo el panel vuelve al mismo estado vacío y el clic
      // parece no haber hecho nada: por eso el mensaje es distinto.
      cargarSugerencias(sugTicket, {
        conservarVistos: true,
        vacioTexto: 'Ya ofreciste todas las sugerencias de esta mesa'
      });
    });
  }

  // ¿El mesero ya metió a la comanda algo de esta tarjeta?
  function sugAceptada(sug) {
    for (var i = 0; i < commandaItems.length; i++) {
      if (commandaItems[i].n === sug.n) return true;
    }
    return false;
  }

  /**
   * Pide a n8n las sugerencias del ticket. n8n rankea varios productos: se
   * muestra el primero y el resto espera en la cola para "otra sugerencia".
   *
   * opts.silencioso      = refresco automático del temporizador: no toca lo que
   *                        hay en pantalla salvo que n8n cambie de etapa.
   * opts.conservarVistos = el mesero pidió otras: se mantiene la memoria de lo
   *                        ya ofrecido para que el flujo no lo repita. Al abrir
   *                        la mesa esa memoria arranca vacía.
   * opts.vacioTexto      = qué decir si no viene nada.
   */
  function cargarSugerencias(ticket, opts) {
    opts = opts || {};
    var silencioso = !!opts.silencioso;
    sugTicket = ticket;

    if (!silencioso) {
      SUGERENCIAS = [];
      sugCola     = [];
      if (!opts.conservarVistos) sugVistos = [];
      var list = sugListEl();
      if (list) list.innerHTML = sugEstadoHtml('Buscando sugerencias…', '⟳');
      sugTimerStart(ticket);
    }

    fetch('/api/sugerencias', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ ticket_id: ticket.id, vistos: sugVistos })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      // El mesero pudo abrir otra mesa mientras n8n respondía
      if (!sugTicket || sugTicket.id !== ticket.id) return;

      if (!result.ok) {
        if (silencioso) return;
        var errEl = sugListEl();
        if (errEl) {
          errEl.innerHTML = sugAccionHtml(result.msg || 'No pudimos obtener sugerencias', '⚠', 'Reintentar');
          bindSugMore();
        }
        return;
      }

      // Refresco automático: solo se recalcula si la mesa cambió de etapa
      if (silencioso && result.etapa === sugEtapa) return;
      sugEtapa = result.etapa;
      pintarEtapa(sugEtapa);

      var frescas = result.sugerencias || [];
      var actual  = SUGERENCIAS[0];

      // Todo lo que llega cuenta como ofrecido: es lo que viaja en la siguiente
      // ronda para que el flujo no lo repita.
      for (var v = 0; v < frescas.length; v++) {
        if (sugVistos.indexOf(frescas[v].producto_id) === -1) {
          sugVistos.push(frescas[v].producto_id);
        }
      }

      // Cambió la etapa, pero la tarjeta en pantalla ya la trabajó el mesero:
      // no se le quita de enfrente; las nuevas esperan en la cola.
      if (silencioso && actual && sugAceptada(actual)) {
        sugCola = frescas.filter(function(s) { return s.producto_id !== actual.producto_id; });
        return;
      }

      sugCola     = frescas;
      SUGERENCIAS = sugCola.length ? [sugCola.shift()] : [];
      renderSugerencias(opts.vacioTexto || 'Sin sugerencias para esta mesa por ahora');
    })
    .catch(function() {
      if (!sugTicket || sugTicket.id !== ticket.id || silencioso) return;
      var errEl = sugListEl();
      if (errEl) {
        errEl.innerHTML = sugAccionHtml('No pudimos obtener sugerencias', '⚠', 'Reintentar');
        bindSugMore();
      }
    });
  }

  function sugTimerStart(ticket) {
    sugTimerStop();
    sugTimer = setInterval(function() {
      if (!sugTicket || sugTicket.id !== ticket.id) { sugTimerStop(); return; }
      cargarSugerencias(ticket, { silencioso: true });
    }, SUG_REFRESCO);
  }

  function sugTimerStop() {
    if (sugTimer) { clearInterval(sugTimer); sugTimer = null; }
  }

  // vacioTexto: al cargar aún no hubo sugerencias; tras los swaps, ya se agotaron.
  function renderSugerencias(vacioTexto) {
    var list = sugListEl();
    if (!list) return;

    if (!SUGERENCIAS.length) {
      list.innerHTML = sugAccionHtml(
        vacioTexto || 'Ya viste todas las sugerencias de esta etapa',
        '✨',
        'Obtener más sugerencias'
      );
      bindSugMore();
      return;
    }

    var h = '';
    for (var i = 0; i < SUGERENCIAS.length; i++) {
      h += buildSuggestionCardHtml(i, SUGERENCIAS[i]);
    }
    list.innerHTML = h;

    for (var b = 0; b < SUGERENCIAS.length; b++) {
      bindSuggestionCard(b);
    }
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
      var ids = Array.isArray(t.mesa_ids) ? t.mesa_ids.map(Number) : [];
      if (ids.indexOf(Number(mesaId)) !== -1) return t;
    }
    return null;
  }

  function ticketsParaMesa(mesaId) {
    var result = [];
    for (var i = 0; i < tickets.length; i++) {
      var t = tickets[i];
      var ids = Array.isArray(t.mesa_ids) ? t.mesa_ids.map(Number) : [];
      if (ids.indexOf(Number(mesaId)) !== -1) result.push(t);
    }
    return result;
  }

  function mesaIdsReserva(reserva) {
    if (!reserva || !Array.isArray(reserva.mesa_ids)) return [];
    return reserva.mesa_ids.map(function(id) {
      return parseInt(id, 10);
    }).filter(function(id) {
      return id > 0;
    });
  }

  function reservaTieneMesa(reserva, mesaId) {
    return mesaIdsReserva(reserva).indexOf(mesaId) !== -1;
  }

  function nombresMesasReserva(reserva) {
    var ids = mesaIdsReserva(reserva);
    var nombres = [];
    for (var i = 0; i < ids.length; i++) {
      var mesa = mesaPorId(ids[i]);
      if (mesa) nombres.push(mesa.nombre);
    }
    return nombres.join(' + ');
  }

  function reservaParaModal(mesaId) {
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (r.estado === 'cancelada') continue;
      if (!reservaTieneMesa(r, mesaId)) continue;
      if (r.estado === 'en_curso') return 'en-curso';
      if (r.estado === 'llego') return 'llego';
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
      if (!reservaTieneMesa(r, mesaId)) continue;
      if (r.estado === 'en_curso') return 'en-curso';
      if (r.estado === 'llego') return 'llego';
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
      if (!reservaTieneMesa(r, mesaId)) continue;
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
  function mesaReservable(mesa) {
    return Boolean(mesa && (mesa.reservable === true || mesa.reservable === 1 || mesa.reservable === '1'));
  }

  function mesaTicketable(mesa) {
    return Boolean(mesa && (mesaReservable(mesa) || mesa.tipo === 'barra'
      || (mesa.tipo === 'especial' && mesa.nombre !== 'Caja')));
  }

  // La "Caja" es una zona estática del mapa que abre el corte de caja del día.
  function esCaja(mesa) {
    return Boolean(mesa && mesa.tipo === 'especial' && mesa.nombre === 'Caja');
  }

  function estadoVisualMapa(mesa, estado) {
    if (!mesaReservable(mesa)) return 'no-reservable';
    if (estado === 'ocupada' || estado === 'con-ticket' || estado === 'en-curso') return 'ocupada';
    if (estado === 'bloqueada' || estado === 'proxima' || estado === 'llego') return 'asignada';
    return 'libre';
  }

  function clasesEstadoMapa(mesa, estado) {
    var classes = ['mesa-pin--' + estado];
    var ticket = ticketActual(parseInt(mesa.id, 10));
    if (ticket && ticket.origen === 'walk_in') classes.push('mesa-pin--walk-in');
    else if (ticket && ticket.origen === 'reservacion') classes.push('mesa-pin--servicio-reservacion');
    if (!mesaReservable(mesa)) classes.push('mesa-pin--zona');
    return classes;
  }

  // Comprime el rango de coordenadas [0,100] a [PAD, 100-PAD] para dar aire
  // entre los pines y el borde del contenedor del mapa (solo en el POS; el
  // editor de mapa del admin usa map-visual.js sin esta compresión).
  var MAPA_INSET = 6;
  function insetPos(v) {
    var n = parseFloat(v);
    if (isNaN(n)) return v;
    return MAPA_INSET + (n * (100 - 2 * MAPA_INSET) / 100);
  }

  function normalizarMesaMapa(mesa) {
    var ticketable = mesaTicketable(mesa);
    // La Caja no es "ticketable" (no lleva estado de mesa) pero sí es clickeable.
    var clickable = ticketable || esCaja(mesa);
    var estado = ticketable ? estadoMesa(parseInt(mesa.id, 10), sliderMin) : 'zona';

    return {
      id: mesa.id,
      nombre: mesa.nombre,
      tipo: mesa.tipo,
      estadoVisual: estadoVisualMapa(mesa, estado),
      x: insetPos(mesa.pos_x),
      y: insetPos(mesa.pos_y),
      ancho: mesa.ancho,
      alto: mesa.alto,
      reservable: mesaReservable(mesa),
      capacidad: mesa.capacidad,
      seleccionada: false,
      interactivo: clickable,
      numero: mesa.numero,
      titulo: mesa.nombre + (mesa.capacidad ? ' · Cap. ' + mesa.capacidad : ''),
      clasesEstado: clasesEstadoMapa(mesa, estado),
      atributos: {
        'data-id': mesa.id,
        'data-numero': mesa.numero == null ? '' : mesa.numero,
        'data-reservable': mesa.reservable,
        'data-ticketable': ticketable ? '1' : '0',
        'data-estado': estado
      }
    };
  }

  function renderEstados() {
    for (var i = 0; i < mesas.length; i++) {
      var mesa = mesas[i];
      if (!mesaTicketable(mesa)) continue;

      var estado = estadoMesa(parseInt(mesa.id, 10), sliderMin);
      mapVisual.actualizarEstado(mesa.id, {
        estadoVisual: estadoVisualMapa(mesa, estado),
        clasesEstado: clasesEstadoMapa(mesa, estado),
        atributos: { 'data-estado': estado }
      });
    }
  }

  // ── Render: pines en el canvas ────────────────────────────
  function renderMesas() {
    mapVisual.render({
      mesas: mesas.map(normalizarMesaMapa),
      elementos: []
    });
  }

  // ── Render: sidebar de reservaciones ──────────────────────
  function renderSidebar() {
    if (!reservaciones.length) {
      reservasList.innerHTML =
        '<div class="mapa-empty-state">' +
          '<span class="mapa-empty-icon" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
              '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>' +
            '</svg>' +
          '</span>' +
          '<span class="mapa-empty-title">Sin reservaciones para este día</span>' +
          '<span class="mapa-empty-hint">Elige otra fecha en el calendario o registra una nueva reservación.</span>' +
        '</div>';
      reservaCount.textContent = '0';
      return;
    }
    reservaCount.textContent = reservaciones.length;
    reservasList.innerHTML   = '';
    for (var i = 0; i < reservaciones.length; i++) {
      var r          = reservaciones[i];
      var tempEstado = temporalEstadoReserva(r);
      var cardClasses = [];
      var tableNames = mesaIdsReserva(r).map(function(mesaId) {
        var mesa = mesaPorId(mesaId);
        return mesa ? mesa.nombre : '';
      }).filter(Boolean);

      if (r.estado === 'cancelada' || tempEstado === 'vencida') cardClasses.push('reserva-card--pasada');
      else if (tempEstado === 'en-curso') cardClasses.push('reserva-card--activa');
      else if (tempEstado === 'proxima') cardClasses.push('reserva-card--proxima');

      var card = window.OperationalReservationCard.create(r, {
        hora: r.hora.substring(0, 5),
        estado: r.estado,
        mesas: tableNames,
        clases: cardClasses,
        meta: TEMPORAL_LABELS[tempEstado] ? {
          label: TEMPORAL_LABELS[tempEstado],
          className: 'reserva-card__temporal reserva-card__temporal--' + tempEstado
        } : null
      });

      (function(rid, mesaIds, button) {
        button.addEventListener('click', function() { onCardClick(rid, mesaIds); });
      })(r.id, mesaIdsReserva(r), card.querySelector('button'));
      reservasList.appendChild(card);
    }
  }

  // ── Click en canvas / sidebar ─────────────────────────────
  function clearHighlight() {
    var cards = $$('.reserva-card', reservasList);
    mapVisual.setSeleccionadas([]);
    for (var i = 0; i < cards.length; i++) {
      cards[i].classList.remove('reserva-card--selected');
      var button = cards[i].querySelector('button');
      if (button) button.setAttribute('aria-pressed', 'false');
    }
  }

  function onMesaClick(mesaId) {
    var mesa = mesaPorId(mesaId);
    if (!mesa) return;
    if (esCaja(mesa)) {
      showCajaModal(mesa);
      return;
    }
    var canOpen = mesa.reservable || mesa.tipo === 'barra'
                  || (mesa.tipo === 'especial' && mesa.nombre !== 'Caja');
    if (!canOpen) return;
    if (isLlevar(mesa)) {
      showLlevarModal(mesa);
    } else {
      showModal(mesa, estadoMesaActual(mesaId));
    }
  }

  // ── Modal Caja: corte del día ─────────────────────────────
  function showCajaModal(mesa) {
    if (!modal || !modalContent) return;
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">Corte de caja</span>';
    h += '<span class="mmodal-title-cliente">— Resumen del día</span>';
    h += '</div></div>';
    h += '<div class="mmodal-caja"><p class="mmodal-cerrar-confirm__sub" style="text-align:center">Cargando el resumen…</p></div>';
    modalContent.innerHTML = h;
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';

    fetch('/api/corte-caja')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.ok) {
          renderCajaModal(data);
        } else {
          renderCajaError();
        }
      })
      .catch(function() { renderCajaError(); });
  }

  function renderCajaError() {
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">Corte de caja</span>';
    h += '</div></div>';
    h += '<div class="mmodal-caja"><div class="mmodal-col-empty">';
    h += '<span class="mmodal-col-empty__icon">⚠</span>';
    h += '<span>No se pudo cargar el corte de caja.</span></div>';
    h += '<div class="mmodal-cerrar-confirm__btns"><button class="mmodal-btn mmodal-btn--ghost" id="caja-cerrar">Cerrar</button></div>';
    h += '</div>';
    modalContent.innerHTML = h;
    var btn = modalContent.querySelector('#caja-cerrar');
    if (btn) btn.addEventListener('click', closeModal);
  }

  function renderCajaModal(data) {
    function money(v) {
      var n = Number(v) || 0;
      return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    var res     = data.resumen || {};
    var metodos = data.metodos || {};
    var efectivo = Number(metodos.efectivo) || 0;
    var tarjeta  = Number(metodos.tarjeta) || 0;
    var totalMet = efectivo + tarjeta;
    var pctEf    = totalMet > 0 ? Math.round((efectivo / totalMet) * 100) : 0;
    var pctTar   = totalMet > 0 ? 100 - pctEf : 0;

    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">Corte de caja</span>';
    h += '<span class="mmodal-title-cliente">— ' + escHtml(data.fecha || '') + '</span>';
    h += '</div></div>';

    h += '<div class="mmodal-caja">';

    // KPIs principales
    h += '<div class="mmodal-caja-kpis">';
    h += cajaKpi('Ventas del día', money(res.ventas), true);
    h += cajaKpi('Propinas', money(res.propinas));
    h += cajaKpi('Tickets', String(res.tickets || 0));
    h += cajaKpi('Ticket promedio', money(res.promedio));
    h += '</div>';

    // Total recibido
    h += '<div class="mmodal-caja-total">';
    h += '<span class="mmodal-total-label">Total recibido</span>';
    h += '<span class="mmodal-caja-total__amount">' + money(res.total) + '</span>';
    h += '</div>';

    // Métodos de pago con barra
    h += '<div class="mmodal-caja-section">';
    h += '<p class="mmodal-section-label">Por método de pago</p>';
    h += '<div class="mmodal-caja-bar">';
    h += '<span class="mmodal-caja-bar__ef" style="width:' + pctEf + '%"></span>';
    h += '<span class="mmodal-caja-bar__tar" style="width:' + pctTar + '%"></span>';
    h += '</div>';
    h += '<div class="mmodal-caja-methods">';
    h += '<div class="mmodal-caja-method"><span class="mmodal-caja-method__dot mmodal-caja-method__dot--ef"></span>Efectivo<strong>' + money(efectivo) + '</strong></div>';
    h += '<div class="mmodal-caja-method"><span class="mmodal-caja-method__dot mmodal-caja-method__dot--tar"></span>Tarjeta<strong>' + money(tarjeta) + '</strong></div>';
    h += '</div>';
    h += '</div>';

    // Top platillos
    if (data.top && data.top.length) {
      h += '<div class="mmodal-caja-section">';
      h += '<p class="mmodal-section-label">Más vendidos</p>';
      h += '<div class="mmodal-caja-list">';
      for (var i = 0; i < data.top.length; i++) {
        var t = data.top[i];
        h += '<div class="mmodal-caja-list__row">';
        h += '<span class="mmodal-caja-list__qty">' + (t.unidades || 0) + '×</span>';
        h += '<span class="mmodal-caja-list__name">' + escHtml(t.nombre || '') + '</span>';
        h += '<span class="mmodal-caja-list__val">' + money(t.importe) + '</span>';
        h += '</div>';
      }
      h += '</div></div>';
    }

    // Ventas por área
    if (data.areas && data.areas.length) {
      h += '<div class="mmodal-caja-section">';
      h += '<p class="mmodal-section-label">Ventas por área</p>';
      h += '<div class="mmodal-caja-list">';
      for (var a = 0; a < data.areas.length; a++) {
        var ar = data.areas[a];
        h += '<div class="mmodal-caja-list__row">';
        h += '<span class="mmodal-caja-list__name">' + escHtml(ar.area || '') + '</span>';
        h += '<span class="mmodal-caja-list__val">' + money(ar.importe) + '</span>';
        h += '</div>';
      }
      h += '</div></div>';
    }

    if (!res.tickets) {
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">◌</span><span>Aún no hay ventas cerradas hoy.</span></div>';
    }

    h += '<div class="mmodal-cerrar-confirm__btns"><button class="mmodal-btn mmodal-btn--ghost" id="caja-cerrar">Cerrar</button></div>';
    h += '</div>';

    modalContent.innerHTML = h;
    var btn = modalContent.querySelector('#caja-cerrar');
    if (btn) btn.addEventListener('click', closeModal);
  }

  function cajaKpi(label, value, destacado) {
    var h = '<div class="mmodal-caja-kpi' + (destacado ? ' mmodal-caja-kpi--main' : '') + '">';
    h += '<span class="mmodal-caja-kpi__label">' + escHtml(label) + '</span>';
    h += '<span class="mmodal-caja-kpi__value">' + escHtml(value) + '</span>';
    h += '</div>';
    return h;
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

  function onCardClick(reservaId, mesaIds) {
    clearHighlight();
    highlightReserva(reservaId, mesaIds);
  }

  function highlightReserva(reservaId, mesaIds) {
    var card = reservasList.querySelector('[data-id="' + reservaId + '"]');
    if (card) {
      card.classList.add('reserva-card--selected');
      var button = card.querySelector('button');
      if (button) button.setAttribute('aria-pressed', 'true');
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    mesaIds = Array.isArray(mesaIds) ? mesaIds : [];
    mapVisual.setSeleccionadas(mesaIds);
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
    sugTimerStop();
    sugTicket = null;
  }

  // Panel operativo de reservaciones: permanece dentro del POS y no mezcla
  // walk-ins, porque éstos sólo existen como tickets abiertos.
  function openReservationManager() {
    if (!modal || !modalContent) return;
    modalContent.innerHTML =
      '<div class="pdv-reservation-manager">' +
        '<div class="pdv-reservation-manager__head">' +
          '<span class="pdv-reservation-manager__icon" aria-hidden="true">📅</span>' +
          '<div><h2>Gestionar reservaciones</h2><p>Hoy · llegada, servicio y no-show</p></div>' +
        '</div>' +
        '<div class="pdv-reservation-manager__list" data-pdv-reservation-list>' +
          '<p class="pdv-reservation-manager__empty">Cargando reservaciones…</p>' +
        '</div>' +
      '</div>';
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';
    loadReservationManager();
  }

  function loadReservationManager() {
    var list = modalContent && modalContent.querySelector('[data-pdv-reservation-list]');
    if (!list) return;
    var fecha = fechaInput ? fechaInput.value : new Date().toISOString().slice(0, 10);
    fetch('/api/punto-de-venta/reservaciones?fecha=' + encodeURIComponent(fecha))
      .then(function(response) { return response.json(); })
      .then(function(result) {
        if (!result.ok) throw new Error(result.msg || 'No fue posible cargar.');
        var items = result.reservaciones || [];
        if (!items.length) {
          list.innerHTML = '<p class="pdv-reservation-manager__empty">No hay reservaciones operativas para esta fecha.</p>';
          return;
        }
        list.innerHTML = items.map(reservationManagerCard).join('');
        bindReservationManagerActions(list);
      })
      .catch(function(error) {
        list.innerHTML = '<p class="pdv-reservation-manager__empty pdv-reservation-manager__empty--error">' +
          escHtml(error.message || 'Error de conexión') + '</p>';
      });
  }

  function reservationManagerCard(reservation) {
    var stateLabels = {
      confirmada: 'Confirmada',
      llego: 'Cliente llegó',
      en_curso: 'En curso',
      no_show: 'No show'
    };
    var tables = reservation.mesas || 'Sin mesa asignada';
    var canArrive = reservation.estado === 'confirmada';
    var canStart = reservation.estado === 'confirmada' || reservation.estado === 'llego';
    var canCancel = canStart;
    var canNoShow = canStart;
    var noShowDisabled = canNoShow && !reservation.no_show_disponible;
    return '<article class="pdv-reservation-card" data-reservation-id="' + reservation.id + '">' +
      '<div class="pdv-reservation-card__eyebrow"><span aria-hidden="true">📅</span> Reservación · ' +
        escHtml(reservation.hora) + '</div>' +
      '<div class="pdv-reservation-card__body">' +
        '<strong>' + escHtml(reservation.nombre) + '</strong>' +
        '<span>' + reservation.personas + ' personas · ' + escHtml(tables) + '</span>' +
        '<span class="pdv-reservation-card__status pdv-reservation-card__status--' +
          escHtml(reservation.retrasada ? 'retrasada' : reservation.estado) + '">' +
          escHtml(reservation.retrasada ? 'Retrasada' : (stateLabels[reservation.estado] || reservation.estado)) + '</span>' +
      '</div>' +
      '<div class="pdv-reservation-card__actions">' +
        (canArrive ? '<button type="button" data-reservation-action="llegada">Llegó</button>' : '') +
        (canStart ? '<button type="button" data-reservation-action="comenzar">Comenzar</button>' : '') +
        (canNoShow ? '<button type="button" data-reservation-action="no-show"' +
          (noShowDisabled ? ' disabled title="Disponible al terminar la tolerancia de 15 minutos"' : '') +
          '>No-show</button>' : '') +
        (canCancel ? '<button type="button" data-reservation-action="cancelar">Cancelar</button>' : '') +
      '</div>' +
    '</article>';
  }

  function bindReservationManagerActions(list) {
    var buttons = list.querySelectorAll('[data-reservation-action]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function(event) {
        var button = event.currentTarget;
        var card = button.closest('[data-reservation-id]');
        if (!card) return;
        var action = button.getAttribute('data-reservation-action');
        var id = parseInt(card.getAttribute('data-reservation-id'), 10);
        var motive = '';
        if (action === 'cancelar') {
          motive = window.prompt('Motivo de cancelación (opcional):', '') || '';
          if (!window.confirm('¿Cancelar esta reservación?')) return;
        }
        if (action === 'no-show' && !window.confirm('¿Marcar esta reservación como no-show?')) return;
        button.disabled = true;
        fetch('/api/punto-de-venta/reservaciones/' + action, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ reservacion_id: id, motivo: motive })
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
          if (!result.ok) throw new Error(result.msg || 'No fue posible actualizar.');
          loadReservationManager();
          fetchData(fechaInput.value, true);
        })
        .catch(function(error) {
          window.alert(error.message || 'Error de conexión');
          button.disabled = false;
        });
      });
    }
  }

  // Preset de layout del modal de comanda (ancho de columnas), persistido.
  var POS_LAYOUT_KEY = 'cp-pos-modal-layout';
  var POS_LAYOUTS = ['menu', 'balanced', 'compact'];
  function getPosLayout() {
    try {
      var v = localStorage.getItem(POS_LAYOUT_KEY);
      return POS_LAYOUTS.indexOf(v) !== -1 ? v : 'balanced';
    } catch (e) { return 'balanced'; }
  }
  function setPosLayout(v) {
    try { localStorage.setItem(POS_LAYOUT_KEY, v); } catch (e) {}
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

      // Las sugerencias las pide cargarSugerencias() cuando el modal ya existe
      sugComensalesCount = ticket.comensales;

      var horaAp = ticket.hora_apertura
        ? String(ticket.hora_apertura).substring(11, 16)
        : '--:--';

      h += '<div class="mmodal-ticket-meta">';
      h += '<span>👥 ' + ticket.comensales + ' com.</span>';
      h += '<span>🕐 ' + horaAp + '</span>';
      // La etapa la detecta n8n; se llena cuando responden las sugerencias.
      h += '<span class="mmodal-etapa" id="mmodal-etapa" hidden></span>';
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

      // ── Presets de layout (solo desktop, 4 columnas) ──────
      var lay = getPosLayout();
      h += '<div class="mmodal-layout-presets" role="group" aria-label="Diseño de columnas">';
      h += '<span class="mmodal-layout-presets__label">Vista</span>';
      h += '<button type="button" class="mmodal-layout-btn' + (lay === 'menu' ? ' is-active' : '') + '" data-pos-layout="menu">Menú amplio</button>';
      h += '<button type="button" class="mmodal-layout-btn' + (lay === 'balanced' ? ' is-active' : '') + '" data-pos-layout="balanced">Equilibrado</button>';
      h += '<button type="button" class="mmodal-layout-btn' + (lay === 'compact' ? ' is-active' : '') + '" data-pos-layout="compact">Compacto</button>';
      h += '</div>';

      // ── Panels wrapper (4 cols en desktop) ────────────────
      h += '<div class="mmodal-panels" data-layout="' + lay + '">';

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

      // Buscador de platillos (filtra en todas las categorías)
      h += '<div class="mmodal-dish-search">';
      h += '<span class="mmodal-dish-search__icon">' + svgIcon('search', 15) + '</span>';
      h += '<input type="search" id="mmodal-dish-search" class="mmodal-dish-search__input" ' +
           'placeholder="Buscar platillo…" autocomplete="off">';
      h += '</div>';

      // Bloques de categoría (grid)
      h += '<div class="mmodal-section-label" id="mmodal-cats-label">Categoría</div>';
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
      h += '<div class="mmodal-cerrar-hint" id="mmodal-cerrar-hint" hidden></div>';
      h += '<button class="mmodal-btn mmodal-btn--danger" id="mmodal-cerrar">Cerrar ticket</button>';
      h += '</div>'; // fin panel-actions
      h += '</div>'; // fin panel-resumen

      // ── Panel 4: Sugerencias ─────────────────────────────────
      h += '<div id="mmodal-panel-sugerencias" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Sugerencias</div>';
      h += '<div class="mmodal-panel-scroll" id="mmodal-sug-list">';
      h += sugEstadoHtml('Buscando sugerencias…', '⟳');
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
      h += buildMeseroSelectHtml();
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
      h += buildMeseroSelectHtml();
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
  // Pinta una lista de platillos. Sin el badge de área (Cocina, Barra de
  // Jugos…) para ahorrar espacio; el área ya se resuelve al enviar la comanda.
  function pintarDishes(lista) {
    var dishesEl = modalContent.querySelector('#mmodal-dishes');
    if (!dishesEl) return;
    dishesEl.innerHTML = '';

    if (!lista.length) {
      dishesEl.innerHTML = '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' +
                           svgIcon('search', 26) + '</span>' +
                           '<span>Sin platillos que coincidan</span></div>';
      return;
    }

    for (var i = 0; i < lista.length; i++) {
      var dish = lista[i];
      var row  = document.createElement('div');
      row.className = 'mmodal-dish-row';
      row.innerHTML =
        '<div class="mmodal-dish-info">' +
          '<span class="mmodal-dish-name">' + escHtml(dish.n) + '</span>' +
        '</div>' +
        '<span class="mmodal-dish-price">$' + dish.p + '</span>';

      var addBtn = document.createElement('button');
      addBtn.className   = 'mmodal-dish-add';
      addBtn.textContent = '+';
      addBtn.setAttribute('aria-label', 'Agregar ' + dish.n);
      (function(d) {
        addBtn.addEventListener('click', function() {
          addToComanda(d.n, d.p, d.area || 'cocina', d._cat || '');
        });
      })(dish);
      row.appendChild(addBtn);
      dishesEl.appendChild(row);
    }
  }

  function renderCategoryDishes(cat) {
    if (!cat || !cat.dishes) return;
    var lista = cat.dishes.map(function(d) { d._cat = cat.label; return d; });
    pintarDishes(lista);
  }

  // Busca por nombre en todas las categorías del menú.
  function buscarDishes(query) {
    var q = (query || '').trim().toLowerCase();
    var catsLabel = modalContent.querySelector('#mmodal-cats-label');
    var catsGrid  = modalContent.querySelector('#mmodal-cats');

    if (q === '') {
      // Sin búsqueda: volver a la vista por categoría
      if (catsLabel) catsLabel.style.display = '';
      if (catsGrid)  catsGrid.style.display  = '';
      var activo = catsGrid ? catsGrid.querySelector('.mmodal-cat-block--active') : null;
      var idx = activo ? parseInt(activo.dataset.idx, 10) : 0;
      if (window.CP_MENU && window.CP_MENU[idx]) renderCategoryDishes(window.CP_MENU[idx]);
      return;
    }

    // Con búsqueda: ocultar categorías y mostrar coincidencias de todo el menú
    if (catsLabel) catsLabel.style.display = 'none';
    if (catsGrid)  catsGrid.style.display  = 'none';

    var res = [];
    if (window.CP_MENU) {
      for (var c = 0; c < window.CP_MENU.length; c++) {
        var cat = window.CP_MENU[c];
        for (var d = 0; d < cat.dishes.length; d++) {
          if (cat.dishes[d].n.toLowerCase().indexOf(q) !== -1) {
            var dish = cat.dishes[d];
            dish._cat = cat.label;
            res.push(dish);
          }
        }
      }
    }
    pintarDishes(res);
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
    if (sug.argumento) {
      h += '<div class="mmodal-sug-argumento">' + escHtml(sug.argumento) + '</div>';
    }
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
    // Descartar la tarjeta rechaza la sugerencia completa: se limpia del
    // carrito lo que cualquier comensal hubiera aceptado. Ya está en sugVistos,
    // así que no vuelve a salir en esta sesión.
    for (var i = commandaItems.length - 1; i >= 0; i--) {
      if (commandaItems[i].n === oldSug.n) commandaItems.splice(i, 1);
    }

    // La siguiente del ranking de n8n; si se agotaron, el panel queda vacío.
    if (sugCola.length) {
      SUGERENCIAS[idx] = sugCola.shift();
    } else {
      SUGERENCIAS.splice(idx, 1);
    }

    renderSugerencias();
    renderComandaCart();
    updateEnviarBtn();
  }

  /**
   * La comanda se envió: la sugerencia que viajó en ella ya está en la cocina,
   * así que la tarjeta cede su lugar a la siguiente del ranking. A partir de
   * aquí el producto queda en el ticket, y el propio ticket_items lo excluye de
   * futuras rondas aunque se reabra la mesa.
   *
   * @param items commandaItems tal como se enviaron.
   */
  function avanzarSugerenciasEnviadas(items) {
    var cambio = false;

    for (var i = SUGERENCIAS.length - 1; i >= 0; i--) {
      var sug = SUGERENCIAS[i];
      var enviados = items.filter(function(it) { return it.n === sug.n; });
      if (!enviados.length) continue;

      SUGERENCIAS.splice(i, 1);
      if (sugCola.length) SUGERENCIAS.splice(i, 0, sugCola.shift());
      cambio = true;
    }

    if (cambio) renderSugerencias();
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
          // El chip solo mueve el pedido: la sugerencia no se da por aceptada
          // hasta que la comanda se envía (ver avanzarSugerenciasEnviadas).
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

  // Habilita/deshabilita "Cerrar ticket" según cuántos productos falten por
  // entregar. La regla también se valida en el backend al cerrar.
  function actualizarCierreEstado(pendientes) {
    var btn  = modalContent.querySelector('#mmodal-cerrar');
    var hint = modalContent.querySelector('#mmodal-cerrar-hint');
    if (!btn) return;
    if (pendientes > 0) {
      btn.disabled = true;
      if (hint) {
        hint.textContent = 'Falta entregar ' + pendientes + ' producto' + (pendientes === 1 ? '' : 's');
        hint.hidden = false;
      }
    } else {
      btn.disabled = false;
      if (hint) hint.hidden = true;
    }
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
          actualizarCierreEstado(0);
          return;
        }

        // Agrupar por área
        var byArea     = {};
        var grandTotal = 0;
        var pendientes = 0; // no cancelados y aún sin entregar
        for (var i = 0; i < data.items.length; i++) {
          var it  = data.items[i];
          var key = it.area_slug;
          if (!byArea[key]) {
            byArea[key] = { label: it.area_nombre, color: it.area_color, items: [] };
          }
          byArea[key].items.push(it);
          if (it.estado !== 'cancelado') {
            grandTotal += it.precio * it.cantidad;
            if (it.estado !== 'entregado') pendientes++;
          }
        }

        // No se puede cerrar la cuenta con productos sin entregar.
        actualizarCierreEstado(pendientes);

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

    // Buscador de platillos
    var searchEl = modalContent.querySelector('#mmodal-dish-search');
    if (searchEl) {
      searchEl.addEventListener('input', function() { buscarDishes(searchEl.value); });
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

    // Presets de layout: ajustan el ancho de las columnas y se recuerdan.
    var layoutBtns = modalContent.querySelectorAll('.mmodal-layout-btn');
    var panelsWrap = modalContent.querySelector('.mmodal-panels');
    for (var li = 0; li < layoutBtns.length; li++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var val = btn.dataset.posLayout;
          if (panelsWrap) panelsWrap.setAttribute('data-layout', val);
          for (var k = 0; k < layoutBtns.length; k++) layoutBtns[k].classList.remove('is-active');
          btn.classList.add('is-active');
          setPosLayout(val);
        });
      })(layoutBtns[li]);
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

    // Sugerencias: el modal ya está en el DOM, se piden a n8n
    cargarSugerencias(ticket);
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
        var meseroId   = selectedMeseroId();
        if (isLlevar(mesa)) {
          apiAbrirLlevarTicket(mesa, comensales, nombre || null, meseroId);
        } else {
          apiAbrirTicket([mesa.id], comensales, null, nombre || null, meseroId);
        }
      });
    }

    var confirmarBtn = modalContent.querySelector('#mmodal-confirmar');
    if (confirmarBtn && reserva) {
      confirmarBtn.addEventListener('click', function() {
        apiAbrirTicket(reserva.mesa_ids || [mesa.id], reserva.comensales, reserva.id, reserva.nombre || null, selectedMeseroId());
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

    // Si había un cierre de cuenta a medias para este ticket, se reanuda en el
    // paso donde quedó (por si el mesero salió por error o por prisa).
    if (ticket && ticket.id && modalContent.querySelector('#mmodal-cerrar')) {
      var paso = leerCierrePaso(ticket.id);
      if (paso && paso.step) {
        if (paso.step === 'completa')      showPagoCompleto(mesa, ticket);
        else if (paso.step === 'dividido') showPagoDividido(mesa, ticket, 'efectivo');
        else                               showCierreTipo(mesa, ticket);
      }
    }
  }

  // ── Persistencia del paso de cierre (sobrevive salir del modal) ──
  function cierrePasoKey(ticketId) { return 'cp_cierre_' + ticketId; }

  function guardarCierrePaso(ticketId, data) {
    try { localStorage.setItem(cierrePasoKey(ticketId), JSON.stringify(data)); } catch (e) {}
  }

  function leerCierrePaso(ticketId) {
    try { return JSON.parse(localStorage.getItem(cierrePasoKey(ticketId)) || 'null'); }
    catch (e) { return null; }
  }

  function limpiarCierrePaso(ticketId) {
    try { localStorage.removeItem(cierrePasoKey(ticketId)); } catch (e) {}
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
    showCierreTipo(mesa, ticket);
  }

  // Paso 1: ¿cuenta completa o dividida? Se pregunta ANTES del método porque
  // en una cuenta dividida cada comensal puede pagar distinto.
  function showCierreTipo(mesa, ticket) {
    guardarCierrePaso(ticket.id, { step: 'tipo' });
    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cierre-tipo">';
    h += '<p class="mmodal-cerrar-confirm__msg">¿Cómo se cierra la cuenta?</p>';
    h += '<div class="mmodal-tipo-cards">';
    h += '<button class="mmodal-tipo-card" id="tipo-completa">';
    h += '<span class="mmodal-tipo-card__icon">' + svgIcon('receipt', 30) + '</span>';
    h += '<span class="mmodal-tipo-card__title">Cuenta completa</span>';
    h += '<span class="mmodal-tipo-card__desc">Un solo pago por toda la mesa</span>';
    h += '</button>';
    h += '<button class="mmodal-tipo-card" id="tipo-dividir">';
    h += '<span class="mmodal-tipo-card__icon">' + svgIcon('users', 30) + '</span>';
    h += '<span class="mmodal-tipo-card__title">Dividir por comensal</span>';
    h += '<span class="mmodal-tipo-card__desc">Cada quien paga lo suyo, con su método</span>';
    h += '</button>';
    h += '</div>';
    h += '<div class="mmodal-cerrar-confirm__btns" style="margin-top:0">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="cc-volver-ticket">← Volver</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    modalContent.querySelector('#tipo-completa').addEventListener('click', function() {
      showPagoCompleto(mesa, ticket);
    });
    modalContent.querySelector('#tipo-dividir').addEventListener('click', function() {
      showPagoDividido(mesa, ticket, 'efectivo');
    });
    modalContent.querySelector('#cc-volver-ticket').addEventListener('click', function() {
      // Volver al ticket = abandonar el cierre: se borra el paso guardado.
      limpiarCierrePaso(ticket.id);
      commandaItems    = [];
      selectedComensal = 0;
      modalContent.innerHTML = buildModalContent(mesa, 'con-ticket', null, ticket);
      bindModalActions(mesa, null, ticket);
    });
  }

  // Paso 2 (completa): método + monto recibido. La propina es el excedente
  // sobre el total. Se carga la cuenta para conocer el total y validar.
  function showPagoCompleto(mesa, ticket) {
    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cerrar-confirm"><p class="mmodal-cerrar-confirm__sub">Cargando la cuenta…</p></div>';
    modalContent.innerHTML = h;

    fetch('/api/ticket-items?ticket_id=' + ticket.id)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        renderPagoCompleto(mesa, ticket, (data.ok && data.items) ? data.items : []);
      })
      .catch(function() {
        alert('Error de conexión');
        showCierreTipo(mesa, ticket);
      });
  }

  function renderPagoCompleto(mesa, ticket, items) {
    var totalCents = 0;
    for (var i = 0; i < items.length; i++) {
      if (items[i].estado === 'cancelado') continue;
      totalCents += Math.round(items[i].precio * 100) * items[i].cantidad;
    }
    if (totalCents <= 0) {
      alert('El ticket no tiene consumo por cobrar');
      showCierreTipo(mesa, ticket);
      return;
    }

    function fmt(cents) { return (cents / 100).toFixed(2).replace(/\.00$/, ''); }

    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cerrar-confirm">';
    h += '<p class="mmodal-cerrar-confirm__msg">Cobro de la cuenta</p>';
    h += '<p class="mmodal-cerrar-confirm__sub" style="margin-top:2px">Elige el método y captura el monto recibido.</p>';

    h += '<div class="mmodal-pago-btns" id="pc-metodos" style="margin-top:12px">';
    h += '<button type="button" class="mmodal-pago-btn mmodal-pago-btn--active" data-metodo="efectivo">';
    h += '<span class="mmodal-pago-btn__icon">' + svgIcon('cash', 24) + '</span><span class="mmodal-pago-btn__label">Efectivo</span></button>';
    h += '<button type="button" class="mmodal-pago-btn" data-metodo="tarjeta">';
    h += '<span class="mmodal-pago-btn__icon">' + svgIcon('card', 24) + '</span><span class="mmodal-pago-btn__label">Tarjeta</span></button>';
    h += '</div>';

    h += '<div class="mmodal-split-status" style="margin-top:14px">';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Total de la cuenta</span><span class="mmodal-total-amount">$' + fmt(totalCents) + '</span></div>';
    h += '<div class="mmodal-total-row" style="align-items:center"><span class="mmodal-total-label">Monto recibido</span>';
    h += '<span class="mmodal-split-monto">$<input type="number" class="mmodal-split-input" id="pc-recibido" min="0" step="0.01" inputmode="decimal" placeholder="' + fmt(totalCents) + '"></span></div>';
    h += '<p class="mmodal-split-diff" id="pc-diff"></p>';
    h += '</div>';

    h += '<div class="mmodal-cerrar-confirm__btns">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="pc-volver">← Volver</button>';
    h += '<button class="mmodal-btn mmodal-btn--danger" id="pc-confirm">Cerrar ticket</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    var metodoBtns = modalContent.querySelectorAll('#pc-metodos .mmodal-pago-btn');
    var recibidoEl = modalContent.querySelector('#pc-recibido');
    var diffEl     = modalContent.querySelector('#pc-diff');
    var confirmBtn = modalContent.querySelector('#pc-confirm');

    // Restaurar lo capturado si el mesero había salido a medias.
    var guardado = leerCierrePaso(ticket.id);
    if (guardado && guardado.step === 'completa') {
      if (guardado.recibido != null) recibidoEl.value = guardado.recibido;
      if (guardado.metodo === 'tarjeta') {
        for (var mb = 0; mb < metodoBtns.length; mb++) {
          metodoBtns[mb].classList.toggle('mmodal-pago-btn--active', metodoBtns[mb].dataset.metodo === 'tarjeta');
        }
      }
    }

    function metodoActivo() {
      var a = modalContent.querySelector('#pc-metodos .mmodal-pago-btn--active');
      return a ? a.dataset.metodo : 'efectivo';
    }

    function persistir() {
      guardarCierrePaso(ticket.id, { step: 'completa', metodo: metodoActivo(), recibido: recibidoEl.value });
    }

    // El botón se habilita cuando el monto recibido cubre el total; el excedente
    // se muestra como propina.
    function validar() {
      var val = parseFloat(recibidoEl.value);
      var recCents = (isNaN(val) || val < 0) ? 0 : Math.round(val * 100);
      // Vacío = pago exacto (sin propina).
      var efectivoCents = recibidoEl.value.trim() === '' ? totalCents : recCents;
      var diff = efectivoCents - totalCents;
      var ok   = diff >= -1;
      if (diffEl) {
        if (recibidoEl.value.trim() === '') {
          diffEl.textContent = 'Pago exacto, sin propina';
          diffEl.className   = 'mmodal-split-diff';
        } else if (diff < -1) {
          diffEl.textContent = 'Faltan $' + fmt(-diff) + ' para cubrir el total';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--falta';
        } else if (diff <= 1) {
          diffEl.textContent = 'Pago exacto, sin propina';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--ok';
        } else {
          diffEl.textContent = '✓ Propina: $' + fmt(diff);
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--ok';
        }
      }
      if (confirmBtn) confirmBtn.disabled = !ok;
      return ok;
    }

    for (var b = 0; b < metodoBtns.length; b++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          for (var k = 0; k < metodoBtns.length; k++) metodoBtns[k].classList.remove('mmodal-pago-btn--active');
          btn.classList.add('mmodal-pago-btn--active');
          persistir();
        });
      })(metodoBtns[b]);
    }
    recibidoEl.addEventListener('input', function() { validar(); persistir(); });

    modalContent.querySelector('#pc-volver').addEventListener('click', function() {
      showCierreTipo(mesa, ticket);
    });
    confirmBtn.addEventListener('click', function() {
      if (!validar()) return;
      confirmBtn.disabled = true;
      var val = parseFloat(recibidoEl.value);
      var recibido = (recibidoEl.value.trim() === '' || isNaN(val)) ? (totalCents / 100) : val;
      apiCerrarTicket(ticket.id, metodoActivo(), mesa, recibido);
    });

    validar();
  }

  // ── Pago dividido por comensal ────────────────────────────
  function showPagoDividido(mesa, ticket, metodoDefault) {
    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-cerrar-confirm">';
    h += '<p class="mmodal-cerrar-confirm__sub">Cargando la cuenta…</p>';
    h += '</div>';
    modalContent.innerHTML = h;

    fetch('/api/ticket-items?ticket_id=' + ticket.id)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        renderPagoDividido(mesa, ticket, metodoDefault, (data.ok && data.items) ? data.items : []);
      })
      .catch(function() {
        alert('Error de conexión');
        showCierreTipo(mesa, ticket);
      });
  }

  function renderPagoDividido(mesa, ticket, metodoDefault, items) {
    // Se trabaja en centavos para que la validación sea exacta (sin errores de coma flotante)
    var totalCents = 0;
    var maxComensal = 0;

    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (it.estado === 'cancelado') continue;
      totalCents += Math.round(it.precio * 100) * it.cantidad;
      if (it.comensal && it.comensal > maxComensal) maxComensal = it.comensal;
    }

    if (totalCents <= 0) {
      alert('El ticket no tiene consumo por cobrar');
      showCierreTipo(mesa, ticket);
      return;
    }

    var n = Math.max(parseInt(ticket.comensales, 10) || 1, maxComensal, 1);

    function fmt(cents) {
      var v = (cents / 100).toFixed(2);
      return v.replace(/\.00$/, '');
    }

    // Reparte `cents` entre `n` comensales de forma exacta: el residuo de la
    // división se distribuye de a un centavo entre los primeros comensales.
    function repartir(cents, cantidad) {
      var base = Math.floor(cents / cantidad);
      var resto = cents - base * cantidad;
      var arr = [];
      for (var i = 0; i < cantidad; i++) arr.push(base + (i < resto ? 1 : 0));
      return arr;
    }

    // Monto (en centavos) que consumió cada comensal según sus ítems. Lo que se
    // pidió como "General" (sin comensal) se reparte por igual entre todos.
    function calcularPorCuenta() {
      var propios = [];
      var general = 0;
      var k;
      for (k = 0; k < n; k++) propios.push(0);
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        if (it.estado === 'cancelado') continue;
        var linea = Math.round(it.precio * 100) * it.cantidad;
        var c = parseInt(it.comensal, 10);
        if (c >= 1 && c <= n) propios[c - 1] += linea;
        else general += linea;
      }
      var reparto = repartir(general, n);
      for (k = 0; k < n; k++) propios[k] += reparto[k];
      return propios;
    }

    // Montos precalculados para cada modo de reparto.
    var modoIguales = repartir(totalCents, n);
    var modoCuenta  = calcularPorCuenta();

    var h = buildCerrarHeader(mesa, ticket);
    h += '<div class="mmodal-split">';
    h += '<p class="mmodal-cerrar-confirm__msg">Cuenta dividida por comensal</p>';
    h += '<p class="mmodal-cerrar-confirm__sub" style="margin-top:2px">Elige cómo repartir la cuenta; puedes ajustar cada monto.</p>';
    h += '<div class="mmodal-split-modes" role="group" aria-label="Modo de reparto">';
    h += '<button type="button" class="mmodal-split-mode" data-modo="iguales">Partes iguales</button>';
    h += '<button type="button" class="mmodal-split-mode" data-modo="cuenta">Cada quien su cuenta</button>';
    h += '<button type="button" class="mmodal-split-mode mmodal-split-mode--active" data-modo="vacio">Vacío</button>';
    h += '</div>';
    h += '<div class="mmodal-split-table">';
    h += '<div class="mmodal-split-row mmodal-split-row--head">';
    h += '<span>Comensal</span><span>Método</span><span>Pagó</span>';
    h += '</div>';
    for (var ci = 1; ci <= n; ci++) {
      h += '<div class="mmodal-split-row" data-c="' + ci + '">';
      h += '<span class="mmodal-split-name">Comensal ' + ci + '</span>';
      h += '<span class="mmodal-split-metodos">';
      h += '<button type="button" class="mmodal-split-metodo' +
           (metodoDefault === 'efectivo' ? ' mmodal-split-metodo--active' : '') +
           '" data-metodo="efectivo" title="Efectivo">' + svgIcon('cash', 18) + '</button>';
      h += '<button type="button" class="mmodal-split-metodo' +
           (metodoDefault === 'tarjeta' ? ' mmodal-split-metodo--active' : '') +
           '" data-metodo="tarjeta" title="Tarjeta">' + svgIcon('card', 18) + '</button>';
      h += '</span>';
      h += '<span class="mmodal-split-monto">$<input type="number" class="mmodal-split-input" min="0" step="0.01" inputmode="decimal" placeholder="0"></span>';
      h += '</div>';
    }
    h += '</div>';
    h += '<div class="mmodal-split-status">';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Total de la cuenta</span><span class="mmodal-total-amount">$' + fmt(totalCents) + '</span></div>';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Pagado</span><span class="mmodal-total-amount" id="split-pagado">$0</span></div>';
    h += '<div class="mmodal-total-row" id="split-propina-row" style="display:none"><span class="mmodal-total-label">Propina</span><span class="mmodal-total-amount" id="split-propina">$0</span></div>';
    h += '<p class="mmodal-split-diff" id="split-diff"></p>';
    h += '</div>';
    h += '<div class="mmodal-cerrar-confirm__btns">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="split-volver">← Volver</button>';
    h += '<button class="mmodal-btn mmodal-btn--danger" id="split-confirm" disabled>Cerrar ticket</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    var rows        = modalContent.querySelectorAll('.mmodal-split-row[data-c]');
    var pagadoEl    = modalContent.querySelector('#split-pagado');
    var diffEl      = modalContent.querySelector('#split-diff');
    var confirmBtn  = modalContent.querySelector('#split-confirm');
    var propinaRow  = modalContent.querySelector('#split-propina-row');
    var propinaEl   = modalContent.querySelector('#split-propina');

    function leerPagos() {
      var pagos = [];
      for (var r = 0; r < rows.length; r++) {
        var activo = rows[r].querySelector('.mmodal-split-metodo--active');
        var monto  = parseFloat(rows[r].querySelector('.mmodal-split-input').value);
        pagos.push({
          comensal: parseInt(rows[r].dataset.c, 10),
          metodo:   activo ? activo.dataset.metodo : metodoDefault,
          monto:    (isNaN(monto) || monto < 0) ? 0 : Math.round(monto * 100) / 100
        });
      }
      return pagos;
    }

    // Restaurar lo capturado si el mesero había salido a medias.
    var guardado = leerCierrePaso(ticket.id);
    if (guardado && guardado.step === 'dividido' && guardado.pagos) {
      for (var g = 0; g < rows.length; g++) {
        var sav = guardado.pagos[g];
        if (!sav) continue;
        if (sav.monto) rows[g].querySelector('.mmodal-split-input').value = sav.monto;
        var mbs = rows[g].querySelectorAll('.mmodal-split-metodo');
        for (var mi = 0; mi < mbs.length; mi++) {
          mbs[mi].classList.toggle('mmodal-split-metodo--active', mbs[mi].dataset.metodo === sav.metodo);
        }
      }
    }

    function persistir() {
      guardarCierrePaso(ticket.id, { step: 'dividido', pagos: leerPagos() });
    }

    // Validación en vivo: el botón se habilita cuando la suma cubre el total.
    // El excedente es propina (se muestra aparte).
    function validar() {
      var pagos = leerPagos();
      var suma  = 0;
      for (var p = 0; p < pagos.length; p++) suma += Math.round(pagos[p].monto * 100);

      if (pagadoEl) pagadoEl.textContent = '$' + fmt(suma);

      var diff    = suma - totalCents; // >0 = propina, <0 = falta
      var propina = diff > 1 ? diff : 0;
      var ok      = diff >= -1;

      if (propinaRow) propinaRow.style.display = propina > 0 ? '' : 'none';
      if (propinaEl)  propinaEl.textContent = '$' + fmt(propina);

      if (diffEl) {
        if (diff < -1) {
          diffEl.textContent = 'Faltan $' + fmt(-diff) + ' por asignar';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--falta';
        } else if (propina > 0) {
          diffEl.textContent = '✓ Incluye $' + fmt(propina) + ' de propina';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--ok';
        } else {
          diffEl.textContent = '✓ Los montos cubren el total';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--ok';
        }
      }
      if (confirmBtn) confirmBtn.disabled = !ok;
      return ok;
    }

    for (var r = 0; r < rows.length; r++) {
      (function(row) {
        row.querySelector('.mmodal-split-input').addEventListener('input', function() { validar(); persistir(); });
        var mbtns = row.querySelectorAll('.mmodal-split-metodo');
        for (var b = 0; b < mbtns.length; b++) {
          (function(btn) {
            btn.addEventListener('click', function() {
              for (var k = 0; k < mbtns.length; k++) mbtns[k].classList.remove('mmodal-split-metodo--active');
              btn.classList.add('mmodal-split-metodo--active');
              persistir();
            });
          })(mbtns[b]);
        }
      })(rows[r]);
    }

    // Selector de modo de reparto: precarga los montos por comensal según la
    // opción. En "vacío" los inputs quedan en 0 (placeholder), en los otros
    // modos se muestran los montos calculados como valor editable y placeholder.
    var modeBtns = modalContent.querySelectorAll('.mmodal-split-mode');

    function aplicarModo(modo) {
      for (var mb = 0; mb < modeBtns.length; mb++) {
        modeBtns[mb].classList.toggle('mmodal-split-mode--active', modeBtns[mb].dataset.modo === modo);
      }
      for (var r = 0; r < rows.length; r++) {
        var ci    = parseInt(rows[r].dataset.c, 10);
        var input = rows[r].querySelector('.mmodal-split-input');
        if (modo === 'vacio') {
          input.value = '';
          input.placeholder = '0';
          continue;
        }
        var cents = modo === 'iguales' ? modoIguales[ci - 1] : modoCuenta[ci - 1];
        input.value = fmt(cents);
        input.placeholder = fmt(cents);
      }
      validar();
      persistir();
    }

    for (var mb2 = 0; mb2 < modeBtns.length; mb2++) {
      (function(btn) {
        btn.addEventListener('click', function() { aplicarModo(btn.dataset.modo); });
      })(modeBtns[mb2]);
    }

    modalContent.querySelector('#split-volver').addEventListener('click', function() {
      showCierreTipo(mesa, ticket);
    });

    confirmBtn.addEventListener('click', function() {
      if (!validar()) return;
      confirmBtn.disabled = true;
      apiCerrarTicketDividido(ticket.id, leerPagos(), mesa);
    });

    validar();
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

  function apiAbrirTicket(mesaIds, comensales, reservaId, nombre, meseroId) {
    apiPost('/api/abrir-ticket', {
      mesa_ids: mesaIds,
      comensales: comensales, reservacion_id: reservaId,
      nombre: nombre || null,
      mesero_id: meseroId || null
    });
  }

  // Abre un ticket de Llevar y va directo al POS sin cerrar el modal
  function apiAbrirLlevarTicket(mesa, comensales, nombre, meseroId) {
    fetch('/api/abrir-ticket', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        mesa_ids:       [mesa.id],
        comensales:     comensales,
        nombre:         nombre || null,
        mesero_id:      meseroId || null,
        allow_multiple: true
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.ok) {
        var newTicket = {
          id:            result.id,
          mesa_ids:      [mesa.id],
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

  function apiCerrarTicket(ticketId, metodoPago, mesa, recibido) {
    fetch('/api/cerrar-ticket', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        ticket_id:          ticketId,
        metodo_pago:        metodoPago,
        separar_comensales: false,
        recibido:           recibido
      })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        limpiarCierrePaso(ticketId);
        showFeedbackQR(result.token, mesa ? mesa.nombre : '');
      } else {
        alert(result.msg || 'Error al cerrar el ticket');
      }
    })
    .catch(function() { alert('Error de conexión'); });
  }

  function apiCerrarTicketDividido(ticketId, pagos, mesa) {
    fetch('/api/cerrar-ticket', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        ticket_id:          ticketId,
        separar_comensales: true,
        pagos:              pagos
      })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        limpiarCierrePaso(ticketId);
        showFeedbackQR(result.token, mesa ? mesa.nombre : '');
      } else {
        alert(result.msg || 'Error al cerrar el ticket');
        var btn = modalContent.querySelector('#split-confirm');
        if (btn) btn.disabled = false;
      }
    })
    .catch(function() {
      alert('Error de conexión');
      var btn = modalContent.querySelector('#split-confirm');
      if (btn) btn.disabled = false;
    });
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
        // Antes de limpiar el carrito: cerrar el ciclo de lo que se sugirió
        // y pasar la siguiente recomendación.
        avanzarSugerenciasEnviadas(commandaItems);
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

  // ── Selector compartido de fecha ─────────────────────────
  function initMapaCalendar() {
    var picker = document.getElementById('mapa-date-picker');
    if (!picker || !fechaInput || typeof window.createReservationDatePicker !== 'function') return;

    window.createReservationDatePicker({
      root: picker,
      input: fechaInput,
      initialValue: fechaInput.value,
      today: picker.getAttribute('data-today-date'),
      allowPast: true,
      onChange: function(val) {
        document.dispatchEvent(new CustomEvent('operational:contextchange', {
          detail: { fecha: val, hora: '' }
        }));
        stopPolling();
        fetchData(val, false);
        startPolling();
      }
    });
  }

  // ── Fetch datos ───────────────────────────────────────────
  function fetchData(fecha, silent) {
    if (!silent) {
      if (loadingEl) loadingEl.classList.remove('hidden');
      reservasList.innerHTML =
        '<div class="mapa-empty-state"><span class="mapa-empty-icon">◌</span><span>Cargando…</span></div>';
    }
    fetch('/api/punto-de-venta?fecha=' + encodeURIComponent(fecha))
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
        meseros       = data.meseros        || [];
        if (!silent) renderMesas();
        renderEstados();
        renderSidebar();
        if (updateStatus) {
          updateStatus.textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-MX', {
            hour: '2-digit',
            minute: '2-digit'
          });
        }
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
    if (ahoraBtn)  ahoraBtn.classList.add('mapa-ahora-btn--active');
    syncLive();
    if (liveInterval) clearInterval(liveInterval);
    liveInterval = setInterval(syncLive, 60000);
    startPolling();
  }

  function deactivateLive() {
    isLive = false;
    if (ahoraBtn)  ahoraBtn.classList.remove('mapa-ahora-btn--active');
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
  }

  // ── Eventos ───────────────────────────────────────────────
  initMapaCalendar();

  if (modalBd)    modalBd.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (manageReservationsBtn) manageReservationsBtn.addEventListener('click', openReservationManager);
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
