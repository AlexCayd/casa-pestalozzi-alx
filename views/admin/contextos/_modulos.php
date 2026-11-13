<?php
/**
 * Teselas del inicio: sólo icono y título; la tesela entera es el enlace.
 *
 * Espera $tarjetas (InicioAdministrativo) y $grande (bool): las del primer
 * grupo van grandes, con el icono en relleno; el resto, compactas.
 */
$mdEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<ul class="admin-inicio__teselas<?= $grande ? ' admin-inicio__teselas--grandes' : '' ?>" role="list">
    <?php foreach ($tarjetas as $tarjeta) : ?>
    <li>
        <a class="admin-modulo<?= $grande ? ' admin-modulo--grande' : '' ?>" href="<?= $mdEsc($tarjeta['ruta']) ?>">
            <span class="admin-modulo__icono" aria-hidden="true"><?= admin_icon($tarjeta['icono'], $grande ? 26 : 20) ?></span>
            <span class="admin-modulo__titulo"><?= $mdEsc($tarjeta['titulo']) ?></span>
            <span class="admin-modulo__flecha" aria-hidden="true"><?= admin_icon('flecha-derecha', 16) ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php unset($mdEsc); ?>
