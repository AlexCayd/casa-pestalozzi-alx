<?php
use Services\Inventory\DecimalInventario;
use Services\Security\Contextos;
/*
 * Bandeja de faltantes (§16): lo que una venta no encontró en su almacén de consumo.
 * La pregunta que contesta es «¿de dónde salió?»; por eso cada faltante muestra
 * cuánto queda por declarar, de qué venta viene y los almacenes que pueden cubrirlo.
 * Sólo los almacenes propios traen saldo: el de otro negocio nunca se expone.
 */
$faltantes=$faltantes??[];
$estadosFaltante=['pendiente'=>['Pendiente','warning'],'en_confirmacion'=>['En confirmación','info']];
$estadosAsignacion=['aplicada'=>['Aplicada','success'],'en_confirmacion'=>['En confirmación','info'],'rechazada'=>['Rechazada','danger'],'anulada'=>['Anulada','neutral']];
?>
<p class="almacen-modal__intro">Cuando una venta no encuentra saldo suficiente, la comanda sale igual: se descuenta lo que había y el resto queda aquí. Declara de qué almacén salió. Los almacenes de <?php echo $esc($nombreContexto); ?> se aplican al confirmar; los de otro negocio quedan en confirmación hasta que su dueño la acepta.</p>
<?php include __DIR__.'/../partials/alertas.php'; ?>
<?php if(!$faltantes): ?>
<div class="admin-empty"><strong>No hay faltantes por conciliar</strong><p>Si una venta sale sin saldo suficiente, aparecerá aquí para declarar su origen.</p></div>
<?php endif; ?>
<?php foreach($faltantes as $f):
    $fid=(int)$f['id']; $unidad=(string)$f['unidad']; $estado=(string)$f['estado'];
    $restanteMil=DecimalInventario::leer((string)$f['restante']);
    $contenido=isset($f['contenido_pieza'])&&$f['contenido_pieza']!==null&&$f['contenido_pieza']!==''?(string)$f['contenido_pieza']:null;
    $restantePiezas=$contenido!==null?DecimalInventario::enPiezas((string)$f['restante'],$contenido,$unidad):null;
    $esMigracion=($f['origen']??'venta')==='migracion';
    [$estadoTexto,$estadoTono]=$estadosFaltante[$estado]??[$estado,'neutral'];
    // Nombres propios del parcial: index.php comparte ámbito y ya usa $propios.
    $origenes=$f['origenes']??[]; $origenesPropios=array_values(array_filter($origenes,static fn($o)=>!empty($o['propio']))); $origenesAjenos=array_values(array_filter($origenes,static fn($o)=>empty($o['propio'])));
    ?>
