<?php
/**
 * Paginación compartida del panel.
 *
 * Estaba escrita a mano sólo en views/admin/menu/index.php y su CSS vivía en
 * menu.scss, así que Tickets —el segundo módulo que la necesita— habría tenido
 * que cargar la hoja de Menú entera para conseguir el cromo. Aquí queda una vez
 * y el estilo se movió a _tables.scss, que carga siempre.
 *
 * Parámetros:
 * - $pagPagina: página actual, 1-based.
 * - $pagTotal:  total de páginas.
 * - $pagUrl:    callable(int $pagina): string que construye el enlace. Le toca
 *               conservar los filtros del módulo.
 * - $pagEtiqueta: texto del aria-label (por omisión, "Paginación").
 * - $pagReactiva: true para marcar los enlaces con data-reactive-page, que
 *               reactive-filters.js intercepta para paginar sin recargar. Sólo
 *               sirve de algo si la vista tiene el andamiaje de filtros
 *               reactivos; sin él los enlaces navegan normal.
 *
 * Cierra con unset() de sus parámetros: si una página lo incluye dos veces, el
 * segundo include heredaría lo que dejó el primero.
 */
$pagPagina = max(1, (int) ($pagPagina ?? 1));
$pagTotal = max(1, (int) ($pagTotal ?? 1));
$pagEtiqueta = (string) ($pagEtiqueta ?? 'Paginación');
$pagReactiva = (bool) ($pagReactiva ?? false);
$pagUrl = is_callable($pagUrl ?? null) ? $pagUrl : static fn (int $p): string => '?page=' . $p;

if ($pagTotal > 1) :
    $pagEscape = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $pagAttr = $pagReactiva ? ' data-reactive-page' : '';

    /*
     * Ventana deslizante de cinco páginas alrededor de la actual.
     *
     * La versión de Menú imprimía un botón por página sin ventana; con 78
     * platillos son ocho y aún cabían, pero Tickets pagina de veinte en veinte
     * sobre un histórico que sólo crece, y la barra se desbordaba. Los extremos
     * siempre están presentes —son los saltos útiles— y las elipsis marcan el
     * hueco.
     */
    $pagRadio = 2;
    $pagDesde = max(1, $pagPagina - $pagRadio);
    $pagHasta = min($pagTotal, $pagPagina + $pagRadio);

    // Si la ventana toca un extremo, se estira por el otro lado para que
    // siempre se ofrezca el mismo número de destinos.
    if ($pagPagina - $pagRadio < 1) {
        $pagHasta = min($pagTotal, $pagHasta + (1 - ($pagPagina - $pagRadio)));
    }
    if ($pagPagina + $pagRadio > $pagTotal) {
        $pagDesde = max(1, $pagDesde - (($pagPagina + $pagRadio) - $pagTotal));
    }
?>
<nav class="admin-pagination" aria-label="<?php echo $pagEscape($pagEtiqueta); ?>">
    <?php if ($pagPagina > 1) : ?>
        <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $pagEscape($pagUrl($pagPagina - 1)); ?>"<?php echo $pagAttr; ?> rel="prev">Anterior</a>
    <?php else : ?>
        <span class="admin-btn admin-btn--disabled admin-btn--small">Anterior</span>
    <?php endif; ?>

    <?php if ($pagDesde > 1) : ?>
        <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $pagEscape($pagUrl(1)); ?>"<?php echo $pagAttr; ?>>1</a>
        <?php if ($pagDesde > 2) : ?>
            <span class="admin-pagination__gap" aria-hidden="true">…</span>
        <?php endif; ?>
    <?php endif; ?>

    <?php for ($pagI = $pagDesde; $pagI <= $pagHasta; $pagI++) : ?>
        <?php if ($pagI === $pagPagina) : ?>
            <span class="admin-btn admin-btn--primary admin-btn--small" aria-current="page"><?php echo $pagI; ?></span>
        <?php else : ?>
            <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $pagEscape($pagUrl($pagI)); ?>"<?php echo $pagAttr; ?>><?php echo $pagI; ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($pagHasta < $pagTotal) : ?>
        <?php if ($pagHasta < $pagTotal - 1) : ?>
            <span class="admin-pagination__gap" aria-hidden="true">…</span>
        <?php endif; ?>
        <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $pagEscape($pagUrl($pagTotal)); ?>"<?php echo $pagAttr; ?>><?php echo $pagTotal; ?></a>
    <?php endif; ?>

    <?php if ($pagPagina < $pagTotal) : ?>
        <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $pagEscape($pagUrl($pagPagina + 1)); ?>"<?php echo $pagAttr; ?> rel="next">Siguiente</a>
    <?php else : ?>
        <span class="admin-btn admin-btn--disabled admin-btn--small">Siguiente</span>
    <?php endif; ?>
</nav>
<?php
endif;

unset($pagPagina, $pagTotal, $pagUrl, $pagEtiqueta, $pagReactiva, $pagEscape, $pagAttr,
      $pagRadio, $pagDesde, $pagHasta, $pagI);
?>
