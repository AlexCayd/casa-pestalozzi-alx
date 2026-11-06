<?php
/**
 * Variantes dentro de «Editar platillo»: lo que el mesero elige, de un
 * vistazo, y el botón que abre el editor completo en un panel encima del
 * formulario (#variantes-modal, al final de menu/form.php).
 *
 * El resumen lo imprime el servidor; después de guardar en el panel lo
 * vuelve a dibujar variantes.js con el mismo marcado (pintarResumen), así que
 * los dos tienen que cambiar juntos.
 *
 * Espera $variantesFormulario = [
 *   'estado' => 'ok' | 'nuevo' | 'protegido' | 'error',
 *   'editor' => Variantes::datosEditor() + nombre/precio (sólo con 'ok'),
 *   'puedeEditar' => bool,
 * ]
 */
use Services\Menu\Variantes;

require_once __DIR__ . '/../partials/_icons.php';

$vrH = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$vrEstado = (string) ($variantesFormulario['estado'] ?? '');
$vrGrupos = $vrEstado === 'ok' ? ($variantesFormulario['editor']['grupos'] ?? []) : [];
$vrPuede = $vrEstado === 'ok' && !empty($variantesFormulario['puedeEditar']);
$vrOpciones = 0;
foreach ($vrGrupos as $vrG) {
    $vrOpciones += count($vrG['opciones'] ?? []);
}
?>
<?php /* id="variantes": destino de «N opciones» en el listado
         (edit?id=…#variantes). Con permiso, variantes.js abre el panel; sin
         él, el navegador al menos baja hasta aquí. */ ?>
<section class="admin-menu__variantes admin-form-grid__full" id="variantes" aria-labelledby="platillo-variantes-titulo">
    <div class="admin-menu__variantes-cabeza">
        <div>
            <h3 class="admin-menu__variantes-titulo" id="platillo-variantes-titulo">
                <?php echo admin_icon('variantes', 18); ?>
                Variantes
                <span class="admin-menu__variantes-cuenta admin-num" data-variantes-cuenta><?php
                    echo $vrGrupos ? count($vrGrupos) . ' ' . (count($vrGrupos) === 1 ? 'grupo' : 'grupos') . ' · ' . $vrOpciones . ' ' . ($vrOpciones === 1 ? 'opción' : 'opciones') : '';
                ?></span>
            </h3>
            <?php /* Dos guardados distintos en una misma pantalla: hay que decirlo,
                     o quien edita espera que «Guardar» del platillo se lleve también
                     las variantes (y al revés). */ ?>
            <p class="admin-field__hint">Lo que el mesero elige al tomar la orden —leche, tamaño, término—. En el POS cada grupo es un paso.<?php echo $vrPuede ? ' Se guardan en su panel, aparte del platillo.' : ''; ?></p>
        </div>
        <?php if ($vrPuede) : ?>
            <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="variantes-modal" aria-haspopup="dialog" data-variantes-abrir>
                <?php echo admin_icon($vrGrupos ? 'editar' : 'mas', 16, 'admin-btn__icon'); ?>
                <span data-variantes-abrir-texto><?php echo $vrGrupos ? 'Editar variantes' : 'Agregar variantes'; ?></span>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($vrEstado === 'nuevo') : ?>
        <p class="admin-vresumen__vacio">Guarda el platillo primero; después podrás agregarle variantes desde aquí.</p>
    <?php elseif ($vrEstado === 'protegido') : ?>
        <p class="admin-vresumen__vacio">Global protege el precio o la receta de este producto de la casa, así que sus variantes no se cambian aquí.</p>
    <?php elseif ($vrEstado === 'error') : ?>
        <p class="admin-vresumen__vacio">No fue posible cargar las variantes. Recarga la página; si sigue, revisa las tablas de variantes.</p>
    <?php else : ?>
        <div class="admin-vresumen" data-variantes-resumen>
            <?php if (!$vrGrupos) : ?>
                <p class="admin-vresumen__vacio">Sin variantes: se vende tal como está en la receta base, con un toque en el POS.</p>
            <?php else : ?>
                <ol class="admin-vresumen__grupos">
                    <?php foreach ($vrGrupos as $vrI => $vrG) :
                        $vrBase = null;
                        $vrActivas = 0;
                        foreach ($vrG['opciones'] as $vrO) {
                            if (!empty($vrO['activo'])) { $vrActivas++; }
                            if ($vrBase === null && !empty($vrO['predeterminada'])) { $vrBase = $vrO; }
                        } ?>
                        <li class="admin-vresumen__grupo">
                            <p class="admin-vresumen__nombre">
                                <span class="admin-vresumen__paso admin-num" aria-hidden="true"><?php echo str_pad((string) ($vrI + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                <span><?php echo $vrH($vrG['nombre']); ?></span>
                                <?php /* Con menos de dos opciones disponibles el POS no ofrece el
                                         paso (Variantes::paraPos): el resumen lo dice en vez de la regla. */ ?>
                                <?php if ($vrActivas < 2) : ?>
                                    <span class="admin-vresumen__aviso">No sale en el POS: necesita dos opciones disponibles</span>
                                <?php else : ?>
                                    <span class="admin-vresumen__regla"><?php echo $vrBase ? 'Si nadie elige: ' . $vrH($vrBase['nombre']) : 'El mesero siempre elige'; ?></span>
                                <?php endif; ?>
                            </p>
                            <ul class="admin-vresumen__opciones">
                                <?php foreach ($vrG['opciones'] as $vrO) :
                                    $vrIcono = Variantes::iconoEfectivo($vrO['icono'] ?? null, (string) $vrG['nombre'], (string) $vrO['nombre']);
                                    $vrSvg = $vrIcono ? admin_icon($vrIcono, 16) : '';
                                    $vrDelta = (float) ($vrO['precio_delta'] ?? 0); ?>
                                    <li class="admin-vresumen__op<?php echo !empty($vrO['predeterminada']) ? ' is-base' : ''; ?><?php echo empty($vrO['activo']) ? ' is-off' : ''; ?>">
                                        <span class="admin-vresumen__icono" aria-hidden="true"><?php echo $vrSvg !== '' ? $vrSvg : '<span class="admin-vicono-inicial">' . $vrH(mb_strtoupper(mb_substr(trim((string) $vrO['nombre']), 0, 1))) . '</span>'; ?></span>
                                        <span class="admin-vresumen__op-nombre"><?php echo $vrH($vrO['nombre']); ?></span>
                                        <?php if (!empty($vrO['predeterminada'])) : ?><span class="admin-vresumen__base">Base</span><?php endif; ?>
                                        <?php /* Mismo formato que la pastilla del POS (dineroCorto): $8 o $8.50, nunca $8.5. */
                                              $vrAbs = round(abs($vrDelta), 2); ?>
                                        <?php if ($vrAbs != 0.0) : ?><span class="admin-vresumen__delta admin-num"><?php echo $vrDelta > 0 ? '+' : '−'; ?>$<?php echo number_format($vrAbs, fmod($vrAbs, 1.0) == 0.0 ? 0 : 2, '.', ''); ?></span><?php endif; ?>
                                        <?php if (empty($vrO['activo'])) : ?><span class="admin-vresumen__off">Apagada</span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php unset($vrH, $vrEstado, $vrGrupos, $vrPuede, $vrOpciones, $vrG, $vrI, $vrO, $vrBase, $vrActivas, $vrIcono, $vrSvg, $vrDelta, $vrAbs); ?>
