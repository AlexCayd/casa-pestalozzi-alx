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

  // AREA_COLOR se retiró: no lo leía ninguna otra línea. El color de la
  // estación lo pinta el CSS desde --area-accent (views/area/index.php y
  // src/scss/area/_area.scss); el JS no lo necesita.
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
  // `banda:id` de cada platillo pintado, en esta pasada y en la anterior: es
  // como se sabe qué acaba de llegar a una banda (ver buildCard).
  var vistos = null;
  var vistosAntes = null;
  var tablero = listEnv.closest('.area-board');

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

  // Ámbar desde los 5 minutos, rojo desde los 10. Sólo la hora de la cabecera
  // lo dice (ver _area.scss, .area-card__time).
  function nivelDeEspera(min) {
    return min >= 10 ? 'urgente' : min >= 5 ? 'alerta' : '';
  }

  /*
   * La firma es la ESTRUCTURA del tablero (qué platillo está en qué estado), y
   * el reloj va aparte.
   *
   * Estuvo con el minuto dentro para que «hace 2 min» no se congelara, y eso
   * reconstruía el tablero entero una vez por minuto: cada comanda se destruía
   * y se volvía a crear, se volvía a empaquetar, el foco del teclado se perdía
   * y un toque que cayera justo en ese instante (mousedown en el nodo viejo,
   * mouseup en el nuevo) no llegaba a ser click. Ahora, si la estructura no
   * cambió, sólo se reescriben las horas en su sitio (actualizarTiempos): la
   * cabecera mide una línea pase lo que pase, así que no mueve nada.
   *
   * Entra todo lo que la comanda PINTA salvo el reloj: un ticket que el mesero
   * cambia de mesa o al que le pone nombre después tiene que verse, y con
   * `id:estado` a secas se quedaría con la mesa vieja hasta el siguiente toque.
   */
  function firmaDe(lista) {
    var partes = [];
    for (var i = 0; i < lista.length; i++) {
      var it = lista[i];
      partes.push([it.id, it.estado, it.cantidad, it.mesa_nombre, it.ticket_nombre,
                   it.nombre, it.variante, it.nota, it.comensal].join('\u0001'));
    }
    return partes.join('\u0002');
  }

  function actualizarTiempos() {
    [listEnv, listPrep, listListo].forEach(function(riel) {
      var horas = riel.querySelectorAll('.area-card__time[data-desde]');
      for (var i = 0; i < horas.length; i++) {
        var hora = horas[i];
        var desde = hora.getAttribute('data-desde');
        var min = minutosDesde(desde);
        var texto = antiguedadTexto(min, desde);
        if (hora.textContent !== texto) hora.textContent = texto;
        var comanda = hora.closest('.area-card');
        if (!comanda) continue;
        var nivel = hora.hasAttribute('data-pendiente') ? nivelDeEspera(min) : '';
        comanda.classList.toggle('area-card--alerta', nivel === 'alerta');
        comanda.classList.toggle('area-card--urgente', nivel === 'urgente');
      }
    });
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

  /*
   * El riel se ordena por LLEGADA, no por ticket.
   *
   * La consulta viene por ticket_id, y con ese orden el postre que la mesa 2
   * pide ahora —ticket abierto hace una hora— se colaba a la izquierda de
   * comandas que llevan doce minutos esperando, y empujaba todas las de su
   * derecha un puesto bajo el dedo. Por hora del platillo pendiente más viejo
   * de la banda, lo nuevo entra siempre por el final y lo que ya está a la vista
   * no se mueve.
   *
   * Una comanda sin nada pendiente (en Listos: todo entregado) no tiene
   * llegada y va al final: es registro, no trabajo, y no puede ocupar el
   * ancho que necesita lo que hay que recoger. El desempate es el orden de la
   * consulta, para que dos comandas de la misma hora no se crucen.
   */
  function tarjeta(group, lista, colType, orden) {
    var llegada = null;
    for (var i = 0; i < lista.length; i++) {
      if (lista[i].estado === 'entregado') continue;
      if (llegada === null || lista[i].created_at < llegada) llegada = lista[i].created_at;
    }
    return { html: buildCard(group, lista, colType), llegada: llegada, orden: orden };
  }

  function porLlegada(a, b) {
    if (a.llegada === b.llegada) return a.orden - b.orden;
    if (a.llegada === null) return 1;
    if (b.llegada === null) return -1;
    // 'AAAA-MM-DD HH:MM:SS' se ordena bien como texto.
    return a.llegada < b.llegada ? -1 : 1;
  }

  function htmlDe(tarjetas) {
    var partes = [];
    for (var i = 0; i < tarjetas.length; i++) partes.push(tarjetas[i].html);
    return partes.join('');
  }

  function renderBoard() {
    var firma = firmaDe(items);
    if (firma === lastSignature) {
      actualizarTiempos();
      return;
    }
    lastSignature = firma;

    // Lo pintado en la pasada anterior, para saber qué platillo acaba de
    // llegar a cada banda (ver buildCard). En la primera pasada no hay
    // «antes» y nada se marca: todo llega a la vez y no es noticia.
    vistosAntes = vistos;
    vistos = {};

    // Agrupar por ticket_id
    var byTicket = {};
    var ticketOrder = [];
    var ticketsPorMesa = {};
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (!byTicket[it.ticket_id]) {
        byTicket[it.ticket_id] = {
          ticket_id:     it.ticket_id,
          mesa_nombre:   it.mesa_nombre,
          ticket_nombre: it.ticket_nombre,
          folio:         false,
          enviados:      [],
          prep:          [],
          listos:        []
        };
        ticketOrder.push(it.ticket_id);
        ticketsPorMesa[it.mesa_nombre] = (ticketsPorMesa[it.mesa_nombre] || 0) + 1;
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

    /*
     * Llevar y las barras son ventanillas: varios pedidos abiertos sobre la
     * MISMA mesa, y todos se llaman «Llevar». Dos comandas tituladas igual en
     * la misma banda no se pueden entregar a nadie, así que en cuanto el nombre
     * se repite la comanda lleva el folio, que es como el POS los distingue
     * («Llevar #N», folioPedido() en punto-de-venta.js). Una mesa del salón
     * nunca se repite, y ahí el folio sería ruido con aire de otra cosa.
     */
    for (var tf = 0; tf < ticketOrder.length; tf++) {
      var conFolio = byTicket[ticketOrder[tf]];
      conFolio.folio = ticketsPorMesa[conFolio.mesa_nombre] > 1;
    }

    for (var ti = 0; ti < ticketOrder.length; ti++) {
      var group = byTicket[ticketOrder[ti]];
      if (group.enviados.length) {
        envCards.push(tarjeta(group, group.enviados, 'enviado', ti));
        envCount += group.enviados.length;
      }
      if (group.prep.length) {
        prepCards.push(tarjeta(group, group.prep, 'prep', ti));
        prepCount += group.prep.length;
      }
      if (group.listos.length) {
        // Dentro de la comanda, lo que espera a que lo recojan va delante y lo
        // ya entregado detrás: así la primera columna es la que importa.
        var porRecoger = [];
        var entregados = [];
        for (var li = 0; li < group.listos.length; li++) {
          if (group.listos[li].estado === 'listo') porRecoger.push(group.listos[li]);
          else entregados.push(group.listos[li]);
        }
        listoCards.push(tarjeta(group, porRecoger.concat(entregados), 'listo', ti));
        // El contador mide trabajo por recoger, no filas visibles: lo ya
        // entregado no cuenta, así que a propósito no coincide con lo pintado.
        listoCount += porRecoger.length;
      }
    }

    envCards.sort(porLlegada);
    prepCards.sort(porLlegada);
    listoCards.sort(porLlegada);

    var vacioIcono = '<span class="area-empty__icon" aria-hidden="true">' + svgIcon('idle', 22) + '</span>';
    var emptyEnv   = '<div class="area-empty">' + vacioIcono + '<span>Sin pedidos</span></div>';
    var emptyOther = '<div class="area-empty">' + vacioIcono + '<span>Sin pedidos</span></div>';

    /*
     * Lo único que desplaza es el tablero entero (nada por dentro de una banda
     * ni de una comanda: ver _area.scss), y su scrollTop NO sobrevive solo: al
     * medir, empaquetar() fuerza un layout con las comandas recién pintadas
     * todavía sin colocar —filas de 4 px, bandas casi vacías—, el tablero
     * encoge un instante y el navegador recorta el desplazamiento, a menudo a
     * cero. Medido: con el tablero bajado 60 px, cada repintado lo devolvía
     * arriba. Por eso se guarda y se repone. Encima de eso, la banda en la que
     * se está trabajando se sostiene en su sitio: ver anclaDeToque().
     */
    var foco  = recordarFoco();
    var ancla = anclaDeToque();
    var desplazado = tablero ? tablero.scrollTop : 0;

    listEnv.innerHTML   = envCards.length   ? htmlDe(envCards)   : emptyEnv;
    listPrep.innerHTML  = prepCards.length  ? htmlDe(prepCards)  : emptyOther;
    listListo.innerHTML = listoCards.length ? htmlDe(listoCards) : emptyOther;

    empaquetar();
    if (tablero) tablero.scrollTop = desplazado;
    sostenerAncla(ancla);
    restaurarFoco(foco);

    if (countEnv)   countEnv.textContent   = envCount;
    if (countPrep)  countPrep.textContent  = prepCount;
    if (countListo) countListo.textContent = listoCount;
  }

  /*
   * Empaquetado de las comandas (_area.scss, .area-banda__riel.is-empaquetado):
   * cada una ocupa tantas filas de 4 px como mide más su margen, y cae en lo
   * alto de la columna más baja. Así las cortas suben a rellenar lo que deja
   * una larga, sin la fila común que fijaba la más alta.
   *
   * ⚠ La colocación la decide este código, comanda por comanda, y NO la
   * rejilla con `dense`. Con `dense` cada repintado recolocaba todo desde
   * cero: el cocinero mandaba a Prep un platillo de la mesa 2, esa comanda
   * encogía 49 px y otra comanda tres puestos más allá cambiaba de COLUMNA —
   * justo la que iba a tocar después—. Ahora cada comanda recuerda su columna
   * (por ticket) mientras siga en la banda: si algo encima de ella crece o
   * encoge, se desliza en vertical dentro de su columna, que es un movimiento
   * que se sigue con la vista, y nunca salta de lado. Las nuevas entran en la
   * columna más baja; las que se van dejan subir a las de debajo.
   *
   * La fidelidad tiene un tope (DESVIO_MAX): si recordar columnas deja el
   * reparto más de 120 px más alto que colocarlo de nuevo, se recoloca. Ese
   * alto es scroll que el cocinero pagaría en cada vistazo.
   *
   * Primero se suelta la colocación anterior, después se mide todo y al final
   * se escribe: medir y escribir comanda por comanda recalcularía el tablero
   * en cada una. La medida vale en cualquier estado de la rejilla, porque una
   * comanda alineada arriba mide lo que su contenido y su ancho sólo depende de
   * cuántas pistas ocupa.
   *
   * Una banda vacía pierde la clase: el aviso «Sin pedidos» no tiene filas que
   * ocupar y se saldría por abajo.
   */
  var FILA = 4;         // = grid-auto-rows de .is-empaquetado
  var HUECO = 12;       // = margin-bottom de la comanda empaquetada
  var DESVIO_MAX = 30;  // filas de 4 px: 120 px
  var columnasPorRiel = {};

  // Las pistas que la rejilla tiene de verdad: auto-fill las decide el CSS, y
  // el valor computado de una rejilla ya maquetada es la lista en px.
  function pistasDe(riel) {
    var plantilla = window.getComputedStyle(riel).gridTemplateColumns || '';
    if (!plantilla || plantilla === 'none') return 1;
    return Math.max(1, plantilla.trim().split(/\s+/).length);
  }

  // Cuántas pistas pide la comanda: lo decide el CSS (@container riel), así
  // que aquí sólo se lee. Va en grid-column-END para que poner la columna de
  // inicio desde aquí no se lo lleve por delante.
  function spanDe(comanda) {
    var m = /span\s+(\d+)/.exec(window.getComputedStyle(comanda).gridColumnEnd || '');
    return m ? parseInt(m[1], 10) : 1;
  }

  function techoDe(alturas, col, ancho) {
    var techo = 0;
    for (var j = col; j < col + ancho; j++) if (alturas[j] > techo) techo = alturas[j];
    return techo;
  }

  // Pura (sin DOM): comandas = [{clave, span, filas}] en orden de llegada;
  // previas = {clave: columna} de la pasada anterior.
  function repartir(pistas, comandas, previas) {
    var alturas = [], lugares = [], columnas = {}, alto = 0, i, j, k;
    for (i = 0; i < pistas; i++) alturas.push(0);
    for (i = 0; i < comandas.length; i++) {
      var c = comandas[i];
      var ancho = Math.min(c.span, pistas);
      var col = Object.prototype.hasOwnProperty.call(previas, c.clave) ? previas[c.clave] : -1;
      if (col < 0 || col + ancho > pistas) {
        var mejor = Infinity;
        for (k = 0; k + ancho <= pistas; k++) {
          var t = techoDe(alturas, k, ancho);
          if (t < mejor) { mejor = t; col = k; }
        }
      }
      var fila = techoDe(alturas, col, ancho);
      for (j = col; j < col + ancho; j++) alturas[j] = fila + c.filas;
      lugares.push({ col: col, fila: fila });
      columnas[c.clave] = col;
    }
    for (i = 0; i < pistas; i++) if (alturas[i] > alto) alto = alturas[i];
    return { lugares: lugares, columnas: columnas, alto: alto };
  }

  function empaquetar() {
    var grupos = [], i, j, g, el;

    // 1. Soltar: con la columna vieja puesta, una comanda que ya no cabe
    //    crearía pistas implícitas y falsearía la cuenta y el ancho.
    [listEnv, listPrep, listListo].forEach(function(riel) {
      var comandas = riel.querySelectorAll(':scope > .area-card');
      for (var c = 0; c < comandas.length; c++) {
        comandas[c].style.gridColumnStart = '';
        comandas[c].style.gridRowStart = '';
      }
      grupos.push({ riel: riel, comandas: comandas });
    });

    // 2. Medir.
    for (i = 0; i < grupos.length; i++) {
      g = grupos[i];
      g.pistas = pistasDe(g.riel);
      g.datos = [];
      for (j = 0; j < g.comandas.length; j++) {
        el = g.comandas[j];
        g.datos.push({
          clave: el.getAttribute('data-clave') || String(j),
          span:  spanDe(el),
          filas: Math.ceil((el.getBoundingClientRect().height + HUECO) / FILA)
        });
      }
    }

    // 3. Colocar.
    for (i = 0; i < grupos.length; i++) {
      g = grupos[i];
      if (!g.comandas.length) {
        g.riel.classList.remove('is-empaquetado');
        delete columnasPorRiel[g.riel.id];
        continue;
      }
      var previo = columnasPorRiel[g.riel.id];
      var fiel  = repartir(g.pistas, g.datos, previo && previo.pistas === g.pistas ? previo.columnas : {});
      var libre = repartir(g.pistas, g.datos, {});
      var plan  = fiel.alto - libre.alto > DESVIO_MAX ? libre : fiel;
      columnasPorRiel[g.riel.id] = { pistas: g.pistas, columnas: plan.columnas };

      for (j = 0; j < g.comandas.length; j++) {
        var estilo = g.comandas[j].style;
        estilo.gridColumnStart = String(plan.lugares[j].col + 1);
        estilo.gridRowStart    = String(plan.lugares[j].fila + 1);
        estilo.gridRowEnd      = 'span ' + g.datos[j].filas;
      }
      g.riel.classList.add('is-empaquetado');
    }
  }

  /*
   * La banda en la que se está trabajando no se mueve bajo el dedo.
   *
   * Las bandas están apiladas, así que una comanda que llega a Enviados empuja
   * hacia abajo Prep y Listos. Con el tablero desplazado, eso es la fila que el
   * cocinero iba a tocar bajando una comanda entera justo antes del toque. Si
   * tocó una banda hace menos de ocho segundos, se compensa el desplazamiento
   * para que esa banda se quede donde estaba; lo de arriba crece fuera de la
   * vista y el contador de su lomo lo cuenta. Pasado ese rato no se compensa:
   * quien no está tocando vuelve a mirar el tablero entero.
   *
   * Por banda y no por comanda porque las bandas son lo único que el repintado
   * no destruye (el scroll anchoring del navegador se ancla a un nodo de dentro,
   * que desaparece con el innerHTML, y Safari ni siquiera lo tiene).
   */
  var toque = { banda: null, en: 0 };
  if (tablero) {
    tablero.addEventListener('pointerdown', function(event) {
      var banda = event.target.closest ? event.target.closest('.area-banda') : null;
      if (!banda) return;
      toque.banda = banda;
      toque.en = Date.now();
    }, { passive: true });
  }

  function anclaDeToque() {
    if (!tablero || !toque.banda || Date.now() - toque.en > 8000) return null;
    return { banda: toque.banda, top: toque.banda.getBoundingClientRect().top };
  }

  function sostenerAncla(ancla) {
    if (!ancla) return;
    var deriva = ancla.banda.getBoundingClientRect().top - ancla.top;
    if (Math.abs(deriva) >= 1) tablero.scrollTop += deriva;
  }

  /*
   * El foco del teclado sobrevive al repintado. El innerHTML destruye el botón
   * enfocado y el foco caía al <body>: con teclado había que volver a tabular
   * desde la barra después de cada platillo. Se devuelve al mismo botón si
   * sigue en su banda o, si el platillo se fue (que es lo normal: se acaba de
   * avanzar), al botón que ocupa su lugar en la misma comanda.
   */
  function recordarFoco() {
    var activo = document.activeElement;
    if (!activo || !activo.classList || !activo.classList.contains('area-card__btn')) return null;
    var riel = activo.closest('.area-banda__riel');
    var comanda = activo.closest('.area-card');
    if (!riel || !comanda) return null;
    var botones = comanda.querySelectorAll('.area-card__btn');
    return {
      riel:   riel,
      clave:  comanda.getAttribute('data-clave'),
      id:     activo.getAttribute('data-id'),
      dir:    activo.getAttribute('data-dir'),
      indice: Array.prototype.indexOf.call(botones, activo)
    };
  }

  function restaurarFoco(foco) {
    if (!foco || (document.activeElement && document.activeElement !== document.body)) return;
    var comanda = foco.riel.querySelector('.area-card[data-clave="' + foco.clave + '"]');
    if (!comanda) return;
    var botones = comanda.querySelectorAll('.area-card__btn');
    var destino = comanda.querySelector('.area-card__btn[data-id="' + foco.id + '"][data-dir="' + foco.dir + '"]') ||
                  botones[Math.min(Math.max(foco.indice, 0), botones.length - 1)];
    if (destino) destino.focus({ preventScroll: true });
  }

  // El alto de cada comanda depende del ancho de su celda (los nombres parten
  // distinto) y de la fuente: se vuelve a empaquetar cuando cambia cualquiera.
  var reempaque = 0;
  function empaquetarPronto() {
    window.cancelAnimationFrame(reempaque);
    reempaque = window.requestAnimationFrame(empaquetar);
  }
  if (typeof window.ResizeObserver === 'function') {
    var anchoPrevio = 0;
    new window.ResizeObserver(function(entradas) {
      var ancho = entradas[0].contentRect.width;
      if (Math.abs(ancho - anchoPrevio) < 1) return;
      anchoPrevio = ancho;
      empaquetarPronto();
    }).observe(listEnv);
  } else {
    window.addEventListener('resize', empaquetarPronto);
  }
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(empaquetarPronto);
  }

  function buildCard(group, itemList, colType) {
    // La antigüedad se mide sobre lo que sigue pendiente. Con ORDER BY
    // created_at ASC el más viejo suele ser justo el ya entregado, y tomarlo
    // pintaría la tarjeta en rojo por trabajo que ya está hecho.
    var pendiente = null;
    for (var p = 0; p < itemList.length; p++) {
      if (itemList[p].estado !== 'entregado') { pendiente = itemList[p]; break; }
    }

    var desde    = (pendiente || itemList[0]).created_at;
    var min      = minutosDesde(desde);
    var nivel    = pendiente ? nivelDeEspera(min) : '';
    var urgClass = nivel ? ' area-card--' + nivel : '';

    // El folio y el nombre del cliente van pegados a lo que identifican
    // (&nbsp;): la raya sola al final de un renglón, o «#N» separado de
    // «Llevar», se leen como otra cosa.
    var mesaTxt = escHtml(group.mesa_nombre) +
      (group.folio ? '&nbsp;<span class="area-card__folio">#' + escHtml(group.ticket_id) + '</span>' : '');
    var clienteTxt = group.ticket_nombre
      ? ' <span class="area-card__cliente">—&nbsp;' + escHtml(group.ticket_nombre) + '</span>'
      : '';

    var minTxt = antiguedadTexto(min, desde);

    /*
     * Una comanda larga se ensancha en vez de estirarse (_area.scss,
     * .area-card--larga): dos celdas desde seis filas, tres desde once. Se
     * cuenta, no se mide —nada que recalcular al repintar ni al girar la
     * tablet—: una nota vale media fila más, y un platillo ya entregado media
     * fila, porque sin botón su renglón mide la mitad.
     */
    var filas = 0;
    for (var f = 0; f < itemList.length; f++) {
      filas += (itemList[f].estado === 'entregado' ? 0.5 : 1) + (itemList[f].nota ? 0.5 : 0);
    }
    var largo = filas >= 11 ? ' area-card--muy-larga'
              : filas >= 6  ? ' area-card--larga' : '';

    var h = '<div class="area-card' + urgClass + largo + '" data-clave="' + escHtml(group.ticket_id) + '">';
    h += '<div class="area-card__head">';
    h += '<span class="area-card__mesa">' + mesaTxt + clienteTxt + '</span>';
    // data-desde lleva la hora de la que se cuenta: actualizarTiempos() la
    // reescribe cada minuto sin repintar la comanda.
    h += '<span class="area-card__time" data-desde="' + escHtml(desde) + '"' +
         (pendiente ? ' data-pendiente' : '') + '>' + minTxt + '</span>';
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
       * Acuse de llegada: el platillo que no estaba en esta banda en la pasada
       * anterior —el que el cocinero acaba de avanzar, el que devolvió, o la
       * comanda nueva que mandó el mesero— se enciende un instante y se apaga
       * (_area.scss, .area-card__item--llega). Con las bandas apiladas el
       * destino de un toque queda en otra franja de la pantalla, y ésta es la
       * respuesta que dice dónde cayó. Entregar no lo dispara: lo entregado
       * sigue en Listos, no llega a ningún sitio.
       */
      var claveVista = colType + ':' + it.id;
      var llega = vistosAntes !== null && !vistosAntes[claveVista];
      vistos[claveVista] = true;

      // Lo que el botón hace Y a qué platillo: «Listo» repetido doce veces no
      // dice nada a quien no ve la fila.
      var queEs = escHtml(it.cantidad + ' ' + it.nombre + (it.variante ? ', ' + it.variante : ''));

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
      h += '<div class="area-card__item' + (entregado ? ' area-card__item--entregado' : '') +
           (llega ? ' area-card__item--llega' : '') + '">';
      h += '<span class="area-card__qty">×' + it.cantidad + '</span>';
      // La variante va DENTRO del nombre y en su mismo peso: «con almendra»
      // es parte de lo que se prepara, no una nota al margen.
      // El chip de comensal va DENTRO del nombre, detrás del texto, y no en
      // una columna propia: en una comanda de 320 px con dos botones, esa
      // columna dejaba al nombre en «Enchilad…».
      h += '<span class="area-card__name">' + escHtml(it.nombre) + (comHtml ? ' ' + comHtml : '') +
           (it.variante ? '<span class="area-card__variante">' + escHtml(it.variante) + '</span>' : '') +
           '</span>';

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
               it.id + '" data-dir="back" aria-label="Devolver: ' + queEs + '" title="Devolver">' +
               svgIcon('left', 17) + '</button>';
        }
        // El nombre accesible EMPIEZA por la palabra visible («Prep», «Listo»):
        // quien dicta la orden por voz dice lo que ve.
        if (colType === 'enviado') {
          h += '<button class="area-card__btn area-card__btn--prep" data-id="' +
               it.id + '" data-dir="fwd" aria-label="Prep: ' + queEs + '">' +
               svgIcon('right', 16) + '<span>Prep</span></button>';
        } else if (colType === 'prep') {
          h += '<button class="area-card__btn area-card__btn--listo" data-id="' +
               it.id + '" data-dir="fwd" aria-label="Listo: ' + queEs + '">' +
               svgIcon('check', 16) + '<span>Listo</span></button>';
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

  // Delegación: los listeners viven en las bandas, que nunca se reemplazan.
  // Atarlos a cada botón obligaba a re-atarlos en cada repintado y dejaba
  // huecos donde el click se perdía.
  [listEnv, listPrep, listListo].forEach(function(riel) {
    riel.addEventListener('click', function(event) {
      var btn = event.target.closest('.area-card__btn[data-id]');
      if (!btn || !riel.contains(btn)) return;
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
