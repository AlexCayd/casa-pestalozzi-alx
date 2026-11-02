<?php

/**
 * Análisis de datos de Global: los dos negocios comparados, para el dueño.
 *
 * Sólo lectura. Global no vende: esta pantalla no tiene formularios ni
 * acciones, sólo el selector de periodo. Se lee en tres alturas:
 *   1. la organización (venta total contra el periodo anterior y la parte de
 *      cada negocio) junto a la venta diaria de los dos en la misma gráfica;
 *   2. cada negocio lado a lado, con el mismo orden de datos en las dos
 *      tarjetas para que el ojo compare renglón con renglón;
 *   3. los productos de la casa: precio vigente y venta de cada negocio.
 *
 * Las analíticas diagnósticas no van aquí (son de cada menú): cada tarjeta
 * enlaza al análisis de su negocio cuando la persona puede abrirlo.
 *
 * Datos: $consolidado (Services\Analytics\AnalisisConsolidado::armar() o
 * null), $errorConsolidado, $enlacesNegocio y $rango.
 */
require_once __DIR__ . '/partials/_icons.php';

use Services\Shared\RangoPeriodo;

$rango = is_array($rango ?? null) ? $rango : [];
$consolidado = is_array($consolidado ?? null) ? $consolidado : null;
$errorConsolidado = (string) ($errorConsolidado ?? '');
$enlacesNegocio = is_array($enlacesNegocio ?? null) ? $enlacesNegocio : [];
$periodoLabel = (string) ($rango['label'] ?? 'Últimos 30 días');

$e = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
// Pesos enteros, como el resto del tablero de Análisis: es para comparar,
// no un corte de caja.
$money = static fn ($v): string => ($v < 0 ? '−' : '') . '$' . number_format(abs((float) $v), 0);
$entero = static fn ($v): string => number_format((int) $v);
$nombres = ['restaurante' => 'Restaurante', 'panaderia' => 'Panadería'];

/*
 * Variación contra el periodo anterior como chip: flecha, signo y cifra (no
 * sólo color). Sin base no se inventa un porcentaje: se dice que no hay con
 * qué comparar.
 */
$delta = static function (?float $d) use ($e): string {
    if ($d === null) {
        return '<span class="admin-an-delta admin-an-delta--sin">Sin periodo anterior</span>';
    }
    $igual = abs($d) < 0.05;
    $clase = $igual ? 'igual' : ($d > 0 ? 'sube' : 'baja');
    $icono = $igual ? '' : admin_icon($d > 0 ? 'sube' : 'baja', 13);
    $texto = $igual ? 'igual' : (($d > 0 ? '+' : '−') . number_format(abs($d), 1) . '%');

    return '<span class="admin-an-delta admin-an-delta--' . $clase . '">' . $icono . $e($texto)
        . '<span class="admin-visually-hidden"> contra el periodo anterior</span></span>';
};

$negocios = is_array($consolidado['negocios'] ?? null) ? $consolidado['negocios'] : [];
$total = is_array($consolidado['total'] ?? null) ? $consolidado['total'] : [];
$productosCasa = is_array($consolidado['productosCasa'] ?? null) ? $consolidado['productosCasa'] : [];

/*
 * Un negocio sin un ticket en el periodo ni en el anterior no tuvo un mal mes:
 * no registra ventas. Hoy es el caso de Panadería, cuya caja está pendiente.
 * Sus cifras no se pintan como ceros ni entra a la gráfica como una línea
 * plana: se dice que no hay registro.
 */
$sinRegistros = [];
foreach ($negocios as $slug => $n) {
    $sinRegistros[$slug] = (int) ($n['tickets'] ?? 0) === 0 && (int) ($n['previo']['tickets'] ?? 0) === 0;
}
$textoSinRegistros = static fn (string $slug): string => $slug === 'panaderia'
    ? 'La caja de Panadería todavía no registra tickets.'
    : 'Sin tickets en este periodo ni en el anterior.';

