<section class="panel" style="max-width:620px;">
    <div class="panel-head">
        <h2><?php echo htmlspecialchars($titulo); ?></h2>
        <a class="btn btn-light btn-sm" href="/dashboard">← Volver</a>
    </div>

    <?php if (!empty($alertas['error'])) : ?>
        <div class="alerta error">
            <strong>Revisa los siguientes datos:</strong>
            <ul>
                <?php foreach ($alertas['error'] as $error) : ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="cp-form" method="POST" enctype="multipart/form-data">
        <label for="nombre">Nombre de la categoría</label>
        <input type="text" id="nombre" name="nombre" maxlength="40"
               value="<?php echo htmlspecialchars($categoria->nombre ?? ''); ?>" required>

        <label for="imagen">Imagen de la categoría</label>
        <?php if (!empty($categoria->img)) : ?>
            <div class="img-actual">
                <img src="/<?php echo htmlspecialchars(ltrim($categoria->img, '/')); ?>"
                     alt="Imagen actual de la categoría">
                <span class="muted">Imagen actual — sube una nueva para reemplazarla.</span>
            </div>
        <?php endif; ?>
        <input type="file" id="imagen" name="imagen" accept="image/*"
               <?php echo empty($categoria->img) ? 'required' : ''; ?>>
        <p class="muted">Formatos: JPG, PNG, WebP, GIF o AVIF (máx. 5 MB). La imagen se convierte automáticamente a WebP.</p>

        <div class="check">
            <input type="checkbox" id="activo" name="activo" value="1"
                   <?php echo (int) ($categoria->activo ?? 1) === 1 ? 'checked' : ''; ?>>
            <label for="activo" style="margin:0;">Categoría activa</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="btn btn-light" href="/dashboard">Cancelar</a>
        </div>
    </form>
</section>
