<?php
    $alertas = $alertas ?? [];
    $unidades = isset($unidades) && is_iterable($unidades) ? $unidades : ['g', 'kg', 'ml', 'l', 'pza'];
    $accion = $accion ?? 'Guardar';
    $ing = $ingrediente ?? null;
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Ingrediente'); ?></h2>
            <p class="admin-page__subtitle">Define el ingrediente y sus existencias actuales.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/inventario">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-form-grid" method="POST">
            <div class="admin-field">
                <label class="admin-field__label" for="nombre">Nombre del ingrediente</label>
                <input type="text" id="nombre" name="nombre" maxlength="120" required
                       value="<?php echo htmlspecialchars($ing->nombre ?? ''); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="unidad">Unidad de medida</label>
                <select id="unidad" name="unidad">
                    <?php foreach ($unidades as $u) : ?>
                        <option value="<?php echo htmlspecialchars($u); ?>" <?php echo ($ing->unidad ?? 'g') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="stock">Stock actual</label>
                <input type="number" id="stock" name="stock" step="0.001"
                       value="<?php echo htmlspecialchars((string) ($ing->stock ?? 0)); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="stock_minimo">Stock mínimo (alerta)</label>
                <input type="number" id="stock_minimo" name="stock_minimo" step="0.001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->stock_minimo ?? 0)); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label admin-field__label--split" for="costo">
                    <span>Costo por unidad</span>
                    <span>MXN / <?php echo htmlspecialchars($ing->unidad ?? 'g'); ?></span>
                </label>
                <input type="number" id="costo" name="costo" step="0.0001" min="0"
                       value="<?php echo htmlspecialchars((string) ($ing->costo ?? 0)); ?>">
            </div>

            <label class="admin-switch admin-form-grid__full">
                <input type="checkbox" name="activo" value="1" <?php echo (int) ($ing->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Ingrediente activo</span>
            </label>

            <div class="admin-form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary" href="/admin/inventario">Cancelar</a>
            </div>
        </form>
    </section>
</section>
