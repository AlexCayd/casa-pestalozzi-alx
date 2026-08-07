<?php
/**
 * Shell autonomo para herramientas de operacion diaria.
 */
$title = (string)($title ?? 'Operacion');
$styles = is_array($styles ?? null) ? $styles : [];
$scripts = is_array($scripts ?? null) ? $scripts : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$fechaContexto = (string)($filtros['fecha'] ?? '');
$horaContexto = (string)($filtros['hora'] ?? '');
$returnUrl = (string)($returnUrl ?? '');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$operationalUsuarioNombre = trim((string)($_SESSION['nombre'] ?? ''));
$operationalRolEtiquetas = [
    'admin' => 'Administrador',
    'cook' => 'Cocinero',
    'waiter' => 'Mesero',
];
$operationalUsuarioRol = $operationalRolEtiquetas[(string)($_SESSION['rol'] ?? '')] ?? 'Usuario';

$operationalView = 'reservations';
$operationalModule = 'reservations';
$operationalModuleTitle = 'Mapa de reservaciones';
$operationalDate = $fechaContexto;
$operationalHour = $horaContexto;
$operationalBrandHref = '/admin/reservations/operation';
$operationalHeaderBackUrl = '/admin/reservations';
$operationalDrawerId = 'operation-reservations-drawer';
$operationalActiveModule = 'reservations';
$operationalMapHref = '/punto-de-venta';
$operationalReservationsHref = '/admin/reservations/operation';
$operationalShellClass = 'operation-shell operation-shell--reservations';
$operationalMainClass = 'operation-main operational-layout';
$operationalMainId = 'operation-main';
$operationalMainAttributes = [];
$operationalContentHtml = (string)$content;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101210">
    <title>Casa Pestalozzi - <?php echo $h($title); ?></title>
    <script>document.documentElement.setAttribute('data-admin-theme', 'dark');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Space+Grotesk:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/build/css/admin.css?v=reservation-operation-v1">
    <?php foreach ($styles as $stylesheet): ?>
        <link rel="stylesheet" href="<?php echo $h($stylesheet); ?>">
    <?php endforeach; ?>
</head>
<body class="admin-body operation-body operational-page" data-operational-page data-operation-module="reservations" data-operational-map-state-key="reservations">
    <?php include __DIR__ . '/partials/shell.php'; ?>

    <div
        id="global-operation-notice-root"
        class="global-operation-notice-root operational-global-notice-stack"
        aria-live="polite"
        aria-atomic="true"
    >
        <?php include __DIR__ . '/partials/global-notice.php'; ?>
    </div>

    <?php foreach ($scripts as $script): ?>
        <script src="<?php echo $h($script); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
