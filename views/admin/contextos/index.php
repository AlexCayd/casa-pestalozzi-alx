<?php
$esc = static fn($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
$cantidad = static fn($valor): string => rtrim(rtrim(number_format((float) $valor, 3, '.', ','), '0'), '.');
$nombreContexto = \Services\Security\Contextos::NOMBRES[$contexto];
?>
<section class="admin-context-page" aria-labelledby="context-page-title">
    <header class="admin-context-page__header">
        <h1 id="context-page-title"><?php echo $esc($seccion === 'inicio' ? ($contexto === 'global' ? 'Administración global' : 'Panadería') : $title); ?></h1>
        <p><?php echo $contexto === 'global' ? 'Supervisa los negocios de Casa Pestalozzi desde un mismo lugar.' : 'Consulta el catálogo, las recetas y las existencias de demostración de Panadería.'; ?></p>
    </header>

    <?php if ($contexto === 'panaderia'): ?>
        <div class="admin-context-page__notice" role="note">
            <strong><?php echo $demo ? 'Datos de prueba · Solo consulta' : 'Demostración no disponible'; ?></strong>
            <p><?php echo $demo ? 'Las cantidades y los precios son ilustrativos. Esta información no registra ventas ni modifica el inventario del Restaurante.' : 'Los datos de prueba solo están disponibles en entornos de desarrollo o pruebas. La operación de Panadería todavía no está habilitada.'; ?></p>
        </div>
    <?php endif; ?>

    <?php if ($contexto === 'global' && $seccion === 'inicio'): ?>
        <div class="admin-card admin-context-page__businesses">
            <h2>Negocios</h2>
            <div class="admin-context-page__business"><div><h3>Restaurante</h3><p>Panel, mesas, menú, recetas e inventario actuales.</p></div><a class="admin-btn admin-btn--primary" href="/admin/contexto?contexto=restaurante">Entrar a Restaurante</a></div>
            <div class="admin-context-page__business"><div><h3>Panadería</h3><p>Entorno de demostración. Operación pendiente.</p></div><a class="admin-btn admin-btn--secondary" href="/admin/contexto?contexto=panaderia">Explorar Panadería</a></div>
        </div>
    <?php elseif ($contexto === 'global'): ?>
        <div class="admin-card admin-context-page__empty">
            <h2><?php echo $esc($title); ?>: pendiente</h2>
            <p><?php echo $seccion === 'inventario' ? 'Aquí podrás consultar existencias consolidadas y su desglose por negocio. Esta etapa no calcula saldos globales.' : 'Este espacio está reservado para la administración central. Su integración se realizará en una etapa posterior.'; ?></p>
            <?php if ($seccion === 'usuarios' || $seccion === 'configuracion'): ?>
                <a class="admin-btn admin-btn--secondary" href="/admin/<?php echo $seccion === 'usuarios' ? 'usuarios' : 'configuracion'; ?>">Abrir gestión actual · Restaurante</a>
            <?php endif; ?>
            <a href="/admin/global">Volver a Negocios</a>
        </div>
    <?php elseif ($seccion === 'inicio'): ?>
        <div class="admin-card admin-context-page__businesses">
            <h2>Explorar la demostración</h2>
            <?php foreach (['menu' => ['Menú', 'Productos, precios ilustrativos y disponibilidad.'], 'productos' => ['Recetas', 'Preparación directa, lotes y preparación intermedia.'], 'inventario' => ['Inventarios', 'Artículos, unidades, cantidades y estados de ejemplo.']] as $modulo => [$rotulo, $detalle]): ?>
                <?php if (!\Classes\Auth::puede($modulo)) { continue; } ?>
                <div class="admin-context-page__business"><div><h3><?php echo $rotulo; ?></h3><p><?php echo $detalle; ?></p></div><a class="admin-btn admin-btn--secondary" href="/admin/panaderia/<?php echo $modulo === 'productos' ? 'recetas' : $modulo; ?>">Consultar <?php echo mb_strtolower($rotulo); ?></a></div>
            <?php endforeach; ?>
        </div>
        <p class="admin-context-page__pending">Punto de venta, transferencias y producción están pendientes. Se habilitarán en etapas posteriores.</p>
    <?php elseif ($demo && $seccion === 'inventario'): ?>
        <div class="admin-card"><div class="admin-table-wrap" tabindex="0" role="region" aria-label="Inventario de prueba"><table class="admin-table admin-context-page__inventory">
            <caption>Existencias de prueba de Panadería</caption>
            <thead><tr><th scope="col">Artículo</th><th class="admin-context-page__unit" scope="col">Unidad</th><th scope="col">Existencia</th><th scope="col">Estado</th></tr></thead>
            <tbody><?php foreach ($fixture['articulos'] as $id => $articulo): ?>
                <tr><th scope="row"><?php echo $esc($articulo['nombre']); ?></th><td class="admin-context-page__unit"><?php echo $esc($articulo['unidad']); ?></td><td class="admin-context-page__number"><?php echo $cantidad($articulo['existencia']); ?><span class="admin-context-page__mobile-unit"> <?php echo $esc($articulo['unidad']); ?></span></td><td><?php echo $articulo['existencia'] <= 0 ? 'Sin existencias' : ($articulo['existencia'] < $articulo['minimo'] ? 'Bajo mínimo de prueba' : 'Disponible'); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div></div>
    <?php elseif ($demo && $seccion === 'menu'): ?>
        <p class="admin-context-page__scroll-hint">Desliza la tabla para consultar todas las columnas.</p>
        <div class="admin-card"><div class="admin-table-wrap" tabindex="0" role="region" aria-label="Menú de prueba, desplaza para ver todas las columnas"><table class="admin-table">
            <caption>Catálogo de prueba · Precios ilustrativos en MXN</caption>
            <thead><tr><th scope="col">Producto</th><th scope="col">Categoría</th><th scope="col">Precio</th><th scope="col">Disponibilidad</th><th scope="col">Preparación prevista</th></tr></thead>
            <tbody><?php foreach ($fixture['productos'] as $id => $producto): ?>
                <tr><th scope="row"><?php echo $esc($producto['nombre']); ?></th><td><?php echo $esc($producto['categoria']); ?></td><td class="admin-context-page__number">$<?php echo number_format($producto['precio'], 2); ?></td><td><?php echo $producto['disponible'] ? 'Disponible' : 'No disponible'; ?></td><td><?php echo $esc($producto['preparacion']); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div></div>
    <?php elseif ($demo && $seccion === 'productos'): ?>
        <?php foreach ($fixture['recetas'] as $id => $receta): ?>
            <details id="receta-<?php echo $esc($id); ?>" class="admin-card admin-context-page__recipe" open>
                <summary><strong><?php echo $esc($receta['nombre']); ?></strong><span>Rendimiento: <?php echo $cantidad($receta['rendimiento']['cantidad']) . ' ' . $esc($receta['rendimiento']['unidad']); ?></span></summary>
                <p><?php echo $esc($receta['detalle']); ?></p>
                <ul><?php foreach ($receta['ingredientes'] as $ingrediente): ?><li><?php echo $esc($fixture['articulos'][$ingrediente['articulo']]['nombre']) . ': ' . $cantidad($ingrediente['cantidad']) . ' ' . $esc($ingrediente['unidad']); ?><?php if (isset($ingrediente['receta'])): ?> <a href="#receta-<?php echo $esc($ingrediente['receta']); ?>">Ver preparación intermedia</a><?php endif; ?></li><?php endforeach; ?></ul>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
