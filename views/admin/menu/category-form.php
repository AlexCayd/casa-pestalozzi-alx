<?php
    $alertas = $alertas ?? [];
    $accion = $accion ?? 'Guardar cambios';
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Categorías</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title ?? 'Categoría'); ?></h2>
            <p class="admin-page__subtitle">Crea una categoría para agrupar platillos en la carta pública.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/menu/categorias">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos de la categoría</h3>
                <p>Completa nombre, imagen y visibilidad.</p>
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

        <form class="admin-menu__form" method="POST" enctype="multipart/form-data">
            <label for="nombre">Nombre de la categoría</label>
            <input type="text" id="nombre" name="nombre" maxlength="40"
                   value="<?php echo htmlspecialchars($categoria->nombre ?? ''); ?>" required>

            <label for="imagen">Imagen de la categoría</label>
            <?php if (!empty($categoria->img)) : ?>
                <div class="admin-menu__current-image">
                    <img src="/<?php echo htmlspecialchars(ltrim($categoria->img, '/')); ?>"
                         alt="Imagen actual de la categoría">
                    <span>Imagen actual. Sube una nueva para reemplazarla.</span>
                </div>
            <?php endif; ?>
            <input type="file" id="imagen" name="imagen" accept="image/*"
                   <?php echo empty($categoria->img) ? 'required' : ''; ?>>
            <p class="admin-menu__help">Formatos: JPG, PNG, WebP, GIF o AVIF. La imagen se convierte a WebP desde el uploader actual.</p>

            <div class="admin-menu__check">
                <input type="checkbox" id="activo" name="activo" value="1"
                       <?php echo (int) ($categoria->activo ?? 1) === 1 ? 'checked' : ''; ?>>
                <label for="activo">Categoría visible en el menú</label>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo htmlspecialchars($accion); ?></button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categorias">Cancelar</a>
            </div>
        </form>
    </section>
</section>
