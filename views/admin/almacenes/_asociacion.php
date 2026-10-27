<?php
$asocId=(int)$asociacion['almacen_id']; $artId=(int)$i['articulo_id'];
$prefijo='a-'.$asocId.'-'.$artId;
$permiteNegativos=false;
foreach($propios as $fisico) if((int)$fisico['almacen_id']===$asocId) $permiteNegativos=(bool)$fisico['permitir_negativos'];
?>
<details data-operation="configurar"><summary>Configurar asociación</summary>
<form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
    <?php $campos('configurar_asociacion',['almacen_id'=>$asocId,'articulo_id'=>$artId]); ?>
    <div class="admin-field"><label class="admin-field__label" for="<?php echo $prefijo; ?>-minimo">Mínimo físico (<?php echo $esc($i['unidad']); ?>)</label><input id="<?php echo $prefijo; ?>-minimo" name="minimo" type="number" min="0" step="0.001" value="<?php echo $esc($asociacion['minimo']); ?>" required inputmode="decimal"></div>
    <div class="admin-field"><label><input name="activo" type="checkbox" value="1" <?php echo $asociacion['asociacion_activa']?'checked':''; ?>> Asociación activa</label><p class="admin-field__hint">Para desactivarla, su saldo debe estar en cero.</p></div>
    <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--secondary">Guardar asociación</button></div>
</form>
</details>
<?php if(!(int)$i['activo']): ?><p class="admin-field__hint">Artículo inactivo; Global debe reactivarlo para registrar operaciones físicas.</p><?php endif; ?>
<?php if($asociacion['asociacion_activa'] && (int)$i['activo']): foreach(['entrada'=>'Registrar entrada','ajuste'=>'Registrar conteo físico','merma'=>'Registrar merma'] as $tipoOperacion=>$tituloOperacion): $opPrefijo=$prefijo.'-'.$tipoOperacion; ?>
<details data-operation="<?php echo $tipoOperacion; ?>"><summary><?php echo admin_icon($tipoOperacion==='entrada'?'mas':($tipoOperacion==='merma'?'menos':'editar')); ?> <?php echo $tituloOperacion; ?></summary>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
        <?php $campos($tipoOperacion,['almacen_id'=>$asocId,'articulo_id'=>$artId,'version'=>$asociacion['version'],'clave'=>'ui-'.bin2hex(random_bytes(16))]); ?>
        <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-cantidad"><?php echo $tipoOperacion==='ajuste'?'Cantidad contada':'Cantidad'; ?> (<?php echo $esc($i['unidad']); ?>)</label><input id="<?php echo $opPrefijo; ?>-cantidad" name="cantidad" type="number" step="0.001" <?php echo $tipoOperacion==='ajuste'?($permiteNegativos?'':'min="0"'):'min="0.001"'; ?> required inputmode="decimal" <?php echo $tipoOperacion==='ajuste'?'value="'.$esc($asociacion['saldo']).'"':''; ?>><p class="admin-field__hint"><?php echo $tipoOperacion==='ajuste'?'El conteo se rechaza si otra operación cambió el saldo desde que abriste esta página.':'Se registra una operación trazable; el saldo no se edita directamente.'; ?></p></div>
        <?php if($tipoOperacion==='entrada'): ?><div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-costo">Costo unitario de esta entrada (opcional)</label><input id="<?php echo $opPrefijo; ?>-costo" name="costo_unitario" type="number" min="0" step="0.0001" inputmode="decimal" placeholder="Costo maestro: <?php echo $esc($i['costo']); ?>"><p class="admin-field__hint">Queda guardado en el movimiento; no cambia el costo maestro.</p></div><?php endif; ?>
        <?php if($tipoOperacion==='merma'): ?><div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-motivo">Motivo de merma</label><select id="<?php echo $opPrefijo; ?>-motivo" name="motivo" required><?php foreach(\Services\Inventory\Inventario::MOTIVOS_MERMA as $motivo=>$nombreMotivo): ?><option value="<?php echo $esc($motivo); ?>"><?php echo $esc($nombreMotivo); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="admin-field"><label class="admin-field__label" for="<?php echo $opPrefijo; ?>-nota">Nota o referencia del documento (opcional)</label><input id="<?php echo $opPrefijo; ?>-nota" name="nota" type="text" maxlength="255"></div>
        <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary"><?php echo $tituloOperacion; ?></button></div>
    </form>
</details>
<?php endforeach; endif; ?>
