<?php
/**
 * Histórico de tickets.
 *
 * Arriba, cuatro tarjetas que SON el filtro de la lista: cada una enseña una
 * cifra con su contexto y su comparación, y al pulsarla la lista se queda con
 * ese estado. Antes eran cuatro cajas grises con un número suelto —«Tickets»,
 * «Abiertos», «Cerrados» y la venta acumulada de toda la historia— que no
 * respondían nada ni llevaban a ningún sitio.
 *
 * Cada tarjeta dice algo distinto, y por eso no todas miden lo mismo:
 *   · Todos      cuántos tickets hubo (tráfico), con su ritmo diario.
 *   · Abiertos   cuentas vivas AHORA, de cualquier fecha: la alarma es la que
 *                se quedó abierta de un día anterior (un cobro olvidado).
 *   · Cerrados   la VENTA del periodo, con el número de tickets y el ticket
 *                promedio de contexto. Con la cifra en tickets, «Todos» y
 *                «Cerrados» habrían dicho casi el mismo número en dos cajas.
 *   · Cancelados cuántos y qué parte del periodo; aquí subir es malo.
 *
 * El color es el del ESTADO —el mismo que el badge de la fila: ámbar, verde y
 * rojo de la paleta funcional— y la de «Todos» es la tinta del panel. En
 * reposo el color vive en la pastilla del rótulo; la tarjeta elegida se rellena
 * entera, sólida, y lleva además una marca de verificación: el estado elegido
 * no se lee sólo por el color.
 *
 * Sin JS es un formulario GET normal (estado + periodo en la URL); tickets.js
 * lo convierte en un cambio de lista sin recarga.
 */
require_once __DIR__ . '/../partials/_icons.php';

$rango = is_array($rango ?? null) ? $rango : [];
$estado = (string) ($estado ?? '');
$resumen = is_array($resumen ?? null) ? $resumen : null;

$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$dinero = static fn ($v, int $decimales = 2): string => '$' . number_format((float) $v, $decimales);
$entero = static fn ($v): string => number_format((float) $v, 0);

// Variación contra el periodo anterior. Mismo formato que el resto del panel
// (_stat-card.php), con el signo menos tipográfico.
$tkDelta = static function (?float $delta): string {
    if ($delta === null) {
        return '';
    }
    $abs = abs($delta);
    $cifra = number_format($abs, $abs >= 100 ? 0 : 1);

    return ($delta >= 0 ? '+' : "\u{2212}") . $cifra . '%';
};

$tkDias = max(1, (int) ($rango['dias'] ?? 30));
$tkPrevios = $tkDias === 1 ? 'el día previo' : ($tkDias === 365 ? 'el año previo' : 'los ' . $tkDias . ' días previos');

// Antigüedad del ticket abierto más viejo, en la unidad que se lee de un
// vistazo. Lo de otro día se cuenta en días de calendario, igual que su fila
// en la lista («Abierto hace 12 días»): las dos cifras no pueden discrepar.
$tkHace = static function (int $minutos, int $dias): string {
    if ($dias === 1) {
        return 'desde ayer';
    }
    if ($dias > 1) {
        return 'hace ' . $dias . ' días';
    }
    if ($minutos < 60) {
        return 'hace ' . max(1, $minutos) . ' min';
    }

    return 'hace ' . intdiv($minutos, 60) . ' h';
};

/*
 * Mini-tendencia en SVG, dibujada aquí y no en JS: es un dato de la carga de
 * página, no cambia con el filtro y así existe también sin scripts. Sin color
 * propio —sale de currentColor, que la hoja fija por tarjeta— y con
 * preserveAspectRatio="none" para que llene su caja; el trazo no se deforma
 * gracias a vector-effect.
 */
$tkSerie = static function (array $valores): string {
    $n = count($valores);
    $max = $n > 0 ? max($valores) : 0;
    if ($n < 2 || $max <= 0) {
        return '';
    }
    $ancho = 100;
    $alto = 32;
    $puntos = [];
    foreach (array_values($valores) as $i => $v) {
        $x = round($i / ($n - 1) * $ancho, 2);
        // 3 px de aire arriba para que el pico no toque el canto de la caja.
        $y = round($alto - ((float) $v / $max) * ($alto - 3), 2);
        $puntos[] = $x . ' ' . $y;
    }
    $linea = 'M' . implode(' L', $puntos);
    $area = $linea . ' L' . $ancho . ' ' . $alto . ' L0 ' . $alto . ' Z';

    return '<svg class="tk-serie" viewBox="0 0 ' . $ancho . ' ' . $alto . '" preserveAspectRatio="none"'
        . ' aria-hidden="true" focusable="false">'
        . '<path class="tk-serie__area" d="' . $area . '"/>'
        . '<path class="tk-serie__linea" d="' . $linea . '" vector-effect="non-scaling-stroke"/>'
        . '</svg>';
};

