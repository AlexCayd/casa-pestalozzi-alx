<?php
/**
 * Contenido del mapa de mesas dentro del shell operativo compartido.
 *
 * El header, drawer, maximización y layout viven en los parciales de
 * operation. Este archivo conserva únicamente el mapa POS, su leyenda y el
 * modal de mesa; los IDs mapa-* y #mesa-modal* son contrato con su adaptador.
 *
 * Para embeberlo en otra sección del proyecto, el contenedor debe:
 *   1) Tener en el <body> los hooks: class "mapa-page operational-page",
 *      data-page="mapa" y data-operational-page (para shell.js).
 *   2) Cargar los assets: /build/css/app.css, /build/js/bundle.min.js,
 *      /build/js/admin/map.js (que trae punto-de-venta.js).
 *   3) Emitir en línea, ANTES de esos <script src>, las globales
 *      window.CP_MENU, window.CP_AREAS y window.CP_USER. Es un contrato duro:
 *      el JS las lee de forma síncrona al abrir una mesa. Se arman con
 *      Services\Menu\Carta::paraPos() / ::areasPos() (antes venían del archivo
 *      escrito a mano src/js/data/menu-data.js, ya eliminado).
 *   4) Definir antes de incluir este partial las variables $h, $mapFecha,
 *      $datePickerHtml, $usuarioNombre y $usuarioRol.
 *
 * IMPORTANTE: los IDs mapa-*, #mesa-modal* y #pos-prefs-* son el contrato con
 * el JS; no renombrar. El overlay #pos-prefs-overlay debe emitirse siempre:
 * es donde vive el panel de ajustes del mesero (fuera del modal, que se
 * reescribe entero en cada apertura de mesa).
 */
require_once __DIR__ . '/../../admin/partials/_icons.php';
$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mapFecha = $mapFecha ?? date('Y-m-d');
$datePickerHtml = $datePickerHtml ?? '';
$usuarioNombre = $usuarioNombre ?? '';
$usuarioRol = $usuarioRol ?? 'Usuario';

// El enlace al panel, para quien entra al panel.
$esAdmin = \Classes\Auth::tienePanel();

/*
 * Fecha del cajón en cristiano.
 *
 * El ISO crudo ("2026-09-09") es el formato del contrato con el backend, no
 * algo que un mesero deba descifrar a mitad de servicio para saber si está
 * mirando el turno de hoy. Se escribe "Mié 9 de septiembre" y el ISO se
 * conserva en el atributo, que es lo que lee shell.js.
 *
 * Sin intl ni setlocale: la extensión no está garantizada en el servidor y
 * setlocale depende de qué locales tenga instalado el sistema — en Windows
 * devuelve los nombres en inglés. Dos tablas de siete y doce entradas resuelven
 * el idioma sin depender de nada.
 */
$posDiasCortos = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$posMeses = [
  1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
  5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
  9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];
$posFechaLegible = static function (string $iso) use ($posDiasCortos, $posMeses): string {
  $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
  // Una fecha que no parsea se devuelve tal cual: es preferible enseñar el ISO
  // a dejar el rótulo vacío.
  if (!$fecha instanceof \DateTimeImmutable) {
    return $iso;
  }
  return $posDiasCortos[(int) $fecha->format('w')]
    . ' ' . (int) $fecha->format('j')
    . ' de ' . ($posMeses[(int) $fecha->format('n')] ?? '');
};

$operationalView = 'map';
$operationalModule = 'tables';
/*
 * La barra del POS tiene cinco piezas, y cada una contesta una pregunta del
 * turno: ☰ las reservas, el MODO (qué momento mira el mapa), la pastilla (qué
 * cabe y qué hay que resolver), el avatar (quién soy y las herramientas) y
 * salir.
 *
 * Se va el wordmark, por la misma razón que en el tablero de área: quien mira
 * el POS ya sabe en qué restaurante está, y su sitio —el más legible de la
 * barra— lo toma el modo, que sí cambia lo que el mesero hace.
 *
 * Ajustes de la vista, la ayuda del mapa y el panel (sólo admin) se pliegan en
 * el avatar ($operationalHeaderMenuHtml, más abajo): se consultan de vez en
 * cuando, y sueltos eran tres círculos iguales que había que descifrar uno por
 * uno al lado de lo que se mira todo el turno.
 */
