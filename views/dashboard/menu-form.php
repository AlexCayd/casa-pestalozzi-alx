<section class="panel" style="max-width:680px;">
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

    <form class="cp-form" method="POST">
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
            <option value="">— Selecciona una categoría —</option>
            <?php foreach ($categorias as $cat) : ?>
                <option value="<?php echo (int) $cat->id; ?>"
                    <?php echo (int) ($platillo->categoria_id ?? 0) === (int) $cat->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat->nombre); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="tag">Etiqueta / Tag (opcional)</label>
        <input type="text" id="tag" name="tag" maxlength="60"
               placeholder="Especialidad C.P., Estrella, Premium…"
               value="<?php echo htmlspecialchars($platillo->tag ?? ''); ?>">

        <div class="check">
            <input type="checkbox" id="activo" name="activo" value="1"
                   <?php echo (int) ($platillo->activo ?? 1) === 1 ? 'checked' : ''; ?>>
            <label for="activo" style="margin:0;">Platillo activo (visible en el menú)</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($accion); ?></button>
            <a class="btn btn-light" href="/dashboard">Cancelar</a>
        </div>
    </form>
</section>
