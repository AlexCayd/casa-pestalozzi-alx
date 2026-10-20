<?php
/**
 * Agenda de reservaciones: quién viene y qué pasó, día por día.
 *
 * Es la primera de las tres pantallas del módulo (Agenda · Grupos grandes ·
 * Casos) y la única que enseña TODAS las reservaciones. No opera: sentar,
 * mover mesas y dar de alta viven en el POS. Aquí se busca a un cliente, se
 * repasa cómo terminó un periodo, se abre el detalle y se cierra la ausencia
 * que nadie registró.
 *
 * La lista es una libreta: cada día lleva su cabecera, la hora se escribe una
 * vez por franja en el margen y las reservas cuelgan de una línea vertical con
 * una marca cuya FORMA dice el estado (ver reservations.scss). La forma va
 * además en palabras en la etiqueta de cada fila: el color nunca dice nada
 * solo.
 *
 * Variables (AdminReservacionController::index): $diasAgenda, $conteos,
 * $filtros, $filtrosActivos, $periodoPorDefecto, $rango, $urlListado,
 * $urlConEstado (callable), $hoy, $adminCsrfToken, $alertas, $partialOnly.
 *
 * Con $partialOnly se pinta sólo el bloque de resultados: lo piden los
 * filtros reactivos al buscar o elegir un estado, y lista.js tras registrar
 * una ausencia.
 */
require_once __DIR__ . '/../partials/_icons.php';

use Services\Reservations\ReservacionListadoPresenter;

$diasAgenda = is_array($diasAgenda ?? null) ? $diasAgenda : [];
$conteos = is_array($conteos ?? null) ? $conteos : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$filtrosActivos = (bool)($filtrosActivos ?? false);
$periodoPorDefecto = (bool)($periodoPorDefecto ?? true);
$partialOnly = (bool)($partialOnly ?? false);
$alertas = is_array($alertas ?? null) ? $alertas : [];
$urlListado = (string)($urlListado ?? '/admin/reservaciones');
$urlConEstado = is_callable($urlConEstado ?? null) ? $urlConEstado : static fn(string $e): string => '/admin/reservaciones';
$adminCsrfToken = (string)($adminCsrfToken ?? '');
$horaAhora = (string)($horaAhora ?? date('H:i'));
$estadoActivo = (string)($filtros['estado'] ?? '');
$busqueda = (string)($filtros['q'] ?? '');

$h = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

// Sólo la cifra en mono: la palabra en mono se lee como volcado de terminal.
$cifra = static fn(int $n, string $uno, string $varios): string =>
    '<span class="admin-num">' . $n . '</span> ' . ($n === 1 ? $uno : $varios);

$alertasNormalizadas = [];
foreach ($alertas as $tipo => $mensajes) {
    foreach ((array)$mensajes as $mensaje) {
        if ((string)$mensaje !== '') {
            $alertasNormalizadas[is_string($tipo) ? $tipo : 'error'][] = (string)$mensaje;
        }
    }
}

/*
 * La línea de «ahora» en la hoja de hoy: cruza la libreta entre la última
 * reserva que ya debió llegar y la primera que falta, y dice en qué punto del
 * servicio se está sin tener que comparar horas. Es una fotografía de cuando
 * se cargó la página, como el resto de la lista. aria-hidden: las horas de
 * cada fila ya lo dicen, y un renglón que no es una reserva cambiaría la
 * cuenta de la lista para el lector de pantalla.
 */
$pintarAhora = static function () use ($h, $horaAhora): void {
    ?>
    <li class="reservations-ahora" aria-hidden="true">
        <span class="reservations-ahora__hora"><?php echo $h($horaAhora); ?></span>
        <span class="reservations-ahora__marca"></span>
        <span class="reservations-ahora__rotulo">Ahora</span>
    </li>
    <?php
};

/*
 * Un estado pedido por dirección que no tiene botón («Apartada», «Expirada»)
 * se añade como opción activa al final: sin ella la lista saldría recortada
 * sin que nada en pantalla dijera por qué.
 */
