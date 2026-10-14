<?php
/**
 * Casos: las reservaciones del periodo que piden que alguien haga algo.
 *
 * No es otro listado de reservaciones. Sólo sale la que tiene un caso, una
 * tarjeta por reservación —quien la abre la resuelve entera—, del caso más
 * urgente al menos, y con la acción que lo resuelve a un toque.
 *
 * Variables: $rango, $resultado (ReservacionCasosAccionablesService::listar),
 * $tipoActivo, $tipos (presentación por tipo), $urlCasos (callable), $returnUrl,
 * $adminCsrfToken, $partialOnly.
 *
 * Con $partialOnly se pinta sólo el bloque de resultados: estrategia.js lo pide
 * así para repintar la lista tras una acción sin recargar la página.
 */
require_once __DIR__ . '/../partials/_icons.php';

$resultado = is_array($resultado ?? null) ? $resultado : [];
$tarjetas = is_array($resultado['casos'] ?? null) ? $resultado['casos'] : [];
$total = (int)($resultado['total'] ?? 0);
$porTipo = is_array($resultado['por_tipo'] ?? null) ? $resultado['por_tipo'] : [];
$pagina = (int)($resultado['pagina'] ?? 1);
$paginas = (int)($resultado['paginas'] ?? 1);
$tipoActivo = (string)($tipoActivo ?? '');
$tipos = is_array($tipos ?? null) ? $tipos : [];
$urlCasos = is_callable($urlCasos ?? null) ? $urlCasos : static fn (array $c): string => '/admin/reservaciones/casos';
$returnUrl = (string)($returnUrl ?? '/admin/reservaciones/casos');
$partialOnly = (bool)($partialOnly ?? false);
$rango = is_array($rango ?? null) ? $rango : [];

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
// Sólo la cifra va en mono: la palabra en mono se leía como volcado de terminal.
$personas = static fn (int $n): string => '<span class="admin-num">' . $n . '</span> ' . ($n === 1 ? 'persona' : 'personas');

