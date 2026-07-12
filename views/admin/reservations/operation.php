<?php
/**
 * Renderiza la operacion diaria de reservaciones y entrega datos iniciales al JS.
 */

$filtros = is_array($filtros ?? null) ? $filtros : [];
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$returnUrl = (string)($returnUrl ?? '');
$fechaInicial = (string)($filtros['fecha'] ?? date('Y-m-d'));
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
    class="admin-reservations admin-reservation-operation admin-map mapa-page admin-page"
    data-page="reservation-operation"
    data-initial-fecha="<?php echo $h($fechaInicial); ?>"
    data-initial-reservation-id="<?php echo $initialReservacionId; ?>"
    data-return-url="<?php echo $h($returnUrl); ?>"
    data-comment-enabled="<?php echo $comentarioAdminDisponible ? '1' : '0'; ?>"
>
    <header class="admin-menu__header admin-page__header reservation-operation__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Recepcion</span>
            <h2 class="admin-page__title">Operacion de reservaciones</h2>
            <p class="admin-page__subtitle">Gestiona las reservaciones y mesas por dia, horario y estado.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <?php if ($returnUrl !== '') : ?>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $h($returnUrl); ?>">Volver al detalle</a>
            <?php endif; ?>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/reservations/create?fecha=<?php echo $h($fechaInicial); ?>">Nueva reservacion</a>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservations">Vista general</a>
        </div>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atencion' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <form class="admin-filters reservation-operation__filters" data-operation-filters>
        <div class="admin-filters__group">
            <label for="operation-fecha">Fecha</label>
            <input id="operation-fecha" type="date" name="fecha" value="<?php echo $h($fechaInicial); ?>" data-operation-date>
        </div>
        <div class="admin-filters__group">
            <label for="operation-hora">Horario seleccionado</label>
            <select id="operation-hora" name="hora" data-operation-hour>
                <option value="">Cargando horarios</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-operation-load>
                Consultar
            </button>
        </div>
    </form>

    <div class="reservation-operation__layout">
        <section class="reservation-operation__reservations admin-card" aria-live="polite">
            <div class="mapa-sidebar-head">
                <span class="mapa-sidebar-title">Reservaciones del dia</span>
                <span class="mapa-reserva-count" data-operation-count>0</span>
            </div>

            <div class="reservation-operation__slot">
                <strong data-operation-date-label><?php echo $h($fechaInicial); ?></strong>
                <span data-operation-hour-label>Sin hora</span>
            </div>

            <div class="reservation-operation__reservation-list" data-operation-reservations>
                <div class="reservation-operation-skeleton">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </section>

        <div class="reservation-operation__workspace">
            <section class="reservation-operation__map admin-card">
                <div class="reservation-operation__map-head">
                    <div>
                        <span class="mapa-sidebar-title">Mapa de mesas</span>
                        <p data-operation-map-status>Selecciona una reservacion para ver disponibilidad.</p>
                    </div>
                    <div class="mapa-leyenda">
                        <span class="mapa-leyenda-item mapa-leyenda-item--libre">Libre</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--ocupada">Ocupada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--bloqueada">Asignada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--seleccionada">Seleccionada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--zona">No reservable</span>
                    </div>
                </div>

                <div class="mapa-canvas-wrap reservation-operation__canvas-wrap">
                    <div class="mapa-canvas reservation-operation__canvas" data-operation-map>
                        <div class="mapa-empty-state">
                            <span class="mapa-empty-icon">o</span>
                            <span>Cargando mapa</span>
                        </div>
                    </div>
                </div>
            </section>

            <aside class="reservation-operation__panel" data-operation-panel>
                <article class="reservation-operation-panel admin-card">
                    <span class="reservation-operation-panel__label">Reservacion seleccionada</span>
                    <h3>Sin seleccion</h3>
                    <p class="reservation-operation-panel__muted">Cargando reservaciones del dia.</p>
                </article>
            </aside>
        </div>
    </div>

    <div class="reservation-operation-toast" data-operation-toast hidden></div>
</section>
