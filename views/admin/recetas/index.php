<?php
    /**
     * Listado de platillos con su receta.
     *
     * Paginado y con buscador reactivo, igual que Menú: el bloque de
     * resultados se intercambia con src/js/admin/core/reactive-filters.js y
     * sin JS el formulario navega como un GET normal. Por eso el archivo se
     * parte en dos con $partialOnly — lo que está fuera del envoltorio
     * (cabecera, tarjetas-filtro y buscador) no se reemplaza en cada búsqueda.
     *
     * Dos modos del mismo listado, con las tarjetas-filtro de Menú
     * (partials/_filtros-estado.php):
     * - todos: la tabla del catálogo con el estado de cada receta, ordenable.
     * - ?estado=sin: la lista de pendientes, una fila por platillo sin receta
     *   con su acción directa, y el acuse cuando ya no queda ninguno.
     * `?estado=con` sigue valiendo por URL (la tabla, sólo con receta), aunque
     * ya no tiene control propio: lo que se busca aquí es lo que falta.
     */
    require_once __DIR__ . '/../partials/_icons.php';

    $productos = isset($productos) && is_iterable($productos) ? $productos : [];
    $conteosReceta = is_array($conteosReceta ?? null) ? $conteosReceta : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
    $totalProductos = (int) ($totalProductos ?? 0);
    $conReceta = (int) ($conReceta ?? 0);
    $ocultos = (int) ($ocultos ?? 0);
    $filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'estado' => 'todas'];
    $filtrosActivos = (bool) ($filtrosActivos ?? false);
    $partialOnly = (bool) ($partialOnly ?? false);
    $totalFiltrado = (int) ($totalFiltrado ?? count($productos));
    $paginaActual = (int) ($paginaActual ?? 1);
    $porPagina = (int) ($porPagina ?? max(1, $totalFiltrado));
    $totalPaginas = (int) ($totalPaginas ?? 1);
    $desde = $totalFiltrado === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalFiltrado);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $estadoActual = (string) ($filtros['estado'] ?? 'todas');
    $enPendientes = $estadoActual === 'sin';
    $busqueda = (string) ($filtros['q'] ?? '');
    $sinReceta = max(0, $totalProductos - $conReceta);

    $buildRecetasUrl = static function (int $page) use ($filtros): string {
        $params = [];

        foreach ($filtros as $clave => $valor) {
            if ((string) $valor !== '') {
                $params[$clave] = $valor;
            }
        }

        $params['page'] = $page;

        return '/admin/recetas?' . http_build_query($params);
    };

    // Lo que se está viendo, para que el editor de la receta devuelva aquí
    // (?volver=): misma búsqueda, mismo estado, misma página.
    $consultaLista = array_filter($filtros, static fn ($v): bool => (string) $v !== '' && $v !== 'todas');
    if ($paginaActual > 1) {
        $consultaLista['page'] = $paginaActual;
    }
    $volverAqui = '/admin/recetas' . (empty($consultaLista) ? '' : '?' . http_build_query($consultaLista));
    $editarUrl = static function (int $id) use ($volverAqui): string {
        return '/admin/recetas/editar?' . http_build_query(['id' => $id, 'volver' => $volverAqui]);
    };
?>

