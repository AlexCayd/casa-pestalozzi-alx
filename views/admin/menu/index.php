<?php
    require_once __DIR__ . '/../partials/_icons.php';
    /**
     * Listado de platillos con pestañas de categoría.
     *
     * Sustituye al antiguo hub (que solo enlazaba a Platillos y Categorías) y a
     * views/admin/menu/items.php. Los datos salen de `productos`, la tabla
     * fusionada. La búsqueda y la paginación intercambian sólo el bloque de
     * resultados (src/js/admin/core/reactive-filters.js); carta, categoría y
     * las tarjetas-filtro navegan, porque cambian cosas que viven FUERA de ese
     * bloque (la fila de pestañas, el campo oculto del filtro, las cifras).
     *
     * Dos modos del mismo listado:
     * - todos: la tabla del catálogo, ordenable.
     * - ?pendiente=receta: la lista de pendientes, una fila por platillo sin
     *   receta con su acción directa, y un acuse cuando ya no queda ninguno.
     */
    $platillos = isset($platillos) && is_iterable($platillos) ? $platillos : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $categoriasMap = $categoriasMap ?? [];
    $categoriasFoto = is_array($categoriasFoto ?? null) ? $categoriasFoto : [];
    $cartas = is_array($cartas ?? null) && $cartas ? $cartas : \Model\CategoriasMenu::CARTAS;
    $areasMap = is_array($areasMap ?? null) ? $areasMap : [];
    $filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'carta' => '', 'categoria' => '', 'pendiente' => ''];
    $categoriaActiva = (int) ($categoriaActiva ?? 0);
    $cartaActiva = (string) ($cartaActiva ?? '');
    $pendienteActivo = (string) ($pendienteActivo ?? '');
    $resumen = is_array($resumen ?? null) ? $resumen : [];
    $resumen += ['total' => 0, 'sinReceta' => 0, 'ocultos' => 0, 'sinRecetaMenu' => 0];
    $filtrosActivos = (bool) ($filtrosActivos ?? false);
    $partialOnly = (bool) ($partialOnly ?? false);
    $totalMenu = (int) ($totalMenu ?? count($platillos));
    $paginaActual = (int) ($paginaActual ?? 1);
    $porPagina = (int) ($porPagina ?? max(1, $totalMenu));
    $totalPaginas = (int) ($totalPaginas ?? 1);
    $desde = $totalMenu === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalMenu);
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $dinero = static fn ($v): string => '$' . number_format((float) $v, 2);

    $buildItemsUrl = static function (int $page) use ($filtros): string {
        $params = [];

        foreach ($filtros as $key => $value) {
            if ((string) $value !== '') {
                $params[$key] = $value;
            }
        }

        $params['page'] = $page;

        return '/admin/menu?' . http_build_query($params);
    };

    // Cambiar de carta suelta la categoría y la página —las pestañas de abajo
    // son otras— y conserva la búsqueda por nombre y el pendiente. Sin $carta
    // vuelve a las dos.
    $buildCartaUrl = static function (string $carta) use ($filtros): string {
        $params = [];

        foreach ($filtros as $key => $value) {
            if ($key !== 'carta' && $key !== 'categoria' && (string) $value !== '') {
                $params[$key] = $value;
            }
        }

        if ($carta !== '') {
            $params['carta'] = $carta;
        }

        return '/admin/menu' . (empty($params) ? '' : '?' . http_build_query($params));
    };

    // Las pestañas de categoría conservan la carta: sólo reemplazan `categoria`.
    $buildTabUrl = static function (int $categoriaId) use ($filtros): string {
        $params = [];

        foreach ($filtros as $key => $value) {
            if ($key !== 'categoria' && (string) $value !== '') {
                $params[$key] = $value;
            }
        }

        if ($categoriaId > 0) {
            $params['categoria'] = $categoriaId;
        }

        return '/admin/menu' . (empty($params) ? '' : '?' . http_build_query($params));
    };

    // Las tarjetas-filtro conservan carta y categoría —la sección que se está
    // mirando— y sueltan la búsqueda y la página: pulsar «Con receta» es pedir
    // la lista de pendientes de ESA sección, entera y desde el principio.
    $buildPendienteUrl = static function (string $pendiente) use ($filtros): string {
        $params = array_filter([
            'carta' => (string) ($filtros['carta'] ?? ''),
            'categoria' => (string) ($filtros['categoria'] ?? ''),
            'pendiente' => $pendiente,
        ], static fn ($v): bool => $v !== '');

        return '/admin/menu' . (empty($params) ? '' : '?' . http_build_query($params));
    };

    // Lo que se está viendo ahora, para que los formularios de la fila
    // (visibilidad, borrar) devuelvan a la misma página del mismo filtro: el
    // controlador vuelve a pintar el listado leyendo esta misma query.
    $consultaLista = array_filter($filtros, static fn ($v): bool => (string) $v !== '');
    if ($paginaActual > 1) {
        $consultaLista['page'] = $paginaActual;
    }
    $sufijoLista = empty($consultaLista) ? '' : '?' . http_build_query($consultaLista);

    // Cómo se llama la sección acotada, para las cifras y la lista de
    // pendientes: «en Café», «en la carta de maridaje» o nada.
    $nombreCategoria = $categoriaActiva > 0 ? (string) ($categoriasMap[$categoriaActiva] ?? '') : '';
    if ($nombreCategoria !== '') {
        $alcanceTexto = 'en ' . $nombreCategoria;
    } elseif ($cartaActiva !== '' && isset($cartas[$cartaActiva])) {
        $alcanceTexto = 'en la carta de ' . mb_strtolower((string) ($cartas[$cartaActiva]['corto'] ?? $cartaActiva));
    } else {
        $alcanceTexto = '';
    }
    $alcanceAcotado = $alcanceTexto !== '';

    $resTotal = (int) $resumen['total'];
    $resSinReceta = (int) $resumen['sinReceta'];
    $resOcultos = (int) $resumen['ocultos'];
    $enPendientes = $pendienteActivo === 'receta';