// La gráfica sólo recibe los negocios con algo que mostrar; el orden y la
// serie (color) de cada uno no cambian aunque falte el otro.
$seriesGrafica = [];
foreach (['restaurante', 'panaderia'] as $i => $slug) {
    if (isset($negocios[$slug]) && empty($sinRegistros[$slug])) {
        $seriesGrafica[] = [
            'slug' => $slug,
            'nombre' => (string) $negocios[$slug]['nombre'],
            'serie' => $i + 1,
            'valores' => array_values($negocios[$slug]['porDia'] ?? []),
        ];
    }
}
$omitidos = array_keys(array_filter($sinRegistros));
?>
<section class="admin-analytics admin-an-global" data-admin-analytics-global aria-labelledby="an-global-titulo">
    <header class="admin-page-header">
        <div class="admin-page-header__intro">
            <div class="admin-page-title-row">
                <h2 id="an-global-titulo">Análisis de datos</h2>
            </div>
            <p class="admin-page-summary">
                Restaurante y Panadería comparados <span aria-hidden="true">·</span>
                <?php echo $e($periodoLabel); ?> <span aria-hidden="true">·</span>
                Sólo consulta
            </p>
        </div>

        <?php include __DIR__ . '/partials/_range-picker.php'; ?>
    </header>

