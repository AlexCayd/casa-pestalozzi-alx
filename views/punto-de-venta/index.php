<?php
$mapFecha = trim((string)($_GET['fecha'] ?? \Services\ReservacionConfig::fechaActual()));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mapFecha) !== 1) {
  $mapFecha = \Services\ReservacionConfig::fechaActual();
}
$mapHora = trim((string)($_GET['hora'] ?? ''));
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $mapHora) !== 1) {
  $mapHora = '';
}
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

// Usuario/mesero activo en la sesión (para mostrarlo en el header).
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$usuarioNombre = trim((string)($_SESSION['nombre'] ?? ''));
$rolEtiquetas = ['admin' => 'Administrador', 'waiter' => 'Mesero', 'cook' => 'Cocinero'];
$usuarioRol = $rolEtiquetas[(string)($_SESSION['rol'] ?? '')] ?? 'Usuario';

// Identidad del mesero para el JS: las preferencias del modal se guardan con
// su id, porque la tablet de piso es compartida entre turnos.
$menuJson = $menuJson ?? '[]';
$areasJson = $areasJson ?? '{}';
$usuarioJson = json_encode([
  'id'     => (int)($_SESSION['id'] ?? 0),
  'nombre' => $usuarioNombre,
  'rol'    => (string)($_SESSION['rol'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#101210">
  <title>Punto de Venta · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Space+Grotesk:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="/build/css/app.css?v=pos-reservations-v3">
</head>

<body class="mapa-page operational-page" data-page="mapa" data-operational-page data-operation-module="tables" data-operational-map-state-key="pos" data-staff-csrf="<?= $h(\Services\StaffCsrfService::token()) ?>">
  <?php
  // Botón hamburguesa que abre el cajón de reservaciones (va en el header).
  // Selector de fecha; se muestra dentro del cajón de reservaciones.
  ob_start();
  $rootId = 'mapa-date-picker';
  $inputId = 'mapa-fecha';
  $displayId = 'mapa-date-display';
  $calendarId = 'mapa-calendar';
  $name = '';
  $value = $mapFecha;
  $min = '';
  $today = \Services\ReservacionConfig::fechaActual();
  $disabled = false;
  $enabledWeekdays = [];
  $allowPast = true;
  $required = false;
  $inputDataAttributes = ['data-operational-context-date' => true];
  $displayAriaDescribedby = '';
  $displayAriaInvalid = false;
  $rootClass = 'operational-context-date';
  $showIcon = true;
  $prevId = 'mapa-cal-prev';
  $nextId = 'mapa-cal-next';
  $labelId = 'mapa-cal-label';
  $gridId = 'mapa-cal-grid';
  include __DIR__ . '/../components/reservations/date-picker.php';
  $datePickerHtml = (string)ob_get_clean();

  // Cuerpo del POS extraído como partial reutilizable (ver el propio archivo).
  include __DIR__ . '/partials/pos-workspace.php';
  ?>

  <script>
    /*
     * Menú, áreas e identidad del mesero. Deben ir antes de map.js:
     * punto-de-venta.js los lee de forma síncrona al construir el modal.
     * CP_MENU/CP_AREAS salen de la BD (Services\Carta); antes vivían escritos
     * a mano en src/js/data/menu-data.js, que ya no existe.
     */
    window.CP_MENU  = <?php echo $menuJson ?: '[]'; ?>;
    window.CP_AREAS = <?php echo $areasJson ?: '{}'; ?>;
    window.CP_USER  = <?php echo $usuarioJson ?: '{"id":0}'; ?>;
    window.CP_STAFF_CSRF_TOKEN = <?php echo json_encode(
      \Services\StaffCsrfService::token(),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
  </script>
  <script>
    window.CP_TWEAKS = {
      hero: 'cinema',
      accent: 'oro',
      cursor: false,
      smooth: false,
      anim: false
    };
    // El POS inicia con las mismas ventanas que usa el backend; la API vuelve
    // a entregarlas en cada actualización para evitar valores divergentes.
    window.CP_RESERVATION_OPERATION_CONFIG = <?php
      echo json_encode(
        \Services\ReservacionConfig::configuracionOperacion(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
      );
    ?>;
  </script>
  <script>
    // Confirmación: en tablet un toque accidental no debe sacar del turno
    (function () {
      var form = document.querySelector('.operational-header__logout-form');
      if (!form) return;
      form.addEventListener('submit', function (e) {
        if (!window.confirm('¿Cerrar sesión y salir del punto de venta?')) {
          e.preventDefault();
        }
      });
    })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
  <script src="/build/js/bundle.min.js"></script>
  <script src="/build/js/admin/map.js?v=pos-reservations-v7"></script>
</body>

</html>