?>

<?php if (!$partialOnly) : ?>
<section class="admin-menu admin-menu--modulo admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title">Menú</h2>
            <p class="admin-page__subtitle">Los platillos de la carta pública, el PDF y el punto de venta salen todos de aquí. La receta de cada uno se compone en Recetas.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-indigo" href="/admin/menu/categorias">Categorías</a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/menu/create" title="Nuevo platillo">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14"/><path d="M5 12h14"/>
                </svg>
                <span>Nuevo platillo</span>
            </a>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo); ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php /* Primer nivel del filtro: la carta, o sea la pieza impresa. Sustituyó
             a los dos botones de PDF —«PDF de la carta» y «PDF de maridaje»—: lo
             que se quería de ellos era ver qué entra en cada pieza, y eso se
             responde mejor con la lista misma que con un archivo.

             Son enlaces que navegan y no un intercambio parcial, a propósito: al
             cambiar de carta cambia también la fila de categorías de abajo, que
             vive FUERA del bloque que se reemplaza. */ ?>
    <?php /* «Ambas» y no «Todas»: la fila de categorías, justo debajo, abre con
             su propio «Todas», y con los dos activos en la primera carga se
             apilaban dos pastillas macizas idénticas con la misma palabra — no
             había forma de saber cuál de los dos niveles decía qué. Si algún día
             hay una tercera carta, vuelve a «Todas» sola. */ ?>
    <?php $rotuloSinCarta = count($cartas) === 2 ? 'Ambas' : 'Todas'; ?>
    <nav class="admin-menu__cartas" aria-label="Carta del menú">
        <span class="admin-eyebrow admin-menu__cartas-label" aria-hidden="true">Carta</span>
        <div class="admin-tabs admin-tabs--links admin-menu__cartas-tabs">
            <span class="admin-tabs__tab">
                <a href="<?php echo htmlspecialchars($buildCartaUrl(''), ENT_QUOTES, 'UTF-8'); ?>"
                   class="<?php echo $cartaActiva === '' ? 'is-active' : ''; ?>"
                   <?php echo $cartaActiva === '' ? 'aria-current="page"' : ''; ?>><span><?php echo $rotuloSinCarta; ?></span></a>
            </span>
            <?php foreach ($cartas as $valor => $carta) : ?>
                <span class="admin-tabs__tab">
                    <a href="<?php echo htmlspecialchars($buildCartaUrl((string) $valor), ENT_QUOTES, 'UTF-8'); ?>"
                       class="<?php echo $cartaActiva === (string) $valor ? 'is-active' : ''; ?>"
                       <?php echo $cartaActiva === (string) $valor ? 'aria-current="page"' : ''; ?>><span><?php echo htmlspecialchars((string) ($carta['corto'] ?? $valor)); ?></span></a>
                </span>
            <?php endforeach; ?>
        </div>
    </nav>

    <?php /* Pestañas de categoría + tab "+" para crear una nueva en línea.
             El checkbox va aquí, hermano del formulario, para que el selector
             `#cat-nueva-toggle:checked ~ .admin-cat-inline` pueda alcanzarlo. */ ?>
    <input type="checkbox" id="cat-nueva-toggle" class="admin-sr-only" aria-label="Nueva categoría">
    <?php /* <nav> y no role="tablist": son enlaces que filtran, no pestañas de un
             panel, y un tablist sin ningún role="tab" dentro se anunciaba como
             «lista de pestañas» vacía. Ahora el lector oye dos navegaciones
             hermanas —carta y categoría—, que es la estructura real. */ ?>
    <nav class="admin-tabs admin-tabs--links admin-menu__cats" aria-label="Categorías del menú">
        <span class="admin-tabs__tab">
            <a href="<?php echo htmlspecialchars($buildTabUrl(0), ENT_QUOTES, 'UTF-8'); ?>"
               class="<?php echo $categoriaActiva === 0 ? 'is-active' : ''; ?>"
               data-reactive-page><span>Todas</span></a>
        </span>
        <?php foreach ($categorias as $categoria) : $cid = (int) ($categoria->id ?? 0); ?>
            <span class="admin-tabs__tab">
                <a href="<?php echo htmlspecialchars($buildTabUrl($cid), ENT_QUOTES, 'UTF-8'); ?>"
                   class="<?php echo $categoriaActiva === $cid ? 'is-active' : ''; ?>"
                   data-reactive-page><span><?php echo htmlspecialchars((string) ($categoria->nombre ?? '')); ?></span></a>
            </span>
        <?php endforeach; ?>
        <span class="admin-tabs__tab admin-tabs__tab--add">
            <label for="cat-nueva-toggle" title="Nueva categoría"><span>+</span></label>
        </span>
    </nav>

    <form class="admin-cat-inline" method="POST" action="/admin/menu/categorias/create">
        <div class="admin-cat-inline__field">
            <label class="admin-cat-inline__label" for="cat-nueva-nombre">Nueva categoría</label>
            <input type="text" id="cat-nueva-nombre" name="nombre" maxlength="40" required
                   placeholder="Postres, Coctelería, Menú infantil…">
        </div>

        <?php /* La carta se elige aquí y no después en Categorías: sin este grupo,
                 toda categoría nacida del tab "+" entraba en la de comida, y una
                 de barra aparecía en la landing hasta que alguien lo notara.

                 Sin las explicaciones de la pantalla de edición: en una fila de alta
                 rápida dos renglones por opción pesan más que la decisión.

                 Parte de la carta que se está viendo: quien filtra por maridaje y
                 pulsa «+» quiere una categoría de barra, no tener que corregirla. */ ?>
        <?php $cartaPorDefecto = isset($cartas[$cartaActiva]) ? $cartaActiva : \Model\CategoriasMenu::CARTA_COMIDA; ?>
        <fieldset class="admin-cat-inline__field admin-pills">
            <legend class="admin-cat-inline__label">Carta</legend>
            <div class="admin-pills__group">
                <?php foreach ($cartas as $valor => $carta) : ?>
                    <label class="admin-pill">
                        <input type="radio" name="carta" value="<?php echo htmlspecialchars((string) $valor, ENT_QUOTES); ?>"
                               <?php echo (string) $valor === $cartaPorDefecto ? 'checked' : ''; ?> required>
                        <span class="admin-pill__body">
                            <span class="admin-pill__title"><?php echo htmlspecialchars((string) ($carta['corto'] ?? $valor)); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button type="submit" class="admin-btn admin-btn--primary admin-cat-inline__submit">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5v14"/><path d="M5 12h14"/>
            </svg>
            Crear categoría
        </button>
        <span class="admin-cat-inline__hint">Aparecerá como pestaña al instante. La imagen se asigna después, en Categorías.</span>
    </form>

    <?php /* Cabecera del listado: dos tarjetas-filtro y el buscador en UNA fila.
             Las tarjetas eran dos cajas anchas con un número cada una; ahora
             dicen cuánto falta —«30 de 107 con receta» y su barra— y, al
             pulsarlas, convierten la tabla de abajo en la lista de pendientes.
             El buscador va en la misma fila porque es el tercer filtro del mismo
             listado: separado, era una cuarta franja apilada entre las pestañas
             y la tabla. Las cifras son de la carta y la categoría elegidas
             (AdminMenuController::resumenEstado), no de la búsqueda. */ ?>
    <div class="admin-filtros-fila">
        <?php
            $notaPlatillos = $resTotal === 0
                ? 'Sin platillos en esta sección'
                : ($resOcultos === 0
                    ? 'Todos visibles en la carta'
                    : number_format($resTotal - $resOcultos) . ' visibles · ' . number_format($resOcultos) . ($resOcultos === 1 ? ' oculto' : ' ocultos'));

            $feEtiqueta = 'Estado de los platillos';
            $feTarjetas = [
                [
                    'tipo' => 'todos',
                    'rotulo' => $nombreCategoria !== '' ? 'Platillos · ' . $nombreCategoria : 'Platillos',
                    'cifra' => number_format($resTotal),
                    'nota' => $notaPlatillos,
                    'accion' => 'Ver todos',
                    'href' => $buildPendienteUrl(''),
                    'activa' => !$enPendientes,
                ],
                [
                    'tipo' => 'tarea',
                    'rotulo' => 'Con receta',
                    'hechos' => $resTotal - $resSinReceta,
                    'total' => $resTotal,
                    'pendientesTexto' => 'sin receta',
                    'completoTexto' => 'Todos con receta',
                    'href' => $buildPendienteUrl($enPendientes ? '' : 'receta'),
                    'activa' => $enPendientes,
                ],
            ];
            include __DIR__ . '/../partials/_filtros-estado.php';
        ?>

        <form
            class="admin-filters admin-filtro-buscar"
            method="GET"
            action="/admin/menu"
            aria-label="Buscar platillos"
            data-reactive-filters
            data-reactive-target="#items-results"
            data-reactive-loading="#items-results-loading"
            data-reactive-error="#items-results-error"
            data-reactive-debounce="350"
        >
            <?php /*
              Un campo de búsqueda por nombre. El filtro de visibilidad y el botón
              de limpiar se retiraron porque la columna "Visible" de la tabla ya
              se lee de un vistazo y el buscador es reactivo (vaciarlo equivale a
              limpiar). El "Buscar" sigue emitido para quien navega sin JS; con
              JS se oculta por _forms.scss.
            */ ?>
            <div class="admin-filters__search">
                <label for="items-q"><?php echo $enPendientes ? 'Buscar entre los pendientes' : 'Buscar platillo'; ?></label>
                <p class="admin-filtro-buscar__nota" id="items-q-nota">Por nombre, <?php echo $alcanceAcotado ? $esc($alcanceTexto) : 'en todo el menú'; ?>.</p>
                <input
                    aria-describedby="items-q-nota"
                    id="items-q"
                    type="search"
                    data-reactive-control
                    data-reactive-default=""
                    name="q"
                    value="<?php echo $esc($filtros['q'] ?? ''); ?>"
                    placeholder="Escribe el nombre del platillo"
                >
                <input type="hidden" name="categoria" data-reactive-control data-reactive-default=""
                       value="<?php echo $esc($filtros['categoria'] ?? ''); ?>">
                <?php /* Sin este campo, buscar por nombre devolvía el listado a las dos
                         cartas: el formulario arma la URL sólo con lo que lleva dentro. */ ?>
                <input type="hidden" name="carta" data-reactive-control data-reactive-default=""
                       value="<?php echo $esc($filtros['carta'] ?? ''); ?>">
                <?php /* Y por lo mismo el pendiente: buscar dentro de la lista de
                         pendientes no debe devolver el catálogo entero. */ ?>
                <input type="hidden" name="pendiente" data-reactive-control data-reactive-default=""
                       value="<?php echo $esc($filtros['pendiente'] ?? ''); ?>">
            </div>
            <div class="admin-filters__actions">
                <button type="submit" class="admin-btn admin-btn--primary" data-reactive-submit>Buscar</button>
            </div>
        </form>
    </div>

    <div class="admin-reactive-results-shell">
        <div id="items-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