// El periodo viaja en la URL del filtro: así cambiar de estado no lo pierde.
$tkPeriodo = ((int) ($rango['preset'] ?? 0)) > 0
    ? ['rango' => (int) $rango['preset']]
    : ['desde' => (string) ($rango['start'] ?? ''), 'hasta' => (string) ($rango['end'] ?? '')];

$tarjetas = [];
if ($resumen !== null) {
    $todos = $resumen['todos'];
    $abiertos = $resumen['abierto'];
    $cerrados = $resumen['cerrado'];
    $cancelados = $resumen['cancelado'];

    $tarjetas[] = [
        'estado' => '',
        'rotulo' => 'Todos',
        'cifra' => $entero($todos['n']),
        'contexto' => [number_format((float) $todos['porDia'], 1) . ' por día en promedio'],
        'delta' => $todos['delta'],
        'bueno' => $todos['delta'] === null || $todos['delta'] >= 0,
        'sinBase' => 'Sin tickets en ' . $tkPrevios,
        'serie' => $tkSerie($todos['serie']),
    ];

    $contextoAbiertos = $abiertos['atrasados'] > 0
        ? ['aviso' => $abiertos['atrasados'] . ($abiertos['atrasados'] === 1 ? ' es de un día anterior' : ' son de días anteriores')]
        : [$abiertos['n'] > 0 ? 'Todos se abrieron hoy' : 'Ninguna cuenta pendiente'];

    $tarjetas[] = [
        'estado' => 'abierto',
        'rotulo' => 'Abiertos',
        // «ahora» es lo que explica que esta cifra no siga al periodo. En una
        // tarjeta estrecha (dos por fila en un teléfono) no cabe en la
        // pastilla y se oculta sólo a la vista: el lector de pantalla la lee.
        'rotuloExtra' => 'ahora',
        'cifra' => $entero($abiertos['n']),
        'contexto' => $contextoAbiertos,
        // Sin comparativo: es el estado de este momento, no un hecho del
        // periodo. El pie dice en cambio cuánto lleva esperando el más viejo.
        'pie' => $abiertos['n'] > 0
            ? ['icono' => 'reloj', 'texto' => 'El más antiguo, ' . $tkHace((int) $abiertos['minutos'], (int) $abiertos['dias'])]
            : ['icono' => 'check', 'texto' => 'Todo cobrado'],
    ];

    $tarjetas[] = [
        'estado' => 'cerrado',
        'rotulo' => 'Cerrados',
        'cifra' => $dinero($cerrados['venta'], 0),
        'contexto' => [
            $entero($cerrados['n']) . ($cerrados['n'] === 1 ? ' ticket' : ' tickets'),
            'promedio ' . $dinero($cerrados['promedio']),
        ],
        'delta' => $cerrados['delta'],
        'bueno' => $cerrados['delta'] === null || $cerrados['delta'] >= 0,
        'sinBase' => 'Sin venta en ' . $tkPrevios,
        'serie' => $tkSerie($cerrados['serie']),
    ];

    $tarjetas[] = [
        'estado' => 'cancelado',
        'rotulo' => 'Cancelados',
        'cifra' => $entero($cancelados['n']),
        'contexto' => [$cancelados['n'] > 0
            ? number_format((float) $cancelados['tasa'], 1) . '% de los tickets del periodo'
            : 'Ninguno en el periodo'],
        'delta' => $cancelados['delta'],
        // Métrica invertida: que suba es malo. La flecha sigue describiendo la
        // dirección real; sólo cambia el color.
        'bueno' => $cancelados['delta'] === null || $cancelados['delta'] <= 0,
        'sinBase' => 'Ninguno en ' . $tkPrevios,
    ];
}
?>
<section class="admin-page tk">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación</span>
            <h2 class="admin-page__title">Tickets</h2>
            <p class="admin-page__subtitle">
                Cuentas del servicio con su total calculado a partir de sus items. Elige una tarjeta para ver sólo ese estado.
            </p>
        </div>
        <div class="admin-actions">
            <?php
            // Conserva el estado al cambiar de periodo: quien mira «Cerrados»
            // y amplía a 60 días quiere seguir viendo cerrados.
            $rangePreserveQuery = true;
            include __DIR__ . '/../partials/_range-picker.php';
            unset($rangePreserveQuery);
            ?>
        </div>
    </header>

    <?php if ($resumen !== null) : ?>
        <form class="tk-filtros" method="get" action="/admin/tickets" data-tk-filtros data-reveal>
            <?php foreach ($tkPeriodo as $clave => $valor) : ?>
                <input type="hidden" name="<?php echo $e($clave); ?>" value="<?php echo $e($valor); ?>">
            <?php endforeach; ?>

            <div class="tk-filtros__grupo" role="group" aria-label="Filtrar la lista por estado">
                <?php foreach ($tarjetas as $t) : ?>
                    <?php
                    $tkId = 'tk-f-' . ($t['estado'] !== '' ? $t['estado'] : 'todos');
                    $tkPulsada = $estado === $t['estado'];
                    ?>
                    <button type="submit" name="estado" value="<?php echo $e($t['estado']); ?>"
                            class="tk-filtro tk-filtro--<?php echo $e($t['estado'] !== '' ? $t['estado'] : 'todos'); ?>"
                            data-tk-estado="<?php echo $e($t['estado']); ?>"
                            aria-pressed="<?php echo $tkPulsada ? 'true' : 'false'; ?>">
                        <span class="tk-filtro__cabeza">
                            <span class="tk-filtro__rotulo"><?php echo $e($t['rotulo']); ?><?php if (!empty($t['rotuloExtra'])) : ?><span class="tk-filtro__rotulo-extra"> <?php echo $e($t['rotuloExtra']); ?></span><?php endif; ?></span>
                            <span class="tk-filtro__marca" aria-hidden="true"><?php echo admin_icon('check', 13); ?></span>
                        </span>

                        <span class="tk-filtro__cuerpo">
                            <span class="tk-filtro__cifra"><?php echo $e($t['cifra']); ?></span>
                            <?php echo $t['serie'] ?? ''; ?>
                        </span>

                        <span class="tk-filtro__contexto">
                            <?php foreach ($t['contexto'] as $tipo => $texto) : ?>
                                <?php if ($tipo === 'aviso') : ?>
                                    <span class="tk-filtro__aviso"><?php echo admin_icon('alerta', 14); ?><?php echo $e($texto); ?></span>
                                <?php else : ?>
                                    <span class="tk-filtro__dato"><?php echo $e($texto); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </span>

                        <span class="tk-filtro__pie">
                            <?php if (isset($t['pie'])) : ?>
                                <span class="tk-filtro__compara tk-filtro__compara--icono"><?php echo admin_icon($t['pie']['icono'], 13); ?><?php echo $e($t['pie']['texto']); ?></span>
                            <?php elseif ($t['delta'] !== null) : ?>
                                <span class="tk-delta tk-delta--<?php echo $t['bueno'] ? 'bueno' : 'malo'; ?>">
                                    <?php echo admin_icon($t['delta'] >= 0 ? 'sube' : 'baja', 12); ?>
                                    <?php echo $e($tkDelta($t['delta'])); ?>
                                </span>
                                <span class="tk-filtro__compara">vs. <?php echo $e($tkPrevios); ?></span>
                            <?php else : ?>
                                <span class="tk-filtro__compara"><?php echo $e($t['sinBase']); ?></span>
                            <?php endif; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    <?php endif; ?>

    <section class="admin-panel tk-panel" aria-labelledby="tk-lista-titulo">
        <div class="tk-lista" id="tk-lista" data-tk-lista aria-busy="false">
            <?php include __DIR__ . '/_lista.php'; ?>
        </div>
        <?php /* La región viva NO es la lista: anunciar una tabla de veinte filas
                 entera cada vez que cambia el filtro sería un muro de voz. Se
                 anuncia sólo el resumen («312 tickets cerrados…»). */ ?>
        <p class="admin-visually-hidden" role="status" data-tk-anuncio></p>
    </section>
</section>
