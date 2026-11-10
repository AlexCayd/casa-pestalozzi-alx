<?php
use Services\Inventory\DecimalInventario;
// Un solo formulario: la operación $tipoOperacion del artículo $i en el almacén
// $asociacion. Lo incluye _operacion.php, una vez por almacén del diálogo. Los campos
// enviados son los mismos que cuando las cuatro operaciones convivían en un modal.
$asocId=(int)$asociacion['almacen_id']; $artId=(int)$i['articulo_id'];
$prefijo='a-'.$asocId.'-'.$artId;
// Cancelar antes que la acción, como en el resto de diálogos del panel; en móvil el
// componente compartido los apila con la acción arriba.
$accionesOperacion=static fn(string $etiqueta): string=>'<div class="admin-modal__actions admin-form-grid__full"><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button><button type="submit" class="admin-btn admin-btn--primary">'.$esc($etiqueta).'</button></div>';
if($tipoOperacion==='configurar'): ?>
<form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
    <?php $campos('configurar_asociacion',['almacen_id'=>$asocId,'articulo_id'=>$artId]); ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $prefijo; ?>-minimo">Mínimo físico (<?php echo $esc($i['unidad']); ?>)</label><input id="<?php echo $prefijo; ?>-minimo" name="minimo" type="number" min="0" step="0.001" value="<?php echo $esc($asociacion['minimo']); ?>" required inputmode="decimal"><p class="admin-field__hint">Por debajo de esta cifra el almacén marca «Bajo mínimo».</p></div>
    <div class="admin-field"><label><input name="activo" type="checkbox" value="1" <?php echo $asociacion['asociacion_activa']?'checked':''; ?>> Asociación activa</label><p class="admin-field__hint">Para desactivarla, su saldo debe estar en cero.</p></div>
    <?php echo $accionesOperacion('Guardar asociación'); ?>
