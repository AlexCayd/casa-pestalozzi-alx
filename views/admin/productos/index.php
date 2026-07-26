<?php
    $productos = isset($productos) && is_iterable($productos) ? $productos : [];
    $conteosReceta = is_array($conteosReceta ?? null) ? $conteosReceta : [];
    $categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
    $totalProductos = (int) ($totalProductos ?? 0);
?>
<section class="admin-productos admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas</span>
            <h2 class="admin-page__title">Productos y recetas</h2>
            <p class="admin-page__subtitle">Cada producto lleva una receta principal de ingredientes y subrecetas. Al venderse, su receta descuenta el inventario. El enlace con el punto de venta es por nombre exacto del platillo.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/productos/subrecetas">Subrecetas</a>
            <a class="admin-btn admin-btn--secondary" href="/admin/inventario">Inventario</a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/productos/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nuevo producto</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card">
        <div class="admin-panel-head">
            <div>
                <h3>Catálogo</h3>
                <p><?php echo $totalProductos; ?> productos registrados.</p>
            </div>
        </div>

        <?php if (empty($productos)) : ?>
            <p class="admin-empty">No hay productos registrados. Crea el primero y define su receta principal.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table" data-sortable>
                    <thead>
                        <tr>
                            <th data-sort-type="text">Producto</th>
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
                            <tr>
                                <td><span class="admin-table__cell-main"><?php echo htmlspecialchars($prod->nombre); ?></span></td>
                                <td><span class="admin-table__cell-sub"><?php echo htmlspecialchars($catNombre); ?></span></td>
                                <td><span class="admin-table__cell-main">$<?php echo number_format((float) $prod->precio, 2); ?></span></td>
                                <td>
                                    <?php if ($n > 0) : ?>
                                        <span class="admin-badge admin-badge--success">Receta asignada</span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning">Falta receta</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $prod->activo ? 'neutral' : 'danger'; ?>"><?php echo $prod->activo ? 'Activo' : 'Inactivo'; ?></span>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/productos/edit?id=<?php echo (int) $prod->id; ?>" title="Editar receta" aria-label="Editar <?php echo htmlspecialchars($prod->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/productos/delete" onsubmit="return confirm('¿Eliminar el producto &quot;<?php echo htmlspecialchars($prod->nombre, ENT_QUOTES); ?>&quot;?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $prod->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo htmlspecialchars($prod->nombre, ENT_QUOTES); ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
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
</section>
