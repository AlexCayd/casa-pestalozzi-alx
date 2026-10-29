<?php
    $menuBase = $menuBase ?? '/admin/menu';
    $menuPuedeEditar = $menuPuedeEditar ?? true;
    $menuContexto = $menuContexto ?? 'restaurante';
    $menuRecetas = $menuRecetas ?? true;
    $menuVariantes = $menuVariantes ?? true;
    $menuCsrf = $menuCsrf ?? '';

/**
 * Editor de variantes de un platillo.
 *
 * El servidor pinta el armazón y deja los datos en JSON; los grupos, las
 * opciones, los ajustes de receta y la vista previa los dibuja
 * src/js/admin/menu/variantes.js, que guarda por JSON sin recargar. Sin JS no
 * hay editor —el aviso <noscript> lo dice—, pero el platillo, su receta y el
 * POS siguen funcionando: las variantes sólo se AÑADEN a lo que ya había.
 */
require_once __DIR__ . '/../partials/_icons.php';

$platillo = $platillo ?? null;
$editorVariantes = is_array($editorVariantes ?? null) ? $editorVariantes : null;
$categoriasMap = is_array($categoriasMap ?? null) ? $categoriasMap : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$precioBase = (float) ($platillo->precio ?? 0);
$categoria = $categoriasMap[(int) ($platillo->categoria_id ?? 0)] ?? '';
$datosJson = $editorVariantes !== null
    ? json_encode($editorVariantes + ['nombre' => (string) $platillo->nombre, 'precio' => $precioBase], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
    : 'null';
?>
<section
    class="admin-variantes admin-page"
    data-variantes-editor
    data-admin-csrf="<?php echo $h($adminCsrfToken ?? ''); ?>"
>
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Menú · Variantes</span>
            <h1 class="admin-page__title"><?php echo $h($platillo->nombre ?? ''); ?></h1>
            <p class="admin-page__subtitle">
                Las opciones que el mesero elige al tomar la orden. Cada una puede mover el precio y ajustar la receta, y el inventario se descuenta con la receta que resulta.
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
            <a class="admin-btn admin-btn--secondary admin-back-button" href="<?php echo htmlspecialchars($menuBase, ENT_QUOTES); ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Menú
            </a>
            <a class="admin-btn admin-btn--ghost" href="/admin/recetas/editar?id=<?php echo (int) ($platillo->id ?? 0); ?>">
                Receta base
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <noscript>
        <p class="admin-variantes__noscript">El editor de variantes necesita JavaScript.</p>
    </noscript>

    <?php if ($editorVariantes !== null) : ?>
        <script type="application/json" data-variantes-datos><?php echo $datosJson; ?></script>

        <div class="admin-variantes__errores" data-variantes-errores role="alert" hidden></div>

        <div class="admin-variantes__layout">
            <div class="admin-variantes__main">
                <?php /* Sin aria-live: el editor se repinta entero en cada cambio de
                         estructura, y una región viva aquí le leería al lector de
                         pantalla todos los grupos cada vez. Lo que hay que anunciar
                         —guardado, cambios pendientes— ya lo dice la barra. */ ?>
                <div class="admin-variantes__grupos" data-variantes-grupos></div>

                <section class="admin-variantes__nuevo" aria-labelledby="variantes-nuevo-titulo">
                    <h2 id="variantes-nuevo-titulo" class="admin-variantes__nuevo-titulo">Agregar un grupo de opciones</h2>
                    <p class="admin-variantes__nuevo-texto">Empieza en blanco o desde una plantilla; después ajusta nombres, precios y receta.</p>
                    <div class="admin-variantes__plantillas" data-variantes-plantillas></div>
                </section>
            </div>

            <?php /* data-scrollable: la columna tiene tope y scroll propio mientras va
                     pegada; motion.js sólo le cede la rueda frente a Lenis cuando
                     de verdad desborda. */ ?>
            <aside class="admin-variantes__aside" aria-labelledby="variantes-preview-titulo" data-scrollable>
                <div class="admin-variantes__preview admin-card">
                    <h2 id="variantes-preview-titulo" class="admin-variantes__preview-titulo">Así lo verá el mesero</h2>
                    <p class="admin-variantes__preview-ayuda">Toca las opciones para probar una combinación.</p>
                    <div class="admin-variantes__pos" data-variantes-pos></div>
                </div>
                <div class="admin-variantes__receta admin-card">
                    <h2 class="admin-variantes__preview-titulo">Receta con esta combinación</h2>
                    <div data-variantes-receta></div>
                </div>
            </aside>
        </div>

        <div class="admin-variantes__barra" data-variantes-barra>
            <p class="admin-variantes__estado" data-variantes-estado aria-live="polite">Sin cambios</p>
            <div class="admin-variantes__barra-acciones">
                <button type="button" class="admin-btn admin-btn--ghost" data-variantes-descartar disabled>Descartar</button>
                <button type="button" class="admin-btn admin-btn--primary" data-variantes-guardar disabled>
                    Guardar variantes
                    <kbd class="admin-variantes__kbd" aria-hidden="true">Ctrl S</kbd>
                </button>
            </div>
        </div>
    <?php endif; ?>
</section>
