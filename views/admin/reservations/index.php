<?php
/**
 * Lista administrativa de reservaciones con filtros, metricas y acceso al detalle.
 */

$reservaciones = isset($reservaciones) && is_iterable($reservaciones) ? $reservaciones : [];
$metricas = is_array($metricas ?? null) ? $metricas : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$filtrosActivos = (bool)($filtrosActivos ?? false);
$partialOnly = (bool)($partialOnly ?? false);
$fechaDefault = \Services\ReservacionConfig::fechaActual();
$queryString = (string)($queryString ?? '');
$returnTo = '/admin/reservations' . ($queryString !== '' ? '?' . $queryString : '');
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$fechaOperacion = (string)($filtros['fecha_inicio'] ?? date('Y-m-d'));
$developmentTools = (bool)($developmentTools ?? false);

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaOperacion) !== 1) {
    $fechaOperacion = date('Y-m-d');
}

$operationUrl = '/admin/reservations/operation?fecha=' . rawurlencode($fechaOperacion);

$valor = static function ($item, string $campo, $default = '') {
    if (is_array($item)) {
        return $item[$campo] ?? $default;
    }

    if (is_object($item)) {
        return $item->$campo ?? $default;
    }

    return $default;
};

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$fechaLegible = static function ($fecha): string {
    $timestamp = strtotime((string)$fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : 'Sin fecha';
};

$horaLegible = static function ($hora): string {
    return substr((string)$hora, 0, 5);
};

$pluralPersonas = static function ($total): string {
    $total = (int)$total;
    return $total . ' ' . ($total === 1 ? 'persona' : 'personas');
};

$metricCards = [
    ['label' => 'Reservaciones totales', 'value' => $metricas['total'] ?? 0],
    ['label' => 'Por confirmar', 'value' => $metricas['pendientes'] ?? 0, 'tone' => 'pending'],
    ['label' => 'Sin mesa', 'value' => $metricas['sin_mesa'] ?? 0, 'tone' => 'needs'],
];

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

<?php if (!$partialOnly) : ?>
<section class="admin-reservations admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Administración</span>
            <h1 class="admin-page__title">Reservaciones</h1>
            <p class="admin-page__subtitle">Consulta, confirma y administra las reservaciones del restaurante.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <?php if ($developmentTools) : ?>
                <a class="admin-btn admin-btn--secondary" href="/admin/reservations/development-tools">Herramientas de desarrollo</a>
            <?php endif; ?>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-reservations__header-action" href="/admin/reservations/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
                <span>Crear reservación</span>
            </a>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-reservations__header-action" href="<?php echo $h($operationUrl); ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"/>
                    <path d="M9 3v15"/>
                    <path d="M15 6v15"/>
                </svg>
                <span>Vista operativa</span>
            </a>
        </div>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atención' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>" role="<?php echo $tipoAlerta === 'error' ? 'alert' : 'status'; ?>" aria-live="polite">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <form
        class="admin-filters admin-reservations__filters"
        method="GET"
        action="/admin/reservations"
        aria-label="Filtros de reservaciones"
        data-reactive-filters
        data-reactive-target="#reservations-results"
        data-reactive-loading="#reservations-results-loading"
        data-reactive-error="#reservations-results-error"
        data-reactive-debounce="350"
    >
        <div class="admin-filters__search">
            <label for="reservations-q">Buscar</label>
            <input
                id="reservations-q"
                type="search"
                data-reactive-control
                data-reactive-default=""
                name="q"
                value="<?php echo $h($filtros['q'] ?? ''); ?>"
                placeholder="Nombre o correo"
            >
        </div>
        <div class="admin-filters__group">
            <label for="reservations-fecha-inicio">Desde</label>
            <input
                id="reservations-fecha-inicio"
                type="date"
                data-reactive-control
                data-reactive-default="<?php echo $h($fechaDefault); ?>"
                name="fecha_inicio"
                value="<?php echo $h($filtros['fecha_inicio'] ?? date('Y-m-d')); ?>"
            >
        </div>
        <div class="admin-filters__group">
            <label for="reservations-fecha-fin">Hasta</label>
            <input
                id="reservations-fecha-fin"
                type="date"
                data-reactive-control
                data-reactive-default="<?php echo $h($fechaDefault); ?>"
                name="fecha_fin"
                value="<?php echo $h($filtros['fecha_fin'] ?? date('Y-m-d')); ?>"
            >
        </div>
        <div class="admin-filters__group">
            <label for="reservations-estado">Estado</label>
            <select id="reservations-estado" name="estado" data-reactive-control data-reactive-default="">
                <option value="">Todos</option>
                <?php foreach ($estadoLabels as $estado => $label) : ?>
                    <option value="<?php echo $h($estado); ?>" <?php echo ($filtros['estado'] ?? '') === $estado ? 'selected' : ''; ?>>
                        <?php echo $h($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filters__group">
            <label for="reservations-asignacion">Asignación</label>
            <select id="reservations-asignacion" name="asignacion" data-reactive-control data-reactive-default="">
                <option value="">Todas</option>
                <option value="con_mesa" <?php echo ($filtros['asignacion'] ?? '') === 'con_mesa' ? 'selected' : ''; ?>>Con mesa</option>
                <option value="sin_mesa" <?php echo ($filtros['asignacion'] ?? '') === 'sin_mesa' ? 'selected' : ''; ?>>Sin mesas asignadas</option>
            </select>
        </div>
        <div class="admin-filters__group">
            <label for="reservations-origen">Origen</label>
            <select id="reservations-origen" name="origen" data-reactive-control data-reactive-default="">
                <option value="">Todos</option>
                <option value="admin" <?php echo ($filtros['origen'] ?? '') === 'admin' ? 'selected' : ''; ?>>Administrativa</option>
                <option value="landing" <?php echo ($filtros['origen'] ?? '') === 'landing' ? 'selected' : ''; ?>>Landing publica</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-reactive-submit>Buscar</button>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservations" data-reactive-clear <?php echo !$filtrosActivos ? 'hidden' : ''; ?>>Limpiar filtros</a>
        </div>
    </form>

    <div class="admin-reactive-results-shell">
        <div id="reservations-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
    <section class="admin-reservations__metrics" aria-label="Métricas de reservaciones">
        <div class="admin-reservations__metrics-grid">
            <?php foreach ($metricCards as $card) : ?>
                <article class="admin-reservations__metric <?php echo !empty($card['tone']) ? 'admin-reservations__metric--' . $h($card['tone']) : ''; ?>">
                    <span><?php echo $h($card['label']); ?></span>
                    <strong><?php echo (int)$card['value']; ?></strong>
                </article>
            <?php endforeach; ?>
            <article class="admin-reservations__metric admin-reservations__metric--other" aria-label="Otros estados">
                <dl class="admin-reservations__other-states">
                    <div><dt>Confirmadas</dt><dd><?php echo (int)($metricas['confirmadas'] ?? 0); ?></dd></div>
                    <div><dt>Completadas</dt><dd><?php echo (int)($metricas['completadas'] ?? 0); ?></dd></div>
                    <div><dt>Canceladas</dt><dd><?php echo (int)($metricas['canceladas'] ?? 0); ?></dd></div>
                    <div><dt>No show</dt><dd><?php echo (int)($metricas['no_show'] ?? 0); ?></dd></div>
                </dl>
            </article>
        </div>
    </section>

    <section class="reservations-table-card admin-card" aria-labelledby="reservations-results-title">
        <div class="reservations-table-card__header">
            <div>
                <?php $totalEncontradas = count($reservaciones); ?>
                <h2 id="reservations-results-title"><?php echo $totalEncontradas; ?> <?php echo $totalEncontradas === 1 ? 'reservación encontrada' : 'reservaciones encontradas'; ?></h2>
                <p>Periodo: <?php echo $h($fechaLegible($filtros['fecha_inicio'] ?? date('Y-m-d'))); ?> - <?php echo $h($fechaLegible($filtros['fecha_fin'] ?? date('Y-m-d'))); ?></p>
            </div>
        </div>

        <?php if (empty($reservaciones)) : ?>
            <div class="admin-menu__empty admin-empty">
                <p>No se encontraron reservaciones con los filtros aplicados.</p>
                <?php if ($filtrosActivos) : ?>
                    <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservations" data-reactive-clear>Limpiar filtros</a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="reservations-table-wrapper">
                <table class="reservations-table">
                    <caption class="admin-visually-hidden">Reservaciones encontradas en el periodo seleccionado</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Hora</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Origen</th>
                            <th scope="col">Comensales</th>
                            <th scope="col">Asignación</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Nota</th>
                            <th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservaciones as $reservacion) : ?>
                            <?php
                            $id = (int)$valor($reservacion, 'id', 0);
                            $nombre = (string)$valor($reservacion, 'nombre');
                            $contacto = (string)$valor($reservacion, 'contacto');
                            $fecha = (string)$valor($reservacion, 'fecha');
                            $hora = (string)$valor($reservacion, 'hora');
                            $comensales = (int)$valor($reservacion, 'comensales', 0);
                            $nota = trim((string)$valor($reservacion, 'nota'));
                            $estado = (string)$valor($reservacion, 'estado', 'confirmada');
                            $mesasCount = (int)$valor($reservacion, 'mesas_count', 0);
                            $tieneMesa = $mesasCount > 0;
                            $origen = (string)$valor($reservacion, 'origen', 'admin');
                            $showUrl = '/admin/reservations/show?id=' . $id . '&return_url=' . rawurlencode($returnTo);
                            $operationContextUrl = '/admin/reservations/operation?' . http_build_query([
                                'fecha' => $fecha,
                                'hora' => $horaLegible($hora),
                                'reservation_id' => $id,
                                'return_url' => $returnTo,
                            ]);
                            ?>
                            <tr>
                                <td class="reservations-table__date-cell">
                                    <span class="reservations-table__date"><?php echo $h($fechaLegible($fecha)); ?></span>
                                </td>
                                <td class="reservations-table__time-cell">
                                    <span class="reservations-table__time"><?php echo $h($horaLegible($hora)); ?></span>
                                </td>
                                <td class="reservations-table__customer-cell">
                                    <div class="reservations-table__customer">
                                        <strong><?php echo $h($nombre); ?></strong>
                                        <span><?php echo $contacto !== '' ? $h($contacto) : 'Sin contacto'; ?></span>
                                    </div>
                                </td>
                                <td class="reservations-table__origin-cell">
                                    <span class="admin-badge admin-badge--<?php echo $origen === 'admin' ? 'info' : 'neutral'; ?>"><?php echo $origen === 'admin' ? 'Administrativa' : 'Landing'; ?></span>
                                </td>
                                <td class="reservations-table__guests-cell">
                                    <span class="reservations-table__guests"><?php echo $h($pluralPersonas($comensales)); ?></span>
                                </td>
                                <td class="reservations-table__tables-cell">
                                    <span class="reservations-table__assignment">
                                        <?php echo $tieneMesa
                                            ? $mesasCount . ' ' . ($mesasCount === 1 ? 'mesa' : 'mesas')
                                            : 'Sin mesas'; ?>
                                    </span>
                                </td>
                                <td class="reservations-table__status-cell">
                                    <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
                                        <?php echo $h(
                                            ($estadoLabels[$estado] ?? ucfirst($estado))
                                            . (!empty($valor($reservacion, 'tolerancia_vencida', false)) ? ' · Tolerancia vencida' : '')
                                        ); ?>
                                    </span>
                                </td>
                                <td class="reservations-table__note-cell">
                                    <?php if ($nota !== '') : ?>
                                        <span class="reservations-table__note" title="<?php echo $h($nota); ?>"><?php echo $h($nota); ?></span>
                                    <?php else : ?>
                                        <span class="reservations-table__muted">Sin nota</span>
                                    <?php endif; ?>
                                </td>
                                <td class="reservations-table__actions-cell">
                                    <div class="reservations-table__actions">
                                        <a class="admin-btn admin-btn--small admin-btn--secondary" href="<?php echo $h($showUrl); ?>" title="Ver detalle de reservación" aria-label="Ver detalle de <?php echo $h($nombre); ?>, <?php echo $h($fechaLegible($fecha)); ?> a las <?php echo $h($horaLegible($hora)); ?>">Ver</a>
                                        <a
                                            class="admin-btn admin-btn--small admin-btn--ghost reservations-table__operate-action"
                                            href="<?php echo $h($operationContextUrl); ?>"
                                            title="Abrir esta reservación en la vista operativa"
                                            aria-label="Abrir esta reservación en la vista operativa"
                                        >
                                            <span>Operar</span>
                                            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M7 17 17 7"/>
                                                <path d="M7 7h10v10"/>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="reservations-results-loading" role="status" hidden>Actualizando resultados</div>
    </div>
    <div class="admin-reactive-error" id="reservations-results-error" role="alert" hidden>
        <span>No fue posible actualizar las reservaciones.</span>
        <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-reactive-retry>Volver a intentar</button>
    </div>
</section>
<?php endif; ?>
