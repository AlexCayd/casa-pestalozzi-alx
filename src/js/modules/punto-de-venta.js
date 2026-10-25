/* ── Punto de Venta — Casa Pestalozzi ──────────────────────── */

function clasificarResultadoAperturaTicket(resultado) {
  resultado = resultado || {};
  if (
    resultado.tipo === 'decision_requerida' ||
    resultado.codigo === 'REQUIERE_CONFIRMACION'
  ) {
    return 'decision';
  }
  if (resultado.ok === false || resultado.tipo === 'error') {
    return 'error';
  }
  if (
    resultado.ok === true &&
    resultado.commit === true
  ) {
    return 'exito';
  }
  return 'inconsistente';
}

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
    mostrarLeyenda: true,
    // Icono + palabra en cada pin: verde y rojo son justo el par que se
    // confunde con daltonismo, así que el color nunca va solo.
    mostrarEstado: true
  });
  if (!mapVisual) return;

  canvas.addEventListener('mapa:mesa-click', function(event) {
    if (event.detail && event.detail.contexto === 'mapa-mesas') {
      onMesaClick(parseInt(event.detail.mesaId, 10));
    }
  });

  // ── Estado ────────────────────────────────────────────────
  var mesas         = [];
  var mesasEstado   = [];
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
  // Ajustes de /admin/configuracion/pos. Llegan en cada refresco del mapa, así
  // que cambiarlos en el admin se aplica en el turno sin recargar la tablet.
  // Arranca en el comportamiento histórico por si la primera respuesta falla.
  var posConfig     = { mesero_editable: true };
  var commandaItems    = []; // { n, p, area, area_id, categoria, comensal, qty }
  var claveComandaPendiente = null;
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
  var isLive        = false;
  var liveInterval  = null;
  var pollTimer     = null;
  var dataRequestSequence = 0;
  var dataAbortController = null;
  var dataAbortTimer = null;
  var mapSnapshotStale = false;
  var loadedMapDate = '';
  /*
   * Qué se le pide al servidor: vacío es AHORA. Con hora (o con una reserva
   * enfocada) es una PROYECCIÓN, que el mesero lanza desde el cajón. El sondeo
   * la conserva: si cada 30 s volviera a «ahora», el mapa saltaría bajo el dedo.
   */
  var consultaPos = { hora: '', reservacionId: 0, intencion: '' };
  /*
   * Lo que pidió el enlace con el que se abrió el POS (buzón, panel, favorito
   * de Operación). El servidor ya lo validó; se consume UNA vez, tras la
   * primera carga, porque la ficha necesita la reserva ya en la lista.
   */
  var enlaceInicial = (function() {
    var body = document.body;
    var id = parseInt(body.getAttribute('data-initial-reservation-id') || '0', 10) || 0;
    return {
      hora: body.getAttribute('data-initial-hora') || '',
      reservacionId: id,
      asignar: id > 0 && body.getAttribute('data-initial-mode') === 'asignar'
    };
  })();
  if (enlaceInicial.hora && !enlaceInicial.reservacionId) consultaPos.hora = enlaceInicial.hora;
  // Lo que el servidor resolvió para esa consulta (modo, hora, sólo lectura).
  var contextoMapa = { modo: 'ahora', fecha: '', hora: '', solo_lectura: false };
  var horarioPos = null;
  var mapaDatePicker = null;
  var capacidadHorarioPos = null;
  var permisosPos = {};
  var temporalConfig = window.CP_RESERVATION_OPERATION_CONFIG || {};
  var POS_REQUEST_TIMEOUT_MS = 15000;
  var serverClockOffsetMs = 0;

  // ── Refs DOM ──────────────────────────────────────────────
  var fechaInput    = $('#mapa-fecha');
  var reservasList  = $('#mapa-reservas-list');
  var reservaCount  = $('#mapa-reserva-count');
  var loadingEl     = $('#mapa-loading');
  var selectionBar = $('#pos-ticket-selection-bar');
  var selectionToggle = $('#pos-ticket-selection-toggle');
  var selectionCancel = $('#pos-ticket-selection-cancel');
  var selectionMessage = $('#pos-ticket-selection-message');
  var selectionActions = selectionBar ? selectionBar.querySelector('.pos-ticket-selection-bar__actions:not(#pos-asignacion-pregunta)') : null;
  var asignacionPregunta = $('#pos-asignacion-pregunta');
  var selectionShell = canvas.closest('.pos-map');
  var modal         = $('#mesa-modal');
  var modalContent  = $('#mesa-modal-content');
  var modalBd       = $('#mesa-modal-bd');
  var modalClose    = $('#mesa-modal-close');
  var modalLastFocus = null;
  var reservationRequestInFlight = false;
  // El día entero para el cajón (incluye en mesa, apartadas y resueltas);
  // `reservaciones` sigue siendo sólo confirmadas, que es lo que la lógica de
  // mesas da por hecho.
  var reservacionesDia = [];
  // Mesas de la reserva cuya ficha está abierta: se pintan con la selección.
  var fichaMesaIds = [];
  // Después de aplicar una respuesta, abrir esta ficha (tocar una tarjeta
  // proyecta y abre en la misma transición).
  var fichaPendiente = null;
  var transicionPendiente = '';
  var posMapEl = canvas.closest('.pos-map');

  // Labels de estado temporal para el sidebar
  var TEMPORAL_LABELS = {
    'futura': 'Futura',
    'advertencia': 'Reservaci\u00f3n pr\u00f3xima',
    'bloqueo': 'Puede iniciar servicio',
    'inicio': 'Puede iniciar servicio',
    'tolerancia': 'Dentro de la tolerancia',
    'ausencia_pendiente': 'Tolerancia vencida',
    'en_curso': 'En curso',
    'cancelada': 'Cancelada'
  };

  // ── Helpers ───────────────────────────────────────────────
  //
  // Nada de alert(): en tablet un diálogo nativo bloquea el turno entero hasta
  // que alguien lo toca, y varios de los antiguos podían salir vacíos cuando el
  // servidor no mandaba mensaje. De ahí el texto de respaldo obligatorio.
  function aviso(texto, variante) {
    var mensaje = String(texto || '').trim() || 'No fue posible completar la acción.';
    if (window.AppNotice && typeof window.AppNotice.show === 'function') {
      window.AppNotice.show({ text: mensaje, variant: variante || 'error' });
      return;
    }
    // Sin el componente cargado, al menos queda rastro en consola.
    if (window.console && window.console.warn) window.console.warn(mensaje);
  }

  function avisoConexion() {
    aviso('No hay conexión con el servidor. Vuelve a intentarlo.', 'error');
  }

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

  /*
   * ¿El nombre de la mesa ya contiene su número?
   *
   * El caso normal es "Mesa 9" con numero 9, donde escribir "#9" al lado repite
   * el dato. Se compara contra el número como PALABRA (\b) y no con indexOf:
   * "Mesa 1" no debe dar por dicho el 12, ni "Salón 20" el 2.
   */
  function nombreIncluyeNumero(nombre, numero) {
    var n = String(numero == null ? '' : numero).trim();
    if (!n) return false;
    return new RegExp('(^|\\D)' + n + '(\\D|$)').test(String(nombre == null ? '' : nombre));
  }

  function modalFocusables() {
    return modal ? Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])')) : [];
  }

  function openModalShell() {
    if (!modal) return;
    if (!modal.classList.contains('mesa-modal--open')) {
      modalLastFocus = document.activeElement;
    }
    var title = modal.querySelector('.mmodal-title');
    if (title) title.id = 'mesa-modal-title';
    modal.querySelectorAll('button:not([type])').forEach(function(button) {
      button.type = 'button';
    });
    modal.classList.add('mesa-modal--open');
    modal.setAttribute('aria-hidden', 'false');
    modal.inert = false;
    document.body.style.overflow = 'hidden';
    window.requestAnimationFrame(function() {
      var items = modalFocusables();
      if (items.length) items[0].focus();
      else modal.focus();
    });
  }

  // ── Iconos SVG en línea (heredan currentColor) ────────────
  var SVG_PATHS = {
    search:  '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    // Opciones de un platillo (variantes): tres deslizadores, el mismo dibujo
    // que `variantes` en el catálogo del panel.
    sliders: '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="10" cy="17" r="2"/><path d="M4 12h2"/><path d="M10 12h10"/><circle cx="8" cy="12" r="2"/>',
    printer: '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/>',
    receipt: '<path d="M5 3h14v18l-2.5-1.6L14 21l-2-1.6L10 21l-2.5-1.6L5 21Z"/><path d="M9 8h6"/><path d="M9 12h6"/>',
    users:   '<path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    cash:    '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    card:    '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',

    /*
     * El resto sustituye a los emojis y a los glifos sueltos que hacían de
     * icono en el modal (✨ 🍽 🕐 👥 ⚠ ☰ ◎ ◌ ↻ ✎ ✓ → ← ↑ ↓ −). Un emoji lo
     * pinta la fuente del sistema: no hereda currentColor, cambia de forma
     * entre Windows, Android y iOS —y la tablet del piso no es siempre la
     * misma— y se sale de la caja tipográfica del resto de la interfaz. Estos
     * son trazo de 1.9 sobre la misma retícula de 24 que los de arriba.
     */
    sparkles: '<path d="M12 3.2 13.5 8 18 9.5 13.5 11 12 15.8 10.5 11 6 9.5 10.5 8Z"/><path d="M18.5 15.5 19.2 17.8 21.5 18.5 19.2 19.2 18.5 21.5 17.8 19.2 15.5 18.5 17.8 17.8Z"/>',
    dish:     '<path d="M3.5 17h17"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 6.5v3.5"/><circle cx="12" cy="5" r="1.2"/><path d="M2.5 20.5h19"/>',
    alert:    '<path d="M10.3 3.9 2.4 17.6A1.8 1.8 0 0 0 4 20.4h16a1.8 1.8 0 0 0 1.6-2.8L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z"/><path d="M12 9.5v4"/><path d="M12 17h.01"/>',
    clock:    '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 1.9"/>',
    list:     '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/>',
    ticket:   '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3"/>',
    idle:     '<circle cx="12" cy="12" r="8.5" stroke-dasharray="3 3.2"/>',
    refresh:  '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4.5V10h-5.5"/>',
    pencil:   '<path d="M4 20h4.2L19.4 8.8a2.1 2.1 0 0 0-3-3L5.2 17Z"/><path d="M14.5 6.5 17.5 9.5"/>',
    check:    '<path d="m5 12.8 4.6 4.4L19 6.8"/>',
    right:    '<path d="M4 12h15.5"/><path d="m13.5 6 6 6-6 6"/>',
    left:     '<path d="M20 12H4.5"/><path d="m10.5 6-6 6 6 6"/>',
    up:       '<path d="M12 19.5V5"/><path d="m6 11 6-6 6 6"/>',
    down:     '<path d="M12 4.5V19"/><path d="m6 13 6 6 6-6"/>',
    minus:    '<path d="M5.5 12h13"/>',
    close:    '<path d="M6.5 6.5l11 11"/><path d="M17.5 6.5l-11 11"/>',
    plus:     '<path d="M5.5 12h13"/><path d="M12 5.5v13"/>'
  };

  function svgIcon(name, size) {
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" ' +
           'stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" ' +
           'aria-hidden="true">' + (SVG_PATHS[name] || '') + '</svg>';
  }

  /**
   * Etiqueta de botón con icono a la izquierda (o a la derecha con `after`).
   * El texto va en su propio <span> para que .mmodal-btn pueda alinearlos como
   * caja flex sin que el SVG herede el letter-spacing de las versalitas.
   */
  function btnLabel(icono, texto, after) {
    var svg = '<span class="mmodal-btn__icon">' + svgIcon(icono, 16) + '</span>';
    var txt = '<span class="mmodal-btn__text">' + escHtml(texto) + '</span>';
    return after ? txt + svg : svg + txt;
  }

  // El mesero de la sesión, si es de los asignables (la API solo lista los de
  // rol waiter activos). Para un admin o un cajero devuelve null.
  function meseroDeSesion() {
    var sesionId = (window.CP_USER && window.CP_USER.id) ? parseInt(window.CP_USER.id, 10) : 0;
    if (!(sesionId > 0)) return null;
    for (var i = 0; i < meseros.length; i++) {
      if (parseInt(meseros[i].id, 10) === sesionId) return meseros[i];
    }
    return null;
  }

  // ── Select de meseros registrados (se asigna al abrir el ticket) ──
  //
  // Llega preseleccionado quien tiene la sesión abierta: en el piso el que
  // abre la mesa es casi siempre el propio mesero, y dejarlo en «Sin asignar»
  // hacía que se perdiera la autoría del ticket cuando se les olvidaba
  // elegirse.
  //
  // Con la selección manual desactivada en Configuración › POS el campo además
  // se bloquea en ese mesero. Si quien entró no es asignable no hay con qué
  // bloquearlo, así que se deja editable; el servidor aplica la misma regla al
  // abrir el ticket, porque un <select> deshabilitado no protege el endpoint.
  // El campo se construye y se realza UNA vez por vida de página y luego se
  // reinserta en cada apertura. #mesa-modal-content se reescribe entero cada
  // vez: volver a realzarlo dejaría un <ul> huérfano en <body> y un listener de
  // documento extra por apertura — cientos a lo largo de un turno.
  var meseroField = null;
  var meseroSelect = null;

  function construirMeseroField() {
    var wrap = document.createElement('div');
    wrap.className = 'mmodal-name-wrap';

    var label = document.createElement('div');
    label.className = 'mmodal-label';
    label.textContent = 'Mesero';

    meseroSelect = document.createElement('select');
    // Clase propia y no .mmodal-name-input: esa declara display:block y ganaba
    // por orden de carga a .admin-select__native, dejando el <select> nativo
    // visible debajo del trigger realzado.
    meseroSelect.className = 'mmodal-select-native';
    meseroSelect.id = 'mmodal-mesero';
    // Opt-in explícito: .mapa-page está en el EXCLUDE de AdminSelect.
    meseroSelect.setAttribute('data-enhance', '');

    var hint = document.createElement('p');
    hint.className = 'mmodal-field-hint';
    hint.textContent = 'Asignación automática: el ticket queda a tu nombre.';
    hint.hidden = true;

    wrap.appendChild(label);
    wrap.appendChild(meseroSelect);
    wrap.appendChild(hint);
    meseroField = { root: wrap, hint: hint };

    if (window.AdminSelect && typeof window.AdminSelect.init === 'function') {
      // Debe estar en el documento para que el portal calcule su posición.
      document.body.appendChild(wrap);
      wrap.style.display = 'none';
      window.AdminSelect.init(wrap);
      wrap.style.display = '';
    }
  }

  function sincronizarMeseroOpciones() {
    var propio = meseroDeSesion();
    var bloqueado = !posConfig.mesero_editable && propio !== null;

    meseroSelect.innerHTML = '';
    var vacia = document.createElement('option');
    vacia.value = '';
    vacia.textContent = '— Sin asignar —';
    meseroSelect.appendChild(vacia);

    for (var i = 0; i < meseros.length; i++) {
      var opcion = document.createElement('option');
      opcion.value = meseros[i].id;
      opcion.textContent = meseros[i].nombre;
      if (propio !== null && parseInt(meseros[i].id, 10) === parseInt(propio.id, 10)) {
        opcion.selected = true;
      }
      meseroSelect.appendChild(opcion);
    }

    meseroSelect.disabled = bloqueado;
    if (meseroField) meseroField.hint.hidden = !bloqueado;
    // El listener de 'change' de AdminSelect solo sincroniza el valor; los <li>
    // del portal hay que regenerarlos a mano tras reescribir los <option>.
    if (window.AdminSelect && typeof window.AdminSelect.refresh === 'function') {
      window.AdminSelect.refresh(meseroSelect);
    }
  }

  /** Monta el campo de mesero dentro del contenedor indicado del modal. */
  function mountMeseroSelect(container) {
    if (!container) return;
    if (!meseroField) construirMeseroField();
    sincronizarMeseroOpciones();
    // El wrapper .admin-select envuelve al <select>; se mueve el bloque entero.
    container.appendChild(meseroField.root);
  }

  function selectedMeseroId() {
    if (!meseroSelect || !meseroSelect.value) return null;
    return parseInt(meseroSelect.value, 10);
  }

  // ── Sugerencias de venta (flujo de n8n) ───────────────────
  // `icono` es el NOMBRE de un icono de SVG_PATHS, no un caracter.
  function sugEstadoHtml(texto, icono) {
    return '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' +
           svgIcon(icono || 'sparkles', 26) + '</span><span>' + escHtml(texto) + '</span></div>';
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

    el.innerHTML   = svgIcon('dish', 14) + '<span>' + escHtml(label) + '</span>';
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
      headers: staffJsonHeaders(),
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
          errEl.innerHTML = sugAccionHtml(result.mensaje || '', 'alert', 'Reintentar');
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
        errEl.innerHTML = sugAccionHtml('No pudimos obtener sugerencias', 'alert', 'Reintentar');
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
        'sparkles',
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


  function ventanaReservaDesdeBackend(reserva, mesaId) {
    var estadoMesaBackend = mesaId != null ? mesaEstadoPorId(mesaId) : null;
    var mismaReserva = estadoMesaBackend
      && parseInt(estadoMesaBackend.reservacion_id || '0', 10) === parseInt(reserva && (reserva.id || reserva.reservacion_id) || '0', 10);
    var estado = mismaReserva && (estadoMesaBackend.ventana_pos || estadoMesaBackend.estado_temporal)
      ? String(estadoMesaBackend.ventana_pos || estadoMesaBackend.estado_temporal)
      : String(reserva && (reserva.ventana_pos || reserva.ventana_operativa) || 'futura');
    var minutosRestantes = mismaReserva && estadoMesaBackend.minutos_para_inicio != null
      ? parseInt(estadoMesaBackend.minutos_para_inicio, 10)
      : (reserva && reserva.minutos_para_reservacion != null
        ? parseInt(reserva.minutos_para_reservacion, 10)
        : null);
    var minutosRetraso = mismaReserva && estadoMesaBackend.minutos_desde_inicio != null
      ? parseInt(estadoMesaBackend.minutos_desde_inicio, 10)
      : parseInt(reserva && reserva.minutos_retraso || '0', 10) || 0;
    var etiquetas = {
      futura: 'Reservaci\u00f3n futura',
      advertencia: minutosRestantes == null
        ? 'Reservaci\u00f3n pr\u00f3xima'
        : 'Reservaci\u00f3n en ' + minutosRestantes + ' min',
      bloqueo: 'Puede iniciar servicio',
      inicio: 'Puede iniciar servicio',
      tolerancia: 'Dentro de la tolerancia',
      ausencia_pendiente: 'Tolerancia vencida',
      en_curso: 'En curso'
    };
    var mensajes = {
      futura: '',
      advertencia: 'El backend mantiene la advertencia; el servicio aún no puede iniciar.',
      bloqueo: 'Puede iniciar servicio.',
      inicio: 'Puede iniciar servicio.',
      tolerancia: 'Cliente con ' + minutosRetraso + ' minutos de retraso. Se encuentra dentro del tiempo de tolerancia.',
      ausencia_pendiente: 'La tolerancia de llegada ya venció. Registra la ausencia antes de utilizar la mesa.',
      en_curso: 'Servicio en curso.'
    };

    return {
      estado: TEMPORAL_LABELS[estado] ? estado : 'futura',
      minutos_restantes: minutosRestantes,
      minutos_retraso: minutosRetraso,
      etiqueta: etiquetas[estado] || 'Reservaci\u00f3n futura',
      mensaje: mensajes[estado] || '',
      hora: String(reserva && reserva.hora || '').substring(0, 5)
    };
  }

  // Estado temporal de una reservacion respecto al reloj del mapa.
  function temporalEstadoReserva(r) {
    if (r.estado === 'cancelada') return 'cancelada';
    if (r.estado === 'en_curso') return 'en_curso';
    return String(r.ventana_pos || r.ventana_operativa || 'futura');
  }

  /**
   * Usa las listas del backend y valida la retención pendiente con el reloj
   * real. Un estado final nunca vuelve a ocupar por cálculos del slider.
   */
  function reservacionInfluye(reserva) {
    return Boolean(reserva && reserva.influye_disponibilidad === true);
  }

  // ── Lookups de ticket y reserva ───────────────────────────
  function capacidadesPosMesa(mesa) {
    var backend = mesaEstadoPorId(parseInt(mesa && mesa.id, 10));
    return backend && backend.capacidades_pos && typeof backend.capacidades_pos === 'object'
      ? backend.capacidades_pos
      : {};
  }

  function isLlevar(mesa) {
    return capacidadesPosMesa(mesa).crear_pedido_llevar === true;
  }

  /*
   * Llevar y las barras son ventanillas, no mesas: atienden varios tickets a
   * la vez y cada uno es propio sobre la misma fila de `mesas`. Llevar no tiene
   * tope y por eso NUNCA se pinta ocupada —un pin rojo le diría al mesero que
   * ya no cabe otro, que es justo lo contrario—; la barra admite hasta el tope
   * de Configuración → POS y sólo al llenarse pasa a rojo.
   *
   * Quién es multi-ticket lo decide el SERVIDOR (capacidades_pos.multi_ticket),
   * igual que el tope: el cliente no adivina por nombre ni por tipo.
   */
  function esMultiTicket(mesa) {
    return capacidadesPosMesa(mesa).multi_ticket === true;
  }

  function ticketsMulti(mesa) {
    return mesa ? ticketsParaMesa(mesa.id) : [];
  }

  // Tickets abiertos a la vez que admite el elemento; null es sin tope (Llevar).
  function topeTickets(mesa) {
    var tope = parseInt(capacidadesPosMesa(mesa).tope_tickets, 10);
    return tope > 0 ? tope : null;
  }

  function multiLleno(mesa) {
    var tope = topeTickets(mesa);
    return tope !== null && ticketsMulti(mesa).length >= tope;
  }

  /*
   * El vocabulario es lo único que separa Llevar de una barra. En la
   * ventanilla se entregan PEDIDOS, con un nombre para la bolsa; en la barra se
   * sienta gente con su TICKET, como en una mesa, y el título es el de la barra
   * porque hay más de una.
   */
  function vocabMulti(mesa) {
    if (isLlevar(mesa)) {
      return {
        singular: 'pedido',
        plural: 'pedidos',
        titulo: 'Para llevar',
        nuevo: 'Nuevo pedido',
        abrir: 'Abrir pedido',
        tablero: 'Pedidos',
        verAbiertos: 'Ver pedidos abiertos',
        volver: 'Volver a pedidos',
        placeholder: 'Nombre para entregar el pedido'
      };
    }
    return {
      singular: 'ticket',
      plural: 'tickets',
      titulo: String(mesa && mesa.nombre || 'Barra'),
      nuevo: 'Nuevo ticket',
      abrir: 'Abrir ticket',
      tablero: 'Tickets',
      verAbiertos: 'Ver tickets abiertos',
      volver: 'Volver a tickets',
      placeholder: 'Nombre del comensal'
    };
  }

  // Con tope se dice contra el tope («2 de 4 tickets»): es la cifra que decide
  // si cabe otro. Sin tope, sólo cuántos van.
  function rotuloTicketsMulti(mesa, total) {
    var v = vocabMulti(mesa);
    var tope = topeTickets(mesa);
    if (tope !== null) return total + ' de ' + tope + ' ' + v.plural;
    if (!total) return '';
    return total + ' ' + (total === 1 ? v.singular : v.plural);
  }

  function pedidosLlevar(mesa) {
    return ticketsMulti(mesa);
  }

  function rotuloPedidosLlevar(total) {
    if (!total) return '';
    return total + (total === 1 ? ' pedido' : ' pedidos');
  }

  function tituloLlevarMapa(mesa, total) {
    return String(mesa.nombre || 'Llevar') + '. Pedidos para llevar. ' +
      (total
        ? rotuloPedidosLlevar(total) + (total === 1 ? ' abierto.' : ' abiertos.')
        : 'Sin pedidos abiertos.') +
      ' Disponible para un nuevo pedido.';
  }

  // El folio del pedido es el id del ticket: es lo único que distingue dos
  // pedidos sin nombre abiertos en el mismo minuto.
  function folioPedido(ticket) {
    return '#' + (ticket && ticket.id ? ticket.id : '');
  }

  // Minutos desde la apertura contra el reloj OPERATIVO, no el del navegador:
  // `hora_apertura` es hora de pared del restaurante y la tablet puede estar
  // en otra zona o con el reloj corrido.
  function minutosAbierto(ticket) {
    var m = String(ticket && ticket.hora_apertura || '')
      .match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (!m) return null;
    var ahora = partesRelojOperativo();
    var f = ahora.fecha.split('-');
    var abierto = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var actual = Date.UTC(+f[0], +f[1] - 1, +f[2], ahora.hora, ahora.minuto);
    return Math.max(0, Math.round((actual - abierto) / 60000));
  }

  function antiguedadPedido(ticket) {
    var min = minutosAbierto(ticket);
    if (min === null) return '';
    if (min < 1) return 'recién abierto';
    if (min < 60) return 'hace ' + min + ' min';
    return 'hace ' + Math.floor(min / 60) + ' h ' + (min % 60) + ' min';
  }

  // Hora de pared operativa en el formato de `tickets.hora_apertura`. El
  // ticket recién abierto se pinta antes de que llegue el refresco, y con
  // toISOString() salía en UTC: seis horas corrido.
  function horaAperturaLocal() {
    var p = partesRelojOperativo();
    return p.fecha + ' ' + String(p.hora).padStart(2, '0') + ':' +
      String(p.minuto).padStart(2, '0') + ':00';
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
      reserva.muestra_advertencia === true
      || reserva.bloquea_walk_ins === true
      || reserva.ticket_abierto === true
      || String(reserva.estado || '') === 'en_curso'
    ));
  }

  function mesaEstadoPorId(mesaId) {
    mesaId = parseInt(mesaId, 10);
    return mesasEstado.find(function(mesaEstado) {
      return parseInt(mesaEstado.id, 10) === mesaId;
    }) || null;
  }

  function resolverOpcionesModalReservacion(context) {
    context = context || {};
    var mesa = context.mesa || {};
    var reserva = context.reserva || {};
    var previousOptions = context.previousOptions || {};
    var backend = context.backend || mesaEstadoPorId(mesa.id) || {};
    var facts = Object.assign({}, mesa, backend);
    var allowWalkIn = previousOptions.allowWalkIn === true
      && String(reserva.estado || '') === 'confirmada'
      && facts.disponible_para_ticket === true
      && facts.requiere_advertencia_ticket === true
      && facts.ticket_abierto !== true
      && facts.ausencia_pendiente !== true
      && reserva.ticket_abierto !== true
      && reserva.ausencia_pendiente !== true;

    return Object.assign({}, previousOptions, {
      allowWalkIn: allowWalkIn
    });
  }

  // El estado del mapa ya fue decidido por MesaEstadoService en el servidor.
  function estadoMesa(mesaId) {
    if (isLlevar(mesaPorId(mesaId))) return 'libre';
    var estado = mesaEstadoPorId(mesaId);
    if (!estado) return 'zona';
    if (estado.ticket_bloquea_consulta === true) {
      return 'con-ticket';
    }
    if (['libre', 'ocupada', 'reservacion-proxima', 'no-utilizable'].indexOf(estado.estado_visual_pos) === -1) {
      return 'zona';
    }
    if (estado.estado_visual_pos === 'no-utilizable') return 'zona';
    if (estado.estado_visual_pos === 'ocupada') return 'ocupada';
    if (estado.estado_visual_pos === 'reservacion-proxima') return 'proxima';
    return 'libre';
  }

  // Estado con tiempo real (para el modal)
  function estadoMesaActual(mesaId) {
    var mesa = mesaPorId(mesaId);
    var backend = mesaEstadoPorId(mesaId);
    if (!isLlevar(mesa)
      && backend
      && backend.ticket_bloquea_consulta === true) {
      return 'con-ticket';
    }
    return estadoMesa(mesaId);
  }

  // ── Render: estados de todos los pines ────────────────────
  function mesaReservable(mesa) {
    return Boolean(mesa && capacidadesPosMesa(mesa).reservable === true);
  }

  function mesaTicketable(mesa) {
    return Boolean(mesa && capacidadesPosMesa(mesa).ticketable === true);
  }

  // La "Caja" es una zona estática del mapa que abre el corte de caja del día.
  function esCaja(mesa) {
    return Boolean(mesa && capacidadesPosMesa(mesa).abrir_caja === true);
  }

  function estadoVisualPosMesa(mesa, backend, estadoFallback) {
    return backend && backend.estado_visual_pos != null
      ? backend.estado_visual_pos
      : estadoFallback;
  }

  function reservacionProximaMesa(mesaId, minActual) {
    var estado = mesaEstadoPorId(mesaId);
    var resumen = estado && estado.reservacion_proxima;
    if (!resumen) return null;
    var reservation = reservacionPorId(resumen.reservacion_id || resumen.id) || resumen;
    var ventana = ventanaReservaDesdeBackend(reservation, mesaId);
    var accionPendiente = estado.ausencia_pendiente === true && estado.puede_marcar_no_show === true
      ? 'REGISTRAR_AUSENCIA'
      : null;
    var bloqueante = estado.disponible_para_ticket !== true
      && estado.ausencia_pendiente !== true;
    return {
      id: parseInt(reservation.reservacion_id || reservation.id, 10),
      reservacion_id: parseInt(reservation.reservacion_id || reservation.id, 10),
      folio: '#' + (reservation.reservacion_id || reservation.id),
      nombre: reservation.nombre || '',
      hora: String(reservation.hora || '').substring(0, 5),
      comensales: parseInt(reservation.comensales || reservation.personas || '0', 10),
      mesa_ids: mesaIdsReserva(reservation),
      mesas: Array.isArray(reservation.mesas_asignadas)
        ? reservation.mesas_asignadas
        : [],
      ventana_operativa: estado.ventana_pos || estado.estado_temporal || ventana.estado,
      minutos_restantes: estado.minutos_para_inicio != null
        ? Number(estado.minutos_para_inicio)
        : ventana.minutos_restantes,
      minutos_retraso: estado.minutos_desde_inicio != null
        ? Number(estado.minutos_desde_inicio)
        : ventana.minutos_retraso,
      nivel: accionPendiente === 'REGISTRAR_AUSENCIA'
        ? 'accion-pendiente'
        : (bloqueante ? 'bloqueo' : 'advertencia'),
      accion_pendiente: accionPendiente,
      motivo_bloqueo: reservation.motivo_bloqueo || null,
      bloqueo: reservation.bloqueo || null
    };
  }

  function reservacionesProximasParaTicket(ticket) {
    var resultado = [];
    var advertencias = Array.isArray(ticket && ticket.reservaciones_proximas)
      ? ticket.reservaciones_proximas
      : [];
    advertencias.forEach(function(advertencia) {
      var reserva = reservacionPorId(advertencia.reservacion_id || advertencia.id) || advertencia;
      var mesaId = mesaIdsReserva(reserva)[0] || (ticket.mesa_ids || [])[0];
      resultado.push({
        reservacion: reserva,
        mesaId: Number(mesaId),
        minutos_restantes: advertencia.minutos_para_reservacion,
        nivel: 'advertencia'
      });
    });
    return resultado;
  }

  function horaCortaTicket(ticket) {
    var match = String(ticket && ticket.hora_apertura || '').match(/(?:T|\s)(\d{2}:\d{2})/);
    return match ? match[1] : '';
  }

  function tituloMesaMapa(mesa, estado, ticket, proxima, ticketBloquea) {
    var partes = [String(mesa.nombre || 'Mesa') + '.'];
    var ticketHora = horaCortaTicket(ticket);
    var capacidades = capacidadesPosMesa(mesa);
    var etiquetaOperacion = String(capacidades.etiqueta_operacion || '');
    var elementoOperativo = capacidades.operable === true;

    if (ticket && ticketBloquea !== false) {
      partes.push('Ocupada. Ticket abierto.');
      if (Number(ticket.comensales) > 0) {
        partes.push(String(ticket.comensales) + (Number(ticket.comensales) === 1 ? ' comensal.' : ' comensales.'));
      }
      if (ticketHora) partes.push('Abierto a las ' + ticketHora + '.');
    } else if (ticket && ticketBloquea === false) {
      partes.push('Disponible después de la liberación estimada del ticket.');
    } else if (esCaja(mesa)) {
      partes.push('Caja operativa. Abre el corte de caja.');
    } else if (isLlevar(mesa)) {
      partes.push('Pedidos para llevar. Disponible para un nuevo pedido.');
    } else if (etiquetaOperacion) {
      partes.push(etiquetaOperacion + ' operativa. Disponible para abrir un ticket.');
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
      var ventana = proxima.ventana_operativa || 'advertencia';
      if (ventana === 'advertencia') {
        if (!ticket) {
          return String(mesa.nombre || 'Mesa') + ', disponible, reservaci\u00f3n dentro de ' +
            proxima.minutos_restantes + ' minutos.';
        }
        partes.push('Reservaci\u00f3n dentro de ' + proxima.minutos_restantes + ' minutos.');
      } else if (ventana === 'bloqueo' || ventana === 'inicio') {
        partes.push('Reservaci\u00f3n a las ' + proxima.hora + '. Puede iniciar servicio.');
      } else if (ventana === 'tolerancia') {
        partes.push('Reservaci\u00f3n a las ' + proxima.hora + '. Cliente con ' +
          proxima.minutos_retraso + ' minutos de retraso. Se encuentra dentro del tiempo de tolerancia.');
      } else if (ventana === 'ausencia_pendiente') {
        partes.push('Tolerancia vencida. Registra que el cliente no llegó antes de utilizar la mesa.');
      }
    } else {
      var reservaContextual = reservaParaModal(parseInt(mesa.id, 10));
      if (reservaContextual
        && (reservaContextual.ventana_pos || reservaContextual.ventana_operativa) !== 'futura') {
        partes.push(ventanaReservaDesdeBackend(reservaContextual, mesa.id).mensaje);
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
  function contratoMesaMapa(mesa, estado) {
    if (isLlevar(mesa)) return contratoLlevarMapa(mesa);
    if (esCaja(mesa)) {
      return Object.assign({}, mesa, {
        estado_base: 'disponible',
        modificadores: [],
        reservacion_proxima: null,
        ticket_abierto: null,
        seleccion_actual: false,
        titulo: 'Caja operativa. Abre el corte de caja.',
        estado_visual_pos: 'libre'
      });
    }
    var backend = mesaEstadoPorId(parseInt(mesa.id, 10)) || {};
    var ticketLeido = ticketActual(parseInt(mesa.id, 10));
    // El ticket sólo entra al contrato del bloque si el backend lo proyectó
    // para la consulta. Una fecha futura puede tener tickets abiertos en el
    // listado global, pero no debe pintarlos ni usarlos como bloqueo.
    var ticketContextual = backend.ticket
      && typeof backend.ticket === 'object'
      ? ticketLeido
      : null;
    var ticket = ticketContextual;
    var ticketBloquea = backend.ticket_bloquea_consulta === true;
    var proxima = reservacionProximaMesa(parseInt(mesa.id, 10));
    var estadoVisualPos = estadoVisualPosMesa(mesa, backend, estado);
    var capacidades = capacidadesPosMesa(mesa);
    var titulo = backend.aria_label_pos || tituloMesaMapa(mesa, estado, ticket, proxima, ticketBloquea);
    // Respaldo sólo si el servidor no mandó su rótulo: el suyo ya cuenta los
    // tickets de una barra contra su tope, y éste no sabe hacerlo.
    if (capacidades.mostrar_estado_ticket === true && !backend.aria_label_pos) {
      titulo = ticket
        ? tituloMesaMapa(mesa, estadoVisualPos, ticket, null, ticketBloquea)
        : (ticketBloquea
          ? String(mesa.nombre || 'Elemento') + '. Ocupada por un ticket abierto.'
          : String(mesa.nombre || 'Elemento') + '. ' + (capacidades.etiqueta_operacion || 'Elemento') +
            ' operativa. Disponible para abrir un ticket.');
    }
    var stateBase = estadoVisualPos === 'ocupada'
      ? 'ocupada'
      : (estadoVisualPos === 'reservacion-proxima' ? 'bloqueada' : (estado === 'zona' ? 'no_reservable' : 'disponible'));
    var modifiers = Array.isArray(backend.modificadores_visual_pos)
      ? backend.modificadores_visual_pos.slice()
      : [];

    if (ticket && ticketBloquea) {
      stateBase = 'ocupada';
      if (modifiers.indexOf('ticket_abierto') === -1) modifiers.push('ticket_abierto');
    }
    if (ticket && !ticketBloquea && modifiers.indexOf('ticket_proyectado_liberado') === -1) {
      modifiers.push('ticket_proyectado_liberado');
    }
    var normalized = Object.assign({}, mesa, {
      estado_base: stateBase,
      modificadores: modifiers,
      reservacion_proxima: proxima,
      minutos_restantes: proxima ? proxima.minutos_restantes : null,
      ticket_abierto: ticket ? { id: ticket.id, reservacion_id: ticket.reservacion_id } : null,
      walk_in: Boolean(ticket && ticket.origen === 'walk_in'),
      seleccion_actual: selectedMesaIds.indexOf(parseInt(mesa.id, 10)) !== -1,
      accion_pendiente: backend.accion_pendiente || (proxima && proxima.accion_pendiente) || null,
      motivo_bloqueo: backend.motivo_bloqueo || (estado === 'bloqueada' ? 'Bloqueada por reservación próxima.' : null),
      bloqueo: backend.bloqueo || (proxima && proxima.bloqueo) || null,
      titulo: titulo,
      estado_visual_pos: estadoVisualPos,
      modificadores_visual_pos: backend.modificadores_visual_pos,
      ticket_abierto: ticket
        ? Object.assign({}, ticket, { bloquea_en_consulta: Boolean(ticketBloquea) })
        : null
    });
    return normalized;
  }

  // El contrato de Llevar no hereda nada del ticket ni del estado físico que
  // calcula el backend: ninguno de los dos le aplica.
  function contratoLlevarMapa(mesa) {
    var total = pedidosLlevar(mesa).length;
    return Object.assign({}, mesa, {
      estado_base: 'disponible',
      modificadores: [],
      reservacion_proxima: null,
      minutos_restantes: null,
      ticket_abierto: null,
      walk_in: false,
      seleccion_actual: false,
      accion_pendiente: null,
      motivo_bloqueo: null,
      bloqueo: null,
      titulo: (mesaEstadoPorId(parseInt(mesa.id, 10)) || {}).aria_label_pos || tituloLlevarMapa(mesa, total),
      estado_visual_pos: 'libre',
      independienteDeConsulta: capacidadesPosMesa(mesa).independiente_consulta === true
    });
  }

  function contratoVisualMesaValido(mesa) {
    if (esCaja(mesa) || isLlevar(mesa)) return true;
    var backend = mesaEstadoPorId(parseInt(mesa && mesa.id, 10)) || {};
    var estado = window.MapaContrato && window.MapaContrato.validarEstado
      ? window.MapaContrato.validarEstado(backend.estado_visual_pos)
      : { valido: false };
    var modificadoresValidos = window.MapaContrato
      && window.MapaContrato.validarModificadores(backend.modificadores_visual_pos);
    return estado.valido && modificadoresValidos;
  }

  /*
   * «Cambiar mesas» enseña el resultado, no el estado guardado: la mesa que
   * se elige pasa a azul con la hora de la reserva, y la que deja pasa a
   * libre. Sin esto, la vieja seguía azul (el servidor aún la tiene asignada)
   * y la nueva sólo ganaba el contorno, así que parecían dos reservas.
   * Un ticket que ocupa manda siempre: esa mesa no se toca.
   */
  function vistaAsignacion(mesa) {
    if (!modoAsignacion || !mesa) return null;
    var id = parseInt(mesa.id, 10);
    var reserva = modoAsignacion.reserva;
    var backend = mesaEstadoPorId(id) || {};
    if (backend.ticket_bloquea_consulta === true || backend.estado_visual_pos === 'ocupada') return null;
    var reservaId = parseInt(reserva.id, 10) || 0;
    var duena = backend.estado_visual_pos === 'reservacion-proxima'
      ? parseInt(backend.reservacion_mapa_id || backend.reservacion_id
        || (backend.hold && backend.hold.reservacion_id)
        || (backend.reservacion_proxima && (backend.reservacion_proxima.reservacion_id || backend.reservacion_proxima.id)), 10) || -1
      : 0;
    var elegida = modoAsignacion.seleccion.indexOf(id) !== -1 || modoAsignacion.pendiente === id;
    if (elegida) {
      // Si ya la tiene OTRA reserva a esa hora, se queda con su hora y su
      // color: pintarla con la nuestra borraba del mapa el choque que el
      // servidor va a pedir confirmar al guardar. El contorno ya dice que
      // está elegida.
      if (duena !== 0 && duena !== reservaId) return null;
      return {
        estadoVisual: 'reservacion-proxima',
        etiquetaEstado: 'Reservada',
        subtitulo: V.hhmm(reserva.hora),
        aria: modoAsignacion.pendiente === id
          ? ' Por confirmar: elige abajo si se añade o sustituye. Toca para descartarla.'
          : ' Para esta reservación, a las ' + V.hhmm(reserva.hora) + '. Toca para quitarla.'
      };
    }
    var eraSuya = reservaId > 0 && duena === reservaId && V.ids(reserva).indexOf(id) !== -1;
    if (eraSuya) {
      return { estadoVisual: 'libre', etiquetaEstado: 'Libre', subtitulo: '',
        aria: ' Libre: sale de esta reservación. Toca para elegirla de nuevo.' };
    }
    return null;
  }

  function opcionesVisualesMesa(mesa, estado) {
    // Caja y Llevar son FICHAS: botones de acción con su icono, sin línea de
    // estado ni color. No hay nada que decidir sobre ellas antes de tocarlas.
    var backendFicha = mesaEstadoPorId(parseInt(mesa.id, 10)) || {};
    if (isLlevar(mesa)) {
      var totalLlevar = pedidosLlevar(mesa).length;
      return {
        x: insetPos(mesa.pos_x),
        y: insetPos(mesa.pos_y),
        ancho: mesa.ancho,
        alto: mesa.alto,
        interactivo: !ticketSelectionMode,
        independienteDeConsulta: capacidadesPosMesa(mesa).independiente_consulta === true,
        seleccionValida: false,
        estadoVisual: 'libre',
        ficha: true,
        icono: 'bolsa',
        ariaLabel: backendFicha.aria_label_pos || tituloLlevarMapa(mesa, totalLlevar),
        seleccionActual: false,
        clasesEstado: totalLlevar ? ['mesa-pin--con-pedidos'] : [],
        atributos: {
          'data-id': mesa.id,
          'data-numero': mesa.numero == null ? '' : mesa.numero,
          'data-reservable': mesa.reservable,
          'data-ticketable': '1',
          'data-estado': 'libre',
          'data-ticket-id': '',
          'data-pedidos': String(totalLlevar)
        }
      };
    }
    if (esCaja(mesa)) {
      return {
        x: insetPos(mesa.pos_x),
        y: insetPos(mesa.pos_y),
        ancho: mesa.ancho,
        alto: mesa.alto,
        interactivo: !ticketSelectionMode,
        independienteDeConsulta: capacidadesPosMesa(mesa).independiente_consulta === true,
        seleccionValida: false,
        estadoVisual: 'libre',
        ficha: true,
        icono: 'caja',
        ariaLabel: backendFicha.aria_label_pos || 'Caja operativa. Abre el corte de caja.',
        seleccionActual: false,
        clasesEstado: [],
        atributos: {
          'data-id': mesa.id,
          'data-ticketable': '1',
          'data-estado': 'libre',
          'data-ticket-id': ''
        }
      };
    }
    var ticket = ticketActual(parseInt(mesa.id, 10));
    var backend = mesaEstadoPorId(parseInt(mesa.id, 10)) || {};
    var ticketBloquea = backend.ticket_bloquea_consulta === true;
    var ticketable = mesaTicketable(mesa);
    // Las mesas de la reserva con la ficha abierta se pintan con la selección
    // de siempre (el contorno que la ayuda ya explica): no hay un segundo
    // código para «enfocada».
    var enFicha = !ticketSelectionMode && fichaMesaIds.indexOf(parseInt(mesa.id, 10)) !== -1;
    if (modoAsignacion) {
      // Cambiando mesas: la selección es la de la reserva, y sólo se tocan
      // las mesas que pueden recibirla.
      var elegible = mesaElegibleAsignacion(mesa);
      enFicha = modoAsignacion.seleccion.indexOf(parseInt(mesa.id, 10)) !== -1
        || modoAsignacion.pendiente === parseInt(mesa.id, 10);
      var vista = vistaAsignacion(mesa);
      return {
        x: insetPos(mesa.pos_x),
        y: insetPos(mesa.pos_y),
        ancho: mesa.ancho,
        alto: mesa.alto,
        interactivo: elegible,
        seleccionValida: elegible || enFicha,
        independienteDeConsulta: false,
        estadoVisual: vista ? vista.estadoVisual : estadoVisualPosMesa(mesa, backend, estado),
        etiquetaEstado: vista ? vista.etiquetaEstado : (backend.etiqueta_estado_pos || null),
        // «Seleccionada» lo añade map-visual: aquí no se repite «elegida».
        ariaLabel: vista
          ? mesa.nombre + '.' + vista.aria
          : (backend.aria_label_pos || mesa.nombre + '.') + (elegible
            ? (enFicha
              ? ' Para esta reservación'
                + (backend.estado_visual_pos === 'reservacion-proxima' ? ', aunque ya tiene otra reserva' : '')
                + '. Toca para quitarla.'
              : ' Toca para elegirla.')
            : ' No puede recibir la reservación.'),
        seleccionActual: enFicha,
        clasesEstado: [],
        atributos: {
          'data-id': mesa.id,
          'data-estado': estado,
          'data-ticket-id': ticket ? ticket.id : ''
        }
      };
    }
    var seleccionValida = enFicha || (ticketSelectionMode
      ? mesaPuedeSeleccionarse(mesa, estado)
      : mesaReservable(mesa) && !ticketBloquea && !(
        reservaParaModal(parseInt(mesa.id, 10)) || {}
      ).bloquea_walk_ins);
    var etiquetaAccesible = backend.aria_label_pos || null;
    if (etiquetaAccesible && enProyeccion()) {
      etiquetaAccesible += ' Vista de las ' + String(contextoMapa.hora || '').substring(0, 5) + ', sólo consulta.'
        + (backend.estado_visual_pos === 'reservacion-proxima' ? ' Abre la ficha de la reservación.' : '');
    }
    return {
      x: insetPos(mesa.pos_x),
      y: insetPos(mesa.pos_y),
      ancho: mesa.ancho,
      alto: mesa.alto,
      interactivo: ticketSelectionMode
        ? seleccionValida
        : ticketable,
      seleccionValida: seleccionValida,
      independienteDeConsulta: capacidadesPosMesa(mesa).independiente_consulta === true,
      estadoVisual: estadoVisualPosMesa(mesa, backend, estado),
      // La palabra la pone el servidor («Libre», «Ocupada», «Reservada»,
      // «Llena»): es la misma que el aria-label y no puede discrepar del color.
      etiquetaEstado: backend.etiqueta_estado_pos || (capacidadesPosMesa(mesa).operable
        ? null
        : 'No disponible'),
      ariaLabel: etiquetaAccesible,
      seleccionActual: enFicha || selectedMesaIds.indexOf(parseInt(mesa.id, 10)) !== -1,
      clasesEstado: !capacidadesPosMesa(mesa).operable ? ['mesa-pin--no-utilizable'] : [],
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
    if (!ticketSelectionMode || mapSnapshotStale || !mesa || !mesaReservable(mesa)
      || !contratoVisualMesaValido(mesa)) return false;
    var estadoBackend = mesaEstadoPorId(parseInt(mesa.id, 10));
    if (estadoBackend && estadoBackend.ticket_bloquea_consulta === true) {
      return false;
    }
    if (estado !== 'libre' && estado !== 'proxima') return false;

    var reserva = reservaParaModal(parseInt(mesa.id, 10));
    if (!reserva) return true;

    return reserva.bloquea_walk_ins !== true;
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
    var estado = ticketable ? estadoMesa(parseInt(mesa.id, 10)) : (esCaja(mesa) ? 'libre' : 'zona');
    var contract = contratoMesaMapa(mesa, estado);

    var visual = window.MesaEstadoAdapter.paraMapaVisual(
      contract,
      opcionesVisualesMesa(mesa, estado)
    );
    visual.subtitulo = subtituloMesaMapa(mesa);
    return visual;
  }

  // El dato que decide («en 45 min», «Sin llegar», «2/4», «3 pedidos») lo
  // calcula el servidor; el conteo local de Llevar sólo cubre una respuesta
  // sin ese campo.
  function subtituloMesaMapa(mesa) {
    var vista = vistaAsignacion(mesa);
    if (vista) return vista.subtitulo;
    var backend = mesaEstadoPorId(parseInt(mesa && mesa.id, 10)) || {};
    if (typeof backend.subtitulo_pos === 'string') return backend.subtitulo_pos;
    return isLlevar(mesa) ? rotuloPedidosLlevar(pedidosLlevar(mesa).length) : '';
  }

  function renderEstados() {
    for (var i = 0; i < mesas.length; i++) {
      var mesa = mesas[i];

      var estado = mesaTicketable(mesa)
        ? estadoMesa(parseInt(mesa.id, 10))
        : (esCaja(mesa) ? 'libre' : 'zona');
      var visual = window.MesaEstadoAdapter.paraMapaVisual(
        contratoMesaMapa(mesa, estado),
        opcionesVisualesMesa(mesa, estado)
      );
      // Con la vista previa de «Cambiar mesas» los modificadores del servidor
      // describen la asignación GUARDADA («asignada_actualmente» enmarca la
      // mesa que se deja): sólo sobrevive el de selección.
      var modificadores = vistaAsignacion(mesa)
        ? (visual.modificadores || []).filter(function(m) { return m === 'seleccion_actual'; })
        : visual.modificadores;
      mapVisual.actualizarEstado(mesa.id, {
        estadoVisual: visual.estadoVisual,
        estadoNoVerificado: visual.estadoNoVerificado,
        interactivo: visual.interactivo,
        seleccionValida: visual.seleccionValida,
        seleccionada: visual.seleccionada,
        modificadores: modificadores,
        clasesEstado: visual.clasesEstado,
        titulo: visual.titulo,
        etiquetaEstado: visual.etiquetaEstado,
        ariaLabel: visual.ariaLabel,
        subtitulo: subtituloMesaMapa(mesa),
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

  // ── Reservaciones en el POS: barra de contexto, cajón y ficha ─────────
  //
  // La pantalla de Operación se retira: aquí se ve el día entero (cajón), se
  // proyecta el mapa a la hora de una reserva o de un horario (barra) y se
  // opera la reserva (ficha). Las tres piezas sólo pintan; las decisiones de
  // flujo viven aquí y las de negocio en el servidor.
  var V = window.PosReservaVista;
  var configReservacionesPos = {};
  var avisoSoloLecturaHasta = 0;
  // Con qué se abrió la ficha (mesa tocada, walk-in): el refresco lo revalida.
  var fichaApertura = {};

  var contextoBarra = window.PosContexto ? window.PosContexto.crear({
    raiz: document.querySelector('[data-pos-contexto]'),
    onAhora: function() { irAAhora(); },
    onHora: function(hora) { proyectarHorario(hora); }
  }) : null;

  var cajon = window.PosCajon ? window.PosCajon.crear({
    lista: reservasList,
    contador: reservaCount,
    onAhora: function() { irAAhora(); },
    onHorario: function(hora) { proyectarHorario(hora); },
    onReserva: function(id) { abrirReservaDesdeLista(id); },
    onReintentar: function() { fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), false); }
  }) : null;

  // Al abrir el cajón, la lista se coloca en el horario que se está mirando
  // o en lo primero que pide algo, no arriba del todo.
  document.addEventListener('click', function(event) {
    if (cajon && event.target.closest && event.target.closest('[data-operational-drawer-toggle]')) {
      window.setTimeout(function() { cajon.enfocarActual(); }, 80);
    }
  });

  var ficha = window.PosFicha ? window.PosFicha.crear({
    raiz: document.getElementById('pos-ficha'),
    acciones: accionesFicha(),
    onCerrar: function(anterior) { ocultarFicha(anterior); }
  }) : null;

  // «Nueva reservación» (pie del cajón): para el día que se está mirando.
  var cambioPendienteId = 0;
  var alta = window.PosAlta ? window.PosAlta.crear({
    mesaPorId: mesaPorId,
    maxComensales: function() { return parseInt(configReservacionesPos.max_comensales_publico, 10) || 12; },
    disponibilidad: function(fecha, personas) {
      return fetch('/api/punto-de-venta/reservaciones/disponibilidad?fecha=' + encodeURIComponent(fecha)
        + '&personas=' + encodeURIComponent(personas), { cache: 'no-store' })
        .then(function(res) { return res.json(); });
    },
    crear: function(datos) {
      return postJson('/api/punto-de-venta/reservaciones/crear', datos);
    },
    elegirMesas: function(datos, sugeridas) { iniciarAltaConMesas(datos, sugeridas); },
    alCrear: function(resultado, datos) {
      var id = parseInt(resultado.reservacion_id || resultado.reservationId, 10);
      var mesas = Array.isArray(resultado.mesa_ids) ? resultado.mesa_ids : [];
      aviso('Reservación creada: ' + datos.nombre + ', ' + datos.hora
        + (mesas.length ? ' en ' + V.nombresMesas(mesas, mesaPorId) + '.' : '.'), 'success');
      if (!id) {
        silentRefresh();
        return;
      }
      // Se proyecta a su hora y se abre su ficha, para ver dónde quedó. Toda
      // reservación nace con mesas: el servidor no guarda una sin ellas.
      fichaPendiente = { id: id, opciones: {} };
      transicionPendiente = 'pos-ficha';
      proyectarMapa({ hora: datos.hora, reservacionId: id });
    }
  }) : null;

  document.addEventListener('click', function(event) {
    var abrir = event.target.closest && event.target.closest('[data-alta-abrir]');
    if (abrir && alta) {
      // El botón vive en el cajón, que se cierra (data-cierra-cajon); el
      // diálogo se abre en el siguiente fotograma para no pelear el foco.
      window.setTimeout(function() { alta.abrir(fechaMapa()); }, 60);
    }
  });

  function esAdmin() {
    return permisosPos && permisosPos.es_admin === true;
  }

  function pad2(n) {
    return ('0' + n).slice(-2);
  }

  function relojHM() {
    var p = partesRelojOperativo();
    return pad2(p.hora) + ':' + pad2(p.minuto);
  }

  function fechaMapa() {
    return fechaInput ? fechaInput.value : fechaHoyLocal();
  }

  function esHoyMapa() {
    return fechaMapa() === fechaHoyLocal();
  }

  function idsBloqueExterior() {
    return mesas.filter(function(m) {
      return String(m.tipo || 'mesa') === 'mesa' && String(m.zona || '') === 'exterior';
    }).map(function(m) { return parseInt(m.id, 10); }).sort(function(a, b) { return a - b; });
  }

  function esBloqueExterior(ids) {
    var bloque = idsBloqueExterior();
    var elegidas = (ids || []).map(Number).sort(function(a, b) { return a - b; });
    return bloque.length > 0 && bloque.join(',') === elegidas.join(',');
  }

  function reservaDiaPorId(id) {
    id = parseInt(id, 10);
    for (var i = 0; i < reservacionesDia.length; i++) {
      if (parseInt(reservacionesDia[i].id, 10) === id) return reservacionesDia[i];
    }
    return reservacionPorId(id);
  }

  function opcionesVistaReserva(extra) {
    return Object.assign({
      mesaPorId: mesaPorId,
      esBloque: esBloqueExterior,
      maxComensales: parseInt(configReservacionesPos.max_comensales_publico, 10) || 0,
      esAdmin: esAdmin(),
      esHoy: esHoyMapa(),
      temporal: temporalConfig,
      reservasDia: reservacionesDia,
      proyeccion: enProyeccion()
    }, extra || {});
  }

  // ── Cajón ──────────────────────────────────────────────────────────
  function renderSidebar() {
    if (!cajon) return;
    cajon.pintar({
      reservas: reservacionesDia,
      esHoy: esHoyMapa(),
      ahoraHM: relojHM(),
      fechaIso: fechaMapa(),
      contexto: contextoMapa,
      enfocadaId: ficha ? ficha.reservaId() : 0,
      esAdmin: esAdmin(),
      mesaPorId: mesaPorId,
      esBloque: esBloqueExterior,
      maxComensales: parseInt(configReservacionesPos.max_comensales_publico, 10) || 0
    });
  }

  function clearHighlight() {
    fichaMesaIds = [];
    mapVisual.setSeleccionadas([]);
  }

  // ── Barra de contexto ──────────────────────────────────────────────
  function reservasVivas() {
    return reservacionesDia.filter(function(r) { return !V.estaResuelta(r); });
  }

  function pintarContexto() {
    if (!contextoBarra) return;
    var horaVista = V.hhmm(contextoMapa.hora);
    var enHora = reservasVivas().filter(function(r) { return V.hhmm(r.hora) === horaVista; });
    contextoBarra.pintar({
      modo: contextoMapa.modo,
      esHoy: esHoyMapa(),
      fecha: contextoMapa.fecha || fechaMapa(),
      hora: horaVista,
      ahoraHM: relojHM(),
      horario: horarioPos,
      capacidad: capacidadHorarioPos ? {
        libre: parseInt(capacidadHorarioPos.capacidad_real_disponible != null
          ? capacidadHorarioPos.capacidad_real_disponible
          : capacidadHorarioPos.capacidad_fisica_libre, 10) || 0,
        total: parseInt(capacidadHorarioPos.capacidad_fisica_total, 10) || 0
      } : null,
      enHora: {
        reservas: enHora.length,
        personas: enHora.reduce(function(acc, r) { return acc + (parseInt(r.comensales, 10) || 0); }, 0)
      },
      esAdmin: esAdmin()
    });
  }

  // El reloj de «Ahora» y las señales de minutos avanzan entre consultas.
  window.setInterval(function() {
    if (contextoBarra) contextoBarra.reloj(relojHM());
  }, 20000);

  // ── Proyección y regla de tiempo ───────────────────────────────────
  // Mientras corre una transición, el mapa que se ve no es todavía el que se
  // pidió: un toque ahí actuaría sobre una mesa en el estado equivocado.
  function enTransicion() {
    return !!(window.PosTransicion && window.PosTransicion.enCurso());
  }

  // Navegar a otra hora, a «Ahora» o a otra reserva deja el modo «Cambiar
  // mesas»: seguir eligiendo mesas mientras el mapa enseña otra cosa asignaría
  // a ciegas.
  function dejarAsignacionAlNavegar() {
    if (!modoAsignacion || modoAsignacion.guardando) return;
    // Cambiar de hora o volver a Ahora a media alta de un grupo grande tira el
    // borrador: sin aviso, el nombre y la nota tecleados desaparecían en
    // silencio. Es lo mismo que dice «Descartar».
    var eraBorrador = !!modoAsignacion.borrador;
    salirModoAsignacion();
    if (eraBorrador) aviso('No se creó la reservación.', 'info');
  }

  function proyectarHorario(hora) {
    if (enTransicion()) return;
    dejarAsignacionAlNavegar();
    // Cambiar de hora con la ficha de OTRA hora abierta la cierra: la ficha
    // describe una reserva de la hora que se está mirando.
    var abierta = ficha && ficha.reserva();
    if (abierta && V.hhmm(abierta.hora) !== hora) ficha.cerrar();
    transicionPendiente = 'pos-proyeccion';
    proyectarMapa({ hora: hora });
  }

  function irAAhora() {
    if (enTransicion()) return;
    dejarAsignacionAlNavegar();
    // La ficha abierta se queda: ahora enseña dónde están sus mesas en vivo.
    transicionPendiente = 'pos-proyeccion';
    var vuelta = volverAAhora();
    if (contextoBarra && vuelta && typeof vuelta.then === 'function') {
      vuelta.then(function() { contextoBarra.enfocar(); });
    }
  }

  /*
   * Regla de tiempo: una reserva cuya hora ya llegó (hoy), o resuelta, se
   * abre EN AHORA; una futura se proyecta a su hora; otro día, a su hora.
   * Proyectar al pasado enseñaría un salón que ya no existe.
   */
  function abrirReservaDesdeLista(id) {
    if (enTransicion()) return;
    dejarAsignacionAlNavegar();
    var reserva = reservaDiaPorId(id);
    if (!reserva) {
      aviso('Esa reservación ya no está en la lista.', 'warning');
      return;
    }
    var hora = V.hhmm(reserva.hora);
    var resuelta = V.estaResuelta(reserva) || V.enMesa(reserva);
    var hoy = esHoyMapa();
    var proyectar = !resuelta && (!hoy || hora > relojHM());
    if (proyectar) {
      if (enProyeccion() && V.hhmm(contextoMapa.hora) === hora) {
        abrirFicha(reserva, {});
        return;
      }
      fichaPendiente = { id: parseInt(reserva.id, 10), opciones: {} };
      transicionPendiente = 'pos-ficha';
      proyectarMapa({ hora: hora, reservacionId: reserva.id });
      return;
    }
    if (enProyeccion() && hoy) {
      fichaPendiente = { id: parseInt(reserva.id, 10), opciones: {} };
      transicionPendiente = 'pos-ficha';
      volverAAhora();
      return;
    }
    abrirFicha(reserva, {});
  }

  // ── Ficha ──────────────────────────────────────────────────────────
  function mostrarFichaSinTransicion(reserva, extra) {
    if (!ficha) return;
    fichaApertura = extra || {};
    ficha.abrir(reserva, opcionesVistaReserva(extra));
    if (posMapEl) posMapEl.classList.add('has-ficha');
    fichaMesaIds = V.ids(reserva);
    renderEstados();
    renderSidebar();
  }

  function llamarMesasFicha() {
    if (!window.PosTransicion) return;
    window.PosTransicion.llamar(fichaMesaIds.map(function(id) {
      return canvas.querySelector('[data-mapa-mesa="' + id + '"]');
    }).filter(Boolean));
  }

  function abrirFicha(reserva, extra) {
    if (!ficha || !reserva) return;
    if (ticketSelectionMode) cancelarModoSeleccion();
    var yaAbierta = ficha.abierta();
    var hecho = function() {
      mostrarFichaSinTransicion(reserva, extra);
    };
    var transicion = !yaAbierta && window.PosTransicion
      ? window.PosTransicion.ejecutar('pos-ficha', hecho)
      : (hecho(), Promise.resolve());
    transicion.then(function() {
      llamarMesasFicha();
      ficha.enfocarTitulo();
    });
  }

  function ocultarFicha(anterior) {
    var hecho = function() {
      var raiz = document.getElementById('pos-ficha');
      if (raiz) raiz.hidden = true;
      if (posMapEl) posMapEl.classList.remove('has-ficha');
      fichaMesaIds = [];
      renderEstados();
      renderSidebar();
    };
    var transicion = window.PosTransicion
      ? window.PosTransicion.ejecutar('pos-ficha', hecho)
      : (hecho(), Promise.resolve());
    transicion.then(function() {
      // El foco vuelve al pin de su primera mesa; si no está, al menú.
      var primera = anterior ? V.ids(anterior)[0] : 0;
      var pin = primera ? canvas.querySelector('[data-mapa-mesa="' + primera + '"]') : null;
      var destino = pin && !pin.disabled ? pin : document.querySelector('[data-operational-drawer-toggle]');
      if (destino && typeof destino.focus === 'function') destino.focus();
    });
  }

  // Después de cada respuesta: la ficha pendiente se abre, la abierta se
  // repinta sólo si su reserva cambió.
  function sincronizarFicha() {
    if (!ficha) return false;
    if (fichaPendiente) {
      var pendiente = reservaDiaPorId(fichaPendiente.id);
      var opcionesPendientes = fichaPendiente.opciones || {};
      fichaPendiente = null;
      if (pendiente) {
        mostrarFichaSinTransicion(pendiente, opcionesPendientes);
        return true;
      }
      aviso('Esa reservación ya no está en la lista.', 'warning');
      return false;
    }
    if (ficha.abierta()) {
      var reserva = reservaDiaPorId(ficha.reservaId());
      if (reserva) {
        var extra = { proyeccion: enProyeccion(), esHoy: esHoyMapa(), reservasDia: reservacionesDia };
        // «Abrir ticket de todas formas» se revalida con los hechos nuevos:
        // si la ventana se cerró entretanto, el botón se va.
        if (fichaApertura.allowWalkIn && fichaApertura.mesa) {
          extra.allowWalkIn = resolverOpcionesModalReservacion({
            mesa: fichaApertura.mesa,
            reserva: reserva,
            previousOptions: { allowWalkIn: true }
          }).allowWalkIn;
        }
        ficha.refrescar(reserva, extra);
        fichaMesaIds = V.ids(reserva);
      }
    }
    return false;
  }

  // Una reservación recién creada sin mesas entra directo a elegirlas.
  function continuarCambioPendiente() {
    continuarEnlaceInicial();
    if (!cambioPendienteId) return;
    var reserva = reservaDiaPorId(cambioPendienteId);
    cambioPendienteId = 0;
    if (reserva) iniciarCambioMesas(reserva);
  }

  function continuarEnlaceInicial() {
    if (!enlaceInicial || !enlaceInicial.reservacionId) return;
    var pedido = enlaceInicial;
    enlaceInicial = null;
    // Recargar la pestaña no debe volver a abrir la ficha ni el modo de mesas:
    // de la URL sólo sobrevive la fecha.
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState(null, '', window.location.pathname + '?fecha=' + encodeURIComponent(fechaMapa()));
    }
    var reserva = reservaDiaPorId(pedido.reservacionId);
    if (!reserva) {
      aviso('Esa reservación ya no está activa en el día.', 'warning');
      return;
    }
    if (pedido.asignar) iniciarCambioMesas(reserva);
    else abrirReservaDesdeLista(reserva.id);
  }

  function postReservacion(endpoint, payload) {
    return postJson(endpoint, payload).then(function(result) {
      if (!result || result.ok !== true) throw result || {};
      return result;
    });
  }

  function accionesFicha() {
    return {
      llego: function(reserva, boton) {
        apiIniciarServicio(reserva, selectedMeseroId(), boton);
      },
      cancelar: function(reserva, motivo) {
        return postReservacion('/api/punto-de-venta/reservaciones/cancelar', {
          reservacion_id: reserva.id,
          motivo: motivo
        }).then(function(result) {
          if (ficha) ficha.cerrar();
          aviso('Reservación cancelada: ' + (reserva.nombre || '#' + reserva.id) + '.', 'success');
          silentRefresh();
          return result;
        });
      },
      cambiarMesas: function(reserva) {
        iniciarCambioMesas(reserva);
      },
      revisar: function(reubicacionId) {
        return postReservacion('/api/punto-de-venta/reservaciones/reubicaciones/revisar', {
          reubicacion_id: reubicacionId
        }).then(function(result) {
          silentRefresh();
          return result;
        }, function(error) {
          aviso((error && error.mensaje) || 'No se pudo marcar como revisada.');
          throw error;
        });
      },
      comentario: function(reserva, texto) {
        return postReservacion('/api/punto-de-venta/reservaciones/comentario', {
          reservacion_id: reserva.id,
          comentario_admin: texto
        }).then(function(result) {
          aviso('Comentario guardado.', 'success');
          return result;
        });
      },
      walkIn: function(reserva, mesa, boton) {
        if (!mesa) return;
        requestOpenTicket({
          mesa_ids: [mesa.id],
          comensales: parseInt(reserva.comensales || '2', 10) || 2,
          nombre: null,
          mesero_id: selectedMeseroId()
        }, { button: boton });
      },
      abrirTicket: function(reserva) {
        var mesaId = V.ids(reserva)[0];
        var mesa = mesaId ? mesaPorId(mesaId) : null;
        if (!mesa) return;
        if (ficha) ficha.cerrar();
        showModal(mesa, 'con-ticket');
      },
      montarMesero: function(slot) {
        mountMeseroSelect(slot);
      }
    };
  }

  /*
   * Toque en una mesa en proyección: las azules abren su ficha; el resto sólo
   * recuerda cómo volver. Un segundo toque antes de 4 s no apila otro aviso.
   */
  function tocarMesaEnProyeccion(mesa) {
    var backend = mesaEstadoPorId(parseInt(mesa.id, 10)) || {};
    if (backend.estado_visual_pos === 'reservacion-proxima') {
      var id = backend.reservacion_mapa_id || backend.reservacion_id
        || (backend.hold && backend.hold.reservacion_id);
      var reserva = id ? reservaDiaPorId(id) : null;
      if (reserva) {
        abrirFicha(reserva, { mesa: mesa });
        return;
      }
    }
    if (Date.now() > avisoSoloLecturaHasta) {
      avisoSoloLectura();
      avisoSoloLecturaHasta = Date.now() + 4000;
    }
    if (contextoBarra) contextoBarra.llamarAhora();
  }

  // ── Cambiar mesas de una reservación (desde la ficha) ────────────────
  //
  // El mapa se proyecta a la hora de la reserva (es el intervalo que importa)
  // y se eligen mesas tocándolas. La barra flotante del modo selección se
  // reutiliza con otro texto. Guardar viaja con la versión y las mesas que se
  // tenían en pantalla: si otra tablet la movió entretanto, el servidor lo
  // rechaza en vez de pisar el cambio. Elegir el exterior completo con reservas
  // dentro las reubica, después de enseñar a quién y pedir confirmación.
  var modoAsignacion = null;

  function mesaElegibleAsignacion(mesa) {
    if (!mesa || String(mesa.tipo || 'mesa') !== 'mesa' || !mesaReservable(mesa)) return false;
    var backend = mesaEstadoPorId(parseInt(mesa.id, 10)) || {};
    // Un ticket que sigue ahí a esa hora no se mueve: la gente está sentada.
    return !(backend.ticket_bloquea_consulta === true && backend.asignada_actualmente !== true);
  }

  /*
   * Alta de un grupo mayor al máximo: las mesas se eligen ANTES de que exista
   * la reservación. El modo es el mismo que «Cambiar mesas», sobre un
   * borrador: al guardar se crea con esas mesas en una sola petición, y si se
   * descarta no queda nada.
   */
  // Mesas que el anfitrión ya había elegido cuando el alta volvió al diálogo
  // por un dato del formulario: al regresar al plano no se pierden.
  var altaSeleccionPrevia = null;

  function iniciarAltaConMesas(datos, sugeridas) {
    if (!datos) return;
    var previa = altaSeleccionPrevia;
    altaSeleccionPrevia = null;
    if (previa && previa.fecha === datos.fecha && previa.hora === datos.hora) sugeridas = previa.ids;
    if (ticketSelectionMode) cancelarModoSeleccion();
    if (ficha && ficha.abierta()) ficha.cerrar();
    modoAsignacion = {
      borrador: datos,
      // Si se empezó en vivo, descartar devuelve a Ahora y no deja al
      // mesero mirando la hora de un grupo que no existe.
      volverAAhora: contextoMapa.modo !== 'proyeccion',
      reserva: { id: 0, nombre: datos.nombre, comensales: datos.comensales, hora: datos.hora, fecha: datos.fecha, mesa_ids: [] },
      // El mapa abre con la propuesta del servidor ya marcada: se ajusta en
      // vez de empezar a tocar mesas en blanco.
      seleccion: (sugeridas || []).map(function(id) { return parseInt(id, 10); }).filter(function(id) { return id > 0; }),
      guardando: false
    };
    pintarModoAsignacion();
    transicionPendiente = 'pos-proyeccion';
    proyectarMapa({ hora: V.hhmm(datos.hora), intencion: 'asignar' })
      .then(function() {
        // Entre la consulta y el mapa proyectado alguien pudo ocupar una de
        // las propuestas: sólo sobrevive lo que el plano deja elegir.
        if (modoAsignacion && modoAsignacion.borrador === datos) {
          modoAsignacion.seleccion = modoAsignacion.seleccion.filter(function(id) {
            var mesa = mesaPorId(id);
            return mesa && mesaElegibleAsignacion(mesa);
          });
          pintarModoAsignacion();
          renderEstados();
        }
        if (selectionMessage) selectionMessage.focus();
      });
  }

  function guardarAltaConMesas(confirmaciones) {
    var borrador = modoAsignacion.borrador;
    var ids = modoAsignacion.seleccion.slice();
    modoAsignacion.guardando = true;
    pintarModoAsignacion();
    return postJson('/api/punto-de-venta/reservaciones/crear', Object.assign({}, borrador, {
      mesa_ids: ids,
      confirmaciones: confirmaciones || []
    })).then(function(result) {
      if (!modoAsignacion) return;
      modoAsignacion.guardando = false;
      if (result && result.ok === true && result.commit === true) {
        var id = parseInt(result.reservacion_id || result.reservationId || result.id, 10);
        aviso('Reservación creada: ' + borrador.nombre + ', ' + V.hhmm(borrador.hora)
          + ' en ' + V.nombresMesas(ids, mesaPorId) + '.', 'success');
        salirModoAsignacion();
        if (id) fichaPendiente = { id: id, opciones: {} };
        transicionPendiente = 'pos-ficha';
        consultaPos.intencion = '';
        fetchData(fechaMapa(), true);
        return;
      }
      var decision = result && Array.isArray(result.confirmaciones_requeridas) ? result.confirmaciones_requeridas[0] : null;
      if (decision && window.ConfirmationModal) {
        pintarModoAsignacion();
        var principal = (decision.acciones || []).find(function(a) { return a.tipo === 'primary'; });
        window.ConfirmationModal.get().open({
          variant: 'warning',
          eyebrow: 'Nueva reservación',
          title: decision.titulo || decision.mensaje || 'Confirma el alta',
          description: decision.mensaje || '',
          consequence: decision.consecuencia || '',
          secondaryLabel: 'Volver a elegir',
          primaryLabel: principal && principal.label ? principal.label : 'Crear de todas formas',
          onPrimary: function() {
            return guardarAltaConMesas((confirmaciones || []).concat([decision.codigo_canonico || decision.codigo]));
          }
        });
        return;
      }
      // Un dato del formulario (el contacto, el nombre) no se arregla en el
      // plano: se vuelve al diálogo con el campo señalado y las mesas ya
      // elegidas se guardan para cuando regrese al mapa.
      if (alta && alta.retomar && result && result.codigo === 'DATOS_INVALIDOS') {
        altaSeleccionPrevia = { fecha: borrador.fecha, hora: borrador.hora, ids: ids.slice() };
        salirModoAsignacion();
        renderEstados();
        if (alta.retomar(result)) return;
        altaSeleccionPrevia = null;
      }
      pintarModoAsignacion();
      aviso((result && result.mensaje) || 'No se pudo crear la reservación con esas mesas.');
    }, function() {
      if (modoAsignacion) {
        modoAsignacion.guardando = false;
        pintarModoAsignacion();
      }
      avisoConexion();
    });
  }

  function iniciarCambioMesas(reserva) {
    if (!reserva) return;
    if (ticketSelectionMode) cancelarModoSeleccion();
    modoAsignacion = { reserva: reserva, seleccion: V.ids(reserva).slice(), guardando: false };
    if (ficha && ficha.abierta()) ficha.cerrar();
    pintarModoAsignacion();
    transicionPendiente = 'pos-proyeccion';
    proyectarMapa({ hora: V.hhmm(reserva.hora), reservacionId: reserva.id, intencion: 'asignar' })
      .then(function() {
        if (selectionMessage) selectionMessage.focus();
      });
  }

  function capacidadSeleccion(ids) {
    return ids.reduce(function(acc, id) {
      var mesa = mesaPorId(id);
      return acc + (mesa ? parseInt(mesa.capacidad, 10) || 0 : 0);
    }, 0);
  }

  function pintarModoAsignacion() {
    if (!modoAsignacion) return;
    var reserva = modoAsignacion.reserva;
    var ids = modoAsignacion.seleccion;
    if (selectionShell) selectionShell.classList.add('is-ticket-selection-mode');
    if (selectionBar) selectionBar.hidden = false;
    // Pregunta pendiente: la barra la hace en su sitio y esconde guardar y
    // descartar hasta que se conteste.
    var pendiente = modoAsignacion.pendiente;
    if (asignacionPregunta) asignacionPregunta.hidden = !pendiente;
    if (selectionActions) selectionActions.hidden = !!pendiente;
    if (pendiente && selectionMessage) {
      var nuevaMesa = mesaPorId(pendiente);
      // La pregunta va en el orden de sus respuestas —«además» a la izquierda,
      // «en su lugar» a la derecha, como «Añadir» y «Cambiar»—: leída de
      // corrido, cada mitad de la frase cae encima de su botón.
      selectionMessage.textContent = (nuevaMesa ? nuevaMesa.nombre : 'Esta mesa') + ': ¿además de '
        + V.nombresMesas(ids, mesaPorId) + ', o en su lugar?';
      return;
    }
    if (selectionMessage) {
      var comensales = parseInt(reserva.comensales, 10) || 0;
      var lugares = capacidadSeleccion(ids);
      // «14 personas … (8 lugares)» dejaba la resta al anfitrión, y con el
      // botón de guardar ya encendido. El faltante se dice en palabras; el
      // servidor sigue pidiendo confirmación si se guarda así.
      //
      // Dos renglones: quién arriba y qué mesas debajo. En una sola frase, un
      // grupo grande dejaba nombre, mesas y cuenta de lugares pegados en una
      // línea que había que leer entera para saber si ya alcanzaba.
      var quien = V.esc(reserva.nombre || 'Reservación') + ' · ' + V.esc(V.plural(comensales, 'persona', 'personas'))
        + (modoAsignacion.borrador ? ' · ' + V.esc(V.hhmm(reserva.hora)) : '');
      selectionMessage.innerHTML = ids.length
        ? '<span class="pos-ticket-selection-message__quien">' + quien + '</span>'
          + '<span class="pos-ticket-selection-message__mesas">' + V.esc(V.nombresMesas(ids, mesaPorId))
          + ' · <span class="pos-ticket-selection-message__cupo' + (lugares < comensales ? ' is-falta' : '') + '">'
          + V.esc(V.plural(lugares, 'lugar', 'lugares'))
          + (lugares < comensales ? ', faltan ' + (comensales - lugares) : '') + '</span></span>'
        : '<span class="pos-ticket-selection-message__quien">' + quien + '</span>'
          + '<span class="pos-ticket-selection-message__mesas">Toca las mesas en el plano.</span>';
    }
    if (selectionToggle) {
      selectionToggle.textContent = modoAsignacion.guardando
        ? 'Guardando…'
        : (modoAsignacion.borrador ? 'Crear reservación' : 'Guardar mesas');
      var bloqueado = modoAsignacion.guardando || !ids.length;
      selectionToggle.disabled = bloqueado;
      selectionToggle.setAttribute('aria-disabled', bloqueado ? 'true' : 'false');
      if (modoAsignacion.guardando) selectionToggle.setAttribute('aria-busy', 'true');
      else selectionToggle.removeAttribute('aria-busy');
    }
    if (selectionCancel) {
      selectionCancel.disabled = modoAsignacion.guardando;
      selectionCancel.textContent = 'Descartar';
    }
  }

  /*
   * Tocar una mesa en «Cambiar mesas»:
   * - una ya elegida se quita;
   * - si a la reserva todavía le faltan lugares, la nueva se suma (no hay
   *   nada que cambiar: necesita más);
   * - si ya le alcanza, se pregunta en la barra si la nueva SUSTITUYE a las
   *   elegidas o se añade. Antes siempre se añadía, y para mover una pareja
   *   de la Mesa 1 a la 11 había que acordarse de quitar la 1.
   */
  function alternarMesaAsignacion(mesa) {
    if (!modoAsignacion || modoAsignacion.guardando || !mesaElegibleAsignacion(mesa)) return;
    var id = parseInt(mesa.id, 10);
    var sel = modoAsignacion.seleccion;
    if (modoAsignacion.pendiente === id) {
      modoAsignacion.pendiente = 0;
    } else if (sel.indexOf(id) !== -1) {
      sel.splice(sel.indexOf(id), 1);
      modoAsignacion.pendiente = 0;
    } else if (sel.length && capacidadSeleccion(sel) >= (parseInt(modoAsignacion.reserva.comensales, 10) || 0)) {
      modoAsignacion.pendiente = id;
    } else {
      sel.push(id);
      modoAsignacion.pendiente = 0;
    }
    sel.sort(function(a, b) { return a - b; });
    pintarModoAsignacion();
    renderEstados();
    if (modoAsignacion.pendiente && asignacionPregunta) {
      var cambiar = asignacionPregunta.querySelector('[data-asignacion-eleccion="cambiar"]');
      if (cambiar) cambiar.focus();
    }
  }

  function resolverPreguntaAsignacion(eleccion) {
    if (!modoAsignacion || !modoAsignacion.pendiente) return;
    var id = modoAsignacion.pendiente;
    modoAsignacion.pendiente = 0;
    if (eleccion === 'cambiar') modoAsignacion.seleccion = [id];
    else if (eleccion === 'anadir') modoAsignacion.seleccion.push(id);
    modoAsignacion.seleccion.sort(function(a, b) { return a - b; });
    pintarModoAsignacion();
    renderEstados();
    if (selectionToggle && !selectionToggle.disabled) selectionToggle.focus();
  }

  function salirModoAsignacion() {
    modoAsignacion = null;
    if (selectionShell) selectionShell.classList.remove('is-ticket-selection-mode');
    if (selectionBar) selectionBar.hidden = true;
    if (asignacionPregunta) asignacionPregunta.hidden = true;
    if (selectionActions) selectionActions.hidden = false;
    if (selectionCancel) selectionCancel.textContent = 'Cancelar';
    renderEstados();
  }

  function cancelarCambioMesas() {
    if (!modoAsignacion) return;
    // Un borrador descartado no deja nada: la reservación nunca existió.
    if (modoAsignacion.borrador) {
      var regresar = modoAsignacion.volverAAhora;
      salirModoAsignacion();
      aviso('No se creó la reservación.', 'info');
      if (regresar) irAAhora();
      return;
    }
    var reserva = modoAsignacion.reserva;
    salirModoAsignacion();
    abrirFicha(reservaDiaPorId(reserva.id) || reserva, {});
  }

  function listaReubicaciones(reubicaciones) {
    var nodo = document.createElement('ul');
    nodo.className = 'pos-reubicaciones';
    (reubicaciones || []).forEach(function(r) {
      var reserva = reservaDiaPorId(r.reservacion_id);
      var item = document.createElement('li');
      item.textContent = (reserva ? reserva.nombre + ' · ' : 'Reservación #' + r.reservacion_id + ' · ')
        + V.hhmm(r.hora || (reserva && reserva.hora)) + ': de '
        + V.nombresMesas(r.mesas_antes || [], mesaPorId) + ' a ' + V.nombresMesas(r.mesas_despues || [], mesaPorId);
      nodo.appendChild(item);
    });
    return nodo;
  }

  function guardarCambioMesas(confirmaciones, extra) {
    if (!modoAsignacion || modoAsignacion.guardando) return Promise.resolve();
    if (modoAsignacion.borrador) return guardarAltaConMesas(confirmaciones);
    var reserva = modoAsignacion.reserva;
    var ids = modoAsignacion.seleccion.slice();
    modoAsignacion.guardando = true;
    pintarModoAsignacion();
    return postJson('/api/punto-de-venta/reservaciones/asignar-mesas', Object.assign({
      reservacion_id: reserva.id,
      mesa_ids: ids,
      fecha: reserva.fecha,
      hora: reserva.hora,
      version_esperada: reserva.version,
      mesa_ids_actuales: V.ids(reserva),
      confirmaciones: confirmaciones || []
    }, extra || {})).then(function(result) {
      if (!modoAsignacion) return;
      modoAsignacion.guardando = false;
      if (result && result.ok === true) {
        var movidas = Array.isArray(result.reubicaciones) ? result.reubicaciones.length : 0;
        aviso('Mesas guardadas: ' + V.nombresMesas(ids, mesaPorId) + '.'
          + (movidas ? ' Se ' + (movidas === 1 ? 'movió 1 reservación' : 'movieron ' + movidas + ' reservaciones') + ' al interior.' : ''), 'success');
        salirModoAsignacion();
        fichaPendiente = { id: parseInt(reserva.id, 10), opciones: {} };
        transicionPendiente = 'pos-ficha';
        consultaPos.intencion = '';
        fetchData(fechaMapa(), true);
        return;
      }
      var decision = result && Array.isArray(result.confirmaciones_requeridas) ? result.confirmaciones_requeridas[0] : null;
      if (result && result.requiere_confirmacion && decision && window.ConfirmationModal) {
        pintarModoAsignacion();
        var principal = (decision.acciones || []).find(function(a) { return a.tipo === 'primary'; });
        window.ConfirmationModal.get().open({
          variant: 'warning',
          eyebrow: 'Cambiar mesas',
          title: decision.titulo || decision.mensaje || 'Confirma el cambio',
          description: decision.mensaje || '',
          consequence: decision.consecuencia || '',
          customContent: result.reubicaciones && result.reubicaciones.length ? listaReubicaciones(result.reubicaciones) : null,
          secondaryLabel: 'Volver a seleccionar',
          primaryLabel: principal && principal.label ? principal.label : 'Confirmar',
          onPrimary: function() {
            var codigo = decision.codigo_canonico || decision.codigo || result.codigo;
            return guardarCambioMesas((confirmaciones || []).concat([codigo]), {
              ticket_ids_aceptados: (result.conflictos_ticket || []).map(function(c) { return c.ticket_id; }),
              conflicto_token: result.conflicto_token || ''
            });
          }
        });
        return;
      }
      if (result && result.codigo === 'VERSION_DESACTUALIZADA') {
        aviso(result.mensaje || 'Otra tablet cambió esta reservación. Revisa y vuelve a intentarlo.', 'warning');
        salirModoAsignacion();
        silentRefresh();
        return;
      }
      pintarModoAsignacion();
      aviso((result && result.mensaje) || 'No se pudieron guardar las mesas.');
    }, function() {
      if (modoAsignacion) {
        modoAsignacion.guardando = false;
        pintarModoAsignacion();
      }
      avisoConexion();
    });
  }

  function onMesaClick(mesaId) {
    var mesa = mesaPorId(mesaId);
    if (!mesa || enTransicion()) return;
    if (modoAsignacion) {
      alternarMesaAsignacion(mesa);
      return;
    }
    if (ticketSelectionState.opening) return;
    // En proyección el mapa enseña cómo ESTARÁ el salón: no se abren tickets
    // sobre una hora que todavía no llega. La Caja es del día, no de la hora.
    if (enProyeccion() && !esCaja(mesa)) {
      tocarMesaEnProyeccion(mesa);
      return;
    }
    if (mapSnapshotStale && !esCaja(mesa) && !isLlevar(mesa)) return;
    if (ticketSelectionMode) {
      mesaSeleccionadaToggle(mesaId);
      return;
    }
    if (esCaja(mesa)) {
      showCajaModal(mesa);
      return;
    }
    // Llevar y las barras no miran ticket ni reservación: siempre abren su
    // tablero, con o sin tickets abiertos. La barra llena también: desde ahí
    // se cobra el que libera el lugar.
    if (esMultiTicket(mesa)) {
      showMultiTicketModal(mesa);
      return;
    }
    var canOpen = mesaTicketable(mesa) || esCaja(mesa);
    if (!canOpen) return;
    var ticket = ticketActual(mesaId);
    if (ticket) {
      showModal(mesa, 'con-ticket');
      return;
    }
    var reserva = reservaParaModal(mesaId);
    var backend = mesaEstadoPorId(mesaId) || {};
    var walkInConAdvertencia = reserva
      && backend.disponible_para_ticket === true
      && backend.requiere_advertencia_ticket === true;
    // La ficha sustituye al modal de reservación: tocar una mesa azul abre la
    // ficha de su reserva. Con la reserva a 60–30 min y la mesa libre, la
    // ficha ofrece además «Abrir ticket de todas formas».
    if (walkInConAdvertencia) {
      abrirFicha(reservaDiaPorId(reserva.id) || reserva, { allowWalkIn: true, mesa: mesa });
      return;
    }
    if (reserva && backend.disponible_para_ticket !== true) {
      abrirFicha(reservaDiaPorId(reserva.id) || reserva, { mesa: mesa });
      return;
    }
    // Apartada por un hold: no está en `reservaciones` (sólo confirmadas).
    if (!reserva && backend.estado_visual_pos === 'reservacion-proxima' && backend.hold) {
      var apartada = reservaDiaPorId(backend.hold.reservacion_id);
      if (apartada) {
        abrirFicha(apartada, { mesa: mesa });
        return;
      }
    }
    // Una mesa sin reserva abre su modal; la ficha que hubiera se cierra.
    if (ficha && ficha.abierta()) ficha.cerrar();
    showModal(mesa, estadoMesaActual(mesaId));
  }

  // ── Selección multimesa para apertura de ticket ───────────
  function actualizarMensajeSeleccion() {
    if (!selectionMessage) return;
    if (!ticketSelectionMode) return;
    selectionMessage.textContent = selectedMesaIds.length
      ? selectedMesaIds.length + (selectedMesaIds.length === 1
        ? ' mesa seleccionada. Confirma para abrir el ticket.'
        : ' mesas seleccionadas. Confirma para abrir el ticket.')
      : 'Selecciona una o más mesas para abrir el ticket.';
  }

  function actualizarControlesApertura() {
    // Cambiando mesas de una reserva, la barra es suya.
    if (modoAsignacion) {
      pintarModoAsignacion();
      return;
    }
    // La barra entera solo existe dentro del modo selección: fuera de él, el
    // punto de entrada es "Unir mesas" en el modal de la mesa.
    if (selectionBar) selectionBar.hidden = !ticketSelectionMode;
    if (selectionToggle) {
      var validas = selectedMesaIds.filter(function(mesaId) {
        var mesa = mesaPorId(mesaId);
        return mesa && mesaPuedeSeleccionarse(mesa, estadoMesaActual(mesaId));
      }).length;
      selectionToggle.textContent = ticketSelectionState.opening
        ? 'Abriendo ticket…'
        : 'Confirmar apertura' + (validas ? ' (' + validas + (validas === 1 ? ' mesa)' : ' mesas)') : '');
      if (ticketSelectionState.opening) {
        selectionToggle.setAttribute('aria-busy', 'true');
      } else {
        selectionToggle.removeAttribute('aria-busy');
      }
      var disabled = ticketSelectionState.opening || validas === 0;
      selectionToggle.disabled = disabled;
      selectionToggle.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    }
    if (selectionCancel) {
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
    if (ticketSelectionState.opening || ticketSelectionMode) return;
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
    openModalShell();

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
    h += '<span class="mmodal-col-empty__icon">' + svgIcon('alert', 26) + '</span>';
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
    // La fecha en cristiano («Lunes 19 de octubre»), no el ISO del contrato:
    // es la regla del cajón (CLAUDE.md) y la tabla ya vive en PosReservaVista,
    // que viaja antes que este archivo en map.js.
    var fechaCaja = data.fecha && window.PosReservaVista && typeof window.PosReservaVista.fechaLarga === 'function'
      ? window.PosReservaVista.fechaLarga(data.fecha)
      : (data.fecha || '');
    h += '<span class="mmodal-title-cliente">— ' + escHtml(fechaCaja) + '</span>';
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

    /*
     * Ranking de los cinco más pedidos.
     *
     * Era una lista suelta de seis filas donde la cantidad iba delante como un
     * dato más. Un ranking necesita dos cosas que ahí faltaban: la POSICIÓN, que
     * es lo que convierte una lista en un orden, y la proporción entre el
     * primero y el resto — sin ella "12×" y "9×" se leen como iguales.
     *
     * La barra se calcula contra el primer puesto, no contra el total del día:
     * la pregunta del corte es "qué se pidió más", no "qué porcentaje del menú
     * representa cada plato".
     */
    if (data.top && data.top.length) {
      var maxUnidades = 0;
      for (var m = 0; m < data.top.length; m++) {
        var u = Number(data.top[m].unidades) || 0;
        if (u > maxUnidades) maxUnidades = u;
      }

      h += '<div class="mmodal-caja-section">';
      h += '<p class="mmodal-section-label">Más pedidos del día</p>';
      h += '<div class="mmodal-caja-list mmodal-caja-list--ranking">';
      for (var i = 0; i < data.top.length; i++) {
        var t = data.top[i];
        var unidades = Number(t.unidades) || 0;
        var pct = maxUnidades > 0 ? Math.round((unidades / maxUnidades) * 100) : 0;
        h += '<div class="mmodal-caja-rank">';
        h += '<span class="mmodal-caja-rank__pos">' + (i + 1) + '</span>';
        h += '<div class="mmodal-caja-rank__body">';
        h += '<div class="mmodal-caja-rank__top">';
        h += '<span class="mmodal-caja-rank__name">' + escHtml(t.nombre || '') + '</span>';
        h += '<span class="mmodal-caja-rank__qty">' + unidades + '</span>';
        h += '</div>';
        h += '<div class="mmodal-caja-rank__bar"><span style="width:' + pct + '%"></span></div>';
        h += '<span class="mmodal-caja-rank__val">' + money(t.importe) + '</span>';
        h += '</div>';
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
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' + svgIcon('idle', 26) + '</span><span>Aún no hay ventas cerradas hoy.</span></div>';
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

  // ── Modal de ventanilla: Llevar y barras ──────────────────
  //
  // Tres pantallas dentro del mismo modal, sin cerrarlo nunca entre ellas:
  // el TABLERO (todos los tickets abiertos), el ALTA de uno nuevo y el TICKET
  // de uno concreto —el mismo que el de una mesa—, que lleva encima una tira
  // para saltar a otro o volver al tablero. Así el mesero lleva varios tickets
  // en paralelo como lleva varias mesas, sin pasar por el mapa.
  //
  // Nació para Llevar y lo heredaron las barras; las clases `mmodal-llevar-*`
  // conservan ese nombre porque son el componente, no el elemento.
  function showMultiTicketModal(mesa) {
    if (!modal || !modalContent) return;
    if (ticketsMulti(mesa).length === 0) {
      mostrarAltaMulti(mesa, false);
    } else {
      mostrarTableroMulti(mesa);
    }
    openModalShell();
    // Igual que showModal(): detrás del modal no se ve el mapa.
    stopPolling();
  }

  function limpiarPedidoEnCurso() {
    commandaItems    = [];
    selectedComensal = 0;
    sugTimerStop();
    sugTicket = null;
    sugPedidas = false;
  }

  function mostrarTableroMulti(mesa) {
    limpiarPedidoEnCurso();
    modalContent.innerHTML = buildMultiList(mesa, ticketsMulti(mesa));
    bindMultiList(mesa);
    enfocarPrimero();
  }

  function mostrarAltaMulti(mesa, conRegreso) {
    limpiarPedidoEnCurso();
    modalContent.innerHTML = buildModalContent(mesa, 'libre', null, null, {
      multiRegreso: conRegreso === true
    });
    bindModalActions(mesa, null, null);
    enfocarPrimero();
  }

  function mostrarTicketMulti(mesa, ticket) {
    limpiarPedidoEnCurso();
    modalContent.innerHTML = buildModalContent(mesa, 'con-ticket', null, ticket);
    bindModalActions(mesa, null, ticket);
    enfocarPrimero();
  }

  // Al reescribir el contenido, el foco se queda en un nodo que ya no existe.
  function enfocarPrimero() {
    modal.querySelectorAll('button:not([type])').forEach(function(button) {
      button.type = 'button';
    });
    window.requestAnimationFrame(function() {
      var items = modalFocusables();
      if (items.length) items[0].focus();
    });
  }

  // Saltar de ticket descarta lo que haya en el Pedido sin enviar: se avisa
  // antes, como al cancelar una mesa.
  function conPedidoSinEnviar(mesa, continuar) {
    if (!commandaItems.length || !window.ConfirmationModal) {
      continuar();
      return;
    }
    var n = commandaItems.reduce(function(acc, item) { return acc + (item.qty || 1); }, 0);
    window.ConfirmationModal.get().open({
      variant: 'warning',
      eyebrow: 'Pedido sin enviar',
      title: '¿Salir de este ' + vocabMulti(mesa).singular + '?',
      description: 'Tienes ' + n + (n === 1 ? ' platillo' : ' platillos') +
        ' en el Pedido que todavía no se envía a producción.',
      consequence: 'Si sales ahora, se pierden. Lo ya enviado no cambia.',
      secondaryLabel: 'Seguir aquí',
      primaryLabel: 'Salir sin enviar',
      onPrimary: continuar
    });
  }

  function buildMultiList(mesa, pedidos) {
    var v = vocabMulti(mesa);
    var lleno = multiLleno(mesa);
    var h = '';
    h += '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(v.titulo) + '</span>';
    // Llena, la cifra toma el rojo del pin que el mesero acaba de tocar: el
    // tablero continúa lo que dijo el mapa en vez de contarlo en neutro.
    h += '<span class="mmodal-llevar-count' + (lleno ? ' mmodal-llevar-count--lleno' : '') + '">' +
         escHtml(rotuloTicketsMulti(mesa, pedidos.length)) + '</span>';
    h += '</div></div>';

    h += '<ul class="mmodal-llevar-list" aria-label="' + escHtml(v.tablero + ' abiertos') + '">';
    for (var i = 0; i < pedidos.length; i++) {
      var t = pedidos[i];
      var horaAp = t.hora_apertura ? String(t.hora_apertura).substring(11, 16) : '--:--';
      var antiguedad = antiguedadPedido(t);
      h += '<li><button type="button" class="mmodal-llevar-row" data-tid="' + t.id + '">';
      h += '<span class="mmodal-llevar-row__folio">' + escHtml(folioPedido(t)) + '</span>';
      h += '<span class="mmodal-llevar-row__info">';
      h += '<span class="mmodal-llevar-row__nombre' + (t.nombre ? '' : ' is-anonimo') + '">' +
           escHtml(t.nombre || 'Sin nombre') + '</span>';
      h += '<span class="mmodal-llevar-row__meta">' + svgIcon('clock', 13) +
           '<span>' + escHtml(horaAp) + (antiguedad ? ' · ' + escHtml(antiguedad) : '') + '</span>' +
           '<span class="mmodal-llevar-row__sep" aria-hidden="true">·</span>' +
           svgIcon('users', 13) + '<span>' + t.comensales + '</span></span>';
      h += '</span>';
      h += '<span class="mmodal-llevar-row__arrow" aria-hidden="true">' + svgIcon('right', 15) + '</span>';
      h += '</button></li>';
    }
    h += '</ul>';

    /*
     * Barra llena: el alta se APAGA, no desaparece. Si se fuera, el mesero no
     * sabría si la barra admite otro ticket o si el botón nunca estuvo; con la
     * nota debajo sabe qué falta —que se cobre o descarte uno— sin probar.
     * El servidor revalida igual (BARRA_LLENA): esto es la señal en pantalla.
     */
    h += '<div class="mmodal-actions">';
    if (lleno) {
      h += '<button class="mmodal-btn mmodal-btn--pending" id="mmodal-llevar-nuevo" disabled' +
           ' aria-describedby="mmodal-llevar-lleno">' + btnLabel('plus', v.nuevo) + '</button>';
    } else {
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-llevar-nuevo">' +
           btnLabel('plus', v.nuevo) + '</button>';
    }
    h += '</div>';
    if (lleno) {
      // Sin el nombre del elemento delante: «Mostrador está llena» saldría
      // con el género equivocado, y el tope ya dice cuál es el límite.
      h += '<p class="mmodal-field-hint mmodal-field-hint--bloqueo" id="mmodal-llevar-lleno">Tope de ' + topeTickets(mesa) +
           ' ' + v.plural + ' alcanzado: cobra o descarta uno para abrir otro.</p>';
    }
    return h;
  }

  function bindMultiList(mesa) {
    var rows = modalContent.querySelectorAll('.mmodal-llevar-row[data-tid]');
    for (var i = 0; i < rows.length; i++) {
      (function(row) {
        row.addEventListener('click', function() {
          var ticket = ticketMultiPorId(mesa, parseInt(row.dataset.tid, 10));
          if (ticket) mostrarTicketMulti(mesa, ticket);
        });
      })(rows[i]);
    }

    var nuevoBtn = modalContent.querySelector('#mmodal-llevar-nuevo');
    if (nuevoBtn && !nuevoBtn.disabled) {
      nuevoBtn.addEventListener('click', function() {
        mostrarAltaMulti(mesa, true);
      });
    }
  }

  function ticketMultiPorId(mesa, ticketId) {
    var pedidos = ticketsMulti(mesa);
    for (var i = 0; i < pedidos.length; i++) {
      if (Number(pedidos[i].id) === Number(ticketId)) return pedidos[i];
    }
    return null;
  }

  /*
   * Tira de tickets dentro del ticket: el tablero, un chip por ticket abierto
   * y el alta. El actual siempre figura, aunque el refresco que lo trae a
   * `tickets` aún no haya llegado (es el caso del ticket recién abierto), y por
   * eso el tope se mide contra esta lista y no contra `tickets`.
   */
  function buildMultiSwitch(mesa, actual) {
    var v = vocabMulti(mesa);
    var pedidos = ticketsMulti(mesa).slice();
    if (actual && !ticketMultiPorId(mesa, actual.id)) pedidos.push(actual);
    var tope = topeTickets(mesa);
    var lleno = tope !== null && pedidos.length >= tope;

    var h = '<nav class="mmodal-llevar-switch" data-singular="' + escHtml(v.singular) + '"' +
            ' aria-label="' + escHtml(v.tablero + ' de ' + v.titulo) + '">';
    h += '<button type="button" class="mmodal-llevar-switch__todos" data-llevar-tablero>' +
         svgIcon('list', 15) + '<span>' + escHtml(v.tablero) + '</span>' +
         '<span class="mmodal-llevar-switch__total">' + pedidos.length + '</span></button>';
    h += '<div class="mmodal-llevar-switch__track" data-lenis-prevent>';
    for (var i = 0; i < pedidos.length; i++) {
      var t = pedidos[i];
      var activo = actual && Number(t.id) === Number(actual.id);
      h += '<button type="button" class="mmodal-llevar-chip' + (activo ? ' is-active' : '') + '"' +
           ' data-llevar-tid="' + t.id + '"' + (activo ? ' aria-current="true"' : '') + '>';
      h += '<span class="mmodal-llevar-chip__folio">' + escHtml(folioPedido(t)) + '</span>';
      if (t.nombre) h += '<span class="mmodal-llevar-chip__nombre">' + escHtml(t.nombre) + '</span>';
      h += '</button>';
    }
    h += '</div>';
    // Lleno, el chip se apaga con aria-disabled y NO con `disabled`: un botón
    // deshabilitado no recibe el toque, y en la tablet el title no se ve nunca,
    // así que el mesero tocaba y no pasaba nada. Así sigue a la vista, apagado,
    // y al tocarlo explica el motivo (bindMultiSwitch).
    var motivoLleno = 'Tope de ' + tope + ' ' + v.plural + ' alcanzado: cobra o descarta uno para abrir otro.';
    h += '<button type="button" class="mmodal-llevar-chip mmodal-llevar-chip--nuevo" data-llevar-nuevo' +
         (lleno ? ' aria-disabled="true" title="' + escHtml(motivoLleno) + '" data-motivo="' + escHtml(motivoLleno) + '"' : '') + '>' +
         svgIcon('plus', 14) + '<span>Nuevo</span></button>';
    h += '</nav>';
    return h;
  }

  function bindMultiSwitch(mesa, actual) {
    var nav = modalContent.querySelector('.mmodal-llevar-switch');
    if (!nav) return;
    var activo = nav.querySelector('.mmodal-llevar-chip.is-active');
    if (activo && activo.scrollIntoView) activo.scrollIntoView({ block: 'nearest', inline: 'nearest' });

    nav.addEventListener('click', function(event) {
      var btn = event.target.closest('button');
      if (!btn || !nav.contains(btn) || btn.disabled) return;
      if (btn.getAttribute('aria-disabled') === 'true') {
        aviso(btn.getAttribute('data-motivo') || 'Ya no se puede abrir otro.', 'warning');
        return;
      }
      if (btn.hasAttribute('data-llevar-tablero')) {
        conPedidoSinEnviar(mesa, function() { volverATableroMulti(mesa); });
        return;
      }
      if (btn.hasAttribute('data-llevar-nuevo')) {
        conPedidoSinEnviar(mesa, function() { mostrarAltaMulti(mesa, true); });
        return;
      }
      var tid = parseInt(btn.getAttribute('data-llevar-tid') || '0', 10);
      if (!tid || (actual && Number(actual.id) === tid)) return;
      var destino = ticketMultiPorId(mesa, tid);
      if (destino) conPedidoSinEnviar(mesa, function() { mostrarTicketMulti(mesa, destino); });
    });
  }

  /*
   * Vuelta al tablero con datos frescos: otra tablet pudo abrir o cobrar un
   * ticket mientras éste estaba en pantalla, y con el modal abierto no hay
   * sondeo. Se pinta con lo que hay y se repinta al llegar el refresco, sólo
   * si el mesero sigue en el tablero.
   *
   * `cerrarSiVacio` es la salida tras cobrar o descartar: si no queda ningún
   * ticket, el modal se cierra en vez de ofrecer un alta que nadie pidió.
   */
  function volverATableroMulti(mesa, opciones) {
    opciones = opciones || {};
    var pintar = function() {
      if (ticketsMulti(mesa).length) {
        mostrarTableroMulti(mesa);
      } else if (opciones.cerrarSiVacio) {
        closeModal({ refresh: false });
      } else {
        mostrarAltaMulti(mesa, false);
      }
    };
    if (!opciones.cerrarSiVacio) pintar();
    var refresco = fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), true);
    if (!refresco || typeof refresco.then !== 'function') {
      if (opciones.cerrarSiVacio) pintar();
      return;
    }
    refresco.then(function(resultado) {
      if (resultado && (resultado.stale || resultado.refreshFailed)) {
        if (opciones.cerrarSiVacio) pintar();
        return;
      }
      var enTablero = modalContent.querySelector('.mmodal-llevar-list');
      if (opciones.cerrarSiVacio || enTablero) pintar();
    }, function() {
      if (opciones.cerrarSiVacio) pintar();
    });
  }

  function showModal(mesa, estado, reservaOverride, ticketOverride) {
    if (!modal || !modalContent) return;
    commandaItems    = [];
    selectedComensal = 0;
    var reserva = reservaOverride === undefined
      ? reservaParaModal(mesa.id)
      : reservaOverride;
    var ticket  = ticketOverride || ticketActual(mesa.id);
    modalContent.innerHTML = buildModalContent(mesa, estado, reserva, ticket, null);
    openModalShell();
    // Detrás del modal no se ve el mapa, y el sondeo de 30 s compite por el
    // único hilo del servidor de desarrollo. Se reanuda al cerrar.
    stopPolling();
    bindModalActions(mesa, reserva, ticket, null);
  }

  function closeModal(options) {
    if (!modal) return;
    options = options || {};
    // La hoja de opciones vive en #mesa-modal, no en #mesa-modal-content: no
    // se va sola con el siguiente repintado.
    cerrarOpciones(false);
    modal.classList.remove('mesa-modal--open');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    document.body.style.overflow = '';
    sugTimerStop();
    sugTicket = null;
    sugPedidas = false;
    if (modalLastFocus && document.contains(modalLastFocus)) modalLastFocus.focus();
    modalLastFocus = null;
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
    categoriaInicial: 0
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
    // 'favoritos' se retiró: si viene en lo guardado se ignora sin más, porque
    // el bucle de arriba sólo copia claves conocidas de POS_PREFS_DEFAULT.
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

  /** Categorías del menú tal y como las publica la vista. */
  function categoriasMenu() {
    return window.CP_MENU || [];
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
                 (i === 0 ? ' disabled' : '') + '>' + svgIcon('up', 14) + '</button>' +
               '<button type="button" data-mover="' + clave + '" data-dir="1" aria-label="Bajar"' +
                 (i === p.orden.length - 1 ? ' disabled' : '') + '>' + svgIcon('down', 14) + '</button>' +
             '</span>' +
           '</div>';
    }
    h += '</div></div>';

    h += '<div class="mmodal-prefs__group"><h4>Vista del menú</h4>';
    h += grupo('Presentación', 'vistaMenu', [['grid', 'Cuadrícula'], ['lista', 'Lista']]);
    h += grupo('Columnas de platillos', 'columnas', [['2', '2'], ['3', '3'], ['4', '4']]);
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
  var prefsLastFocus = null;

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
    prefsLastFocus = document.activeElement;
    renderPrefsPanel();
    prefsOverlay.hidden = false;
    prefsOverlay.setAttribute('aria-hidden', 'false');
    if (prefsToggle) {
      prefsToggle.setAttribute('aria-expanded', 'true');
      prefsToggle.classList.add('is-active');
    }
    window.requestAnimationFrame(function() {
      var first = prefsOverlay.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
      if (first) first.focus();
    });
  }

  function cerrarPrefs() {
    if (!prefsOverlay) return;
    prefsOverlay.hidden = true;
    prefsOverlay.setAttribute('aria-hidden', 'true');
    if (prefsToggle) {
      prefsToggle.setAttribute('aria-expanded', 'false');
      prefsToggle.classList.remove('is-active');
      var restore = prefsLastFocus && document.contains(prefsLastFocus) ? prefsLastFocus : prefsToggle;
      if (restore) restore.focus();
    }
    prefsLastFocus = null;
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

  // ── Alertas de impresión ──────────────────────────────────
  //
  // Cada comanda o cuenta que no llegó a su impresora —con el servicio de
  // impresión encendido— es una alerta del PISO, no de quien la envió: se ven
  // en todas las tablets hasta que alguien las marca como atendidas. Llegan
  // por dos vías: en la respuesta del envío o del cobro (quien la causó se
  // entera al instante) y en cada refresco del mapa (el resto, en ≤30 s).
  //
  // Con el servicio apagado el backend manda la lista vacía y el botón se
  // oculta solo: sin papel esperado no hay nada que avisar.
  var alertasImpresion = [];
  var alertasVistas = {};
  var alertasSembradas = false;
  var alertasOverlay = null;
  var alertasToggle = null;
  var alertasLista = null;
  var alertasLastFocus = null;

  function alertasAbierto() {
    return !!alertasOverlay && !alertasOverlay.hidden;
  }

  /*
   * Sustituye la lista con la del servidor. Lo nuevo —ids que esta tablet no
   * había visto— se anuncia con un aviso; lo que ya estaba al cargar la
   * pantalla no, porque no es una novedad para nadie y abrir el turno con
   * una ráfaga de avisos viejos entierra lo que sí importa. Para eso está el
   * botón con su contador.
   */
  function sincronizarAlertasImpresion(lista, opciones) {
    opciones = opciones || {};
    lista = Array.isArray(lista) ? lista : [];
    var nuevas = [];
    lista.forEach(function(alerta) {
      if (!alertasVistas[alerta.id]) {
        alertasVistas[alerta.id] = true;
        if (alertasSembradas || opciones.propias) nuevas.push(alerta);
      }
    });
    alertasSembradas = true;
    alertasImpresion = lista;
    pintarAlertasImpresion();
    anunciarAlertasImpresion(nuevas);
  }

  // Las de la respuesta propia se suman a las conocidas sin esperar al
  // refresco; el siguiente refresco trae la lista canónica y la reemplaza.
  function recibirAlertasPropias(lista) {
    if (!Array.isArray(lista) || !lista.length) return;
    var porId = {};
    alertasImpresion.forEach(function(a) { porId[a.id] = a; });
    lista.forEach(function(a) { porId[a.id] = a; });
    var fusion = Object.keys(porId).map(function(id) { return porId[id]; })
      .sort(function(a, b) { return b.id - a.id; });
    sincronizarAlertasImpresion(fusion, { propias: true });
  }

  function anunciarAlertasImpresion(nuevas) {
    if (!nuevas.length) return;
    // Una sola por ráfaga: tres áreas caídas en el mismo envío son un aviso,
    // no tres que se empujan entre sí fuera del stack.
    var texto = nuevas.length === 1
      ? nuevas[0].titulo + '. ' + nuevas[0].mensaje
      : nuevas.length + ' impresiones no llegaron a su impresora. Revisa las alertas de impresión.';
    if (window.AppNotice && typeof window.AppNotice.show === 'function') {
      // Más tiempo que un error normal: quien está tomando un pedido puede no
      // levantar la vista en ocho segundos, y la alerta no se repite.
      window.AppNotice.show({ text: texto, variant: 'error', timeout: 14000 });
    }
  }

  function pintarAlertasImpresion() {
    var total = alertasImpresion.length;
    if (alertasToggle) {
      alertasToggle.hidden = total === 0;
      var contador = document.getElementById('pos-print-alerts-count');
      if (contador) contador.textContent = String(total);
      var etiqueta = total === 1
        ? '1 alerta de impresión'
        : total + ' alertas de impresión';
      alertasToggle.setAttribute('aria-label', etiqueta);
      alertasToggle.title = etiqueta;
    }
    if (!total && alertasAbierto()) {
      cerrarAlertasImpresion();
      return;
    }
    if (!alertasLista) return;

    var sub = document.getElementById('pos-print-alerts-sub');
    if (sub) {
      sub.textContent = total === 1
        ? '1 documento no llegó a su impresora'
        : total + ' documentos no llegaron a su impresora';
    }

    var h = '';
    alertasImpresion.forEach(function(a) {
      var hora = String(a.creada_en || '').substring(11, 16);
      var hace = antiguedadPedido({ hora_apertura: a.creada_en });
      h += '<li class="pos-print-alert pos-print-alert--' + escHtml(a.documento) + '" data-alerta-id="' + a.id + '">';
      h += '<span class="pos-print-alert__icon" aria-hidden="true">' + svgIcon('printer', 18) + '</span>';
      h += '<div class="pos-print-alert__body">';
      h += '<p class="pos-print-alert__title">' + escHtml(a.titulo) + '</p>';
      h += '<p class="pos-print-alert__msg">' + escHtml(a.mensaje) + '</p>';
      h += '<p class="pos-print-alert__accion">' + escHtml(a.accion) + '</p>';
      h += '<p class="pos-print-alert__meta">';
      h += '<span class="pos-print-alert__num">' + escHtml(hora) + '</span>';
      if (hace) h += '<span>' + escHtml(hace) + '</span>';
      if (a.ticket_id) h += '<span>Ticket <span class="pos-print-alert__num">#' + a.ticket_id + '</span></span>';
      h += '</p>';
      if (a.detalle) {
        h += '<p class="pos-print-alert__detalle">' + escHtml(a.detalle) + '</p>';
      }
      h += '</div>';
      h += '<button type="button" class="mmodal-btn mmodal-btn--ghost pos-print-alert__done" data-atender="' + a.id + '">' +
           'Atendida</button>';
      h += '</li>';
    });
    alertasLista.innerHTML = h;
  }

  function abrirAlertasImpresion() {
    if (!alertasOverlay || !alertasImpresion.length) return;
    alertasLastFocus = document.activeElement;
    pintarAlertasImpresion();
    alertasOverlay.hidden = false;
    alertasOverlay.setAttribute('aria-hidden', 'false');
    if (alertasToggle) alertasToggle.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(function() {
      var first = alertasOverlay.querySelector('.pos-print-alert__done, #pos-print-alerts-all');
      if (first) first.focus();
    });
  }

  function cerrarAlertasImpresion() {
    if (!alertasOverlay) return;
    alertasOverlay.hidden = true;
    alertasOverlay.setAttribute('aria-hidden', 'true');
    if (alertasToggle) alertasToggle.setAttribute('aria-expanded', 'false');
    var restore = alertasLastFocus && document.contains(alertasLastFocus) && !alertasLastFocus.hidden
      ? alertasLastFocus
      : null;
    if (restore) restore.focus();
    alertasLastFocus = null;
  }

  function atenderAlertasImpresion(payload, button) {
    setActionBusy(button, true, 'Guardando…');
    postJson('/api/impresion/alertas/atender', payload)
      .then(function(result) {
        if (result && result.ok) {
          sincronizarAlertasImpresion(result.alertas_impresion || []);
          return;
        }
        aviso(result && result.mensaje);
      })
      .catch(function() { avisoConexion(); })
      .finally(function() {
        if (button && document.contains(button)) setActionBusy(button, false);
      });
  }

  /** Se enlaza UNA vez: el overlay vive fuera del modal de mesa. */
  function initAlertasImpresion() {
    alertasOverlay = document.getElementById('pos-print-alerts');
    alertasToggle  = document.getElementById('pos-print-alerts-toggle');
    alertasLista   = document.getElementById('pos-print-alerts-list');
    if (!alertasOverlay || !alertasToggle || !alertasLista) return;

    alertasToggle.addEventListener('click', function() {
      if (alertasAbierto()) cerrarAlertasImpresion(); else abrirAlertasImpresion();
    });
    alertasOverlay.addEventListener('click', function(event) {
      if (event.target.closest('[data-print-alerts-close]')) {
        cerrarAlertasImpresion();
        return;
      }
      var btn = event.target.closest('[data-atender]');
      if (btn) {
        atenderAlertasImpresion({ ids: [parseInt(btn.getAttribute('data-atender'), 10)] }, btn);
      }
    });
    alertasOverlay.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        cerrarAlertasImpresion();
      }
    });
    var todas = document.getElementById('pos-print-alerts-all');
    if (todas) {
      todas.addEventListener('click', function() {
        // Se mandan los ids a la vista y no «todas»: una alerta que llegue
        // mientras el mesero lee no debe marcarse sin que la haya visto.
        atenderAlertasImpresion({ ids: alertasImpresion.map(function(a) { return a.id; }) }, todas);
      });
    }
  }

  function buildModalContent(mesa, estado, reserva, ticket, modalOptions) {
    var h = '';
    var reservaChipLabel = estado === 'bloqueada' ? '¡Próxima a llegar!'
      : estado === 'ocupada' ? 'En ventana de reserva'
      : estado === 'proxima' ? 'Próxima reservación'
      : 'Mesa reservada';
    var reservaVentana = reserva ? ventanaReservaDesdeBackend(reserva, mesa.id) : null;
    var reservaDisplayName = reserva ? nombresMesasReserva(reserva) : mesa.nombre;
    var reservaChipClass = estado;
    if (reservaVentana) {
      reservaChipLabel = reservaVentana.etiqueta;
      reservaChipClass = reservaVentana.estado;
    }

    // El encabezado identifica la mesa una sola vez y mantiene el estado cerca.
    // En Llevar y en una barra lo que se identifica es el TICKET: el nombre de
    // la fila de `mesas` es el mismo para todos y el folio es lo que los
    // distingue.
    var multi = esMultiTicket(mesa);
    var vocab = multi ? vocabMulti(mesa) : null;
    h += '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' +
         escHtml(multi ? vocab.titulo : (reservaDisplayName || mesa.nombre)) + '</span>';
    if (multi && ticket) {
      h += '<span class="mmodal-table-number">' + escHtml(folioPedido(ticket)) + '</span>';
    }
    /*
     * El número SÓLO si el nombre no lo dice ya.
     *
     * `mesa.nombre` es "Mesa 9" y al lado se pintaba "#9": la misma cifra dos
     * veces, y la segunda con el aire de ser otra cosa —un folio, un ticket—.
     * El nombre de la mesa es su identidad completa; el número suelto sólo
     * aporta cuando alguien la ha renombrado ("Terraza", "Barra 2") y hace
     * falta saber contra qué fila del mapa se está trabajando.
     */
    if (!multi && !reserva && mesa.numero && !nombreIncluyeNumero(mesa.nombre, mesa.numero)) {
      h += '<span class="mmodal-table-number">#' + escHtml(mesa.numero) + '</span>';
    }
    h += '</div>';
    if (ticket) {
      // Sólo el chip: la esquina del encabezado es INFORMACIÓN, no acción.
      //
      // Aquí vivía la pastilla de "Cancelar mesa", que aparecía mientras la
      // comanda estaba vacía y se retiraba en cuanto había consumo. Eran dos
      // salidas para la misma mesa en dos sitios distintos del modal —una
      // pastilla en la esquina y un botón al fondo de la pestaña Ticket— y
      // cuál de las dos estaba viva dependía de un conteo que el mesero no ve.
      // Ahora la salida es una sola y está donde se la busca: el botón de la
      // pestaña Ticket (ver actualizarCierreEstado).
      h += '<div class="mmodal-header-estado">';
      h += '<span class="mmodal-chip mmodal-chip--ticket"><span class="mmodal-chip__texto">Ticket abierto</span></span>';
      h += '</div>';
    } else if (reserva) {
      h += '<span class="mmodal-chip mmodal-chip--' + reservaChipClass + '"><span class="mmodal-chip__texto">' + reservaChipLabel + '</span></span>';
    } else if (multi) {
      h += '<span class="mmodal-chip mmodal-chip--ticket"><span class="mmodal-chip__texto">' + escHtml(vocab.nuevo) + '</span></span>';
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
      h += '<span class="mmodal-meta-item">' + svgIcon('users', 14) + '<span>' + ticket.comensales + ' com.</span></span>';
      h += '<span class="mmodal-meta-item">' + svgIcon('clock', 14) + '<span>' + escHtml(horaAp) + '</span></span>';
      // La etapa la detecta n8n; se llena cuando responden las sugerencias.
      h += '<span class="mmodal-etapa" id="mmodal-etapa" hidden></span>';
      h += '</div>';

      var ticketMesaNames = (Array.isArray(ticket.mesa_ids) ? ticket.mesa_ids : []).map(function(mesaId) {
        var mesaTicket = mesaPorId(mesaId);
        return mesaTicket ? mesaTicket.nombre : 'Mesa ' + mesaId;
      });
      if (multi) {
        h += buildMultiSwitch(mesa, ticket);
      } else if (ticketMesaNames.length) {
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
      var catsMenu = categoriasMenu();
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
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' + svgIcon('list', 26) + '</span>' +
           '<span>Selecciona platos en el Menú</span></div>';
      h += '</div>';
      h += '</div>'; // fin panel-scroll
      h += '<div class="mmodal-panel-actions">';
      h += '<div class="mmodal-total-row" id="mmodal-cart-total" style="display:none">';
      h += '<span class="mmodal-total-label">Total</span>';
      h += '<span class="mmodal-total-amount" id="mmodal-total-val">$0</span>';
      h += '</div>';
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-enviar" disabled>';
      h += btnLabel('right', 'Confirmar y enviar (0)', true);
      h += '</button>';
      h += '</div>'; // fin panel-actions
      h += '</div>'; // fin panel-cart

      // ── Panel 3: Estado del ticket ─────────────────────────
      h += '<div id="mmodal-panel-resumen" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Estado del ticket</div>';
      h += '<div class="mmodal-panel-scroll">';
      h += '<div id="mmodal-resumen-content">';
      h += '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' + svgIcon('ticket', 26) + '</span>' +
           '<span>Sin comandas enviadas aún</span></div>';
      h += '</div>';
      h += '</div>'; // fin panel-scroll
      h += '<div class="mmodal-panel-actions">';
      // role="status": un botón deshabilitado no recibe foco, así que quien
      // navega con lector nunca llegaría a la explicación de por qué no puede
      // cerrar. Como región viva, el motivo se anuncia solo al cambiar.
      h += '<div class="mmodal-cerrar-hint" id="mmodal-cerrar-hint" role="status" hidden></div>';
      // Nace deshabilitado y con la etiqueta del caso con consumo: hasta que
      // llega el conteo de ticket_items no se sabe cuál de los dos finales le
      // toca, y el estado seguro es no poder terminar la mesa. Lo suelta
      // actualizarCierreEstado, que corre en cuanto responde /api/ticket-items.
      h += '<button class="mmodal-btn mmodal-btn--danger" id="mmodal-cerrar" data-accion="cobrar" disabled>Cerrar ticket</button>';
      h += '</div>'; // fin panel-actions
      h += '</div>'; // fin panel-resumen

      // ── Panel 4: Sugerencias ─────────────────────────────────
      h += '<div id="mmodal-panel-sugerencias" class="mmodal-tab-panel">';
      h += '<div class="mmodal-panel-label">Sugerencias</div>';
      h += '<div class="mmodal-panel-scroll" id="mmodal-sug-list">';
      // Estado ocioso: no se pide nada hasta que el mesero lo pida (ver
      // prepararSugerencias). El botón lo enlaza bindSugMore.
      h += sugAccionHtml('Recomienda algo a esta mesa', 'sparkles', 'Buscar sugerencias');
      h += '</div>'; // fin panel-scroll
      h += '</div>'; // fin panel-sugerencias

      h += '</div>'; // fin mmodal-panels

    } else if (reserva) {
      // ── Resumen compacto de reservación ────────────────────
      var reservaHora = String(reserva.hora || '').substring(0, 5);
      var reservaVentanaActual = reservaVentana || ventanaReservaDesdeBackend(reserva, mesa.id);
      var reservaMesaIds = mesaIdsReserva(reserva);
      var reservaMesas = reservaMesaIds.map(function (mesaId) {
        var mesaReservada = mesaPorId(mesaId);
        return mesaReservada ? mesaReservada.nombre : 'Mesa ' + mesaId;
      });
      var reservaEstado = String(reserva.estado || '');
      var reservaComensales = parseInt(reserva.comensales || reserva.personas || '0', 10);
      var ausenciaPendiente = reservaEstado === 'confirmada'
        && (reserva.ausencia_pendiente === true || reserva.accion_pendiente === 'REGISTRAR_AUSENCIA')
        && reserva.ticket_abierto !== true;
      var allowWalkIn = Boolean(modalOptions && modalOptions.allowWalkIn === true
        && reservaEstado === 'confirmada'
        && reserva.disponible_para_ticket === true
        && reserva.requiere_advertencia_ticket === true);

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
      h += '<div><dt>Mesas</dt><dd class="' + (reservaMesas.length ? '' : 'is-empty') + '">' +
        escHtml(reservaMesas.length ? reservaMesas.join(', ') : 'Sin mesas asignadas') + '</dd></div>';
      h += '</dl>';
      if (ausenciaPendiente) {
        var toleranciaMinutos = temporalNumber('tolerancia_llegada_minutos');
        var toleranciaLabel = toleranciaMinutos > 0 ? toleranciaMinutos + ' minutos' : 'No disponible';
        var toleranciaHora = String(reserva.tolerancia_hasta || '').match(/(?:T|\s)(\d{2}:\d{2})/);
        var toleranciaHasta = toleranciaHora ? toleranciaHora[1] : '';
        h += '<section class="mmodal-reservation__pending" id="mmodal-reservation-pending" role="alert" aria-live="assertive" aria-labelledby="mmodal-reservation-pending-title">';
        h += '<strong id="mmodal-reservation-pending-title">Tolerancia vencida</strong>';
        h += '<p>Tolerancia vencida. Registra que el cliente no llegó antes de utilizar la mesa.</p>';
        h += '<dl class="mmodal-reservation__pending-facts">';
        h += '<div><dt>Tolerancia</dt><dd>' + toleranciaLabel + (toleranciaHasta ? ' · hasta las ' + escHtml(toleranciaHasta) : '') + '</dd></div>';
        h += '<div><dt>Retraso</dt><dd>' + (parseInt(reserva.minutos_retraso || '0', 10) || 0) + ' minutos</dd></div>';
        h += '</dl>';
        h += '<p>Al registrar la ausencia, la reservación quedará como no show y las mesas dejarán de estar comprometidas.</p>';
        h += '</section>';
      }
      if (reserva.nota) {
        h += '<div class="mmodal-reserva-nota"><span class="mmodal-reserva-nota__label">Nota</span>' + escHtml(reserva.nota) + '</div>';
      }
      if (reserva.comentario_admin) {
        h += '<div class="mmodal-reserva-nota mmodal-reserva-nota--admin"><span class="mmodal-reserva-nota__label">Comentario administrativo</span>' +
          escHtml(reserva.comentario_admin) + '</div>';
      }
      // El campo se inyecta como nodo tras pintar: es un componente vivo, no HTML.
      h += '<div class="mmodal-reservation__waiter" data-mesero-slot></div>';
      h += '<div class="mmodal-reservation__actions">';
      if (allowWalkIn) {
        var minutosAdvertencia = reservaVentanaActual.minutos_restantes != null
          ? parseInt(reservaVentanaActual.minutos_restantes, 10)
          : parseInt(reserva.minutos_para_inicio || reserva.minutos_para_reservacion || '0', 10);
        h += '<section class="mmodal-reservation__blocking mmodal-reservation__blocking--warning" role="alert" aria-live="polite" aria-labelledby="mmodal-reservation-warning-title">';
        h += '<strong id="mmodal-reservation-warning-title">Reservación cercana</strong>';
        h += '<p>El walk-in todavía está permitido, pero requiere confirmación explícita.</p>';
        h += '<p>Faltan ' + escHtml(Math.max(0, minutosAdvertencia || 0)) + ' minutos para la reservación de las ' + escHtml(reservaHora || '--:--') + '.</p>';
        h += '</section>';
        h += '<button type="button" class="mmodal-btn mmodal-btn--primary" id="mmodal-abrir-reservacion">Abrir ticket de todas formas</button>';
        h += '<div class="mmodal-reservation__action-hint">El servidor volverá a validar la reservación y pedirá confirmación antes de crear el ticket.</div>';
      } else if (reservaEstado === 'confirmada' && ausenciaPendiente) {
        if (reserva.puede_marcar_no_show === true && reserva.accion_pendiente === 'REGISTRAR_AUSENCIA') {
          h += '<button type="button" class="mmodal-btn mmodal-btn--release" id="mmodal-no-show">Registrar ausencia</button>';
        } else {
          h += '<p class="mmodal-reservation__action-hint">El registro de ausencia todavía no está disponible.</p>';
        }
        h += '<button type="button" class="mmodal-btn mmodal-btn--ghost" id="mmodal-reservation-back">Volver</button>';
      } else if (reservaEstado === 'confirmada') {
        var puedeIniciar = reserva.puede_iniciar_servicio === true;
        var reservacionProgramada = reserva.reservacion_programada === true
          || (!puedeIniciar && reserva.ventana_pos === 'futura' && !ausenciaPendiente);
        var mesasBloqueantes = Array.isArray(reserva.mesas_bloqueantes)
          ? reserva.mesas_bloqueantes
          : [];
        if (reservacionProgramada) {
          h += '<section class="mmodal-reservation__scheduled" aria-label="Reservación programada">';
          h += '<strong>Reservación programada</strong>';
          h += '<p>' + escHtml(reservaHora || '--:--') + ' · ' + escHtml(reserva.nombre || 'Reservación') + '</p>';
          h += '<span>Todavía no hay acciones disponibles.</span>';
          h += '</section>';
        } else {
        var mostrarBloqueo = !puedeIniciar && (
          mesasBloqueantes.length > 0 ||
          Boolean(reserva.bloqueo)
        );
        if (mostrarBloqueo) {
          h += '<section class="mmodal-reservation__blocking" id="mmodal-reservation-blocking" role="alert" aria-live="polite" aria-labelledby="mmodal-reservation-blocking-title">';
          h += '<strong id="mmodal-reservation-blocking-title">No se puede iniciar el servicio</strong>';
          var bloqueoGeneral = reserva.bloqueo && reserva.bloqueo.mensaje
            ? reserva.bloqueo
            : null;
          if (bloqueoGeneral) {
            h += '<p>' + escHtml(String(bloqueoGeneral.mensaje)) + '</p>';
            if (bloqueoGeneral.consecuencia) {
              h += '<p>' + escHtml(String(bloqueoGeneral.consecuencia)) + '</p>';
            }
          }
          if (mesasBloqueantes.length) {
            h += '<ul class="mmodal-reservation__blocking-list">';
            mesasBloqueantes.forEach(function (bloqueo) {
              var numero = String(bloqueo.numero || bloqueo.mesa_id || '');
              var presentacion = bloqueo.presentacion && bloqueo.presentacion.mensaje
                ? bloqueo.presentacion
                : null;
              if (!presentacion) {
                console.error('Reservacion POS: bloqueo sin presentacion canonica', bloqueo);
                presentacion = bloqueoGeneral;
              }
              if (!presentacion) {
                return;
              }
              h += '<li><strong>Mesa ' + escHtml(numero) + '</strong> — ' +
                escHtml(String(presentacion.mensaje));
              if (presentacion.consecuencia) {
                h += ' ' + escHtml(String(presentacion.consecuencia));
              }
              h += '</li>';
            });
            h += '</ul>';
          }
          h += '</section>';
        }
        var iniciarDescribedBy = mostrarBloqueo
          ? ' aria-describedby="mmodal-reservation-blocking"'
          : '';
        h += '<button class="mmodal-btn mmodal-btn--primary' + (puedeIniciar ? '' : ' mmodal-btn--pending') +
          '" id="mmodal-iniciar"' + iniciarDescribedBy + (puedeIniciar ? '' : ' disabled') + '>Iniciar servicio</button>';
        h += '<div class="mmodal-reservation__action-hint">' +
          escHtml(puedeIniciar
            ? 'El ticket y el servicio se crearán en un solo paso.'
            : (reservaVentanaActual.mensaje || 'Disponible según el horario operativo.')) +
          '</div>';
        var puedeMarcarAusencia = reserva.puede_registrar_ausencia === true && !reserva.ticket_abierto;
        if (puedeMarcarAusencia) {
          h += '<button type="button" class="mmodal-btn mmodal-btn--release" id="mmodal-no-show">Cliente no se presentó</button>';
        }
        }
      }
      h += '</div>';
      h += '</div>';

    } else {
      // ── Vista de mesa libre: abrir ticket ─────────────────
      // En una ventanilla se arranca en 1: un pedido no ocupa asientos y en la
      // barra cada banco es una persona. En una mesa, la pareja es lo común.
      var defaultCom = multi ? 1 : 2;
      h += '<div class="mmodal-name-wrap">';
      h += '<div class="mmodal-label">Nombre</div>';
      h += '<input type="text" class="mmodal-name-input" id="mmodal-nombre"';
      // En Llevar el nombre es con lo que se entrega la bolsa en la barra.
      h += ' placeholder="' + escHtml(multi ? vocab.placeholder : 'Nombre del comensal') +
           '" autocomplete="off" maxlength="80">';
      h += '</div>';
      h += '<div data-mesero-slot></div>';
      h += '<div class="mmodal-stepper-wrap">';
      h += '<div class="mmodal-label">Comensales</div>';
      h += '<div class="mmodal-stepper">';
      h += '<button class="mmodal-step" id="mmodal-dec" aria-label="Quitar comensal">' + svgIcon('minus', 22) + '</button>';
      h += '<span class="mmodal-step-val" id="mmodal-cval">' + defaultCom + '</span>';
      h += '<button class="mmodal-step" id="mmodal-inc" aria-label="Sumar comensal">' + svgIcon('plus', 22) + '</button>';
      h += '</div>';
      h += '</div>';
      h += '<div class="mmodal-actions">';
      h += '<button class="mmodal-btn mmodal-btn--primary" id="mmodal-abrir">' +
           escHtml(multi ? vocab.abrir : 'Abrir ticket') + '</button>';
      // Punto de entrada al modo selección: reemplaza al botón fijo que vivía
      // en el mapa y le comía ancho al salón. Una ventanilla no se une a nada:
      // el servidor rechaza combinarla con otra mesa.
      if (!multi) {
        h += '<button type="button" class="mmodal-btn mmodal-btn--ghost" id="mmodal-unir-mesas">Unir mesas</button>';
      } else if (modalOptions && modalOptions.multiRegreso) {
        h += '<button type="button" class="mmodal-btn mmodal-btn--ghost" id="mmodal-llevar-volver">' +
             escHtml(vocab.verAbiertos) + '</button>';
      }
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

    // La tarjeta entera es el botón de agregar: en tablet apuntarle a un "+"
    // de 20px cuesta más que tocar la fila completa. Es un <button> real para
    // que el teclado y el foco funcionen sin replicarlos a mano.
    //
    // Las tarjetas con opciones ya NO llevan la pastilla «Opciones»/«Elegir»:
    // el cliente la retiró porque la hoja se abre sola al tocar, y en una
    // rejilla de tres columnas la pastilla era lo que más ancho pedía y lo que
    // partía los nombres. Para quien navega con lector, `aria-haspopup` dice
    // que el toque abre un diálogo en vez de sumar al pedido.
    for (var i = 0; i < lista.length; i++) {
      var dish = lista[i];
      var conOpciones = tieneOpciones(dish);
      var row  = document.createElement('button');
      row.type = 'button';
      row.className = 'mmodal-dish-row' + (conOpciones ? ' mmodal-dish-row--opciones' : '');
      row.setAttribute('aria-label', 'Agregar ' + dish.n + ', ' + dineroCorto(dish.p) +
        (conOpciones ? ', con opciones' : ''));
      if (conOpciones) row.setAttribute('aria-haspopup', 'dialog');
      row.innerHTML =
        '<span class="mmodal-dish-name">' + escHtml(dish.n) + '</span>' +
        '<span class="mmodal-dish-price">' + dineroCorto(dish.p) + '</span>';

      (function(d, boton) {
        boton.addEventListener('click', function() {
          // Todo platillo con grupos abre la hoja, también si TODOS traen
          // predeterminada (el Cappuccino: entera, regular, sin jarabe). Se
          // decidió así con el cliente: «abre el modal en cuanto se elige el
          // platillo». Lo normal sigue costando un toque más —la hoja nace con
          // lo predeterminado marcado y el foco en «Agregar»—, y a cambio el
          // mesero ve SIEMPRE qué se puede pedir distinto antes de que la
          // comanda salga; agregar directo escondía las opciones justo en los
          // platillos que más las piden.
          if (tieneOpciones(d)) {
            abrirOpciones(d, { comensal: selectedComensal, origen: boton });
            return;
          }
          addToComanda(d.n, d.p, d.area || 'cocina', d._cat || '', null, null, d.oferta_id || d.id);
        });
      })(dish, row);
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
      var catsBusq = categoriasMenu();
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

  /*
   * `variante` = { ids, label } que arma el selector de opciones. Dos líneas
   * se funden sólo si son el MISMO platillo con las MISMAS opciones para el
   * mismo comensal: un Cappuccino con almendra y otro entero son dos renglones
   * en la comanda aunque se llamen igual.
   */
  function claveVariante(ids) {
    return (ids || []).slice().sort(function(a, b) { return a - b; }).join(',');
  }

  function addToComanda(name, price, areaSlug, categoria, variante, comensal, ofertaId) {
    var areaId = (window.CP_AREAS && window.CP_AREAS[areaSlug])
                 ? window.CP_AREAS[areaSlug].id : 3;
    var para = typeof comensal === 'number' ? comensal : selectedComensal;
    var ids = variante && variante.ids ? variante.ids.slice() : [];
    var clave = claveVariante(ids);
    var found = false;
    for (var i = 0; i < commandaItems.length; i++) {
      var ci = commandaItems[i];
      if (ci.n === name && ci.oferta_id === Number(ofertaId || 0) && ci.comensal === para && claveVariante(ci.v) === clave) {
        ci.qty++;
        found = true;
        break;
      }
    }
    if (!found) {
      commandaItems.push({
        n: name, p: price, area: areaSlug, area_id: areaId,
        categoria: categoria, comensal: para, qty: 1, nota: '',
        oferta_id: Number(ofertaId || 0),
        v: ids, vl: variante && variante.label ? variante.label : ''
      });
    }
    renderComandaCart();
    updateEnviarBtn();
  }

  // ── Opciones de un platillo (variantes) ───────────────────
  //
  // Hoja encima del modal de mesa —no un diálogo aparte— para que el ticket
  // siga a la vista detrás, velado. Los grupos vienen en CP_MENU (dish.g); el precio
  // que se ve aquí es orientativo: el servidor lo recalcula al enviar.

  var opcionesHoja = null;

  function tieneOpciones(dish) {
    return !!(dish && dish.g && dish.g.length);
  }

  function opcionesObligatorias(dish) {
    return tieneOpciones(dish) && dish.g.some(function(gr) { return gr.req; });
  }

  function buscarDishPorNombre(nombre) {
    var cats = window.CP_MENU || [];
    for (var c = 0; c < cats.length; c++) {
      for (var d = 0; d < cats[c].dishes.length; d++) {
        if (cats[c].dishes[d].n === nombre) {
          var dish = cats[c].dishes[d];
          dish._cat = dish._cat || cats[c].label;
          return dish;
        }
      }
    }
    return null;
  }

  function opcionesAbiertas() {
    return !!opcionesHoja;
  }

  function dineroCorto(n) {
    var v = Math.round((Number(n) || 0) * 100) / 100;
    return '$' + (v % 1 === 0 ? v : v.toFixed(2));
  }

  /*
   * La cuenta del precio con opciones: base del platillo más el ajuste de cada
   * opción elegida. Es la MISMA que acaba en el carrito (item.p, y de ahí
   * item.p × qty en el subtotal), así que el botón de la hoja y la fila del
   * pedido no pueden discrepar. Sigue siendo orientativa: el servidor la
   * recalcula en enviarComanda (Variantes::resolver).
   *
   * Un grupo obligatorio sin elegir no suma nada, pero su opción más barata es
   * un piso real: `minimo` lo incluye, para poder decir «desde $X» mientras
   * falta la elección. `exacto` dice si ya no puede moverse.
   */
  function resumenOpciones() {
    var h = opcionesHoja;
    var delta = 0;
    var minimoFaltante = 0;
    var exacto = true;
    var ids = [];
    var etiqueta = [];
    var faltan = [];
    h.dish.g.forEach(function(gr, gi) {
      var id = h.seleccion[gi];
      var op = gr.o.filter(function(o) { return o.id === id; })[0];
      if (!op) {
        faltan.push(gr.n);
        var deltas = gr.o.length ? gr.o.map(function(o) { return Number(o.d) || 0; }) : [0];
        var menor = Math.min.apply(null, deltas);
        minimoFaltante += menor;
        if (Math.max.apply(null, deltas) !== menor) exacto = false;
        return;
      }
      ids.push(op.id);
      delta += Number(op.d) || 0;
      if (!op.def) etiqueta.push(op.n);
    });
    var precio = (Number(h.dish.p) || 0) + delta;
    return {
      ids: ids,
      label: etiqueta.join(' · '),
      precio: precio,
      minimo: precio + minimoFaltante,
      exacto: exacto,
      faltan: faltan
    };
  }

  /*
   * El botón principal de la hoja: verbo a la izquierda y precio a la derecha.
   * El precio va SIEMPRE, también mientras falta un grupo obligatorio —antes
   * ese estado decía «Elige término» y nada más, y era justo cuando el mesero
   * quería saber cuánto iba a costar—. Al editar una línea con varias
   * unidades lo dice («2 × $90»): cambiar las opciones cambia todas a la vez,
   * y sin el multiplicador eso sólo se descubría en el subtotal.
   */
  function botonConfirmarOpciones(h, r) {
    var editando = h.ctx.editIdx !== undefined;
    var item = editando ? commandaItems[h.ctx.editIdx] : null;
    var qty = item && item.qty > 1 ? item.qty : 1;
    var verbo = r.faltan.length
      ? 'Elige ' + r.faltan[0].toLowerCase()
      : (editando ? 'Guardar' : 'Agregar');
    var precio = r.faltan.length && !r.exacto
      ? 'desde ' + dineroCorto(r.minimo)
      : dineroCorto(r.faltan.length ? r.minimo : r.precio);
    if (qty > 1) precio = qty + ' \xD7 ' + precio;
    return '<button type="button" class="mmodal-btn mmodal-btn--primary mmodal-opts__confirmar" data-opts-confirmar' +
      (r.faltan.length ? ' disabled' : '') + '>' +
      // El espacio entre los dos <span> no se ve (es un flex) pero hace que
      // el nombre accesible sea «Agregar $90» y no «Agregar$90».
      '<span class="mmodal-opts__verbo">' + escHtml(verbo) + '</span> ' +
      '<span class="mmodal-opts__precio">' + escHtml(precio) + '</span>' +
      '</button>';
  }

  function pintarOpciones(enfocar) {
    var h = opcionesHoja;
    if (!h) return;
    var r = resumenOpciones();
    var html = '<div class="mmodal-opts__head">' +
      '<div><p class="mmodal-opts__eyebrow">' + (h.ctx.editIdx !== undefined ? 'Cambiar opciones' : 'Opciones') + '</p>' +
      '<h3 class="mmodal-opts__titulo" id="mmodal-opts-titulo">' + escHtml(h.dish.n) + '</h3></div>' +
      '<button type="button" class="mmodal-opts__cerrar" data-opts-cerrar aria-label="Cerrar opciones">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6 6 18"/></svg></button>' +
      '</div><div class="mmodal-opts__cuerpo">';
    h.dish.g.forEach(function(gr, gi) {
      var elegido = h.seleccion[gi];
      var falta = gr.req && !elegido;
      html += '<div class="mmodal-opts__grupo' + (falta ? ' is-falta' : '') + '">' +
        '<p class="mmodal-opts__grupo-nombre" id="mmodal-opts-g' + gi + '">' + escHtml(gr.n) +
        (gr.req ? '<span class="mmodal-opts__req">' + (falta ? 'Elige una' : 'Obligatorio') + '</span>' : '') + '</p>' +
        '<div class="mmodal-opts__pills" role="radiogroup" aria-labelledby="mmodal-opts-g' + gi + '">';
      gr.o.forEach(function(o) {
        var on = o.id === elegido;
        // Roving tabindex: un solo tope de tabulador por grupo, flechas dentro.
        var foco = on || (!elegido && o === gr.o[0]);
        html += '<button type="button" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="' + (foco ? '0' : '-1') + '"' +
          ' class="mmodal-opts__pill' + (on ? ' is-on' : '') + '" data-opt-g="' + gi + '" data-opt-id="' + o.id + '">' +
          '<span class="mmodal-opts__pill-nombre">' + escHtml(o.n) + '</span>' +
          (Number(o.d) ? '<span class="mmodal-opts__pill-delta">' + (o.d > 0 ? '+' : '−') + dineroCorto(Math.abs(o.d)) + '</span>' : '') +
          '</button>';
      });
      html += '</div></div>';
    });
    html += '</div><div class="mmodal-opts__pie">' +
      '<p class="mmodal-opts__resumen">' + (r.label ? escHtml(r.label) : 'Como en la carta') + '</p>' +
      '<div class="mmodal-opts__acciones">' +
      '<button type="button" class="mmodal-btn mmodal-btn--ghost" data-opts-cerrar>Cancelar</button>' +
      botonConfirmarOpciones(h, r) +
      '</div></div>';
    h.card.innerHTML = html;

    if (enfocar) {
      var destino = h.card.querySelector(enfocar);
      if (destino) destino.focus();
    }
  }

  function abrirOpciones(dish, ctx) {
    cerrarOpciones(false);
    // Va en #mesa-modal y no dentro del panel: en móvil el panel es una hoja
    // con scroll propio, y una capa absoluta dentro se iría con el scroll.
    var panel = modal;
    if (!panel || !tieneOpciones(dish)) return;
    ctx = ctx || {};
    var seleccion = {};
    dish.g.forEach(function(gr, gi) {
      var previa = ctx.ids ? gr.o.filter(function(o) { return ctx.ids.indexOf(o.id) !== -1; })[0] : null;
      var defecto = gr.o.filter(function(o) { return o.def; })[0];
      seleccion[gi] = previa ? previa.id : (defecto ? defecto.id : null);
    });
    var hoja = document.createElement('div');
    hoja.className = 'mmodal-opts';
    hoja.innerHTML = '<div class="mmodal-opts__bd" data-opts-cerrar></div>' +
      '<div class="mmodal-opts__card" role="dialog" aria-modal="true" aria-labelledby="mmodal-opts-titulo"></div>';
    panel.appendChild(hoja);
    opcionesHoja = {
      el: hoja,
      card: hoja.querySelector('.mmodal-opts__card'),
      dish: dish,
      ctx: ctx,
      seleccion: seleccion,
      origen: ctx.origen || document.activeElement
    };
    pintarOpciones();
    // El foco va al primer grupo que falte por elegir; si no falta ninguno,
    // directo al botón de agregar: lo normal es aceptar lo predeterminado.
    var primeraFalta = hoja.querySelector('.mmodal-opts__grupo.is-falta [tabindex="0"]');
    (primeraFalta || hoja.querySelector('[data-opts-confirmar]')).focus();
    window.requestAnimationFrame(function() { hoja.classList.add('is-open'); });
  }

  function cerrarOpciones(devolverFoco) {
    if (!opcionesHoja) return;
    var h = opcionesHoja;
    opcionesHoja = null;
    h.el.parentNode && h.el.parentNode.removeChild(h.el);
    if (devolverFoco !== false && h.origen && document.body.contains(h.origen)) h.origen.focus();
  }

  function confirmarOpciones() {
    var h = opcionesHoja;
    if (!h) return;
    var r = resumenOpciones();
    if (r.faltan.length) return;
    var d = h.dish;
    if (h.ctx.editIdx !== undefined && commandaItems[h.ctx.editIdx]) {
      var item = commandaItems[h.ctx.editIdx];
      item.v = r.ids;
      item.vl = r.label;
      item.p = r.precio;
      var queda = item;
      // Si el cambio la deja igual a otra línea del mismo comensal, se funden.
      for (var i = 0; i < commandaItems.length; i++) {
        var otra = commandaItems[i];
        if (otra !== item && otra.n === item.n && otra.comensal === item.comensal && claveVariante(otra.v) === claveVariante(item.v)) {
          otra.qty += item.qty;
          if (!otra.nota && item.nota) otra.nota = item.nota;
          commandaItems.splice(commandaItems.indexOf(item), 1);
          queda = otra;
          break;
        }
      }
      // El foco no vuelve al origen: el repintado del pedido lo destruye. Va
      // a la fila que quedó —la misma o aquella con la que se fundió—.
      cerrarOpciones(false);
      renderComandaCart();
      updateEnviarBtn();
      var filaQueda = modalContent.querySelector('[data-editar-opciones="' + commandaItems.indexOf(queda) + '"]');
      if (filaQueda) filaQueda.focus();
      return;
    }
    var comensal = typeof h.ctx.comensal === 'number' ? h.ctx.comensal : selectedComensal;
    cerrarOpciones();
    addToComanda(d.n, r.precio, d.area || 'cocina', d._cat || '', { ids: r.ids, label: r.label }, comensal, d.oferta_id || d.id);
  }

  if (modal) {
    modal.addEventListener('click', function(e) {
      if (!opcionesHoja || !opcionesHoja.el.contains(e.target)) return;
      if (e.target.closest('[data-opts-cerrar]')) {
        cerrarOpciones();
        return;
      }
      if (e.target.closest('[data-opts-confirmar]')) {
        confirmarOpciones();
        return;
      }
      var pill = e.target.closest('[data-opt-id]');
      if (pill) {
        var gi = Number(pill.getAttribute('data-opt-g'));
        opcionesHoja.seleccion[gi] = Number(pill.getAttribute('data-opt-id'));
        pintarOpciones('[data-opt-g="' + gi + '"][data-opt-id="' + pill.getAttribute('data-opt-id') + '"]');
      }
    });
  }

  function addSuggestionItem(sug, comensal) {
    // Una sugerencia con elección obligatoria (el término de la hamburguesa)
    // no puede entrar a ciegas: el servidor la rechazaría al enviar.
    var dishSug = buscarDishPorNombre(sug.n);
    if (!dishSug) {
      aviso('Actualiza el menú antes de agregar esta sugerencia.', 'warning');
      return;
    }
    var sugerenciaOfertaId = Number(dishSug.oferta_id || dishSug.id || 0);
    if (opcionesObligatorias(dishSug)) {
      abrirOpciones(dishSug, { comensal: comensal });
      return;
    }
    for (var i = 0; i < commandaItems.length; i++) {
      if (commandaItems[i].oferta_id === sugerenciaOfertaId && commandaItems[i].comensal === comensal && claveVariante(commandaItems[i].v) === '') {
        commandaItems[i].qty++;
        renderComandaCart();
        return;
      }
    }
    commandaItems.push({
      n: sug.n, p: sug.p, area: sug.area, area_id: sug.area_id,
      categoria: sug.categoria, comensal: comensal, qty: 1, nota: '',
      oferta_id: sugerenciaOfertaId, v: []
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
    h += '<button class="mmodal-sug-swap" data-swap="' + idx + '">' + svgIcon('refresh', 14) + '<span>Otra sugerencia</span></button>';
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
        var dishCart = buscarDishPorNombre(item.n);
        // Sólo lo que AÚN no se envía se edita así: este carrito es el pedido
        // en preparación. Lo enviado vive en «Estado del ticket» y ahí ya no se
        // cambia: la comanda está en la cocina.
        var editable = tieneOpciones(dishCart);
        row.className = 'mmodal-cart-row' + (editable ? ' mmodal-cart-row--editable' : '');

        // La pista va pegada al NOMBRE, antes de la opción: la opción es un
        // bloque propio y detrás de ella la pista caía sola a otro renglón.
        var nombreHtml = '<span class="mmodal-cart-name__texto">' + escHtml(item.n) +
            (editable ? '<span class="mmodal-cart-name__pista">' + svgIcon('sliders', 13) + '</span>' : '') +
          '</span>' +
          (item.vl ? '<span class="mmodal-cart-variante">' + escHtml(item.vl) + '</span>' : '');
        /*
         * Toda la fila abre las opciones; el BOTÓN es el nombre. Así el
         * teclado y el lector tienen un control real —con Enter, Espacio y
         * foco propios— y el dedo tiene la fila entera, que es lo que
         * sustituye al «Cambiar» que vivía aquí. El nombre accesible empieza
         * por lo que se ve escrito y dice de QUÉ platillo: en un pedido con
         * tres cappuccinos, tres «Cambiar opciones» seguidos no se distinguen.
         * Los deslizadores son una pista, no un botón: en tablet no hay hover
         * que delate que la fila se puede tocar.
         */
        var nombre = editable
          ? '<button type="button" class="mmodal-cart-name mmodal-cart-name--editable" data-editar-opciones="' + idx + '"' +
              ' aria-haspopup="dialog" aria-label="' +
              escHtml(item.n + (item.vl ? ', ' + item.vl : '') + '. Cambiar opciones') + '">' +
              nombreHtml +
            '</button>'
          : '<span class="mmodal-cart-name">' + nombreHtml + '</span>';

        row.innerHTML =
          nombre +
          '<span class="mmodal-cart-subtotal">' + dineroCorto(item.p * item.qty) + '</span>' +
          '<div class="mmodal-cart-meta">' +
            '<span class="mmodal-cart-comensal">' + comLabel + '</span>' +
            '<div class="mmodal-cart-controls">' +
              '<button type="button" data-idx="' + idx + '" data-op="dec" aria-label="Quitar uno de ' + escHtml(item.n) + '">' + svgIcon('minus', 14) + '</button>' +
              '<span class="mmodal-cart-qty">' + item.qty + '</span>' +
              '<button type="button" data-idx="' + idx + '" data-op="inc" aria-label="Sumar uno de ' + escHtml(item.n) + '">' + svgIcon('plus', 14) + '</button>' +
            '</div>' +
          '</div>';

        // Nota toggle
        var noteToggle = document.createElement('button');
        noteToggle.className = 'mmodal-cart-nota-btn' + (item.nota ? ' mmodal-cart-nota-btn--active' : '');
        noteToggle.type = 'button';
        noteToggle.innerHTML = svgIcon('pencil', 13) + '<span>Nota</span>' +
        (item.nota ? svgIcon('check', 13) : '');
        row.querySelector('.mmodal-cart-meta').appendChild(noteToggle);

        if (editable) {
          // Un toque en cualquier parte de la fila que NO sea otro control
          // (cantidad, nota, el campo de la nota) abre la hoja. Se filtra por
          // ancestro y no con stopPropagation en cada control: los +/− se
          // repintan y re-enlazan a cada toque, y bastaba olvidar uno.
          row.addEventListener('click', function(e) {
            var nombreBtn = row.querySelector('[data-editar-opciones]');
            var otro = e.target.closest('button, textarea, input, select, a, .mmodal-cart-nota-wrap');
            if (otro && otro !== nombreBtn) return;
            abrirEdicionOpciones(idx, nombreBtn);
          });
        }

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
            toggle.innerHTML = svgIcon('pencil', 13) + '<span>Nota</span>' +
          (!open ? svgIcon('up', 13) : (commandaItems[capturedIdx].nota ? svgIcon('check', 13) : ''));
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
          var op = btn.dataset.op;
          if (op === 'dec') {
            if (commandaItems[idx].qty > 1) commandaItems[idx].qty--;
            else commandaItems.splice(idx, 1);
          } else {
            commandaItems[idx].qty++;
          }
          renderComandaCart();
          updateEnviarBtn();
          // El repintado se lleva el botón pulsado: sin esto quien suma con el
          // teclado perdía el foco a cada pulsación y volvía a empezar desde
          // el principio del modal.
          var mismo = cartEl.querySelector('button[data-idx="' + idx + '"][data-op="' + op + '"]');
          if (mismo) mismo.focus();
        });
      })(ctrlBtns[j]);
    }
  }

  function abrirEdicionOpciones(idx, origen) {
    var item = commandaItems[idx];
    var dish = item ? buscarDishPorNombre(item.n) : null;
    if (dish) abrirOpciones(dish, { editIdx: idx, ids: item.v || [], comensal: item.comensal, origen: origen });
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
      btn.innerHTML = btnLabel('right', 'Confirmar y enviar (' + total + ')', true);
      btn.disabled    = (total === 0);
    }
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? 'inline' : 'none'; }
    if (totalRow) totalRow.style.display = total > 0 ? 'flex' : 'none';
    if (totalVal) totalVal.textContent = '$' + amount;
  }

  /*
   * El ÚNICO botón de salida del modal de ticket.
   *
   * Antes eran dos, y cuál de los dos servía dependía del consumo: "Cerrar
   * ticket" al fondo de la pestaña Ticket, deshabilitado mientras no hubiera
   * comandas, y una pastilla de "Cancelar mesa" en la esquina del encabezado
   * que se retiraba en cuanto las había. Una mesa recién abierta y sin pedido
   * no se podía cerrar desde el botón que dice cerrar: había que encontrar el
   * otro, en la otra punta del modal.
   *
   * Ahora el botón es uno y decide por el mismo dato que antes gobernaba a los
   * dos —`total`, que cuenta TODAS las filas de ticket_items, cancelados
   * incluidos, porque un item cancelado ya movió inventario—:
   *
   *   con consumo  → "Cerrar ticket" → el flujo de cobro (tipo de cuenta,
   *                  método de pago), y sigue frenado mientras queden
   *                  productos sin entregar.
   *   sin consumo  → "Cerrar mesa"   → la confirmación de descarte, que es la
   *                  que ya existía y advierte de que no queda cuenta.
   *
   * La etiqueta cambia porque la consecuencia cambia: una sola palabra para
   * dos finales distintos —uno cobra y el otro no— sería la parte del botón
   * que miente. La intención viaja en `data-accion` para que el manejador no
   * tenga que volver a contar.
   *
   * Las dos reglas se revalidan en el backend; esto es la señal en pantalla.
   */
  function actualizarCierreEstado(pendientes, total) {
    var btn  = modalContent.querySelector('#mmodal-cerrar');
    var hint = modalContent.querySelector('#mmodal-cerrar-hint');
    if (!btn) return;

    var sinComandas = !total;
    var mensaje = '';
    if (!sinComandas && pendientes > 0) {
      mensaje = 'Falta entregar ' + pendientes + ' producto' + (pendientes === 1 ? '' : 's');
    }

    var accion = sinComandas ? 'cancelar' : 'cobrar';
    // La tira sólo existe en una ventanilla (Llevar o barra), y trae la palabra
    // con la que ésa llama a sus tickets.
    var tira = modalContent.querySelector('.mmodal-llevar-switch');
    var etiqueta = sinComandas
      ? (tira ? 'Descartar ' + (tira.getAttribute('data-singular') || 'pedido') : 'Cerrar mesa')
      : 'Cerrar ticket';
    btn.dataset.accion = accion;

    /*
     * Mientras hay una petición en vuelo, este cálculo NO toca el botón.
     *
     * setActionBusy() lo deshabilita y le pone la etiqueta de progreso; el
     * resumen se repinta solo cada pocos segundos, así que sin esta guarda un
     * refresco que cayera en medio devolvía `disabled = false` y la mesa se
     * podía cerrar dos veces con la primera petición todavía viajando. Antes
     * no se notaba porque el descarte vivía en otro elemento: ahora comparten
     * botón y el choque es real.
     *
     * `posOriginalLabel` es la marca de "en vuelo" —la escribe setActionBusy al
     * ocupar el botón y la borra al soltarlo—, así que lo que se actualiza es
     * esa copia y no el texto visible: al terminar, el botón vuelve con la
     * etiqueta que le toque AHORA y no con la que tenía al empezar.
     */
    if (btn.dataset.posOriginalLabel) {
      btn.dataset.posOriginalLabel = etiqueta;
      return;
    }
    if (btn.textContent !== etiqueta) btn.textContent = etiqueta;

    btn.disabled = mensaje !== '';
    if (hint) {
      // Sólo se escribe cuando cambia: el estado se recalcula en cada refresco
      // del ticket y reescribir el mismo texto en una región viva la hace
      // repetirse en voz cada pocos segundos.
      if (hint.textContent !== mensaje) hint.textContent = mensaje;
      hint.hidden = mensaje === '';
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
          resumenEl.innerHTML = '<div class="mmodal-col-empty"><span class="mmodal-col-empty__icon">' + svgIcon('ticket', 26) + '</span><span>Sin comandas enviadas aún</span></div>';
          var badge = modalContent.querySelector('#mmodal-resumen-badge');
          if (badge) { badge.textContent = '0'; badge.style.display = 'none'; }
          // Sin ítems el botón no se bloquea: pasa a "Cerrar mesa" y libera
          // la mesa sin cuenta, que es la salida de este estado.
          actualizarCierreEstado(0, 0);
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
        actualizarCierreEstado(pendientes, data.items.length);

        var html = '';
        for (var slug in byArea) {
          if (!byArea.hasOwnProperty(slug)) continue;
          var ag = byArea[slug];
          html += '<div class="mmodal-confirm-area-header" style="border-left-color:' + ag.color + '">' +
                  escHtml(ag.label) + '</div>';
          for (var j = 0; j < ag.items.length; j++) {
            var row = ag.items[j];
            // Los colores de estado salen de los tokens --estado-*, los mismos
            // que usan los tableros de área: aquí eran cuatro hex sueltos que
            // ya no coincidían del todo con el CSS.
            var statusColor = row.estado === 'cancelado'      ? 'var(--estado-cancelado)'
                            : row.estado === 'entregado'      ? 'var(--estado-entregado)'
                            : row.estado === 'listo'          ? 'var(--estado-listo)'
                            : row.estado === 'en_preparacion' ? 'var(--estado-preparacion)'
                            : 'var(--estado-enviado)';
            var statusLabel = row.estado === 'cancelado'      ? 'Cancelado'
                            : row.estado === 'entregado'      ? 'Entregado'
                            : row.estado === 'listo'          ? 'Listo'
                            : row.estado === 'en_preparacion' ? 'En preparación' : 'Enviado';
            var com = row.comensal !== null ? 'C.' + row.comensal : 'Gral';
            // Entregable en cualquier estado vivo: el mesero puede llevarse el
            // plato aunque el tablero de área vaya un paso atrás.
            var entBtn = (row.estado !== 'entregado' && row.estado !== 'cancelado')
              ? '<button class="mmodal-entregar-btn" data-id="' + row.id + '">' + svgIcon('check', 13) + '<span>Entregar</span></button>'
              : '';
            // El aspa era el carácter «×»: lo pintaba la fuente, sin nombre
            // accesible, y a 12 px se quedaba fuera del centro de su círculo.
            var cancelBtn = (row.estado !== 'entregado' && row.estado !== 'cancelado')
              ? '<button type="button" class="mmodal-cancel-btn" data-id="' + row.id +
                '" data-nombre="' + escHtml(row.nombre) + '" aria-label="Cancelar ' + escHtml(row.nombre) + '">' +
                svgIcon('close', 12) + '</button>'
              : '';
            var notaHtml = (row.variante
              ? '<span class="mmodal-resumen-variante">' + escHtml(row.variante) + '</span>'
              : '') + (row.nota
              ? '<span class="mmodal-resumen-nota">' + escHtml(row.nota) + '</span>'
              : '');
            var itemClass = row.estado === 'cancelado'
              ? 'mmodal-confirm-item mmodal-confirm-item--cancelado'
              : 'mmodal-confirm-item';
            html += '<div class="' + itemClass + '">' +
                    '<span class="mmodal-status-dot" style="background:' + statusColor + '" title="' + statusLabel + '"></span>' +
                    '<span class="mmodal-confirm-item-name">' + escHtml(row.nombre) +
                    ' <span class="mmodal-cart-comensal">' + com + '</span>' +
                    notaHtml + '</span>' +
                    '<span class="mmodal-confirm-item-qty">\xD7' + row.cantidad + '</span>' +
                    '<span class="mmodal-confirm-item-price">' + dineroCorto(row.precio * row.cantidad) + '</span>' +
                    // Las acciones van juntas en su propio piso de la rejilla
                    // (ver .mmodal-confirm-item): en fila con el precio se
                    // salían de la columna en una tablet a 1024.
                    (cancelBtn || entBtn ? '<span class="mmodal-confirm-item-acciones">' + cancelBtn + entBtn + '</span>' : '') +
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
              // Un doble toque mandaría la segunda petición sobre un ítem ya
              // entregado y el servidor la rechazaría con un aviso de error.
              if (btn.disabled) return;
              btn.disabled = true;
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

  /** Repinta la categoría abierta (tras cambiar de vista o de densidad). */
  function renderCategoriaActual() {
    // Alcanzable desde el panel de ajustes, que se puede abrir sin ninguna
    // mesa en pantalla.
    if (!modalContent || !modalContent.querySelector('#mmodal-dishes')) return;
    var activa = modalContent.querySelector('.mmodal-cat-block--active');
    var cats = categoriasMenu();
    var idx = activa ? parseInt(activa.dataset.idx, 10) : 0;
    if (cats[idx]) renderCategoryDishes(cats[idx]);
  }

  function bindTabsAndComanda(mesa, ticket) {
    // Bloques de categoría (grid)
    var catTabs = modalContent.querySelectorAll('.mmodal-cat-block');
    var cats = categoriasMenu();
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
            renderCategoryDishes(categoriasMenu()[parseInt(tab.dataset.idx, 10)]);
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
    } else {
      // En móvil el resumen no se pinta hasta que el mesero abre su pestaña,
      // así que el botón del encabezado se quedaría deshabilitado para
      // siempre. Comparte la caché de renderResumen: no es una petición extra.
      (function(tid) {
        cargarTicketItems(tid, false)
          .then(function(data) {
            var total = (data && data.ok && data.items) ? data.items.length : 0;
            // El botón de salida vive en la pestaña del resumen, que en móvil
            // aún no se ha pintado; se resuelve aquí su etiqueta y su freno sin
            // esperar a que el mesero la abra.
            var pendientes = 0;
            if (data && data.ok && data.items) {
              data.items.forEach(function(item) {
                if (item.estado !== 'cancelado' && item.estado !== 'entregado') pendientes++;
              });
            }
            actualizarCierreEstado(pendientes, total);
          })
          .catch(function() { /* el botón se queda como nació: deshabilitado */ });
      })(ticket.id);
    }

    // Sugerencias: NO se piden al abrir. Abrir la mesa deja exactamente una
    // petición en vuelo (/api/ticket-items), que es lo que el mesero espera ver.
    prepararSugerencias(ticket);
  }

  // ── Bind de acciones del modal ────────────────────────────
  function bindModalActions(mesa, reserva, ticket, modalOptions) {
    mountMeseroSelect(modalContent.querySelector('[data-mesero-slot]'));
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

    var multiVolverBtn = modalContent.querySelector('#mmodal-llevar-volver');
    if (multiVolverBtn) {
      multiVolverBtn.addEventListener('click', function() {
        volverATableroMulti(mesa);
      });
    }
    if (ticket && esMultiTicket(mesa)) bindMultiSwitch(mesa, ticket);

    var unirBtn = modalContent.querySelector('#mmodal-unir-mesas');
    if (unirBtn) {
      unirBtn.addEventListener('click', function() {
        // Sin refrescar: el refresco marca el mapa como desactualizado mientras
        // viaja, y entonces la mesa de origen no se podía preseleccionar.
        closeModal({ refresh: false });
        activarModoSeleccion();
        // La mesa de origen entra preseleccionada: es la que el mesero tocó.
        mesaSeleccionadaToggle(mesa.id);
        if (selectionMessage) selectionMessage.focus();
      });
    }

    var abrirBtn = modalContent.querySelector('#mmodal-abrir');
    if (abrirBtn) {
      abrirBtn.addEventListener('click', function() {
        var comensales = cval ? parseInt(cval.textContent, 10) : 2;
        var nombreEl   = modalContent.querySelector('#mmodal-nombre');
        var nombre     = nombreEl ? nombreEl.value.trim() : '';
        var meseroId   = selectedMeseroId();
        if (esMultiTicket(mesa)) {
          apiAbrirTicketMulti(mesa, comensales, nombre || null, meseroId, abrirBtn);
        } else {
          apiAbrirTicket([mesa.id], comensales, null, nombre || null, meseroId, abrirBtn);
        }
      });
    }

    var abrirReservaBtn = modalContent.querySelector('#mmodal-abrir-reservacion');
    if (abrirReservaBtn && reserva && modalOptions && modalOptions.allowWalkIn === true) {
      abrirReservaBtn.addEventListener('click', function() {
        requestOpenTicket({
          mesa_ids: [mesa.id],
          comensales: parseInt(reserva.comensales || reserva.personas || '2', 10) || 2,
          nombre: reserva.nombre || null,
          mesero_id: selectedMeseroId()
        }, { button: abrirReservaBtn });
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
        var noShowHour = String(reserva.hora || '').substring(0, 5);
        var noShowPeople = parseInt(reserva.comensales || '0', 10) || 0;
        var toleranceMinutes = temporalNumber('tolerancia_llegada_minutos');
        var toleranceLabel = toleranceMinutes > 0 ? toleranceMinutes + ' minutos' : 'No disponible';
        var toleranceUntil = String(reserva.tolerancia_hasta || '').match(/(?:T|\s)(\d{2}:\d{2})/);
        var toleranceText = toleranceUntil ? toleranceUntil[1] : '';
        var delayMinutes = parseInt(reserva.minutos_retraso || '0', 10) || 0;
        var noShowMessage = 'La tolerancia de llegada de esta reservación ya venció.';
        var noShowTables = mesaIdsReserva(reserva).map(function(mesaId) {
          var mesaNoShow = mesaPorId(mesaId);
          return mesaNoShow ? mesaNoShow.nombre : 'Mesa ' + mesaId;
        });
        showOpenTicketNotice({
          variant: 'absence',
          confirmButtonVariant: 'warning',
          title: 'Registrar que el cliente no se presentó',
          message: noShowMessage,
          details: [
            'Nombre: ' + (reserva.nombre || 'Sin nombre'),
            'Hora de la reservación: ' + (noShowHour || '--:--'),
            'Comensales: ' + noShowPeople,
            'Mesa(s): ' + (noShowTables.length ? noShowTables.join(', ') : 'Sin mesas asignadas'),
            'Tolerancia configurada: ' + toleranceLabel + (toleranceText ? ' · terminó a las ' + toleranceText : ''),
            'Minutos de retraso: ' + delayMinutes
          ],
          consequence: 'La reservación cambiará a no show y sus mesas dejarán de estar comprometidas.',
          cancelLabel: 'Volver',
          confirmLabel: 'Registrar ausencia',
          onConfirm: function() {
            return apiMarcarNoShow(reserva, noShowBtn);
          }
        });
      });
    }

    var reservationBackBtn = modalContent.querySelector('#mmodal-reservation-back');
    if (reservationBackBtn) {
      reservationBackBtn.addEventListener('click', function() {
        closeModal();
      });
    }

    // El único botón de salida: cobra la mesa o la libera, según lo que
    // actualizarCierreEstado haya dejado en data-accion. Se lee en el momento
    // del click y no al enlazar: el mesero puede enviar la primera comanda con
    // el modal abierto, y entonces la salida pasa de descarte a cobro.
    var cerrarBtn = modalContent.querySelector('#mmodal-cerrar');
    if (cerrarBtn && ticket) {
      cerrarBtn.addEventListener('click', function() {
        if (cerrarBtn.dataset.accion === 'cancelar') {
          showCancelarMesaConfirm(ticket.id, mesa);
          return;
        }
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

  /**
   * Encabezado del cierre.
   *
   * `asideHtml` es la ranura derecha, y el cobro la usa para el importe: es el
   * número que el mesero le dice al cliente, así que va arriba del todo y no
   * enterrado en la columna de totales. Antes el cobro gastaba tres líneas
   * —mesa, «Cobro de la cuenta» y «Elige el método, la propina y captura lo
   * recibido»— para describir una pantalla que se explica sola con dos botones
   * y un campo; y ese párrafo era además lo primero que el scroll se comía.
   */
  function buildCerrarHeader(mesa, ticket, asideHtml) {
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' + escHtml(mesa.nombre) + '</span>';
    if (ticket.nombre) {
      h += '<span class="mmodal-title-cliente">— ' + escHtml(ticket.nombre) + '</span>';
    }
    h += '</div>';
    if (asideHtml) {
      h += '<div class="mmodal-header-aside">' + asideHtml + '</div>';
    }
    h += '</div>';
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
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="cc-volver-ticket">' + btnLabel('left', 'Volver') + '</button>';
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
        avisoConexion();
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
      aviso('El ticket no tiene consumo por cobrar.', 'warning');
      showCierreTipo(mesa, ticket);
      return;
    }

    function fmt(cents) { return (cents / 100).toFixed(2).replace(/\.00$/, ''); }

    var propinaCents = 0;
    var propinaPct = null;
    var PROPINA_PRESETS = [10, 15, 20];
    // Qué hacer con lo que sobra del efectivo: 'cambio' (devolverlo todo),
    // 'propina' (quedárselo todo) o 'parcial' (partirlo a mano).
    var destinoExcedente = 'cambio';

    var h = buildCerrarHeader(mesa, ticket,
      '<span class="mmodal-header-aside__label">A cobrar</span>' +
      '<strong class="mmodal-header-aside__val" id="pc-cobrar-head">$' + fmt(totalCents) + '</strong>');
    h += '<div class="mmodal-cobro">';

    // Columna izquierda: lo que se decide (método y propina).
    h += '<div class="mmodal-cobro__col">';
    h += '<div class="mmodal-pago-btns" id="pc-metodos">';
    h += '<button type="button" class="mmodal-pago-btn mmodal-pago-btn--active" data-metodo="efectivo">';
    h += '<span class="mmodal-pago-btn__icon">' + svgIcon('cash', 24) + '</span><span class="mmodal-pago-btn__label">Efectivo</span></button>';
    h += '<button type="button" class="mmodal-pago-btn" data-metodo="tarjeta">';
    h += '<span class="mmodal-pago-btn__icon">' + svgIcon('card', 24) + '</span><span class="mmodal-pago-btn__label">Tarjeta</span></button>';
    h += '</div>';

    h += '<div class="mmodal-propina" id="pc-propina">';
    h += '<span class="mmodal-propina__label">Propina</span>';
    h += '<div class="mmodal-propina__btns">';
    h += '<button type="button" class="mmodal-propina__btn is-on" data-pct="0">Sin propina</button>';
    for (var pp = 0; pp < PROPINA_PRESETS.length; pp++) {
      h += '<button type="button" class="mmodal-propina__btn" data-pct="' + PROPINA_PRESETS[pp] + '">' +
           PROPINA_PRESETS[pp] + '%</button>';
    }
    h += '<button type="button" class="mmodal-propina__btn" data-pct="custom">Otro</button>';
    h += '</div>';
    h += '<span class="mmodal-propina__monto mmodal-money-field mmodal-money-field--sm" id="pc-propina-monto" hidden>' +
         // aria-label y no un <label>: el "$" de al lado es aria-hidden y el
         // campo quedaría anunciado como "cuadro de número, en blanco". El
         // gemelo del excedente sí va dentro de un <label> con su texto.
         '<span aria-hidden="true">$</span><input type="number" ' +
         'id="pc-propina-input" min="0" step="0.01" inputmode="decimal" ' +
         'aria-label="Monto de propina" placeholder="0" hidden></span>';
    h += '</div>';

    h += '<div class="mmodal-recibido" id="pc-recibido-wrap">';
    h += '<label class="mmodal-recibido__label" for="pc-recibido">Monto recibido</label>';
    h += '<div class="mmodal-money-field"><span aria-hidden="true">$</span>';
    h += '<input type="number" id="pc-recibido" min="0" step="0.01" inputmode="decimal" placeholder="' + fmt(totalCents) + '"></div>';
    h += '</div>';
    h += '</div>';

    // Columna derecha: lo que resulta (cuentas y cambio).
    h += '<div class="mmodal-cobro__col mmodal-cobro__col--resumen">';
    h += '<div class="mmodal-split-status">';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Total de la cuenta</span><span class="mmodal-total-amount">$' + fmt(totalCents) + '</span></div>';
    h += '<div class="mmodal-total-row" id="pc-propina-row" hidden><span class="mmodal-total-label">Propina</span><span class="mmodal-total-amount" id="pc-propina-val">$0</span></div>';
    h += '<div class="mmodal-total-row mmodal-total-row--fuerte"><span class="mmodal-total-label">A cobrar</span><span class="mmodal-total-amount" id="pc-cobrar">$' + fmt(totalCents) + '</span></div>';
    h += '</div>';

    /*
     * Reparto del excedente.
     *
     * Antes sólo existía «Quédese con el cambio», todo o nada: si el cliente
     * pagaba $1,000 de una cuenta de $900 y dejaba $40, el mesero tenía que
     * calcular la propina aparte y escribirla en «Otro». Aquí se ve el
     * excedente, se parte donde toque y el cambio se recalcula solo.
     */
    h += '<div class="mmodal-excedente" id="pc-excedente" hidden>';
    h += '<div class="mmodal-excedente__head">';
    h += '<span class="mmodal-excedente__label">Excedente</span>';
    h += '<strong class="mmodal-excedente__val" id="pc-excedente-val">$0</strong>';
    h += '</div>';
    h += '<div class="mmodal-excedente__btns">';
    h += '<button type="button" class="mmodal-excedente__btn" data-destino="propina">Todo propina</button>';
    h += '<button type="button" class="mmodal-excedente__btn is-on" data-destino="cambio">Todo cambio</button>';
    h += '<button type="button" class="mmodal-excedente__btn" data-destino="parcial">Otro</button>';
    h += '</div>';
    h += '<label class="mmodal-excedente__monto" id="pc-excedente-monto" hidden>';
    h += '<span>Propina</span>';
    h += '<span class="mmodal-money-field mmodal-money-field--sm"><span aria-hidden="true">$</span>' +
         '<input type="number" id="pc-excedente-input" min="0" step="0.01" inputmode="decimal" placeholder="0"></span>';
    h += '</label>';
    h += '</div>';

    h += '<div class="mmodal-cambio" id="pc-cambio-wrap">';
    h += '<span class="mmodal-cambio__label">Cambio a devolver</span>';
    h += '<strong class="mmodal-cambio__val" id="pc-cambio">$0</strong>';
    h += '</div>';
    h += '<p class="mmodal-split-diff" id="pc-diff"></p>';
    h += '</div>';

    h += '<div class="mmodal-cerrar-confirm__btns mmodal-cobro__acciones">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="pc-volver">' + btnLabel('left', 'Volver') + '</button>';
    h += '<button class="mmodal-btn mmodal-btn--danger" id="pc-confirm">Cerrar ticket</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    var metodoBtns   = modalContent.querySelectorAll('#pc-metodos .mmodal-pago-btn');
    var propinaBtns  = modalContent.querySelectorAll('#pc-propina .mmodal-propina__btn');
    var propinaInput = modalContent.querySelector('#pc-propina-input');
    var propinaMonto = modalContent.querySelector('#pc-propina-monto');
    var propinaRow   = modalContent.querySelector('#pc-propina-row');
    var propinaVal   = modalContent.querySelector('#pc-propina-val');
    var excedenteWrap  = modalContent.querySelector('#pc-excedente');
    var excedenteVal   = modalContent.querySelector('#pc-excedente-val');
    var excedenteBtns  = modalContent.querySelectorAll('#pc-excedente .mmodal-excedente__btn');
    var excedenteMonto = modalContent.querySelector('#pc-excedente-monto');
    var excedenteInput = modalContent.querySelector('#pc-excedente-input');
    var cobrarEl     = modalContent.querySelector('#pc-cobrar');
    // El mismo importe, repetido en el encabezado: ahí es lo que el mesero le
    // dice al cliente, y en la columna de totales es el renglón que cierra la
    // suma. Se mueven juntos, nunca uno sin el otro.
    var cobrarHeadEl = modalContent.querySelector('#pc-cobrar-head');
    var recibidoWrap = modalContent.querySelector('#pc-recibido-wrap');
    var recibidoEl   = modalContent.querySelector('#pc-recibido');
    var cambioWrap   = modalContent.querySelector('#pc-cambio-wrap');
    var cambioEl     = modalContent.querySelector('#pc-cambio');
    var diffEl       = modalContent.querySelector('#pc-diff');
    var confirmBtn   = modalContent.querySelector('#pc-confirm');

    function metodoActivo() {
      var a = modalContent.querySelector('#pc-metodos .mmodal-pago-btn--active');
      return a ? a.dataset.metodo : 'efectivo';
    }

    /*
     * Se oculta el envoltorio, no sólo el input: el "$" vive en el <span> de
     * fuera y se quedaba solo, colgando bajo los botones de propina.
     */
    function mostrarPropinaInput(visible) {
      propinaInput.hidden = !visible;
      propinaMonto.hidden = !visible;
    }

    function marcarPropinaActiva(pct) {
      for (var i = 0; i < propinaBtns.length; i++) {
        propinaBtns[i].classList.toggle('is-on', propinaBtns[i].dataset.pct === String(pct));
      }
    }

    function persistir() {
      guardarCierrePaso(ticket.id, {
        step: 'completa',
        metodo: metodoActivo(),
        recibido: recibidoEl.value,
        propina: propinaCents,
        propinaPct: propinaPct,
        destino: destinoExcedente
      });
    }

    /*
     * El botón activo es el que se eligió, no el que se deduzca del importe.
     *
     * Deducirlo tenía un punto ciego: una propina de $0 se lee igual como "todo
     * cambio" que como "otro, todavía sin escribir". Al pulsar "Otro" la propina
     * arranca en cero, así que el reparto se declaraba a sí mismo "todo cambio"
     * y volvía a esconder el campo antes de que diera tiempo a teclear nada.
     * Quien mueve la propina por otra vía (los porcentajes) actualiza este
     * estado a mano.
     */
    function marcarDestinoActivo() {
      for (var i = 0; i < excedenteBtns.length; i++) {
        excedenteBtns[i].classList.toggle('is-on', excedenteBtns[i].dataset.destino === destinoExcedente);
      }
      excedenteMonto.hidden = destinoExcedente !== 'parcial';
      // Mientras se teclea no se pisa lo escrito.
      if (destinoExcedente === 'parcial' && document.activeElement !== excedenteInput) {
        excedenteInput.value = (propinaCents / 100).toFixed(2);
      }
    }

    /**
     * Con tarjeta no hay efectivo en juego: se cobra total + propina y el
     * cambio no aplica. Con efectivo, el cambio es lo recibido menos el total
     * menos la propina, así que una propina mayor al excedente es imposible.
     */
    function validar() {
      var esEfectivo = metodoActivo() === 'efectivo';
      var aCobrar = totalCents + propinaCents;

      cobrarEl.textContent = '$' + fmt(aCobrar);
      if (cobrarHeadEl) cobrarHeadEl.textContent = '$' + fmt(aCobrar);
      propinaRow.hidden = propinaCents <= 0;
      propinaVal.textContent = '$' + fmt(propinaCents);
      recibidoWrap.hidden = !esEfectivo;

      if (!esEfectivo) {
        cambioWrap.hidden = true;
        excedenteWrap.hidden = true;
        diffEl.textContent = propinaCents > 0
          ? 'Se cargará $' + fmt(aCobrar) + ' a la tarjeta.'
          : 'Se cargará el total exacto a la tarjeta.';
        diffEl.className = 'mmodal-split-diff mmodal-split-diff--ok';
        confirmBtn.disabled = false;
        return true;
      }

      var val = parseFloat(recibidoEl.value);
      var vacio = recibidoEl.value.trim() === '';
      var recibidoCents = vacio ? aCobrar : ((isNaN(val) || val < 0) ? 0 : Math.round(val * 100));
      var diff = recibidoCents - aCobrar;
      var excedenteSobreTotal = recibidoCents - totalCents;
      var ok = diff >= -1;

      // El reparto sólo aparece cuando hay algo que repartir: con el campo
      // vacío el cliente paga justo y no sobra nada.
      excedenteWrap.hidden = vacio || excedenteSobreTotal <= 1;
      excedenteVal.textContent = '$' + fmt(Math.max(0, excedenteSobreTotal));
      excedenteInput.max = (Math.max(0, excedenteSobreTotal) / 100).toFixed(2);
      marcarDestinoActivo();

      // Siempre visible, aunque sea $0: que el cambio desapareciera obligaba a
      // deducir de su ausencia que no había nada que devolver.
      cambioWrap.hidden = false;
      cambioEl.textContent = '$' + fmt(Math.max(0, diff));

      if (diff < -1) {
        diffEl.textContent = 'Faltan $' + fmt(-diff) + ' para cubrir ' +
          (propinaCents > 0 ? 'la cuenta con propina' : 'el total');
        diffEl.className = 'mmodal-split-diff mmodal-split-diff--falta';
      } else if (diff <= 1) {
        diffEl.textContent = propinaCents > 0 ? 'Pago exacto con propina, sin cambio' : 'Pago exacto, sin cambio';
        diffEl.className = 'mmodal-split-diff mmodal-split-diff--ok';
      } else {
        diffEl.textContent = 'Devuelve $' + fmt(diff) +
          (propinaCents > 0 ? ' después de la propina' : '');
        diffEl.className = 'mmodal-split-diff mmodal-split-diff--ok';
      }

      confirmBtn.disabled = !ok;
      return ok;
    }

    function aplicarPropinaPct(pct) {
      propinaPct = pct;
      propinaCents = Math.round(totalCents * pct / 100);
      mostrarPropinaInput(false);
      // Un porcentaje fija un importe concreto: si luego cambia lo recibido, la
      // propina se recorta a lo que sobre en vez de dejar la cuenta corta.
      destinoExcedente = pct > 0 ? 'parcial' : 'cambio';
      marcarPropinaActiva(pct);
      validar();
      persistir();
    }

    // Restaurar lo capturado si el mesero había salido a medias.
    var guardado = leerCierrePaso(ticket.id);
    if (guardado && guardado.step === 'completa') {
      if (guardado.recibido != null) recibidoEl.value = guardado.recibido;
      if (guardado.metodo === 'tarjeta') {
        for (var mb = 0; mb < metodoBtns.length; mb++) {
          metodoBtns[mb].classList.toggle('mmodal-pago-btn--active', metodoBtns[mb].dataset.metodo === 'tarjeta');
        }
      }
      if (typeof guardado.propina === 'number' && guardado.propina >= 0) {
        propinaCents = Math.round(guardado.propina);
        propinaPct = guardado.propinaPct != null ? guardado.propinaPct : 'custom';
        marcarPropinaActiva(propinaPct);
        if (propinaPct === 'custom') {
          mostrarPropinaInput(true);
          propinaInput.value = (propinaCents / 100).toFixed(2);
        }
      }
      if (guardado.destino) {
        destinoExcedente = guardado.destino;
      }
    }

    for (var b = 0; b < metodoBtns.length; b++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          for (var k = 0; k < metodoBtns.length; k++) metodoBtns[k].classList.remove('mmodal-pago-btn--active');
          btn.classList.add('mmodal-pago-btn--active');
          validar();
          persistir();
        });
      })(metodoBtns[b]);
    }

    for (var pb = 0; pb < propinaBtns.length; pb++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          if (btn.dataset.pct === 'custom') {
            propinaPct = 'custom';
            mostrarPropinaInput(true);
            marcarPropinaActiva('custom');
            validar();
            persistir();
            propinaInput.focus();
            return;
          }
          aplicarPropinaPct(parseInt(btn.dataset.pct, 10));
        });
      })(propinaBtns[pb]);
    }

    propinaInput.addEventListener('input', function() {
      var val = parseFloat(propinaInput.value);
      propinaCents = (isNaN(val) || val < 0) ? 0 : Math.round(val * 100);
      // Una propina escrita a mano es un importe suelto, no "todo el excedente"
      // ni "nada": el reparto tiene que reflejarlo o los dos bloques dirían
      // cosas distintas de la misma cifra.
      destinoExcedente = propinaCents > 0 ? 'parcial' : 'cambio';
      validar();
      persistir();
    });

    // ── Reparto del excedente ──────────────────────────────────
    //
    // El excedente se mide siempre contra el total pelado, no contra "total +
    // propina": si se midiera contra la propina ya aplicada, mover el reparto
    // cambiaría la base sobre la que se está repartiendo y los botones se
    // perseguirían a sí mismos.
    function excedenteCents() {
      var val = parseFloat(recibidoEl.value);
      if (recibidoEl.value.trim() === '' || isNaN(val) || val < 0) {
        return 0;
      }
      return Math.max(0, Math.round(val * 100) - totalCents);
    }

    function aplicarDestino(destino) {
      destinoExcedente = destino;
      var sobra = excedenteCents();

      if (destino === 'propina') {
        propinaCents = sobra;
      } else if (destino === 'cambio') {
        propinaCents = 0;
      } else {
        var val = parseFloat(excedenteInput.value);
        // Nadie puede dejar de propina más de lo que sobró: pasarse
        // significaría cobrar dinero que el cliente no entregó.
        propinaCents = Math.min(sobra, (isNaN(val) || val < 0) ? 0 : Math.round(val * 100));
      }

      // La propina deja de venir de un porcentaje en cuanto se reparte a mano.
      propinaPct = 'custom';
      mostrarPropinaInput(false);
      marcarPropinaActiva('custom');
      validar();
      persistir();
    }

    for (var eb = 0; eb < excedenteBtns.length; eb++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var destino = btn.dataset.destino;
          if (destino === 'parcial') {
            // Se arranca desde lo que ya hubiera de propina, para no borrar de
            // golpe lo que el mesero acababa de elegir.
            excedenteInput.value = (Math.min(propinaCents, excedenteCents()) / 100).toFixed(2);
          }
          aplicarDestino(destino);
          if (destino === 'parcial') {
            excedenteInput.focus();
            excedenteInput.select();
          }
        });
      })(excedenteBtns[eb]);
    }

    excedenteInput.addEventListener('input', function() {
      aplicarDestino('parcial');
    });

    recibidoEl.addEventListener('input', function() {
      // Cambiar lo recibido mueve el excedente; "todo propina" tiene que
      // seguirlo o el importe se quedaría anclado al efectivo anterior.
      if (destinoExcedente === 'propina') {
        propinaCents = excedenteCents();
      } else if (destinoExcedente === 'parcial') {
        propinaCents = Math.min(propinaCents, excedenteCents());
      }
      validar();
      persistir();
    });

    modalContent.querySelector('#pc-volver').addEventListener('click', function() {
      showCierreTipo(mesa, ticket);
    });
    confirmBtn.addEventListener('click', function() {
      if (!validar()) return;
      confirmBtn.disabled = true;
      var aCobrar = totalCents + propinaCents;
      var val = parseFloat(recibidoEl.value);
      var recibido = (metodoActivo() !== 'efectivo' || recibidoEl.value.trim() === '' || isNaN(val))
        ? (aCobrar / 100)
        : val;
      apiCerrarTicket(ticket.id, metodoActivo(), mesa, recibido, propinaCents / 100);
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
        avisoConexion();
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
      aviso('El ticket no tiene consumo por cobrar.', 'warning');
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
    h += '<div class="mmodal-cobro mmodal-split">';
    h += '<div class="mmodal-cobro__head">';
    h += '<p class="mmodal-cerrar-confirm__msg">Cuenta dividida por comensal</p>';
    h += '<p class="mmodal-cerrar-confirm__sub" style="margin-top:2px">Elige cómo repartir la cuenta; puedes ajustar cada monto.</p>';
    h += '</div>';

    // Columna izquierda: cómo se reparte.
    h += '<div class="mmodal-cobro__col">';
    h += '<div class="mmodal-split-modes" role="group" aria-label="Modo de reparto">';
    h += '<button type="button" class="mmodal-split-mode" data-modo="iguales">Partes iguales</button>';
    h += '<button type="button" class="mmodal-split-mode" data-modo="cuenta">Cada quien su cuenta</button>';
    h += '<button type="button" class="mmodal-split-mode mmodal-split-mode--active" data-modo="vacio">Vacío</button>';
    h += '</div>';
    h += '<div class="mmodal-propina mmodal-propina--split">';
    h += '<span class="mmodal-propina__label">Propina sugerida</span>';
    h += '<div class="mmodal-propina__btns" id="split-propina-btns">';
    h += '<button type="button" class="mmodal-propina__btn is-on" data-pct="0">Sin propina</button>';
    h += '<button type="button" class="mmodal-propina__btn" data-pct="10">10%</button>';
    h += '<button type="button" class="mmodal-propina__btn" data-pct="15">15%</button>';
    h += '<button type="button" class="mmodal-propina__btn" data-pct="20">20%</button>';
    h += '</div>';
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
    h += '</div>';

    // Columna derecha: cómo va quedando la cuenta.
    h += '<div class="mmodal-cobro__col mmodal-cobro__col--resumen">';
    h += '<div class="mmodal-split-status">';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Total de la cuenta</span><span class="mmodal-total-amount">$' + fmt(totalCents) + '</span></div>';
    h += '<div class="mmodal-total-row"><span class="mmodal-total-label">Pagado</span><span class="mmodal-total-amount" id="split-pagado">$0</span></div>';
    h += '<div class="mmodal-total-row" id="split-propina-row" style="display:none"><span class="mmodal-total-label">Propina</span><span class="mmodal-total-amount" id="split-propina">$0</span></div>';
    h += '<p class="mmodal-split-diff" id="split-diff"></p>';
    h += '</div>';
    h += '</div>';

    h += '<div class="mmodal-cerrar-confirm__btns mmodal-cobro__acciones">';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="split-volver">' + btnLabel('left', 'Volver') + '</button>';
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
          diffEl.innerHTML   = svgIcon('alert', 13) + '<span>Faltan $' + fmt(-diff) + ' por asignar</span>';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--falta';
        } else if (propina > 0) {
          diffEl.innerHTML   = svgIcon('check', 13) + '<span>Incluye $' + fmt(propina) + ' de propina</span>';
          diffEl.className   = 'mmodal-split-diff mmodal-split-diff--ok';
        } else {
          diffEl.innerHTML   = svgIcon('check', 13) + '<span>Los montos cubren el total</span>';
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
    var propinaBtns = modalContent.querySelectorAll('#split-propina-btns .mmodal-propina__btn');
    var modoActivo = 'vacio';
    var propinaPct = 0;

    /**
     * Reescribe los montos según el modo y el porcentaje de propina. La propina
     * se reparte con el mismo repartir() que el resto para que los centavos
     * cuadren: la suma de los inputs tiene que dar total + propina exacto, que
     * es lo que el servidor vuelve a comprobar.
     */
    function aplicarModo(modo, pct) {
      modoActivo = modo;
      if (pct != null) propinaPct = pct;

      for (var mb = 0; mb < modeBtns.length; mb++) {
        modeBtns[mb].classList.toggle('mmodal-split-mode--active', modeBtns[mb].dataset.modo === modo);
      }
      for (var pb = 0; pb < propinaBtns.length; pb++) {
        propinaBtns[pb].classList.toggle('is-on', parseInt(propinaBtns[pb].dataset.pct, 10) === propinaPct);
      }

      var extra = repartir(Math.round(totalCents * propinaPct / 100), n);

      for (var r = 0; r < rows.length; r++) {
        var ci    = parseInt(rows[r].dataset.c, 10);
        var input = rows[r].querySelector('.mmodal-split-input');
        if (modo === 'vacio') {
          // Sin base sobre la que calcular no hay dónde sumar la propina; el
          // mesero captura los montos a mano.
          input.value = '';
          input.placeholder = '0';
          continue;
        }
        var cents = (modo === 'iguales' ? modoIguales[ci - 1] : modoCuenta[ci - 1]) + extra[ci - 1];
        input.value = fmt(cents);
        input.placeholder = fmt(cents);
      }
      validar();
      persistir();
    }

    for (var mb2 = 0; mb2 < modeBtns.length; mb2++) {
      (function(btn) {
        btn.addEventListener('click', function() { aplicarModo(btn.dataset.modo, null); });
      })(modeBtns[mb2]);
    }

    for (var pb2 = 0; pb2 < propinaBtns.length; pb2++) {
      (function(btn) {
        btn.addEventListener('click', function() {
          var pct = parseInt(btn.dataset.pct, 10);
          // En modo "vacío" no hay montos base que recalcular: se elige un
          // reparto primero para que la propina tenga sobre qué aplicarse.
          if (modoActivo === 'vacio' && pct > 0) {
            aplicarModo('iguales', pct);
            return;
          }
          aplicarModo(modoActivo, pct);
        });
      })(propinaBtns[pb2]);
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

  // ── Pantalla de encuesta de feedback ──────────────────────
  function showFeedbackEncuesta(token, mesaNombre, mesa, ticketId) {
    var url = window.location.origin + '/feedback?token=' + token;
    var multi = esMultiTicket(mesa);
    var vocab = multi ? vocabMulti(mesa) : null;
    // `tickets` todavía incluye el ticket recién cobrado: el refresco llega
    // después. Quedan otros si hay más de uno.
    var quedanOtros = multi && ticketsMulti(mesa).filter(function(t) {
      return Number(t.id) !== Number(ticketId);
    }).length > 0;
    var h = '<div class="mmodal-header"><div class="mmodal-header-id">';
    h += '<span class="mmodal-title">' +
         escHtml(multi ? vocab.titulo + ' ' + folioPedido({ id: ticketId }) : mesaNombre) + '</span>';
    h += '<span class="mmodal-title-cliente">— Ticket cerrado</span>';
    h += '</div></div>';
    h += '<div class="mmodal-feedback-encuesta">';
    h += '<p class="mmodal-feedback-encuesta__title">Invita al comensal a dejar su reseña</p>';
    h += '<div class="mmodal-cerrar-confirm__btns">';
    h += '<button class="mmodal-btn mmodal-btn--primary" id="encuesta-abrir">Pasar a encuesta</button>';
    h += '<button class="mmodal-btn mmodal-btn--ghost" id="encuesta-cerrar">' +
         escHtml(quedanOtros ? vocab.volver : 'Cerrar') + '</button>';
    h += '</div>';
    h += '</div>';

    modalContent.innerHTML = h;

    // La encuesta se abre en otra pestaña: el POS conserva el mapa y el modal
    // detrás, así el mesero entrega la tablet y vuelve sin rehacer el turno.
    modalContent.querySelector('#encuesta-abrir').addEventListener('click', function() {
      window.open(url, '_blank', 'noopener');
    });

    modalContent.querySelector('#encuesta-cerrar').addEventListener('click', function() {
      if (multi) {
        volverATableroMulti(mesa, { cerrarSiVacio: true });
        return;
      }
      closeModal({ refresh: false });
      fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), false);
    });
  }

  // ── Llamadas API ──────────────────────────────────────────
  function staffJsonHeaders() {
    return {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': String(window.CP_STAFF_CSRF_TOKEN || document.body.getAttribute('data-staff-csrf') || '')
    };
  }

  function postJson(url, payload, timeoutMs) {
    var controller = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;
    var timer = null;
    var requestOptions = {
      method: 'POST',
      headers: staffJsonHeaders(),
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
            parseError.kind = 'invalid_json';
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

  // En tablet un toque accidental no debe sacar del turno. La confirmación va
  // en el modal de marca, no en un diálogo del navegador.
  function initLogoutConfirm() {
    var form = document.querySelector('[data-confirm-logout]');
    if (!form) return;

    form.addEventListener('submit', function(event) {
      if (form.dataset.logoutConfirmed === '1') return;
      event.preventDefault();
      if (!window.ConfirmationModal) {
        form.dataset.logoutConfirmed = '1';
        form.submit();
        return;
      }
      window.ConfirmationModal.get().open({
        variant: 'warning',
        eyebrow: 'Sesión',
        title: '¿Cerrar sesión?',
        description: 'Saldrás del punto de venta y volverás a la pantalla de acceso.',
        consequence: 'Los tickets abiertos siguen abiertos para el resto del equipo.',
        secondaryLabel: 'Seguir aquí',
        primaryLabel: 'Cerrar sesión',
        onPrimary: function() {
          form.dataset.logoutConfirmed = '1';
          form.submit();
        }
      });
    });
  }

  function apiPost(url, data) {
    postJson(url, data)
    .then(function(result) {
      if (result.ok) {
        closeModal();
        silentRefresh();
      } else {
        aviso(result.mensaje);
      }
    })
    .catch(function() { avisoConexion(); });
  }

  function showOpenTicketNotice(options) {
    options = options || {};
    if (!modal || !modalContent) return;
    if (!window.ConfirmationModal) return;
    sugTimerStop();
    sugTicket = null;
    sugPedidas = false;
    modalContent.innerHTML = '';
    openModalShell();
    // get() reutiliza el singleton de <body>. create() montaba un root nuevo en
    // cada aviso y ninguno se recogía: se acumulaban durante todo el turno.
    var confirmationController = window.ConfirmationModal.get();
    confirmationController.open({
      variant: options.variant === 'absence' || options.confirmButtonVariant === 'warning'
        ? 'warning'
        : 'default',
      eyebrow: options.variant === 'absence' ? 'Registro operativo' : 'Confirmación operativa',
      title: options.title || 'Aviso',
      description: options.message || 'Revisa la causa y la consecuencia antes de continuar.',
      summary: options.summary || options.details || [],
      consequence: options.consequence || 'No se realizó ningún cambio.',
      secondaryLabel: options.cancelLabel || 'Cerrar',
      primaryLabel: options.confirmLabel || 'Continuar',
      primaryHidden: !options.onConfirm,
      onSecondary: function () {
        if (typeof options.onCancel === 'function') options.onCancel();
        closeModal({ refresh: options.refreshOnCancel !== false });
      },
      onPrimary: function () {
        if (typeof options.onConfirm !== 'function') return;
        var result = options.onConfirm();
        if (!result || typeof result.then !== 'function') return result;
        return result.then(function (value) {
          if (value && value.ok === true && value.commit === true) {
            confirmationController.close(false, { action: 'primary', value: value });
          }
          return value;
        });
      }
    });
  }

  function mostrarAdvertenciaReservacionProxima(payload, warnings, presentacionRespuesta) {
    warnings = Array.isArray(warnings) ? warnings.filter(Boolean) : [];
    if (!warnings.length) return false;

    var warning = warnings[0];
    var presentacion = presentacionRespuesta && presentacionRespuesta.mensaje
      ? presentacionRespuesta
      : (warning.presentacion && warning.presentacion.mensaje
        ? warning.presentacion
        : null);
    if (!presentacion) {
      console.error('Reservacion POS: advertencia sin presentacion canonica', warning);
      return false;
    }
    var acciones = Array.isArray(presentacion.acciones) ? presentacion.acciones : [];
    var accionVolver = acciones.find(function (accion) { return accion.id === 'VOLVER'; });
    var accionConfirmar = acciones.find(function (accion) {
      return accion.tipo === 'primary';
    });

    showOpenTicketNotice({
      title: presentacion.titulo || presentacion.mensaje,
      message: presentacion.mensaje,
      summary: presentacion.descripcion ? [presentacion.descripcion] : [],
      consequence: presentacion.consecuencia || '',
      cancelLabel: accionVolver ? accionVolver.label : 'Volver',
      confirmLabel: accionConfirmar ? accionConfirmar.label : 'Confirmar',
      refreshOnCancel: false,
      onConfirm: function() {
        var confirmedPayload = Object.assign({}, payload, {
          confirmar_reservacion_proxima: 1
        });
        return requestOpenTicket(confirmedPayload, { warningConfirmed: true });
      }
    });
    return true;
  }

  function requestOpenTicket(payload, options) {
    options = options || {};
    if (ticketRequestInFlight) return null;
    payload = Object.assign({}, payload || {});
    if (!options.warningConfirmed && !payload.reservacion_id) {
      payload.confirmar_reservacion_proxima = 0;
    }
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
      var resultadoTipo = clasificarResultadoAperturaTicket(result);
      if (resultadoTipo === 'decision') {
        ticketSelectionState.opening = false;
        ticketSelectionState.warningConfirmed = false;
        actualizarModoSeleccion();
        var warnings = Array.isArray(result.advertencias) && result.advertencias.length
          ? result.advertencias
          : (result.advertencia ? [result.advertencia] : []);
        if (mostrarAdvertenciaReservacionProxima(payload, warnings, result)) {
          return;
        }
        throw new Error('La respuesta de confirmación no incluyó una presentación válida.');
      }

      if (resultadoTipo === 'error') {
        ticketSelectionState.opening = false;
        ticketSelectionState.warningConfirmed = false;
        actualizarModoSeleccion();
        var message = result.mensaje || '';
        var conflictDetails = Array.isArray(result.mesas_conflicto)
          ? result.mesas_conflicto.map(function(mesaId) {
              var mesaConflict = mesaPorId(mesaId);
              return mesaConflict ? mesaConflict.nombre : 'Mesa ' + mesaId;
            })
          : [];
        showOpenTicketNotice({
          title: 'No se puede abrir el ticket',
          message: message,
          details: conflictDetails.length
            ? ['Mesas en conflicto: ' + conflictDetails.join(', ')]
            : [],
          cancelLabel: 'Entendido',
          refreshOnCancel: !ticketSelectionMode
        });
        return null;
      }

      if (resultadoTipo === 'exito') {
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
        return result;
      }
      throw new Error('Respuesta contractual inconsistente al abrir el ticket.');
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
      } else if (error && error.kind === 'invalid_json') {
        errorMessage = 'El servidor devolvió una respuesta inválida. Las mesas se conservaron para intentarlo nuevamente.';
      }
      showOpenTicketNotice({
        title: 'No fue posible abrir el ticket',
        message: errorMessage,
        cancelLabel: 'Entendido',
        refreshOnCancel: !ticketSelectionMode
      });
      return null;
    })
    .finally(function() {
      ticketRequestInFlight = false;
      setActionBusy(actionButton, false);
    });
  }

  function requestReservationOperation(endpoint, payload, options) {
    options = options || {};
    if (reservationRequestInFlight) return Promise.resolve(null);
    reservationRequestInFlight = true;
    var actionButton = options.button || null;
    setActionBusy(actionButton, true, 'Procesando…');
    return postJson(endpoint, payload)
    .then(function(result) {
      var committed = result && result.commit === true;
      if (committed) {
        if (typeof options.onCommit === 'function') {
          return options.onCommit(result);
        }
        if (result.ticket_id) {
          // La llegada es un hecho del presente: la ficha se cierra y, si se
          // estaba mirando otra hora, el mapa vuelve a Ahora antes de abrir el
          // ticket nuevo.
          if (ficha && ficha.abierta()) ficha.cerrar();
          if (enProyeccion()) consultaPos = { hora: '', reservacionId: 0, intencion: '' };
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

      if (result.requiere_reasignacion || result.codigo === 'REQUIERE_REASIGNACION' || result.codigo === 'CAPACIDAD_INSUFICIENTE') {
        showOpenTicketNotice({
          title: 'No fue posible iniciar el servicio',
          message: result.mensaje || '',
          cancelLabel: 'Cerrar',
        });
        return;
      }

      showOpenTicketNotice({
        title: options.errorTitle || 'No fue posible completar la acción',
          message: result.mensaje || '',
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
      reservationRequestInFlight = false;
      setActionBusy(actionButton, false);
    });
  }

  // El POS no monta el indicador de actualización del header, así que el aviso
  // va por el componente de toasts: antes leía una variable que aquí no existe
  // y lanzaba ReferenceError justo cuando el refresco ya había fallado.
  function showNonBlockingReservationNotice(message) {
    aviso(message, 'warning');
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
    return requestReservationOperation(
      '/api/punto-de-venta/reservaciones/no-show',
      { reservacion_id: reserva.id },
      {
        reserva: reserva,
        errorTitle: 'No se pudo registrar la ausencia',
        button: button,
        onCommit: function(result) {
          // La mutación ya está confirmada: el overlay debe desaparecer antes
          // de consultar el snapshot canónico del servidor.
          closeModal({ refresh: false });
          if (ficha && ficha.abierta()) ficha.cerrar();
          var mesasLibres = V.nombresMesas(mesaIdsReserva(reserva), mesaPorId);
          aviso('Ausencia registrada' + (mesasLibres ? ': ' + mesasLibres + ' libre.' : '.'), 'success');
          var refresh = silentRefresh();
          if (!refresh || typeof refresh.then !== 'function') return result;
          return refresh.then(function(refreshResult) {
            if (refreshResult && refreshResult.refreshFailed) {
              showNonBlockingReservationNotice(
                'La ausencia fue registrada, pero no fue posible actualizar el mapa automáticamente.'
              );
            }
            return result;
          }).catch(function() {
            showNonBlockingReservationNotice(
              'La ausencia fue registrada, pero no fue posible actualizar el mapa automáticamente.'
            );
            return result;
          });
        }
      }
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

  // Abre un ticket de ventanilla (Llevar o barra) y va directo al POS sin
  // cerrar el modal.
  function apiAbrirTicketMulti(mesa, comensales, nombre, meseroId, button) {
    if (ticketRequestInFlight) return;
    ticketRequestInFlight = true;
    setActionBusy(button, true, 'Abriendo ticket…');
    postJson('/api/abrir-ticket', {
        mesa_ids:       [mesa.id],
        comensales:     comensales,
        nombre:         nombre || null,
        mesero_id:      meseroId || null
      })
    .then(function(result) {
      if (clasificarResultadoAperturaTicket(result) === 'exito') {
        if (!result.id && !result.ticket_id) {
          throw new Error('La respuesta no incluyó el ticket creado.');
        }
        var newTicket = {
          id:            result.id || result.ticket_id,
          mesa_ids:      [mesa.id],
          nombre:        nombre,
          comensales:    comensales,
          hora_apertura: horaAperturaLocal()
        };
        silentRefresh();
        mostrarTicketMulti(mesa, newTicket);
      } else if (result && result.codigo === 'BARRA_LLENA') {
        // Otra tablet ocupó el último lugar mientras éste llenaba el alta. Se
        // vuelve al tablero ya refrescado, que es donde se ve qué cobrar.
        aviso(result.mensaje || 'La barra ya está llena.', 'warning');
        volverATableroMulti(mesa);
      } else {
        aviso(result.mensaje || 'No se pudo abrir el ticket.');
      }
    })
    .catch(function() { avisoConexion(); })
    .finally(function() {
      ticketRequestInFlight = false;
      setActionBusy(button, false);
    });
  }

  function apiCerrarTicket(ticketId, metodoPago, mesa, recibido, propina) {
    fetch('/api/cerrar-ticket', {
      method:  'POST',
      headers: staffJsonHeaders(),
      body:    JSON.stringify({
        ticket_id:          ticketId,
        metodo_pago:        metodoPago,
        separar_comensales: false,
        recibido:           recibido,
        propina:            propina || 0
      })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.commit === true) {
        limpiarCierrePaso(ticketId);
        recibirAlertasPropias(result.alertas_impresion);
        showFeedbackEncuesta(result.token, mesa ? mesa.nombre : '', mesa, ticketId);
      } else {
        aviso(result.mensaje);
      }
    })
    .catch(function() { avisoConexion(); });
  }

  function apiCerrarTicketDividido(ticketId, pagos, mesa) {
    fetch('/api/cerrar-ticket', {
      method:  'POST',
      headers: staffJsonHeaders(),
      body:    JSON.stringify({
        ticket_id:          ticketId,
        separar_comensales: true,
        pagos:              pagos
      })
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.commit === true) {
        limpiarCierrePaso(ticketId);
        recibirAlertasPropias(result.alertas_impresion);
        showFeedbackEncuesta(result.token, mesa ? mesa.nombre : '', mesa, ticketId);
      } else {
        aviso(result.mensaje);
        var btn = modalContent.querySelector('#split-confirm');
        if (btn) btn.disabled = false;
      }
    })
    .catch(function() {
      avisoConexion();
      var btn = modalContent.querySelector('#split-confirm');
      if (btn) btn.disabled = false;
    });
  }

  function apiEnviarComanda(ticketId) {
    var payload = { ticket_id: ticketId, items: [] };
    for (var i = 0; i < commandaItems.length; i++) {
      var ci = commandaItems[i];
      payload.items.push({
        oferta_id:  ci.oferta_id,
        nombre:    ci.n,
        precio:    ci.p,
        categoria: ci.categoria,
        area_id:   ci.area_id,
        comensal:  ci.comensal === 0 ? null : ci.comensal,
        cantidad:  ci.qty,
        nota:      ci.nota && ci.nota.trim() ? ci.nota.trim() : null,
        variantes: ci.v || []
      });
    }

    var firmaComanda = JSON.stringify([payload.ticket_id, payload.items]);
    if (!claveComandaPendiente || claveComandaPendiente.firma !== firmaComanda) {
      var clave = '';
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        clave = window.crypto.randomUUID();
      } else {
        clave = 'pos-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
      }
      claveComandaPendiente = { firma: firmaComanda, clave: clave };
    }
    payload.clave_idempotencia = claveComandaPendiente.clave;

    fetch('/api/enviar-comanda', {
      method:  'POST',
      headers: staffJsonHeaders(),
      body:    JSON.stringify(payload)
    })
    .then(function(res) { return res.json(); })
    .then(function(result) {
      if (result.ok) {
        claveComandaPendiente = null;
        invalidarTicketItems(ticketId);
        recibirAlertasPropias(result.alertas_impresion);
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
        aviso(result.mensaje);
      }
    })
    .catch(function() { avisoConexion(); });
  }

  function apiEntregarItem(itemId, ticketId) {
    fetch('/api/entregar-item', {
      method:  'POST',
      headers: staffJsonHeaders(),
      body:    JSON.stringify({ item_id: itemId })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.ok) {
        invalidarTicketItems(ticketId);
        renderResumen(ticketId);
        return;
      }
      // Antes se ignoraba el fallo y la fila se quedaba igual sin explicación.
      aviso(result.mensaje || 'No se pudo entregar el platillo.', 'error');
      invalidarTicketItems(ticketId);
      renderResumen(ticketId);
    })
    .catch(function() { avisoConexion(); });
  }

  function showCancelarMesaConfirm(ticketId, mesa) {
    var nombreMesa = mesa && mesa.nombre ? mesa.nombre : 'la mesa';
    if (!window.ConfirmationModal) {
      apiCancelarMesa(ticketId, mesa);
      return;
    }
    if (esMultiTicket(mesa)) {
      var v = vocabMulti(mesa);
      var llevar = isLlevar(mesa);
      window.ConfirmationModal.get().open({
        variant: 'danger',
        eyebrow: 'Acción irreversible',
        title: 'Descartar ' + v.singular,
        description: llevar
          ? '¿Descartar el pedido para llevar ' + folioPedido({ id: ticketId }) + ' sin registrar consumo?'
          : '¿Descartar el ticket ' + folioPedido({ id: ticketId }) + ' de ' + v.titulo + ' sin registrar consumo?',
        consequence: 'No queda cuenta en el corte del día. ' +
                     (llevar ? 'Los demás pedidos para llevar no cambian.' : 'Los demás tickets de ' + v.titulo + ' no cambian.'),
        secondaryLabel: 'No, conservar',
        primaryLabel: 'Sí, descartar',
        onPrimary: function() {
          apiCancelarMesa(ticketId, mesa);
        }
      });
      return;
    }
    window.ConfirmationModal.get().open({
      variant: 'danger',
      eyebrow: 'Acción irreversible',
      title: 'Cancelar mesa',
      description: '¿Liberar ' + nombreMesa + ' sin registrar consumo?',
      consequence: 'El ticket se descarta y no queda cuenta en el corte del día. ' +
                   'Lo que tengas en el Pedido sin enviar también se pierde.',
      secondaryLabel: 'No, conservar',
      primaryLabel: 'Sí, cancelar mesa',
      onPrimary: function() {
        apiCancelarMesa(ticketId, mesa);
      }
    });
  }

  function apiCancelarMesa(ticketId, mesa) {
    // El botón en vuelo es el mismo que el del cobro, así que se bloquea con
    // setActionBusy() como el resto de las acciones del modal: deshabilitado,
    // aria-busy y etiqueta de progreso. La etiqueta original la guarda él y la
    // devuelve al soltarlo, que es lo que hace falta si la petición falla.
    var btn = modalContent.querySelector('#mmodal-cerrar');
    setActionBusy(btn, true, 'Cerrando mesa…');
    postJson('/api/cancelar-mesa', { ticket_id: ticketId })
    .then(function(result) {
      if (result && result.ok) {
        invalidarTicketItems(ticketId);
        limpiarCierrePaso(ticketId);
        // El carrito local muere con el ticket: si no se vacía, la siguiente
        // mesa que se abra en esta sesión hereda los platillos.
        commandaItems = [];
        selectedComensal = 0;
        if (esMultiTicket(mesa)) {
          // Un ticket descartado no cierra la ventanilla: se vuelve a los
          // que siguen abiertos.
          var singular = vocabMulti(mesa).singular;
          aviso(singular.charAt(0).toUpperCase() + singular.slice(1) + ' ' + folioPedido({ id: ticketId }) +
                ' descartado sin registrar cuenta.', 'success');
          volverATableroMulti(mesa, { cerrarSiVacio: true });
          return;
        }
        closeModal({ refresh: false });
        aviso(
          (result.mensaje || 'La mesa quedó libre y no se registró ninguna cuenta.'),
          'success'
        );
        fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), false);
      } else {
        aviso(result && result.mensaje);
        // Puede haber llegado una comanda desde otra tablet, y entonces esta
        // mesa ya no se descarta sino que se cobra: repintar el resumen vuelve
        // a calcular la etiqueta y la acción del botón.
        setActionBusy(btn, false);
        invalidarTicketItems(ticketId);
        renderResumen(ticketId);
      }
    })
    .catch(function() {
      avisoConexion();
      setActionBusy(btn, false);
    });
  }

  function showCancelItemConfirm(itemId, nombre, ticketId) {
    if (!window.ConfirmationModal) return;
    window.ConfirmationModal.get().open({
      variant: 'danger',
      eyebrow: 'Acción irreversible',
      title: 'Cancelar preparación',
      description: '¿Cancelar ' + nombre + '?',
      consequence: 'El área dejará de prepararlo. Esta acción no se puede deshacer.',
      secondaryLabel: 'No, conservar',
      primaryLabel: 'Sí, cancelar',
      onPrimary: function() {
        apiCancelarItem(itemId, ticketId);
      }
    });
  }

  function apiCancelarItem(itemId, ticketId) {
    fetch('/api/cancelar-item', {
      method:  'POST',
      headers: staffJsonHeaders(),
      body:    JSON.stringify({ item_id: itemId })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
      if (result.ok) {
        invalidarTicketItems(ticketId);
        renderResumen(ticketId);
      } else {
        aviso(result.mensaje);
      }
    })
    .catch(function() { avisoConexion(); });
  }

  // ── Selector compartido de fecha ─────────────────────────
  /*
   * Fila de fecha del cajón: «‹ Hoy · Vie 9 de octubre ›». El calendario va
   * plegado y en flujo: abierto se comía la lista a 1024×768.
   */
  var filaFecha = document.querySelector('[data-cajon-fecha]');
  var calendarioCajon = document.getElementById('pos-cajon-calendario');

  function diaRelativo(iso) {
    var hoy = fechaHoyLocal();
    var parte = function(f) { var m = f.split('-'); return Date.UTC(+m[0], +m[1] - 1, +m[2]); };
    var diferencia = Math.round((parte(iso) - parte(hoy)) / 86400000);
    return { '-1': 'Ayer · ', '0': 'Hoy · ', '1': 'Mañana · ' }[String(diferencia)] || '';
  }

  function sumarDias(iso, dias) {
    var m = iso.split('-');
    var fecha = new Date(Date.UTC(+m[0], +m[1] - 1, +m[2] + dias));
    return fecha.getUTCFullYear() + '-' + pad2(fecha.getUTCMonth() + 1) + '-' + pad2(fecha.getUTCDate());
  }

  function actualizarFilaFecha(iso) {
    if (!filaFecha) return;
    var relativo = filaFecha.querySelector('[data-cajon-relativo]');
    if (relativo) relativo.textContent = diaRelativo(iso);
    var anterior = filaFecha.querySelector('[data-cajon-dia="-1"]');
    var siguiente = filaFecha.querySelector('[data-cajon-dia="1"]');
    if (anterior) anterior.setAttribute('aria-label', 'Día anterior: ' + V.fechaLarga(sumarDias(iso, -1)).toLowerCase());
    if (siguiente) siguiente.setAttribute('aria-label', 'Día siguiente: ' + V.fechaLarga(sumarDias(iso, 1)).toLowerCase());
  }

  function plegarCalendario(devolverFoco) {
    if (!calendarioCajon || calendarioCajon.hidden) return;
    calendarioCajon.hidden = true;
    var boton = filaFecha && filaFecha.querySelector('[data-cajon-calendario]');
    if (boton) {
      boton.setAttribute('aria-expanded', 'false');
      if (devolverFoco) boton.focus();
    }
  }

  if (filaFecha) {
    actualizarFilaFecha(fechaMapa());
    filaFecha.addEventListener('click', function(event) {
      var boton = event.target.closest('button');
      if (!boton) return;
      if (boton.hasAttribute('data-cajon-calendario')) {
        var abrir = calendarioCajon && calendarioCajon.hidden;
        if (calendarioCajon) calendarioCajon.hidden = !abrir;
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        return;
      }
      var dias = parseInt(boton.getAttribute('data-cajon-dia') || '0', 10);
      if (!dias) return;
      var destino = sumarDias(fechaMapa(), dias);
      // setValue sin silencio dispara el onChange del calendario: ahí se
      // reinicia la consulta y se pide el mapa del día nuevo.
      if (mapaDatePicker && typeof mapaDatePicker.setValue === 'function') {
        mapaDatePicker.setValue(destino, false);
      }
    });
  }

  function initMapaCalendar() {
    var picker = document.getElementById('mapa-date-picker');
    if (!picker || !fechaInput || typeof window.createReservationDatePicker !== 'function') return;

    mapaDatePicker = window.createReservationDatePicker({
      root: picker,
      input: fechaInput,
      initialValue: fechaInput.value,
      today: picker.getAttribute('data-today-date'),
      allowPast: true,
      onChange: function(val) {
        // Otro día es otra consulta: la proyección del día anterior no aplica.
        // El servidor decide: hoy abre en «ahora», otro día en su primera reserva.
        consultaPos = { hora: '', reservacionId: 0, intencion: '' };
        if (ficha && ficha.abierta()) ficha.cerrar();
        actualizarFilaFecha(val);
        plegarCalendario(true);
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
  function urlConsultaMapa(fecha) {
    var url = '/api/punto-de-venta?fecha=' + encodeURIComponent(fecha);
    if (consultaPos.hora) url += '&hora=' + encodeURIComponent(consultaPos.hora);
    if (consultaPos.reservacionId > 0) url += '&reservacion_id=' + consultaPos.reservacionId;
    if (consultaPos.intencion === 'asignar') url += '&intencion=asignar';
    return url;
  }

  function aplicarContextoMapa(data) {
    var contexto = data && data.contexto && typeof data.contexto === 'object' ? data.contexto : null;
    contextoMapa = {
      modo: contexto && contexto.modo === 'proyeccion' ? 'proyeccion' : 'ahora',
      fecha: String((contexto && contexto.fecha) || data.fecha || ''),
      hora: String((contexto && contexto.hora) || ''),
      solo_lectura: Boolean(contexto && contexto.solo_lectura === true),
      reservacion_id: contexto && contexto.reservacion_id ? parseInt(contexto.reservacion_id, 10) : 0,
      intencion: contexto && contexto.intencion ? String(contexto.intencion) : ''
    };
    horarioPos = data.horario && typeof data.horario === 'object' ? data.horario : null;
    capacidadHorarioPos = data.capacidad_horario && typeof data.capacidad_horario === 'object'
      ? data.capacidad_horario
      : null;
    permisosPos = data.permisos && typeof data.permisos === 'object' ? data.permisos : {};
    // La trama de proyección cuelga de aquí (_proyeccion.scss).
    if (posMapEl) {
      posMapEl.setAttribute('data-modo', contextoMapa.modo);
    }
    // La barra de contexto, el cajón y la ficha escuchan esto: el mapa sólo
    // anuncia qué está mirando, no decide cómo se presenta.
    document.dispatchEvent(new CustomEvent('pos:contexto', {
      detail: {
        contexto: contextoMapa,
        horario: horarioPos,
        capacidad: capacidadHorarioPos,
        permisos: permisosPos
      }
    }));
  }

  function enProyeccion() {
    return contextoMapa.modo === 'proyeccion';
  }

  function avisoSoloLectura() {
    var hora = String(contextoMapa.hora || '').substring(0, 5);
    aviso((hora ? 'Estás viendo el salón a las ' + hora + '. ' : 'Estás viendo una proyección. ') +
      'Vuelve a «Ahora» para operar.', 'info');
  }

  /*
   * Proyecta el mapa a una hora (o a la hora de una reserva) del día que se
   * está mirando. No hay selector libre: se llega aquí tocando un horario o
   * una reserva del cajón. Es silencioso para no vaciar el cajón mientras
   * carga: lo que cambia es el mapa.
   */
  function proyectarMapa(opciones) {
    opciones = opciones || {};
    if (ticketSelectionMode) cancelarModoSeleccion();
    consultaPos = {
      hora: opciones.hora ? String(opciones.hora).substring(0, 5) : '',
      reservacionId: parseInt(opciones.reservacionId || 0, 10) || 0,
      intencion: opciones.intencion === 'asignar' ? 'asignar' : ''
    };
    return fetchData(fechaInput ? fechaInput.value : fechaHoyLocal(), true);
  }

  // «Ahora»: vuelve a la operación en vivo del día de hoy.
  function volverAAhora() {
    consultaPos = { hora: '', reservacionId: 0, intencion: '' };
    var hoy = fechaHoyLocal();
    if (fechaInput && fechaInput.value !== hoy) {
      // En silencio: el onChange del calendario volvería a pedir el mapa.
      if (mapaDatePicker && typeof mapaDatePicker.setValue === 'function') {
        mapaDatePicker.setValue(hoy, true);
      } else {
        fechaInput.value = hoy;
      }
      document.dispatchEvent(new CustomEvent('operational:contextchange', { detail: { fecha: hoy, hora: '' } }));
      actualizarFilaFecha(hoy);
    }
    return fetchData(hoy, true);
  }

  function validatePosMapResponse(data, expectedDate) {
    if (!data || typeof data !== 'object' || Array.isArray(data) || data.ok !== true) {
      return { valida: false, motivo: 'incompleta' };
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(data.fecha || ''))
      || String(data.fecha) !== String(expectedDate || '')) {
      return { valida: false, motivo: 'contexto' };
    }

    var collections = ['mesas', 'mesas_estado', 'reservaciones', 'tickets', 'meseros', 'alertas_impresion'];
    for (var i = 0; i < collections.length; i += 1) {
      var collection = data[collections[i]];
      if (!Array.isArray(collection)) {
        return { valida: false, motivo: 'incompleta' };
      }
      if (collections[i] === 'mesas' || collections[i] === 'mesas_estado') {
        var validItems = collection.every(function (item) {
          var valid = item && typeof item === 'object' && !Array.isArray(item)
            && Number.isInteger(Number(item.id)) && Number(item.id) > 0;
          if (valid && collections[i] === 'mesas_estado') {
            var capabilities = item.capacidades_pos;
            var flags = [
              'reservable', 'operable', 'ticketable', 'independiente_consulta',
              'abrir_caja', 'crear_pedido_llevar', 'mostrar_estado_ticket', 'decorativo'
            ];
            valid = capabilities && typeof capabilities === 'object' && !Array.isArray(capabilities)
              && flags.every(function (flag) { return typeof capabilities[flag] === 'boolean'; })
              && typeof capabilities.etiqueta_operacion === 'string';
          }
          return valid;
        });
        if (!validItems) {
          return { valida: false, motivo: 'incompleta' };
        }
      }
    }
    return { valida: true, motivo: '' };
  }

  function fetchData(fecha, silent) {
    var requestSequence = ++dataRequestSequence;
    if (dataAbortTimer) {
      window.clearTimeout(dataAbortTimer);
      dataAbortTimer = null;
    }
    if (dataAbortController && typeof dataAbortController.abort === 'function') {
      dataAbortController.abort();
    }
    dataAbortController = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;
    var requestController = dataAbortController;
    var requestOptions = requestController ? { signal: requestController.signal } : {};
    var timedOut = false;
    var requestTimer = requestController ? window.setTimeout(function() {
      timedOut = true;
      requestController.abort();
    }, POS_REQUEST_TIMEOUT_MS) : null;
    dataAbortTimer = requestTimer;
    mapSnapshotStale = true;
    if (mapVisual && typeof mapVisual.setConsultaEstado === 'function') {
      mapVisual.setConsultaEstado(loadedMapDate
        ? 'Actualizando el mapa; las operaciones que dependen de esta consulta están deshabilitadas.'
        : 'Cargando el mapa.', true);
    }
    actualizarControlesApertura();
    if (!silent) {
      if (loadingEl) loadingEl.classList.remove('hidden');
      if (cajon) cajon.cargando();
    }
    requestOptions.cache = 'no-store';
    return fetch(urlConsultaMapa(fecha), requestOptions)
      .then(function(res) {
        return res.json().then(function(data) {
          if (!res.ok) throw { kind: 'http' };
          return data;
        });
      })
      .then(function(data) {
        var fechaSeleccionada = fechaInput ? fechaInput.value : fecha;
        if (requestSequence !== dataRequestSequence || fechaSeleccionada !== fecha) {
          return { stale: true };
        }
        var responseValidation = validatePosMapResponse(data, fecha);
        if (!responseValidation.valida) {
          throw { kind: responseValidation.motivo === 'contexto' ? 'context' : 'invalid_response' };
        }
        var recoveringMap = mapSnapshotStale;
        var aplicar = function() {
          mesas         = data.mesas         || [];
          mesasEstado   = data.mesas_estado  || [];
          reservaciones = data.reservaciones  || [];
          reservacionesDia = Array.isArray(data.reservaciones_dia) ? data.reservaciones_dia : reservaciones;
          tickets       = data.tickets        || [];
          meseros       = data.meseros        || [];
          sincronizarAlertasImpresion(data.alertas_impresion || []);
          temporalConfig = (data.config && data.config.temporal) || temporalConfig;
          posConfig      = (data.config && data.config.pos)      || posConfig;
          configReservacionesPos = (data.config && data.config.reservaciones) || configReservacionesPos;
          aplicarContextoMapa(data);
          sincronizarRelojOperativo(data.server_time || data.actualizado_en);
          mapSnapshotStale = false;
          loadedMapDate = data.fecha;
          if (mapVisual && typeof mapVisual.setConsultaEstado === 'function') {
            mapVisual.setConsultaEstado('', false);
          }
          if (ticketSelectionMode) {
            selectedMesaIds = selectedMesaIds.filter(function(mesaId) {
              var mesa = mesaPorId(mesaId);
              return mesa && mesaPuedeSeleccionarse(mesa, estadoMesaActual(mesaId));
            });
          }
          actualizarControlesApertura();
          var fichaNueva = sincronizarFicha();
          if (!silent || recoveringMap) renderMesas();
          renderEstados();
          renderSidebar();
          pintarContexto();
          if (loadingEl) loadingEl.classList.add('hidden');
          return fichaNueva;
        };
        /*
         * Si la consulta la pidió el mesero (tocar un horario, una reserva,
         * «Ahora»), el cambio entra con su transición; el sondeo repinta en
         * seco. Al terminar, la «llamada» señala las mesas de la ficha nueva.
         */
        var tipoTransicion = transicionPendiente;
        transicionPendiente = '';
        if (tipoTransicion && window.PosTransicion) {
          var fichaAbierta = false;
          return window.PosTransicion.ejecutar(tipoTransicion, function() {
            fichaAbierta = aplicar();
          }).then(function() {
            if (fichaAbierta) {
              llamarMesasFicha();
              if (ficha) ficha.enfocarTitulo();
            }
            continuarCambioPendiente();
            return { ok: true };
          });
        }
        if (aplicar()) {
          llamarMesasFicha();
          if (ficha) ficha.enfocarTitulo();
        }
        continuarCambioPendiente();
        return { ok: true };
      })
      .catch(function(error) {
        if (requestTimer) window.clearTimeout(requestTimer);
        if (dataAbortTimer === requestTimer) dataAbortTimer = null;
        if (error && error.name === 'AbortError' && !timedOut) return;
        if (requestSequence !== dataRequestSequence) return { stale: true };
        // Una proyección que no llega no abre su ficha a destiempo, y se queda
        // el modo anterior: el mesero lo pidió, así que se le dice.
        if (transicionPendiente) {
          aviso('No se pudo cargar el mapa' + (consultaPos.hora ? ' de las ' + consultaPos.hora : '') + '. Revisa la conexión.');
        }
        fichaPendiente = null;
        transicionPendiente = '';
        mapSnapshotStale = true;
        var mapFailureCause = error && error.kind === 'context'
          ? 'La respuesta del mapa correspondía a otra fecha y se descartó.'
          : (error && error.kind === 'invalid_response'
            ? 'La respuesta del mapa estaba incompleta y se descartó.'
            : (timedOut
              ? 'La consulta del mapa tardó demasiado y se canceló.'
              : 'No fue posible actualizar el mapa.'));
        var mapFailureConsequence = loadedMapDate
          ? ' Se conserva la consulta del ' + loadedMapDate + '; las operaciones que dependen de ella están deshabilitadas.'
          : ' Intenta actualizar la consulta.';
        var mapFailureCopy = mapFailureCause + mapFailureConsequence;
        if (mapVisual && typeof mapVisual.setConsultaEstado === 'function') {
          mapVisual.setConsultaEstado(mapFailureCopy, true);
        }
        actualizarControlesApertura();
        if (!silent) {
          // La lista anterior se conserva debajo del aviso: datos de hace 30 s
          // valen más que una pantalla vacía.
          if (cajon) cajon.error();
          if (loadingEl) loadingEl.classList.add('hidden');
        }
        return { refreshFailed: true, timedOut: timedOut };
      })
      .finally(function() {
        if (requestTimer) window.clearTimeout(requestTimer);
        if (dataAbortTimer === requestTimer) dataAbortTimer = null;
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
  // Redibuja con los datos ya cargados para que los rótulos relativos al reloj
  // («en 45 min») avancen entre dos consultas al servidor.
  function syncLive() {
    renderEstados();
    renderSidebar();
  }

  function activateLive() {
    isLive = true;
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
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
  }

  // ── Eventos ───────────────────────────────────────────────
  initMapaCalendar();
  initPrefsOverlay();
  initAlertasImpresion();
  actualizarModoSeleccion();
  initLogoutConfirm();
  if (selectionToggle) selectionToggle.addEventListener('click', function() {
    if (modoAsignacion) guardarCambioMesas([]);
    else confirmarAperturaSeleccion();
  });
  if (selectionCancel) selectionCancel.addEventListener('click', function() {
    if (modoAsignacion) cancelarCambioMesas();
    else cancelarModoSeleccion();
  });
  if (asignacionPregunta) asignacionPregunta.addEventListener('click', function(event) {
    var boton = event.target.closest('[data-asignacion-eleccion]');
    if (boton) resolverPreguntaAsignacion(boton.getAttribute('data-asignacion-eleccion'));
  });
  // Escape con la pregunta abierta la deja sin efecto: ni cambia ni añade.
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modoAsignacion && modoAsignacion.pendiente) {
      e.preventDefault();
      resolverPreguntaAsignacion('ninguna');
    }
  });

  // Hoja de opciones: Escape la cierra, Tab no sale de ella y las flechas se
  // mueven dentro del grupo (patrón de radiogroup) eligiendo al moverse.
  function teclaOpciones(e) {
    var h = opcionesHoja;
    if (e.key === 'Escape') {
      e.preventDefault();
      cerrarOpciones();
      return;
    }
    var pill = e.target.closest ? e.target.closest('[data-opt-id]') : null;
    if (pill && /^Arrow(Left|Right|Up|Down)$/.test(e.key)) {
      e.preventDefault();
      var hermanos = Array.prototype.slice.call(pill.parentNode.querySelectorAll('[data-opt-id]'));
      var paso = (e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : -1;
      var siguiente = hermanos[(hermanos.indexOf(pill) + paso + hermanos.length) % hermanos.length];
      var gi = Number(siguiente.getAttribute('data-opt-g'));
      h.seleccion[gi] = Number(siguiente.getAttribute('data-opt-id'));
      pintarOpciones('[data-opt-g="' + gi + '"][data-opt-id="' + siguiente.getAttribute('data-opt-id') + '"]');
      return;
    }
    // Enter sobre una opción la elige Y agrega si ya no falta nada: el camino
    // corto del teclado es «flecha hasta la opción, Enter».
    if (e.key === 'Enter' && pill) {
      e.preventDefault();
      var giEnter = Number(pill.getAttribute('data-opt-g'));
      h.seleccion[giEnter] = Number(pill.getAttribute('data-opt-id'));
      if (resumenOpciones().faltan.length) {
        pintarOpciones('.mmodal-opts__grupo.is-falta [tabindex="0"]');
        return;
      }
      confirmarOpciones();
      return;
    }
    if (e.key !== 'Tab') return;
    var items = Array.prototype.slice.call(h.card.querySelectorAll('button:not([disabled]):not([tabindex="-1"])'));
    if (!items.length) return;
    var primero = items[0];
    var ultimo = items[items.length - 1];
    if (e.shiftKey && document.activeElement === primero) {
      e.preventDefault();
      ultimo.focus();
    } else if (!e.shiftKey && document.activeElement === ultimo) {
      e.preventDefault();
      primero.focus();
    }
  }

  if (modalBd)    modalBd.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) {
    // El panel de ajustes se dibuja por encima del modal: cierra primero.
    if (prefsAbierto()) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cerrarPrefs();
        return;
      }
      if (e.key === 'Tab') {
        var prefsItems = Array.from(prefsOverlay.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
        if (!prefsItems.length) return;
        var prefsFirst = prefsItems[0];
        var prefsLast = prefsItems[prefsItems.length - 1];
        if (e.shiftKey && document.activeElement === prefsFirst) {
          e.preventDefault();
          prefsLast.focus();
        } else if (!e.shiftKey && document.activeElement === prefsLast) {
          e.preventDefault();
          prefsFirst.focus();
        }
      }
      return;
    }
    if (!modal || !modal.classList.contains('mesa-modal--open')) return;
    if (opcionesAbiertas()) {
      teclaOpciones(e);
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal();
      return;
    }
    if (e.key !== 'Tab') return;
    var modalItems = modalFocusables();
    if (!modalItems.length) {
      e.preventDefault();
      modal.focus();
      return;
    }
    var modalFirst = modalItems[0];
    var modalLast = modalItems[modalItems.length - 1];
    if (e.shiftKey && document.activeElement === modalFirst) {
      e.preventDefault();
      modalLast.focus();
    } else if (!e.shiftKey && document.activeElement === modalLast) {
      e.preventDefault();
      modalFirst.focus();
    }
  });
  window.addEventListener('pagehide', function() {
    stopPolling();
    deactivateLive();
    dataRequestSequence++;
    if (dataAbortController && typeof dataAbortController.abort === 'function') {
      dataAbortController.abort();
    }
    dataAbortController = null;
  });
  window.addEventListener('pageshow', function() {
    if (!isLive) activateLive();
  });

  // ── Init ──────────────────────────────────────────────────
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
