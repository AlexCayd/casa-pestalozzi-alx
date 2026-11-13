<?php
/**
 * Cuerpo del editor de variantes: datos, errores, grupos, plantillas y la
 * columna de comprobación (vista del mesero y receta resultante).
 *
 * Lo comparten la página propia (menu/variantes.php) y el panel de «Editar
 * platillo» (menu/form.php). Quien lo incluye pone la raíz
 * [data-variantes-editor] con su token y su modo, y la barra de guardado; aquí
 * sólo va lo que src/js/admin/menu/variantes.js dibuja.
 *
 * Espera:
 *   $editorVariantes  Variantes::datosEditor() + nombre y precio del platillo.
 *   $variantesModo    'pagina' | 'modal' (sólo cambia ids para no repetirlos).
 */
$veH = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$veSufijo = ($variantesModo ?? 'pagina') === 'modal' ? '-modal' : '';
$veJson = json_encode($editorVariantes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script type="application/json" data-variantes-datos><?php echo $veJson; ?></script>

<div class="admin-variantes__errores" data-variantes-errores role="alert" hidden></div>

<div class="admin-variantes__layout">
    <?php /* data-scrollable: en el panel las dos columnas desplazan por su
             cuenta; motion.js les cede la rueda frente a Lenis sólo cuando de
             verdad desbordan. En la página, el principal no desborda y lo
             ignora. */ ?>
    <?php /* fieldset y no div: sin permiso de edición (`$menuPuedeEditar`)
             se deshabilita entero y conserva la lectura (origin/main). */ ?>
    <fieldset class="admin-variantes__main m2-fieldset" data-scrollable <?php echo ($menuPuedeEditar ?? true) ? '' : 'disabled'; ?>>
        <?php /* Sin aria-live: el editor se repinta entero en cada cambio de
                 estructura, y una región viva aquí le leería al lector de
                 pantalla todos los grupos cada vez. Lo que hay que anunciar
                 —guardado, cambios pendientes— ya lo dice la barra. */ ?>
        <div class="admin-variantes__grupos" data-variantes-grupos></div>

        <section class="admin-variantes__nuevo" aria-labelledby="variantes-nuevo-titulo<?php echo $veSufijo; ?>">
            <h2 id="variantes-nuevo-titulo<?php echo $veSufijo; ?>" class="admin-variantes__nuevo-titulo">Agregar un grupo de opciones</h2>
            <p class="admin-variantes__nuevo-texto">Empieza en blanco o desde una plantilla; después ajusta nombres, iconos, precios y receta.</p>
            <div class="admin-variantes__plantillas" data-variantes-plantillas></div>
        </section>
    </fieldset>

    <aside class="admin-variantes__aside" aria-labelledby="variantes-preview-titulo<?php echo $veSufijo; ?>" data-scrollable>
        <div class="admin-variantes__preview admin-card">
            <h2 id="variantes-preview-titulo<?php echo $veSufijo; ?>" class="admin-variantes__preview-titulo">Así lo verá el mesero</h2>
            <p class="admin-variantes__preview-ayuda">Un paso por grupo. Toca las opciones para probar una combinación.</p>
            <div class="admin-variantes__pos" data-variantes-pos></div>
        </div>
        <div class="admin-variantes__receta admin-card">
            <h2 class="admin-variantes__preview-titulo">Receta con esta combinación</h2>
            <div data-variantes-receta></div>
        </div>
    </aside>
</div>
<?php unset($veH, $veSufijo, $veJson); ?>
