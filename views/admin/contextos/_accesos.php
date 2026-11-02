<?php foreach ($tarjetas as $tarjeta) : ?>
<a class="admin-access admin-card" href="<?= $esc($tarjeta['ruta']) ?>">
    <span class="admin-access__icon" aria-hidden="true"><?= admin_icon($tarjeta['icono'],24) ?></span>
    <span class="admin-access__copy"><strong><?= $esc($tarjeta['titulo']) ?></strong><span><?= $esc($tarjeta['descripcion']) ?></span></span>
    <span class="admin-access__action"><?= $esc($tarjeta['accion']) ?> <?= admin_icon('flecha-derecha',16) ?></span>
</a>
<?php endforeach; ?>