<?php if (!$partialOnly) : ?>
<section class="admin-recetas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación</span>
            <h2 class="admin-page__title">Recetas</h2>
            <p class="admin-page__subtitle">Define qué ingredientes y subrecetas consume cada platillo. Al venderse, su receta descuenta el inventario automáticamente. Los datos del platillo (nombre, precio, categoría) se editan en Menú.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-azul" href="/admin/recetas/subrecetas">Subrecetas</a>
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-verde" href="/admin/inventario">Inventario</a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/menu/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nuevo platillo</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php /* Cabecera del listado: dos tarjetas-filtro y el buscador en una fila.
             Eran dos cajas anchas con un número cada una —«Platillos 107» y
             «Sin receta 77» en ámbar— y unas pastillas de estado aparte que
             decían lo mismo; ahora la tarjeta dice el avance («30 de 107 con
             receta», con su barra) y ES el filtro: al pulsarla, la tabla se
             vuelve la lista de pendientes. Las cifras son del CATÁLOGO, no de la
             página ni de la búsqueda: salen de su propia consulta en el
             controlador. Navegan (no son intercambio parcial) porque cambian el
             campo oculto del buscador, que vive fuera del bloque reactivo. */ ?>
    <div class="admin-filtros-fila">
        <?php
            $feEtiqueta = 'Estado de las recetas';
            $feTarjetas = [
                [
                    'tipo' => 'todos',
                    'rotulo' => 'Platillos',
                    'cifra' => number_format($totalProductos),
                    'nota' => $ocultos > 0
                        ? number_format($totalProductos - $ocultos) . ' en la carta · ' . number_format($ocultos) . ($ocultos === 1 ? ' oculto' : ' ocultos')
                        : 'Todos en la carta',
                    'accion' => 'Ver todos',
                    'href' => '/admin/recetas',
                    'activa' => !$enPendientes,
                ],
                [
                    'tipo' => 'tarea',
                    'rotulo' => 'Con receta',
                    'hechos' => $conReceta,
                    'total' => $totalProductos,
                    'pendientesTexto' => 'sin receta',
                    'completoTexto' => 'Todos con receta',
                    'href' => $enPendientes ? '/admin/recetas' : '/admin/recetas?estado=sin',
                    'activa' => $enPendientes,
                ],
            ];
            include __DIR__ . '/../partials/_filtros-estado.php';
        ?>

        <form
            class="admin-filters admin-filtro-buscar"
            method="GET"
            action="/admin/recetas"
            aria-label="Buscar recetas"
            data-reactive-filters
            data-reactive-target="#recetas-results"
            data-reactive-loading="#recetas-results-loading"
            data-reactive-error="#recetas-results-error"
            data-reactive-debounce="350"
        >
            <?php /*
              El buscador mira DENTRO de la receta, no sólo en el nombre: escribir
              «cilantro» devuelve los platillos que lo consumen aunque ninguno lo
              lleve en el título. Es la pregunta que sólo este módulo puede
              responder, y por eso la nota lo dice en vez de dejarlo a que alguien
              lo descubra. Los términos se cruzan con AND, así que seguir
              escribiendo afina.
            */ ?>
            <div class="admin-filters__search">
                <label for="recetas-q"><?php echo $enPendientes ? 'Buscar entre los pendientes' : 'Buscar platillo o ingrediente'; ?></label>
                <p class="admin-filtro-buscar__nota" id="recetas-q-hint">Palabras sueltas, en cualquier orden. También busca dentro de los ingredientes y subrecetas.</p>
                <input
                    id="recetas-q"
                    type="search"
                    name="q"
                    data-reactive-control
                    data-reactive-default=""
                    value="<?php echo $esc($busqueda); ?>"
                    placeholder="pollo ensalada, cilantro…"
                    aria-describedby="recetas-q-hint"
                >
                <?php /* El estado viaja oculto: buscar dentro de los pendientes no
                         debe devolver el catálogo entero. Lo fijan las tarjetas
                         (que navegan) y el intercambio parcial lo vuelve a leer
                         de la URL canónica. */ ?>
                <input type="hidden" name="estado" data-reactive-control data-reactive-default="todas"
                       value="<?php echo $esc($estadoActual); ?>">
            </div>

            <div class="admin-filters__actions">
                <button type="submit" class="admin-btn admin-btn--primary" data-reactive-submit>Buscar</button>
            </div>
        </form>
    </div>

    <div class="admin-reactive-results-shell">
        <div id="recetas-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
