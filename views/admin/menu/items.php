<?php
    $platillos = isset($platillos) && is_iterable($platillos) ? $platillos : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $categoriasMap = $categoriasMap ?? [];
    $filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'category_id' => '', 'visible' => ''];
    $filtrosActivos = (bool) ($filtrosActivos ?? false);
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

        return '/admin/menu/items?' . http_build_query($params) . '#items';
    };
?>

<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title">Platillos</h2>
            <p class="admin-page__subtitle">Administra los platillos disponibles, su precio, categoría, etiqueta y visibilidad.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/menu">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-create-button" href="/admin/menu/items/create" title="Nuevo platillo" aria-label="Nuevo platillo">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
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

    <form class="admin-filters" method="GET" action="/admin/menu/items">
        <div class="admin-filters__search">
            <label for="items-q">Buscar</label>
            <input
                id="items-q"
                type="search"
                name="q"
                value="<?php echo htmlspecialchars((string) ($filtros['q'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Nombre o descripción"
            >
        </div>
        <div class="admin-filters__group">
            <label for="items-category">Categoría</label>
            <select id="items-category" name="category_id">
                <option value="">Todas</option>
                <?php foreach ($categorias as $categoria) : ?>
                    <?php $categoriaId = (int) ($categoria->id ?? 0); ?>
                    <option value="<?php echo $categoriaId; ?>" <?php echo (string) ($filtros['category_id'] ?? '') === (string) $categoriaId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) ($categoria->nombre ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filters__group">
            <label for="items-visible">Visibilidad</label>
            <select id="items-visible" name="visible">
                <option value="">Todos</option>
                <option value="1" <?php echo ($filtros['visible'] ?? '') === '1' ? 'selected' : ''; ?>>Visibles</option>
                <option value="0" <?php echo ($filtros['visible'] ?? '') === '0' ? 'selected' : ''; ?>>No visibles</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Buscar</button>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items">Limpiar</a>
        </div>
    </form>

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
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table admin-menu__table--items">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Precio</th>
                            <th>Categoría</th>
                            <th>Tag</th>
                            <th>Visibilidad</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($platillos as $platillo) : ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-sub">#<?php echo (int) $platillo->id; ?></span>
                                </td>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($platillo->nombre); ?></span>
                                </td>
                                <td class="admin-menu__description">
                                    <span class="admin-table__description" title="<?php echo htmlspecialchars($platillo->descripcion, ENT_QUOTES); ?>"><?php echo htmlspecialchars($platillo->descripcion); ?></span>
                                </td>
                                <td>
                                    <span class="admin-table__cell-main">$<?php echo number_format((float) $platillo->precio, 2); ?></span>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($categoriasMap[$platillo->categoria_id] ?? '#' . $platillo->categoria_id); ?></span>
                                </td>
                                <td>
                                    <?php if ($platillo->tag) : ?>
                                        <span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($platillo->tag); ?></span>
                                    <?php else : ?>
                                        <span class="admin-table__cell-sub">Sin tag</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="/admin/menu/items/edit?id=<?php echo (int) $platillo->id; ?>">
                                        <input type="hidden" name="nombre" value="<?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>">
                                        <input type="hidden" name="descripcion" value="<?php echo htmlspecialchars($platillo->descripcion, ENT_QUOTES); ?>">
                                        <input type="hidden" name="precio" value="<?php echo htmlspecialchars((string) $platillo->precio, ENT_QUOTES); ?>">
                                        <input type="hidden" name="categoria_id" value="<?php echo (int) $platillo->categoria_id; ?>">
                                        <input type="hidden" name="tag" value="<?php echo htmlspecialchars((string) ($platillo->tag ?? ''), ENT_QUOTES); ?>">
                                        <?php if (!$platillo->activo) : ?>
                                            <input type="hidden" name="activo" value="1">
                                        <?php endif; ?>
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
                                            href="/admin/menu/items/edit?id=<?php echo (int) $platillo->id; ?>"
                                            title="Editar"
                                            aria-label="Editar platillo <?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                            </svg>
                                        </a>
                                        <form method="POST" action="/admin/menu/items/delete" onsubmit="return confirm('Eliminar el platillo &quot;<?php echo htmlspecialchars($platillo->nombre, ENT_QUOTES); ?>&quot;?');">
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

            <?php if ($totalPaginas > 1) : ?>
                <nav class="admin-menu__pagination" aria-label="Paginación de platillos">
                    <?php if ($paginaActual > 1) : ?>
                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="<?php echo htmlspecialchars($buildItemsUrl($paginaActual - 1), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                    <?php else : ?>
                        <span class="admin-btn admin-btn--disabled admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--disabled">Anterior</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPaginas; $i++) : ?>
                        <?php if ($i === $paginaActual) : ?>
                            <span class="admin-btn admin-btn--primary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--primary"><?php echo $i; ?></span>
                        <?php else : ?>
                            <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="<?php echo htmlspecialchars($buildItemsUrl($i), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($paginaActual < $totalPaginas) : ?>
                        <a class="admin-btn admin-btn--secondary admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--light" href="<?php echo htmlspecialchars($buildItemsUrl($paginaActual + 1), ENT_QUOTES, 'UTF-8'); ?>">Siguiente</a>
                    <?php else : ?>
                        <span class="admin-btn admin-btn--disabled admin-btn--small admin-menu__button admin-menu__button--small admin-menu__button--disabled">Siguiente</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>