<?php if ($consolidado === null || $negocios === []) : ?>
    <?php /* Error o sin permiso: qué pasó y a dónde se puede ir, en vez de
             tarjetas en cero. */ ?>
    <section class="admin-panel admin-an-vacio" aria-labelledby="an-global-vacio-titulo">
        <span class="admin-an-vacio__icono admin-an-vacio__icono--alerta"><?php echo admin_icon('alerta', 22); ?></span>
        <h3 class="admin-an-vacio__titulo" id="an-global-vacio-titulo">No hay cifras de la organización que mostrar</h3>
        <p class="admin-an-vacio__texto" role="alert"><?php echo $e($errorConsolidado !== '' ? $errorConsolidado : 'No se pudo cargar el análisis de la organización.'); ?></p>
        <?php if ($enlacesNegocio !== []) : ?>
            <p class="admin-an-vacio__nota">El análisis de cada negocio se consulta en su propia pantalla:</p>
            <div class="admin-an-vacio__acciones">
                <?php foreach ($enlacesNegocio as $slug => $url) : ?>
                    <a class="admin-an-enlace" href="<?php echo $e($url); ?>">
                        Análisis de <?php echo $e($nombres[$slug] ?? ucfirst((string) $slug)); ?><?php echo admin_icon('flecha-derecha', 15); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php else : ?>
    <script>
        window.AdminAnalyticsGlobal = <?php echo json_encode([
            'etiquetas' => array_values($consolidado['etiquetasDia'] ?? []),
            'series' => $seriesGrafica,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>

    <?php /* ── 1 · La organización ────────────────────────────────────
             Misma composición que el titular de cada negocio: la cifra manda
             y la tendencia se lee a su lado. La participación va al pie de la
             cifra porque es la respuesta a «¿de dónde salió este total?». */ ?>
    <section class="admin-metrics-section" aria-label="La organización">
        <div class="admin-metrics-lead">
            <div class="admin-metrics-hero">
                <article class="admin-hero">
                    <div class="admin-hero__head">
                        <span class="admin-hero__label">Ventas de la organización</span>
                    </div>
                    <div class="admin-hero__main">
                        <strong class="admin-hero__value"><?php echo $e($money($total['ventas'] ?? 0)); ?></strong>
                        <span class="admin-hero__detail">
                            <?php echo $e($entero($total['tickets'] ?? 0)); ?> tickets
                            <?php if (($total['ticketPromedio'] ?? null) !== null) : ?>
                                · ticket promedio <?php echo $e($money($total['ticketPromedio'])); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="admin-hero__foot">
                        <?php echo $delta(isset($total['deltaVentas']) ? (float) $total['deltaVentas'] : null); ?>
                        <span class="admin-hero__prev"><?php echo $e($money($total['previo']['ventas'] ?? 0)); ?> en el periodo anterior</span>

                        <?php if ((float) ($total['ventas'] ?? 0) > 0) : ?>
                            <?php /* Parte de cada negocio en la venta. El color
                                     es la identidad del negocio (el mismo de la
                                     gráfica) y el nombre con su cifra lo dice
                                     también en texto. */ ?>
                            <div class="admin-an-parte">
                                <span class="admin-an-parte__titulo">Parte de la venta</span>
                                <div class="admin-an-parte__barra" aria-hidden="true">
                                    <?php foreach (['restaurante', 'panaderia'] as $i => $slug) : ?>
                                        <?php $p = $total['participacion'][$slug] ?? null; ?>
                                        <?php if ($p !== null && $p > 0) : ?>
                                            <span class="admin-an-parte__tramo admin-an-serie--<?php echo $i + 1; ?>" style="--parte: <?php echo $e(round((float) $p, 2)); ?>%"></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <ul class="admin-an-parte__lista" role="list">
                                    <?php foreach (['restaurante', 'panaderia'] as $i => $slug) : ?>
                                        <?php if (!isset($negocios[$slug])) continue; ?>
                                        <li>
                                            <span class="admin-an-muestra admin-an-serie--<?php echo $i + 1; ?>" aria-hidden="true"></span>
                                            <?php echo $e($negocios[$slug]['nombre']); ?>
                                            <?php if (!empty($sinRegistros[$slug])) : ?>
                                                <span class="admin-an-parte__sin">sin registros</span>
                                            <?php else : ?>
                                                <strong><?php echo $e(number_format((float) ($total['participacion'][$slug] ?? 0), 1) . '%'); ?></strong>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <article class="admin-panel admin-chart-card admin-chart-card--lead">
                <header>
                    <div>
                        <h3>Venta diaria por negocio</h3>
                        <p>Cobrado cada día en cada negocio</p>
                    </div>
                    <span>MXN</span>
                </header>
                <?php if ($seriesGrafica !== []) : ?>
                    <div class="admin-chart-card__canvas">
                        <canvas id="orgSalesByDayChart" role="img" aria-label="Venta diaria de <?php echo $e(implode(' y ', array_column($seriesGrafica, 'nombre'))); ?> en el periodo"></canvas>
                    </div>
                <?php else : ?>
                    <p class="admin-an-sin-filas">Ningún negocio registra ventas en este periodo ni en el anterior.</p>
                <?php endif; ?>
                <?php foreach ($omitidos as $slug) : ?>
                    <?php if ($seriesGrafica !== []) : ?>
                        <p class="admin-an-nota"><?php echo $e($nombres[$slug] ?? $slug); ?> no aparece: <?php echo $e(lcfirst($textoSinRegistros($slug))); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </article>
        </div>
    </section>

    <?php /* ── 2 · Cada negocio ───────────────────────────────────────
             Las dos tarjetas repiten exactamente el mismo orden (ventas,
             tickets, ticket promedio, piezas por ticket, lo más vendido) para
             que se comparen por renglón. */ ?>
    <section class="admin-an-negocios" aria-label="Comparativo por negocio">
        <?php foreach (['restaurante', 'panaderia'] as $i => $slug) : ?>
            <?php if (!isset($negocios[$slug])) continue; ?>
            <?php
                $n = $negocios[$slug];
                $tituloId = 'an-negocio-' . $slug;
                $ticketProm = $n['ticketPromedio'] ?? null;
                $ticketPromPrev = $n['previo']['ticketPromedio'] ?? null;
                $deltaTicketProm = ($ticketProm !== null && $ticketPromPrev !== null)
                    ? RangoPeriodo::delta((float) $ticketProm, (float) $ticketPromPrev) : null;
                $topPiezas = 0;
                foreach ($n['masVendidos'] ?? [] as $mv) {
                    $topPiezas = max($topPiezas, (int) $mv['piezas']);
                }
            ?>
            <article class="admin-panel admin-an-negocio" aria-labelledby="<?php echo $tituloId; ?>">
                <header class="admin-an-negocio__head">
                    <h3 id="<?php echo $tituloId; ?>">
                        <span class="admin-an-muestra admin-an-serie--<?php echo $i + 1; ?>" aria-hidden="true"></span>
                        <?php echo $e($n['nombre']); ?>
                    </h3>
                    <?php if (isset($enlacesNegocio[$slug])) : ?>
                        <a class="admin-an-enlace" href="<?php echo $e($enlacesNegocio[$slug]); ?>">
                            Ver su análisis<span class="admin-visually-hidden"> de <?php echo $e($n['nombre']); ?></span><?php echo admin_icon('flecha-derecha', 15); ?>
                        </a>
                    <?php endif; ?>
                </header>

                <?php if (!empty($sinRegistros[$slug])) : ?>
                    <div class="admin-an-negocio__pendiente">
                        <span class="admin-badge"><?php echo admin_icon($slug === 'panaderia' ? 'caja' : 'info', 14); ?><?php echo $slug === 'panaderia' ? 'Caja pendiente' : 'Sin registros'; ?></span>
                        <p><?php echo $e($textoSinRegistros($slug)); ?> Sus cifras aparecerán aquí, en la gráfica y en los productos de la casa cuando empiece a cobrar.</p>
                    </div>
                <?php else : ?>
                    <div class="admin-an-negocio__venta">
                        <span class="admin-an-negocio__rotulo">Ventas</span>
                        <strong class="admin-an-negocio__cifra"><?php echo $e($money($n['ventas'])); ?></strong>
                        <p class="admin-an-negocio__comparacion">
                            <?php echo $delta(isset($n['deltaVentas']) ? (float) $n['deltaVentas'] : null); ?>
                            <?php if ((float) ($n['previo']['ventas'] ?? 0) > 0) : ?>
                                <span><?php echo $e($money($n['previo']['ventas'])); ?> antes</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <dl class="admin-an-negocio__datos">
                        <div>
                            <dt>Tickets</dt>
                            <dd>
                                <span class="admin-an-negocio__valor"><?php echo $e($entero($n['tickets'])); ?></span>
                                <?php echo $delta(isset($n['deltaTickets']) ? (float) $n['deltaTickets'] : null); ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Ticket promedio</dt>
                            <dd>
                                <span class="admin-an-negocio__valor"><?php echo $ticketProm === null ? '—' : $e($money($ticketProm)); ?></span>
                                <?php echo $delta($deltaTicketProm); ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Piezas por ticket</dt>
                            <dd>
                                <span class="admin-an-negocio__valor"><?php echo ($n['piezasPorTicket'] ?? null) === null ? '—' : $e(number_format((float) $n['piezasPorTicket'], 1)); ?></span>
                                <?php echo $delta(isset($n['deltaPiezasPorTicket']) ? (float) $n['deltaPiezasPorTicket'] : null); ?>
                                <span class="admin-an-negocio__aparte"><?php echo $e($entero($n['piezas'] ?? 0)); ?> piezas</span>
                            </dd>
                        </div>
                    </dl>

                    <section class="admin-an-top" aria-labelledby="<?php echo $tituloId; ?>-top">
                        <h4 class="admin-an-top__titulo" id="<?php echo $tituloId; ?>-top">Lo más vendido</h4>
                        <?php if (empty($n['masVendidos'])) : ?>
                            <p class="admin-an-sin-filas">Sin ventas en el periodo.</p>
                        <?php else : ?>
                            <?php /* Magnitud, no identidad: la barra va en un
                                     solo tono neutro y es proporcional al más
                                     vendido de ESTE negocio. */ ?>
                            <ol class="admin-an-top__lista">
                                <?php foreach ($n['masVendidos'] as $mv) : ?>
                                    <li class="admin-an-top__item">
                                        <span class="admin-an-top__nombre"><?php echo $e($mv['nombre']); ?></span>
                                        <span class="admin-an-top__cifras admin-num">
                                            <?php echo $e($entero($mv['piezas'])); ?> pzas · <?php echo $e($money($mv['ventas'])); ?>
                                        </span>
                                        <span class="admin-an-top__barra" aria-hidden="true">
                                            <span style="--parte: <?php echo $topPiezas > 0 ? $e(round((int) $mv['piezas'] / $topPiezas * 100, 1)) : 0; ?>%"></span>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php /* ── 3 · Productos de la casa ────────────────────────────────
             Una fila por estándar publicado desde Global; por negocio, su
             oferta y lo vendido. «No lo ofrece» ocupa las cuatro celdas del
             negocio; «Sin ventas» sólo las tres de venta, porque el precio
             vigente sí existe. */ ?>
    <article class="admin-panel admin-table-panel admin-an-casa" aria-labelledby="an-casa-titulo">
        <header>
            <div>
                <p class="admin-page-eyebrow">Productos de la casa</p>
                <h3 id="an-casa-titulo">Cómo ofrece y vende cada negocio los estándares</h3>
            </div>
            <span><?php echo count($productosCasa); ?> <?php echo count($productosCasa) === 1 ? 'producto' : 'productos'; ?></span>
        </header>

        <?php if ($productosCasa === []) : ?>
            <p class="admin-an-sin-filas">Global todavía no publica productos de la casa.</p>
        <?php else : ?>
            <p class="admin-an-nota">
                Precio de la oferta vigente en cada negocio y lo vendido en el periodo.
                «Cobrado» es el precio promedio que realmente se cobró.
                <?php foreach ($omitidos as $slug) : ?>
                    <strong><?php echo $e($textoSinRegistros($slug)); ?></strong> De <?php echo $e($nombres[$slug] ?? $slug); ?> sólo se muestra su precio.
                <?php endforeach; ?>
            </p>
            <div class="admin-table-wrap" data-scrollable>
                <table class="admin-table admin-table--compact admin-an-casa__tabla">
                    <caption class="admin-visually-hidden">Productos de la casa por negocio: precio vigente, piezas, ingreso y precio cobrado</caption>
                    <thead>
                        <tr>
                            <th scope="col" rowspan="2" class="admin-an-casa__producto">Producto</th>
                            <?php foreach (['restaurante', 'panaderia'] as $i => $slug) : ?>
                                <th scope="colgroup" colspan="4" class="admin-an-casa__grupo">
                                    <span class="admin-an-muestra admin-an-serie--<?php echo $i + 1; ?>" aria-hidden="true"></span><?php echo $e($nombres[$slug]); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach (['restaurante', 'panaderia'] as $slug) : ?>
                                <th scope="col" class="admin-table__num admin-an-casa__inicio">Precio</th>
                                <th scope="col" class="admin-table__num">Piezas</th>
                                <th scope="col" class="admin-table__num">Ingreso</th>
                                <th scope="col" class="admin-table__num">Cobrado</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productosCasa as $prod) : ?>
                            <?php
                                $ofertas = is_array($prod['negocios'] ?? null) ? $prod['negocios'] : [];
                                $precios = [];
                                foreach ($ofertas as $o) {
                                    if (is_array($o) && ($o['precio'] ?? null) !== null) {
                                        $precios[] = round((float) $o['precio'], 2);
                                    }
                                }
                                // Mismo producto de la casa con precio distinto en
                                // cada negocio: dato que el dueño querrá ver.
                                $precioDistinto = count($precios) === 2 && $precios[0] !== $precios[1];
                            ?>
                            <tr>
                                <th scope="row" class="admin-an-casa__producto">
                                    <span class="admin-table__cell-main"><?php echo $e($prod['nombre']); ?></span>
                                    <?php if ($precioDistinto) : ?>
                                        <span class="admin-table__cell-sub">Precio distinto entre negocios</span>
                                    <?php endif; ?>
                                </th>
                                <?php foreach (['restaurante', 'panaderia'] as $slug) : ?>
                                    <?php $o = $ofertas[$slug] ?? null; ?>
                                    <?php if (!is_array($o)) : ?>
                                        <td colspan="4" class="admin-an-casa__inicio admin-an-casa__estado admin-an-casa__ausente">No lo ofrece</td>
                                    <?php else : ?>
                                        <td class="admin-table__num admin-an-casa__inicio">
                                            <?php if (($o['precio'] ?? null) === null) : ?>
                                                <span class="admin-an-casa__estado">Sin oferta vigente</span>
                                            <?php else : ?>
                                                <span class="admin-table__cell-main"><?php echo $e($money($o['precio'])); ?></span>
                                                <?php if (empty($o['activo'])) : ?>
                                                    <span class="admin-table__cell-sub">Oferta inactiva</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ((int) ($o['piezas'] ?? 0) <= 0) : ?>
                                            <td colspan="3" class="admin-an-casa__estado">Sin ventas</td>
                                        <?php else : ?>
                                            <td class="admin-table__num"><?php echo $e($entero($o['piezas'])); ?></td>
                                            <td class="admin-table__num"><?php echo $e($money($o['ventas'])); ?></td>
                                            <td class="admin-table__num">
                                                <?php echo ($o['precioCobrado'] ?? null) === null ? '—' : $e($money($o['precioCobrado'])); ?>
                                                <?php if (($o['precioCobrado'] ?? null) !== null && ($o['precio'] ?? null) !== null
                                                    && abs((float) $o['precioCobrado'] - (float) $o['precio']) >= 0.5) : ?>
                                                    <span class="admin-table__cell-sub">distinto al vigente</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <p class="admin-an-global__pie">
        La ingeniería de menú, las franjas que más rinden y qué se pide junto con qué son de
        cada menú: están en el análisis de cada negocio.
    </p>
<?php endif; ?>
</section>