$opcionesEstado = ReservacionListadoPresenter::FILTROS;
if ($estadoActivo !== '' && !array_key_exists($estadoActivo, $opcionesEstado)) {
    $opcionesEstado[$estadoActivo] = ReservacionListadoPresenter::ETIQUETAS[$estadoActivo] ?? ucfirst($estadoActivo);
}
?>
<?php if (!$partialOnly) : ?>
<section class="admin-page admin-reservations reservations-agenda-page"
         data-reservations-listado
         data-admin-csrf="<?php echo $h($adminCsrfToken); ?>">
    <?php $subnavActiva = 'historico'; include __DIR__ . '/_subnav.php'; ?>

    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Reservaciones</span>
            <h1 class="admin-page__title">Agenda</h1>
            <p class="admin-page__subtitle">Quién viene y qué pasó, día por día. Para sentar, mover mesas o dar de alta, el POS.</p>
        </div>
        <?php /*
          Buscar y periodo comparten la fila del encabezado. Antes eran una
          caja de filtros propia —búsqueda, periodo, «Buscar», «Limpiar» y un
          «Más filtros» con estado, asignación y origen— entre el título y la
          primera reservación. El estado pasó a la tira de cifras de abajo, que
          además dice cuántas hay de cada uno; asignación y origen salieron
          (ver el controlador).

          El estado y el periodo viajan en campos ocultos porque el formulario
          arma la URL sólo con lo que lleva dentro: sin ellos, escribir un
          nombre devolvía la lista a «Todas» y a los próximos 30 días. El estado
          lleva data-reactive-control (lo pone la tira y «Limpiar» lo vacía); el
          periodo no, porque se cambia en su selector y «Limpiar» no debe dejar
          el selector diciendo un periodo y la lista enseñando otro.
        */ ?>
        <div class="admin-actions reservations-controles">
            <form class="admin-filters reservations-buscador"
                  method="GET"
                  action="/admin/reservaciones"
                  role="search"
                  aria-label="Buscar reservaciones"
                  data-reactive-filters
                  data-reactive-target="#reservations-results"
                  data-reactive-loading="#reservations-results-loading"
                  data-reactive-error="#reservations-results-error"
                  data-reactive-debounce="300">
                <label class="reservations-buscador__rotulo" for="reservations-q">Buscar</label>
                <div class="reservations-buscador__fila">
                    <input id="reservations-q"
                           type="search"
                           name="q"
                           value="<?php echo $h($busqueda); ?>"
                           placeholder="Nombre, teléfono o correo"
                           autocomplete="off"
                           data-reactive-control
                           data-reactive-default="">
                    <input type="hidden" name="estado" value="<?php echo $h($estadoActivo); ?>" data-reactive-control data-reactive-default="">
                    <?php if (!$periodoPorDefecto) : ?>
                        <input type="hidden" name="fecha_inicio" value="<?php echo $h($filtros['fecha_inicio'] ?? ''); ?>">
                        <input type="hidden" name="fecha_fin" value="<?php echo $h($filtros['fecha_fin'] ?? ''); ?>">
                    <?php endif; ?>
                    <?php /* Para quien navega sin JS; con JS lo oculta _forms.scss. */ ?>
                    <button type="submit" class="admin-btn admin-btn--secondary" data-reactive-submit>Buscar</button>
                    <a class="admin-btn admin-btn--ghost reservations-buscador__limpiar"
                       href="<?php echo $h($periodoPorDefecto ? '/admin/reservaciones' : '/admin/reservaciones?' . http_build_query(['fecha_inicio' => $filtros['fecha_inicio'] ?? '', 'fecha_fin' => $filtros['fecha_fin'] ?? ''])); ?>"
                       data-reactive-clear
                       <?php echo $filtrosActivos ? '' : 'hidden'; ?>>Limpiar</a>
                </div>
            </form>
            <?php
            // Fuera del formulario: el selector navega por su cuenta y conserva
            // la búsqueda y el estado ($rangePreserveQuery).
            $rangeCaption = 'Periodo';
            $rangeStartParam = 'fecha_inicio';
            $rangeEndParam = 'fecha_fin';
            $rangeAllowFuture = true;
            $rangeShowPresets = false;
            $rangePreserveQuery = true;
            include __DIR__ . '/../partials/_range-picker.php';
            ?>
        </div>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atención' : 'No se pudo');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>" role="<?php echo $tipoAlerta === 'error' ? 'alert' : 'status'; ?>">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <div class="admin-reactive-results-shell">
        <div id="reservations-results" class="admin-reactive-results reservations-resultados" data-reactive-results aria-busy="false">
