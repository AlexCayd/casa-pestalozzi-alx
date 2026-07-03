<?php
$reservacion = is_object($reservacion ?? null) ? $reservacion : null;
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$capacidadTotal = (int)($capacidadTotal ?? 0);
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
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

$actionForm = static function (
    string $action,
    int $id,
    string $returnUrl,
    string $label,
    string $class,
    bool $disabled = false,
    string $confirm = ''
) use ($h): void {
    ?>
    <form method="POST" action="<?php echo $h($action); ?>" <?php echo $confirm !== '' ? 'onsubmit="return confirm(\'' . $h($confirm) . '\')"' : ''; ?>>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
        <button type="submit" class="<?php echo $h($class); ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
            <?php echo $h($label); ?>
        </button>
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
$estado = (string)$valor($reservacion, 'estado', 'pendiente');
$createdAt = (string)$valor($reservacion, 'created_at', '');
$mesasCount = count($mesasAsignadas);
$tieneMesa = $mesasCount > 0;
$estadoFinal = in_array($estado, ['completada', 'cancelada', 'no_show'], true);
$puedeConfirmar = $tieneMesa && !$estadoFinal && $estado !== 'confirmada';
$puedeReasignar = !$estadoFinal;
$puedeCancelar = !$estadoFinal;
$puedeCompletar = !$estadoFinal;
$puedeNoShow = !$estadoFinal;
?>

<section class="admin-reservations admin-reservation-detail admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Administración</span>
            <h2 class="admin-page__title">Detalle de reservación</h2>
            <p class="admin-page__subtitle">Consulta la información completa y gestiona el estado de la reservación.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $h($backUrl); ?>">Volver</a>
        </div>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atención' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <div class="reservation-detail-grid">
        <div class="reservation-detail-grid__main">
            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Información general</span>
                        <h3>Reservación #<?php echo $id; ?></h3>
                    </div>
                    <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
                        <?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?>
                    </span>
                </div>

                <dl class="reservation-detail-list reservation-detail-list--grid">
                    <div>
                        <dt>Fecha</dt>
                        <dd><?php echo $h($fechaLegible($fecha)); ?></dd>
                    </div>
                    <div>
                        <dt>Hora</dt>
                        <dd><?php echo $h($horaLegible($hora)); ?></dd>
                    </div>
                    <div>
                        <dt>Comensales</dt>
                        <dd><?php echo $h($plural($comensales, 'persona', 'personas')); ?></dd>
                    </div>
                    <?php if ($createdAt !== '') : ?>
                        <div>
                            <dt>Creación</dt>
                            <dd><?php echo $h($fechaHoraLegible($createdAt)); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </article>

            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Cliente</span>
                        <h3>Datos de contacto</h3>
                    </div>
                </div>

                <dl class="reservation-detail-list">
                    <div>
                        <dt>Nombre</dt>
                        <dd><?php echo $h($nombre); ?></dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><a href="mailto:<?php echo $h($email); ?>"><?php echo $h($email); ?></a></dd>
                    </div>
                </dl>
            </article>

            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Nota</span>
                        <h3>Comentarios de la reservación</h3>
                    </div>
                </div>

                <?php if ($nota !== '') : ?>
                    <p class="reservation-detail-note"><?php echo nl2br($h($nota)); ?></p>
                <?php else : ?>
                    <p class="reservation-detail-empty">Sin nota registrada.</p>
                <?php endif; ?>
            </article>
        </div>

        <aside class="reservation-detail-grid__side">
            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Mesas asignadas</span>
                        <h3><?php echo $tieneMesa ? $h($plural($mesasCount, 'mesa asignada', 'mesas asignadas')) : 'Sin mesas asignadas'; ?></h3>
                    </div>
                    <?php if (!$tieneMesa) : ?>
                        <span class="admin-badge admin-badge--warning">Requiere asignación</span>
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
                <?php else : ?>
                    <p class="reservation-detail-empty">Esta reservación necesita asignación de mesas antes de confirmarse.</p>
                <?php endif; ?>
            </article>

            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Gestión de reservación</span>
                        <h3>Acciones operativas</h3>
                    </div>
                </div>

                <div class="reservation-detail-actions">
                    <section class="reservation-detail-actions__group reservation-detail-actions__group--primary">
                        <h4>Acción principal</h4>
                        <?php if ($estado === 'pendiente' && !$tieneMesa) : ?>
                            <p class="reservation-detail-actions__hint">Requiere asignación de mesas antes de confirmar.</p>
                        <?php elseif ($estado === 'pendiente') : ?>
                            <?php $actionForm('/admin/reservations/confirm', $id, $returnUrl, 'Confirmar reservación', 'admin-btn admin-btn--primary', !$puedeConfirmar); ?>
                        <?php else : ?>
                            <p class="reservation-detail-actions__muted">No hay acción principal pendiente para este estado.</p>
                        <?php endif; ?>
                    </section>

                    <section class="reservation-detail-actions__group">
                        <h4>Mesas</h4>
                        <?php $actionForm('/admin/reservations/reassign', $id, $returnUrl, 'Reasignar automáticamente', 'admin-btn admin-btn--secondary', !$puedeReasignar); ?>
                    </section>

                    <section class="reservation-detail-actions__group">
                        <h4>Cierre operativo</h4>
                        <div class="reservation-detail-actions__row">
                            <?php $actionForm('/admin/reservations/complete', $id, $returnUrl, 'Completar', 'admin-btn admin-btn--ghost', !$puedeCompletar, 'Marcar esta reservación como completada?'); ?>
                            <?php $actionForm('/admin/reservations/no-show', $id, $returnUrl, 'No show', 'admin-btn admin-btn--ghost', !$puedeNoShow, 'Marcar esta reservación como no show?'); ?>
                        </div>
                    </section>

                    <section class="reservation-detail-actions__group reservation-detail-actions__group--danger">
                        <h4>Acción destructiva</h4>
                        <?php $actionForm('/admin/reservations/cancel', $id, $returnUrl, 'Cancelar reservación', 'admin-btn admin-btn--danger', !$puedeCancelar, 'Cancelar esta reservación?'); ?>
                    </section>
                </div>
            </article>
        </aside>
    </div>
</section>
