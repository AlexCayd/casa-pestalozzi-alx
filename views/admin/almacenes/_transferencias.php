<?php
use Services\Inventory\DecimalInventario;
use Services\Security\Contextos;
$estadosTransferencia=['solicitada'=>'Solicitada','autorizada'=>'Salida autorizada','enviada'=>'En tránsito','incidencia'=>'Incidencia pendiente','recibida'=>'Recibida','cerrada'=>'Cerrada con retorno','rechazada'=>'Rechazada','cancelada'=>'Cancelada'];
$accionesTransferencia=['autorizar'=>['Autorizar salida','Comprueba las existencias del origen. La autorización todavía no descuenta ni reserva stock.'],'enviar'=>['Confirmar envío','Descuenta del origen todas las cantidades solicitadas. El destino no tendrá stock disponible hasta registrar la recepción.'],'recibir'=>['Registrar recepción','Indica únicamente lo recibido ahora. Deja vacíos los artículos que no llegaron. El resto seguirá en tránsito con incidencia.'],'incidencia'=>['Registrar diferencia','Describe lo ocurrido, incluso si no llegó ningún artículo. No modifica existencias; habilita el retorno físico al origen.'],'retornar'=>['Registrar retorno','Indica únicamente lo que volvió físicamente al origen. No registra una entrada en el destino.'],'rechazar'=>['Rechazar solicitud','Cierra la solicitud sin modificar existencias.'],'cancelar'=>['Cancelar solicitud','Cierra la solicitud sin modificar existencias.']];
?>
<p class="almacen-modal__intro">Solicitar, enviar y recibir son pasos distintos. El tránsito no es existencia disponible. Esta consulta documental incluye ambos almacenes y conserva su propietario.</p>
<?php if($puedeEditar&&$activos): ?>
<details class="almacen-transfer-request"><summary>Solicitar transferencia</summary>
 <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid" data-transfer-request>
  <?php $campos('transferencia_solicitar',['volver_vista'=>'transferencias','clave'=>bin2hex(random_bytes(16))]); ?>
  <?php foreach(['origen_id'=>'Almacén origen','destino_id'=>'Almacén destino'] as $campo=>$label): ?>
  <div class="admin-field"><label for="transfer-<?php echo $campo; ?>"><?php echo $label; ?></label><select id="transfer-<?php echo $campo; ?>" name="<?php echo $campo; ?>" required><option value="">Elige un almacén</option><?php foreach($contrapartes as $w): ?><option value="<?php echo (int)$w['id']; ?>"><?php echo $esc($w['nombre'].' · '.Contextos::NOMBRES[$w['contexto']]); ?></option><?php endforeach; ?></select></div>
  <?php endforeach; ?>
  <p class="admin-field__hint almacen-transfer-wide">Uno de los almacenes debe pertenecer a <?php echo $esc($nombreContexto); ?>. Los artículos deben estar asociados en ambos. La solicitud no mueve stock.</p>
  <div class="almacen-transfer-lines almacen-transfer-wide" data-transfer-lines>
   <div class="almacen-transfer-line" data-transfer-line>
    <div class="admin-field"><label for="transfer-articulo-0">Artículo</label><select id="transfer-articulo-0" name="lineas[0][articulo_id]" required><option value="">Elige un artículo</option><?php foreach($catalogoTransferencias as $a): if(!(int)$a['activo'])continue; ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="transfer-cantidad-0">Cantidad solicitada</label><input id="transfer-cantidad-0" name="lineas[0][cantidad]" type="number" min="0.001" step="0.001" required inputmode="decimal"></div>
    <button type="button" class="admin-btn admin-btn--secondary" data-transfer-remove hidden>Quitar línea</button>
   </div>
  </div>
  <button type="button" class="admin-btn admin-btn--secondary" data-transfer-add><?php echo admin_icon('mas'); ?> Agregar artículo</button>
  <div class="admin-field almacen-transfer-wide"><label for="transfer-motivo">Motivo de la solicitud</label><textarea id="transfer-motivo" name="motivo" maxlength="230" required></textarea></div>
  <button type="submit" class="admin-btn admin-btn--primary">Registrar solicitud</button>
 </form>
