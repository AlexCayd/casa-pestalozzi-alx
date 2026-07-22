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
<body class="admin-body operation-body operational-page" data-operational-page>
    <div class="operation-shell">
        <main class="operation-main operational-layout" id="operation-main">
            <?php
            $operationalView = 'reservations';
            $operationalDate = $fechaContexto;
            $operationalHour = $horaContexto;
            $operationalReturnUrl = $returnUrl;
            include __DIR__ . '/partials/header.php';
            ?>
            <?php echo $content; ?>
        </main>
    </div>

    <?php foreach ($scripts as $script): ?>
        <script src="<?php echo $h($script); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
