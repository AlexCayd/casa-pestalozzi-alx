<?php
// Diálogo de UNA operación de UN artículo: entrada, merma, conteo físico o la
// configuración de su asociación. Cada uno trae sólo sus campos, su acción y, si el
// artículo está en varios almacenes propios, su propio selector: elegir el almacén
// muestra su saldo y su formulario. Los formularios son los mismos que antes vivían
// apilados en un único modal por artículo; sólo cambia en qué diálogo está cada uno.
// Espera $i, $tipoOperacion, $tituloOperacion, $introOperacion y $almacenesOperacion.
$modalId='articulo-'.(int)$i['articulo_id'].'-'.$tipoOperacion;
$variosAlmacenes=count($almacenesOperacion)>1;
$modalInicio($modalId,$tituloOperacion.' · '.$i['nombre'],false,'almacen-modal--operacion'); ?>
    <p class="almacen-modal__intro"><?php echo $esc(sprintf($introOperacion,$nombreContexto)); ?><?php if(!(int)$i['activo']): ?> Artículo inactivo: Global debe reactivarlo para registrar operaciones físicas.<?php endif; ?></p>
    <?php if($variosAlmacenes): ?>
    <div class="admin-field almacen-modal__picker"><label class="admin-field__label" for="<?php echo $modalId; ?>-almacen">Almacén físico</label><select id="<?php echo $modalId; ?>-almacen" data-warehouse-picker><option value="">Elige un almacén físico</option><?php foreach($almacenesOperacion as $asociacion): ?><option value="<?php echo (int)$asociacion['almacen_id']; ?>"><?php echo $esc($asociacion['nombre']); ?></option><?php endforeach; ?></select><p class="admin-field__hint" data-warehouse-empty>El artículo está en <?php echo count($almacenesOperacion); ?> almacenes de <?php echo $esc($nombreContexto); ?>. Elige uno para ver su saldo<?php echo $tipoOperacion==='configurar'?' y su configuración':' y registrar la operación'; ?>.</p></div>
    <?php endif; ?>
    <?php foreach($almacenesOperacion as $asociacion): ?>
    <div class="almacen-modal__panel" data-warehouse-panel="<?php echo (int)$asociacion['almacen_id']; ?>" <?php echo $variosAlmacenes?'hidden':''; ?>>
        <p class="almacen-modal__balance"><?php echo $esc($asociacion['nombre'].' · Saldo '.$asociacion['saldo'].' '.$i['unidad'].(!empty($asociacion['saldo_piezas'])?' · '.$asociacion['saldo_piezas']:'')); ?></p>
        <?php include __DIR__.'/_asociacion.php'; ?>
    </div>
    <?php endforeach; ?>
<?php $modalFin();