<?php endif; ?>
    <?php /*
      La tira de estados: resumen y filtro a la vez. Sustituye a las cinco
      tarjetas de métricas —tres de ellas contaban cosas que el modelo de
      tres estados ya no produce (sin mesa, por confirmar, tolerancia)— y al
      desplegable de estado. Cada cifra es un enlace al filtro que la produce y
      el reemplazo parcial lo atiende sin recargar (data-reactive-page).

      No es .admin-tabs--links a propósito: reactive-filters.js re-marca esas
      pestañas comparando la URL, y «Todas» —que no lleva parámetro— salía
      activa junto a la elegida. Ésta la pinta el servidor en cada reemplazo.

      tabindex="-1": es adonde vuelve el foco tras registrar una ausencia si
      la fila ya no está en la lista (lista.js).
    */ ?>
    <nav class="reservations-estados" aria-label="Filtrar por estado" tabindex="-1">
        <?php foreach ($opcionesEstado as $clave => $etiqueta) : ?>
            <?php
            $clave = (string)$clave;
            $activa = $clave === $estadoActivo;
            $cuenta = (int)($conteos[$clave] ?? 0);
            $clase = 'reservations-estados__opcion reservations-estados__opcion--' . ($clave === '' ? 'todas' : $clave)
                . ($activa ? ' is-active' : '')
                . ($cuenta === 0 && !$activa ? ' is-empty' : '');
            ?>
            <a class="<?php echo $h($clase); ?>"
               href="<?php echo $h($urlConEstado($clave)); ?>"
               data-reactive-page
               <?php echo $activa ? 'aria-current="page"' : ''; ?>>
                <span class="reservations-estados__cifra"><?php echo $cuenta; ?></span>
                <span class="reservations-estados__etiqueta">
                    <?php /* La misma marca que lleva cada fila en la línea del
                             día: la tira hace de leyenda sin ser una leyenda. */ ?>
                    <?php if ($clave !== '') : ?>
                        <span class="reservations-marca reservations-marca--<?php echo $h(ReservacionListadoPresenter::tono($clave)); ?>" aria-hidden="true"></span>
                    <?php endif; ?>
                    <?php echo $h($etiqueta); ?>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($diasAgenda === []) : ?>
        <div class="reservations-vacio">
            <?php echo admin_icon('reservations', 22, 'reservations-vacio__icono'); ?>
            <?php if ($busqueda !== '') : ?>
                <p><strong>Nadie con «<?php echo $h($busqueda); ?>»</strong> en este periodo.</p>
                <p>Prueba con otra parte del nombre, el teléfono o el correo, o amplía el periodo.</p>
            <?php elseif ($estadoActivo !== '') : ?>
                <p><strong>Nada en «<?php echo $h($opcionesEstado[$estadoActivo] ?? $estadoActivo); ?>»</strong> en este periodo.</p>
            <?php else : ?>
                <p><strong>Sin reservaciones</strong> en este periodo.</p>
                <p>Las nuevas llegan desde la página y desde «Nueva reservación» del POS.</p>
            <?php endif; ?>
            <?php if ($filtrosActivos) : ?>
                <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $h($periodoPorDefecto ? '/admin/reservaciones' : $urlConEstado('')); ?>" data-reactive-clear>Ver todas</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="reservations-libreta">
            <?php foreach ($diasAgenda as $dia) : ?>
                <?php
                $fecha = (string)$dia['fecha'];
                $idDia = 'agenda-' . $fecha;
                $partesResumen = [];
                if ((int)$dia['reservas'] > 0) {
                    $partesResumen[] = $cifra((int)$dia['reservas'], 'reservación', 'reservaciones');
                    $partesResumen[] = $cifra((int)$dia['personas'], 'persona', 'personas');
                }
                if ((int)$dia['no_llegaron'] > 0) {
                    $partesResumen[] = $cifra((int)$dia['no_llegaron'], 'no llegó', 'no llegaron');
                }
                if ((int)$dia['canceladas'] > 0) {
                    $partesResumen[] = $cifra((int)$dia['canceladas'], 'cancelada', 'canceladas');
                }
                $claseDia = 'reservations-dia'
                    . (!empty($dia['es_hoy']) ? ' is-hoy' : '')
                    . (!empty($dia['pasado']) ? ' is-pasado' : '');
                ?>
                <section class="<?php echo $h($claseDia); ?>" aria-labelledby="<?php echo $h($idDia); ?>">
                    <?php /* Una hoja de agenda: el número del día en el margen de
                             las horas —misma columna, misma mono— y a su derecha
                             «Hoy» y el día de la semana. El encabezado se lee
                             entero para el lector de pantalla; lo visible es la
                             composición. */ ?>
                    <header class="reservations-dia__cabecera">
                        <h2 class="reservations-dia__titulo" id="<?php echo $h($idDia); ?>">
                            <span class="admin-visually-hidden"><?php echo $h(($dia['relativo'] !== '' ? $dia['relativo'] . ', ' : '') . $dia['titulo']); ?></span>
                            <span class="reservations-dia__fecha" aria-hidden="true">
                                <span class="reservations-dia__numero"><?php echo (int)$dia['numero']; ?></span>
                                <span class="reservations-dia__mes"><?php echo $h($dia['mes']); ?></span>
                            </span>
                            <span class="reservations-dia__nombre" aria-hidden="true">
                                <?php if ($dia['relativo'] !== '') : ?>
                                    <span class="reservations-dia__relativo"><?php echo $h($dia['relativo']); ?></span>
                                <?php endif; ?>
                                <span class="reservations-dia__semana"><?php echo $h($dia['dia_semana'] . ($dia['anio'] !== '' ? ' · ' . $dia['anio'] : '')); ?></span>
                            </span>
                        </h2>
                        <?php if ($partesResumen !== []) : ?>
                            <p class="reservations-dia__resumen"><?php echo implode(' · ', $partesResumen); ?></p>
                        <?php endif; ?>
                    </header>

                    <ol class="reservations-dia__filas">
                        <?php $ahoraPintada = empty($dia['es_hoy']); ?>
                        <?php foreach ($dia['filas'] as $fila) : ?>
                            <?php
                            $nombre = $fila['nombre'] !== '' ? $fila['nombre'] : 'Sin nombre';
                            $claseFila = 'reservations-fila reservations-fila--' . $fila['tono']
                                . ($fila['inicio_franja'] ? ' is-inicio-franja' : '')
                                . ($fila['sin_efecto'] ? ' is-sin-efecto' : '')
                                . ($fila['tarde'] ? ' is-late' : '');
                            $momento = $fila['hora'] . ', ' . ReservacionListadoPresenter::fechaCorta($fecha);
                            ?>
                            <?php if (!$ahoraPintada && $fila['hora'] > $horaAhora) : ?>
                                <?php $ahoraPintada = true; $pintarAhora(); ?>
                            <?php endif; ?>
                            <li class="<?php echo $h($claseFila); ?>" data-reservacion-id="<?php echo (int)$fila['id']; ?>">
                                <?php /* La hora se escribe una vez por franja; en las
                                         que comparten hora queda sólo para el lector de
                                         pantalla, que no ve el margen. */ ?>
                                <time class="reservations-fila__hora" datetime="<?php echo $h($fecha . 'T' . $fila['hora']); ?>">
                                    <?php if ($fila['inicio_franja']) : ?>
                                        <?php echo $h($fila['hora']); ?>
                                    <?php else : ?>
                                        <span class="admin-visually-hidden"><?php echo $h($fila['hora']); ?></span>
                                    <?php endif; ?>
                                </time>
                                <span class="reservations-fila__marca reservations-marca reservations-marca--<?php echo $h($fila['tono']); ?>" aria-hidden="true"></span>

                                <div class="reservations-fila__quien">
                                    <?php /* La fila entera abre el detalle: el enlace es
                                             el nombre y su ::after cubre la fila. Los
                                             botones de la derecha quedan por encima. */ ?>
                                    <a class="reservations-fila__nombre" href="<?php echo $h($fila['url_detalle']); ?>">
                                        <?php echo $h($nombre); ?><span class="admin-visually-hidden">, <?php echo $h($fila['hora']); ?>. Ver detalle</span>
                                    </a>
                                    <?php if ($fila['nota'] !== '') : ?>
                                        <p class="reservations-fila__nota" title="<?php echo $h($fila['nota']); ?>">
                                            <?php echo admin_icon('nota', 13, 'reservations-fila__nota-icono'); ?>
                                            <span><?php echo $h($fila['nota']); ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="reservations-fila__datos">
                                    <span class="reservations-fila__personas"><?php echo $cifra((int)$fila['comensales'], 'persona', 'personas'); ?></span>
                                    <?php
                                    // Sin mesas sólo es un aviso en una reserva que todavía va a
                                    // ocurrir (se guardan con ellas; si falta, es un Caso). En una
                                    // que ya terminó no avisa de nada: es lo que quedó registrado.
                                    $mesasAviso = $fila['mesas'] === '' && in_array($fila['estado'], ['confirmada', 'en_curso'], true);
                                    ?>
                                    <span class="reservations-fila__mesas<?php echo $fila['mesas'] === '' ? ($mesasAviso ? ' is-aviso' : ' is-vacio') : ''; ?>"<?php echo $fila['mesas'] !== '' ? ' title="' . $h($fila['mesas']) . '"' : ''; ?>>
                                        <?php echo $fila['mesas'] !== '' ? $h($fila['mesas']) : ($mesasAviso ? 'Sin mesas asignadas' : 'Sin mesa'); ?>
                                    </span>
                                    <span class="reservations-fila__estado"><?php echo $h($fila['etiqueta']); ?></span>
                                </div>

                                <div class="reservations-fila__acciones">
                                    <?php if ($fila['puede_no_show']) : ?>
                                        <?php /* La única acción que se resuelve aquí. Sin JS el
                                                 formulario publica y vuelve (POST-redirect-GET);
                                                 con JS, lista.js confirma con el componente y
                                                 repinta sólo la lista. data-confirm-* es el
                                                 respaldo del diálogo genérico de admin.js. */ ?>
                                        <form class="reservations-fila__form"
                                              method="POST"
                                              action="/admin/reservaciones/estado"
                                              data-reservacion-no-llego
                                              data-confirm-delete
                                              data-confirm-variant="warning"
                                              data-confirm-eyebrow="Reservaciones"
                                              data-confirm-title="Registrar que no llegó"
                                              data-confirm-description="<?php echo $h($nombre . ', ' . $momento . '. La tolerancia de llegada ya venció.'); ?>"
                                              data-confirm-consequence="Quedará como ausencia y su mesa se libera."
                                              data-confirm-primary="Registrar que no llegó"
                                              data-nombre="<?php echo $h($nombre); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$fila['id']; ?>">
                                            <input type="hidden" name="estado" value="no_show">
                                            <input type="hidden" name="motivo" value="agenda_ausencia_pendiente">
                                            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
                                            <input type="hidden" name="return_to" value="<?php echo $h($urlListado); ?>">
                                            <button type="submit" class="admin-btn admin-btn--primary admin-btn--small reservations-fila__accion">
                                                <?php echo admin_icon('estado-sin-llegar', 14, 'admin-btn__icon'); ?>
                                                <span>No llegó</span>
                                            </button>
                                        </form>
                                    <?php elseif ($fila['url_pos'] !== '') : ?>
                                        <a class="reservations-fila__pos"
                                           href="<?php echo $h($fila['url_pos']); ?>"
                                           title="Abrir en el POS"
                                           aria-label="Abrir en el POS la reservación de <?php echo $h($nombre . ', ' . $momento); ?>">
                                            <?php echo admin_icon('plano', 16); ?>
                                        </a>
                                    <?php endif; ?>
                                    <span class="reservations-fila__ir" aria-hidden="true"><?php echo admin_icon('chevron-derecha', 16); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$ahoraPintada) : ?>
                            <?php $pintarAhora(); ?>
                        <?php endif; ?>
                    </ol>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="reservations-results-loading" role="status" hidden>Actualizando la agenda</div>
    </div>
    <div class="admin-reactive-error" id="reservations-results-error" role="alert" hidden>
        <span>No fue posible actualizar la agenda.</span>
        <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-reactive-retry>Volver a intentar</button>
    </div>
</section>
<?php endif; ?>