<?php if ($enPendientes) : ?>
    <?php /* ── Lista de pendientes ───────────────────────────────────────
             Una fila por platillo sin receta, con UNA acción: crearla. Al
             guardar, el editor vuelve aquí (?volver=) y el platillo ya no está;
             la tarjeta de arriba cuenta uno más. */ ?>
    <section class="admin-panel admin-card admin-recetas__lista" aria-labelledby="pendientes-titulo">
        <div class="admin-panel-head admin-tareas-cabeza">
            <div>
                <span class="admin-eyebrow">Pendientes</span>
                <h3 id="pendientes-titulo">Platillos sin receta</h3>
                <p>Sin receta, vender un platillo no descuenta inventario y su costo sale en cero. Al guardar la receta, sale de esta lista.</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-btn--small admin-tareas-cabeza__volver" href="/admin/recetas">
                <?php echo admin_icon('flecha-izquierda', 16, 'admin-btn__icon'); ?>
                Todos los platillos
            </a>
        </div>

        <?php if (empty($productos)) : ?>
            <?php if ($busqueda !== '' && $sinReceta > 0) : ?>
                <?php /* La búsqueda vació la lista, no el trabajo: nada que celebrar. */ ?>
                <p class="admin-empty">Ningún platillo sin receta coincide con la búsqueda. Quedan <?php echo number_format($sinReceta); ?> pendientes; borra la búsqueda para verlos.</p>
            <?php elseif ($totalProductos === 0) : ?>
                <p class="admin-empty">No hay platillos registrados. Crea el primero en Menú y vuelve aquí a definir su receta.</p>
            <?php else : ?>
                <?php
                    $tcTitulo = 'Todos los platillos tienen receta';
                    $tcTexto = number_format($totalProductos) . ' de ' . number_format($totalProductos) . '. Cada venta descuenta su inventario y el costo de cada platillo es real.';
                    $tcAcciones = [['href' => '/admin/recetas', 'texto' => 'Volver a la lista']];
                    include __DIR__ . '/../partials/_tareas-completas.php';
                ?>
            <?php endif; ?>
        <?php else : ?>
            <p class="admin-tareas-cuenta">
                <?php if ($totalPaginas > 1 || $busqueda !== '') : ?>
                    <span class="admin-num"><?php echo $desde; ?>–<?php echo $hasta; ?></span> de <span class="admin-num"><?php echo number_format($totalFiltrado); ?></span> pendientes
                <?php else : ?>
                    <span class="admin-num"><?php echo number_format($totalFiltrado); ?></span> <?php echo $totalFiltrado === 1 ? 'pendiente' : 'pendientes'; ?>
                <?php endif; ?>
            </p>
            <ul class="admin-tareas" aria-labelledby="pendientes-titulo">
                <?php foreach ($productos as $prod) : ?>
                    <?php $catNombre = $categoriasMap[(int) $prod->categoria_id] ?? 'Sin categoría'; ?>
                    <li class="admin-tarea">
                        <span class="admin-tarea__marca" aria-hidden="true"></span>
                        <span class="admin-tarea__texto">
                            <span class="admin-tarea__nombre"><?php echo $esc($prod->nombre); ?></span>
                            <span class="admin-tarea__detalle">
                                <?php echo $esc($catNombre); ?><?php if (!$prod->activo) : ?><span aria-hidden="true"> · </span><span class="admin-visually-hidden">, </span>Oculto en la carta<?php endif; ?>
                            </span>
                        </span>
                        <span class="admin-tarea__dato admin-num">$<?php echo number_format((float) $prod->precio, 2); ?></span>
                        <span class="admin-tarea__accion">
                            <?php /* Se estira a toda la fila (_pendientes.scss). */ ?>
                            <a class="admin-btn admin-btn--secondary admin-btn--small admin-tarea__enlace"
                               href="<?php echo $esc($editarUrl((int) $prod->id)); ?>"
                               aria-label="Crear receta de <?php echo $esc($prod->nombre); ?>">
                                Crear receta
                                <?php echo admin_icon('flecha-derecha', 15, 'admin-btn__icon'); ?>
                            </a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php
            $pagPagina = $paginaActual;
            $pagTotal = $totalPaginas;
            $pagUrl = $buildRecetasUrl;
            $pagEtiqueta = 'Paginación de pendientes';
            $pagReactiva = true;
            include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
    </section>
