<?php
/**
 * Barra compartida por las herramientas operativas de mapa y reservaciones.
 */
$operationalView = (string)($operationalView ?? 'reservations');
$operationalDate = (string)($operationalDate ?? date('Y-m-d'));
$operationalHour = (string)($operationalHour ?? '');
$operationalReturnUrl = (string)($operationalReturnUrl ?? '');
$operationalH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$mapQuery = http_build_query(array_filter([
    'fecha' => $operationalDate,
    'hora' => $operationalHour,
], static fn($value): bool => $value !== ''));
$operationQuery = http_build_query(array_filter([
    'fecha' => $operationalDate,
    'hora' => $operationalHour,
], static fn($value): bool => $value !== ''));
?>
<header class="operation-header operational-header" data-operational-header>
    <a class="operation-header__brand" href="/admin/reservations" aria-label="CASA PESTALOZZI, gestión de reservaciones">
        <span class="operation-header__isotype" aria-hidden="true">CP</span>
        <strong>CASA PESTALOZZI</strong>
    </a>

    <nav class="operation-header__nav" aria-label="Herramientas operativas">
        <a
            href="/mapa<?php echo $mapQuery !== '' ? '?' . $operationalH($mapQuery) : ''; ?>"
            data-operational-nav="map"
            <?php echo $operationalView === 'map' ? 'class="is-active" aria-current="page"' : ''; ?>
        >Mapa de mesas</a>
        <a
            href="/admin/reservations/operation<?php echo $operationQuery !== '' ? '?' . $operationalH($operationQuery) : ''; ?>"
            data-operational-nav="reservations"
            <?php echo $operationalView === 'reservations' ? 'class="is-active" aria-current="page"' : ''; ?>
        >Operación</a>
    </nav>

    <div class="operation-header__context" aria-label="Última actualización">
        <?php
        $operationalUpdateId = $operationalView === 'map' ? 'mapa-live-badge' : '';
        $operationalUpdateTextId = $operationalView === 'map' ? 'mapa-update-status' : '';
        $operationalUpdateText = $operationalView === 'map' ? 'En vivo' : 'Preparando operación';
        $operationalUpdateTextAttributes = $operationalView === 'reservations'
            ? ['data-operation-update-status' => true]
            : [];
        $operationalUpdateClass = $operationalView === 'map' ? 'mapa-live-badge' : '';
        include __DIR__ . '/last-update.php';
        ?>
    </div>

    <div class="operation-header__actions">
        <?php if ($operationalReturnUrl !== ''): ?>
            <a class="operation-header__return" href="<?php echo $operationalH($operationalReturnUrl); ?>">Volver al detalle</a>
        <?php endif; ?>
        <a class="operation-header__back" href="/admin/reservations">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6M9 12h10"></path></svg>
            <span>Volver a gestión</span>
        </a>
    </div>
</header>
