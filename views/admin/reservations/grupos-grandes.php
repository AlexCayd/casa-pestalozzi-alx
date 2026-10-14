<?php
/**
 * Agenda de grupos grandes: el bloque exterior día por día, hacia adelante.
 *
 * Contesta una sola pregunta —«¿dónde cabe un grupo?»— antes de que alguien se
 * lo prometa a un cliente. Por eso cada día enseña primero la FRANJA del bloque
 * (libre, parcial, tomada cada 30 minutos) y después quién lo tiene ya.
 *
 * Variables: $rango (RangoPeriodo::resolverProspectivo), $agenda
 * (ReservacionAgendaGruposService::rango), $umbralGrupo, $returnUrl.
 */
require_once __DIR__ . '/../partials/_icons.php';

$agenda = is_array($agenda ?? null) ? $agenda : [];
$agendaDias = is_array($agenda['dias'] ?? null) ? $agenda['dias'] : [];
$bloque = is_array($agenda['bloque'] ?? null) ? $agenda['bloque'] : [];
$resumen = (array)($agenda['resumen'] ?? []) + ['grupos' => 0, 'dias_con_grupo' => 0, 'personas' => 0];
$rango = is_array($rango ?? null) ? $rango : [];
$umbralGrupo = (int)($umbralGrupo ?? 9);
$returnUrl = (string)($returnUrl ?? '/admin/reservaciones/grupos-grandes');

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
// Sólo la cifra va en mono: la palabra en mono se leía como volcado de terminal.
$personas = static fn (int $n): string => '<span class="admin-num">' . $n . '</span> ' . ($n === 1 ? 'persona' : 'personas');
$horaCorta = static fn ($hora): string => substr((string)$hora, 0, 5);

// «Mesas 8, 9, 10 y 11» sale de la base, no del texto: si el bloque cambia,
// la nota sigue diciendo la verdad.
$nombresBloque = array_map(static fn (array $mesa): string => (string)$mesa['nombre'], $bloque);
$listaBloque = count($nombresBloque) > 1
    ? implode(', ', array_slice($nombresBloque, 0, -1)) . ' y ' . end($nombresBloque)
    : (string)($nombresBloque[0] ?? 'el exterior');
$totalBloque = count($bloque);

$estadosFranja = [
    'libre' => 'Libre',
    'parcial' => 'Parcial',
    'tomada' => 'Tomada',
];

