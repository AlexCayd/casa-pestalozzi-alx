<?php
    /**
     * Formulario único del platillo.
     *
     * Fusiona el antiguo item-form.php (nombre, descripción, precio, tag) con
     * la parte de datos del form de Productos (categoría y área en pestañas).
     * La receta se compone aparte, en /admin/recetas.
     */
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar';
    $platillo = $platillo ?? null;
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $areas = isset($areas) && is_iterable($areas) ? $areas : [];
    $esNuevo = empty($platillo->id);
?>
<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Menú</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Platillo'); ?></h2>
            <p class="admin-page__subtitle">Este platillo se publica en la carta y en el PDF, y es el mismo que el mesero toca en el punto de venta.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/menu">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <form method="POST">
        <section class="admin-panel admin-card">
            <?php include __DIR__ . '/../partials/alertas.php'; ?>

            <div class="admin-form-grid">
                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="nombre">Nombre del platillo</label>
                    <input type="text" id="nombre" name="nombre" maxlength="120" required
                           value="<?php echo htmlspecialchars($platillo->nombre ?? ''); ?>">
                    <p class="admin-field__hint">Debe ser único: es el nombre con el que la venta descuenta inventario.</p>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <label class="admin-field__label" for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="3" required><?php echo htmlspecialchars($platillo->descripcion ?? ''); ?></textarea>
                    <p class="admin-field__hint">Es el texto que ve el comensal en la carta y en el PDF.</p>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Categoría</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Categoría">
                        <?php foreach ($categorias as $cat) : $cid = (int) $cat->id; ?>
                            <label class="admin-tabs__tab">
                                <input type="radio" name="categoria_id" value="<?php echo $cid; ?>"
                                       <?php echo (int) ($platillo->categoria_id ?? 0) === $cid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($cat->nombre); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-field admin-form-grid__full">
                    <span class="admin-field__label">Área de producción</span>
                    <div class="admin-tabs" role="radiogroup" aria-label="Área de producción">
                        <?php foreach ($areas as $area) : $aid = (int) $area['id']; ?>
                            <label class="admin-tabs__tab">
                                <input type="radio" name="area_id" value="<?php echo $aid; ?>"
                                       <?php echo (int) ($platillo->area_id ?? 0) === $aid ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($area['nombre']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="admin-field__hint">Determina a qué comanda se envía cuando el mesero lo pide.</p>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="precio">Precio (MXN)</label>
                    <input type="number" id="precio" name="precio" step="0.01" min="0" required
                           value="<?php echo htmlspecialchars((string) ($platillo->precio ?? '')); ?>">
                    <?php if (!empty($platillo->id)) : ?>
                        <p class="admin-field__hint">
                            <button type="button" class="admin-btn admin-btn--ghost"
                                    data-historial-precios
                                    data-historial-entidad="producto"
                                    data-historial-id="<?php echo (int) $platillo->id; ?>"
                                    data-historial-titulo="<?php echo htmlspecialchars($platillo->nombre ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                Ver histórico de precios
                            </button>
                        </p>
                    <?php endif; ?>
                </div>

                <label class="admin-switch admin-form-grid__full">
                    <input type="checkbox" name="activo" value="1" <?php echo (int) ($platillo->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                    <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Platillo activo (visible en la carta y vendible en el punto de venta)</span>
                </label>
            </div>
        </section>

        <div class="admin-form-grid__actions">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="admin-btn admin-btn--secondary" href="/admin/menu">Cancelar</a>
            <?php if (!$esNuevo) : ?>
                <a class="admin-btn admin-btn--secondary" href="/admin/recetas/editar?id=<?php echo (int) $platillo->id; ?>">Editar receta</a>
            <?php endif; ?>
        </div>
    </form>

    <?php include __DIR__ . '/../partials/_historial-precios-modal.php'; ?>
</section>