// Fecha en palabras y no en d/m/Y: una tarjeta se lee como frase («sáb 17 oct
// · 14:00»), y el día de la semana es lo que el anfitrión usa para ubicarse.
$diasCortos = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$mesesCortos = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$fechaCorta = static function (string $iso) use ($diasCortos, $mesesCortos): string {
    $ts = strtotime($iso);
    return $ts ? $diasCortos[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $mesesCortos[(int)date('n', $ts)] : $iso;
};
$hoy = \Services\Reservations\ReservacionConfig::fechaActual();
?>
<?php if (!$partialOnly) : ?>
<section class="admin-page reservations-estrategia reservations-casos"
         data-casos
         data-admin-csrf="<?php echo $h($adminCsrfToken ?? ''); ?>">
    <?php $subnavActiva = 'casos'; include __DIR__ . '/_subnav.php'; ?>

    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Reservaciones</span>
            <h1 class="admin-page__title">Casos</h1>
            <p class="admin-page__subtitle">Las reservaciones que piden que alguien haga algo, de la más urgente a la menos.</p>
        </div>
        <div class="admin-actions">
            <?php
            $rangeCaption = 'Periodo';
            $rangeAllowFuture = true;
            $rangeShowPresets = false;
            // Conserva el filtro de tipo al cambiar de periodo.
            $rangePreserveQuery = true;
            include __DIR__ . '/../partials/_range-picker.php';
            ?>
        </div>
    </header>

    <?php /* Sin aria-live: cada repintado metería hasta veinte tarjetas en una
             región viva y el lector las leería todas. El resultado de la acción
             ya lo anuncia AppNotice; el foco va al conteo (tabindex -1). */ ?>
    <div class="estrategia-resultados" data-casos-resultados aria-busy="false">
<?php endif; ?>
        <?php /* El filtro va DENTRO del bloque que se repinta: sus cifras cambian
                 en cuanto se resuelve un caso, y fuera se quedarían viejas. Las
                 cifras cuentan CASOS, no tarjetas —una reservación sin mesas y
                 sin contacto suma en las dos—, y por eso «Todos» no lleva
                 número: no sería la suma de los demás. */ ?>
        <nav class="admin-tabs admin-tabs--links estrategia-filtro" aria-label="Filtrar casos por tipo">
            <span class="admin-tabs__tab">
                <a href="<?php echo $h($urlCasos(['tipo' => '', 'pagina' => 1])); ?>"
                   class="<?php echo $tipoActivo === '' ? 'is-active' : ''; ?>"
                   <?php echo $tipoActivo === '' ? 'aria-current="page"' : ''; ?>><span>Todos</span></a>
            </span>
            <?php foreach ($tipos as $clave => $tipo) : ?>
                <?php $cuenta = (int)($porTipo[$clave] ?? 0); ?>
                <span class="admin-tabs__tab">
                    <a href="<?php echo $h($urlCasos(['tipo' => $clave, 'pagina' => 1])); ?>"
                       class="<?php echo $tipoActivo === $clave ? 'is-active' : ''; ?><?php echo $cuenta === 0 ? ' is-empty' : ''; ?>"
                       <?php echo $tipoActivo === $clave ? 'aria-current="page"' : ''; ?>>
                        <?php /* <small> y no <span>: la regla compartida de las
                                 pestañas pinta TODO span descendiente del enlace
                                 como si fuera la pestaña (relleno, fondo, sombra). */ ?>
                        <span><?php echo $h($tipo['etiqueta']); ?> <small class="estrategia-filtro__cuenta admin-num"><?php echo $cuenta; ?></small></span>
                    </a>
                </span>
            <?php endforeach; ?>
        </nav>

        <p class="estrategia-resultados__conteo" tabindex="-1">
            <strong class="admin-num"><?php echo $total; ?></strong>
            <?php echo $total === 1 ? 'reservación con algo pendiente' : 'reservaciones con algo pendiente'; ?>
            <span>· <?php echo $h((string)($rango['label'] ?? '')); ?></span>
        </p>

        <?php if ($tarjetas === []) : ?>
            <div class="admin-card estrategia-vacio">
                <?php echo admin_icon('check', 20, 'estrategia-vacio__icono'); ?>
                <p><?php echo $tipoActivo === ''
                    ? 'Nada pendiente en este periodo.'
                    : 'Ningún caso de «' . $h($tipos[$tipoActivo]['etiqueta'] ?? $tipoActivo) . '» en este periodo.'; ?></p>
            </div>
        <?php else : ?>
            <ol class="estrategia-casos">
                <?php foreach ($tarjetas as $tarjeta) : ?>
                    <?php
                    $reserva = (array)$tarjeta['reservacion'];
                    $casos = (array)$tarjeta['casos'];
                    $principal = (string)($casos[0]['tipo'] ?? '');
                    $id = (int)$reserva['id'];
                    $nombre = (string)$reserva['nombre'];
                    $mesas = trim((string)($reserva['mesas'] ?? ''));
                    $tiposDeTarjeta = array_column($casos, 'tipo');
                    $reubicacionId = 0;
                    foreach ($casos as $caso) {
                        if (($caso['tipo'] ?? '') === \Services\Reservations\ReservacionCasosAccionablesService::TIPO_REUBICADA) {
                            $reubicacionId = (int)($caso['reubicacion_id'] ?? 0);
                        }
                    }
                    $detalle = (string)$reserva['url_detalle'] . '&return_url=' . rawurlencode($returnUrl);
                    $asignar = '/punto-de-venta?' . http_build_query(['reservation_id' => $id, 'mode' => 'assign']);
                    $esHoy = (string)$reserva['fecha'] === $hoy;
                    ?>
                    <li class="estrategia-caso estrategia-caso--<?php echo $h($tipos[$principal]['badge'] ?? 'neutral'); ?>">
                        <article aria-labelledby="caso-<?php echo $id; ?>">
                            <header class="estrategia-caso__cabecera">
                                <p class="estrategia-caso__cuando">
                                    <time datetime="<?php echo $h($reserva['fecha'] . 'T' . $reserva['hora']); ?>">
                                        <?php /* Mono sólo para la hora: la fecha son palabras. «Hoy» en
                                                 fuerte porque es lo que hace urgente la tarjeta. */ ?>
                                        <?php echo $esHoy ? '<strong>Hoy</strong>' : $h($fechaCorta((string)$reserva['fecha'])); ?> · <span class="admin-num"><?php echo $h($reserva['hora']); ?></span>
                                    </time>
                                </p>
                                <h2 class="estrategia-caso__nombre" id="caso-<?php echo $id; ?>"><?php echo $h($nombre); ?></h2>
                                <p class="estrategia-caso__datos">
                                    <?php echo $personas((int)$reserva['comensales']); ?>
                                    · <?php echo $mesas !== '' ? $h($mesas) : 'Sin mesas'; ?>
                                    <?php if (!empty($reserva['grupo_grande'])) : ?>
                                        · <span class="estrategia-caso__grupo">Grupo grande</span>
                                    <?php endif; ?>
                                </p>
                            </header>

                            <?php /* Cada caso con su porqué. La etiqueta sola
                                     («Sin contacto») dice qué pasa pero no qué
                                     significa para el servicio; la descripción sí. */ ?>
                            <ul class="estrategia-caso__motivos">
                                <?php foreach ($casos as $caso) : ?>
                                    <?php $presentacion = $tipos[$caso['tipo']] ?? ['badge' => 'neutral']; ?>
                                    <li>
                                        <span class="admin-badge admin-badge--<?php echo $h($presentacion['badge']); ?>"><?php echo $h($caso['etiqueta']); ?></span>
                                        <span class="estrategia-caso__porque"><?php echo $h($caso['descripcion']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php /* Una sola acción primaria por tarjeta: la que
                                     resuelve el caso más urgente. Las demás
                                     resoluciones van en secundario y abrir o ver
                                     detalle, en fantasma, al final. */ ?>
                            <footer class="estrategia-acciones">
                                <?php
                                $primariaUsada = false;
                                // A quien no se presentó no se le asignan mesas: con
                                // «No llegó» en la tarjeta, «Asignar mesas» sobra.
                                $noLlego = in_array(\Services\Reservations\ReservacionCasosAccionablesService::TIPO_AUSENCIA, $tiposDeTarjeta, true);
                                ?>
                                <?php foreach ($tiposDeTarjeta as $tipoCaso) : ?>
                                    <?php $claseBoton = $primariaUsada ? 'admin-btn--secondary' : 'admin-btn--primary'; ?>
                                    <?php if ($tipoCaso === \Services\Reservations\ReservacionCasosAccionablesService::TIPO_AUSENCIA) : ?>
                                        <button type="button" class="admin-btn <?php echo $claseBoton; ?> admin-btn--small"
                                                data-caso-no-show
                                                data-reservation-id="<?php echo $id; ?>"
                                                data-nombre="<?php echo $h($nombre); ?>"
                                                data-hora="<?php echo $h($reserva['hora']); ?>">
                                            <?php echo admin_icon('estado-sin-llegar', 14, 'admin-btn__icon'); ?>
                                            <span>Registrar que no llegó</span>
                                        </button>
                                        <?php $primariaUsada = true; ?>
                                    <?php elseif ($tipoCaso === \Services\Reservations\ReservacionCasosAccionablesService::TIPO_SIN_MESA && !$noLlego) : ?>
                                        <a class="admin-btn <?php echo $claseBoton; ?> admin-btn--small" href="<?php echo $h($asignar); ?>" aria-label="Asignar mesas a <?php echo $h($nombre); ?> en el POS">
                                            <?php echo admin_icon('tables', 14, 'admin-btn__icon'); ?>
                                            <span>Asignar mesas</span>
                                        </a>
                                        <?php $primariaUsada = true; ?>
                                    <?php elseif ($tipoCaso === \Services\Reservations\ReservacionCasosAccionablesService::TIPO_REUBICADA && $reubicacionId > 0) : ?>
                                        <button type="button" class="admin-btn <?php echo $claseBoton; ?> admin-btn--small"
                                                data-caso-revisar
                                                data-reubicacion-id="<?php echo $reubicacionId; ?>"
                                                data-nombre="<?php echo $h($nombre); ?>">
                                            <?php echo admin_icon('check', 14, 'admin-btn__icon'); ?>
                                            <span>Marcar revisada</span>
                                        </button>
                                        <?php $primariaUsada = true; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <a class="admin-btn admin-btn--ghost admin-btn--small" href="<?php echo $h($reserva['url_pos']); ?>" aria-label="Abrir en el POS la reservación de <?php echo $h($nombre); ?>">
                                    <?php echo admin_icon('plano', 14, 'admin-btn__icon'); ?>
                                    <span>Abrir en el POS</span>
                                </a>
                                <a class="admin-btn admin-btn--ghost admin-btn--small" href="<?php echo $h($detalle); ?>" aria-label="Ver detalle de la reservación de <?php echo $h($nombre); ?>">
                                    <span>Ver detalle</span>
                                </a>
                            </footer>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php
            $pagPagina = $pagina;
            $pagTotal = $paginas;
            $pagEtiqueta = 'Páginas de casos';
            $pagUrl = static fn (int $p): string => $urlCasos(['pagina' => $p]);
            include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
<?php if (!$partialOnly) : ?>
    </div>
</section>
<?php endif; ?>
