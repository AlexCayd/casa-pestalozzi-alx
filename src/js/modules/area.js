/* ── KDS — Área de Producción · Casa Pestalozzi ─────────────── */

/**
 * En tablet un toque accidental no debe sacar del turno. El header operativo
 * marca su formulario con [data-confirm-logout], pero el manejador vivía en
 * punto-de-venta.js, que el tablero no carga: aquí se repite para las dos
 * pantallas de área, que sí reciben ConfirmationModal dentro de bundle.min.js.
 */
function initAreaLogoutConfirm() {
  var form = document.querySelector('[data-confirm-logout]');
  if (!form || form.dataset.areaLogoutBound === '1') return;
  form.dataset.areaLogoutBound = '1';

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
      description: 'Saldrás del tablero y volverás a la pantalla de acceso.',
      consequence: 'Las comandas en curso siguen en el tablero para el resto del equipo.',
      secondaryLabel: 'Seguir aquí',
      primaryLabel: 'Cerrar sesión',
      onPrimary: function() {
        form.dataset.logoutConfirmed = '1';
        form.submit();
      }
    });
  });
}

function initArea() {
  if (!window.CP_AREA) return;
  if (initArea._done) return;
  initArea._done = true;

  // AREA_COLOR se retiró: no lo leía ninguna otra línea, y con el color de la
  // estación fuera del tablero (ver src/scss/area/_area.scss) ya no hay razón
  // para que vuelva.
  var AREA_ID     = window.CP_AREA.id;
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

  /*
   * Catálogo local de iconos, como el del POS (SVG_PATHS en punto-de-venta.js).
   * No se reutiliza window.AdminIcons porque vive en admin.js y el tablero de
   * piso sólo carga bundle.min.js, que excluye src/js/admin/** a propósito.
   * La regla de la casa se mantiene: ni un emoji ni un glifo haciendo de icono.
   */
  var SVG_PATHS = {
    check: '<path d="m5 12.8 4.6 4.4L19 6.8"/>',
    right: '<path d="M4 12h15.5"/><path d="m13.5 6 6 6-6 6"/>',
    left:  '<path d="M20 12H4.5"/><path d="m10.5 6-6 6 6 6"/>',
    // Columna vacía. Sustituye al glifo ◌, que es un carácter y por tanto lo
    // pintaba la fuente del sistema: no heredaba currentColor y cambiaba de
    // forma entre plataformas —y la tablet del piso no es siempre la misma—.
    idle:  '<circle cx="12" cy="12" r="8.5" stroke-dasharray="3 3.2"/>'
  };

  function svgIcon(name, size) {
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" ' +
           'stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" ' +
           'aria-hidden="true">' + (SVG_PATHS[name] || '') + '</svg>';
  }

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

  /**
   * La antigüedad en palabras de cocina.
   *
   * Los minutos a pelo dejan de ser una duración en cuanto pasan de la hora:
   * una comanda olvidada —o un fixture viejo— enseñaba "hace 11575 min", que
   * nadie convierte mentalmente a nada. Por encima de la hora se dice en horas,
   * y pasado medio día lo útil ya no es cuánto lleva sino a qué hora entró.
   */
  function antiguedadTexto(min, timestamp) {
    if (min === 0) return 'ahora';
    if (min < 60) return 'hace ' + min + ' min';
    if (min < 720) {
      var horas = Math.floor(min / 60);
      var resto = min % 60;
      return 'hace ' + horas + ' h' + (resto ? ' ' + resto + ' min' : '');
    }
    var d = new Date(timestamp.replace(' ', 'T'));
    return 'desde ' + d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
  }

  /*
   * La firma incluye el MINUTO transcurrido, no sólo el estado.
   *
   * Con `id:estado` el tablero salía temprano de renderBoard() mientras nada
   * cambiara de columna, así que "hace 2 min" seguía diciendo dos minutos media
   * hora después y el filete nunca cruzaba a ámbar ni a rojo: el dato que más
   * corre en una cocina era el único congelado. Con el minuto dentro, el poll
   * de 3 s repinta una vez por minuto —y el scroll se conserva, ver renderBoard.
   */
  function firmaDe(lista) {
    var partes = [];
    for (var i = 0; i < lista.length; i++) {
      partes.push(lista[i].id + ':' + lista[i].estado + ':' + minutosDesde(lista[i].created_at));
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
          // data-status lo lee el indicador compartido del header operativo
          // (_header.scss) para teñir el punto; sin él se quedaría en verde
          // aunque el tablero llevara minutos sin poder consultar.
          // Sólo la hora: la palabra "Actualizado" ocupaba media barra para
          // decir lo que el punto verde de al lado ya dice. Si el punto late
          // en verde, esa es la hora del último dato; en rojo, "Sin conexión".
          refreshInfo.setAttribute('data-status', 'ok');
          refreshInfo.textContent =
            now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        }
      })
      .catch(function() {
        if (!refreshInfo) return;
        refreshInfo.setAttribute('data-status', 'error');
        refreshInfo.textContent = 'Sin conexión';
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

    var vacioIcono = '<span class="area-empty__icon" aria-hidden="true">' + svgIcon('idle', 22) + '</span>';
    var emptyEnv   = '<div class="area-empty">' + vacioIcono + '<span>Sin pedidos</span></div>';
    var emptyOther = '<div class="area-empty">' + vacioIcono + '<span>Sin pedidos</span></div>';

    /*
     * El scroll de las tres columnas se guarda y se repone.
     *
     * Reemplazar el innerHTML destruye los nodos y con ellos el scrollTop: quien
     * había bajado en "Enviados" a ver las comandas viejas volvía al tope en
     * cuanto alguien —él mismo o el compañero de la otra estación— tocaba
     * cualquier botón del tablero, porque la firma cubre TODOS los ítems. Ahora
     * que la firma incluye el minuto, esto pasaría además una vez por minuto.
     */
    var scrollPrevio = [listEnv.scrollTop, listPrep.scrollTop, listListo.scrollTop];

    listEnv.innerHTML   = envCards.length   ? envCards.join('')   : emptyEnv;
    listPrep.innerHTML  = prepCards.length  ? prepCards.join('')  : emptyOther;
    listListo.innerHTML = listoCards.length ? listoCards.join('') : emptyOther;

    listEnv.scrollTop   = scrollPrevio[0];
    listPrep.scrollTop  = scrollPrevio[1];
    listListo.scrollTop = scrollPrevio[2];

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

    var minTxt = antiguedadTexto(min, (pendiente || itemList[0]).created_at);

    var h = '<div class="area-card' + urgClass + '">';
    h += '<div class="area-card__head">';
    h += '<span class="area-card__mesa">' + mesaTxt + clienteTxt + '</span>';
    h += '<span class="area-card__time">' + minTxt + '</span>';
    h += '</div>';
    h += '<div class="area-card__items">';

    for (var i = 0; i < itemList.length; i++) {
      var it = itemList[i];
      // El chip sólo sale cuando hay comensal. «GL» se emitía en TODAS las
      // filas para decir «global», que es el caso por defecto: 44 px por
      // platillo robados al nombre para no informar de nada.
      var comHtml = it.comensal !== null
        ? '<span class="area-card__com">C.' + escHtml(it.comensal) + '</span>'
        : '';
      // La columna de Listos es mixta, así que el estado se decide por ítem y
      // no por columna: lo entregado ya no admite ninguna acción.
      var entregado  = it.estado === 'entregado';
      var hasBack    = !entregado && (colType === 'prep' || colType === 'listo');
      var hasForward = !entregado && (colType === 'enviado' || colType === 'prep');

      /*
       * El platillo es UNA fila: ×N · nombre · comensal · acción.
       *
       * Los botones iban debajo del texto, y esa segunda fila de 48 px era más
       * de la mitad de los ~92 px que costaba cada platillo: en una columna de
       * 600 px cabían tres. Al lado del nombre, el botón deja de sumar altura y
       * sólo manda el objetivo táctil (44 px, el mínimo de la casa para el
       * piso). La nota es lo único que sigue bajando de línea, porque es lo
       * único de la comanda que el cocinero no puede pasar por alto.
       */
      h += '<div class="area-card__item' + (entregado ? ' area-card__item--entregado' : '') + '">';
      h += '<span class="area-card__qty">×' + it.cantidad + '</span>';
      h += '<span class="area-card__name">' + escHtml(it.nombre) + '</span>';
      h += comHtml;

      if (entregado) {
        h += '<span class="area-card__badge area-card__badge--entregado">' +
             svgIcon('check', 15) + '<span>Entregado</span></span>';
      } else if (hasBack || hasForward) {
        h += '<div class="area-card__item-btns">';
        if (hasBack) {
          // Sin etiqueta: "Devolver" es la acción rara de la fila y gastaba el
          // ancho que necesita el nombre del platillo. El destino se sabe por
          // la columna, y el aria-label lo dice para quien no ve la flecha.
          h += '<button class="area-card__btn area-card__btn--back" data-id="' +
               it.id + '" data-dir="back" aria-label="Devolver" title="Devolver">' +
               svgIcon('left', 17) + '</button>';
        }
        if (colType === 'enviado') {
          h += '<button class="area-card__btn area-card__btn--prep" data-id="' +
               it.id + '" data-dir="fwd">' + svgIcon('right', 16) + '<span>Prep</span></button>';
        } else if (colType === 'prep') {
          h += '<button class="area-card__btn area-card__btn--listo" data-id="' +
               it.id + '" data-dir="fwd">' + svgIcon('check', 16) + '<span>Listo</span></button>';
        }
        h += '</div>';
      }

      if (it.nota) {
        h += '<span class="area-card__nota">' + escHtml(it.nota) + '</span>';
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
    if (!document.body) return;
    var page = document.body.dataset.page;
    // La confirmación de salida vale para el tablero y para el selector de
    // estación: las dos llevan el header operativo con su formulario.
    if (page === 'area' || page === 'area-seleccion') initAreaLogoutConfirm();
    if (page === 'area') initArea();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryInitArea);
  } else {
    tryInitArea();
  }
})();