$detalleUrl = static fn (array $reserva): string => (string)$reserva['url_detalle'] . '&return_url=' . rawurlencode($returnUrl);
?>
<section class="admin-page reservations-estrategia reservations-grupos">
    <?php $subnavActiva = 'grupos'; include __DIR__ . '/_subnav.php'; ?>

    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Reservaciones</span>
            <h1 class="admin-page__title">Grupos grandes</h1>
            <p class="admin-page__subtitle">Dónde cabe un grupo de <?php echo $umbralGrupo; ?> o más en los próximos días, antes de prometérselo a nadie.</p>
        </div>
        <div class="admin-actions">
            <?php
            $rangeCaption = 'Periodo';
            $rangeAllowFuture = true;
            $rangeShowPresets = false;
            include __DIR__ . '/../partials/_range-picker.php';
            ?>
            <?php /* El alta del panel ya admite grupos grandes (su máximo es el
                     administrativo, no el público). Sale de aquí con la fecha de
                     hoy; cada día lleva además su propio atajo con su fecha. */ ?>
            <a class="admin-btn admin-btn--primary" href="/admin/reservaciones/crear">
                <?php echo admin_icon('mas', 16, 'admin-btn__icon'); ?>
                <span>Nuevo grupo</span>
            </a>
        </div>
    </header>

    <?php if (!empty($rango['recortado'])) : ?>
        <p class="estrategia-aviso" role="status">
            <?php echo admin_icon('info', 16); ?>
            <span>La agenda enseña hasta <?php echo (int)\Services\Reservations\ReservacionAgendaGruposService::MAX_DIAS; ?> días seguidos; el periodo se recortó al <?php echo $h(date('d/m/Y', strtotime((string)$rango['end']))); ?>.</span>
        </p>
    <?php endif; ?>

    <div class="admin-stat-strip">
        <?php
        $statLabel = 'Grupos grandes';
        $statValue = (string)(int)$resumen['grupos'];
        $statSub = 'Reservaciones de ' . $umbralGrupo . ' personas o más';
        include __DIR__ . '/../partials/_stat-card.php';

        $statLabel = 'Días con grupo';
        $statValue = (string)(int)$resumen['dias_con_grupo'];
        $statSub = 'De ' . count($agendaDias) . ' ' . (count($agendaDias) === 1 ? 'día' : 'días') . ' en el periodo';
        include __DIR__ . '/../partials/_stat-card.php';

        $statLabel = 'Personas en grupos';
        $statValue = (string)(int)$resumen['personas'];
        $statSub = 'Comensales comprometidos en grupos grandes';
        include __DIR__ . '/../partials/_stat-card.php';
        ?>
    </div>

    <?php /* La limitación va escrita y no sólo en la documentación: «parcial»
             promete que el grupo puede entrar, y hay un caso en el que no
             entra aunque la franja lo diga. Quien planea tiene que saberlo
             aquí, no después de llamar al cliente. */ ?>
    <aside class="estrategia-nota" aria-labelledby="estrategia-nota-titulo">
        <?php echo admin_icon('info', 18, 'estrategia-nota__icono'); ?>
        <div>
            <h2 class="estrategia-nota__titulo" id="estrategia-nota-titulo">Cómo leer la franja</h2>
            <p>El bloque exterior —<?php echo $h($listaBloque); ?>— es el único sitio donde cabe un grupo de <?php echo $umbralGrupo; ?> o más. <strong>Parcial</strong> quiere decir que alguna mesa del bloque ya tiene reserva y el sistema puede reubicarla al interior para dar paso al grupo.</p>
            <p>Con un límite: una reubicación nunca manda a nadie a otra mesa del bloque, y las Mesas 3 y 4 son la única pareja interior que admite de 5 a 8 personas. Por eso, en cada ventana de 90 minutos sólo se puede desplazar <strong>una</strong> reserva de 5–8. Si hay dos solapadas, el grupo no entra aunque la franja diga parcial.</p>
        </div>
    </aside>

    <div class="estrategia-leyenda" aria-hidden="true">
        <span class="estrategia-leyenda__titulo">Bloque exterior</span>
        <?php foreach ($estadosFranja as $estado => $etiqueta) : ?>
            <span class="estrategia-leyenda__item">
                <span class="estrategia-franja__marca estrategia-franja__marca--<?php echo $h($estado); ?>"></span>
                <?php echo $h($etiqueta); ?>
            </span>
        <?php endforeach; ?>
        <span class="estrategia-leyenda__nota">La cifra es mesas ocupadas de <?php echo $totalBloque; ?>.</span>
    </div>

    <?php
    /*
     * Eje horario común a todo el periodo. Cada día abre a una hora distinta
     * (lunes a las 13:00, martes a las 08:30), y con una rejilla por día la
     * misma columna era una hora distinta en cada tarjeta: no se podía bajar
     * la vista por «las 20:00». Con el eje común, cada día rellena con huecos
     * lo que queda fuera de su horario y todas las columnas coinciden.
     */
    $horasComunes = [];
    foreach ($agendaDias as $diaEje) {
        foreach ((array)($diaEje['franjas'] ?? []) as $franjaEje) {
            $horasComunes[(string)$franjaEje['hora']] = true;
        }
    }
    $horasComunes = array_keys($horasComunes);
    sort($horasComunes);
    ?>

    <?php if ($agendaDias === []) : ?>
        <div class="admin-card estrategia-vacio">
            <p>No hay días que mostrar en este periodo.</p>
        </div>
    <?php else : ?>
        <ol class="estrategia-dias">
            <?php foreach ($agendaDias as $dia) : ?>
                <?php
                $fecha = (string)$dia['fecha'];
                $grupos = is_array($dia['grupos'] ?? null) ? $dia['grupos'] : [];
                $franjas = is_array($dia['franjas'] ?? null) ? $dia['franjas'] : [];
                $abierto = !empty($dia['abierto']);
                $etiquetaDia = (string)$dia['etiqueta'];
                $etiquetaDiaTitulo = function_exists('mb_strtoupper')
                    ? mb_strtoupper(mb_substr($etiquetaDia, 0, 1)) . mb_substr($etiquetaDia, 1)
                    : ucfirst($etiquetaDia);
                $deGrupo = count(array_filter($grupos, static fn (array $r): bool => !empty($r['grupo_grande'])));
                $idTitulo = 'dia-' . $fecha;
                $franjasPorHora = array_column($franjas, null, 'hora');
                // Un día con el bloque libre toda la jornada no necesita
                // rejilla: veinticinco celdas iguales dicen lo mismo que una
                // línea, y entre treinta días escondían los dos que tienen algo.
                $todoLibre = $franjas !== [] && array_filter(
                    $franjas,
                    static fn (array $f): bool => ($f['estado'] ?? 'libre') !== 'libre'
                ) === [];
                $claseDia = 'estrategia-dia' . ($grupos === [] ? ' estrategia-dia--sin-grupos' : '')
                    . (!$abierto ? ' estrategia-dia--cerrado' : '')
                    . ($todoLibre ? ' estrategia-dia--libre' : '');
                ?>
                <li class="<?php echo $h($claseDia); ?>">
                    <article aria-labelledby="<?php echo $h($idTitulo); ?>">
                        <header class="estrategia-dia__cabecera">
                            <div class="estrategia-dia__titulo">
                                <h2 id="<?php echo $h($idTitulo); ?>"><time datetime="<?php echo $h($fecha); ?>"><?php echo $h($etiquetaDiaTitulo); ?></time></h2>
                                <?php if ($abierto) : ?>
                                    <span class="estrategia-dia__horario admin-num"><?php echo $h($horaCorta($dia['hora_apertura'] ?? '')); ?>–<?php echo $h($horaCorta($dia['hora_cierre'] ?? '')); ?></span>
                                <?php else : ?>
                                    <span class="admin-badge admin-badge--neutral admin-badge--outline">Cerrado</span>
                                <?php endif; ?>
                            </div>
                            <div class="estrategia-dia__resumen">
                                <?php if ($deGrupo > 0) : ?>
                                    <span class="admin-badge admin-badge--neutral"><?php echo $deGrupo; ?> <?php echo $deGrupo === 1 ? 'grupo' : 'grupos'; ?></span>
                                <?php endif; ?>
                                <?php if ($abierto) : ?>
                                    <a class="admin-btn admin-btn--ghost admin-btn--small" href="/admin/reservaciones/crear?fecha=<?php echo $h(rawurlencode($fecha)); ?>" aria-label="Nuevo grupo el <?php echo $h($etiquetaDia); ?>">
                                        <?php echo admin_icon('mas', 14, 'admin-btn__icon'); ?>
                                        <span>Nuevo grupo</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </header>

                        <?php if ($abierto && $todoLibre) : ?>
                            <p class="estrategia-dia__vacio">
                                <span class="estrategia-franja__marca estrategia-franja__marca--libre" aria-hidden="true"></span>
                                Bloque exterior libre toda la jornada.
                            </p>
                        <?php elseif ($abierto && $franjas !== []) : ?>
                            <?php /* Una celda por franja de 30 minutos. El estado se
                                     dice tres veces y ninguna depende del color: la
                                     forma de la marca (vacía, rayada, maciza), la
                                     cifra de mesas ocupadas y, para el lector de
                                     pantalla, la frase completa. */ ?>
                            <ol class="estrategia-franja" style="--franjas: <?php echo count($horasComunes); ?>" aria-label="Bloque exterior por franja, <?php echo $h($etiquetaDia); ?>">
                                <?php foreach ($horasComunes as $horaEje) : ?>
                                    <?php if (!isset($franjasPorHora[$horaEje])) : ?>
                                        <li class="estrategia-franja__slot estrategia-franja__slot--fuera" aria-hidden="true"></li>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <?php
                                    $franja = $franjasPorHora[$horaEje];
                                    $estado = (string)($franja['estado'] ?? 'libre');
                                    $ocupadas = (int)($franja['mesas_ocupadas'] ?? 0);
                                    $total = (int)($franja['mesas_total'] ?? $totalBloque);
                                    $lectura = $horaCorta($franja['hora']) . ': ' . strtolower($estadosFranja[$estado] ?? $estado)
                                        . ($estado === 'libre' ? '' : ', ' . $ocupadas . ' de ' . $total . ' mesas ocupadas');
                                    ?>
                                    <li class="estrategia-franja__slot estrategia-franja__slot--<?php echo $h($estado); ?><?php echo substr((string)$franja['hora'], 3, 2) === '30' ? ' estrategia-franja__slot--media' : ''; ?>" title="<?php echo $h($lectura); ?>">
                                        <span class="estrategia-franja__hora admin-num" aria-hidden="true"><?php echo $h($horaCorta($franja['hora'])); ?></span>
                                        <span class="estrategia-franja__marca estrategia-franja__marca--<?php echo $h($estado); ?>" aria-hidden="true">
                                            <?php if ($estado !== 'libre') : ?>
                                                <span class="admin-num"><?php echo $ocupadas; ?>/<?php echo $total; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="admin-visually-hidden"><?php echo $h($lectura); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php elseif ($abierto) : ?>
                            <p class="estrategia-dia__vacio">El horario de este día no deja una franja de 90 minutos completa.</p>
                        <?php else : ?>
                            <p class="estrategia-dia__vacio">El restaurante no abre este día.</p>
                        <?php endif; ?>

                        <?php if ($grupos !== []) : ?>
                            <ul class="estrategia-grupos" aria-label="Reservaciones que usan el bloque, <?php echo $h($etiquetaDia); ?>">
                                <?php foreach ($grupos as $reserva) : ?>
                                    <?php
                                    $nombre = (string)$reserva['nombre'];
                                    $mesas = trim((string)($reserva['mesas'] ?? ''));
                                    $porConfirmar = (string)($reserva['estado'] ?? '') === 'pendiente_verificacion';
                                    ?>
                                    <li class="estrategia-grupo<?php echo !empty($reserva['grupo_grande']) ? ' estrategia-grupo--grande' : ''; ?>">
                                        <time class="estrategia-grupo__hora admin-num" datetime="<?php echo $h($fecha . 'T' . $reserva['hora']); ?>"><?php echo $h($reserva['hora']); ?></time>
                                        <div class="estrategia-grupo__quien">
                                            <strong><?php echo $h($nombre); ?></strong>
                                            <span>
                                                <?php echo $personas((int)$reserva['comensales']); ?>
                                                · <?php echo $mesas !== '' ? $h($mesas) : 'Sin mesas asignadas'; ?>
                                            </span>
                                        </div>
                                        <div class="estrategia-grupo__marcas">
                                            <?php if (!empty($reserva['grupo_grande'])) : ?>
                                                <span class="admin-badge admin-badge--info">Grupo grande</span>
                                            <?php else : ?>
                                                <span class="admin-badge admin-badge--neutral admin-badge--outline">Usa el exterior</span>
                                            <?php endif; ?>
                                            <?php if (!empty($reserva['sin_mesa'])) : ?>
                                                <span class="admin-badge admin-badge--warning">Sin mesas</span>
                                            <?php endif; ?>
                                            <?php if ($porConfirmar) : ?>
                                                <span class="admin-badge admin-badge--warning admin-badge--outline">Por confirmar</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="estrategia-acciones">
                                            <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $h($reserva['url_pos']); ?>" aria-label="Abrir en el POS la reservación de <?php echo $h($nombre); ?>">
                                                <?php echo admin_icon('plano', 14, 'admin-btn__icon'); ?>
                                                <span>Abrir en el POS</span>
                                            </a>
                                            <a class="admin-btn admin-btn--ghost admin-btn--small" href="<?php echo $h($detalleUrl($reserva)); ?>" aria-label="Ver detalle de la reservación de <?php echo $h($nombre); ?>">
                                                <span>Ver detalle</span>
                                            </a>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