<details class="almacen-faltante" <?php echo $estado==='pendiente'?'open':''; ?>>
  <summary>
    <span class="almacen-faltante__head">
      <strong><?php echo $esc($f['articulo_nombre']); ?></strong>
      <span class="admin-status admin-status--<?php echo $estadoTono; ?>"><?php echo $esc($estadoTexto); ?></span>
    </span>
    <span class="almacen-faltante__resumen"><?php echo $esc($f['almacen_nombre']); ?> · <?php if($restanteMil>0): ?>por conciliar <strong class="admin-num"><?php echo $esc($f['restante'].' '.$unidad); ?></strong><?php else: ?>todo declarado, en confirmación<?php endif; ?></span>
  </summary>
  <div class="almacen-faltante__body">
    <p class="almacen-faltante__origen"><?php if($esMigracion): ?>Saldo negativo convertido al retirar existencias negativas.<?php else: ?>Venta: <?php echo $esc(($f['item_nombre']??'Item vendido').' · Ticket #'.(int)$f['ticket_id'].' · '.($f['item_fecha']??$f['creado_at'])); ?><?php endif; ?></p>
    <dl class="almacen-faltante__cifras">
      <div><dt>Faltó al vender</dt><dd class="admin-num"><?php echo $esc($f['cantidad'].' '.$unidad); ?></dd></div>
      <div><dt>Conciliado</dt><dd class="admin-num"><?php echo $esc($f['cantidad_conciliada'].' '.$unidad); ?></dd></div>
      <div><dt>En confirmación</dt><dd class="admin-num"><?php echo $esc($f['cantidad_en_confirmacion'].' '.$unidad); ?></dd></div>
      <div class="is-key"><dt>Por conciliar</dt><dd class="admin-num"><?php echo $esc($f['restante'].' '.$unidad); ?><?php if($restantePiezas): ?><small><?php echo $esc($restantePiezas); ?></small><?php endif; ?></dd></div>
    </dl>
    <?php if(!empty($f['asignaciones'])): ?>
    <h4 class="almacen-faltante__subtitulo">Orígenes declarados</h4>
    <ul class="almacen-faltante__asignaciones">
      <?php foreach($f['asignaciones'] as $s): [$sTexto,$sTono]=$estadosAsignacion[$s['estado']]??[$s['estado'],'neutral']; ?>
      <li>
        <span><?php echo $esc($s['almacen_nombre'].' · '.(Contextos::NOMBRES[$s['contexto']]??$s['contexto'])); ?><?php if(!empty($s['transferencia_id'])): ?><small>Transferencia #<?php echo (int)$s['transferencia_id']; ?></small><?php endif; ?></span>
        <span class="admin-num"><?php echo $esc($s['cantidad'].' '.$unidad); ?></span>
        <span class="admin-status admin-status--<?php echo $sTono; ?>"><?php echo $esc($sTexto); ?></span>
        <?php if($s['estado']==='rechazada'&&!empty($s['motivo'])): ?><p class="almacen-faltante__motivo">Motivo: <?php echo $esc($s['motivo']); ?></p><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if($restanteMil>0&&empty($f['cubierto_por_propios'])): ?>
    <p class="almacen-faltante__nota"><?php echo admin_icon('alerta',16); ?><span><strong>Ningún almacén propio cubre este faltante.</strong> Entre los almacenes de <?php echo $esc($nombreContexto); ?> hay <?php echo $esc($f['disponible_propio'].' '.$unidad); ?>.<?php echo $origenesAjenos?' Puedes pedir el resto a otro negocio, que lo confirma.':' Queda pendiente hasta registrar una entrada de este artículo.'; ?></span></p>
    <?php endif; ?>

    <?php if($estado==='en_confirmacion'||$restanteMil<=0): ?>
    <p class="admin-field__hint">Todo lo pendiente ya tiene origen. Se concilia cuando el dueño de cada almacén confirme su parte; si la rechaza, vuelve aquí como pendiente.</p>
    <?php elseif(!$puedeEditar): ?>
    <p class="admin-field__hint">Conciliar requiere permiso de edición de Almacenes en <?php echo $esc($nombreContexto); ?>.</p>
    <?php elseif(!$origenes): ?>
    <p class="admin-field__hint">Ningún almacén activo tiene este artículo asociado. Asócialo y registra una entrada para conciliarlo.</p>
    <?php else: ?>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="almacen-faltante__form" data-faltante-form data-faltante-restante="<?php echo $esc($f['restante']); ?>" data-faltante-unidad="<?php echo $esc($unidad); ?>"
      data-confirm-delete data-confirm-variant="warning" data-confirm-eyebrow="Conciliar faltante" data-confirm-title="<?php echo $esc('Origen de '.$f['articulo_nombre']); ?>" data-confirm-description="<?php echo $esc('Por conciliar '.$f['restante'].' '.$unidad.' en '.$f['almacen_nombre']); ?>" data-confirm-primary="Conciliar" data-confirm-consequence="Lo de almacenes propios se descuenta al confirmar. Lo de otro negocio queda en confirmación hasta que su dueño lo acepte.">
      <?php $campos('conciliar_faltante',['volver_vista'=>'faltantes','faltante_id'=>$fid,'version'=>$f['version'],'clave'=>bin2hex(random_bytes(16))]); ?>
      <fieldset class="almacen-faltante__origenes" aria-describedby="faltante-<?php echo $fid; ?>-ayuda">
        <legend>¿De dónde salió?</legend>
        <p class="admin-field__hint" id="faltante-<?php echo $fid; ?>-ayuda">Reparte <?php echo $esc($f['restante'].' '.$unidad); ?> entre uno o varios almacenes. La suma debe ser exacta; deja vacíos los que no usas.</p>
        <?php $n=0; foreach([['Almacenes de '.$nombreContexto.' · se aplican al confirmar',$origenesPropios],['Otros negocios · su dueño confirma',$origenesAjenos]] as [$grupoTitulo,$grupo]): if(!$grupo)continue; ?>
        <p class="admin-eyebrow almacen-faltante__grupo"><?php echo $esc($grupoTitulo); ?></p>
        <?php foreach($grupo as $o): $oid=(int)$o['almacen_id']; $campo='faltante-'.$fid.'-origen-'.$n; $propio=!empty($o['propio']);
          $saldoMil=$propio&&isset($o['saldo'])?DecimalInventario::leer((string)$o['saldo'],3,true):null; $sinSaldo=$propio&&$saldoMil!==null&&$saldoMil<=0; ?>
        <div class="almacen-origen<?php echo $sinSaldo?' is-empty':''; ?>">
          <<?php echo $sinSaldo?'span':'label for="'.$campo.'"'; ?>><span class="almacen-origen__nombre"><?php echo $esc($o['nombre']); ?></span><small><?php
            echo $esc(Contextos::NOMBRES[$o['contexto']]??$o['contexto']).' · ';
            echo $propio?($sinSaldo?'Sin saldo disponible':$esc('Saldo '.$o['saldo'].' '.$unidad)):'Requiere su confirmación'; ?></small></<?php echo $sinSaldo?'span':'label'; ?>>
          <?php if($sinSaldo): /* Sin campos: un almacen_id sin cantidad llegaría al servidor como fila y se rechazaría. */ ?>
          <span class="almacen-origen__vacio">No disponible</span>
          <?php else: ?>
          <input type="hidden" name="asignaciones[<?php echo $n; ?>][almacen_id]" value="<?php echo $oid; ?>">
          <span class="almacen-origen__campo"><input id="<?php echo $campo; ?>" name="asignaciones[<?php echo $n; ?>][cantidad]" type="number" min="0" step="0.001" inputmode="decimal" placeholder="0.000"<?php echo $propio&&isset($o['saldo'])?' max="'.$esc($o['saldo']).'"':''; ?> data-faltante-cantidad data-origen-nombre="<?php echo $esc($o['nombre']); ?>" data-origen-propio="<?php echo $propio?'1':'0'; ?>"><span class="almacen-origen__unidad" aria-hidden="true"><?php echo $esc($unidad); ?></span></span>
          <?php endif; ?>
        </div>
        <?php $n++; endforeach; endforeach; ?>
      </fieldset>
      <p class="almacen-faltante__suma" data-faltante-suma role="status" aria-live="polite">Por repartir: <?php echo $esc($f['restante'].' '.$unidad); ?>.</p>
      <div class="almacen-faltante__acciones"><button type="submit" class="admin-btn admin-btn--primary">Conciliar faltante</button></div>
    </form>
    <?php endif; ?>
  </div>
</details>
<?php endforeach; ?>
