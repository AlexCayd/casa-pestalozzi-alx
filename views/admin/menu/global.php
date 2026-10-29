<?php
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$linkPagina=static fn(int $p):string=>'?'.http_build_query(['q'=>$q,'negocio'=>$negocioFiltro,'uso'=>$usoFiltro,'page'=>$p]);
?>
<section class="admin-menu admin-page">
    <header class="admin-page__header"><div class="admin-page__intro">
        <h1 class="admin-page__title">Productos comerciales</h1>
        <p class="admin-page__subtitle">Cada producto es una identidad compartida: nombre, descripción e imagen. El precio, la categoría y la disponibilidad se administran en la oferta de cada negocio.</p>
    </div></header>
    <?php include __DIR__.'/../partials/alertas.php'; ?>
    <form class="admin-filters admin-catalogo-filters" method="GET">
        <label class="admin-field"><span class="admin-field__label">Buscar identidad</span><input type="search" name="q" value="<?= $esc($q) ?>" placeholder="Nombre, descripción o identificador" maxlength="100"></label>
        <label class="admin-field"><span class="admin-field__label">Negocio autorizado</span><select name="negocio"><option value="">Todos los autorizados</option><?php foreach($negociosConsulta as $slug): ?><option value="<?= $esc($slug) ?>" <?= $negocioFiltro===$slug?'selected':'' ?>><?= $slug==='panaderia'?'Panadería':'Restaurante' ?></option><?php endforeach; ?></select></label>
        <label class="admin-field"><span class="admin-field__label">Uso comercial consultable</span><select name="uso"><option value="">Todos</option><option value="con" <?= $usoFiltro==='con'?'selected':'' ?>>Con oferta</option><option value="sin" <?= $usoFiltro==='sin'?'selected':'' ?>>Sin oferta consultable</option></select></label>
        <button class="admin-btn admin-btn--primary">Buscar</button><a class="admin-btn admin-btn--secondary" href="<?= $esc($menuBase) ?>">Limpiar</a>
    </form>
    <p class="admin-field__hint"><?= (int)$totalIdentidades ?> identidades. Los negocios y recuentos de ofertas reflejan únicamente tus permisos de consulta.</p>
    <div class="admin-table-wrap"><table class="admin-table admin-catalogo-table">
        <caption class="admin-sr-only">Identidades comerciales compartidas y uso autorizado</caption>
        <thead><tr><th>Producto maestro</th><th>Identificador</th><th>Negocios con oferta</th><th>Ofertas consultables</th><th>Ficha maestra</th></tr></thead>
        <tbody><?php foreach ($identidades as $p): ?><tr>
            <td data-label="Producto maestro"><strong><?= $esc($p['nombre']) ?></strong><small class="admin-table__cell-sub"><?= $esc(mb_strimwidth((string)$p['descripcion'],0,120,'…')) ?></small></td>
            <td data-label="Identificador" class="admin-num">#<?= (int)$p['id'] ?></td>
            <td data-label="Negocios con oferta"><?php if(!$p['usos']): ?>Sin ofertas consultables<?php endif; ?><?php foreach($p['usos'] as $uso): ?><span class="admin-badge admin-badge--neutral"><?= $uso['slug']==='panaderia'?'Panadería':'Restaurante' ?></span> <?php endforeach; ?></td>
            <td data-label="Ofertas consultables" class="admin-num"><?= count($p['usos']) ?></td>
            <td data-label="Ficha maestra"><a class="admin-btn admin-btn--secondary" href="<?= $esc($menuBase) ?>/edit?id=<?= (int)$p['id'] ?>" aria-label="Abrir ficha de <?= $esc($p['nombre']) ?>">Abrir ficha</a></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php if (!$identidades): ?><p class="admin-empty">No hay identidades que coincidan. Cambia la búsqueda o limpia los filtros.</p><?php endif; ?>
    <nav class="admin-pagination" aria-label="Páginas de productos"><span>Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span>
        <?php if ($pagina>1): ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($linkPagina($pagina-1)) ?>">Anterior</a><?php endif; ?>
        <?php if ($pagina<$paginas): ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($linkPagina($pagina+1)) ?>">Siguiente</a><?php endif; ?>
    </nav>
</section>