<?php else : ?>
    <section class="admin-panel admin-card admin-recetas__lista">
        <div class="admin-panel-head">
            <div>
                <h3>Platillos del menú</h3>
                <?php /* Sin repetir las cifras de las tarjetas: aquí sólo lo que
                         ellas no dicen, la ventana que se está viendo. */ ?>
                <p>
                    <?php if ($totalFiltrado > 0) : ?>
                        <span class="admin-num"><?php echo $desde; ?>–<?php echo $hasta; ?></span> de <span class="admin-num"><?php echo number_format($totalFiltrado); ?></span><?php echo $busqueda !== '' ? ' resultados.' : ' platillos.'; ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if (empty($productos)) : ?>
            <p class="admin-empty">
                <?php if ($filtrosActivos) : ?>
                    Ningún platillo coincide con la búsqueda. Prueba con menos palabras: el filtro exige que todas aparezcan.
                <?php else : ?>
                    No hay platillos registrados. Crea el primero en Menú y vuelve aquí a definir su receta.
                <?php endif; ?>
            </p>
        <?php else : ?>
            <?php /* El orden de las cabeceras es de CLIENTE y reordena la página
                     visible, no el catálogo entero: el listado viene paginado de
                     diez en diez y el orden de fondo lo fija el SQL (activo,
                     categoría, nombre).

                     Cuatro columnas en el orden en que se lee una fila: QUÉ
                     platillo (con su categoría debajo, que es contexto y no un
                     dato que se compare), CUÁNTO cuesta, CÓMO está su receta y lo
                     que se HACE. La columna «Estado» con un «Activo» en cada fila
                     salió: lo único que importa de ella es la excepción, y
                     «Oculto en la carta» va ahora bajo el nombre. */ ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-recetas__tabla" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Platillo</th>
                            <th data-sort-type="number" class="admin-recetas__col-num">Precio</th>
                            <th data-sort-type="number">Receta</th>
                            <th data-sort-disabled>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $prod) : ?>
                            <?php
                                $comp = $conteosReceta[(int) $prod->id] ?? ['ingredientes' => 0, 'subrecetas' => 0];
                                $nIng = (int) $comp['ingredientes'];
                                $nSub = (int) $comp['subrecetas'];
                                $nTotal = $nIng + $nSub;
                                $catNombre = $categoriasMap[(int) $prod->categoria_id] ?? '—';
                                $url = $editarUrl((int) $prod->id);
                                $partesReceta = [];
                                if ($nIng > 0) {
                                    $partesReceta[] = $nIng . ($nIng === 1 ? ' ingrediente' : ' ingredientes');
                                }
                                if ($nSub > 0) {
                                    $partesReceta[] = $nSub . ($nSub === 1 ? ' subreceta' : ' subrecetas');
                                }
                            ?>
                            <tr data-row-href="<?php echo $esc($url); ?>">
                                <td data-sort-value="<?php echo $esc($prod->nombre); ?>">
                                    <span class="admin-recetas__nombre"><?php echo $esc($prod->nombre); ?></span>
                                    <span class="admin-table__cell-sub">
                                        <?php echo $esc($catNombre); ?><?php if (!$prod->activo) : ?><span aria-hidden="true"> · </span><span class="admin-visually-hidden">, </span><span class="admin-recetas__oculto">Oculto en la carta</span><?php endif; ?>
                                    </span>
                                </td>
                                <td class="admin-num admin-recetas__col-num" data-sort-value="<?php echo (float) $prod->precio; ?>">
                                    $<?php echo number_format((float) $prod->precio, 2); ?>
                                </td>
                                <?php /* Ordena por número de componentes: «Sin receta»
                                        cae entero a un extremo en vez de alfabetizarse.
                                        La marca es la misma de la lista de pendientes
                                        —círculo discontinuo— para que se lea como lo
                                        mismo en los dos modos; con receta, un visto. */ ?>
                                <td data-sort-value="<?php echo $nTotal; ?>">
                                    <?php if ($nTotal > 0) : ?>
                                        <span class="admin-recetas__receta">
                                            <?php echo admin_icon('check', 14, 'admin-recetas__receta-icono'); ?>
                                            <?php echo $esc(implode(' · ', $partesReceta)); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="admin-recetas__receta is-falta">
                                            <span class="admin-recetas__receta-marca" aria-hidden="true"></span>
                                            Sin receta
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="<?php echo $esc($url); ?>"
                                           title="<?php echo $nTotal > 0 ? 'Editar receta' : 'Crear receta'; ?>"
                                           aria-label="<?php echo $nTotal > 0 ? 'Editar receta de ' : 'Crear receta de '; ?><?php echo $esc($prod->nombre); ?>">
                                            <?php echo admin_icon('editar', 18); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $pagPagina = $paginaActual;
            $pagTotal = $totalPaginas;
            $pagUrl = $buildRecetasUrl;
            $pagEtiqueta = 'Paginación de recetas';
            $pagReactiva = true;
            include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="recetas-results-loading" role="status" hidden>Actualizando resultados</div>
        <div class="admin-reactive-error" id="recetas-results-error" role="alert" hidden>No se pudieron cargar los resultados.</div>
    </div>
</section>
<?php endif; ?>
