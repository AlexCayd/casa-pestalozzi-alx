<?php
    /**
     * Listado de platillos con pestañas de categoría.
     *
     * Sustituye al antiguo hub (que solo enlazaba a Platillos y Categorías) y a
     * views/admin/menu/items.php. Los datos salen de `productos`, la tabla
     * fusionada. Los tabs son enlaces con `data-reactive-page`, así que
     * reutilizan el intercambio parcial de src/js/admin/core/reactive-filters.js
     * (cambian sin recargar y, sin JS, navegan normal).
     */
    $platillos = isset($platillos) && is_iterable($platillos) ? $platillos : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $categoriasMap = $categoriasMap ?? [];
    $areasMap = is_array($areasMap ?? null) ? $areasMap : [];
    $filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'categoria' => '', 'visible' => ''];
    $categoriaActiva = (int) ($categoriaActiva ?? 0);
    $filtrosActivos = (bool) ($filtrosActivos ?? false);
    $partialOnly = (bool) ($partialOnly ?? false);
    $totalMenu = (int) ($totalMenu ?? count($platillos));
    $paginaActual = (int) ($paginaActual ?? 1);
    $porPagina = (int) ($porPagina ?? max(1, $totalMenu));
    $totalPaginas = (int) ($totalPaginas ?? 1);
    $desde = $totalMenu === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalMenu);

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
?>

