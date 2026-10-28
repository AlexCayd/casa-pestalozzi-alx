<?php $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?>
<section class="admin-menu admin-page">
    <header class="admin-page__header"><div class="admin-page__intro">
        <h1 class="admin-page__title">Productos comerciales</h1>
        <p class="admin-page__subtitle">Identidades compartidas y ofertas de los negocios autorizados. Los precios se administran en cada negocio.</p>
    </div></header>
    <?php include __DIR__.'/../partials/alertas.php'; ?>
    <form class="admin-filters" method="GET">
        <label class="admin-field"><span class="admin-field__label">Buscar producto</span><input type="search" name="q" value="<?= $esc($q) ?>" maxlength="100"></label>
        <button class="admin-btn admin-btn--secondary">Buscar</button>
    </form>
    <?php if (!$identidades) : ?><p class="admin-empty">No hay productos en esta página.</p><?php endif; ?>
    <div class="admin-menu__catalogo">
        <?php foreach ($identidades as $p) : ?>
        <article class="admin-card">
            <h2><?= $esc($p['nombre']) ?> <span class="admin-badge admin-badge--neutral">#<?= (int)$p['id'] ?></span></h2>
            <p><?= $esc($p['descripcion']) ?></p>
            <?php if (!$p['usos']) : ?><p class="admin-field__hint">Sin ofertas consultables con tus permisos actuales.</p><?php endif; ?>
            <ul><?php foreach ($p['usos'] as $uso) : ?><li>
                <strong><?= $uso['slug']==='panaderia'?'Panadería':'Restaurante' ?></strong> · <?= $esc($uso['categoria']) ?> · $<?= $esc($uso['precio']) ?> · <?= $uso['activo']?'Disponible':'Retirada / no disponible' ?>
            </li><?php endforeach; ?></ul>
            <a class="admin-btn admin-btn--secondary" href="<?= $esc($menuBase) ?>/edit?id=<?= (int)$p['id'] ?>"><?= $identidadEditable?'Editar datos maestros':'Consultar datos maestros' ?></a>
        </article>
        <?php endforeach; ?>
    </div>
    <nav class="admin-actions" aria-label="Páginas de productos">
        <?php if ($pagina>1) : ?><a class="admin-btn admin-btn--secondary" href="?<?= $esc(http_build_query(['q'=>$q,'page'=>$pagina-1])) ?>">Anterior</a><?php endif; ?>
        <?php if (count($identidades)===30) : ?><a class="admin-btn admin-btn--secondary" href="?<?= $esc(http_build_query(['q'=>$q,'page'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?>
    </nav>
</section>