<?php if ($enPendientes) : ?>
    <?php /* ── Lista de pendientes ────────────────────────────────────────
             Una fila por platillo sin receta, con UNA acción: crearla. Sin la
             tabla de siete columnas: aquí no se compara nada, se trabaja de
             arriba abajo. Cada fila sale de la lista en cuanto su receta se
             guarda, y la tarjeta de arriba lo cuenta. */ ?>
    <section class="admin-menu__panel admin-panel admin-card admin-menu__panel--tareas" id="items" aria-labelledby="pendientes-titulo">
        <div class="admin-panel-head admin-tareas-cabeza">
            <div>
                <span class="admin-eyebrow">Pendientes<?php echo $alcanceAcotado ? ' · ' . $esc(preg_replace('/^en (la carta de )?/u', '', $alcanceTexto)) : ''; ?></span>
                <h3 id="pendientes-titulo">Platillos sin receta</h3>
                <p>Sin receta, vender un platillo no descuenta inventario y su costo sale en cero. Al guardar la receta, sale de esta lista.</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-btn--small admin-tareas-cabeza__volver" href="<?php echo $esc($buildPendienteUrl('')); ?>">
                <?php echo admin_icon('flecha-izquierda', 16, 'admin-btn__icon'); ?>
                Todos los platillos
            </a>
        </div>

        <?php if (empty($platillos)) : ?>
            <?php if ((string) ($filtros['q'] ?? '') !== '' && $resSinReceta > 0) : ?>
                <?php /* La búsqueda vació la lista, no el trabajo: nada que celebrar. */ ?>
                <p class="admin-empty">Ningún platillo sin receta se llama así. Quedan <?php echo number_format($resSinReceta); ?> pendientes; borra la búsqueda para verlos.</p>
            <?php elseif ($resTotal === 0) : ?>
                <p class="admin-empty">No hay platillos <?php echo $esc($alcanceTexto); ?> todavía.</p>
            <?php elseif ($alcanceAcotado && (int) $resumen['sinRecetaMenu'] > 0) : ?>
                <?php
                    $restoMenu = (int) $resumen['sinRecetaMenu'];
                    $tcTitulo = 'Todos los platillos ' . preg_replace('/^en /u', 'de ', $alcanceTexto) . ' tienen receta';
                    $tcTexto = 'Quedan ' . number_format($restoMenu) . ($restoMenu === 1 ? ' platillo' : ' platillos') . ' sin receta en el resto del menú.';
                    $tcParcial = true;
                    $tcAcciones = [
                        ['href' => '/admin/menu?pendiente=receta', 'texto' => 'Ver los que faltan', 'primaria' => true],
                        ['href' => $buildPendienteUrl(''), 'texto' => 'Volver a la lista'],
                    ];
                    include __DIR__ . '/../partials/_tareas-completas.php';
                ?>
            <?php else : ?>
                <?php
                    $tcTitulo = 'Todos los platillos tienen receta';
                    $tcTexto = number_format($resTotal) . ' de ' . number_format($resTotal) . '. Cada venta descuenta su inventario y el costo de cada platillo es real.';
                    $tcAcciones = [
                        ['href' => $buildPendienteUrl(''), 'texto' => 'Volver a la lista'],
                    ];
                    include __DIR__ . '/../partials/_tareas-completas.php';
                ?>
            <?php endif; ?>
        <?php else : ?>
            <p class="admin-tareas-cuenta">
                <?php if ($totalPaginas > 1 || (string) ($filtros['q'] ?? '') !== '') : ?>
                    <span class="admin-num"><?php echo $desde; ?>–<?php echo $hasta; ?></span> de <span class="admin-num"><?php echo number_format($totalMenu); ?></span> pendientes<?php echo $alcanceAcotado ? ' ' . $esc($alcanceTexto) : ''; ?>
                <?php else : ?>
                    <span class="admin-num"><?php echo number_format($totalMenu); ?></span> <?php echo $totalMenu === 1 ? 'pendiente' : 'pendientes'; ?><?php echo $alcanceAcotado ? ' ' . $esc($alcanceTexto) : ''; ?>
                <?php endif; ?>
            </p>
            <?php
                // A dónde volver tras guardar la receta: el editor redirige
                // aquí (AdminRecetasController::volverSeguro) y el platillo ya
                // no sale en la lista. Conserva carta y categoría.
                $volverAqui = $buildPendienteUrl('receta');
            ?>
            <ul class="admin-tareas admin-tareas--con-foto" aria-labelledby="pendientes-titulo">
                <?php foreach ($platillos as $platillo) : ?>
                    <?php
                        $miniPropia = \Services\Menu\FotoPlatillo::url($platillo->img ?? null);
                        $miniCategoria = $categoriasFoto[(int) $platillo->categoria_id] ?? null;
                        $miniSrc = $miniPropia ?? $miniCategoria;
                        $miniTipo = $miniPropia !== null ? 'propia' : ($miniCategoria !== null ? 'categoria' : 'vacia');
                        $catNombre = $categoriasMap[(int) $platillo->categoria_id] ?? '';
                        $areaNombre = $areasMap[(int) $platillo->area_id] ?? '';
                        $recetaUrl = '/admin/recetas/editar?' . http_build_query([
                            'id' => (int) $platillo->id,
                            'volver' => $volverAqui,
                        ]);
                    ?>
                    <li class="admin-tarea">
                        <span class="admin-tarea__marca" aria-hidden="true"></span>
                        <span class="admin-tarea__foto admin-menu__foto admin-menu__foto--<?php echo $miniTipo; ?>" aria-hidden="true">
                            <?php if ($miniSrc !== null) : ?>
                                <img src="<?php echo $esc($miniSrc); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                            <?php else : ?>
                                <?php echo admin_icon('foto', 18); ?>
                            <?php endif; ?>
                        </span>
                        <span class="admin-tarea__texto">
                            <span class="admin-tarea__nombre"><?php echo $esc($platillo->nombre); ?></span>
                            <span class="admin-tarea__detalle">
                                <?php echo $esc($catNombre !== '' ? $catNombre : 'Sin categoría'); ?><?php if ($areaNombre !== '') : ?><span aria-hidden="true"> · </span><span class="admin-visually-hidden">, </span><?php echo $esc($areaNombre); ?><?php endif; ?><?php if (!$platillo->activo) : ?><span aria-hidden="true"> · </span><span class="admin-visually-hidden">, </span>Oculto<?php endif; ?>
                            </span>
                        </span>
                        <span class="admin-tarea__dato admin-num"><?php echo $dinero($platillo->precio); ?></span>
                        <span class="admin-tarea__accion">
                            <?php /* El enlace se estira a toda la fila (::after en
                                     _pendientes.scss): el blanco es el renglón entero,
                                     no un botón de 34 px. */ ?>
                            <a class="admin-btn admin-btn--secondary admin-btn--small admin-tarea__enlace"
                               href="<?php echo $esc($recetaUrl); ?>"
                               aria-label="Crear receta de <?php echo $esc($platillo->nombre); ?>">
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
            $pagUrl = $buildItemsUrl;
            $pagEtiqueta = 'Paginación de pendientes';
            $pagReactiva = true;
            include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
    </section>
