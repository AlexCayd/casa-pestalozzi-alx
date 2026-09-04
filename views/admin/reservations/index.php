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
$returnTo = '/admin/reservaciones' . ($queryString !== '' ? '?' . $queryString : '');
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$fechaOperacion = (string)($filtros['fecha_inicio'] ?? date('Y-m-d'));
$developmentTools = (bool)($developmentTools ?? false);

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaOperacion) !== 1) {
    $fechaOperacion = date('Y-m-d');
}

$operationUrl = '/admin/reservaciones/operacion?fecha=' . rawurlencode($fechaOperacion);
$rango = [
    'start' => (string)($filtros['fecha_inicio'] ?? $fechaDefault),
    'end' => (string)($filtros['fecha_fin'] ?? $fechaDefault),
    'preset' => 0,
    'label' => 'Periodo',
];

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

/*
 * Métricas accionables: cada una responde «¿qué tengo que hacer ahora?» y lleva
 * al filtro que la produce. Antes eran contadores sueltos que no se podían
 * pulsar, así que ver «3 sin mesa» obligaba a ir a buscarlas a mano.
 *
 * La tolerancia vencida se cuenta sobre las filas cargadas: la calcula
 * ReservacionService::clasificarVigencia() al vuelo y no hay columna que
 * consultar.
 */
$enTolerancia = 0;
foreach ($reservaciones as $r) {
    if (!empty($valor($r, 'tolerancia_vencida', false))) {
        $enTolerancia++;
    }
}

$urlFiltro = static function (array $cambios) use ($filtros): string {
    $query = array_merge($filtros, $cambios);
    $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);
    return '/admin/reservaciones' . ($query ? '?' . http_build_query($query) : '');
};

$metricCards = [
    [
        'label' => 'En el periodo',
        'value' => $metricas['total'] ?? 0,
        // «Todos los estados», no «con los filtros actuales»: la franja se
        // calcula a propósito SIN el filtro de estado (Reservacion::metricasAdmin
        // pasa $incluirEstado = false) para que cada tarjeta siga siendo un
        // atajo utilizable. Si se estrechara al filtro activo, pulsar «Por
        // confirmar» estando en «no show» daría cero. La etiqueta decía lo
        // contrario y hacía que el número pareciera discrepar de la tabla.
        'detalle' => 'Todos los estados del periodo',
        'url' => $urlFiltro(['estado' => '', 'asignacion' => '']),
    ],
    [
        'label' => 'Por confirmar',
        'value' => $metricas['pendientes'] ?? 0,
        'detalle' => 'Esperan verificación de contacto',
        'tone' => 'pending',
        'url' => $urlFiltro(['estado' => 'pendiente_verificacion', 'asignacion' => '']),
    ],
    [
        'label' => 'Sin mesa',
        'value' => $metricas['sin_mesa'] ?? 0,
        'detalle' => 'Confirmadas y aún sin asignar',
        'tone' => 'needs',
        'url' => $urlFiltro(['asignacion' => 'sin_mesa', 'estado' => '']),
    ],
    [
        'label' => 'Tolerancia vencida',
        'value' => $enTolerancia,
        'detalle' => 'Pasó su hora y nadie llegó',
        'tone' => 'late',
    ],
    [
        'label' => 'No show',
        'value' => $metricas['no_show'] ?? 0,
        'detalle' => 'Marcadas como ausencia',
        'tone' => 'other',
        'url' => $urlFiltro(['estado' => 'no_show', 'asignacion' => '']),
    ],
];

/*
 * Chips de los filtros aplicados. Se pintan dentro del bloque que sustituyen
 * los filtros reactivos, así se rehacen solos con cada búsqueda. Cada uno es un
 * enlace que quita ese filtro: sin JS de por medio y con una URL compartible.
 */
$etiquetaAsignacion = ['con_mesa' => 'Con mesa', 'sin_mesa' => 'Sin mesas asignadas'];
$etiquetaOrigen = ['admin' => 'Administrativa', 'landing' => 'Landing pública'];
$chipsFiltro = [];

