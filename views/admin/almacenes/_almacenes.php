<section class="admin-panel admin-card">
    <h3>Almacenes físicos</h3><p><?php echo (int)$almacenes['total']; ?> almacenes autorizados. El propietario se define al crear el almacén; no se cambia desde su configuración.</p>
    <?php if(!$filasAlmacen): ?><div class="admin-empty"><strong>Sin almacenes para estos filtros</strong><p>La creación física corresponde a Global con facultad delegada.</p></div><?php else: ?>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Almacén</th><th>Propietario</th><th>Estado</th><th>Negativos</th><th>Configuración</th></tr></thead><tbody>
    <?php foreach($filasAlmacen as $a): $aId=(int)$a['almacen_id']; ?><tr><td><strong><?php echo $esc($a['nombre']); ?></strong><p><a href="<?php echo $esc($base.'?vista=existencias&almacen_id='.$aId); ?>">Ver artículos de este almacén</a></p></td><td><?php echo $esc(\Services\Security\Contextos::NOMBRES[$a['slug']]); ?></td><td><?php echo (int)$a['activo']?'Activo':'Inactivo'; ?></td><td><?php echo (int)$a['permitir_negativos']?'Permitidos':'No permitidos'; ?></td><td>
        <?php if($puedeEditar&&$a['slug']===$ctx): ?><details><summary>Configurar almacén</summary>
        <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
            <?php $campos('configurar_almacen',['almacen_id'=>$aId]); ?>
            <div class="admin-field"><label class="admin-field__label" for="almacen-<?php echo $aId; ?>-nombre">Nombre</label><input id="almacen-<?php echo $aId; ?>-nombre" name="nombre" maxlength="100" value="<?php echo $esc($a['nombre']); ?>" required></div>
            <div class="admin-field"><label><input type="checkbox" name="activo" value="1" <?php echo (int)$a['activo']?'checked':''; ?>> Almacén activo</label></div>
            <div class="admin-field"><label><input type="checkbox" name="permitir_negativos" value="1" <?php echo (int)$a['permitir_negativos']?'checked':''; ?>> Permitir existencias negativas</label><p class="admin-field__hint">Esta política corresponde solo a este almacén. No puede desactivarse si hay saldos negativos pendientes.</p></div>
            <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary">Guardar configuración</button></div>
        </form>
        <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" data-confirm-delete data-confirm-title="Eliminar almacén" data-confirm-message="<?php echo $esc('¿Eliminar '.$a['nombre'].'?'); ?>" data-confirm-require="<?php echo $esc($a['nombre']); ?>">
            <?php $campos('eliminar_almacen',['almacen_id'=>$aId]); ?><p class="admin-field__hint">Solo se elimina un almacén sin saldo, historial ni operaciones pendientes.</p><button type="submit" class="admin-btn admin-btn--danger">Eliminar almacén vacío</button>
        </form></details><?php else: ?><span><?php echo $a['slug']!==$ctx?'Consulta autorizada; la operación pertenece a su ámbito.':'Solo consulta'; ?></span><?php endif; ?>
    </td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php if($puedeCrearAlmacen): ?><section class="admin-panel admin-card"><h3>Crear almacén físico</h3><p>La asignación de propietario no concede permisos de operación sobre el negocio elegido.</p>
<form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('crear_almacen'); ?>
    <div class="admin-field"><label class="admin-field__label" for="nuevo-almacen-nombre">Nombre del almacén</label><input id="nuevo-almacen-nombre" name="nombre" maxlength="100" required></div>
    <div class="admin-field"><label class="admin-field__label" for="nuevo-almacen-propietario">Ámbito propietario</label><select id="nuevo-almacen-propietario" name="propietario" required><?php foreach(['global'=>'Global','restaurante'=>'Restaurante','panaderia'=>'Panadería'] as $slug=>$nombre): ?><option value="<?php echo $slug; ?>"><?php echo $nombre; ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label><input type="checkbox" name="permitir_negativos" value="1"> Permitir existencias negativas</label></div>
    <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary">Crear almacén</button></div>
</form></section><?php endif; ?>
