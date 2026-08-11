<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mesas = is_array($mesas ?? null) ? $mesas : [];

// Mismos nombres que usa el mapa del POS para no tener dos vocabularios de
// estado entre pantallas.
$etiquetaEstado = [
    'libre' => 'Disponible',
    'ocupada' => 'Ocupada',
    'reservacion-proxima' => 'Reservación próxima',
    'no-utilizable' => 'No utilizable',
];
$tonoEstado = [
    'libre' => 'success',
    'ocupada' => 'danger',
    'reservacion-proxima' => 'info',
    'no-utilizable' => 'neutral',
];

$totalMesas = 0;
$totalLibres = 0;
foreach ($mesas as $mesa) {
    if (empty($mesa['reservable'])) {
        continue;
    }
    $totalMesas++;
    if (($mesa['estado_visual'] ?? '') === 'libre') {
        $totalLibres++;
    }
}
?>
<section class="admin-map admin-map--launch admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación en piso</span>
            <h2 class="admin-page__title">Punto de Venta</h2>
            <p class="admin-page__subtitle">
                Gestión de mesas, reservaciones y tickets activos en tiempo real.
                El punto de venta se abre como herramienta operativa a pantalla completa.
            </p>
        </div>
    </header>

    <article class="admin-card admin-launch-card" data-reveal>
        <div class="admin-launch-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z"/>
                <path d="M9 3v15"/><path d="M15 6v15"/>
            </svg>
        </div>
        <div class="admin-launch-card__body">
            <h3>Abrir el punto de venta</h3>
            <p>Reservaciones del día, apertura y cierre de tickets, y envío de comandas por área desde una vista dedicada.</p>
        </div>
        <a
            class="admin-btn admin-btn--primary admin-launch-card__button"
            href="/punto-de-venta"
            target="_blank"
            rel="noopener"
            data-admin-magnetic
        >
            Abrir punto de venta
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M7 17 17 7"/><path d="M7 7h10v10"/>
            </svg>
        </a>
    </article>

    <article class="admin-panel admin-table-panel admin-pos-tables" data-reveal>
        <header>
            <div>
                <p class="admin-page-eyebrow">Piso</p>
                <h3>Lista estructurada de mesas</h3>
            </div>
            <span><?php echo $h($totalLibres); ?> de <?php echo $h($totalMesas); ?> mesas disponibles</span>
        </header>

        <?php if ($mesas === []) : ?>
            <p class="admin-empty">No hay mesas activas configuradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <th scope="col">Mesa</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Capacidad</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mesas as $mesa) : ?>
                            <?php
                            $estado = (string) ($mesa['estado_visual'] ?? 'libre');
                            $tono = $tonoEstado[$estado] ?? 'neutral';
                            $reservable = !empty($mesa['reservable']);
                            $capacidad = (int) ($mesa['capacidad'] ?? 0);
                            ?>
                            <tr>
                                <th scope="row"><?php echo $h($mesa['nombre'] ?? ''); ?></th>
                                <td><?php echo $h($reservable ? 'Mesa' : 'Área operativa'); ?></td>
                                <td><?php echo $capacidad > 0 ? $h($capacidad) : '—'; ?></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $h($tono); ?>">
                                        <?php echo $h($etiquetaEstado[$estado] ?? $estado); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>