<?php if (!$partialOnly) : ?>
<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title">Menú</h2>
            <p class="admin-page__subtitle">Los platillos de la carta pública, el PDF y el punto de venta salen todos de aquí. La receta de cada uno se compone en Recetas.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-indigo" href="/admin/menu/categorias">Categorías</a>
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-turquesa" href="/admin/menu/pdf" target="_blank" rel="noopener">Generar PDF</a>
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

    <?php /* Pestañas de categoría + tab "+" para crear una nueva en línea.
             El checkbox va aquí, hermano del formulario, para que el selector
             `#cat-nueva-toggle:checked ~ .admin-cat-inline` pueda alcanzarlo. */ ?>
    <input type="checkbox" id="cat-nueva-toggle" class="admin-sr-only">
    <div class="admin-tabs admin-tabs--links admin-menu__cats" role="tablist" aria-label="Categorías del menú">
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
    </div>

    <form class="admin-cat-inline" method="POST" action="/admin/menu/categorias/create">
        <div class="admin-cat-inline__field">
            <label class="admin-cat-inline__label" for="cat-nueva-nombre">Nueva categoría</label>
            <input type="text" id="cat-nueva-nombre" name="nombre" maxlength="40" required
                   placeholder="Postres, Coctelería, Menú infantil…">
        </div>
        <button type="submit" class="admin-btn admin-btn--primary admin-cat-inline__submit">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5v14"/><path d="M5 12h14"/>
            </svg>
            Crear categoría
        </button>
        <span class="admin-cat-inline__hint">Aparecerá como pestaña al instante. La imagen se asigna después, en Categorías.</span>
    </form>

    <form
        class="admin-filters"
        method="GET"
        action="/admin/menu"
        aria-label="Filtros de platillos"
        data-reactive-filters
        data-reactive-target="#items-results"
        data-reactive-loading="#items-results-loading"
        data-reactive-error="#items-results-error"
        data-reactive-debounce="350"
    >
        <?php /*
          Una sola línea: un campo de búsqueda por nombre. El filtro de
          visibilidad y el botón de limpiar se retiraron porque la columna
          "Visible" de la tabla ya se lee de un vistazo y el buscador es
          reactivo (vaciarlo equivale a limpiar). El "Buscar" sigue emitido
          para quien navega sin JS; con JS se oculta por _forms.scss.
        */ ?>
        <div class="admin-filters__search">
            <label for="items-q">Buscar platillo</label>
            <input
                id="items-q"
                type="search"
                data-reactive-control
                data-reactive-default=""
                name="q"
                value="<?php echo htmlspecialchars((string) ($filtros['q'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Escribe el nombre del platillo"
            >
            <input type="hidden" name="categoria" data-reactive-control data-reactive-default=""
                   value="<?php echo htmlspecialchars((string) ($filtros['categoria'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary" data-reactive-submit>Buscar</button>
        </div>
    </form>

    <div class="admin-reactive-results-shell">
        <div id="items-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
    <section class="admin-menu__panel admin-panel admin-card" id="items">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Platillos</h3>
                <p><?php echo $totalMenu; ?> registros. Mostrando <?php echo $desde; ?>-<?php echo $hasta; ?> de <?php echo $totalMenu; ?> platillos.</p>
            </div>
        </div>

        <?php if (empty($platillos)) : ?>
            <p class="admin-menu__empty admin-empty">
                <?php echo $filtrosActivos ? 'No se encontraron resultados con los filtros aplicados.' : 'No hay platillos registrados.'; ?>
            </p>
        <?php else : ?>
            <?php /* El orden es de CLIENTE y ordena la página visible, no los 78
                     platillos: el listado viene paginado de diez en diez y el
                     orden de fondo lo sigue fijando el SQL (categoría, nombre).
                     Visibilidad es un formulario y Acciones no es un dato, así
                     que ninguna de las dos ordena. */ ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table admin-menu__table--items" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Nombre</th>
                            <th data-sort-type="text">Descripción</th>
                            <th data-sort-type="number">Precio</th>
                            <th data-sort-type="text">Categoría</th>
                            <th data-sort-type="text">Área</th>
                            <th data-sort-disabled>Visibilidad</th>
                            <th data-sort-disabled>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($platillos as $platillo) : ?>
                            <tr data-row-href="/admin/menu/edit?id=<?php echo (int) $platillo->id; ?>">
                                <td>
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($platillo->nombre); ?></span>
                                </td>
                                <td class="admin-menu__description">
                                    <?php if (trim((string) $platillo->descripcion) !== '') : ?>
                                        <span class="admin-table__description" title="<?php echo htmlspecialchars((string) $platillo->descripcion, ENT_QUOTES); ?>"><?php echo htmlspecialchars((string) $platillo->descripcion); ?></span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning">Sin descripción</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-table__cell-main">$<?php echo number_format((float) $platillo->precio, 2); ?></span>
                                </td>
                                <td>
                                    <?php /* El tono se deriva del id, no se guarda: `categorias` no tiene
                                            columna de color y así cada categoría conserva el suyo entre
                                            pantallas sin tocar el esquema. */ ?>
                                    <span class="admin-badge admin-badge--cat-<?php echo (int) $platillo->categoria_id % 10; ?>"><?php echo htmlspecialchars($categoriasMap[(int) $platillo->categoria_id] ?? '#' . $platillo->categoria_id); ?></span>
                                </td>
                                <td>
                                    <span class="admin-table__cell-sub"><?php echo htmlspecialchars($areasMap[(int) $platillo->area_id] ?? '—'); ?></span>
                                </td>
                                <td>
                                    <?php /* Endpoint dedicado: reenviar la fila entera por campos ocultos
                                            fallaba la validación al faltarle area_id. */ ?>
                                    <form method="POST" action="/admin/menu/toggle">
                                        <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                        <button
                                            type="submit"
                                            class="admin-status-switch <?php echo $platillo->activo ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $platillo->activo ? 'Ocultar platillo' : 'Mostrar platillo'; ?>"
                                            aria-label="<?php echo $platillo->activo ? 'Ocultar platillo ' : 'Mostrar platillo '; ?><?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>"
                                        >
                                            <span class="admin-status-switch__track" aria-hidden="true">
                                                <span class="admin-status-switch__thumb"></span>
                                            </span>
                                            <span class="admin-sr-only"><?php echo $platillo->activo ? 'Visible' : 'Oculto'; ?></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a
                                            class="admin-icon-button admin-icon-button--edit"
                                            href="/admin/menu/edit?id=<?php echo (int) $platillo->id; ?>"
                                            title="Editar"
                                            aria-label="Editar platillo <?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                            </svg>
                                        </a>
                                        <form method="POST" action="/admin/menu/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar platillo"
                                              data-confirm-title="¿Eliminar «<?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>»?"
                                              data-confirm-description="Saldrá de la carta pública, del PDF y del punto de venta."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $platillo->id; ?>">
                                            <button
                                                type="submit"
                                                class="admin-icon-button admin-icon-button--danger"
                                                title="Eliminar"
                                                aria-label="Eliminar platillo <?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M3 6h18"/>
                                                    <path d="M8 6V4h8v2"/>
                                                    <path d="M19 6l-1 14H6L5 6"/>
                                                    <path d="M10 11v5"/>
                                                    <path d="M14 11v5"/>
                                                </svg>
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
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="items-results-loading" role="status" hidden>Actualizando resultados</div>
        <div class="admin-reactive-error" id="items-results-error" role="alert" hidden>No se pudieron cargar los resultados.</div>
    </div>
</section>
<?php endif; ?>
