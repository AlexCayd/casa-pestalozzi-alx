<?php
/**
 * Herramienta diaria de reservaciones; el shell vive en views/operation/layout.php.
 */

$filtros = is_array($filtros ?? null) ? $filtros : [];
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$returnUrl = (string)($returnUrl ?? '');
$fechaMinima = (string)($fechaMinima ?? \Services\ReservacionConfig::fechaActual());
$fechaInicial = (string)($filtros['fecha'] ?? $fechaMinima);
$modoSoloLectura = (bool)($modoSoloLectura ?? false);
$fechaInvalidaRecibida = (string)($fechaInvalidaRecibida ?? '');
$horaInicial = \Services\HorarioReservacionService::normalizarHoraCorta((string)($filtros['hora'] ?? ''));
$initialReservacionId = (int)($initialReservacionId ?? 0);
$comentarioAdminDisponible = (bool)($comentarioAdminDisponible ?? false);

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$alertasNormalizadas = [];
$agregarAlerta = static function ($tipo, $mensajes) use (&$alertasNormalizadas, &$agregarAlerta): void {
    if ($mensajes === null || $mensajes === '') {
        return;
    }

    if (is_array($mensajes)) {
        foreach ($mensajes as $mensaje) {
            $agregarAlerta($tipo, $mensaje);
        }
        return;
    }

    $tipo = is_string($tipo) ? $tipo : 'error';
    $alertasNormalizadas[$tipo][] = (string)$mensajes;
};

foreach ($alertas as $tipo => $mensajes) {
    $agregarAlerta($tipo, $mensajes);
}
?>

<section
    class="reservation-operation admin-reservation-operation operational-module"
    aria-label="Operacion de reservaciones"
    data-page="reservation-operation"
    data-initial-fecha="<?php echo $h($fechaInicial); ?>"
    data-min-fecha="<?php echo $h($fechaMinima); ?>"
    data-initial-date-warning="<?php echo $fechaInvalidaRecibida !== '' ? '1' : '0'; ?>"
    data-operation-mode="<?php echo $modoSoloLectura ? 'solo_lectura' : 'operacion'; ?>"
    data-operation-editable="<?php echo $modoSoloLectura ? '0' : '1'; ?>"
    data-initial-hora="<?php echo $h($horaInicial); ?>"
    data-initial-reservation-id="<?php echo $initialReservacionId; ?>"
    data-return-url="<?php echo $h($returnUrl); ?>"
    data-comment-enabled="<?php echo $comentarioAdminDisponible ? '1' : '0'; ?>"