/*
 * El periodo va SIEMPRE, y va primero.
 *
 * Era el único filtro sin chip, y justamente el que puede vaciar la tabla: con
 * la ventana por defecto en un solo día la pantalla decía «0 reservaciones» sin
 * nada visible que explicara por qué. Cuando coincide con la ventana por
 * defecto el chip es informativo (no lleva enlace de quitar); en cuanto el
 * usuario lo mueve, se vuelve removible como los demás.
 */
$rangoInicio = (string)($filtros['fecha_inicio'] ?? '');
$rangoFin = (string)($filtros['fecha_fin'] ?? '');
$diaBonito = static function (string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : $iso;
};
$finPorDefecto = date('Y-m-d', strtotime($fechaDefault . ' +29 days'));
$rangoEsPorDefecto = $rangoInicio === $fechaDefault && $rangoFin === $finPorDefecto;
if ($rangoInicio !== '' && $rangoFin !== '') {
    $chipsFiltro[] = [
        'label' => $rangoInicio === $rangoFin
            ? 'Periodo: ' . $diaBonito($rangoInicio)
            : 'Periodo: ' . $diaBonito($rangoInicio) . ' – ' . $diaBonito($rangoFin),
        'url' => $rangoEsPorDefecto ? '' : $urlFiltro(['fecha_inicio' => '', 'fecha_fin' => '']),
    ];
}

if (($filtros['q'] ?? '') !== '') {
    $chipsFiltro[] = ['label' => 'Busca «' . $filtros['q'] . '»', 'url' => $urlFiltro(['q' => ''])];
}
if (($filtros['estado'] ?? '') !== '') {
    $chipsFiltro[] = [
        'label' => 'Estado: ' . ($estadoLabels[$filtros['estado']] ?? $filtros['estado']),
        'url' => $urlFiltro(['estado' => '']),
    ];
}
if (($filtros['asignacion'] ?? '') !== '') {
    $chipsFiltro[] = [
        'label' => $etiquetaAsignacion[$filtros['asignacion']] ?? $filtros['asignacion'],
        'url' => $urlFiltro(['asignacion' => '']),
    ];
}
if (($filtros['origen'] ?? '') !== '') {
    $chipsFiltro[] = [
        'label' => 'Origen: ' . ($etiquetaOrigen[$filtros['origen']] ?? $filtros['origen']),
        'url' => $urlFiltro(['origen' => '']),
    ];
}

