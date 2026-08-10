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
$vigencia = is_array($vigencia ?? null) ? $vigencia : [];
$ticketAbierto = is_array($ticketAbierto ?? null) ? $ticketAbierto : null;
$ticketFisico = is_array($ticketFisico ?? null) ? $ticketFisico : $ticketAbierto;
$adminCsrfToken = (string)($adminCsrfToken ?? '');
$returnUrl = (string)($returnUrl ?? '/admin/reservaciones');
$backUrl = (string)($backUrl ?? '/admin/reservaciones');

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

$actionButton = static function (
    string $estado,
    string $label,
    string $class,
    bool $requiereMotivo = false
) use ($h): void {
    ?>
    <button
        type="button"
        class="<?php echo $h($class); ?>"
        data-reservation-action-open
        data-action-state="<?php echo $h($estado); ?>"
        data-action-label="<?php echo $h($label); ?>"
        data-action-reason="<?php echo $requiereMotivo ? '1' : '0'; ?>"
        data-reservation-operational-control
    ><?php echo $h($label); ?></button>
    <?php
};

$id = (int)$valor($reservacion, 'id', 0);
$nombre = (string)$valor($reservacion, 'nombre');
$contacto = (string)$valor($reservacion, 'contacto');
$contactoTipo = (string)$valor($reservacion, 'contacto_tipo', 'ninguno');
$origen = (string)$valor($reservacion, 'origen', 'admin');
$fecha = (string)$valor($reservacion, 'fecha');
$hora = (string)$valor($reservacion, 'hora');
$comensales = (int)$valor($reservacion, 'comensales', 0);
$nota = trim((string)$valor($reservacion, 'nota'));
$comentarioAdmin = (string)$valor($reservacion, 'comentario_admin');
$estado = (string)$valor($reservacion, 'estado', 'confirmada');
$createdAt = (string)$valor($reservacion, 'created_at', '');
$updatedAt = (string)$valor($reservacion, 'updated_at', '');
$stateChangedAt = (string)$valor($reservacion, 'estado_changed_at', '');
$reemplazaId = (int)$valor($reservacion, 'reemplaza_reservacion_id', 0);
$mesasCount = count($mesasAsignadas);
$tieneMesa = $mesasCount > 0;
$estadoFinal = in_array($estado, ['completada', 'cancelada', 'no_show', 'expirada'], true);
$puedeAsignar = $editable;
$capacidadRestaurante = max((int)($capacidadRestaurante ?? 0), $comensales, 1);
$diferenciaCapacidad = $capacidadTotal - $comensales;
$horaCorta = $horaLegible($hora);
$operationUrl = '/admin/reservaciones/operacion?' . http_build_query([
    'fecha' => $fecha,
    'hora' => $horaCorta,
    'reservation_id' => $id,
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
            <h1 class="admin-page__title"><?php echo $nombre !== '' ? $h($nombre) : 'Detalle de reservacion'; ?></h1>
        </div>
        <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
            <?php echo $h(($estadoLabels[$estado] ?? ucfirst($estado)) . (!empty($vigencia['tolerancia_vencida']) ? ' · Tolerancia vencida' : '')); ?>
        </span>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atencion' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>" role="<?php echo $tipoAlerta === 'error' ? 'alert' : 'status'; ?>" aria-live="polite">
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
                        <dt>Origen</dt>
                        <dd><?php echo $origen === 'admin' ? 'Administrativa' : 'Landing publica'; ?></dd>
                    </div>
                    <div>
                        <dt>Contacto</dt>
                        <dd><?php echo $contacto !== '' ? $h($contacto) : 'Sin contacto'; ?></dd>
                    </div>
                    <div>
                        <dt>Tipo de contacto</dt>
                        <dd><?php echo $h($contactoTipo === 'ninguno' ? 'Sin contacto' : ucfirst($contactoTipo)); ?></dd>
                    </div>
                    <?php if (!empty($vigencia['tolerancia_vencida'])) : ?>
                        <div>
                            <dt>Condición operativa</dt>
                            <dd>Tolerancia vencida</dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($ticketAbierto) : ?>
                        <div>
                            <dt>Ticket abierto</dt>
                            <dd>#<?php echo (int)$ticketAbierto['id']; ?></dd>
                        </div>
                    <?php elseif ($ticketFisico) : ?>
                        <div>
                            <dt>Ticket ligado</dt>
                            <dd>#<?php echo (int)$ticketFisico['id']; ?> (<?php echo $h($ticketFisico['estado'] ?? 'cerrado'); ?>)</dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Comensales</dt>
                        <dd><?php echo $h($plural($comensales, 'persona', 'personas')); ?></dd>
                    </div>
                    <div>
                        <dt>Mesas</dt>
                        <dd><?php echo $tieneMesa ? $h($plural($mesasCount, 'mesa asignada', 'mesas asignadas')) : 'Pendiente de asignar mesas'; ?></dd>
                    </div>
                    <?php if ($createdAt !== '') : ?>
                        <div>
                            <dt>Creacion</dt>
                            <dd><?php echo $h($fechaHoraLegible($createdAt)); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($stateChangedAt !== '') : ?>
                        <div>
                            <dt>Ultimo cambio de estado</dt>
                            <dd><?php echo $h($fechaHoraLegible($stateChangedAt)); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($updatedAt !== '') : ?>
                        <div>
                            <dt>Ultima actualizacion</dt>
                            <dd><?php echo $h($fechaHoraLegible($updatedAt)); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($reemplazaId > 0) : ?>
                        <div>
                            <dt>Reemplaza</dt>
                            <dd>#<?php echo $reemplazaId; ?></dd>
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

            <?php if ($ticketFisico) : ?>
                <article class="reservation-detail-card admin-card">
                    <div class="reservation-detail-card__head">
                        <div>
                            <span class="reservation-detail-card__label">Relacion fisica</span>
                            <h3>Ticket #<?php echo (int)$ticketFisico['id']; ?></h3>
                        </div>
                    </div>
                    <p class="reservation-detail-empty">Estado: <?php echo $h($ticketFisico['estado'] ?? ''); ?> · Mesas fisicas: <?php echo $ticketFisico['mesa_ids'] !== [] ? $h(implode(', ', array_map('strval', (array)$ticketFisico['mesa_ids']))) : 'Sin mesas'; ?></p>
                </article>
            <?php endif; ?>
        </section>

        <aside class="reservation-detail-side">
            <article class="reservation-detail-card admin-card">
                <div class="reservation-detail-card__head">
                    <div>
                        <span class="reservation-detail-card__label">Mesas asignadas</span>
                        <h3><?php echo $tieneMesa ? $h($plural($mesasCount, 'mesa asignada', 'mesas asignadas')) : 'Pendiente de asignar mesas'; ?></h3>
                    </div>
                    <?php if (!$tieneMesa) : ?>
                        <span class="admin-badge admin-badge--warning">Pendiente de asignar</span>
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
                        <p class="reservation-detail-warning">Pendiente de asignar mesas. La capacidad y el horario siguen siendo datos separados de la asignacion.</p>
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
                    <div class="reservation-detail-actions__primary">
                        <a class="admin-btn admin-btn--primary" href="<?php echo $h($operationUrl); ?>" data-reservation-operational-action data-reservation-operational-control>Gestionar en operacion</a>
                    </div>

                    <div class="reservation-detail-actions__secondary">
                    <?php if (!$estadoFinal) : ?>
                        <?php if ($editable) : ?>
                        <form method="POST" action="/admin/reservaciones/reasignar" data-reservation-operational-action>
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
                            <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
                            <button type="submit" class="admin-btn admin-btn--secondary" data-reservation-operational-control>Reasignar automaticamente</button>
                        </form>
                        <?php endif; ?>

                        <?php if (false && $estado === 'pendiente_verificacion' && $editable) : ?>
                            <?php $actionButton('confirmada', 'Confirmar verificación', 'admin-btn admin-btn--primary'); ?>
                        <?php endif; ?>
                        <?php if (!empty($vigencia['elegible_no_show'])) : ?>
                            <?php $actionButton('no_show', 'Registrar que el cliente no se presentó', 'admin-btn admin-btn--ghost'); ?>
                        <?php endif; ?>
                        <?php if (in_array($estado, ['confirmada', 'pendiente_verificacion'], true) && !$ticketAbierto) : ?>
                            <?php $actionButton('cancelada', 'Cancelar reservación', 'admin-btn admin-btn--danger', true); ?>
                        <?php endif; ?>
                        <?php if ($ticketAbierto) : ?>
                            <a class="admin-btn admin-btn--secondary" href="/punto-de-venta">Consultar ticket #<?php echo (int)$ticketAbierto['id']; ?></a>
                        <?php endif; ?>
                    <?php else : ?>
                        <p class="reservation-detail-actions__muted">Esta reservacion esta en modo de solo lectura.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </article>
        </aside>
    </div>

    <div class="reservation-action-confirmation-host" data-reservation-action-confirmation></div>
    <form method="POST" action="/admin/reservaciones/estado" data-reservation-action-form hidden>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
        <input type="hidden" name="estado" value="" data-reservation-action-state>
        <input type="hidden" name="motivo" value="" data-reservation-action-reason-value>
        <input type="hidden" name="return_to" value="<?php echo $h($returnUrl); ?>">
    </form>
</section>
