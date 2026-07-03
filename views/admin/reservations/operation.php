<?php
$filtros = is_array($filtros ?? null) ? $filtros : [];
$horarios = isset($horarios) && is_iterable($horarios) ? $horarios : [];
$horarios = is_array($horarios) ? $horarios : iterator_to_array($horarios);
$reservaciones = isset($reservaciones) && is_iterable($reservaciones) ? $reservaciones : [];
$reservaciones = is_array($reservaciones) ? $reservaciones : iterator_to_array($reservaciones);
$mesas = isset($mesas) && is_iterable($mesas) ? $mesas : [];
$mesas = is_array($mesas) ? $mesas : iterator_to_array($mesas);
$mesasAsignadas = isset($mesasAsignadas) && is_iterable($mesasAsignadas) ? $mesasAsignadas : [];
$mesasAsignadas = is_array($mesasAsignadas) ? $mesasAsignadas : iterator_to_array($mesasAsignadas);
$ocupacion = is_array($ocupacion ?? null) ? $ocupacion : [];
$reservacionSeleccionada = is_object($reservacionSeleccionada ?? null) ? $reservacionSeleccionada : null;
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$returnUrl = (string)($returnUrl ?? '');
$currentUrl = (string)($currentUrl ?? '/admin/reservations/operation');
$comentarioAdminDisponible = (bool)($comentarioAdminDisponible ?? false);
$capacidadAsignada = (int)($capacidadAsignada ?? 0);

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

$horaLegible = static function ($hora): string {
    return substr((string)$hora, 0, 5);
};