$operationalModuleTitle = '';
$operationalHeaderBrand = false;
$operationalShowLastUpdate = false;
// Sin desplegable de usuario «de los de antes»: salir sigue siendo su propio
// botón, a un toque —el modal de confirmación ya frena el toque accidental—.
// El avatar sólo abre herramientas de consulta.
$operationalUserMenu = false;
// La rejilla al panel vive ahora en el menú del avatar (sólo admin).
$operationalHeaderBack = false;
$operationalDate = $mapFecha;
$operationalHour = '';
$operationalBrandHref = '/punto-de-venta';
$operationalDrawerId = 'map-reservations-drawer';
$operationalActiveModule = 'map';
$operationalMapHref = '/punto-de-venta';
// Vive bajo /admin: a un mesero la guardia lo rebotaría, así que sólo se
// ofrece si quien mira es administrador.
// /admin y no /admin/analytics: un rol sin Análisis aterriza en su primer módulo.
$operationalAdminHref = $esAdmin ? '/admin' : '';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'mapa-shell pos-shell';
$operationalMainClass = 'mapa-body pos-body operational-layout';
$operationalMainId = '';
$operationalMainAttributes = ['aria-label' => 'Mapa de mesas', 'data-operational-main' => true];

ob_start();
?>
<?php /*
  Alertas de impresión. Nace oculto: sólo aparece cuando hay una comanda o una
  cuenta que no llegó a su impresora con el servicio encendido, y desaparece
  cuando alguien las atiende. Es lo único que se queda suelto junto al avatar
  porque es lo único de esa esquina que pide acción.
*/ ?>
<button type="button" class="pos-header__print-alerts" id="pos-print-alerts-toggle" hidden
        aria-haspopup="dialog" aria-expanded="false" aria-controls="pos-print-alerts"
        aria-label="Alertas de impresión" title="Alertas de impresión">
  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path d="M6 9V3h12v6"/>
    <rect x="3" y="9" width="18" height="8" rx="2"/>
    <path d="M6 14h12v7H6z"/>
  </svg>
  <span class="pos-header__print-alerts-count" id="pos-print-alerts-count">0</span>
</button>
<?php
$operationalHeaderActionsHtml = (string)ob_get_clean();

/*
 * Menú del avatar. Los ids y atributos son contrato: #pos-prefs-toggle lo
 * engancha punto-de-venta.js y [data-map-help-open][aria-controls] lo resuelve
 * map-help.js por delegación. Al elegir una opción, shell.js cierra el menú y
 * devuelve el foco al avatar ANTES de que se abra el diálogo, así que al
 * cerrarlo el foco vuelve a un botón visible.
 */
$operationalHeaderMenuHtml = '<button type="button" class="operational-user-menu__item" id="pos-prefs-toggle" role="menuitem"'
  . ' aria-haspopup="dialog" aria-expanded="false" aria-controls="pos-prefs-overlay">'
  . admin_icon('configuration', 18) . '<span>Ajustes de la vista</span></button>'
  . '<button type="button" class="operational-user-menu__item" role="menuitem" data-map-help-open'
  . ' aria-haspopup="dialog" aria-controls="map-help-dialog-mapa-mesas">'
  . admin_icon('ayuda', 18) . '<span>Cómo leer el mapa</span></button>'
  . ($esAdmin
    ? '<a class="operational-user-menu__item" role="menuitem" href="/admin/punto-de-venta">'
      . admin_icon('panel', 18) . '<span>Panel de administración</span></a>'
    : '');

/*
 * El contexto del mapa (Ahora / la hora proyectada, libres, próxima llegada y
 * reservas sin mesa) vive en el CENTRO de la barra. Como fila propia entre la
 * barra y el plano le quitaba al salón el alto de una fila entera.
 */
ob_start();
include __DIR__ . '/pos-contexto.php';
$operationalHeaderCenterHtml = (string)ob_get_clean();

