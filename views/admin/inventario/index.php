<?php
    /**
     * Inventario de ingredientes.
     *
     * Arriba, las cifras del periodo (valor, consumo, merma). Debajo, las
     * existencias con sus tarjetas-filtro —el mismo componente que Menú—: cada
     * estado accionable convierte el listado en una lista de pendientes con su
     * acción directa (?pendiente=stock → registrar la entrada; ?pendiente=
     * proveedor → asignarlo). Al final, el alta y la bitácora de merma.
     *
     * El antiguo panel «Reabastecimiento rápido» es ahora la lista de
     * pendientes de stock: era la misma tarea en otro sitio, siempre a la vista
     * aunque nadie fuera a surtir en ese momento.
     */
    require_once __DIR__ . '/../partials/_icons.php';

    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $ingredientesBajos = isset($ingredientesBajos) && is_iterable($ingredientesBajos) ? $ingredientesBajos : [];
    $ingredientesSinProveedor = isset($ingredientesSinProveedor) && is_iterable($ingredientesSinProveedor) ? $ingredientesSinProveedor : [];
    $totalIngredientes = (int) ($totalIngredientes ?? 0);
    $ingredientesActivos = (int) ($ingredientesActivos ?? 0);
    $pendiente = (string) ($pendiente ?? '');
    $consultaActual = is_array($consultaActual ?? null) ? $consultaActual : [];
    $hayProveedores = (bool) ($hayProveedores ?? true);
    $rango = is_array($rango ?? null) ? $rango : [];
    $valorInventario = (float) ($valorInventario ?? 0);
    $consumo = is_array($consumo ?? null) ? $consumo : [];
    $consumo += ['valor' => 0.0, 'movimientos' => 0, 'ajustes' => 0, 'entradas' => 0, 'mermaValor' => 0.0, 'mermas' => 0];
    $consumoPrev = is_array($consumoPrev ?? null) ? $consumoPrev : [];
    $consumoPrev += ['valor' => 0.0, 'mermaValor' => 0.0];
    $comparando = (bool) ($comparando ?? false);
    $deltaConsumo = isset($deltaConsumo) ? $deltaConsumo : null;
    $deltaMerma = isset($deltaMerma) ? $deltaMerma : null;
    $motivosMerma = isset($motivosMerma) && is_array($motivosMerma) ? $motivosMerma : [];
    $mermas = isset($mermas) && is_iterable($mermas) ? $mermas : [];
    $mermaPorMotivo = is_array($mermaPorMotivo ?? null) ? $mermaPorMotivo : [];
    // Para el valor de un <input>: sin separador de miles, que el campo no lo admite.
    $fmt = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    // Para leer: con miles, y sin ceros de relleno.
    $cant = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $money = static fn ($v): string => '$' . number_format((float) $v, 2);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    // El badge de variación lo arma views/admin/partials/_stat-card.php.

    // Los formularios de la página llevan la query de lo que se está viendo
    // (periodo y pendiente): el controlador vuelve a pintar el listado con ella.
    $sufijo = empty($consultaActual) ? '' : '?' . http_build_query($consultaActual);

    // Las tarjetas-filtro conservan el periodo y sólo cambian el pendiente.
    $urlPendiente = static function (string $p) use ($consultaActual): string {
        $consulta = $consultaActual;
        unset($consulta['pendiente']);
        if ($p !== '') {
            $consulta['pendiente'] = $p;
        }

        return '/admin/inventario' . (empty($consulta) ? '' : '?' . http_build_query($consulta));
    };

    $numBajos = count($ingredientesBajos);
    $numSinProveedor = count($ingredientesSinProveedor);
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title">Inventario de ingredientes</h2>
            <p class="admin-page__subtitle">Controla las existencias de cada ingrediente. Las ventas descuentan el stock automáticamente según la receta de cada producto.</p>
        </div>
        <?php /* Igual que en Finanzas: el selector de periodo se queda solo en el
                 encabezado y las acciones bajan a su propia barra. */ ?>
        <div class="admin-actions">
            <?php include __DIR__ . '/../partials/_range-picker.php'; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-toolbar admin-inventario__toolbar">
        <a class="admin-btn admin-btn--secondary" href="/admin/recetas">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>
            Ver recetas
        </a>
        <a class="admin-btn admin-btn--secondary" href="#inventario-merma">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
            Registrar merma
        </a>
        <a class="admin-btn admin-btn--secondary" href="/admin/inventario/proveedores">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 9h18l-2 11H5L3 9Z"/><path d="M8 9V6a4 4 0 0 1 8 0v3"/></svg>
            Proveedores
        </a>
        <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/inventario/create">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            <span>Nuevo ingrediente</span>
        </a>
    </div>

    <?php /* Las cifras del PERIODO, las que siguen al selector del encabezado.
             «Ingredientes» y «Bajo stock» bajaron a las tarjetas-filtro de las
             existencias: son fotos del almacén de hoy, no del periodo, y además
             ahora filtran la lista que tienen justo debajo. */ ?>
    <div class="admin-stat-strip admin-inventario__cifras">
        <?php
            $statLabel = 'Valor del inventario';
            $statValue = $money($valorInventario);
            $statSub = 'Existencias de hoy × costo unitario';
            include __DIR__ . '/../partials/_stat-card.php';

            // Valorizado con el costo ACTUAL del ingrediente: las salidas por
            // venta no guardan el costo del momento.
            $statLabel = 'Consumo del periodo';
            $statValue = $money($consumo['valor']);
            $statSub = (int) $consumo['movimientos'] . ' salidas por venta · '
                . (int) $consumo['entradas'] . ' entradas · '
                . (int) $consumo['ajustes'] . ' ajustes · a costo actual';
            $statDelta = $deltaConsumo;
            $statPrev = $comparando ? $money($consumoPrev['valor']) : '';
            include __DIR__ . '/../partials/_stat-card.php';

            // La merma sí trae su costo congelado: lo que se tiró costó lo que
            // costaba ese día, no lo que cuesta hoy.
            $statLabel = 'Merma del periodo';
            $statValue = $money($consumo['mermaValor']);
            $statSub = (int) $consumo['mermas'] . ' registro(s) · al costo del día';
            $statDelta = $deltaMerma;
            $statPrev = $comparando ? $money($consumoPrev['mermaValor']) : '';
            $statTone = $consumo['mermaValor'] > 0 ? 'bad' : '';
            $statInverso = true;
            include __DIR__ . '/../partials/_stat-card.php';
        ?>
    </div>

    <?php /* ── Existencias ─────────────────────────────────────────────
             Las tarjetas-filtro van pegadas al listado que filtran (proximidad)
             y cuentan sólo ingredientes activos (ver el controlador). */ ?>
    <div class="admin-inventario__control" id="existencias">
        <?php
            $inactivos = $totalIngredientes - $ingredientesActivos;
            $feEtiqueta = 'Estado de las existencias';
            $feTarjetas = [
                [
                    'tipo' => 'todos',
                    'rotulo' => 'Ingredientes',
                    'cifra' => number_format($totalIngredientes),
                    'nota' => $inactivos > 0
                        ? number_format($ingredientesActivos) . ' activos · ' . number_format($inactivos) . ($inactivos === 1 ? ' inactivo' : ' inactivos')
                        : 'Todos activos',
                    'accion' => 'Ver existencias',
                    'href' => $urlPendiente(''),
                    'activa' => $pendiente === '',
                ],
                [
                    'tipo' => 'tarea',
                    'rotulo' => 'Sobre el mínimo',
                    'hechos' => $ingredientesActivos - $numBajos,
                    'total' => $ingredientesActivos,
                    'pendientesTexto' => 'por surtir',
                    'completoTexto' => 'Todo surtido',
                    'href' => $urlPendiente($pendiente === 'stock' ? '' : 'stock'),
                    'activa' => $pendiente === 'stock',
                ],
                [
                    'tipo' => 'tarea',
                    'rotulo' => 'Con proveedor',
                    'hechos' => $ingredientesActivos - $numSinProveedor,
                    'total' => $ingredientesActivos,
                    'pendientesTexto' => 'sin proveedor',
                    'completoTexto' => 'Todos con proveedor',
                    'href' => $urlPendiente($pendiente === 'proveedor' ? '' : 'proveedor'),
                    'activa' => $pendiente === 'proveedor',
                ],
            ];
            include __DIR__ . '/../partials/_filtros-estado.php';
        ?>
    </div>

    <?php if ($pendiente === 'stock') : ?>
        <?php /* Lista de pendientes de stock: lo más urgente arriba (menor parte
                 del mínimo cubierta). La acción es la de siempre —SUMAR lo que
                 llegó, no fijar un número—, en la misma fila. Al pasar del
                 mínimo, el ingrediente sale de la lista. */ ?>
        <section class="admin-panel admin-card admin-inventario__lista" aria-labelledby="pendientes-titulo">
            <div class="admin-panel-head admin-tareas-cabeza">
                <div>
                    <span class="admin-eyebrow">Pendientes</span>
                    <h3 id="pendientes-titulo">Por surtir</h3>
                    <p>En su mínimo o por debajo. Registra lo que llegó: se suma a las existencias, y en cuanto el ingrediente pasa de su mínimo sale de esta lista.</p>
                </div>
                <a class="admin-btn admin-btn--secondary admin-btn--small admin-tareas-cabeza__volver" href="<?php echo $esc($urlPendiente('')); ?>">
                    <?php echo admin_icon('flecha-izquierda', 16, 'admin-btn__icon'); ?>
                    Todas las existencias
                </a>
            </div>

            <?php if ($ingredientesActivos === 0) : ?>
                <p class="admin-empty">No hay ingredientes activos. Crea el primero para empezar a controlar el inventario.</p>
            <?php elseif ($numBajos === 0) : ?>
                <?php
                    $tcTitulo = 'Todo está sobre su mínimo';
                    $tcTexto = number_format($ingredientesActivos) . ' de ' . number_format($ingredientesActivos) . ' ingredientes con existencias de sobra. Cuando uno baje de su mínimo aparecerá aquí.';
                    $tcAcciones = [['href' => $urlPendiente(''), 'texto' => 'Volver a existencias']];
                    include __DIR__ . '/../partials/_tareas-completas.php';
                ?>
            <?php else : ?>
                <p class="admin-tareas-cuenta"><span class="admin-num"><?php echo number_format($numBajos); ?></span> <?php echo $numBajos === 1 ? 'ingrediente por surtir' : 'ingredientes por surtir'; ?></p>
                <ul class="admin-tareas admin-tareas--stock" aria-labelledby="pendientes-titulo">
                    <?php foreach ($ingredientesBajos as $ing) : ?>
                        <?php
                            $rStock = (float) $ing->stock;
                            $rMin = (float) $ing->stock_minimo;
                            $rFaltan = max(0.0, $rMin - $rStock);
                            $rPct = $rMin > 0 ? max(0.0, min(100.0, ($rStock / $rMin) * 100)) : ($rStock > 0 ? 100.0 : 0.0);
                            $rAgotado = $rStock <= 0;
                            $rUnidad = (string) $ing->unidad;
                            $rCampo = 'entrada-' . (int) $ing->id;
                        ?>
                        <li class="admin-tarea">
                            <span class="admin-tarea__marca" aria-hidden="true"></span>
                            <span class="admin-tarea__texto">
                                <span class="admin-tarea__nombre"><?php echo $esc($ing->nombre); ?></span>
                                <span class="admin-tarea__detalle">
                                    <?php if ($rAgotado) : ?>
                                        <span class="admin-tarea__aviso admin-tarea__aviso--grave">Agotado</span>
                                        <?php if ($rFaltan > 0) : ?> · faltan <?php echo $cant($rFaltan); ?> <?php echo $esc($rUnidad); ?> para el mínimo<?php endif; ?>
                                    <?php elseif ($rFaltan > 0) : ?>
                                        <span class="admin-tarea__aviso">Faltan <?php echo $cant($rFaltan); ?> <?php echo $esc($rUnidad); ?></span> para el mínimo
                                    <?php else : ?>
                                        <span class="admin-tarea__aviso">Justo en el mínimo</span>
                                    <?php endif; ?>
                                </span>
                            </span>
                            <?php /* Hoy contra el mínimo, en cifra y en barra: la
                                     barra dice de un vistazo qué fila urge más. */ ?>
                            <span class="admin-tarea__dato admin-inventario__nivel">
                                <span class="admin-num"><?php echo $cant($rStock); ?> / <?php echo $cant($rMin); ?> <?php echo $esc($rUnidad); ?></span>
                                <span class="admin-inventario__nivel-barra" aria-hidden="true">
                                    <span class="<?php echo $rAgotado || $rPct < 50 ? 'is-critico' : ''; ?>" style="--avance: <?php echo round($rPct, 1); ?>%"></span>
                                </span>
                            </span>
                            <form class="admin-tarea__accion admin-inventario__entrada" method="POST"
                                  action="/admin/inventario/entrada<?php echo $esc($sufijo); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                <label class="admin-visually-hidden" for="<?php echo $rCampo; ?>">Cantidad recibida de <?php echo $esc($ing->nombre); ?>, en <?php echo $esc($rUnidad); ?></label>
                                <span class="admin-inventario__campo">
                                    <input type="number" id="<?php echo $rCampo; ?>" name="cantidad" step="0.001" min="0.001" required
                                           inputmode="decimal" placeholder="Recibido">
                                    <span class="admin-inventario__unidad" aria-hidden="true"><?php echo $esc($rUnidad); ?></span>
                                </span>
                                <button type="submit" class="admin-btn admin-btn--secondary admin-inventario__sumar">
                                    <?php echo admin_icon('mas', 16, 'admin-btn__icon'); ?>
                                    Sumar
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php elseif ($pendiente === 'proveedor') : ?>
        <?php /* Lista de pendientes de proveedor. La asignación vive en la ficha
                 del ingrediente (tabla de proveedores con costo y preferente), así
                 que la acción lleva allí, al bloque de proveedores, con una fila
                 ya abierta; al guardar se vuelve aquí con el filtro puesto. */ ?>
        <section class="admin-panel admin-card admin-inventario__lista" aria-labelledby="pendientes-titulo">
            <div class="admin-panel-head admin-tareas-cabeza">
                <div>
                    <span class="admin-eyebrow">Pendientes</span>
                    <h3 id="pendientes-titulo">Sin proveedor</h3>
                    <p>Sin proveedor no hay a quién pedirlo ni precio con el que comparar. Asígnale al menos uno; el preferente es el que se propone al recibir mercancía.</p>
                    <?php if (!$hayProveedores) : ?>
                        <p class="admin-inventario__aviso">Todavía no hay proveedores dados de alta: <a href="/admin/inventario/proveedores/create">crea el primero</a> y vuelve a esta lista.</p>
                    <?php endif; ?>
                </div>
                <a class="admin-btn admin-btn--secondary admin-btn--small admin-tareas-cabeza__volver" href="<?php echo $esc($urlPendiente('')); ?>">
                    <?php echo admin_icon('flecha-izquierda', 16, 'admin-btn__icon'); ?>
                    Todas las existencias
                </a>
            </div>

            <?php if ($ingredientesActivos === 0) : ?>
                <p class="admin-empty">No hay ingredientes activos. Crea el primero para empezar a controlar el inventario.</p>
            <?php elseif ($numSinProveedor === 0) : ?>
                <?php
                    $tcTitulo = 'Todos los ingredientes tienen proveedor';
                    $tcTexto = number_format($ingredientesActivos) . ' de ' . number_format($ingredientesActivos) . '. Cada uno tiene a quién pedirse y un precio con el que comparar.';
                    $tcAcciones = [['href' => $urlPendiente(''), 'texto' => 'Volver a existencias']];
                    include __DIR__ . '/../partials/_tareas-completas.php';
                ?>
            <?php else : ?>
                <p class="admin-tareas-cuenta"><span class="admin-num"><?php echo number_format($numSinProveedor); ?></span> <?php echo $numSinProveedor === 1 ? 'ingrediente sin proveedor' : 'ingredientes sin proveedor'; ?></p>
                <ul class="admin-tareas" aria-labelledby="pendientes-titulo">
                    <?php foreach ($ingredientesSinProveedor as $ing) : ?>
                        <?php
                            $editarUrl = '/admin/inventario/edit?'
                                . http_build_query(['id' => (int) $ing->id] + $consultaActual)
                                . '#proveedores';
                        ?>
                        <li class="admin-tarea">
                            <span class="admin-tarea__marca" aria-hidden="true"></span>
                            <span class="admin-tarea__texto">
                                <span class="admin-tarea__nombre"><?php echo $esc($ing->nombre); ?></span>
                                <span class="admin-tarea__detalle"><?php echo $cant($ing->stock); ?> <?php echo $esc($ing->unidad); ?> en existencia</span>
                            </span>
                            <span class="admin-tarea__dato">
                                <span class="admin-num">$<?php echo number_format((float) $ing->costo, 4); ?></span> / <?php echo $esc($ing->unidad); ?>
                            </span>
                            <span class="admin-tarea__accion">
                                <a class="admin-btn admin-btn--secondary admin-btn--small admin-tarea__enlace"
                                   href="<?php echo $esc($editarUrl); ?>"
                                   aria-label="Asignar proveedor a <?php echo $esc($ing->nombre); ?>">
                                    Asignar proveedor
                                    <?php echo admin_icon('flecha-derecha', 15, 'admin-btn__icon'); ?>
                                </a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php else : ?>
        <section class="admin-panel admin-card admin-inventario__lista">
            <div class="admin-panel-head">
                <div>
                    <h3>Existencias</h3>
                    <p><?php echo number_format($totalIngredientes); ?> ingredientes registrados. Un punto delante de la cifra marca lo que está en su mínimo o por debajo.</p>
                </div>
            </div>

            <?php if (empty($ingredientes)) : ?>
                <p class="admin-empty">No hay ingredientes registrados. Crea el primero para comenzar a controlar el inventario.</p>
            <?php else : ?>
                <?php /* Ordenable en el cliente (table-sort.js): la lista no va
                         paginada, así que ordenar aquí sí ordena el inventario
                         entero. Las cifras van en mono y a la derecha para
                         compararse en vertical, con su unidad pegada: la columna
                         «Unidad» suelta obligaba a cruzar la fila para saber si
                         2100 eran gramos o mililitros. */ ?>
                <div class="admin-table-wrap" data-scrollable>
                    <table class="admin-table admin-inventario__tabla" data-sortable>
                        <thead>
                            <tr>
                                <th data-sort-type="text">Ingrediente</th>
                                <th data-sort-type="number" class="admin-inventario__col-num">Existencias</th>
                                <th data-sort-type="number" class="admin-inventario__col-num">Mínimo</th>
                                <th data-sort-type="number" class="admin-inventario__col-num">Costo / u</th>
                                <th data-sort-disabled>Ajuste rápido</th>
                                <th data-sort-disabled>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ingredientes as $ing) : ?>
                                <?php
                                    $stock = (float) $ing->stock;
                                    $min = (float) $ing->stock_minimo;
                                    $estadoStock = $stock <= 0 ? 'agotado' : ($stock <= $min ? 'bajo' : '');
                                    $unidad = (string) $ing->unidad;
                                ?>
                                <tr data-row-href="/admin/inventario/edit?id=<?php echo (int) $ing->id; ?>"<?php echo !$ing->activo ? ' class="is-inactivo"' : ''; ?>>
                                    <td data-sort-value="<?php echo $esc($ing->nombre); ?>">
                                        <span class="admin-table__cell-main"><?php echo $esc($ing->nombre); ?></span>
                                        <?php if (!$ing->activo) : ?>
                                            <span class="admin-table__cell-sub">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php /* Sólo lo que falta lleva marca: con un semáforo en las
                                            setenta filas, verde incluido, el bajo stock dejaba de
                                            destacar. El punto es de forma además de color, y el
                                            texto oculto lo dice en voz. */ ?>
                                    <td class="admin-num admin-inventario__col-num" data-sort-value="<?php echo $stock; ?>">
                                        <span class="admin-inventario__stock<?php echo $estadoStock !== '' ? ' is-' . $estadoStock : ''; ?>">
                                            <?php echo $cant($stock); ?> <span class="admin-inventario__u"><?php echo $esc($unidad); ?></span>
                                            <?php if ($estadoStock === 'agotado') : ?>
                                                <span class="admin-visually-hidden">, agotado</span>
                                            <?php elseif ($estadoStock === 'bajo') : ?>
                                                <span class="admin-visually-hidden">, en su mínimo o por debajo</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="admin-num admin-inventario__col-num admin-inventario__tenue" data-sort-value="<?php echo $min; ?>">
                                        <?php echo $cant($min); ?> <span class="admin-inventario__u"><?php echo $esc($unidad); ?></span>
                                    </td>
                                    <td class="admin-num admin-inventario__col-num admin-inventario__tenue" data-sort-value="<?php echo (float) $ing->costo; ?>">
                                        $<?php echo number_format((float) $ing->costo, 4); ?>
                                    </td>
                                    <td>
                                        <form class="admin-inline-form" method="POST" action="/admin/inventario/ajustar<?php echo $esc($sufijo); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                            <input type="number" name="stock" step="0.001" value="<?php echo $esc($fmt($stock)); ?>" aria-label="Nuevo stock de <?php echo $esc($ing->nombre); ?>, en <?php echo $esc($unidad); ?>">
                                            <?php /* Secundario y no dorado: fijar el stock es rutina de
                                                    conteo, no la acción principal de la pantalla. */ ?>
                                            <button type="submit" class="admin-btn admin-btn--secondary admin-inventario__save" title="Fijar stock">
                                                <?php echo admin_icon('check', 16, 'admin-btn__icon'); ?>
                                                Guardar
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php /* Dos acciones, separadas por un filete: editar y,
                                                aparte, borrar. «Registrar merma» salió de la fila:
                                                el panel de merma tiene el formulario completo. */ ?>
                                        <div class="admin-table-actions">
                                            <a class="admin-icon-button admin-icon-button--edit" href="/admin/inventario/edit?id=<?php echo (int) $ing->id; ?>" title="Editar" aria-label="Editar <?php echo $esc($ing->nombre); ?>">
                                                <?php echo admin_icon('editar', 18); ?>
                                            </a>
                                            <?php /* data-ingrediente-delete lo intercepta inventario.js para
                                                    pedir al servidor en qué recetas entra el ingrediente y
                                                    exigir que se escriba el nombre. Sin JS, el formulario
                                                    envía igual: los data-confirm-* son el respaldo. */ ?>
                                            <form method="POST" action="/admin/inventario/delete<?php echo $esc($sufijo); ?>"
                                                  class="admin-inventario__accion-destructiva"
                                                  data-confirm-delete
                                                  data-ingrediente-delete
                                                  data-ingrediente-id="<?php echo (int) $ing->id; ?>"
                                                  data-ingrediente-nombre="<?php echo $esc($ing->nombre); ?>"
                                                  data-confirm-eyebrow="Eliminar ingrediente"
                                                  data-confirm-title="¿Eliminar «<?php echo $esc($ing->nombre); ?>»?"
                                                  data-confirm-require="<?php echo $esc($ing->nombre); ?>"
                                                  data-confirm-description="Se borrará del inventario y de las recetas que lo usan."
                                                  data-confirm-consequence="También se pierde su bitácora de movimientos. Esta acción no se puede deshacer.">
                                                <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                                <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo $esc($ing->nombre); ?>">
                                                    <?php echo admin_icon('basura', 18); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php /*
      Panel de merma: alta y bitácora en el mismo sitio. Va DESPUÉS de las
      existencias: las tarjetas-filtro tienen que quedar pegadas a la lista que
      filtran, y la barra de arriba sigue llevando aquí con «Registrar merma».
    */ ?>
    <section class="admin-panel admin-card admin-merma" id="inventario-merma" aria-labelledby="merma-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="merma-titulo">Merma</h3>
                <p>Producto que se tiró. Se descuenta del stock y cuenta como costo del periodo.</p>
            </div>
            <span class="admin-badge admin-badge--<?php echo $consumo['mermaValor'] > 0 ? 'danger' : 'neutral'; ?>">
                <?php echo $money($consumo['mermaValor']); ?> en el periodo
            </span>
        </div>

        <form class="admin-merma__form" method="POST" action="/admin/inventario/merma<?php echo $esc($sufijo); ?>">
            <div class="admin-merma__field admin-merma__field--wide">
                <label for="merma-ingrediente">Ingrediente</label>
                <select id="merma-ingrediente" name="id" required>
                    <option value="">Elige un ingrediente</option>
                    <?php foreach ($ingredientes as $ing) : ?>
                        <option value="<?php echo (int) $ing->id; ?>">
                            <?php echo $esc($ing->nombre); ?> — <?php echo $cant($ing->stock); ?> <?php echo $esc($ing->unidad); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-merma__field">
                <label for="merma-cantidad">Cantidad</label>
                <input type="number" id="merma-cantidad" name="cantidad" step="0.001" min="0.001" placeholder="0" required>
            </div>
            <div class="admin-merma__field">
                <label for="merma-motivo">Motivo</label>
                <select id="merma-motivo" name="motivo" required>
                    <?php foreach ($motivosMerma as $clave => $etiqueta) : ?>
                        <option value="<?php echo $esc($clave); ?>"><?php echo $esc($etiqueta); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-merma__field admin-merma__field--wide">
                <label for="merma-nota">Detalle <small>(opcional)</small></label>
                <input type="text" id="merma-nota" name="nota" maxlength="255" autocomplete="off"
                       placeholder="Ej. se rompió la cadena de frío el sábado">
            </div>
            <div class="admin-merma__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Registrar merma</button>
            </div>
        </form>

        <?php if (!empty($mermaPorMotivo)) : ?>
            <ul class="admin-merma__motivos">
                <?php foreach ($mermaPorMotivo as $clave => $valorMotivo) : ?>
                    <li>
                        <span class="admin-merma__motivo-label"><?php echo $esc($motivosMerma[$clave] ?? $clave); ?></span>
                        <span class="admin-merma__motivo-val"><?php echo $money($valorMotivo); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (empty($mermas)) : ?>
            <div class="admin-empty admin-merma__empty">
                <strong>Sin mermas registradas en el periodo</strong>
                <span>
                    Anota aquí lo que se echa a perder, se derrama o no llega a venderse.
                    Sin ese registro, el costo aparece como si el producto siguiera en el almacén.
                </span>
            </div>
        <?php else : ?>
            <?php /* data-scrollable, no data-lenis-prevent: este wrap sólo
                     desplaza en horizontal, así que reteniendo la rueda no
                     movía nada y la página se plantaba encima de la tabla.
                     Con la marca de inventario, motion.js decide en cada rueda
                     si de verdad desborda en vertical. */ ?>
            <div class="admin-table-wrap" data-scrollable>
                <table class="admin-table admin-merma__table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th>Cantidad</th>
                            <th>Motivo</th>
                            <th>Costo</th>
                            <th>Registró</th>
                            <th>Cuándo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mermas as $m) : ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo $esc($m['ingrediente']); ?></span>
                                    <?php if ($m['nota'] !== '') : ?>
                                        <span class="admin-table__cell-sub"><?php echo $esc($m['nota']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-table__cell-sub"><?php echo $cant($m['cantidad']); ?> <?php echo $esc($m['unidad']); ?></span></td>
                                <td><span class="admin-badge admin-badge--neutral"><?php echo $esc($motivosMerma[$m['motivo']] ?? $m['motivo']); ?></span></td>
                                <td><strong class="admin-merma__costo"><?php echo $money($m['valor']); ?></strong></td>
                                <td><span class="admin-table__cell-sub"><?php echo $m['usuario'] !== '' ? $esc($m['usuario']) : '—'; ?></span></td>
                                <td><span class="admin-table__cell-sub"><?php echo $esc(date('d/m/Y H:i', strtotime($m['fecha']))); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>


<?php /*
  El modal «Registrar merma» se retiró: lo abría únicamente el botón de cada
  fila de Existencias, y ese botón salió de la tabla. El formulario del panel
  de merma hace lo mismo y además deja elegir el ingrediente, así que el
  diálogo era una segunda copia del mismo alta.
*/ ?>
