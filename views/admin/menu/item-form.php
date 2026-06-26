<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Platillos</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Platillo'); ?></h2>
            <p class="admin-page__subtitle">Estos datos alimentan el menu publico y el modulo operativo que consume la tabla menu.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items">Volver</a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
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

            <label for="descripcion">Descripcion</label>
            <textarea id="descripcion" name="descripcion" required><?php echo htmlspecialchars($platillo->descripcion ?? ''); ?></textarea>

            <label for="precio">Precio (MXN)</label>
            <input type="number" id="precio" name="precio" step="0.01" min="0"
                   value="<?php echo htmlspecialchars($platillo->precio ?? ''); ?>" required>

            <label for="categoria_id">Categoria</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Selecciona una categoria</option>
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
                <label for="activo">Platillo activo y visible en el menu</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items">Cancelar</a>
            </div>
        </form>
    </section>
</section>