ob_start();
?>
  <div class="pos-map" data-operational-workspace>
  <?php /*
    Barra de selección múltiple. Aparece solo dentro del modo selección, que
    ahora arranca desde "Unir mesas" en el modal de la mesa. Los IDs son
    contrato con punto-de-venta.js; no renombrar.
  */ ?>
  <div class="pos-ticket-selection-bar" id="pos-ticket-selection-bar" hidden>
    <p class="pos-ticket-selection-message" id="pos-ticket-selection-message" role="status" tabindex="-1">
      Selecciona una o m&aacute;s mesas para abrir el ticket.
    </p>
    <div class="pos-ticket-selection-bar__actions">
      <?php /* Neutro, no rojo: salir de un modo de selección no destruye nada. */ ?>
      <button type="button" class="operational-map-action" id="pos-ticket-selection-cancel">
        Cancelar
      </button>
      <button type="button" class="operational-map-action operational-map-action--primary" id="pos-ticket-selection-toggle">
        Confirmar apertura
      </button>
    </div>
    <?php /* «Cambiar mesas»: al tocar una mesa libre cuando la reserva ya
             tiene lugar suficiente, se pregunta aquí mismo si la sustituye o
             se suma. Contrato con punto-de-venta.js: [data-asignacion-eleccion]. */ ?>
    <div class="pos-ticket-selection-bar__actions pos-ticket-selection-bar__pregunta" id="pos-asignacion-pregunta" hidden>
      <button type="button" class="operational-map-action" data-asignacion-eleccion="anadir">Añadir</button>
      <button type="button" class="operational-map-action operational-map-action--primary" data-asignacion-eleccion="cambiar">Cambiar</button>
    </div>
  </div>
  <?php
  // Sin encabezado ni botonera: el mapa es la pantalla del mesero y cada
  // rótulo o columna de acciones le quitaba ancho útil al salón. 'titleId'
  // debe ir vacío o map.php dejaría un aria-labelledby apuntando a la nada.
  $mapVisual = [
    'context' => 'mapa-mesas',
    'helpContext' => 'pos',
    'sectionClass' => 'mapa-operational-map',
    'titleId' => '',
    'title' => '',
    'subtitle' => '',
    'ariaLabel' => 'Mapa de mesas',
    'canvasId' => 'mapa-canvas',
    'canvasMode' => 'map',
    'loadingMode' => 'overlay',
    // La ayuda vive en el menú del avatar: flotando sobre el plano se
    // montaba encima de la Mesa 8 en cuanto la ficha estrechaba el mapa.
    'helpPosition' => 'externo',
    // Sin leyenda: el color de cada mesa ya dice su estado y la lista de
    // abreviaturas ocupaba más que el propio mapa.
    'legendPosition' => 'none',
  ];
  include __DIR__ . '/../../operation/partials/map.php';
  include __DIR__ . '/pos-ficha.php';
  include __DIR__ . '/pos-alta.php';
  ?>
</div>

<?php
$operationalDrawerTitleId = 'map-reservations-title';
$operationalDrawerClass = 'mapa-sidebar';
$operationalDrawerAttributes = [];
// «Reservaciones» a secas: la fecha la dice la fila de abajo, y con «del día»
// el título partía en dos líneas a 340 px.
$operationalDrawerTitle = 'Reservaciones';
// Sin navegación de módulos: sólo quedaría «Mesas», que es esta página.
$operationalDrawerModules = false;
$operationalDrawerDateHtml = '';
$operationalDrawerCountHtml = '<span class="mapa-reserva-count" id="mapa-reserva-count">—</span>';
/*
 * Fecha plegada: «‹ Hoy · Vie 9 de octubre ›». El calendario sigue en flujo
 * (CLAUDE.md) pero ya no está abierto siempre: abierto se comía ~370 px y a
 * 1024×768 dejaba lista para una tarjeta y media. El ISO viaja en data-iso,
 * que es lo que lee y reescribe shell.js.
 */
$posHoyIso = \Services\Reservations\ReservacionConfig::fechaActual();
$posRelativo = static function (string $iso) use ($posHoyIso): string {
  $hoy = \DateTimeImmutable::createFromFormat('!Y-m-d', $posHoyIso);
  $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
  if (!$hoy || !$dia) {
    return '';
  }
  $diferencia = (int) $hoy->diff($dia)->format('%r%a');
  return [-1 => 'Ayer · ', 0 => 'Hoy · ', 1 => 'Mañana · '][$diferencia] ?? '';
};
$operationalDrawerSlotHtml = '<div class="pos-cajon-fecha" data-cajon-fecha>'
  . '<button type="button" class="pos-btn-redondo pos-btn-redondo--icono" data-cajon-dia="-1" aria-label="Día anterior">'
  . admin_icon('chevron-izquierda', 18) . '</button>'
  . '<button type="button" class="pos-cajon-fecha__dia" data-cajon-calendario aria-expanded="false" aria-controls="pos-cajon-calendario">'
  . '<span class="pos-cajon-fecha__rel" data-cajon-relativo>' . $h($posRelativo((string) $mapFecha)) . '</span>'
  . '<span data-operational-map-date data-iso="' . $h($mapFecha) . '">' . $h($posFechaLegible((string) $mapFecha)) . '</span>'
  . '</button>'
  . '<button type="button" class="pos-btn-redondo pos-btn-redondo--icono" data-cajon-dia="1" aria-label="Día siguiente">'
  . admin_icon('chevron-derecha', 18) . '</button>'
  . '</div>'
  . '<div class="pos-drawer-date" id="pos-cajon-calendario" hidden>' . $datePickerHtml . '</div>';
