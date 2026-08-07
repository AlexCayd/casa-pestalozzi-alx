<?php
    $productos = isset($productos) && is_iterable($productos) ? $productos : [];
    $conteosReceta = is_array($conteosReceta ?? null) ? $conteosReceta : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
    $totalProductos = (int) ($totalProductos ?? 0);
    $conReceta = (int) ($conReceta ?? 0);
?>
<section class="admin-recetas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación</span>
            <h2 class="admin-page__title">Recetas</h2>
            <p class="admin-page__subtitle">Define qué ingredientes y subrecetas consume cada platillo. Al venderse, su receta descuenta el inventario automáticamente. Los datos del platillo (nombre, precio, categoría) se editan en Menú.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/recetas/subrecetas">Subrecetas</a>
            <a class="admin-btn admin-btn--secondary" href="/admin/inventario">Inventario</a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/menu/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nuevo platillo</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

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

    <section class="admin-panel admin-card">
        <div class="admin-panel-head">
            <div>
                <h3>Platillos del menú</h3>
                <p><?php echo $conReceta; ?> de <?php echo $totalProductos; ?> tienen receta asignada.</p>
            </div>
        </div>

        <?php if (empty($productos)) : ?>
            <p class="admin-empty">No hay platillos registrados. Crea el primero en Menú y vuelve aquí a definir su receta.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Platillo</th>
                            <th data-sort-type="text">Categoría</th>
                            <th data-sort-type="number">Precio</th>
                            <th data-sort-type="text">Receta</th>
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
                                <td>
                                    <?php if ($n > 0) : ?>
                                        <span class="admin-badge admin-badge--success"><?php echo $n; ?> ingrediente<?php echo $n === 1 ? '' : 's'; ?></span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning">Falta receta</span>
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
        <?php endif; ?>
    </section>
</section>
