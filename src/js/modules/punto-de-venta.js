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
    seleccionMultiple: true,
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
  var ticketSelectionMode = false;
  var selectedMesaIds = [];
  var ticketSelectionState = {
    mode: 'idle',
    pendingAction: null,
    warningConfirmed: false,
    opening: false
  };
  var ticketRequestInFlight = false;
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
  // Ya se pidieron sugerencias para el ticket abierto: evita que la pestaña de
  // móvil vuelva a lanzar la consulta cada vez que se toca.
  var sugPedidas          = false;
  var sugComensalesCount  = 0;

  // n8n decide la etapa (ENTRADAS/DESARROLLO/CIERRE) con el tiempo de la mesa
  // y lo ya pedido; se re-consulta cada tanto por si la mesa cambió de etapa
  // con el modal abierto.
  var SUG_REFRESCO = 5 * 60 * 1000;
  var sliderMin     = 0;
  var isLive        = false;
  var liveInterval  = null;
  var pollTimer     = null;
  var temporalConfig = window.CP_RESERVATION_OPERATION_CONFIG || {};
  var POS_REQUEST_TIMEOUT_MS = 15000;
  var serverClockOffsetMs = 0;

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
  var selectionToggle = $('#pos-ticket-selection-toggle');
  var selectionCancel = $('#pos-ticket-selection-cancel');
  var selectionMessage = $('#pos-ticket-selection-message');
  var selectionShell = canvas.closest('.pos-map');
  var modal         = $('#mesa-modal');
  var modalContent  = $('#mesa-modal-content');
  var modalBd       = $('#mesa-modal-bd');
  var modalClose    = $('#mesa-modal-close');

  // Labels de estado temporal para el sidebar
  var TEMPORAL_LABELS = {
    'future': 'Futura',
    'warning': 'Reservaci\u00f3n pr\u00f3xima',
    'service_window': 'Puede iniciar servicio',
    'tolerance': 'Dentro de la tolerancia',
    'overdue': 'Tolerancia vencida',
    'en-curso': 'En curso',
    'cancelada': 'Cancelada'
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

  function temporalNumber(key) {
    var value = parseInt(temporalConfig[key] || '0', 10);
    return value > 0 ? value : 0;
  }

  function operationalTimeZone() {
    return String(temporalConfig.zona_horaria || 'America/Mexico_City');
  }

  function ahoraOperativa() {
    return new Date(Date.now() + serverClockOffsetMs);
  }

  function partesRelojOperativo(instante) {
    var reloj = instante instanceof Date ? instante : ahoraOperativa();
    try {
      var partes = new Intl.DateTimeFormat('en-CA', {
        timeZone: operationalTimeZone(),
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
      }).formatToParts(reloj);
      var valores = {};
      partes.forEach(function(parte) { valores[parte.type] = parte.value; });
      return {
        fecha: valores.year + '-' + valores.month + '-' + valores.day,
        hora: parseInt(valores.hour, 10),
        minuto: parseInt(valores.minute, 10)
      };
    } catch (error) {
      return {
        fecha: reloj.getFullYear() + '-' + String(reloj.getMonth() + 1).padStart(2, '0') + '-' +
          String(reloj.getDate()).padStart(2, '0'),
        hora: reloj.getHours(),
        minuto: reloj.getMinutes()
      };
    }
  }

  function sincronizarRelojOperativo(valor) {
    var timestamp = Date.parse(String(valor || ''));
    if (Number.isFinite(timestamp)) {
      serverClockOffsetMs = timestamp - Date.now();
    }
  }

  function fechaHoraOperativa(fecha, hora) {
    var partesFecha = String(fecha || '').split('-').map(Number);
    var partesHora = String(hora || '').substring(0, 5).split(':').map(Number);
    var year = partesFecha[0];
    var month = partesFecha[1];
    var day = partesFecha[2];
    var hours = partesHora[0];
    var minutes = partesHora[1];
    if (!year || !month || !day || !Number.isFinite(hours) || !Number.isFinite(minutes)) {
      return new Date(NaN);
    }

    try {
      var targetUtc = Date.UTC(year, month - 1, day, hours, minutes, 0, 0);
      var guessUtc = targetUtc;
      var formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: operationalTimeZone(),
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
      });
      for (var intento = 0; intento < 3; intento++) {
        var partes = formatter.formatToParts(new Date(guessUtc));
        var valores = {};
        partes.forEach(function(parte) { valores[parte.type] = parseInt(parte.value, 10); });
        var representadoUtc = Date.UTC(
          valores.year,
          valores.month - 1,
          valores.day,
          valores.hour,
          valores.minute,
          valores.second || 0,
          0
        );
        guessUtc = targetUtc - (representadoUtc - guessUtc);
      }
      return new Date(guessUtc);
    } catch (error) {
      return new Date(year, month - 1, day, hours, minutes, 0, 0);
    }
  }

  function snapToReservationInterval(min) {
    var interval = temporalNumber('intervalo_reservacion_minutos');
    return interval > 0 ? Math.round(min / interval) * interval : min;
  }

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
    card:    '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
    star:    '<path d="m12 3 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.2l5.9-.9Z"/>'
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
      sugPedidas  = true;
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
      if (document.hidden) return;
      if (!modal || !modal.classList.contains('mesa-modal--open')) { sugTimerStop(); return; }
      if (!sugTicket || sugTicket.id !== ticket.id) { sugTimerStop(); return; }
      cargarSugerencias(ticket, { silencioso: true });
    }, SUG_REFRESCO);
  }

  function sugTimerStop() {
    if (sugTimer) { clearInterval(sugTimer); sugTimer = null; }
  }

  /**
   * Deja el panel listo para pedir sugerencias SIN llamar a n8n.
   *
   * Abrir una mesa no debe disparar el webhook: el servidor de desarrollo
   * (php -S) atiende una petición a la vez, así que esos hasta 8 s de techo
   * bloqueaban a /api/ticket-items, que es lo único que el mesero necesita ver
   * de inmediato. Regla: una llamada a n8n por intención explícita.
   */
  function prepararSugerencias(ticket) {
    sugTicket   = ticket;
    SUGERENCIAS = [];
    sugCola     = [];
    sugVistos   = [];
    sugEtapa    = '';
    sugPedidas  = false;
    sugTimerStop();
    // El botón del estado ocioso ya está en el DOM; sin sugTicket no responde.
    bindSugMore();
  }

  /** Una sola carga automática por apertura de modal (pestaña de móvil). */
  function asegurarSugerencias(ticket) {
    if (sugPedidas || !ticket) return;
    cargarSugerencias(ticket);
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


  function resolverVentanaOperativaReservacion(reserva, ahora) {
    var reloj = ahora instanceof Date ? ahora : ahoraOperativa();
    var fecha = String(reserva && reserva.fecha || '');
    var hora = String(reserva && reserva.hora || '').substring(0, 5);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha) || !/^\d{2}:\d{2}$/.test(hora)) {
      return {
        estado: 'future',
        minutos_restantes: null,
        minutos_retraso: 0,
        etiqueta: 'Reservaci\u00f3n futura',
        mensaje: ''
      };
    }

    var inicio = fechaHoraOperativa(fecha, hora);
    var segundosRestantes = inicio.getTime() - reloj.getTime();
    var minutosRestantes = Math.ceil(segundosRestantes / 60000);
    var minutosRetraso = segundosRestantes < 0
      ? Math.ceil(Math.abs(segundosRestantes) / 60000)
      : 0;
    var aviso = temporalNumber('advertencia_reservacion_minutos');
    var bloqueo = temporalNumber('bloqueo_previo_minutos');
    var tolerancia = temporalNumber('tolerancia_llegada_minutos');
    var estado = segundosRestantes > aviso * 60000
      ? 'future'
      : segundosRestantes > bloqueo * 60000
        ? 'warning'
        : segundosRestantes >= 0
          ? 'service_window'
          : segundosRestantes > -tolerancia * 60000
            ? 'tolerance'
            : 'overdue';
    var etiqueta = {
      future: 'Reservaci\u00f3n futura',
      warning: 'Reservaci\u00f3n en ' + minutosRestantes + ' min',
      service_window: 'Puede iniciar servicio',
      tolerance: 'Dentro de la tolerancia',
      overdue: 'Tolerancia vencida'
    }[estado];
    var mensaje = {
      future: '',
      warning: 'Podr\u00e1s iniciar el servicio dentro de ' +
        Math.max(0, minutosRestantes - bloqueo) + ' minutos.',
      service_window: 'Puede iniciar servicio.',
      tolerance: 'Cliente con ' + minutosRetraso +
        ' minutos de retraso. Se encuentra dentro del tiempo de tolerancia.',
      overdue: 'Tolerancia vencida. El cliente lleva ' + minutosRetraso +
        ' minutos de retraso.'
    }[estado];

    return {
      estado: estado,
      minutos_restantes: minutosRestantes,
      minutos_retraso: minutosRetraso,
      etiqueta: etiqueta,
      mensaje: mensaje,
      hora: hora,
      inicio: inicio
    };
  }

  function relojMapa(minActual) {
    var fecha = fechaInput && /^\d{4}-\d{2}-\d{2}$/.test(String(fechaInput.value || ''))
      ? String(fechaInput.value)
      : fechaHoyLocal();
    var minutosMapa = Number.isFinite(Number(minActual)) ? Number(minActual) : 0;
    return fechaHoraOperativa(fecha, formatTime(minutosMapa));
  }

  // Estado temporal de una reservacion respecto al reloj del mapa.
  function temporalEstadoReserva(r) {
    if (r.estado === 'cancelada') return 'cancelada';
    if (r.estado === 'en_curso') return 'en-curso';
    return resolverVentanaOperativaReservacion(r, relojMapa(sliderMin)).estado;
  }

  /**
   * Usa las listas del backend y valida la retención pendiente con el reloj
   * real. Un estado final nunca vuelve a ocupar por cálculos del slider.
   */
  function reservacionInfluye(reserva) {
    return Boolean(reserva && reserva.influye_disponibilidad === true);
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
    if (nombres.length <= 1) return nombres[0] || '';
    var etiquetas = nombres.map(function(nombre) {
      return String(nombre).replace(/^Mesa\s+/i, '');
    });
    return 'Mesas ' + (etiquetas.length === 2
      ? etiquetas.join(' y ')
      : etiquetas.slice(0, -1).join(', ') + ' y ' + etiquetas[etiquetas.length - 1]);
  }

  function reservaParaModal(mesaId) {
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (!reservacionOperativa(r)) continue;
      if (!reservaTieneMesa(r, mesaId)) continue;
      return r;
    }
    return null;
  }

  function reservacionPorId(reservacionId) {
    for (var i = 0; i < reservaciones.length; i++) {
      if (parseInt(reservaciones[i].id, 10) === parseInt(reservacionId, 10)) {
        return reservaciones[i];
      }
    }
    return null;
  }

  function fechaHoyLocal() {
    return partesRelojOperativo().fecha;
  }

  function reservacionOperativa(reserva) {
    return Boolean(reserva && (
      reservacionInfluye(reserva)
      || ['confirmada', 'en_curso'].indexOf(String(reserva.estado || '')) !== -1
    ));
  }

  // ── Estado de mesa según slider (incluye tickets abiertos) ─
  function estadoMesa(mesaId, minActual) {
    if (ticketActual(mesaId)) return 'con-ticket';
    var estado = 'libre';
    var reloj = relojMapa(minActual);
    for (var i = 0; i < reservaciones.length; i++) {
      var r = reservaciones[i];
      if (!reservacionInfluye(r)) continue;
      if (!reservaTieneMesa(r, mesaId)) continue;
      if (r.estado === 'en_curso') return 'ocupada';
      var ventana = resolverVentanaOperativaReservacion(r, reloj);
      if (ventana.estado === 'warning' && estado === 'libre') estado = 'proxima';
      if (['service_window', 'tolerance', 'overdue'].indexOf(ventana.estado) !== -1) {
        return 'bloqueada';
      }
    }
    return estado;
  }

  // Estado con tiempo real (para el modal)
  function estadoMesaActual(mesaId) {
    var mesa = mesaPorId(mesaId);
    if (!isLlevar(mesa) && ticketActual(mesaId)) return 'con-ticket';
    var now    = partesRelojOperativo();
    var minNow = now.hora * 60 + now.minuto;
    return estadoMesa(mesaId, minNow);
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

  function minutoConsultaMapa() {
    if (!isLive) return sliderMin;
    var now = partesRelojOperativo();
    return now.hora * 60 + now.minuto;
  }

  function reservacionProximaMesa(mesaId, minActual) {
    var reloj = relojMapa(minActual);
    var candidate = null;
    for (var i = 0; i < reservaciones.length; i++) {
      var reservation = reservaciones[i];
      if (!reservacionInfluye(reservation) || !reservaTieneMesa(reservation, mesaId)) continue;
      var ventana = resolverVentanaOperativaReservacion(reservation, reloj);
      if (ventana.estado === 'future') continue;
      if (!candidate || ventana.minutos_restantes < candidate.minutos_restantes) {
        candidate = {
          id: parseInt(reservation.id, 10),
          folio: '#' + reservation.id,
          nombre: reservation.nombre || '',
          hora: String(reservation.hora || '').substring(0, 5),
          comensales: parseInt(reservation.comensales || reservation.personas || '0', 10),
          mesa_ids: mesaIdsReserva(reservation),
          mesas: Array.isArray(reservation.mesas) ? reservation.mesas : [],
          ventana_operativa: ventana.estado,
          minutos_restantes: ventana.minutos_restantes,
          minutos_retraso: ventana.minutos_retraso,
          nivel: ventana.estado === 'warning' ? 'advertencia' : 'bloqueo'
        };
      }
    }
    return candidate;
  }

  function reservacionesProximasParaTicket(ticket) {
    var resultado = [];
    var vistos = {};
    var ids = Array.isArray(ticket && ticket.mesa_ids) ? ticket.mesa_ids : [];
    var reloj = relojMapa(minutoConsultaMapa());

    ids.forEach(function(mesaId) {
      for (var i = 0; i < reservaciones.length; i++) {
        var reserva = reservaciones[i];
        if (!reservacionInfluye(reserva) || !reservaTieneMesa(reserva, Number(mesaId))) continue;
        var ventana = resolverVentanaOperativaReservacion(reserva, reloj);
        if (ventana.estado !== 'warning') continue;
        var clave = String(reserva.id) + ':' + String(mesaId);
        if (vistos[clave]) continue;
        vistos[clave] = true;
        resultado.push({
          reservacion: reserva,
          mesaId: Number(mesaId),
          minutos_restantes: ventana.minutos_restantes,
          nivel: 'advertencia'
        });
      }
    });
    return resultado;
  }

  function horaCortaTicket(ticket) {
    var match = String(ticket && ticket.hora_apertura || '').match(/(?:T|\s)(\d{2}:\d{2})/);
    return match ? match[1] : '';
  }

  function tituloMesaMapa(mesa, estado, ticket, proxima) {
    var partes = [String(mesa.nombre || 'Mesa') + '.'];
    var ticketHora = horaCortaTicket(ticket);
    var elementoOperativo = mesa.tipo === 'barra' || esCaja(mesa) || isLlevar(mesa);

    if (ticket) {
      partes.push('Ocupada. Ticket abierto.');
      if (Number(ticket.comensales) > 0) {
        partes.push(String(ticket.comensales) + (Number(ticket.comensales) === 1 ? ' comensal.' : ' comensales.'));
      }
      if (ticketHora) partes.push('Abierto a las ' + ticketHora + '.');
    } else if (esCaja(mesa)) {
      partes.push('Caja operativa. Abre el corte de caja.');
    } else if (isLlevar(mesa)) {
      partes.push('Pedidos para llevar. Disponible para un nuevo pedido.');
    } else if (mesa.tipo === 'barra') {
      partes.push('Barra operativa. Disponible para abrir un ticket.');
    } else if (!mesaReservable(mesa) && !elementoOperativo) {
      partes.push('No utilizable.');
    } else if (estado === 'ocupada') {
      partes.push('Ocupada por una reservaci\u00f3n en curso.');
    } else if (estado === 'bloqueada' && !proxima) {
      partes.push('No disponible por una reservaci\u00f3n pr\u00f3xima.');
    } else if (!proxima) {
      partes.push('Disponible.');
    }

    if (proxima) {
      var ventana = proxima.ventana_operativa || 'warning';
      if (ventana === 'warning') {
        if (!ticket) {
          return String(mesa.nombre || 'Mesa') + ', disponible, reservaci\u00f3n dentro de ' +
            proxima.minutos_restantes + ' minutos.';
        }
        partes.push('Reservaci\u00f3n dentro de ' + proxima.minutos_restantes + ' minutos.');
      } else if (ventana === 'service_window') {
        partes.push('Reservaci\u00f3n a las ' + proxima.hora + '. Puede iniciar servicio.');
      } else if (ventana === 'tolerance') {
        partes.push('Reservaci\u00f3n a las ' + proxima.hora + '. Cliente con ' +
          proxima.minutos_retraso + ' minutos de retraso. Se encuentra dentro del tiempo de tolerancia.');
      } else if (ventana === 'overdue') {
        partes.push('Reservaci\u00f3n con tolerancia vencida. El cliente lleva ' +
          proxima.minutos_retraso + ' minutos de retraso.');
      }
    } else {
      var reservaContextual = reservaParaModal(parseInt(mesa.id, 10));
      if (reservaContextual) {
        var ventanaContextual = resolverVentanaOperativaReservacion(reservaContextual, ahoraOperativa());
        if (ventanaContextual.estado !== 'future') {
          partes.push(ventanaContextual.mensaje);
        }
      }
    }
    if (ticket && Array.isArray(ticket.mesa_ids) && ticket.mesa_ids.length > 1) {
      partes.push('Servicio vinculado a ' + ticket.mesa_ids.length + ' mesas.');
    }
    return partes.join(' ');
  }

  /**
   * Adapta el estado local del slider al contrato común. Las ventanas ya
   * provienen de config y MapaVisual sólo dibuja este resultado.
   */
  function contratoMesaMapa(mesa, estado, minActual) {
    var ticket = ticketActual(parseInt(mesa.id, 10));
    var proxima = reservacionProximaMesa(parseInt(mesa.id, 10), minActual);
    var stateBase = (!mesaReservable(mesa) && !mesaTicketable(mesa))
      ? 'no_reservable'
      : (ticket || estado === 'ocupada' || estado === 'con-ticket' || estado === 'en-curso'
        ? 'ocupada'
        : (estado === 'bloqueada' ? 'bloqueada' : 'disponible'));
    var modifiers = [];

    if (ticket) {
      modifiers.push('ticket_abierto');
      if (ticket.origen === 'walk_in') {
        modifiers.push('walk_in');
      }
      if ((ticket.mesa_ids || []).length > 1) {
        // Expone la relación N:M sin sustituir el estado físico principal.
        modifiers.push('varias_mesas');
      }
    }
    if (proxima) {
      modifiers.push('reservacion_proxima');
      if (proxima.ventana_operativa === 'warning') {
        modifiers.push('reservacion_advertencia');
      } else if (proxima.ventana_operativa === 'service_window') {
        modifiers.push('reservacion_inminente', 'reservacion_bloqueante');
      } else if (proxima.ventana_operativa === 'tolerance') {
        modifiers.push('reservacion_tolerancia', 'reservacion_bloqueante');
      } else if (proxima.ventana_operativa === 'overdue') {
        modifiers.push('reservacion_vencida', 'reservacion_bloqueante');
      } else if (proxima.nivel === 'bloqueo') {
        modifiers.push('reservacion_bloqueante');
      }
    }

    var normalized = Object.assign({}, mesa, {
      estado_base: stateBase,
      modificadores: modifiers,
      reservacion_proxima: proxima,
      minutos_restantes: proxima ? proxima.minutos_restantes : null,
      ticket_abierto: ticket ? { id: ticket.id, reservacion_id: ticket.reservacion_id } : null,
      walk_in: Boolean(ticket && ticket.origen === 'walk_in'),
      seleccion_actual: selectedMesaIds.indexOf(parseInt(mesa.id, 10)) !== -1,
      motivo_bloqueo: estado === 'bloqueada' ? 'Bloqueada por reservación próxima.' : null,
      titulo: tituloMesaMapa(mesa, estado, ticket, proxima)
    });
    return normalized;
  }

  function opcionesVisualesMesa(mesa, estado) {
    var ticket = ticketActual(parseInt(mesa.id, 10));
    var ticketable = mesaTicketable(mesa);
    var seleccionValida = ticketSelectionMode
      ? mesaPuedeSeleccionarse(mesa, estado)
      : mesaReservable(mesa) && !ticket;
    return {
      x: insetPos(mesa.pos_x),
      y: insetPos(mesa.pos_y),
      ancho: mesa.ancho,
      alto: mesa.alto,
      interactivo: ticketSelectionMode
        ? seleccionValida
        : ticketable || esCaja(mesa),
      seleccionValida: seleccionValida,
      seleccionActual: selectedMesaIds.indexOf(parseInt(mesa.id, 10)) !== -1,
      noUtilizable: !mesaTicketable(mesa) && !esCaja(mesa),
      clasesEstado: !mesaReservable(mesa) && !mesaTicketable(mesa) ? ['mesa-pin--no-utilizable'] : [],
      atributos: {
        'data-id': mesa.id,
        'data-numero': mesa.numero == null ? '' : mesa.numero,
        'data-reservable': mesa.reservable,
        'data-ticketable': ticketable ? '1' : '0',
        'data-estado': estado,
        'data-ticket-id': ticket ? ticket.id : ''
      }
    };
  }

  function mesaPuedeSeleccionarse(mesa, estado) {
    if (!ticketSelectionMode || !mesa || !mesaReservable(mesa)) return false;
    if (ticketActual(parseInt(mesa.id, 10))) return false;
    if (estado !== 'libre' && estado !== 'proxima') return false;

    var reserva = reservaParaModal(parseInt(mesa.id, 10));
    if (!reserva) return true;

    var ventana = resolverVentanaOperativaReservacion(
      reserva,
      relojMapa(minutoConsultaMapa())
    );
    return ['future', 'warning'].indexOf(ventana.estado) !== -1;
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
    var minute = minutoConsultaMapa();
    var estado = ticketable ? estadoMesa(parseInt(mesa.id, 10), minute) : 'zona';
    var contract = contratoMesaMapa(mesa, estado, minute);

    return window.MesaEstadoAdapter.paraMapaVisual(
      contract,
      opcionesVisualesMesa(mesa, estado)
    );
  }

  function renderEstados() {
    for (var i = 0; i < mesas.length; i++) {
      var mesa = mesas[i];
      if (!mesaTicketable(mesa)) continue;

      var minute = minutoConsultaMapa();
      var estado = estadoMesa(parseInt(mesa.id, 10), minute);
      var visual = window.MesaEstadoAdapter.paraMapaVisual(
        contratoMesaMapa(mesa, estado, minute),
        opcionesVisualesMesa(mesa, estado)
      );
      mapVisual.actualizarEstado(mesa.id, {
        estadoVisual: visual.estadoVisual,
        seleccionada: visual.seleccionada,
        modificadores: visual.modificadores,
        clasesEstado: visual.clasesEstado,
        titulo: visual.titulo,
        atributos: visual.atributos
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

      if (r.estado === 'cancelada' || tempEstado === 'overdue') cardClasses.push('reserva-card--pasada');
      else if (['en-curso', 'service_window', 'tolerance'].indexOf(tempEstado) !== -1) {
        cardClasses.push('reserva-card--activa');
      } else if (tempEstado === 'warning') cardClasses.push('reserva-card--proxima');

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
    if (ticketSelectionState.opening) return;
    if (ticketSelectionMode) {
      mesaSeleccionadaToggle(mesaId);
      return;
    }
    if (esCaja(mesa)) {
      showCajaModal(mesa);
      return;
    }
    var canOpen = mesaTicketable(mesa) || esCaja(mesa);
    if (!canOpen) return;
    var ticket = ticketActual(mesaId);
    if (ticket) {
      if (isLlevar(mesa)) showLlevarModal(mesa);
      else showModal(mesa, 'con-ticket');
      return;
    }
    var reserva = reservaParaModal(mesaId);
    var ventanaReserva = reserva
      ? resolverVentanaOperativaReservacion(reserva, relojMapa(minutoConsultaMapa()))
      : null;
    var reservaCercana = reserva && ventanaReserva && ventanaReserva.estado !== 'future';
    if (reservaCercana) {
      showReservationModal(reserva);
      return;
    }
    if (isLlevar(mesa)) {
      showLlevarModal(mesa);
    } else {
      showModal(mesa, estadoMesaActual(mesaId));
    }
  }

  // ── Selección multimesa para apertura de ticket ───────────
  function actualizarMensajeSeleccion() {
    if (!selectionMessage) return;
    if (!ticketSelectionMode) {
      selectionMessage.hidden = true;
      return;
    }
    selectionMessage.hidden = false;
    selectionMessage.textContent = selectedMesaIds.length
      ? selectedMesaIds.length + (selectedMesaIds.length === 1
        ? ' mesa seleccionada. Confirma para abrir el ticket.'
        : ' mesas seleccionadas. Confirma para abrir el ticket.')
      : 'Selecciona una o más mesas para abrir el ticket.';
  }

  function actualizarControlesApertura() {
    if (selectionToggle) {
      selectionToggle.textContent = ticketSelectionState.opening
        ? 'Abriendo ticket…'
        : (ticketSelectionMode ? 'Confirmar apertura' : 'Abrir ticket');
      selectionToggle.setAttribute('aria-pressed', ticketSelectionMode ? 'true' : 'false');
      if (ticketSelectionState.opening) {
        selectionToggle.setAttribute('aria-busy', 'true');
      } else {
        selectionToggle.removeAttribute('aria-busy');
      }
      var disabled = ticketSelectionState.opening
        || (ticketSelectionMode && !hayMesasSeleccionadasValidas());
      selectionToggle.disabled = disabled;
      selectionToggle.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      selectionToggle.classList.toggle('is-active', ticketSelectionMode && !ticketSelectionState.opening);
    }
    if (selectionCancel) {
      selectionCancel.hidden = !ticketSelectionMode;
      selectionCancel.disabled = ticketSelectionState.opening;
    }
    actualizarMensajeSeleccion();
  }

  function actualizarModoSeleccion() {
    if (selectionShell) {
      selectionShell.classList.toggle('is-ticket-selection-mode', ticketSelectionMode);
    }
    actualizarControlesApertura();
    if (!mapVisual) return;
    renderMesas();
    renderEstados();
    mapVisual.setSeleccionadas(selectedMesaIds);
  }

  function activarModoSeleccion() {
    if (ticketSelectionState.opening) return;
    if (ticketSelectionMode) {
      confirmarAperturaSeleccion();
      return;
    }
    selectedMesaIds = [];
    ticketSelectionMode = true;
    ticketSelectionState.mode = 'ticket-selection';
    ticketSelectionState.pendingAction = 'open-walk-in-ticket';
    ticketSelectionState.warningConfirmed = false;
    ticketSelectionState.opening = false;
    actualizarModoSeleccion();
  }

  function cancelarModoSeleccion() {
    if (ticketSelectionState.opening) return;
    ticketSelectionMode = false;
    selectedMesaIds = [];
    ticketSelectionState.mode = 'idle';
    ticketSelectionState.pendingAction = null;
    ticketSelectionState.warningConfirmed = false;
    ticketSelectionState.opening = false;
    actualizarModoSeleccion();
  }

  function mesaSeleccionadaToggle(mesaId) {
    var mesa = mesaPorId(mesaId);
    if (!mesa || !mesaPuedeSeleccionarse(mesa, estadoMesaActual(mesaId))) return;

    var index = selectedMesaIds.indexOf(Number(mesaId));
    if (index === -1) {
      selectedMesaIds.push(Number(mesaId));
      selectedMesaIds.sort(function(a, b) { return a - b; });
    } else {
      selectedMesaIds.splice(index, 1);
    }
    actualizarControlesApertura();
    mapVisual.setSeleccionadas(selectedMesaIds);
    renderEstados();
  }

  function hayMesasSeleccionadasValidas() {
    if (!ticketSelectionMode) return false;
    return selectedMesaIds.some(function(selectedMesaId) {
      var mesa = mesaPorId(selectedMesaId);
      return mesa && mesaPuedeSeleccionarse(mesa, estadoMesaActual(selectedMesaId));
    });
  }

  function confirmarAperturaSeleccion() {
    var tableIds = selectedMesaIds.filter(function(mesaId) {
      var mesa = mesaPorId(mesaId);
      return mesa && mesaPuedeSeleccionarse(mesa, estadoMesaActual(mesaId));
    });
    if (ticketSelectionState.opening || tableIds.length === 0) {
      actualizarMensajeSeleccion();
      if (selectionMessage) selectionMessage.focus();
      return;
    }
    requestOpenTicket({
      mesa_ids: tableIds.slice(),
      comensales: 2,
      nombre: null,
      mesero_id: null
    }, { selection: true });
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
    if (ticketSelectionMode || ticketSelectionState.opening) return;
    clearHighlight();
    highlightReserva(reservaId, mesaIds);
    var reserva = reservacionPorId(reservaId);
    if (reserva) showReservationModal(reserva);
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
    mapVisual.setSeleccionadas(mesaIds.filter(function (mesaId) {
      var mesa = mesaPorId(mesaId);
      return mesa && mesaReservable(mesa) && !ticketActual(mesaId);
    }));
  }

  // ── Modal ─────────────────────────────────────────────────
  function showModal(mesa, estado, reservaOverride, ticketOverride) {
    if (!modal || !modalContent) return;
    commandaItems    = [];
    selectedComensal = 0;
    var reserva = reservaOverride === undefined
      ? reservaParaModal(mesa.id)
      : reservaOverride;
    var ticket  = ticketOverride || ticketActual(mesa.id);
    modalContent.innerHTML = buildModalContent(mesa, estado, reserva, ticket);
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';
    // Detrás del modal no se ve el mapa, y el sondeo de 30 s compite por el
    // único hilo del servidor de desarrollo. Se reanuda al cerrar.
    stopPolling();
    bindModalActions(mesa, reserva, ticket);
  }

  function showReservationModal(reserva) {
    if (!modal || !modalContent || !reserva || ticketSelectionMode || ticketSelectionState.opening) return;

    var mesaIds = mesaIdsReserva(reserva);
    var mesa = mesaIds.length ? mesaPorId(mesaIds[0]) : null;
    if (!mesa) {
      mesa = {
        id: 0,
        nombre: 'Reservación #' + reserva.id,
        numero: null,
        reservable: false,
        tipo: 'especial'
      };
    }

    var estado = reserva.estado === 'en_curso'
      ? 'ocupada'
      : 'reservada';
    commandaItems = [];
    selectedComensal = 0;
    modalContent.innerHTML = buildModalContent(mesa, estado, reserva, null);
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';
    bindModalActions(mesa, reserva, null);
  }

  function closeModal(options) {
    if (!modal) return;
    options = options || {};
    modal.classList.remove('mesa-modal--open');
    document.body.style.overflow = '';
    sugTimerStop();
    sugTicket = null;
    sugPedidas = false;
    startPolling();
    // Puesta al día de golpe: recupera lo que no se refrescó con el modal abierto.
    return options.refresh === false ? null : silentRefresh();
  }

  // Panel operativo de reservaciones: permanece dentro del POS y no mezcla
  // walk-ins, porque éstos sólo existen como tickets abiertos.

  // ── Preferencias del modal, por mesero ────────────────────
  //
  // La clave lleva el id de usuario porque la tablet es compartida: sin eso
  // cada mesero heredaba la configuración del turno anterior. Se guarda en
  // localStorage (no en la BD) para que funcione sin red y sin latencia; si
  // algún día hace falta que el setup siga al mesero entre tablets, se sube a
  // una tabla `usuario_preferencias` sin tocar esta interfaz.
  var POS_PREFS_BASE = 'cp-pos-prefs';
  var POS_LEGACY_LAYOUT_KEY = 'cp-pos-modal-layout';

  var POS_OPCIONES = {
    layout:    ['menu', 'balanced', 'compact'],
    densidad:  ['comoda', 'compacta'],
    texto:     ['s', 'm', 'l'],
    toque:     ['normal', 'grande'],
    vistaMenu: ['grid', 'lista'],
    columnas:  ['2', '3', '4']
  };

  var POS_PREFS_DEFAULT = {
    layout: 'balanced',
    densidad: 'comoda',
    texto: 'm',
    toque: 'normal',
    vistaMenu: 'grid',
    columnas: '3',
    verSugerencias: true,
    verTicket: true,
    // Orden de las 4 columnas del modal por su clave.
    orden: ['menu', 'cart', 'resumen', 'sugerencias'],
    categoriaInicial: 0,
    favoritos: []
  };

  var POS_PANELES = {
    menu:        { label: 'Menú' },
    cart:        { label: 'Pedido' },
    resumen:     { label: 'Estado del ticket' },
    sugerencias: { label: 'Sugerencias' }
  };

  function posPrefsKey() {
    var uid = (window.CP_USER && window.CP_USER.id) ? window.CP_USER.id : 'anon';
    return POS_PREFS_BASE + ':' + uid;
  }

  var posPrefs = null;

  function getPosPrefs() {
    if (posPrefs) return posPrefs;

    var guardado = {};
    try {
      guardado = JSON.parse(localStorage.getItem(posPrefsKey()) || '{}') || {};
    } catch (e) { guardado = {}; }

    posPrefs = {};
    for (var k in POS_PREFS_DEFAULT) {
      if (POS_PREFS_DEFAULT.hasOwnProperty(k)) posPrefs[k] = POS_PREFS_DEFAULT[k];
    }

    // Solo se aceptan valores conocidos: una preferencia corrupta no debe
    // dejar el POS en un estado que el mesero no pueda deshacer.
    for (var key in POS_OPCIONES) {
      if (POS_OPCIONES.hasOwnProperty(key) &&
          POS_OPCIONES[key].indexOf(guardado[key]) !== -1) {
        posPrefs[key] = guardado[key];
      }
    }
    if (typeof guardado.verSugerencias === 'boolean') posPrefs.verSugerencias = guardado.verSugerencias;
    if (typeof guardado.verTicket === 'boolean') posPrefs.verTicket = guardado.verTicket;
    if (typeof guardado.categoriaInicial === 'number' && guardado.categoriaInicial >= 0) {
      posPrefs.categoriaInicial = guardado.categoriaInicial;
    }
    if (Object.prototype.toString.call(guardado.favoritos) === '[object Array]') {
      posPrefs.favoritos = guardado.favoritos.filter(function (n) { return typeof n === 'string'; });
    }
    if (Object.prototype.toString.call(guardado.orden) === '[object Array]') {
      var limpio = guardado.orden.filter(function (p) { return POS_PANELES.hasOwnProperty(p); });
      // Se completa con los que falten para no perder ningún panel.
      POS_PREFS_DEFAULT.orden.forEach(function (p) {
        if (limpio.indexOf(p) === -1) limpio.push(p);
      });
      posPrefs.orden = limpio;
    }

    // Migración del ajuste anterior, que era global y no por mesero.
    try {
      var viejo = localStorage.getItem(POS_LEGACY_LAYOUT_KEY);
      if (viejo && !guardado.layout && POS_OPCIONES.layout.indexOf(viejo) !== -1) {
        posPrefs.layout = viejo;
      }
    } catch (e) {}

    return posPrefs;
  }

  function setPosPref(clave, valor) {
    var prefs = getPosPrefs();
    prefs[clave] = valor;
    try { localStorage.setItem(posPrefsKey(), JSON.stringify(prefs)); } catch (e) {}
    aplicarPosPrefs();
  }

  /**
   * Vuelca las preferencias a atributos data- sobre .mmodal-panels. Toda la
   * variación es CSS: el JS no vuelve a renderizar nada.
   */
  function aplicarPosPrefs() {
    var panels = modalContent ? modalContent.querySelector('.mmodal-panels') : null;
    if (!panels) return;

    var p = getPosPrefs();
    panels.setAttribute('data-layout', p.layout);
    panels.setAttribute('data-densidad', p.densidad);
    panels.setAttribute('data-texto', p.texto);
    panels.setAttribute('data-toque', p.toque);
    panels.setAttribute('data-vista-menu', p.vistaMenu);
    panels.setAttribute('data-columnas', p.columnas);
    panels.setAttribute('data-ocultos',
      (p.verTicket ? '' : 'resumen ') + (p.verSugerencias ? '' : 'sugerencias'));

    // El orden se aplica con `order`; el DOM no se toca.
    p.orden.forEach(function (clave, i) {
      var el = panels.querySelector('#mmodal-panel-' + clave);
      if (el) el.style.order = String(i);
    });
  }

  function moverPanel(clave, delta) {
    var p = getPosPrefs();
    var orden = p.orden.slice();
    var i = orden.indexOf(clave);
    var j = i + delta;
    if (i === -1 || j < 0 || j >= orden.length) return;
    orden[i] = orden[j];
    orden[j] = clave;
    setPosPref('orden', orden);
  }

  function esFavorito(nombre) {
    return getPosPrefs().favoritos.indexOf(nombre) !== -1;
  }

  function toggleFavorito(nombre) {
    var favs = getPosPrefs().favoritos.slice();
    var i = favs.indexOf(nombre);
    if (i === -1) favs.push(nombre); else favs.splice(i, 1);
    setPosPref('favoritos', favs);
  }

  /** Categoría virtual "Favoritos" al frente de las pestañas del menú. */
  function categoriasConFavoritos() {
    var base = window.CP_MENU || [];
    var favs = getPosPrefs().favoritos;
    if (!favs.length) return base;

    var platillos = [];
    for (var i = 0; i < base.length; i++) {
      var dishes = base[i].dishes || [];
      for (var j = 0; j < dishes.length; j++) {
        if (favs.indexOf(dishes[j].n) !== -1) platillos.push(dishes[j]);
      }
    }
    if (!platillos.length) return base;

    return [{ id: -1, label: '★ Favoritos', dishes: platillos }].concat(base);
  }

  /**
   * Cuerpo del panel de ajustes. Se inyecta en #pos-prefs-panel, el contenedor
   * que emite pos-workspace.php: ese nodo lleva ya la clase .mmodal-prefs, así
   * que aquí solo se devuelven los grupos.
   */
  function panelAjustesHtml() {
    var p = getPosPrefs();

    function grupo(titulo, clave, opciones) {
      var s = '<div class="mmodal-prefs__row"><span class="mmodal-prefs__label">' + titulo + '</span>' +
              '<div class="mmodal-prefs__opts">';
      for (var i = 0; i < opciones.length; i++) {
        var activo = p[clave] === opciones[i][0] ? ' is-active' : '';
        s += '<button type="button" class="mmodal-prefs__opt' + activo + '" ' +
             'data-pref="' + clave + '" data-val="' + opciones[i][0] + '">' + opciones[i][1] + '</button>';
      }
      return s + '</div></div>';
    }

    function interruptor(titulo, clave) {
      return '<div class="mmodal-prefs__row"><span class="mmodal-prefs__label">' + titulo + '</span>' +
             '<div class="mmodal-prefs__opts">' +
             '<button type="button" class="mmodal-prefs__opt' + (p[clave] ? ' is-active' : '') + '" ' +
             'data-pref-bool="' + clave + '" data-val="1">Mostrar</button>' +
             '<button type="button" class="mmodal-prefs__opt' + (!p[clave] ? ' is-active' : '') + '" ' +
             'data-pref-bool="' + clave + '" data-val="0">Ocultar</button>' +
             '</div></div>';
    }

    var h = '';

    h += '<div class="mmodal-prefs__group"><h4>Layout y densidad</h4>';
    h += grupo('Columnas', 'layout', [['menu', 'Menú amplio'], ['balanced', 'Equilibrado'], ['compact', 'Compacto']]);
    h += grupo('Densidad', 'densidad', [['comoda', 'Cómoda'], ['compacta', 'Compacta']]);
    h += grupo('Texto', 'texto', [['s', 'A-'], ['m', 'A'], ['l', 'A+']]);
    h += grupo('Botones', 'toque', [['normal', 'Normal'], ['grande', 'Grandes']]);
    h += '</div>';

    h += '<div class="mmodal-prefs__group"><h4>Paneles</h4>';
    h += interruptor('Estado del ticket', 'verTicket');
    h += interruptor('Sugerencias', 'verSugerencias');
    h += '<div class="mmodal-prefs__orden">';
    for (var i = 0; i < p.orden.length; i++) {
      var clave = p.orden[i];
      h += '<div class="mmodal-prefs__orden-item">' +
             '<span>' + POS_PANELES[clave].label + '</span>' +
             '<span class="mmodal-prefs__orden-btns">' +
               '<button type="button" data-mover="' + clave + '" data-dir="-1" aria-label="Subir"' +
                 (i === 0 ? ' disabled' : '') + '>↑</button>' +
               '<button type="button" data-mover="' + clave + '" data-dir="1" aria-label="Bajar"' +
                 (i === p.orden.length - 1 ? ' disabled' : '') + '>↓</button>' +
             '</span>' +
           '</div>';
    }
    h += '</div></div>';

    h += '<div class="mmodal-prefs__group"><h4>Vista del menú</h4>';
    h += grupo('Presentación', 'vistaMenu', [['grid', 'Cuadrícula'], ['lista', 'Lista']]);
    h += grupo('Columnas de platillos', 'columnas', [['2', '2'], ['3', '3'], ['4', '4']]);
    h += '<p class="mmodal-prefs__hint">Marca la estrella de un platillo para tenerlo en Favoritos.</p>';
    h += '</div>';

    h += '<div class="mmodal-prefs__foot">' +
           '<button type="button" class="mmodal-prefs__reset" id="mmodal-prefs-reset">Restablecer</button>' +
         '</div>';

    return h;
  }

  function resetPosPrefs() {
    try { localStorage.removeItem(posPrefsKey()); } catch (e) {}
    posPrefs = null;
    aplicarPosPrefs();
  }

  // ── Overlay de ajustes (fuera del modal de mesa) ───────────
  var prefsOverlay = null;
  var prefsPanel   = null;
  var prefsToggle  = null;

  /** (Re)pinta el cuerpo del panel y lo vuelve a enlazar. */
  function renderPrefsPanel() {
    if (!prefsPanel) return;
    prefsPanel.innerHTML = panelAjustesHtml();
    bindPreferencias();
  }

  function prefsAbierto() {
    return !!prefsOverlay && !prefsOverlay.hidden;
  }

  function abrirPrefs() {
    if (!prefsOverlay) return;
    renderPrefsPanel();
    prefsOverlay.hidden = false;
    if (prefsToggle) {
      prefsToggle.setAttribute('aria-expanded', 'true');
      prefsToggle.classList.add('is-active');
    }
  }

  function cerrarPrefs() {
    if (!prefsOverlay) return;
    prefsOverlay.hidden = true;
    if (prefsToggle) {
      prefsToggle.setAttribute('aria-expanded', 'false');
      prefsToggle.classList.remove('is-active');
      prefsToggle.focus();
    }
  }

  /** Se enlaza UNA vez: el overlay vive fuera del modal y no se destruye. */
  function initPrefsOverlay() {
    prefsOverlay = document.getElementById('pos-prefs-overlay');
    prefsPanel   = document.getElementById('pos-prefs-panel');
    prefsToggle  = document.getElementById('pos-prefs-toggle');
    if (!prefsOverlay || !prefsPanel || !prefsToggle) return;

    prefsToggle.addEventListener('click', function() {
      if (prefsAbierto()) cerrarPrefs(); else abrirPrefs();
    });

    var bd = document.getElementById('pos-prefs-bd');
    var cl = document.getElementById('pos-prefs-close');
    if (bd) bd.addEventListener('click', cerrarPrefs);
    if (cl) cl.addEventListener('click', cerrarPrefs);
  }

  function buildModalContent(mesa, estado, reserva, ticket) {
    var h = '';
    var reservaChipLabel = estado === 'bloqueada' ? '¡Próxima a llegar!'
      : estado === 'ocupada' ? 'En ventana de reserva'
      : estado === 'proxima' ? 'Próxima reservación'
      : 'Mesa reservada';
    var reservaVentana = reserva ? resolverVentanaOperativaReservacion(reserva, ahoraOperativa()) : null;
    var reservaDisplayName = reserva ? nombresMesasReserva(reserva) : mesa.nombre;
    var reservaChipClass = estado;
    if (reservaVentana) {
      reservaChipLabel = reservaVentana.etiqueta;
      reservaChipClass = reservaVentana.estado;
    }

    // El encabezado identifica la mesa una sola vez y mantiene el estado cerca.
    h += '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(reservaDisplayName || mesa.nombre) + '</span>';
    if (!reserva && mesa.numero) {
      h += '<span class="mmodal-table-number">#' + escHtml(mesa.numero) + '</span>';
    }
    h += '</div>';
    if (ticket) {
      h += '<span class="mmodal-chip mmodal-chip--ticket">Ticket abierto</span>';
    } else if (reserva) {
      h += '<span class="mmodal-chip mmodal-chip--' + reservaChipClass + '">' + reservaChipLabel + '</span>';
    }
    h += '</div>';

    if (estado === 'con-ticket' && ticket) {
      // ── Vista de ticket abierto con sistema de comandas ────

      // Las sugerencias las pide cargarSugerencias() cuando el modal ya existe
      sugComensalesCount = ticket.comensales;

      var horaAp = ticket.hora_apertura
        ? String(ticket.hora_apertura).substring(11, 16)
        : '--:--';

      h += '<div class="mmodal-ticket-meta">';
      if (ticket.nombre) {
        h += '<span>Cliente: ' + escHtml(ticket.nombre) + '</span>';
      }
      h += '<span>👥 ' + ticket.comensales + ' com.</span>';
      h += '<span>🕐 ' + horaAp + '</span>';
      // La etapa la detecta n8n; se llena cuando responden las sugerencias.
      h += '<span class="mmodal-etapa" id="mmodal-etapa" hidden></span>';
      h += '</div>';

      var ticketMesaNames = (Array.isArray(ticket.mesa_ids) ? ticket.mesa_ids : []).map(function(mesaId) {
        var mesaTicket = mesaPorId(mesaId);
        return mesaTicket ? mesaTicket.nombre : 'Mesa ' + mesaId;
      });
      if (ticketMesaNames.length) {
        h += '<div class="mmodal-ticket-tables">Mesas: ' + escHtml(ticketMesaNames.join(', ')) + '</div>';
      }

      var ticketAlertas = reservacionesProximasParaTicket(ticket);
      if (ticketAlertas.length) {
        h += '<div class="mmodal-ticket-reservation-alert" role="status">';
        h += '<strong>Advertencia de reservación próxima</strong>';
        for (var tai = 0; tai < ticketAlertas.length; tai++) {
          var alerta = ticketAlertas[tai];
          var mesaAlerta = mesaPorId(alerta.mesaId);
          h += '<span>' + escHtml(mesaAlerta ? mesaAlerta.nombre : 'Mesa ' + alerta.mesaId) +
            ' — reservación a las ' + escHtml(String(alerta.reservacion.hora || '').substring(0, 5)) +
            ' — faltan ' + alerta.minutos_restantes + ' minutos</span>';
        }
        h += '</div>';
      }

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

      // Los ajustes viven en el engranaje del header (#pos-prefs-overlay), no
      // aquí: este nodo se reescribe entero en cada apertura de mesa.
      var prefs = getPosPrefs();

      // ── Panels wrapper (4 cols en desktop) ────────────────
      h += '<div class="mmodal-panels" data-layout="' + prefs.layout + '">';

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
      var catsMenu = categoriasConFavoritos();
      var catInicial = prefs.categoriaInicial;
      if (catInicial < 0 || catInicial >= catsMenu.length) catInicial = 0;
      for (var mi = 0; mi < catsMenu.length; mi++) {
        h += '<button class="mmodal-cat-block' + (mi === catInicial ? ' mmodal-cat-block--active' : '') +
             '" data-idx="' + mi + '">' + escHtml(catsMenu[mi].label) + '</button>';
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
      // Estado ocioso: no se pide nada hasta que el mesero lo pida (ver
      // prepararSugerencias). El botón lo enlaza bindSugMore.
      h += sugAccionHtml('Recomienda algo a esta mesa', '✨', 'Buscar sugerencias');
      h += '</div>'; // fin panel-scroll
      h += '</div>'; // fin panel-sugerencias

      h += '</div>'; // fin mmodal-panels

    } else if (reserva) {
      // ── Resumen compacto de reservación ────────────────────
      var reservaHora = String(reserva.hora || '').substring(0, 5);
      var reservaVentanaActual = reservaVentana || resolverVentanaOperativaReservacion(reserva, ahoraOperativa());
      var reservaMesas = mesaIdsReserva(reserva).map(function (mesaId) {
        var mesaReservada = mesaPorId(mesaId);
        return mesaReservada ? mesaReservada.nombre : 'Mesa ' + mesaId;
      });
      var reservaEstado = String(reserva.estado || '');
      var reservaComensales = parseInt(reserva.comensales || reserva.personas || '0', 10);

      h += '<div class="mmodal-reserva-preview mmodal-reservation">';
      h += '<div class="mmodal-reservation__identity">';
      h += '<span>Cliente</span>';
      h += '<strong>' + escHtml(reserva.nombre || 'Sin nombre') + '</strong>';
      h += '</div>';
      h += '<dl class="mmodal-reservation__facts">';
      h += '<div><dt>Hora</dt><dd>' + escHtml(reservaHora || '--:--') +
        '<small class="mmodal-reservation__temporal">' +
        escHtml(reservaVentanaActual.mensaje || reservaVentanaActual.etiqueta || '') +
        '</small></dd></div>';
      h += '<div><dt>Comensales</dt><dd>' + escHtml(reservaComensales || 0) + '</dd></div>';
      h += '<div><dt>Contacto</dt><dd class="' + (reserva.contacto ? '' : 'is-empty') + '">' +
        escHtml(reserva.contacto || 'Sin contacto') + '</dd></div>';
      h += '<div><dt>Mesas</dt><dd class="' + (reservaMesas.length ? '' : 'is-empty') + '">' +
        escHtml(reservaMesas.length ? reservaMesas.join(', ') : 'Sin mesas asignadas') + '</dd></div>';
      h += '</dl>';
      if (reserva.nota) {
        h += '<div class="mmodal-reserva-nota"><span class="mmodal-reserva-nota__label">Nota</span>' + escHtml(reserva.nota) + '</div>';
      }
      if (reserva.comentario_admin) {
        h += '<div class="mmodal-reserva-nota mmodal-reserva-nota--admin"><span class="mmodal-reserva-nota__label">Comentario administrativo</span>' +
          escHtml(reserva.comentario_admin) + '</div>';
      }
      h += '<div class="mmodal-reservation__waiter">';
      h += buildMeseroSelectHtml();
      h += '</div>';
      h += '<div class="mmodal-reservation__actions">';
      if (reservaEstado === 'confirmada') {
        var puedeIniciar = reserva.puede_iniciar_servicio === true
          && reservaVentanaActual.estado !== 'overdue';
        if (reservaVentanaActual.estado !== 'overdue') {
          h += '<button class="mmodal-btn mmodal-btn--primary' + (puedeIniciar ? '' : ' mmodal-btn--pending') +
            '" id="mmodal-iniciar"' + (puedeIniciar ? '' : ' disabled') + '>Iniciar servicio</button>';
        }
        h += '<div class="mmodal-reservation__action-hint">' +
          escHtml(puedeIniciar
            ? 'El ticket y el servicio se crearán en un solo paso.'
            : (reservaVentanaActual.mensaje || 'Disponible desde 30 minutos antes.')) +
          '</div>';
        var puedeMarcarAusencia = reservaEstado === 'confirmada'
          && reservaVentanaActual.estado === 'overdue'
          && (reserva.no_show_disponible === true || reserva.elegible_no_show === true)
          && !reserva.ticket_id;
        if (puedeMarcarAusencia) {
          h += '<button type="button" class="mmodal-btn mmodal-btn--release" id="mmodal-no-show">Cliente no se presentó</button>';
        }
      } else if (reservaEstado === 'llego') {
        h += '<div class="mmodal-reservation__action-hint">La llegada del cliente ya fue registrada; esta reservación no puede registrarse como ausencia.</div>';
      }
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

      // Estrella de favorito: alimenta la categoría virtual "★ Favoritos".
      var favBtn = document.createElement('button');
      favBtn.className = 'mmodal-dish-fav' + (esFavorito(dish.n) ? ' is-on' : '');
      favBtn.innerHTML = svgIcon('star', 14);
      favBtn.setAttribute('aria-pressed', esFavorito(dish.n) ? 'true' : 'false');
      favBtn.setAttribute('aria-label', 'Marcar ' + dish.n + ' como favorito');
      (function(d, btn) {
        btn.addEventListener('click', function(ev) {
          ev.stopPropagation();
          toggleFavorito(d.n);
          btn.classList.toggle('is-on');
          btn.setAttribute('aria-pressed', btn.classList.contains('is-on') ? 'true' : 'false');
        });
      })(dish, favBtn);
      row.appendChild(favBtn);

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
      var catsBusq = categoriasConFavoritos();
      if (catsBusq[idx]) renderCategoryDishes(catsBusq[idx]);
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

  // ── Caché de los ítems del ticket ─────────────────────────
  // La misma cuenta se pedía en cada cambio de pestaña y en cada paso del
  // cierre. Se guarda la última respuesta por ticket y se invalida al enviar
  // comanda, entregar o cancelar.
  //
  // El TTL corto existe porque las áreas de producción marcan entregas desde
  // otra pantalla: sin él, el mesero podría ver un estado viejo. Los pasos de
  // cobro piden refresco forzado, porque ahí el total tiene que ser exacto.
  var TICKET_ITEMS_TTL = 15000;
  var ticketItemsCache = {};

  function cargarTicketItems(ticketId, forzar) {
    var hit = ticketItemsCache[ticketId];
    if (!forzar && hit && (Date.now() - hit.ts) < TICKET_ITEMS_TTL) {
      return Promise.resolve(hit.data);
    }
    return fetch('/api/ticket-items?ticket_id=' + ticketId)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.ok) ticketItemsCache[ticketId] = { ts: Date.now(), data: data };
        return data;
      });
  }

  function invalidarTicketItems(ticketId) {
    delete ticketItemsCache[ticketId];
  }

  function renderResumen(ticketId) {
    var resumenEl = modalContent.querySelector('#mmodal-resumen-content');
    if (!resumenEl) return;
    if (!ticketItemsCache[ticketId]) {
      resumenEl.innerHTML = '<div class="mmodal-cart-empty">Cargando…</div>';
    }

    cargarTicketItems(ticketId, false)
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

  /**
   * Enlaza los controles del panel de ajustes. La raíz es #pos-prefs-panel, no
   * el modal: el panel vive en el overlay del header y sobrevive a las
   * reescrituras de #mesa-modal-content.
   */
  function bindPreferencias() {
    if (!prefsPanel) return;

    function marcarActivo(btn, selector) {
      var hermanos = prefsPanel.querySelectorAll(selector);
      for (var k = 0; k < hermanos.length; k++) hermanos[k].classList.remove('is-active');
      btn.classList.add('is-active');
    }

    // Opciones de valor (layout, densidad, texto, toque, vista, columnas).
    var opts = prefsPanel.querySelectorAll('[data-pref]');
    for (var i = 0; i < opts.length; i++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var clave = btn.getAttribute('data-pref');
          setPosPref(clave, btn.getAttribute('data-val'));
          marcarActivo(btn, '[data-pref="' + clave + '"]');
          if (clave === 'vistaMenu' || clave === 'columnas') renderCategoriaActual();
        });
      })(opts[i]);
    }

    // Mostrar/ocultar paneles.
    var bools = prefsPanel.querySelectorAll('[data-pref-bool]');
    for (var b = 0; b < bools.length; b++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var clave = btn.getAttribute('data-pref-bool');
          setPosPref(clave, btn.getAttribute('data-val') === '1');
          marcarActivo(btn, '[data-pref-bool="' + clave + '"]');
        });
      })(bools[b]);
    }

    // Reordenar columnas: se repinta el panel para refrescar el orden y los
    // botones que ya llegaron al tope.
    var movers = prefsPanel.querySelectorAll('[data-mover]');
    for (var m = 0; m < movers.length; m++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          moverPanel(btn.getAttribute('data-mover'), parseInt(btn.getAttribute('data-dir'), 10));
          renderPrefsPanel();
          aplicarPosPrefs();
        });
      })(movers[m]);
    }

    var reset = prefsPanel.querySelector('#mmodal-prefs-reset');
    if (reset) {
      reset.addEventListener('click', function() {
        resetPosPrefs();
        renderPrefsPanel();
        aplicarPosPrefs();
        renderCategoriaActual();
      });
    }

    aplicarPosPrefs();
  }

  /** Repinta la categoría abierta (tras cambiar vista o favoritos). */
  function renderCategoriaActual() {
    // Alcanzable desde el panel de ajustes, que se puede abrir sin ninguna
    // mesa en pantalla.
    if (!modalContent || !modalContent.querySelector('#mmodal-dishes')) return;
    var activa = modalContent.querySelector('.mmodal-cat-block--active');
    var cats = categoriasConFavoritos();
    var idx = activa ? parseInt(activa.dataset.idx, 10) : 0;
    if (cats[idx]) renderCategoryDishes(cats[idx]);
  }

  function bindTabsAndComanda(mesa, ticket) {
    // Bloques de categoría (grid)
    var catTabs = modalContent.querySelectorAll('.mmodal-cat-block');
    var cats = categoriasConFavoritos();
    if (catTabs.length && cats.length) {
      var inicial = getPosPrefs().categoriaInicial;
      if (inicial < 0 || inicial >= cats.length) inicial = 0;
      renderCategoryDishes(cats[inicial]);
      for (var k0 = 0; k0 < catTabs.length; k0++) {
        catTabs[k0].classList.toggle('mmodal-cat-block--active',
          parseInt(catTabs[k0].dataset.idx, 10) === inicial);
      }
      for (var i = 0; i < catTabs.length; i++) {
        (function(tab) {
          tab.addEventListener('click', function() {
            for (var k = 0; k < catTabs.length; k++) catTabs[k].classList.remove('mmodal-cat-block--active');
            tab.classList.add('mmodal-cat-block--active');
            renderCategoryDishes(categoriasConFavoritos()[parseInt(tab.dataset.idx, 10)]);
          });
          // Mantener pulsado fija la categoría de arranque del mesero.
          tab.addEventListener('contextmenu', function(ev) {
            ev.preventDefault();
            setPosPref('categoriaInicial', parseInt(tab.dataset.idx, 10));
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
      if (targetTab.dataset.tab === 'sugerencias')  {
        panel = panelSugerencias;
        // La pestaña solo existe en móvil (.mmodal-tabs se oculta ≥768px), así
        // que tocarla ES la intención explícita. En escritorio la columna se ve
        // desde el inicio y ahí manda el botón del estado ocioso.
        if (window.innerWidth < 768) asegurarSugerencias(ticket);
      }
      if (panel) panel.classList.add('mmodal-tab-panel--active');
    }

    for (var ti = 0; ti < mainTabs.length; ti++) {
      (function(tab) {
        tab.addEventListener('click', function() { activatePanel(tab); });
      })(mainTabs[ti]);
    }

    // El panel de ajustes se enlaza aparte (vive en el header). Aquí basta con
    // volcar las preferencias sobre las columnas recién construidas.
    aplicarPosPrefs();

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

    // Sugerencias: NO se piden al abrir. Abrir la mesa deja exactamente una
    // petición en vuelo (/api/ticket-items), que es lo que el mesero espera ver.
    prepararSugerencias(ticket);
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
          apiAbrirLlevarTicket(mesa, comensales, nombre || null, meseroId, abrirBtn);
        } else {
          apiAbrirTicket([mesa.id], comensales, null, nombre || null, meseroId, abrirBtn);
        }
      });
    }

    var iniciarBtn = modalContent.querySelector('#mmodal-iniciar');
    if (iniciarBtn && reserva) {
      iniciarBtn.addEventListener('click', function() {
        apiIniciarServicio(reserva, selectedMeseroId(), iniciarBtn);
      });
    }

    var noShowBtn = modalContent.querySelector('#mmodal-no-show');
    if (noShowBtn && reserva) {
      noShowBtn.addEventListener('click', function() {
        showOpenTicketNotice({
          variant: 'absence',
          confirmButtonVariant: 'warning',
          title: 'Registrar ausencia',
          message: '¿Confirmas que el cliente no se presentó? La reservación se registrará como ausencia y las mesas quedarán disponibles.',
          cancelLabel: 'Volver',
          confirmLabel: 'Confirmar ausencia',
          onConfirm: function() {
            apiMarcarNoShow(reserva, noShowBtn);
          }
        });
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

    cargarTicketItems(ticket.id, true)
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

    cargarTicketItems(ticket.id, true)
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
  function postJson(url, payload, timeoutMs) {
    var controller = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;
    var timer = null;
    var requestOptions = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    };

    if (controller) {
      requestOptions.signal = controller.signal;
      timer = window.setTimeout(function() {
        controller.abort();
      }, timeoutMs || POS_REQUEST_TIMEOUT_MS);
    }

    return fetch(url, requestOptions)
      .then(function(response) {
        return response.text().then(function(text) {
          var result;
          try {
            result = text ? JSON.parse(text) : {};
          } catch (error) {
            var parseError = new Error('Respuesta inválida del servidor.');
            parseError.httpStatus = response.status;
            parseError.rawBody = text.substring(0, 500);
            throw parseError;
          }
          result = result && typeof result === 'object' ? result : {};
          result.httpStatus = response.status;
          return result;
        });
      })
      .then(function(result) {
        if (timer) window.clearTimeout(timer);
        return result;
      }, function(error) {
        if (timer) window.clearTimeout(timer);
        throw error;
      });
  }

  function setActionBusy(button, busy, label) {
    if (!button) return;
    if (busy) {
      if (!button.dataset.posOriginalLabel) {
        button.dataset.posOriginalLabel = button.textContent;
      }
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = label || 'Procesando…';
      return;
    }
    button.disabled = false;
    button.removeAttribute('aria-busy');
    if (button.dataset.posOriginalLabel) {
      button.textContent = button.dataset.posOriginalLabel;
      delete button.dataset.posOriginalLabel;
    }
  }

  function apiPost(url, data) {
    postJson(url, data)
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

  function showOpenTicketNotice(options) {
    options = options || {};
    if (!modal || !modalContent) return;
    var overlay = document.createElement('div');
    overlay.className = 'mmodal-cancel-confirm-overlay';
    var isAbsenceNotice = options.variant === 'absence';
    var noticeClass = isAbsenceNotice ? ' mmodal-cancel-confirm--absence' : '';
    var noticeIcon = isAbsenceNotice
      ? '<div class="mmodal-cancel-confirm__icon" aria-hidden="true">!</div>'
      : '';
    var confirmButtonClass = options.confirmButtonVariant === 'warning'
      ? 'mmodal-btn--release'
      : 'mmodal-btn--primary';
    overlay.innerHTML =
      '<div class="mmodal-cancel-confirm' + noticeClass + '" role="alertdialog" aria-modal="true">' +
        noticeIcon +
        '<p class="mmodal-cancel-confirm__msg"><strong>' + escHtml(options.title || 'Aviso') + '</strong></p>' +
        '<p class="mmodal-cancel-confirm__sub">' + escHtml(options.message || '') + '</p>' +
        (Array.isArray(options.details) && options.details.length
          ? '<ul class="mmodal-operation-details">' + options.details.map(function(detail) {
              return '<li>' + escHtml(detail) + '</li>';
            }).join('') + '</ul>'
          : '') +
        '<div class="mmodal-cancel-confirm__btns">' +
          '<button class="mmodal-btn mmodal-btn--ghost" type="button" data-ticket-notice-cancel>' +
            escHtml(options.cancelLabel || 'Cerrar') +
          '</button>' +
          (options.onConfirm
            ? '<button class="mmodal-btn ' + confirmButtonClass + '" type="button" data-ticket-notice-confirm>' +
                escHtml(options.confirmLabel || 'Continuar') +
              '</button>'
            : '') +
        '</div>' +
      '</div>';
    // El aviso reemplaza el contenido anterior del mismo root modal. Esto
    // evita que un resumen de reservación o un ticket quede activo debajo del
    // warning y también elimina listeners temporales del flujo anterior.
    sugTimerStop();
    sugTicket = null;
    sugPedidas = false;
    modalContent.innerHTML = '';
    modalContent.appendChild(overlay);
    modal.classList.add('mesa-modal--open');
    document.body.style.overflow = 'hidden';

    var cancel = overlay.querySelector('[data-ticket-notice-cancel]');
    var confirm = overlay.querySelector('[data-ticket-notice-confirm]');
    cancel.addEventListener('click', function(event) {
      event.preventDefault();
      event.stopPropagation();
      overlay.remove();
      if (typeof options.onCancel === 'function') options.onCancel();
      closeModal({ refresh: options.refreshOnCancel !== false });
    });
    if (confirm) {
      confirm.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();
        confirm.disabled = true;
        overlay.remove();
        if (typeof options.onConfirm === 'function') options.onConfirm();
      });
    }
    window.requestAnimationFrame(function() {
      (options.onConfirm && confirm ? confirm : cancel).focus();
    });
  }

  function requestOpenTicket(payload, options) {
    options = options || {};
    if (ticketRequestInFlight) return null;
    ticketRequestInFlight = true;
    var actionButton = options.button || null;
    setActionBusy(actionButton, true, 'Abriendo ticket…');
    if (ticketSelectionMode && options.selection) {
      if (ticketSelectionState.opening) {
        ticketRequestInFlight = false;
        setActionBusy(actionButton, false);
        return null;
      }
      ticketSelectionState.opening = true;
      ticketSelectionState.warningConfirmed = false;
      actualizarModoSeleccion();
    } else if (ticketSelectionMode && options.warningConfirmed) {
      if (ticketSelectionState.opening) {
        ticketRequestInFlight = false;
        setActionBusy(actionButton, false);
        return null;
      }
      ticketSelectionState.opening = true;
      ticketSelectionState.warningConfirmed = true;
      actualizarModoSeleccion();
    }
    return postJson('/api/abrir-ticket', payload)
    .then(function(result) {
      if (result.ok) {
        if (!result.ticket_id && !result.id) {
          throw new Error('La respuesta no incluyó el ticket creado.');
        }
        var openedMesaIds = Array.isArray(result.mesa_ids) && result.mesa_ids.length
          ? result.mesa_ids
          : (Array.isArray(payload.mesa_ids) ? payload.mesa_ids : []);
        var openedTicket = {
          id: result.id || result.ticket_id,
          mesa_ids: openedMesaIds,
          nombre: payload.nombre || null,
          comensales: parseInt(payload.comensales || '2', 10),
          hora_apertura: new Date().toISOString().replace('T', ' ').substring(0, 19),
          reservacion_id: payload.reservacion_id || null
        };
        if (ticketSelectionMode) {
          ticketSelectionMode = false;
          selectedMesaIds = [];
          ticketSelectionState.mode = 'idle';
          ticketSelectionState.pendingAction = null;
          ticketSelectionState.warningConfirmed = false;
          ticketSelectionState.opening = false;
          actualizarModoSeleccion();
        }
        var refresh = modal && modal.classList.contains('mesa-modal--open')
          ? closeModal()
          : silentRefresh();
        var abrirCreado = function() {
          var mesaCreada = openedMesaIds.length ? mesaPorId(openedMesaIds[0]) : null;
          var ticketActualizado = openedMesaIds.length
            ? ticketActual(openedMesaIds[0])
            : null;
          if (mesaCreada) {
            commandaItems = [];
            selectedComensal = 0;
            showModal(mesaCreada, 'con-ticket', null, ticketActualizado || openedTicket);
          }
        };
        if (refresh && typeof refresh.then === 'function') refresh.then(abrirCreado);
        else abrirCreado();
        return;
      }

      if (result.codigo === 'REQUIERE_CONFIRMACION' && result.advertencia) {
        ticketSelectionState.opening = false;
        ticketSelectionState.warningConfirmed = false;
        actualizarModoSeleccion();
        var warnings = Array.isArray(result.advertencias) && result.advertencias.length
          ? result.advertencias
          : [result.advertencia];
        var warning = warnings[0];
        var details = [];
        warnings.forEach(function(item) {
          var ids = Array.isArray(item.mesa_ids) ? item.mesa_ids : [];
          if (!ids.length && Array.isArray(item.reservation_mesa_ids)) {
            ids = item.reservation_mesa_ids;
          }
          ids.forEach(function(mesaId) {
            var mesa = mesaPorId(mesaId);
            details.push(
              (mesa ? mesa.nombre : 'Mesa ' + mesaId) +
              ' — reservación a las ' + String(item.hora || '').substring(0, 5) +
              ' — faltan ' + parseInt(item.minutos_restantes || '0', 10) + ' minutos'
            );
          });
        });
        showOpenTicketNotice({
          title: 'Reservación próxima',
          message: 'Esta mesa tiene una reservación a las ' + String(warning.hora || '').substring(0, 5) +
            '. Faltan ' + parseInt(warning.minutos_restantes || '0', 10) +
            ' minutos. El servicio deberá finalizar o cambiar de mesa antes de esa hora.',
          details: details,
          cancelLabel: 'Volver a la selección',
          confirmLabel: 'Abrir ticket de todas formas',
          refreshOnCancel: false,
          onConfirm: function() {
            payload.confirmar_reservacion_proxima = 1;
            requestOpenTicket(payload, { warningConfirmed: true });
            closeModal({ refresh: false });
          }
        });
        return;
      }

      ticketSelectionState.opening = false;
      ticketSelectionState.warningConfirmed = false;
      actualizarModoSeleccion();
      var bloqueo = result.bloqueo || {};
      var message = result.msg || 'No fue posible abrir el ticket.';
      var conflictDetails = Array.isArray(result.mesas_conflicto)
        ? result.mesas_conflicto.map(function(mesaId) {
            var mesaConflict = mesaPorId(mesaId);
            return mesaConflict ? mesaConflict.nombre : 'Mesa ' + mesaId;
          })
        : [];
      if (bloqueo.hora) {
        message = 'La mesa tiene una reservación a las ' + String(bloqueo.hora).substring(0, 5) +
          ' y faltan ' + parseInt(bloqueo.minutos_restantes || '0', 10) +
          ' minutos. Dentro de los 30 minutos previos no se puede abrir un ticket incompatible.';
      }
      showOpenTicketNotice({
        title: 'No se puede abrir el ticket',
        message: message,
        details: conflictDetails.length
          ? ['Mesas en conflicto: ' + conflictDetails.join(', ')]
          : [],
        cancelLabel: 'Entendido',
        refreshOnCancel: !ticketSelectionMode
      });
    })
    .catch(function(error) {
      ticketSelectionState.opening = false;
      ticketSelectionState.warningConfirmed = false;
      actualizarModoSeleccion();
      var errorMessage = 'Verifica que las mesas continúen disponibles e inténtalo nuevamente.';
      if (error && error.name === 'AbortError') {
        errorMessage = 'La solicitud tardó demasiado. Las mesas se conservaron para intentarlo nuevamente.';
      } else if (error && error.httpStatus) {
        errorMessage = 'El servidor respondió con HTTP ' + error.httpStatus +
          '. Las mesas se conservaron para intentarlo nuevamente.';
      } else if (error && error.message === 'Respuesta inválida del servidor.') {
        errorMessage = 'El servidor devolvió una respuesta inválida. Las mesas se conservaron para intentarlo nuevamente.';
      }
      showOpenTicketNotice({
        title: 'No fue posible abrir el ticket',
        message: errorMessage,
        cancelLabel: 'Entendido',
        refreshOnCancel: !ticketSelectionMode
      });
    })
    .finally(function() {
      ticketRequestInFlight = false;
      setActionBusy(actionButton, false);
    });
  }

  function requestReservationOperation(endpoint, payload, options) {
    options = options || {};
    var actionButton = options.button || null;
    setActionBusy(actionButton, true, 'Procesando…');
    return postJson(endpoint, payload)
    .then(function(result) {
      if (result.ok) {
        if (result.ticket_id) {
          var mesaIdsServicio = Array.isArray(result.mesa_ids) && result.mesa_ids.length
            ? result.mesa_ids
            : (options.reserva && Array.isArray(options.reserva.mesa_ids)
              ? options.reserva.mesa_ids
              : []);
          var ticketServicio = {
            id: result.ticket_id,
            mesa_ids: mesaIdsServicio,
            nombre: options.reserva && options.reserva.nombre || null,
            comensales: options.reserva && parseInt(options.reserva.comensales || '2', 10) || 2,
            reservacion_id: options.reserva && options.reserva.id || null,
            hora_apertura: new Date().toISOString().replace('T', ' ').substring(0, 19)
          };
          var refreshServicio = modal && modal.classList.contains('mesa-modal--open')
            ? closeModal()
            : silentRefresh();
          var abrirServicio = function() {
            var mesaServicio = mesaIdsServicio.length ? mesaPorId(mesaIdsServicio[0]) : null;
            var ticketActualServicio = mesaIdsServicio.length
              ? ticketActual(mesaIdsServicio[0])
              : null;
            if (mesaServicio) {
              commandaItems = [];
              selectedComensal = 0;
              showModal(mesaServicio, 'con-ticket', null, ticketActualServicio || ticketServicio);
            }
          };
          if (refreshServicio && typeof refreshServicio.then === 'function') {
            refreshServicio.then(abrirServicio);
          } else {
            abrirServicio();
          }
        } else {
          closeModal();
        }
        return;
      }

      if (result.requiere_reasignacion || result.codigo === 'REQUIERE_REASIGNACION' || result.codigo === 'SIN_CAPACIDAD') {
        showOpenTicketNotice({
          title: 'No fue posible iniciar el servicio',
          message: result.msg || 'Las mesas asignadas ya no están disponibles. Actualiza la información e intenta nuevamente.',
          cancelLabel: 'Cerrar',
        });
        return;
      }

      showOpenTicketNotice({
        title: options.errorTitle || 'No fue posible completar la acción',
        message: result.msg || 'El estado operativo cambió. Actualiza la información e intenta nuevamente.',
        cancelLabel: 'Entendido'
      });
    })
    .catch(function() {
      showOpenTicketNotice({
        title: 'Error de conexión',
        message: 'No fue posible validar la reservación. Intenta nuevamente.',
        cancelLabel: 'Entendido'
      });
    })
    .finally(function() {
      setActionBusy(actionButton, false);
    });
  }

  function apiIniciarServicio(reserva, meseroId, button) {
    requestReservationOperation(
      '/api/punto-de-venta/reservaciones/comenzar',
      {
        reservacion_id: reserva.id,
        mesero_id: meseroId || null
      },
      { reserva: reserva, errorTitle: 'No se pudo iniciar el servicio', button: button }
    );
  }

  function apiMarcarNoShow(reserva, button) {
    requestReservationOperation(
      '/api/punto-de-venta/reservaciones/no-show',
      { reservacion_id: reserva.id },
      { reserva: reserva, errorTitle: 'No se pudo registrar la ausencia', button: button }
    );
  }

  function apiAbrirTicket(mesaIds, comensales, reservaId, nombre, meseroId, button) {
    requestOpenTicket({
      mesa_ids: mesaIds,
      comensales: comensales, reservacion_id: reservaId,
      nombre: nombre || null,
      mesero_id: meseroId || null
    }, { button: button });
  }

  // Abre un ticket de Llevar y va directo al POS sin cerrar el modal
  function apiAbrirLlevarTicket(mesa, comensales, nombre, meseroId, button) {
    if (ticketRequestInFlight) return;
    ticketRequestInFlight = true;
    setActionBusy(button, true, 'Abriendo ticket…');
    postJson('/api/abrir-ticket', {
        mesa_ids:       [mesa.id],
        comensales:     comensales,
        nombre:         nombre || null,
        mesero_id:      meseroId || null,
        allow_multiple: true
      })
    .then(function(result) {
      if (result.ok) {
        if (!result.id && !result.ticket_id) {
          throw new Error('La respuesta no incluyó el ticket creado.');
        }
        var newTicket = {
          id:            result.id || result.ticket_id,
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
    .catch(function() { alert('Error de conexión'); })
    .finally(function() {
      ticketRequestInFlight = false;
      setActionBusy(button, false);
    });
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
        invalidarTicketItems(ticketId);
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
    .then(function(result) {
      if (result.ok) {
        invalidarTicketItems(ticketId);
        renderResumen(ticketId);
      }
    });
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
      if (result.ok) {
        invalidarTicketItems(ticketId);
        renderResumen(ticketId);
      } else {
        alert(result.msg || 'No se pudo cancelar el platillo');
      }
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
        '<div class="mapa-empty-state">' +
          '<span class="mapa-empty-icon" aria-hidden="true"><span class="mapa-empty-spinner"></span></span>' +
          '<span class="mapa-empty-title">Cargando reservaciones…</span>' +
        '</div>';
    }
    return fetch('/api/punto-de-venta?fecha=' + encodeURIComponent(fecha))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.ok === false) {
          if (!silent) {
            reservasList.innerHTML =
              '<div class="mapa-empty-state mapa-empty-state--error">' +
                '<span class="mapa-empty-icon" aria-hidden="true">⚠</span>' +
                '<span class="mapa-empty-title">No se pudieron cargar las reservaciones</span>' +
                '<span class="mapa-empty-hint">' + (data.hint || data.error || 'Error de servidor') + '</span>' +
              '</div>';
            if (loadingEl) loadingEl.classList.add('hidden');
          }
          return;
        }
        mesas         = data.mesas         || [];
        reservaciones = data.reservaciones  || [];
        tickets       = data.tickets        || [];
        meseros       = data.meseros        || [];
        temporalConfig = (data.config && data.config.temporal) || temporalConfig;
        sincronizarRelojOperativo(data.actualizado_en);
        if (ticketSelectionMode) {
          selectedMesaIds = selectedMesaIds.filter(function(mesaId) {
            var mesa = mesaPorId(mesaId);
            return mesa && mesaPuedeSeleccionarse(mesa, estadoMesaActual(mesaId));
          });
        }
        actualizarControlesApertura();
        if (!silent) renderMesas();
        renderEstados();
        renderSidebar();
        if (updateStatus) {
          var horaActualizada = partesRelojOperativo();
          updateStatus.textContent = 'Actualizado ' + String(horaActualizada.hora).padStart(2, '0') + ':' +
            String(horaActualizada.minuto).padStart(2, '0');
        }
        if (loadingEl) loadingEl.classList.add('hidden');
      })
      .catch(function() {
        if (!silent) {
          reservasList.innerHTML =
            '<div class="mapa-empty-state mapa-empty-state--error">' +
              '<span class="mapa-empty-icon" aria-hidden="true">⚠</span>' +
              '<span class="mapa-empty-title">No se pudieron cargar las reservaciones</span>' +
              '<span class="mapa-empty-hint">Revisa la conexión e inténtalo de nuevo.</span>' +
            '</div>';
          if (loadingEl) loadingEl.classList.add('hidden');
        }
      });
  }

  function silentRefresh() {
    // Con la tablet en otra app o con la pantalla bloqueada no hay nadie
    // mirando el mapa: refrescar solo gasta batería y datos.
    if (document.hidden) return null;
    return fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), true);
  }

  // ── Polling en tiempo real (cada 30 s) ────────────────────
  function startPolling() {
    stopPolling();
    // Guarda para que activateLive() no reviva el sondeo con el modal abierto.
    if (modal && modal.classList.contains('mesa-modal--open')) return;
    pollTimer = setInterval(silentRefresh, 30000);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // Al volver a la pestaña, ponerse al día de inmediato en vez de esperar
  // hasta 30 s al siguiente tick.
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden && pollTimer) silentRefresh();
  });

  // ── Modo en vivo ──────────────────────────────────────────
  function syncLive() {
    var reloj = partesRelojOperativo();
    var min = snapToReservationInterval(reloj.hora * 60 + reloj.minuto);
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
    var refreshSeconds = temporalNumber('refresco_estados_segundos');
    if (refreshSeconds > 0) {
      liveInterval = setInterval(syncLive, refreshSeconds * 1000);
    }
    startPolling();
  }

  function deactivateLive() {
    isLive = false;
    if (ahoraBtn)  ahoraBtn.classList.remove('mapa-ahora-btn--active');
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
  }

  // ── Eventos ───────────────────────────────────────────────
  initMapaCalendar();
  initPrefsOverlay();
  actualizarModoSeleccion();
  if (selectionToggle) selectionToggle.addEventListener('click', activarModoSeleccion);
  if (selectionCancel) selectionCancel.addEventListener('click', cancelarModoSeleccion);

  if (modalBd)    modalBd.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    // El panel de ajustes se dibuja por encima del modal: cierra primero.
    if (prefsAbierto()) { cerrarPrefs(); return; }
    closeModal();
  });
  window.addEventListener('pagehide', function() {
    stopPolling();
    deactivateLive();
  });
  window.addEventListener('pageshow', function() {
    if (!isLive) activateLive();
  });

  // ── Init ──────────────────────────────────────────────────
  var relojInicial = partesRelojOperativo();
  sliderMin = Math.max(510, Math.min(1320, snapToReservationInterval(relojInicial.hora * 60 + relojInicial.minuto)));
  fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), false);
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
