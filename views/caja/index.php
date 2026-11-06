<?php
/**
 * Caja de mostrador de Panadería (/panaderia/caja).
 *
 * Mismo shell de piso que el POS de Restaurante (header, tema oscuro forzado,
 * voz tipográfica), sin mapa: el mostrador ES la pantalla. A la izquierda la
 * vitrina —una tecla por pan, partida en sus opciones—; a la derecha el
 * ticket y el cobro. Todo lo dinámico lo pinta src/js/caja/caja.js a partir
 * de CP_CAJA, que el controlador emite en línea para que la primera tecla
 * responda sin esperar una petición.
 */
require_once __DIR__ . '/../admin/partials/_icons.php';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
\Classes\Auth::start();
$usuarioNombre = trim((string)($_SESSION['nombre'] ?? ''));
$esAdmin = \Classes\Auth::tienePanel();
$estado = json_decode((string)$estadoJson, true) ?: [];
$permisos = $estado['permisos'] ?? ['vender' => false, 'hornear' => false, 'anular' => false];
$usuarioRol = $permisos['anular'] ? 'Encargado de caja' : ($permisos['vender'] ? 'Caja' : 'Horneadas');
$usuarioJson = json_encode(['id' => (int)($_SESSION['id'] ?? 0), 'nombre' => $usuarioNombre], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// ── Header operativo compartido ────────────────────────────────
$operationalView = 'caja';
$operationalModule = 'caja-panaderia';
$operationalModuleTitle = 'Caja · Panadería';
$operationalHeaderBrand = false;
$operationalShowLastUpdate = false;
$operationalUserMenu = false;
$operationalHeaderBack = false;
$operationalHeaderDrawerToggle = false;
$operationalBrandHref = \Services\Security\Contextos::CAJA_PANADERIA;
$operationalUsuarioNombre = $usuarioNombre !== '' ? $usuarioNombre : 'Caja';
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'caja-shell';
$operationalMainClass = 'caja-body operational-layout';
$operationalMainId = 'caja-main';
$operationalMainAttributes = ['aria-label' => 'Caja de mostrador', 'data-caja-main' => true];

// Centro de la barra: cómo va el día. Lo llena caja.js con el corte.
// Sin aria-live: tras cada cobro el portavoz de la caja ya dice «Cobrado»;
// anunciar también el acumulado del día era una segunda voz encima.
$operationalHeaderCenterHtml = $permisos['vender']
    ? '<p class="caja-pulso" data-caja-pulso><span class="caja-pulso__rotulo">Hoy</span> <span class="caja-pulso__dato" data-caja-pulso-ventas>—</span></p>'
    : '';

ob_start();
?>
<?php if ($permisos['hornear']): ?>
  <button type="button" class="caja-accion" data-caja-abrir="horneada" aria-haspopup="dialog" aria-controls="caja-hoja-horneada">
    <?= admin_icon('area', 18) ?><span>Horneada</span>
  </button>
<?php endif; ?>
<?php if ($permisos['vender']): ?>
  <button type="button" class="caja-accion" data-caja-abrir="tickets" aria-haspopup="dialog" aria-controls="caja-hoja-tickets">
    <?= admin_icon('tickets', 18) ?><span>Tickets</span>
  </button>
  <button type="button" class="caja-accion" data-caja-abrir="corte" aria-haspopup="dialog" aria-controls="caja-hoja-corte">
    <?= admin_icon('caja', 18) ?><span>Corte</span>
  </button>
<?php endif; ?>
<?php
$operationalHeaderActionsHtml = (string)ob_get_clean();

$operationalHeaderMenuHtml = '<button type="button" class="operational-user-menu__item" role="menuitem" data-caja-abrir="atajos"'
  . ' aria-haspopup="dialog" aria-controls="caja-hoja-atajos">' . admin_icon('ayuda', 18) . '<span>Atajos de teclado</span></button>'
  . ($esAdmin ? '<a class="operational-user-menu__item" role="menuitem" href="/admin/panaderia">' . admin_icon('panel', 18) . '<span>Panel de administración</span></a>' : '');

ob_start();
?>
<div class="caja" data-caja data-modo="<?= $permisos['vender'] ? 'venta' : 'horneada' ?>">
  <?php /* El portavoz para lectores de pantalla: caja.js dice aquí sólo lo que
     cambió («Croissant, 2 piezas. Total $84.00»). El ticket y el cobro se
     repintan enteros y, con aria-live en ellos, se leía la venta completa. */ ?>
  <p class="operational-visually-hidden" data-caja-anuncio role="status" aria-live="polite" aria-atomic="true"></p>
  <?php if ($permisos['vender']): ?>
  <section class="caja-vitrina" aria-labelledby="caja-vitrina-titulo">
    <h2 class="operational-visually-hidden" id="caja-vitrina-titulo">Vitrina</h2>
    <div class="caja-vitrina__barra">
      <label class="caja-buscar">
        <span class="caja-buscar__icono" aria-hidden="true"><?= admin_icon('buscar', 18) ?></span>
        <span class="operational-visually-hidden">Buscar pan</span>
        <input type="search" class="caja-buscar__campo" data-caja-buscar placeholder="Buscar pan" autocomplete="off" enterkeyhint="search" spellcheck="false">
        <kbd class="caja-kbd caja-buscar__kbd" aria-hidden="true">/</kbd>
      </label>
      <div class="caja-familias" role="tablist" aria-label="Familias" data-caja-familias></div>
    </div>
    <div class="caja-teclas" id="caja-teclas" data-caja-teclas role="list" aria-label="Panes de la vitrina"></div>
    <p class="caja-vacio" data-caja-sin-resultados hidden>No hay panes con ese nombre.</p>
  </section>

  <aside class="caja-ticket" aria-labelledby="caja-ticket-titulo" data-caja-ticket>
    <header class="caja-ticket__cabeza">
      <h2 class="caja-ticket__titulo" id="caja-ticket-titulo">Venta</h2>
      <button type="button" class="caja-ticket__vaciar" data-caja-vaciar hidden><?= admin_icon('basura', 16) ?><span>Vaciar</span></button>
      <button type="button" class="caja-ticket__cerrar-hoja" data-caja-cerrar-ticket aria-label="Volver a la vitrina"><?= admin_icon('chevron-abajo', 20) ?></button>
    </header>
    <ol class="caja-lineas" data-caja-lineas aria-label="Piezas de la venta"></ol>
    <div class="caja-ticket__vacio" data-caja-ticket-vacio>
      <p class="caja-ticket__vacio-titulo">Toca un pan para empezar</p>
      <p class="caja-ticket__vacio-ayuda">Cada toque suma una pieza. Mantén presionado para 2, 3, 6 o 12.</p>
    </div>
    <footer class="caja-ticket__pie" data-caja-pie>
      <div class="caja-total">
        <span class="caja-total__piezas" data-caja-piezas>0 piezas</span>
        <span class="caja-total__rotulo">Total</span>
        <span class="caja-total__cifra" data-caja-total>$0.00</span>
      </div>
      <div class="caja-cobrar">
        <button type="button" class="caja-cobrar__btn caja-cobrar__btn--efectivo" data-caja-cobrar="efectivo" disabled>
          <span>Efectivo</span><kbd class="caja-kbd" aria-hidden="true">F2</kbd>
        </button>
        <button type="button" class="caja-cobrar__btn caja-cobrar__btn--tarjeta" data-caja-cobrar="tarjeta" disabled>
          <span>Tarjeta</span><kbd class="caja-kbd" aria-hidden="true">F3</kbd>
        </button>
      </div>
    </footer>

    <?php /* El cobro se superpone al ticket: el total sigue a la vista. */ ?>
    <div class="caja-pago" data-caja-pago hidden role="dialog" aria-modal="false" aria-labelledby="caja-pago-titulo"></div>
  </aside>

  <?php /* Teléfono y tableta vertical: el ticket vive en una barra que se abre en hoja. */ ?>
  <button type="button" class="caja-barra-ticket" data-caja-abrir-ticket hidden>
    <span class="caja-barra-ticket__piezas" data-caja-barra-piezas>0 piezas</span>
    <span class="caja-barra-ticket__total" data-caja-barra-total>$0.00</span>
    <span class="caja-barra-ticket__accion">Cobrar <?= admin_icon('chevron-derecha', 18) ?></span>
  </button>
  <?php else: ?>
  <section class="caja-solo-horneada">
    <p class="caja-solo-horneada__rotulo">Horneadas</p>
    <h2 class="caja-solo-horneada__titulo">Registra lo que sale del horno</h2>
    <p class="caja-solo-horneada__ayuda">Las piezas entran a la vitrina de la caja en cuanto las registras.</p>
    <button type="button" class="caja-solo-horneada__btn" data-caja-abrir="horneada" aria-haspopup="dialog" aria-controls="caja-hoja-horneada"><?= admin_icon('area', 20) ?><span>Registrar horneada</span></button>
  </section>
  <?php endif; ?>
</div>

<?php /* Hojas laterales: horneada, tickets del día, corte y atajos. */ ?>
<div class="caja-velo" data-caja-velo hidden></div>
<?php foreach (['horneada' => 'Horneada', 'tickets' => 'Tickets de hoy', 'corte' => 'Corte de caja', 'atajos' => 'Atajos de teclado'] as $hoja => $titulo): ?>
  <section class="caja-hoja" id="caja-hoja-<?= $h($hoja) ?>" data-caja-hoja="<?= $h($hoja) ?>" role="dialog" aria-modal="true" aria-labelledby="caja-hoja-<?= $h($hoja) ?>-titulo" hidden>
    <header class="caja-hoja__cabeza">
      <h2 class="caja-hoja__titulo" id="caja-hoja-<?= $h($hoja) ?>-titulo"><?= $h($titulo) ?></h2>
      <button type="button" class="caja-hoja__cerrar" data-caja-cerrar-hoja aria-label="Cerrar <?= $h(mb_strtolower($titulo)) ?>"><?= admin_icon('cerrar', 20) ?></button>
    </header>
    <div class="caja-hoja__cuerpo" data-caja-hoja-cuerpo></div>
    <footer class="caja-hoja__pie" data-caja-hoja-pie hidden></footer>
    <?php /* Portavoz propio: la hoja es aria-modal y el de la caja queda fuera. */ ?>
    <p class="operational-visually-hidden" data-caja-hoja-anuncio role="status" aria-live="polite" aria-atomic="true"></p>
  </section>
<?php endforeach; ?>

<?php /* Íconos del catálogo de la casa (admin_icon) para el JS. */ ?>
<?php foreach (['mas', 'menos', 'cerrar', 'check', 'basura', 'imprimir', 'alerta', 'chevron-derecha', 'reloj'] as $icono): ?>
  <template data-caja-icono="<?= $h($icono) ?>"><?= admin_icon($icono, 18) ?></template>
<?php endforeach; ?>
<?php
$operationalContentHtml = (string)ob_get_clean();
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0b0b0c">
  <title>Caja · Panadería · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
  <link rel="preload" href="/build/fonts/satoshi-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= $h(recursoVersionado('/build/css/operation.css')) ?>">
  <link rel="stylesheet" href="<?= $h(recursoVersionado('/build/css/caja.css')) ?>">
</head>
<body class="admin-body operational-page caja-page" data-operational-page data-staff-csrf="<?= $h(\Services\Security\StaffCsrfService::token()) ?>">
  <?php include __DIR__ . '/../operation/partials/shell.php'; ?>
  <script>
    window.CP_CAJA = <?= $estadoJson ?: '{}' ?>;
    window.CP_USER = <?= $usuarioJson ?: '{"id":0}' ?>;
    window.CP_STAFF_CSRF_TOKEN = <?= json_encode(\Services\Security\StaffCsrfService::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="<?= $h(recursoVersionado('/build/js/caja.js')) ?>"></script>
</body>
</html>
