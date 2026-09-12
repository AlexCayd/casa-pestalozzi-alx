<?php
    /**
     * Listado de platillos con su receta.
     *
     * Paginado y con buscador reactivo, igual que Menú: el bloque de
     * resultados se intercambia con src/js/admin/core/reactive-filters.js y
     * sin JS el formulario navega como un GET normal. Por eso el archivo se
     * parte en dos con $partialOnly — lo que está fuera del envoltorio
     * (cabecera, métricas y filtros) no se reemplaza en cada búsqueda.
     */
    $productos = isset($productos) && is_iterable($productos) ? $productos : [];
    $conteosReceta = is_array($conteosReceta ?? null) ? $conteosReceta : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
    $totalProductos = (int) ($totalProductos ?? 0);
    $conReceta = (int) ($conReceta ?? 0);
    $filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'estado' => 'todas'];
    $filtrosActivos = (bool) ($filtrosActivos ?? false);
    $partialOnly = (bool) ($partialOnly ?? false);
    $totalFiltrado = (int) ($totalFiltrado ?? count($productos));
    $paginaActual = (int) ($paginaActual ?? 1);
    $porPagina = (int) ($porPagina ?? max(1, $totalFiltrado));
    $totalPaginas = (int) ($totalPaginas ?? 1);
    $desde = $totalFiltrado === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
    $hasta = min($paginaActual * $porPagina, $totalFiltrado);

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

    $estadoActual = (string) ($filtros['estado'] ?? 'todas');
    $opcionesEstado = [
        'todas' => 'Todas',
        'sin'   => 'Sin receta',
        'con'   => 'Con receta',
    ];
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

    <?php /* Las dos cifras son del CATÁLOGO, no de la página ni del filtro:
             salen de su propia consulta en el controlador. */ ?>
    <div class="admin-stat-strip">
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">Platillos</span>
            <span class="admin-stat-card__value"><?php echo $totalProductos; ?></span>
        </div>
        <div class="admin-stat-card <?php echo ($totalProductos - $conReceta) > 0 ? 'admin-stat-card--alert' : ''; ?>">
            <span class="admin-stat-card__label">Sin receta</span>
            <span class="admin-stat-card__value"><?php echo max(0, $totalProductos - $conReceta); ?></span>
        </div>
    </div>

    <form
        class="admin-filters admin-recetas__filters"
        method="GET"
        action="/admin/recetas"
        aria-label="Filtros de recetas"
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
          responder, y por eso la ayuda lo dice en vez de dejarlo a que alguien
          lo descubra. Los términos se cruzan con AND, así que seguir
          escribiendo afina.
        */ ?>
        <div class="admin-filters__search admin-field">
            <label class="admin-field__label" for="recetas-q">Buscar platillo, categoría o ingrediente</label>
            <input
                id="recetas-q"
                type="search"
                name="q"
                data-reactive-control
                data-reactive-default=""
                value="<?php echo htmlspecialchars((string) ($filtros['q'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="pollo ensalada, cilantro, salsa verde…"
                aria-describedby="recetas-q-hint"
            >
            <p class="admin-field__hint" id="recetas-q-hint">Busca por palabras sueltas y en cualquier orden; también dentro de los ingredientes y subrecetas de cada receta.</p>
        </div>

        <fieldset class="admin-pills admin-recetas__estado">
            <legend class="admin-pills__legend">Estado de la receta</legend>
            <div class="admin-pills__group">
                <?php foreach ($opcionesEstado as $valor => $titulo) : ?>
                    <label class="admin-pill">
                        <input
                            type="radio"
                            name="estado"
                            value="<?php echo $valor; ?>"
                            data-reactive-control
                            data-reactive-default="todas"
                            <?php echo $estadoActual === $valor ? 'checked' : ''; ?>
                        >
                        <span class="admin-pill__body">
                            <span class="admin-pill__title"><?php echo $titulo; ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary" data-reactive-submit>Buscar</button>
        </div>
    </form>

    <div class="admin-reactive-results-shell">
        <div id="recetas-results" class="admin-reactive-results" data-reactive-results aria-live="polite" aria-busy="false">
<?php endif; ?>
    <section class="admin-panel admin-card">
        <div class="admin-panel-head">
            <div>
                <h3>Platillos del menú</h3>
                <?php /* La segunda frase sólo cuando dice algo que la primera no:
                         sin filtro y en una sola página, «Mostrando 1-78 de 78
                         platillos» repite el mismo 78 que acaba de salir dos
                         palabras antes. */ ?>
                <p>
                    <?php echo $conReceta; ?> de <?php echo $totalProductos; ?> tienen receta asignada.
                    <?php if ($totalFiltrado > 0 && ($filtrosActivos || $totalPaginas > 1)) : ?>
                        Mostrando <?php echo $desde; ?>-<?php echo $hasta; ?> de <?php echo $totalFiltrado; ?><?php echo $filtrosActivos ? ' resultados.' : ' platillos.'; ?>
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
                     categoría, nombre). */ ?>
            <div class="admin-table-wrap">
                <table class="admin-table" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Platillo</th>
                            <th data-sort-type="text">Categoría</th>
                            <th data-sort-type="number">Precio</th>
                            <th data-sort-type="number">Receta</th>
                            <th data-sort-type="text">Estado</th>
                            <th data-sort-disabled>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $prod) : ?>
                            <?php $n = (int) ($conteosReceta[(int) $prod->id] ?? 0); ?>
                            <?php $catNombre = $categoriasMap[(int) $prod->categoria_id] ?? '—'; ?>
                            <tr data-row-href="/admin/recetas/editar?id=<?php echo (int) $prod->id; ?>">
                                <td><span class="admin-table__cell-main"><?php echo htmlspecialchars($prod->nombre); ?></span></td>
                                <td><span class="admin-table__cell-sub"><?php echo htmlspecialchars($catNombre); ?></span></td>
                                <td><span class="admin-table__cell-main">$<?php echo number_format((float) $prod->precio, 2); ?></span></td>
                                <td data-sort-value="<?php echo $n; ?>">
                                    <?php /* Los dos en contorno: es la misma columna diciendo lo mismo
                                            —cuántos ingredientes hay—, y en versión rellena el ámbar de
                                            «Falta receta» gritaba más que el estado de la fila. El orden
                                            va por el conteo, no por el texto: así «Falta receta» cae
                                            entero a un extremo en vez de alfabetizarse por la F. */ ?>
                                    <?php if ($n > 0) : ?>
                                        <span class="admin-badge admin-badge--success admin-badge--outline"><?php echo $n; ?> ingrediente<?php echo $n === 1 ? '' : 's'; ?></span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning admin-badge--outline">Falta receta</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $prod->activo ? 'neutral' : 'danger'; ?>"><?php echo $prod->activo ? 'Activo' : 'Inactivo'; ?></span>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/recetas/editar?id=<?php echo (int) $prod->id; ?>" title="Editar receta" aria-label="Editar receta de <?php echo htmlspecialchars($prod->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
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
<?php if (!$partialOnly) : ?>
        </div>
        <div class="admin-reactive-loading" id="recetas-results-loading" role="status" hidden>Actualizando resultados</div>
        <div class="admin-reactive-error" id="recetas-results-error" role="alert" hidden>No se pudieron cargar los resultados.</div>
    </div>
</section>
<?php endif; ?>
