<?php
    $alertas = $alertas ?? [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : [];
    $accion = $accion ?? 'Guardar cambios';
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Platillos</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Platillo'); ?></h2>
            <p class="admin-page__subtitle">Crea o actualiza los platillos que aparecen en la carta pública.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/menu/items">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos del platillo</h3>
                <p>Define nombre, descripción, precio, categoría y visibilidad.</p>
            </div>
        </div>

        <?php if (!empty($alertas['error'])) : ?>
            <div class="admin-menu__alert">
                <strong>Revisa los siguientes datos:</strong>
                <ul>
                    <?php foreach ($alertas['error'] as $error) : ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="admin-menu__form" method="POST">
            <label for="nombre">Nombre del platillo</label>
            <input type="text" id="nombre" name="nombre" maxlength="100"
                   value="<?php echo htmlspecialchars($platillo->nombre ?? ''); ?>" required>

            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" required><?php echo htmlspecialchars($platillo->descripcion ?? ''); ?></textarea>

            <label for="precio">Precio (MXN)</label>
            <input type="number" id="precio" name="precio" step="0.01" min="0"
                   value="<?php echo htmlspecialchars($platillo->precio ?? ''); ?>" required>

            <label for="categoria_id">Categoría</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Selecciona una categoría</option>
                <?php foreach ($categorias as $cat) : ?>
                    <option value="<?php echo (int) $cat->id; ?>"
                        <?php echo (int) ($platillo->categoria_id ?? 0) === (int) $cat->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat->nombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="tag">Etiqueta / tag opcional</label>
            <input type="text" id="tag" name="tag" maxlength="60"
                   placeholder="Especialidad C.P., Estrella, Premium"
                   value="<?php echo htmlspecialchars($platillo->tag ?? ''); ?>">

            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($platillo->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Platillo visible en el menú</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items">Cancelar</a>
            </div>
        </form>
    </section>
</section>