$operationalDrawerFooterHtml = '<button type="button" class="mmodal-btn mmodal-btn--primary pos-cajon__nueva" data-alta-abrir data-cierra-cajon>'
  . admin_icon('mas', 18) . '<span>Nueva reservación</span></button>';
$operationalDrawerListId = 'mapa-reservas-list';
$operationalDrawerListClass = 'mapa-reservas-list';
$operationalDrawerListAttributes = [];
$operationalDrawerListHtml = '<div class="mapa-empty-state"><span class="mapa-empty-icon" aria-hidden="true">'
  . '<span class="mapa-empty-spinner"></span></span><span>Cargando…</span></div>';
include __DIR__ . '/../../operation/partials/drawer.php';

?>

<div class="mesa-modal" id="mesa-modal" role="dialog" aria-modal="true" aria-hidden="true" inert aria-labelledby="mesa-modal-title" tabindex="-1">
  <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
  <div class="mesa-modal__panel">
    <div class="mesa-modal__handle"></div>
    <?php /* SVG y no el glifo «×»: lo pintaba la fuente del sistema, así que no
             casaba de grosor ni de tamaño con el icono de al lado —que sí es un
             SVG— y era lo que más desordenaba esa esquina. */ ?>
    <button type="button" class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar detalle de mesa"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6 6 18"/></svg></button>
    <div id="mesa-modal-content"></div>
  </div>
</div>

<?php /*
  Preferencias del mesero. Va fuera de #mesa-modal: el modal se abre y cierra
  de forma independiente y #mesa-modal-content se reescribe en cada apertura.
*/ ?>
<div class="pos-prefs" id="pos-prefs-overlay" hidden aria-hidden="true">
  <div class="pos-prefs__bd" id="pos-prefs-bd"></div>
  <div class="pos-prefs__dialog" role="dialog" aria-modal="true" aria-labelledby="pos-prefs-title">
    <header class="pos-prefs__head">
      <h3 class="pos-prefs__title" id="pos-prefs-title">Ajustes de la vista</h3>
      <button type="button" class="pos-prefs__close" id="pos-prefs-close" aria-label="Cerrar ajustes">×</button>
    </header>
    <div class="pos-prefs__body mmodal-prefs" id="pos-prefs-panel"></div>
  </div>
</div>
<?php /*
  Bandeja de alertas de impresión. Reutiliza la caja del overlay de ajustes
  (.pos-prefs): mismo z-index por encima del modal de mesa, porque la alerta
  puede llegar con una mesa abierta.
*/ ?>
<div class="pos-prefs pos-prefs--alertas" id="pos-print-alerts" hidden aria-hidden="true">
  <div class="pos-prefs__bd" data-print-alerts-close></div>
  <div class="pos-prefs__dialog" role="dialog" aria-modal="true" aria-labelledby="pos-print-alerts-title">
    <header class="pos-prefs__head">
      <div class="pos-print-alerts__heading">
        <h3 class="pos-prefs__title" id="pos-print-alerts-title">Alertas de impresión</h3>
        <p class="pos-print-alerts__sub" id="pos-print-alerts-sub"></p>
      </div>
      <button type="button" class="pos-prefs__close" data-print-alerts-close aria-label="Cerrar alertas">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </header>
    <ul class="pos-prefs__body pos-print-alerts__list" id="pos-print-alerts-list" data-lenis-prevent></ul>
    <footer class="pos-print-alerts__foot">
      <button type="button" class="mmodal-btn mmodal-btn--secondary" id="pos-print-alerts-all">
        Marcar todas como atendidas
      </button>
    </footer>
  </div>
</div>
<?php
$operationalContentHtml = (string)ob_get_clean();
include __DIR__ . '/../../operation/partials/shell.php';
?>