</form>
<?php return; endif;
// Artículos con contenido por pieza (750 ml por botella) se capturan como se cuentan
// en bodega: piezas completas más lo suelto. El servidor convierte a la unidad base.
$contenidoPieza=isset($i['contenido_pieza'])&&$i['contenido_pieza']!==null&&$i['contenido_pieza']!==''&&DecimalInventario::leer((string)$i['contenido_pieza'])>0?(string)$i['contenido_pieza']:null;
$contenidoTexto=$contenidoPieza!==null?rtrim(rtrim($contenidoPieza,'0'),'.').' '.$i['unidad']:'';
$saldoPiezas=$asociacion['saldo_piezas']??null;
$saldoLectura=$asociacion['saldo'].' '.$i['unidad'].($saldoPiezas?' ('.$saldoPiezas.')':'');
$opPrefijo=$prefijo.'-'.$tipoOperacion;
?>
<form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid" data-stock-operation="<?php echo $tipoOperacion; ?>" data-stock-saldo="<?php echo $esc($asociacion['saldo']); ?>" data-stock-unidad="<?php echo $esc($i['unidad']); ?>"<?php if($contenidoPieza!==null): ?> data-stock-contenido="<?php echo $esc($contenidoPieza); ?>"<?php endif; ?> data-confirm-delete data-confirm-eyebrow="Operación física" data-confirm-title="<?php echo $esc($tituloOperacion.' · '.$i['nombre']); ?>" data-confirm-description="<?php echo $esc($asociacion['nombre'].' · Saldo actual '.$saldoLectura); ?>" data-confirm-primary="Confirmar operación" data-confirm-consequence="Se registrará un movimiento trazable en este almacén. El servidor volverá a verificar permisos y saldo.">
    <?php $campos($tipoOperacion,['almacen_id'=>$asocId,'articulo_id'=>$artId,'version'=>$asociacion['version'],'clave'=>'ui-'.bin2hex(random_bytes(16))]); ?>
    <?php if($contenidoPieza!==null): ?>
    <fieldset class="almacen-piezas admin-form-grid__full">
        <legend class="admin-field__label">Cantidad</legend>
        <div class="almacen-piezas__campos">
            <div class="admin-field"><label for="<?php echo $opPrefijo; ?>-piezas">Piezas de <?php echo $esc($contenidoTexto); ?></label><input id="<?php echo $opPrefijo; ?>-piezas" name="piezas" type="number" min="0" step="1" inputmode="numeric"></div>
            <div class="admin-field"><label for="<?php echo $opPrefijo; ?>-cantidad">Más suelto (<?php echo $esc($i['unidad']); ?>)</label><input id="<?php echo $opPrefijo; ?>-cantidad" name="cantidad" type="number" min="0" step="0.001" inputmode="decimal"></div>
        </div>
        <p class="admin-field__hint">Se registra en <?php echo $esc($i['unidad']); ?>: piezas por <?php echo $esc($contenidoTexto); ?> más lo suelto. Sin piezas, lo suelto es el total.</p>
    </fieldset>
    <?php else: ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-cantidad">Cantidad (<?php echo $esc($i['unidad']); ?>)</label><input id="<?php echo $opPrefijo; ?>-cantidad" name="cantidad" type="number" step="0.001" min="0.001" required inputmode="decimal"><p class="admin-field__hint">Se registra una operación trazable; el saldo no se edita directamente.</p></div>
    <?php endif; ?>
    <?php if($tipoOperacion==='entrada'):
        // Proveedor opcional: liga la entrada a su historial. Los que surten este
        // artículo van primero, el preferente marcado; su costo de referencia lo
        // propone inventario.js si el campo de costo está vacío.
        $provEntrada=($proveedoresPorContexto??[])[$asociacion['slug']??$ctx]??($proveedoresEntrada??[]);
        $provDisponibles=$provEntrada['proveedores']??[];
        if($provDisponibles):
            $surten=[];foreach(($provEntrada['porArticulo'][$artId]??[]) as $fila)$surten[(int)$fila['proveedor_id']]=$fila;
            usort($provDisponibles,static fn($x,$y)=>[isset($surten[(int)$y['id']])?(int)$surten[(int)$y['id']]['preferente']+1:0] <=> [isset($surten[(int)$x['id']])?(int)$surten[(int)$x['id']]['preferente']+1:0] ?: strcmp($x['nombre'],$y['nombre'])); ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-proveedor">Proveedor (opcional)</label><select id="<?php echo $opPrefijo; ?>-proveedor" name="proveedor_id" data-entrada-proveedor><option value="">Sin proveedor</option><?php foreach($provDisponibles as $prov): $ref=$surten[(int)$prov['id']]??null; ?><option value="<?php echo (int)$prov['id']; ?>"<?php if($ref): ?> data-costo="<?php echo $esc($ref['costo']); ?>"<?php endif; ?>><?php echo $esc($prov['nombre'].($ref?((int)$ref['preferente']?' · preferente':' · surte este artículo'):'')); ?></option><?php endforeach; ?></select><p class="admin-field__hint">Queda en el historial del proveedor. Con proveedor, el costo es obligatorio.</p></div>
    <?php endif; ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-costo">Costo de esta entrada<span data-costo-opcional> (opcional)</span></label><input id="<?php echo $opPrefijo; ?>-costo" name="costo_unitario" type="number" min="0" step="0.0001" inputmode="decimal" placeholder="Costo maestro: <?php echo $esc($i['costo'].' por '.$i['unidad']); ?>"><p class="admin-field__hint">Queda guardado en el movimiento; no cambia el costo maestro.</p></div>
    <?php if($contenidoPieza!==null): ?>
    <fieldset class="admin-pills almacen-costo-por">
        <legend class="admin-pills__legend">El costo es</legend>
        <div class="admin-pills__group">
            <label class="admin-pill"><input type="radio" name="costo_por" value="unidad" checked><span class="admin-pill__body"><span class="admin-pill__title">Por <?php echo $esc($i['unidad']); ?></span></span></label>
            <label class="admin-pill"><input type="radio" name="costo_por" value="pieza"><span class="admin-pill__body"><span class="admin-pill__title">Por pieza de <?php echo $esc($contenidoTexto); ?></span></span></label>
        </div>
    </fieldset>
    <?php endif; endif; ?>
    <?php if($tipoOperacion==='merma'): ?><div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-motivo">Motivo de merma</label><select id="<?php echo $opPrefijo; ?>-motivo" name="motivo" required><?php foreach(\Services\Inventory\Inventario::MOTIVOS_MERMA as $motivo=>$nombreMotivo): ?><option value="<?php echo $esc($motivo); ?>"><?php echo $esc($nombreMotivo); ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-nota">Nota o referencia del documento (opcional)</label><input id="<?php echo $opPrefijo; ?>-nota" name="nota" type="text" maxlength="255"></div>
    <p class="admin-field__hint admin-form-grid__full" data-stock-preview aria-live="polite">Escribe una cantidad para consultar el resultado estimado.</p>
    <?php echo $accionesOperacion($tituloOperacion); ?>
</form>