</details>
<?php endif; ?>
<div class="almacen-section-head"><h4>Solicitudes e historial</h4><span><?php echo (int)($transferenciasTotal??0); ?> documentos · pendientes primero</span></div>
<?php if(!$transferencias): ?><p class="admin-empty">Todavía no hay transferencias en este ámbito. Las solicitudes nuevas aparecerán aquí sin modificar las existencias.</p><?php endif; ?>
<?php foreach($transferencias as $t): $tid=(int)$t['id']; ?>
<details class="almacen-transfer-document" <?php echo in_array($t['estado'],['solicitada','autorizada','enviada','incidencia'],true)?'open':''; ?>>
 <summary><strong>Transferencia #<?php echo $tid; ?></strong><span class="admin-badge"><?php echo $esc($estadosTransferencia[$t['estado']]??$t['estado']); ?></span><span><?php echo $esc($t['origen_nombre'].' → '.$t['destino_nombre']); ?></span></summary>
 <p><?php echo $esc($t['origen_nombre'].' · '.Contextos::NOMBRES[$t['origen_contexto']].' → '.$t['destino_nombre'].' · '.Contextos::NOMBRES[$t['destino_contexto']]); ?><small> · <?php echo $esc($t['creada_at']); ?></small></p>
 <div class="admin-table-wrap" data-scrollable><table class="admin-table"><caption class="almacen-transfer-caption">Cantidades de la transferencia #<?php echo $tid; ?>, en la unidad de cada artículo</caption><thead><tr><th>Artículo</th><th>Solicitado / enviado</th><th>Recibido / retornado</th><th>En tránsito</th></tr></thead><tbody>
 <?php foreach($t['lineas'] as $l): ?><tr><td data-label="Artículo"><?php echo $esc($l['nombre'].' · '.$l['unidad']); ?></td><td data-label="Solicitado / enviado" class="admin-num"><?php echo $esc($l['cantidad_solicitada'].' / '.$l['cantidad_enviada']); ?></td><td data-label="Recibido / retornado" class="admin-num"><?php echo $esc($l['cantidad_recibida'].' / '.$l['cantidad_retornada']); ?></td><td data-label="En tránsito" class="admin-num"><?php echo $esc($l['transito']); ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <?php foreach($t['acciones'] as $accion): [$titulo,$explicacion]=$accionesTransferencia[$accion]; ?>
 <details class="almacen-transfer-action"><summary><?php echo $esc($titulo); ?></summary>
  <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
   <?php $campos('transferencia_'.$accion,['volver_vista'=>'transferencias','clave'=>bin2hex(random_bytes(16)),'transferencia_id'=>$tid,'version'=>$t['version']]); ?>
   <p class="almacen-transfer-wide"><?php echo $esc($explicacion); ?></p>
   <?php if(in_array($accion,['recibir','retornar'],true)): foreach($t['lineas'] as $n=>$l): if(DecimalInventario::leer($l['transito'])===0)continue;$lid='transfer-'.$tid.'-'.$accion.'-'.$n; ?>
   <div class="admin-field"><label for="<?php echo $lid; ?>"><?php echo $esc($l['nombre'].' · '.$l['unidad']); ?></label><input type="hidden" name="lineas[<?php echo $n; ?>][articulo_id]" value="<?php echo (int)$l['articulo_id']; ?>"><input id="<?php echo $lid; ?>" type="number" name="lineas[<?php echo $n; ?>][cantidad]" min="0" step="0.001" max="<?php echo $esc($l['transito']); ?>" placeholder="0.000" inputmode="decimal"><p class="admin-field__hint">En tránsito: <?php echo $esc($l['transito'].' '.$l['unidad']); ?></p></div>
   <?php endforeach; endif; ?>
   <div class="admin-field almacen-transfer-wide"><label for="transfer-nota-<?php echo $tid.'-'.$accion; ?>">Motivo o diferencia observada</label><textarea id="transfer-nota-<?php echo $tid.'-'.$accion; ?>" name="motivo" maxlength="230" required></textarea></div>
   <button type="submit" class="admin-btn admin-btn--primary"><?php echo $esc($titulo); ?></button>
  </form>
 </details>
 <?php endforeach; ?>
 <details class="almacen-transfer-history"><summary>Historial de la transferencia #<?php echo $tid; ?></summary><ol><?php foreach($t['historial'] as $h): ?><li><strong><?php echo $esc($estadosTransferencia[$h['estado_nuevo']]??$h['estado_nuevo']); ?></strong> · <?php echo $esc($h['fecha'].' · '.($h['actor_nombre']??'Actor histórico')); ?><p><?php echo $esc($h['motivo']); ?></p></li><?php endforeach; ?></ol></details>
</details>
<?php endforeach; ?>
<?php $tp=(int)($transferenciasPagina??1);$tmax=max(1,(int)ceil(($transferenciasTotal??0)/100)); ?>
<nav class="admin-pagination" aria-label="Paginación de transferencias"><span>Página <?php echo $tp; ?> de <?php echo $tmax; ?></span><?php if($tp>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('transferencias',['transferencia_pagina'=>$tp-1])); ?>">Anterior</a><?php endif;if($tp<$tmax): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('transferencias',['transferencia_pagina'=>$tp+1])); ?>">Siguiente</a><?php endif; ?></nav>