>
    <?php
    ob_start();
    include __DIR__ . '/_filters.php';
    $operationalContextControlsHtml = (string)ob_get_clean();

    ob_start();
    if (!$modoSoloLectura):
    ?>
        <a class="admin-btn admin-btn--primary" href="/admin/reservations/create?fecha=<?php echo $h($fechaInicial); ?>" data-operation-create>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M8 3v4M16 3v4M3 10h18M12 13v6M9 16h6"></path>
            </svg>
            <span>Crear reservación</span>
        </a>
    <?php
    endif;
    $operationalContextActionsHtml = (string)ob_get_clean();
    $operationalContextView = 'reservations';
    $operationalDrawerId = 'operation-reservations-drawer';
    $operationalDrawerInitialCount = '0';
    include __DIR__ . '/../partials/context-bar.php';
    ?>

    <div class="reservation-operation-notice reservation-operation-notice--warning" role="status" aria-live="polite" data-operation-readonly-notice <?php echo $modoSoloLectura ? '' : 'hidden'; ?>>
        <span class="reservation-operation-notice__icon" aria-hidden="true">i</span>
        <span class="reservation-operation-notice__copy">
            <strong>Modo historico de solo lectura</strong>
            <span>Asignacion, reasignacion, comentarios y cambios de estado estan deshabilitados.</span>
        </span>
    </div>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes): ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atencion' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>" role="status">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <div class="reservation-operation-notice reservation-operation-notice--neutral reservation-operation-context" data-operation-context role="status" aria-live="polite" hidden>
        <span class="reservation-operation-notice__icon" aria-hidden="true">i</span>
        <span class="reservation-operation-notice__copy">
            <strong data-operation-context-title></strong>
            <span data-operation-context-message></span>
        </span>
    </div>

    <div class="reservation-operation-load-error" data-operation-load-error role="alert" hidden>
        <span class="reservation-operation-load-error__copy">
            <strong data-operation-load-error-title>No fue posible actualizar la operacion</strong>
            <span data-operation-load-error-message></span>
        </span>
        <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-operation-retry>Reintentar</button>
    </div>

    <?php
    $operationalDrawerId = 'operation-reservations-drawer';
    $operationalDrawerTitleId = 'operation-reservations-title';
    $operationalDrawerClass = 'reservation-operation__reservations';
    $operationalDrawerAttributes = ['data-operation-results' => true];
    $operationalDrawerDateHtml = '<span data-operation-date-label>' . $h($fechaInicial) . '</span>';
    $operationalDrawerCountHtml = '<span class="mapa-reserva-count" data-operation-count>0</span>';
    $operationalDrawerSlotHtml = '<span data-operation-hour-label>Sin horario</span>';
    $operationalDrawerListAttributes = ['data-operation-reservations' => true];
    $operationalDrawerListHtml = '<div class="reservation-operation-skeleton"><span></span><span></span><span></span></div>';
    include __DIR__ . '/../partials/drawer.php';
    ?>

    <div class="reservation-operation__workspace operational-workspace" data-operation-mobile-view="tables">
            <?php
            $mapVisual = [
                'context' => 'operacion-reservaciones',
                'title' => 'Mapa de mesas',
                'subtitle' => 'Selecciona una reservación para ver disponibilidad.',
                'canvasMode' => 'operation',
                'loadingMode' => 'empty',
            ];
            include __DIR__ . '/../partials/map.php';
            ?>

            <aside class="reservation-operation__panel" data-operation-panel-shell aria-label="Panel operativo" aria-hidden="false">
                <div class="reservation-operation__panel-toolbar reservation-operation__header">
                    <span>Detalle operativo</span>
                    <button type="button" class="operational-icon-button reservation-operation__panel-close" aria-label="Cerrar panel operativo" data-operation-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="reservation-operation__panel-content reservation-operation__body" data-operation-panel>
                    <article class="reservation-operation-panel admin-card">
                        <span class="reservation-operation-panel__label">Reservacion seleccionada</span>
                        <h3>Sin seleccion</h3>
                        <p class="reservation-operation-panel__muted">Cargando reservaciones del dia.</p>
                    </article>
                </div>
            </aside>
    </div>

        <section
            class="reservation-operation-assignment-bar assignment-toolbar"
            id="operation-assignment-bar"
            aria-labelledby="operation-assignment-title"
            aria-hidden="true"
            data-operation-assignment-bar
            hidden
        >
            <div class="reservation-operation-assignment-bar__identity">
                <span id="operation-assignment-title" tabindex="-1" data-operation-assignment-title>ASIGNACIÓN DE MESAS</span>
                <strong data-operation-assignment-reservation>Sin reservación</strong>
            </div>
            <div class="reservation-operation-assignment-bar__summary" aria-live="polite">
                <span><small>Personas</small><strong data-operation-assignment-people>0</strong></span>
                <span><small>Capacidad</small><strong data-operation-assignment-capacity>0</strong></span>
                <span><small>Diferencia</small><strong data-operation-assignment-difference>0</strong></span>
                <span class="reservation-operation-assignment-bar__tables"><small>Mesas</small><strong data-operation-assignment-tables>Sin mesas seleccionadas</strong></span>
            </div>
            <div class="reservation-operation-assignment-bar__actions">
                <button type="button" class="admin-btn admin-btn--secondary" data-operation-assignment-cancel>Cancelar</button>
                <button type="button" class="admin-btn admin-btn--primary" data-operation-save data-operation-assignment-save data-disabled="1" disabled>Guardar asignación</button>
            </div>
        </section>

    <div class="reservation-operation-toast" data-operation-toast hidden></div>
</section>