<?php else : ?>
    <section class="admin-menu__panel admin-panel admin-card" id="items">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Platillos</h3>
                <?php /* La leyenda de la miniatura: el gris es la única pista visual
                         de «sin foto propia», y sin la frase había que pasar el ratón
                         por encima para saber qué quería decir. */ ?>
                <p>
                    <?php if ($totalMenu > 0) : ?>
                        <span class="admin-num"><?php echo $desde; ?>–<?php echo $hasta; ?></span> de <span class="admin-num"><?php echo number_format($totalMenu); ?></span><?php echo (string) ($filtros['q'] ?? '') !== '' ? ' resultados' : ' platillos'; ?>.
                    <?php endif; ?>
                    Miniatura en gris: sin foto propia, la carta usa la de su categoría.
                </p>
            </div>
        </div>

        <?php if (empty($platillos)) : ?>
            <p class="admin-menu__empty admin-empty">
                <?php echo $filtrosActivos ? 'No se encontraron resultados con los filtros aplicados.' : 'No hay platillos registrados.'; ?>
            </p>
        <?php else : ?>
            <?php /* El orden es de CLIENTE y ordena la página visible, no el
                     catálogo: el listado viene paginado de diez en diez y el
                     orden de fondo lo sigue fijando el SQL (categoría, nombre).
                     Visibilidad es un formulario y Acciones no es un dato, así
                     que ninguna de las dos ordena.

                     Cinco columnas en el orden en que se lee una fila: QUÉ es
                     (foto, nombre y descripción juntos, porque se leen juntos),
                     CUÁNTO cuesta, DÓNDE vive (categoría y, debajo, el área que
                     lo prepara) y lo que se HACE con él. La descripción tenía
                     columna propia y empujaba el precio a media tabla. */ ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table admin-menu__table--items" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Platillo</th>
                            <th data-sort-type="number" class="admin-menu__col-precio">Precio</th>
                            <th data-sort-type="text">Categoría y área</th>
                            <th data-sort-disabled class="admin-menu__col-visible">Visible</th>
                            <th data-sort-disabled>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($platillos as $platillo) : ?>
                            <?php $opcionesVariante = (int) (($variantesPorProducto ?? [])[(int) $platillo->id] ?? 0); ?>
                            <tr data-row-href="/admin/menu/edit?id=<?php echo (int) $platillo->id; ?>">
                                <?php
                                    // Lo que la carta enseñará: la foto propia o, si no hay,
                                    // la de la categoría (Carta::publica entrega las dos).
                                    $miniPropia = \Services\Menu\FotoPlatillo::url($platillo->img ?? null);
                                    $miniCategoria = $categoriasFoto[(int) $platillo->categoria_id] ?? null;
                                    if ($miniPropia !== null) {
                                        $miniTipo = 'propia';
                                        $miniSrc = $miniPropia;
                                        $miniTexto = 'Con foto propia';
                                    } elseif ($miniCategoria !== null) {
                                        $miniTipo = 'categoria';
                                        $miniSrc = $miniCategoria;
                                        $miniTexto = 'Sin foto propia: usa la de la categoría';
                                    } else {
                                        $miniTipo = 'vacia';
                                        $miniSrc = null;
                                        $miniTexto = 'Sin foto';
                                    }
                                    $descripcion = trim((string) $platillo->descripcion);
                                    $catNombre = $categoriasMap[(int) $platillo->categoria_id] ?? '#' . $platillo->categoria_id;
                                ?>
                                <td data-sort-value="<?php echo $esc($platillo->nombre); ?>">
                                    <div class="admin-menu__platillo">
                                        <div class="admin-menu__platillo-texto">
                                            <span class="admin-menu__nombre">
                                                <span class="admin-menu__nombre-texto"><?php echo $esc($platillo->nombre); ?></span>
                                                <?php if ($opcionesVariante > 0) : ?>
                                                    <span class="admin-menu__variantes-sub"><?php echo admin_icon('variantes', 13); ?><?php echo $opcionesVariante; ?> <?php echo $opcionesVariante === 1 ? 'opción' : 'opciones'; ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($descripcion !== '') : ?>
                                                <span class="admin-menu__descripcion" title="<?php echo $esc($descripcion); ?>"><?php echo $esc($descripcion); ?></span>
                                            <?php else : ?>
                                                <span class="admin-menu__descripcion admin-menu__descripcion--falta">Sin descripción</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="admin-menu__foto admin-menu__foto--<?php echo $miniTipo; ?>" title="<?php echo $esc($miniTexto); ?>">
                                            <?php if ($miniSrc !== null) : ?>
                                                <img src="<?php echo $esc($miniSrc); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                                            <?php else : ?>
                                                <?php echo admin_icon('foto', 18); ?>
                                            <?php endif; ?>
                                            <span class="admin-sr-only"><?php echo $esc($miniTexto); ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="admin-num admin-menu__precio" data-sort-value="<?php echo (float) $platillo->precio; ?>">
                                    <?php echo $dinero($platillo->precio); ?>
                                </td>
                                <td data-sort-value="<?php echo $esc($catNombre); ?>">
                                    <?php /* El tono se deriva del id, no se guarda: `categorias` no tiene
                                            columna de color y así cada categoría conserva el suyo entre
                                            pantallas sin tocar el esquema. */ ?>
                                    <span class="admin-badge admin-badge--cat-<?php echo (int) $platillo->categoria_id % 10; ?>"><?php echo $esc($catNombre); ?></span>
                                    <span class="admin-table__cell-sub admin-menu__area"><?php echo $esc($areasMap[(int) $platillo->area_id] ?? 'Sin área'); ?></span>
                                </td>
                                <td class="admin-menu__col-visible">
                                    <?php /* Endpoint dedicado: reenviar la fila entera por campos ocultos
                                            fallaba la validación al faltarle area_id. La query de la
                                            acción devuelve a esta misma página del mismo filtro. */ ?>
                                    <form method="POST" action="/admin/menu/toggle<?php echo $esc($sufijoLista); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                        <button
                                            type="submit"
                                            class="admin-status-switch <?php echo $platillo->activo ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $platillo->activo ? 'Ocultar platillo' : 'Mostrar platillo'; ?>"
                                            aria-label="<?php echo $platillo->activo ? 'Ocultar platillo ' : 'Mostrar platillo '; ?><?php echo $esc($platillo->nombre); ?>"
                                        >
                                            <span class="admin-status-switch__track" aria-hidden="true">
                                                <span class="admin-status-switch__thumb"></span>
                                            </span>
                                            <span class="admin-sr-only"><?php echo $platillo->activo ? 'Visible' : 'Oculto'; ?></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <?php /* Dos grupos: lo que modifica el platillo (variantes,
                                             editar) y, separado por un filete, lo que lo destruye.
                                             Pegados, borrar quedaba a 8 px de editar. */ ?>
                                    <div class="admin-table-actions admin-menu__acciones">
                                        <?php /* Las variantes tienen editor propio: ajustan la receta
                                                 y el precio, y necesitan la vista previa del POS. */ ?>
                                        <a
                                            class="admin-icon-button admin-icon-button--variantes<?php echo $opcionesVariante > 0 ? ' is-set' : ''; ?>"
                                            href="/admin/menu/variantes?id=<?php echo (int) $platillo->id; ?>"
                                            title="<?php echo $opcionesVariante > 0 ? 'Variantes' : 'Agregar variantes'; ?>"
                                            aria-label="<?php echo $opcionesVariante > 0 ? 'Variantes de ' : 'Agregar variantes a '; ?><?php echo $esc($platillo->nombre); ?>"
                                        >
                                            <?php echo admin_icon('variantes', 18); ?>
                                        </a>
                                        <a
                                            class="admin-icon-button admin-icon-button--edit"
                                            href="/admin/menu/edit?id=<?php echo (int) $platillo->id; ?>"
                                            title="Editar"
                                            aria-label="Editar platillo <?php echo $esc($platillo->nombre); ?>"
                                        >
                                            <?php echo admin_icon('editar', 18); ?>
                                        </a>
                                        <form method="POST" action="/admin/menu/delete<?php echo $esc($sufijoLista); ?>"
                                              class="admin-menu__accion-destructiva"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar platillo"
                                              data-confirm-title="¿Eliminar «<?php echo $esc($platillo->nombre); ?>»?"
                                              data-confirm-require="<?php echo $esc($platillo->nombre); ?>"
                                              data-confirm-description="Saldrá de la carta pública, del PDF y del punto de venta."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                            <button
                                                type="submit"
                                                class="admin-icon-button admin-icon-button--danger"
                                                title="Eliminar"
                                                aria-label="Eliminar platillo <?php echo $esc($platillo->nombre); ?>"
                                            >
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

            <?php
            $pagPagina = $paginaActual;
            $pagTotal = $totalPaginas;
            $pagUrl = $buildItemsUrl;
            $pagEtiqueta = 'Paginación de platillos';
            $pagReactiva = true;
            include __DIR__ . '/../partials/_pagination.php';
            ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="items-results-loading" role="status" hidden>Actualizando resultados</div>
        <div class="admin-reactive-error" id="items-results-error" role="alert" hidden>No se pudieron cargar los resultados.</div>
    </div>
</section>
<?php endif; ?>
