<?php
$reservaciones = isset($reservaciones) && is_iterable($reservaciones) ? $reservaciones : [];
$metricas = is_array($metricas ?? null) ? $metricas : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$filtrosActivos = (bool)($filtrosActivos ?? false);
$queryString = (string)($queryString ?? '');
$returnTo = '/admin/reservations' . ($queryString !== '' ? '?' . $queryString : '');
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$fechaOperacion = (string)($filtros['fecha_inicio'] ?? date('Y-m-d'));

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

$mesasListado = static function (string $mesas): array {
    $partes = preg_split('/\s*,\s*/', $mesas, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(array_map('trim', $partes ?: [])));
};

$metricCards = [
    ['label' => 'Total', 'value' => $metricas['total'] ?? 0],
    ['label' => 'Pendientes', 'value' => $metricas['pendientes'] ?? 0, 'tone' => 'pending'],
    ['label' => 'Confirmadas', 'value' => $metricas['confirmadas'] ?? 0, 'tone' => 'confirmed'],
    ['label' => 'Completadas', 'value' => $metricas['completadas'] ?? 0, 'tone' => 'completed'],
    ['label' => 'Canceladas', 'value' => $metricas['canceladas'] ?? 0, 'tone' => 'cancelled'],
    ['label' => 'No show', 'value' => $metricas['no_show'] ?? 0, 'tone' => 'noshow'],
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

<section class="admin-reservations admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Administración</span>
            <h2 class="admin-page__title">Reservaciones</h2>
            <p class="admin-page__subtitle">Consulta, confirma y administra las reservaciones del restaurante.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="<?php echo $h($operationUrl); ?>">Vista operativa</a>
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

    <section class="admin-reservations__metrics" aria-label="Métricas de reservaciones">
        <?php foreach ($metricCards as $card) : ?>
            <article class="admin-reservations__metric <?php echo !empty($card['tone']) ? 'admin-reservations__metric--' . $h($card['tone']) : ''; ?>">
                <span><?php echo $h($card['label']); ?></span>
                <strong><?php echo (int)$card['value']; ?></strong>
            </article>
        <?php endforeach; ?>
    </section>

    <form class="admin-filters admin-reservations__filters" method="GET" action="/admin/reservations">
        <div class="admin-filters__search">
            <label for="reservations-q">Buscar</label>
            <input
                id="reservations-q"
                type="search"
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
                name="fecha_inicio"
                value="<?php echo $h($filtros['fecha_inicio'] ?? date('Y-m-d')); ?>"
            >
        </div>
        <div class="admin-filters__group">
            <label for="reservations-fecha-fin">Hasta</label>
            <input
                id="reservations-fecha-fin"
                type="date"
                name="fecha_fin"
                value="<?php echo $h($filtros['fecha_fin'] ?? date('Y-m-d')); ?>"
            >
        </div>
        <div class="admin-filters__group">
            <label for="reservations-estado">Estado</label>
            <select id="reservations-estado" name="estado">
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
            <select id="reservations-asignacion" name="asignacion">
                <option value="">Todas</option>
                <option value="con_mesa" <?php echo ($filtros['asignacion'] ?? '') === 'con_mesa' ? 'selected' : ''; ?>>Con mesa</option>
                <option value="sin_mesa" <?php echo ($filtros['asignacion'] ?? '') === 'sin_mesa' ? 'selected' : ''; ?>>Sin mesa</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Buscar</button>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservations">Limpiar</a>
        </div>
    </form>

    <section class="reservations-table-card admin-card">
        <div class="reservations-table-card__header">
            <div>
                <h3><?php echo count($reservaciones); ?> reservaciones</h3>
                <p>Periodo: <?php echo $h($fechaLegible($filtros['fecha_inicio'] ?? date('Y-m-d'))); ?> - <?php echo $h($fechaLegible($filtros['fecha_fin'] ?? date('Y-m-d'))); ?></p>
            </div>
        </div>

        <?php if (empty($reservaciones)) : ?>
            <div class="admin-menu__empty admin-empty">
                <p>No se encontraron reservaciones con los filtros aplicados.</p>
                <?php if ($filtrosActivos) : ?>
                    <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservations">Limpiar filtros</a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="reservations-table-wrapper">
                <table class="reservations-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Cliente</th>
                            <th>Comensales</th>
                            <th>Mesas</th>
                            <th>Estado</th>
                            <th>Nota</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservaciones as $reservacion) : ?>
                            <?php
                            $id = (int)$valor($reservacion, 'id', 0);
                            $nombre = (string)$valor($reservacion, 'nombre');
                            $email = (string)$valor($reservacion, 'email');
                            $fecha = (string)$valor($reservacion, 'fecha');
                            $hora = (string)$valor($reservacion, 'hora');
                            $comensales = (int)$valor($reservacion, 'comensales', 0);
                            $nota = trim((string)$valor($reservacion, 'nota'));
                            $estado = (string)$valor($reservacion, 'estado', 'pendiente');
                            $mesas = trim((string)$valor($reservacion, 'mesas_asignadas'));
                            $mesasCount = (int)$valor($reservacion, 'mesas_count', 0);
                            $mesasDetalle = $mesasListado($mesas);
                            $mesasVisibles = array_slice($mesasDetalle, 0, 3);
                            $mesasRestantes = max(0, $mesasCount - count($mesasVisibles));
                            $tieneMesa = $mesasCount > 0;
                            $showUrl = '/admin/reservations/show?id=' . $id . '&return_url=' . rawurlencode($returnTo);
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
                                        <span><?php echo $h($email); ?></span>
                                    </div>
                                </td>
                                <td class="reservations-table__guests-cell">
                                    <span class="reservations-table__guests"><?php echo $h($pluralPersonas($comensales)); ?></span>
                                </td>
                                <td class="reservations-table__tables-cell">
                                    <?php if ($tieneMesa) : ?>
                                        <div class="reservations-table__tables">
                                            <strong><?php echo $mesasCount . ' ' . ($mesasCount === 1 ? 'mesa asignada' : 'mesas asignadas'); ?></strong>
                                            <?php if (!empty($mesasVisibles)) : ?>
                                                <span class="reservations-table__chips" aria-label="<?php echo $h($mesas); ?>">
                                                    <?php foreach ($mesasVisibles as $mesaAsignada) : ?>
                                                        <span class="reservations-table__chip"><?php echo $h($mesaAsignada); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ($mesasRestantes > 0) : ?>
                                                        <span class="reservations-table__chip reservations-table__chip--more">+<?php echo $mesasRestantes; ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else : ?>
                                                <span class="reservations-table__muted"><?php echo $h($mesas); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <div class="reservations-table__needs-table">
                                            <strong>Sin mesa</strong>
                                            <span class="admin-badge admin-badge--warning">Requiere asignación</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="reservations-table__status-cell">
                                    <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
                                        <?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?>
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
                                        <a class="admin-btn admin-btn--small admin-btn--secondary" href="<?php echo $h($showUrl); ?>" title="Ver detalle de reservación">Ver</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