// Filtros avanzados: el bloque plegable arranca abierto si alguno está puesto,
// o el usuario no vería por qué la lista está recortada.
$filtrosAvanzadosActivos = ($filtros['estado'] ?? '') !== ''
    || ($filtros['asignacion'] ?? '') !== ''
    || ($filtros['origen'] ?? '') !== '';

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
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-reservations__header-action" href="/admin/reservaciones/crear">
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
        action="/admin/reservaciones"
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
        <div class="admin-filters__range">
            <?php
            $rangeCaption = 'Periodo';
            $rangeStartParam = 'fecha_inicio';
            $rangeEndParam = 'fecha_fin';
            $rangeAllowFuture = true;
            $rangeShowPresets = false;
            $rangePreserveQuery = true;
            include __DIR__ . '/../partials/_range-picker.php';
            ?>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-reactive-submit>Buscar</button>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservaciones" data-reactive-clear <?php echo !$filtrosActivos ? 'hidden' : ''; ?>>Limpiar filtros</a>
        </div>

        <?php /*
          Estado, asignación y origen se pliegan: son los que menos se tocan y
          entre los seis campos ocupaban un tercio de la pantalla antes de
          enseñar una sola reservación. <details> los mantiene en el DOM, que es
          lo que reactive-filters necesita para leerlos.
        */ ?>
        <details class="admin-filters__more" <?php echo $filtrosAvanzadosActivos ? 'open' : ''; ?>>
            <summary>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                Más filtros
            </summary>
            <div class="admin-filters__more-grid">
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
                        <option value="landing" <?php echo ($filtros['origen'] ?? '') === 'landing' ? 'selected' : ''; ?>>Landing pública</option>
                    </select>
                </div>
            </div>
        </details>
    </form>

    <div class="admin-reactive-results-shell">
        <div id="reservations-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
    <?php if (!empty($chipsFiltro)) : ?>
        <div class="admin-reservations__chips" aria-label="Filtros aplicados">
            <span class="admin-reservations__chips-label">Filtrando por</span>
            <?php foreach ($chipsFiltro as $chip) : ?>
                <?php /* Sin URL el chip es informativo, no removible: es el caso
                         del periodo cuando aún es la ventana por defecto. Se
                         pinta como <span> para no ofrecer una × que no quita
                         nada. */ ?>
                <?php if (($chip['url'] ?? '') !== '') : ?>
                    <a class="admin-reservations__chip" href="<?php echo $h($chip['url']); ?>">
                        <span><?php echo $h($chip['label']); ?></span>
                        <span class="admin-reservations__chip-x" aria-hidden="true">×</span>
                        <span class="admin-visually-hidden">Quitar este filtro</span>
                    </a>
                <?php else : ?>
                    <span class="admin-reservations__chip admin-reservations__chip--fijo">
                        <span><?php echo $h($chip['label']); ?></span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="admin-reservations__metrics" aria-label="Métricas de reservaciones">
        <div class="admin-reservations__metrics-grid">
            <?php foreach ($metricCards as $card) : ?>
                <?php
                $claseMetric = 'admin-reservations__metric'
                    . (!empty($card['tone']) ? ' admin-reservations__metric--' . $h($card['tone']) : '');
                $etiqueta = $h($card['label']);
                $cifra = (int) $card['value'];
                $detalle = $h($card['detalle'] ?? '');
                ?>
                <?php if (!empty($card['url'])) : ?>
                    <a class="<?php echo $claseMetric; ?>" href="<?php echo $h($card['url']); ?>">
                        <span><?php echo $etiqueta; ?></span>
                        <strong><?php echo $cifra; ?></strong>
                        <small><?php echo $detalle; ?></small>
                    </a>
                <?php else : ?>
                    <article class="<?php echo $claseMetric; ?>">
                        <span><?php echo $etiqueta; ?></span>
                        <strong><?php echo $cifra; ?></strong>
                        <small><?php echo $detalle; ?></small>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <?php
        // Agrupación por día: en un rango de varias fechas la columna "Fecha" se
        // repetía en cada renglón sin aportar nada. El encabezado de día lo dice
        // una vez y de paso separa visualmente las jornadas.
        $porDia = [];
        foreach ($reservaciones as $r) {
            $porDia[(string) $valor($r, 'fecha')][] = $r;
        }
        $totalEncontradas = count($reservaciones);
    ?>
    <section class="reservations-table-card admin-card" aria-labelledby="reservations-results-title">
        <div class="reservations-table-card__header">
            <div>
                <h2 id="reservations-results-title"><?php echo $totalEncontradas; ?> <?php echo $totalEncontradas === 1 ? 'reservación encontrada' : 'reservaciones encontradas'; ?></h2>
                <p>Periodo: <?php echo $h($fechaLegible($filtros['fecha_inicio'] ?? date('Y-m-d'))); ?> – <?php echo $h($fechaLegible($filtros['fecha_fin'] ?? date('Y-m-d'))); ?></p>
            </div>

            <?php if (!empty($reservaciones)) : ?>
                <?php /* Dos lecturas del mismo dato: la lista para trabajar fila
                         a fila y la agenda para ver de un vistazo cómo se reparte
                         la carga del servicio. */ ?>
                <fieldset class="admin-tabs reservations-views">
                    <legend class="admin-visually-hidden">Forma de ver las reservaciones</legend>
                    <label class="admin-tabs__tab">
                        <input type="radio" name="reservations-view" value="lista" checked data-reservations-view aria-label="Vista de lista" title="Lista">
                        <span title="Lista" aria-hidden="true">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 6h14M5 12h14M5 18h14"/></svg>
                        </span>
                    </label>
                    <label class="admin-tabs__tab">
                        <input type="radio" name="reservations-view" value="agenda" data-reservations-view aria-label="Vista de agenda" title="Agenda">
                        <span title="Agenda" aria-hidden="true">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 9h16M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01M16 17h.01"/></svg>
                        </span>
                    </label>
                </fieldset>
            <?php endif; ?>
        </div>

        <?php if (empty($reservaciones)) : ?>
            <div class="admin-menu__empty admin-empty">
                <p>No se encontraron reservaciones con los filtros aplicados.</p>
                <?php if ($filtrosActivos) : ?>
                    <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/reservaciones" data-reactive-clear>Limpiar filtros</a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php /* data-scrollable, no data-lenis-prevent: .reservations-table-wrapper
                     es `overflow-x: auto` sin tope de alto, así que retener la
                     rueda dejaba la página clavada sobre el listado. La agenda
                     de más abajo sí tiene scroll vertical propio y conserva su
                     marca escrita a mano. */ ?>
            <div class="reservations-table-wrapper" data-reservations-panel="lista" data-scrollable>
                <table class="reservations-table">
                    <caption class="admin-visually-hidden">Reservaciones encontradas en el periodo seleccionado</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cuándo</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Personas</th>
                            <th scope="col">Mesas</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="admin-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <?php foreach ($porDia as $dia => $delDia) : ?>
                        <tbody class="reservations-table__day">
                            <tr class="reservations-table__day-row">
                                <th class="reservations-table__day-head" colspan="6" scope="colgroup">
                                    <span><?php echo $h($fechaLegible($dia)); ?></span>
                                    <small><?php echo count($delDia); ?> <?php echo count($delDia) === 1 ? 'reservación' : 'reservaciones'; ?></small>
                                </th>
                            </tr>
                            <?php foreach ($delDia as $reservacion) : ?>
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
                                // mesas_asignadas ya viene de buscarAdmin() y no se
                                // usaba: decir "Mesa 3, Mesa 4" es más útil que "2 mesas".
                                $mesasNombres = trim((string)$valor($reservacion, 'mesas_asignadas'));
                                $tieneMesa = $mesasCount > 0;
                                $origen = (string)$valor($reservacion, 'origen', 'admin');
                                $toleranciaVencida = !empty($valor($reservacion, 'tolerancia_vencida', false));
                                $showUrl = '/admin/reservaciones/detalle?id=' . $id . '&return_url=' . rawurlencode($returnTo);
                                $operationContextUrl = '/admin/reservaciones/operacion?' . http_build_query([
                                    'fecha' => $fecha,
                                    'hora' => $horaLegible($hora),
                                    'reservation_id' => $id,
                                    'return_url' => $returnTo,
                                ]);
                                ?>
                                <tr<?php echo $toleranciaVencida ? ' class="is-late"' : ''; ?>>
                                    <td class="reservations-table__time-cell">
                                        <span class="reservations-table__time"><?php echo $h($horaLegible($hora)); ?></span>
                                        <?php if ($toleranciaVencida) : ?>
                                            <span class="reservations-table__late">Tolerancia vencida</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="reservations-table__customer-cell">
                                        <div class="reservations-table__customer">
                                            <strong><?php echo $h($nombre); ?></strong>
                                            <span><?php echo $contacto !== '' ? $h($contacto) : 'Sin contacto'; ?></span>
                                        </div>
                                        <?php /* Origen y nota bajan aquí: eran dos columnas
                                                 que sólo se leen cuando ya miras el cliente. */ ?>
                                        <div class="reservations-table__meta">
                                            <span class="admin-badge admin-badge--<?php echo $origen === 'admin' ? 'info' : 'neutral'; ?>"><?php echo $origen === 'admin' ? 'Administrativa' : 'Landing'; ?></span>
                                            <?php if ($nota !== '') : ?>
                                                <span class="reservations-table__note" title="<?php echo $h($nota); ?>"><?php echo $h($nota); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="reservations-table__guests-cell">
                                        <span class="reservations-table__guests"><?php echo $h($pluralPersonas($comensales)); ?></span>
                                    </td>
                                    <td class="reservations-table__tables-cell">
                                        <?php if ($tieneMesa) : ?>
                                            <span class="reservations-table__assignment" title="<?php echo $h($mesasNombres); ?>">
                                                <?php echo $mesasNombres !== '' ? $h($mesasNombres) : $mesasCount . ' ' . ($mesasCount === 1 ? 'mesa' : 'mesas'); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="reservations-table__assignment reservations-table__needs-table">Sin mesas</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="reservations-table__status-cell">
                                        <span class="reservations-table__status reservations-table__status--<?php echo $h($estado); ?>">
                                            <?php echo $h($estadoLabels[$estado] ?? ucfirst($estado)); ?>
                                        </span>
                                    </td>
                                    <td class="reservations-table__actions-cell">
                                        <div class="reservations-table__actions">
                                            <a class="admin-btn admin-btn--secondary" href="<?php echo $h($showUrl); ?>" title="Ver detalle de reservación" aria-label="Ver detalle de <?php echo $h($nombre); ?>, <?php echo $h($fechaLegible($fecha)); ?> a las <?php echo $h($horaLegible($hora)); ?>">Ver</a>
                                            <a
                                                class="admin-btn admin-btn--ghost reservations-table__operate-action"
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
                    <?php endforeach; ?>
                </table>
            </div>

            <?php /*
              Agenda: el mismo listado repartido por franja horaria. Contesta de
              un vistazo a qué hora se concentra el servicio y cuánto aforo hay
              comprometido, que en una tabla ordenada por fecha no se ve.
            */ ?>
            <div class="reservations-agenda" data-reservations-panel="agenda" hidden data-lenis-prevent>
                <?php foreach ($porDia as $dia => $delDia) : ?>
                    <?php
                    $porHora = [];
                    $comensalesDia = 0;
                    foreach ($delDia as $r) {
                        $porHora[$horaLegible($valor($r, 'hora'))][] = $r;
                        $comensalesDia += (int) $valor($r, 'comensales', 0);
                    }
                    ksort($porHora);
                    ?>
                    <section class="reservations-agenda__day">
                        <header class="reservations-agenda__day-head">
                            <h3><?php echo $h($fechaLegible($dia)); ?></h3>
                            <span><?php echo count($delDia); ?> reservaciones · <?php echo $h($pluralPersonas($comensalesDia)); ?></span>
                        </header>
                        <?php foreach ($porHora as $franja => $deLaHora) : ?>
                            <?php
                            $comensalesFranja = 0;
                            foreach ($deLaHora as $r) {
                                $comensalesFranja += (int) $valor($r, 'comensales', 0);
                            }
                            ?>
                            <div class="reservations-agenda__slot">
                                <div class="reservations-agenda__hour">
                                    <strong><?php echo $h($franja); ?></strong>
                                    <small><?php echo $h($pluralPersonas($comensalesFranja)); ?></small>
                                </div>
                                <ul class="reservations-agenda__list">
                                    <?php foreach ($deLaHora as $r) : ?>
                                        <?php
                                        $rid = (int) $valor($r, 'id', 0);
                                        $rEstado = (string) $valor($r, 'estado', 'confirmada');
                                        $rMesas = trim((string) $valor($r, 'mesas_asignadas'));
                                        ?>
                                        <li>
                                            <a class="reservations-agenda__card reservations-agenda__card--<?php echo $h($rEstado); ?>"
                                               href="/admin/reservaciones/detalle?id=<?php echo $rid; ?>&return_url=<?php echo $h(rawurlencode($returnTo)); ?>">
                                                <strong><?php echo $h((string) $valor($r, 'nombre')); ?></strong>
                                                <span><?php echo $h($pluralPersonas((int) $valor($r, 'comensales', 0))); ?></span>
                                                <small><?php echo $rMesas !== '' ? $h($rMesas) : 'Sin mesas'; ?></small>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
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
