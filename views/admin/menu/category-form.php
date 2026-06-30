<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Categorias</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Categoria'); ?></h2>
            <p class="admin-page__subtitle">Los cambios impactan el catalogo que consume la landing publica.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categories">Volver</a>
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

        <form class="admin-menu__form" method="POST" enctype="multipart/form-data">
            <label for="nombre">Nombre de la categoria</label>
            <input type="text" id="nombre" name="nombre" maxlength="40"
                   value="<?php echo htmlspecialchars($categoria->nombre ?? ''); ?>" required>

            <label for="imagen">Imagen de la categoria</label>
            <?php if (!empty($categoria->img)) : ?>
                <div class="admin-menu__current-image">
                    <img src="/<?php echo htmlspecialchars(ltrim($categoria->img, '/')); ?>"
                         alt="Imagen actual de la categoria">
                    <span>Imagen actual. Sube una nueva para reemplazarla.</span>
                </div>
            <?php endif; ?>
            <input type="file" id="imagen" name="imagen" accept="image/*"
                   <?php echo empty($categoria->img) ? 'required' : ''; ?>>
            <p class="admin-menu__help">Formatos: JPG, PNG, WebP, GIF o AVIF. La imagen se convierte a WebP desde el uploader actual.</p>

            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($categoria->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Categoria activa</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categories">Cancelar</a>
            </div>
        </form>
    </section>
</section>
