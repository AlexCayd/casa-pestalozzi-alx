<?php
$mapFecha = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mapFecha) !== 1) {
    $mapFecha = date('Y-m-d');
}
$mapHora = trim((string)($_GET['hora'] ?? ''));
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $mapHora) !== 1) {
    $mapHora = '';
}
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101210">
    <title>Mapa de mesas · Casa Pestalozzi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Space+Grotesk:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/build/css/app.css">
</head>
<body class="mapa-page operational-page" data-page="mapa" data-operational-page>
    <div class="mapa-shell">
        <main class="mapa-body operational-main operational-layout" aria-label="Mapa de mesas">
        <?php
        $operationalView = 'map';
        $operationalDate = $mapFecha;
        $operationalHour = $mapHora;
        $operationalReturnUrl = '';
        include __DIR__ . '/../operation/partials/header.php';
        ?>

        <section class="mapa-module operational-module">
            <?php
            ob_start();
            $rootId = 'mapa-date-picker';
            $inputId = 'mapa-fecha';
            $displayId = 'mapa-date-display';
            $calendarId = 'mapa-calendar';
            $name = '';
            $value = $mapFecha;
            $min = '';
            $today = date('Y-m-d');
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
            $operationalContextControlsHtml = (string)ob_get_clean();
            $operationalContextActionsHtml = '';
            $operationalContextView = 'map';
            $operationalDrawerId = 'map-reservations-drawer';
            $operationalDrawerInitialCount = '0';
            include __DIR__ . '/../operation/partials/context-bar.php';

            ?>
            <div class="operational-workspace mapa-workspace">
            <?php
            $mapVisual = [
                'context' => 'mapa-mesas',
                'sectionClass' => 'mapa-operational-map',
                'titleId' => 'mapa-operational-title',
                'title' => 'Mapa de mesas',
                'subtitle' => 'Estado actual del salón.',
                'canvasId' => 'mapa-canvas',
                'canvasMode' => 'map',
                'loadingMode' => 'overlay',
            ];
            include __DIR__ . '/../operation/partials/map.php';
            ?>
            </div>
        </section>

            <?php
            $operationalDrawerId = 'map-reservations-drawer';
            $operationalDrawerTitleId = 'map-reservations-title';
            $operationalDrawerClass = 'mapa-sidebar';
            $operationalDrawerAttributes = [];
            $operationalDrawerDateHtml = '<span data-operational-map-date>' . $h($mapFecha) . '</span>';
            $operationalDrawerCountHtml = '<span class="mapa-reserva-count" id="mapa-reserva-count">—</span>';
            $operationalDrawerSlotHtml = '<span>Reservaciones activas</span>';
            $operationalDrawerListId = 'mapa-reservas-list';
            $operationalDrawerListClass = 'mapa-reservas-list';
            $operationalDrawerListAttributes = [];
            $operationalDrawerListHtml = '<div class="mapa-empty-state"><span class="mapa-empty-icon">◌</span><span>Cargando…</span></div>';
            include __DIR__ . '/../operation/partials/drawer.php';
            ?>
        </main>

        <div class="mesa-modal" id="mesa-modal">
            <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
            <div class="mesa-modal__panel">
                <div class="mesa-modal__handle"></div>
                <button class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar">×</button>
                <div id="mesa-modal-content"></div>
            </div>
        </div>
    </div>

    <script>
        window.CP_TWEAKS = { hero: 'cinema', accent: 'oro', cursor: false, smooth: false, anim: false };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script src="/build/js/bundle.min.js"></script>
    <script src="/build/js/admin/map.js"></script>
</body>
</html>
