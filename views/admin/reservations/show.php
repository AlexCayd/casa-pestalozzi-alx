<?php
/**
 * Muestra y edita el detalle administrativo de una reservacion.
 * Las acciones publican hacia endpoints canonicos del modulo.
 */

$reservacion = is_object($reservacion ?? null) ? $reservacion : null;
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$capacidadTotal = (int)($capacidadTotal ?? 0);
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$editable = (bool)($editable ?? false);
$returnUrl = (string)($returnUrl ?? '/admin/reservations');
$backUrl = (string)($backUrl ?? '/admin/reservations');

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$valor = static function ($item, string $campo, $default = '') {
    if (is_array($item)) {
        return $item[$campo] ?? $default;
    }

    if (is_object($item)) {
        return $item->$campo ?? $default;
    }

    return $default;
};

$fechaLegible = static function ($fecha): string {
    $timestamp = strtotime((string)$fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : 'Sin fecha';
};

$fechaHoraLegible = static function ($fecha): string {
    $timestamp = strtotime((string)$fecha);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '';
};

$horaLegible = static function ($hora): string {
    return substr((string)$hora, 0, 5);
};

$plural = static function (int $total, string $singular, string $plural): string {
    return $total . ' ' . ($total === 1 ? $singular : $plural);
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

$statusForm = static function (
    int $id,
    string $estado,
    string $returnUrl,
    string $label,
    string $class,
    string $confirm = ''
) use ($h): void {
    ?>
    <form method="POST" action="/admin/reservations/status" data-reservation-operational-action <?php echo $confirm !== '' ? 'onsubmit="return confirm(\'' . $h($confirm) . '\')"' : ''; ?>>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="estado" value="<?php echo $h($estado); ?>">
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
        <button type="submit" class="<?php echo $h($class); ?>" data-reservation-operational-control><?php echo $h($label); ?></button>
    </form>
    <?php
};

$id = (int)$valor($reservacion, 'id', 0);
$nombre = (string)$valor($reservacion, 'nombre');
$email = (string)$valor($reservacion, 'email');
$fecha = (string)$valor($reservacion, 'fecha');
$hora = (string)$valor($reservacion, 'hora');
$comensales = (int)$valor($reservacion, 'comensales', 0);
$nota = trim((string)$valor($reservacion, 'nota'));
$comentarioAdmin = (string)$valor($reservacion, 'comentario_admin');
$estado = (string)$valor($reservacion, 'estado', 'pendiente');
$createdAt = (string)$valor($reservacion, 'created_at', '');
$mesasCount = count($mesasAsignadas);
$tieneMesa = $mesasCount > 0;
$estadoFinal = in_array($estado, ['completada', 'cancelada', 'no_show'], true);
$capacidadRestaurante = max((int)($capacidadRestaurante ?? 0), $comensales, 1);
$diferenciaCapacidad = $capacidadTotal - $comensales;
$horaCorta = $horaLegible($hora);
$operationUrl = '/admin/reservations/operation?' . http_build_query([
    'fecha' => $fecha,
    'reservacion_id' => $id,
    'return_url' => $returnUrl,
]);
?>

<section
    class="admin-reservations admin-reservation-detail admin-menu admin-page"
    data-reservation-detail-root
>
    <header class="admin-menu__header admin-page__header reservation-detail-header">
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $h($backUrl); ?>">Volver</a>
        <div class="admin-page__intro">
            <h2 class="admin-page__title"><?php echo $nombre !== '' ? $h($nombre) : 'Detalle de reservacion'; ?></h2>
        </div>
        <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
            <?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?>
        </span>
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

    <div class="reservation-detail-layout">
        <section class="reservation-detail-main">
            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Resumen de estado</span>
                        <h3><?php echo $h($fechaLegible($fecha)); ?> · <?php echo $h($horaLegible($hora)); ?></h3>
                    </div>
                </div>
                <dl class="reservation-detail-list reservation-detail-list--grid">
                    <div>
                        <dt>Estado</dt>
                        <dd><?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?></dd>
                    </div>
                    <div>
                        <dt>Comensales</dt>
                        <dd><?php echo $h($plural($comensales, 'persona', 'personas')); ?></dd>
                    </div>
                    <div>
                        <dt>Mesas</dt>
                        <dd><?php echo $tieneMesa ? $h($plural($mesasCount, 'mesa asignada', 'mesas asignadas')) : 'Sin mesas asignadas'; ?></dd>
                    </div>
                    <?php if ($createdAt !== '') : ?>
                        <div>
                            <dt>Creacion</dt>
                            <dd><?php echo $h($fechaHoraLegible($createdAt)); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </article>

            <?php
            $modo = 'editar';
            include __DIR__ . '/_form.php';
            ?>

            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Notas</span>
                        <h3>Cliente y operacion</h3>
                    </div>
                </div>
                <div class="reservation-detail-notes">
                    <section>
                        <h4>Nota original del cliente</h4>
                        <?php if ($nota !== '') : ?>
                            <p class="reservation-detail-note"><?php echo nl2br($h($nota)); ?></p>
                        <?php else : ?>
                            <p class="reservation-detail-empty">Sin nota registrada.</p>
                        <?php endif; ?>
                    </section>
                    <section>
                        <h4>Comentario interno</h4>
                        <?php if (trim($comentarioAdmin) !== '') : ?>
                            <p class="reservation-detail-note"><?php echo nl2br($h($comentarioAdmin)); ?></p>
                        <?php else : ?>
                            <p class="reservation-detail-empty">Sin comentario interno.</p>
                        <?php endif; ?>
                    </section>
                </div>
            </article>
        </section>

        <aside class="reservation-detail-side">
            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Mesas asignadas</span>
                        <h3><?php echo $tieneMesa ? $h($plural($mesasCount, 'mesa asignada', 'mesas asignadas')) : 'Sin mesas asignadas'; ?></h3>
                    </div>
                    <?php if (!$tieneMesa) : ?>
                        <span class="admin-badge admin-badge--warning">Sin mesas asignadas</span>
                    <?php endif; ?>
                </div>

                <?php if ($tieneMesa) : ?>
                    <ul class="reservation-detail-tables">
                        <?php foreach ($mesasAsignadas as $mesa) : ?>
                            <?php
                            $mesaNombre = (string)$valor($mesa, 'nombre');
                            $mesaCapacidad = (int)$valor($mesa, 'capacidad', 0);
                            ?>
                            <li>
                                <span><?php echo $h($mesaNombre); ?></span>
                                <small><?php echo $h($plural($mesaCapacidad, 'persona', 'personas')); ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="reservation-detail-capacity">
                        <span>Capacidad total</span>
                        <strong><?php echo $h($plural($capacidadTotal, 'persona', 'personas')); ?></strong>
                    </div>
                    <div class="reservation-detail-capacity reservation-detail-capacity--muted">
                        <span>Comensales</span>
                        <strong><?php echo $h($plural($comensales, 'persona', 'personas')); ?></strong>
                    </div>
                    <div class="reservation-detail-capacity <?php echo $diferenciaCapacidad < 0 ? 'reservation-detail-capacity--warning' : ''; ?>">
                        <span>Diferencia</span>
                        <strong><?php echo ($diferenciaCapacidad > 0 ? '+' : '') . $diferenciaCapacidad; ?></strong>
                    </div>
                <?php else : ?>
                    <p class="reservation-detail-warning">Sin mesas asignadas</p>
                <?php endif; ?>
            </article>

            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Acciones operativas</span>
                        <h3>Gestion</h3>
                    </div>
                </div>

                <div class="reservation-detail-actions">
                    <a class="admin-btn admin-btn--secondary" href="<?php echo $h($operationUrl); ?>" data-reservation-operational-action data-reservation-operational-control>Ver en mapa</a>

                    <?php if (!$estadoFinal && $editable) : ?>
                        <form method="POST" action="/admin/reservations/reassign" data-reservation-operational-action>
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
                            <button type="submit" class="admin-btn admin-btn--secondary" data-reservation-operational-control>Reasignar automaticamente</button>
                        </form>

                        <?php if ($estado === 'pendiente' && $tieneMesa) : ?>
                            <?php $statusForm($id, 'confirmada', $returnUrl, 'Confirmar', 'admin-btn admin-btn--primary'); ?>
                        <?php elseif ($estado === 'pendiente' && !$tieneMesa) : ?>
                            <p class="reservation-detail-actions__hint">Sin mesas asignadas. Asigna al menos una mesa antes de confirmar.</p>
                        <?php endif; ?>

                        <?php $statusForm($id, 'completada', $returnUrl, 'Completar', 'admin-btn admin-btn--ghost', 'Marcar esta reservacion como completada?'); ?>
                        <?php $statusForm($id, 'no_show', $returnUrl, 'Marcar no show', 'admin-btn admin-btn--ghost', 'Marcar esta reservacion como no show?'); ?>
                        <?php $statusForm($id, 'cancelada', $returnUrl, 'Cancelar reservacion', 'admin-btn admin-btn--danger', 'Cancelar esta reservacion?'); ?>
                    <?php else : ?>
                        <p class="reservation-detail-actions__muted">Esta reservacion esta en modo de solo lectura.</p>
                    <?php endif; ?>
                </div>
            </article>
        </aside>
    </div>
</section>
