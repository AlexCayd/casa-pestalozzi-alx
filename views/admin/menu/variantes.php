<?php
    $menuBase = $menuBase ?? '/admin/menu';
    $menuPuedeEditar = $menuPuedeEditar ?? true;
    $menuContexto = $menuContexto ?? 'restaurante';
    $menuRecetas = $menuRecetas ?? true;
    $menuVariantes = $menuVariantes ?? true;
    $menuCsrf = $menuCsrf ?? '';

/**
 * Editor de variantes de un producto.
 *
 * El servidor pinta el armazón y deja los datos en JSON; los grupos, las
 * opciones, los ajustes de receta y la vista previa los dibuja
 * src/js/admin/menu/variantes.js, que guarda por JSON sin recargar. Sin JS no
 * hay editor —el aviso <noscript> lo dice—, pero el producto, su receta y el
 * POS siguen funcionando: las variantes sólo se AÑADEN a lo que ya había.
 */
require_once __DIR__ . '/../partials/_icons.php';

$platillo = $platillo ?? null;
$editorVariantes = is_array($editorVariantes ?? null) ? $editorVariantes : null;
$categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$precioBase = (float) ($platillo->precio ?? 0);
$categoria = $categoriasMap[(int) ($platillo->categoria_id ?? 0)] ?? '';
// Reventa confirmada (arquitectura §16): sólo variantes de precio. El servidor
// rechaza los ajustes de artículos; aquí se dice antes y no se ofrecen.
$soloPrecio = ($platillo->tipo_venta ?? '') === 'reventa' && ($platillo->tipo_venta_estado ?? '') === 'confirmado';
if ($editorVariantes !== null) {
    $editorVariantes += ['nombre' => (string) $platillo->nombre, 'precio' => $precioBase, 'solo_precio' => $soloPrecio];
}
$variantesModo = 'pagina';
?>
<section
    class="admin-variantes admin-page"
    data-variantes-editor
    data-variantes-modo="pagina"
    data-variantes-url="<?= $menuContexto==='panaderia'?'/admin/panaderia/api/menu/variantes':'/admin/api/menu/variantes' ?>"
    data-admin-csrf="<?php echo $h($adminCsrfToken ?? ''); ?>"
>
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Menú · Variantes</span>
            <h1 class="admin-page__title"><?php echo $h($platillo->nombre ?? ''); ?></h1>
            <p class="admin-page__subtitle">
                <?php if ($soloPrecio) : ?>
                    <?= $menuContexto==='panaderia' ? 'Las opciones que se eligen al vender en caja.' : 'Las opciones que el mesero elige al tomar la orden.' ?> Es un producto de reventa: cada opción sólo cambia el nombre y el precio de la línea, y el artículo que se descuenta es siempre el mismo.
                <?php else : ?>
                <?= $menuContexto==='panaderia'?'Configura opciones, precios y ajustes de servicio. La venta en el POS de Panadería se habilitará en una etapa posterior.':'Las opciones que el mesero elige al tomar la orden. Cada una puede mover el precio y ajustar la receta, y el inventario se descuenta con la receta que resulta.' ?>
                <?php endif; ?>
            </p>
            <p class="admin-variantes__ficha">
                <span>Precio base <strong class="admin-num">$<?php echo number_format($precioBase, 2); ?></strong></span>
                <?php if ($categoria !== '') : ?>
                    <span aria-hidden="true">·</span>
                    <span><?php echo $h($categoria); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary admin-back-button" href="<?php echo htmlspecialchars($volverContextual??$menuBase, ENT_QUOTES); ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                <?= $volverContextual?'Volver al origen':'Menú' ?>
            </a>
            <?php if(!empty($composicionActual['url'])): ?><a class="admin-btn admin-btn--ghost" href="<?= $h($composicionActual['url']) ?>"><?= $h($composicionActual['accion']) ?></a><?php endif; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>
    <?php if(!empty($almacenesPendientes)&&!empty($composicionActual['url'])): ?><p class="admin-field__hint" role="status">Falta elegir el almacén de consumo de <?= (int)$almacenesPendientes ?> <?= (int)$almacenesPendientes===1?'artículo':'artículos' ?>. <a href="<?= $h(explode('#',$composicionActual['url'])[0].($composicionActual['modo']==='presentaciones'?'#origenes':'')) ?>">Configurar orígenes</a></p><?php endif; ?>

    <noscript>
        <p class="admin-variantes__noscript">El editor de variantes necesita JavaScript.</p>
    </noscript>

    <?php if ($editorVariantes !== null) : ?>
        <?php include __DIR__ . '/_variantes-editor.php'; ?>

        <div class="admin-variantes__barra" data-variantes-barra>
            <p class="admin-variantes__estado" data-variantes-estado aria-live="polite">Sin cambios</p>
            <div class="admin-variantes__barra-acciones">
                <button type="button" class="admin-btn admin-btn--ghost" data-variantes-descartar disabled <?= $menuPuedeEditar?'':'hidden' ?>>Descartar</button>
                <button type="button" class="admin-btn admin-btn--primary" data-variantes-guardar disabled <?= $menuPuedeEditar?'':'hidden' ?>>
                    Guardar variantes
                    <kbd class="admin-variantes__kbd" aria-hidden="true">Ctrl S</kbd>
                </button>
            </div>
        </div>
    <?php endif; ?>
</section>