$fechaLegible = static function ($fecha): string {
    $timestamp = strtotime((string)$fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : 'Sin fecha';
};

$plural = static function (int $total, string $singular, string $plural): string {
    return $total . ' ' . ($total === 1 ? $singular : $plural);
};

$textoCorto = static function ($texto, int $limite = 96): string {
    $texto = trim((string)$texto);

    if ($texto === '') {
        return '';
    }

    return strlen($texto) > $limite ? substr($texto, 0, $limite - 3) . '...' : $texto;
};

$mesasListado = static function (string $mesas): array {
    $partes = preg_split('/\s*,\s*/', $mesas, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(array_map('trim', $partes ?: [])));
};

$operationSelectionUrl = static function (int $reservacionId) use ($filtros, $returnUrl): string {
    $query = [
        'fecha' => $filtros['fecha'] ?? date('Y-m-d'),
        'hora' => $filtros['hora'] ?? '',
        'reservacion_id' => $reservacionId,
    ];

    if (!empty($filtros['estado'])) {
        $query['estado'] = $filtros['estado'];
    }

    if ($returnUrl !== '') {
        $query['return_url'] = $returnUrl;
    }

    return '/admin/reservations/operation?' . http_build_query($query);
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

$selectedId = $reservacionSeleccionada ? (int)$valor($reservacionSeleccionada, 'id', 0) : 0;
$selectedEstado = $reservacionSeleccionada ? (string)$valor($reservacionSeleccionada, 'estado', 'pendiente') : '';
$estadoActivo = in_array($selectedEstado, ['pendiente', 'confirmada'], true);
$puedeAsignar = $selectedId > 0 && $estadoActivo;
$assignedIds = array_map(static function ($mesa) use ($valor): int {
    return (int)$valor($mesa, 'id', 0);
}, $mesasAsignadas);
$assignedIds = array_values(array_filter($assignedIds));
$assignedNames = array_map(static function ($mesa) use ($valor): string {
    return (string)$valor($mesa, 'nombre', '');
}, $mesasAsignadas);
$selectedComensales = $reservacionSeleccionada ? (int)$valor($reservacionSeleccionada, 'comensales', 0) : 0;
$selectedComentario = $reservacionSeleccionada ? (string)$valor($reservacionSeleccionada, 'comentario_admin', '') : '';
$selectedNota = $reservacionSeleccionada ? trim((string)$valor($reservacionSeleccionada, 'nota', '')) : '';

$quickActionForm = static function (
    string $action,
    string $label,
    string $class,
    bool $disabled = false
) use ($h, $selectedId, $currentUrl): void {
    ?>
    <form method="POST" action="<?php echo $h($action); ?>">
        <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
        <input type="hidden" name="return_to" value="<?php echo $h($currentUrl); ?>">
        <button type="submit" class="<?php echo $h($class); ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
            <?php echo $h($label); ?>
        </button>
    </form>
    <?php
};
?>

<section
    class="admin-reservations admin-reservation-operation admin-map mapa-page admin-page"
    data-page="reservation-operation"
    data-required-guests="<?php echo $selectedComensales; ?>"
>
    <header class="admin-menu__header admin-page__header reservation-operation__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Recepcion</span>
            <h2 class="admin-page__title">Operacion de reservaciones</h2>
            <p class="admin-page__subtitle">Gestiona las reservaciones y mesas por horario.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <?php if ($returnUrl !== '') : ?>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $h($returnUrl); ?>">Volver al detalle</a>
            <?php endif; ?>
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

    <form class="admin-filters reservation-operation__filters" method="GET" action="/admin/reservations/operation">
        <div class="admin-filters__group">
            <label for="operation-fecha">Fecha</label>
            <input id="operation-fecha" type="date" name="fecha" value="<?php echo $h($filtros['fecha'] ?? date('Y-m-d')); ?>">
        </div>
        <div class="admin-filters__group">
            <label for="operation-hora">Hora</label>
            <select id="operation-hora" name="hora">
                <?php if (empty($horarios)) : ?>
                    <option value="<?php echo $h($filtros['hora'] ?? '09:00'); ?>">Sin horarios configurados</option>
                <?php else : ?>
                    <?php foreach ($horarios as $horario) : ?>
                        <?php $horaOption = $horaLegible($valor($horario, 'hora')); ?>
                        <option value="<?php echo $h($horaOption); ?>" <?php echo ($filtros['hora'] ?? '') === $horaOption ? 'selected' : ''; ?>>
                            <?php echo $h($horaOption); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="admin-filters__group">
            <label for="operation-estado">Estado</label>
            <select id="operation-estado" name="estado">
                <option value="">Todos</option>
                <?php foreach ($estadoLabels as $estado => $label) : ?>
                    <option value="<?php echo $h($estado); ?>" <?php echo ($filtros['estado'] ?? '') === $estado ? 'selected' : ''; ?>>
                        <?php echo $h($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($returnUrl !== '') : ?>
            <input type="hidden" name="return_url" value="<?php echo $h($returnUrl); ?>">
        <?php endif; ?>
        <?php if ($selectedId > 0) : ?>
            <input type="hidden" name="reservacion_id" value="<?php echo $selectedId; ?>">
        <?php endif; ?>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Consultar</button>
        </div>
    </form>

    <div class="reservation-operation__layout">
        <section class="reservation-operation__reservations admin-card">
            <div class="mapa-sidebar-head">
                <span class="mapa-sidebar-title">Reservaciones del horario</span>
                <span class="mapa-reserva-count"><?php echo count($reservaciones); ?></span>
            </div>

            <div class="reservation-operation__slot">
                <strong><?php echo $h($fechaLegible($filtros['fecha'] ?? date('Y-m-d'))); ?></strong>
                <span><?php echo $h($filtros['hora'] ?? ''); ?></span>
            </div>

            <div class="reservation-operation__reservation-list">
                <?php if (empty($reservaciones)) : ?>
                    <div class="mapa-empty-state">
                        <span class="mapa-empty-icon">o</span>
                        <span>No hay reservaciones para este horario.</span>
                    </div>
                <?php else : ?>
                    <?php foreach ($reservaciones as $reservacion) : ?>
                        <?php
                        $id = (int)$valor($reservacion, 'id', 0);
                        $estado = (string)$valor($reservacion, 'estado', 'pendiente');
                        $mesasTexto = trim((string)$valor($reservacion, 'mesas_asignadas'));
                        $mesasCount = (int)$valor($reservacion, 'mesas_count', 0);
                        $nota = $textoCorto($valor($reservacion, 'nota', ''), 88);
                        $comentario = $textoCorto($valor($reservacion, 'comentario_admin', ''), 88);
                        $requiereAsignacion = $mesasCount === 0 && in_array($estado, ['pendiente', 'confirmada'], true);
                        $mesasCard = $mesasListado($mesasTexto);
                        ?>
                        <article class="reservation-operation-card <?php echo $id === $selectedId ? 'is-selected' : ''; ?>">
                            <div class="reservation-operation-card__head">
                                <div>
                                    <strong><?php echo $h($valor($reservacion, 'nombre')); ?></strong>
                                    <span><?php echo $h($valor($reservacion, 'email')); ?></span>
                                </div>
                                <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
                                    <?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?>
                                </span>
                            </div>
                            <div class="reservation-operation-card__meta">
                                <span><?php echo $h($horaLegible($valor($reservacion, 'hora'))); ?></span>
                                <span><?php echo $h($plural((int)$valor($reservacion, 'comensales', 0), 'persona', 'personas')); ?></span>
                            </div>
                            <div class="reservation-operation-card__tables">
                                <?php if (!empty($mesasCard)) : ?>
                                    <?php foreach (array_slice($mesasCard, 0, 3) as $mesaNombre) : ?>
                                        <span><?php echo $h($mesaNombre); ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($mesasCount > 3) : ?>
                                        <span>+<?php echo $mesasCount - 3; ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="reservation-operation-card__muted">Sin mesas</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($nota !== '') : ?>
                                <p><?php echo $h($nota); ?></p>
                            <?php endif; ?>
                            <?php if ($comentario !== '') : ?>
                                <p class="reservation-operation-card__internal"><?php echo $h($comentario); ?></p>
                            <?php endif; ?>
                            <div class="reservation-operation-card__footer">
                                <?php if ($requiereAsignacion) : ?>
                                    <span class="admin-badge admin-badge--warning">Requiere asignacion</span>
                                <?php else : ?>
                                    <span></span>
                                <?php endif; ?>
                                <a class="admin-btn admin-btn--small admin-btn--secondary" href="<?php echo $h($operationSelectionUrl($id)); ?>">Seleccionar</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <div class="reservation-operation__workspace">
            <section class="reservation-operation__map admin-card">
                <div class="reservation-operation__map-head">
                    <div>
                        <span class="mapa-sidebar-title">Mapa de mesas</span>
                    </div>
                    <div class="mapa-leyenda">
                        <span class="mapa-leyenda-item mapa-leyenda-item--libre">Libre</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--ocupada">Ocupada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--bloqueada">Asignada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--seleccionada">Seleccionada</span>
                        <span class="mapa-leyenda-item mapa-leyenda-item--zona">No reservable</span>
                    </div>
                </div>

                <form id="operation-assign-form" class="reservation-operation__map-form" method="POST" action="/admin/reservations/operation/assign-tables">
                    <input type="hidden" name="reservacion_id" value="<?php echo $selectedId; ?>">
                    <input type="hidden" name="fecha" value="<?php echo $h($filtros['fecha'] ?? date('Y-m-d')); ?>">
                    <input type="hidden" name="hora" value="<?php echo $h($filtros['hora'] ?? ''); ?>">
                    <?php if ($returnUrl !== '') : ?>
                        <input type="hidden" name="return_url" value="<?php echo $h($returnUrl); ?>">
                    <?php endif; ?>

                    <div class="mapa-canvas-wrap reservation-operation__canvas-wrap">
                        <div class="mapa-canvas reservation-operation__canvas">
                            <?php foreach ($mesas as $mesa) : ?>
                                <?php
                                $mesaId = (int)$valor($mesa, 'id', 0);
                                $mesaNombre = (string)$valor($mesa, 'nombre');
                                $mesaCapacidad = (int)$valor($mesa, 'capacidad', 0);
                                $mesaTipo = (string)$valor($mesa, 'tipo', 'mesa');
                                $mesaActiva = (int)$valor($mesa, 'activo', 0) === 1;
                                $mesaReservable = (int)$valor($mesa, 'reservable', 0) === 1;
                                $mesaDisponibleParaReserva = $mesaActiva && $mesaReservable;
                                $asignadaSeleccionada = in_array($mesaId, $assignedIds, true);
                                $ocupada = $ocupacion[$mesaId] ?? null;
                                $ocupadaPorOtra = $ocupada && (int)($ocupada['reservacion_id'] ?? 0) !== $selectedId;
                                $estadoMesa = !$mesaDisponibleParaReserva ? 'zona' : ($asignadaSeleccionada ? 'bloqueada' : ($ocupadaPorOtra ? 'ocupada' : 'libre'));
                                $seleccionable = $puedeAsignar && $mesaDisponibleParaReserva && !$ocupadaPorOtra;
                                $checked = $asignadaSeleccionada;
                                $left = max(0, min(100, (float)$valor($mesa, 'pos_x', 50)));
                                $top = max(0, min(100, (float)$valor($mesa, 'pos_y', 50)));
                                if (!$mesaDisponibleParaReserva) {
                                    $title = $mesaNombre . ' no reservable';
                                } elseif ($ocupadaPorOtra) {
                                    $title = 'Ocupada por ' . (string)($ocupada['nombre'] ?? 'otra reservacion') . ' a las ' . $horaLegible($ocupada['hora'] ?? '');
                                } else {
                                    $title = $mesaNombre . ' disponible';
                                }
                                ?>
                                <?php if ($seleccionable) : ?>
                                    <input
                                        class="reservation-operation__table-check"
                                        id="operation-mesa-<?php echo $mesaId; ?>"
                                        type="checkbox"
                                        name="mesa_ids[]"
                                        value="<?php echo $mesaId; ?>"
                                        <?php echo $checked ? 'checked' : ''; ?>
                                    >
                                <?php endif; ?>
                                <button
                                    class="mesa-pin mesa-pin--tipo-<?php echo $h($mesaTipo); ?> mesa-pin--<?php echo $h($estadoMesa); ?> <?php echo $asignadaSeleccionada ? 'reservation-operation-pin--assigned' : ''; ?> <?php echo $checked ? 'mesa-pin--highlight reservation-operation-pin--selected' : ''; ?>"
                                    type="button"
                                    style="left: <?php echo $left; ?>%; top: <?php echo $top; ?>%;"
                                    title="<?php echo $h($title); ?>"
                                    data-operation-table
                                    data-table-id="<?php echo $mesaId; ?>"
                                    data-table-name="<?php echo $h($mesaNombre); ?>"
                                    data-capacity="<?php echo $mesaCapacidad; ?>"
                                    data-original-assigned="<?php echo $asignadaSeleccionada ? '1' : '0'; ?>"
                                    data-reservable="<?php echo $mesaReservable ? '1' : '0'; ?>"
                                    data-active="<?php echo $mesaActiva ? '1' : '0'; ?>"
                                    <?php echo $seleccionable ? '' : 'disabled'; ?>
                                >
                                    <span class="mesa-pin__label">
                                        <?php echo $h($mesaNombre); ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="reservation-operation__panel">
                <?php if (!$reservacionSeleccionada) : ?>
                    <article class="reservation-operation-panel admin-card">
                        <span class="reservation-operation-panel__label">Reservacion seleccionada</span>
                        <h3>Sin seleccion</h3>
                        <p class="reservation-operation-panel__muted">No hay reservacion seleccionada.</p>
                    </article>
                <?php else : ?>
                    <article class="reservation-operation-panel admin-card">
                        <div class="reservation-operation-panel__head">
                            <div>
                                <span class="reservation-operation-panel__label">Reservacion seleccionada</span>
                                <h3>#<?php echo $selectedId; ?> - <?php echo $h($valor($reservacionSeleccionada, 'nombre')); ?></h3>
                            </div>
                            <span class="reservations-table__status reservations-table__status--<?php echo $h($selectedEstado); ?>">
                                <?php echo $h($estadoLabels[$selectedEstado] ?? ucfirst($selectedEstado)); ?>
                            </span>
                        </div>

                        <dl class="reservation-operation-panel__facts">
                            <div>
                                <dt>Correo</dt>
                                <dd><?php echo $h($valor($reservacionSeleccionada, 'email')); ?></dd>
                            </div>
                            <div>
                                <dt>Comensales</dt>
                                <dd><?php echo $h($plural($selectedComensales, 'persona', 'personas')); ?></dd>
                            </div>
                            <div>
                                <dt>Hora</dt>
                                <dd><?php echo $h($horaLegible($valor($reservacionSeleccionada, 'hora'))); ?></dd>
                            </div>
                            <div>
                                <dt>Mesas actuales</dt>
                                <dd><?php echo empty($assignedNames) ? 'Sin mesas' : $h(implode(', ', array_filter($assignedNames))); ?></dd>
                            </div>
                        </dl>

                        <section class="reservation-operation-panel__section">
                            <h4>Asignacion manual</h4>
                            <?php if (!$puedeAsignar) : ?>
                                <p class="reservation-operation-panel__muted">Este estado no permite modificar mesas.</p>
                            <?php endif; ?>
                            <div class="reservation-operation-summary">
                                <div>
                                    <span>Requeridos</span>
                                    <strong><?php echo $selectedComensales; ?></strong>
                                </div>
                                <div>
                                    <span>Capacidad</span>
                                    <strong data-operation-selected-capacity><?php echo $capacidadAsignada; ?></strong>
                                </div>
                                <div>
                                    <span>Mesas</span>
                                    <strong data-operation-selected-count><?php echo count($assignedIds); ?></strong>
                                </div>
                            </div>
                            <p class="reservation-operation-panel__selected" data-operation-selected-tables>
                                <?php echo empty($assignedNames) ? 'Sin mesas seleccionadas' : $h(implode(', ', array_filter($assignedNames))); ?>
                            </p>
                            <button
                                class="admin-btn admin-btn--primary reservation-operation-panel__submit"
                                type="submit"
                                form="operation-assign-form"
                                data-operation-save
                                data-can-assign="<?php echo $puedeAsignar ? '1' : '0'; ?>"
                                <?php echo $puedeAsignar ? '' : 'disabled'; ?>
                            >
                                Guardar asignacion
                            </button>
                        </section>

                        <section class="reservation-operation-panel__section">
                            <h4>Nota del cliente</h4>
                            <?php if ($selectedNota !== '') : ?>
                                <p class="reservation-operation-panel__note"><?php echo nl2br($h($selectedNota)); ?></p>
                            <?php else : ?>
                                <p class="reservation-operation-panel__muted">Sin nota del cliente.</p>
                            <?php endif; ?>
                        </section>
                    </article>

                    <article class="reservation-operation-panel admin-card">
                        <div class="reservation-operation-panel__head">
                            <div>
                                <span class="reservation-operation-panel__label">Operacion interna</span>
                                <h3>Comentario y estado</h3>
                            </div>
                        </div>

                        <section class="reservation-operation-panel__section">
                            <h4>Comentario interno</h4>
                            <?php if ($comentarioAdminDisponible) : ?>
                                <form class="reservation-operation-comment" method="POST" action="/admin/reservations/operation/update-comment">
                                    <input type="hidden" name="reservacion_id" value="<?php echo $selectedId; ?>">
                                    <input type="hidden" name="fecha" value="<?php echo $h($filtros['fecha'] ?? date('Y-m-d')); ?>">
                                    <input type="hidden" name="hora" value="<?php echo $h($filtros['hora'] ?? ''); ?>">
                                    <?php if ($returnUrl !== '') : ?>
                                        <input type="hidden" name="return_url" value="<?php echo $h($returnUrl); ?>">
                                    <?php endif; ?>
                                    <textarea name="comentario_admin" rows="4" placeholder="Notas internas para recepcion y piso"><?php echo $h($selectedComentario); ?></textarea>
                                    <button type="submit" class="admin-btn admin-btn--secondary">Guardar comentario</button>
                                </form>
                            <?php else : ?>
                                <div class="reservation-operation-migration">
                                    <p>Aplica esta migracion para activar comentarios internos:</p>
                                    <code>ALTER TABLE reservaciones ADD COLUMN comentario_admin TEXT NULL AFTER nota;</code>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="reservation-operation-panel__section">
                            <h4>Acciones rapidas</h4>
                            <div class="reservation-operation-actions">
                                <?php $quickActionForm('/admin/reservations/confirm', 'Confirmar', 'admin-btn admin-btn--primary', $selectedEstado !== 'pendiente' || empty($assignedIds)); ?>
                                <?php $quickActionForm('/admin/reservations/complete', 'Completar', 'admin-btn admin-btn--secondary', !$estadoActivo); ?>
                                <?php $quickActionForm('/admin/reservations/no-show', 'No show', 'admin-btn admin-btn--ghost', !$estadoActivo); ?>
                                <?php $quickActionForm('/admin/reservations/cancel', 'Cancelar', 'admin-btn admin-btn--danger', !$estadoActivo); ?>
                            </div>
                        </section>
                    </article>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
