<?php
use Services\Inventory\DecimalInventario;
use Services\Security\Contextos;
// «Salida autorizada» sólo aparece en documentos anteriores al retiro de ese paso.
$estadosTransferencia=['solicitada'=>'Solicitada','autorizada'=>'Salida autorizada','enviada'=>'Enviada','incidencia'=>'Incidencia pendiente','recibida'=>'Recibida','cerrada'=>'Cerrada con retorno','rechazada'=>'Rechazada','cancelada'=>'Cancelada'];
// Una regularización no sigue el flujo ordinario: el dueño del origen sólo confirma
// (envío, recepción y consumo en una operación) o rechaza, y el rechazo devuelve esa
// parte del faltante a pendiente en el negocio que la pidió.
$accionesRegularizacion=['confirmar'=>['Confirmar regularización','Una venta salió sin saldo suficiente y el negocio destino declaró que lo faltante salió de tu almacén. Al confirmar se descuenta de tu almacén y se registran envío y recepción en una sola operación.'],'rechazar'=>['Rechazar regularización','Esa parte del faltante vuelve a quedar pendiente en el negocio destino, con tu motivo. No modifica existencias.']];
$accionesTransferencia=['enviar'=>['Enviar','Descuenta del origen todas las cantidades solicitadas. El destino las tendrá disponibles al registrar la recepción.'],'recibir'=>['Registrar recepción','Las cantidades vienen completas: corrige sólo lo que no llegó. Si falta algo, queda como diferencia para que el origen registre el retorno.'],'incidencia'=>['Registrar diferencia','Describe lo ocurrido, incluso si no llegó ningún artículo. No modifica existencias; habilita el retorno físico al origen.'],'retornar'=>['Registrar retorno','Indica únicamente lo que volvió físicamente al origen. No registra una entrada en el destino.'],'rechazar'=>['Rechazar solicitud','Cierra la solicitud sin modificar existencias.'],'cancelar'=>['Cancelar solicitud','Cierra la solicitud sin modificar existencias.']];
// Peso visual de cada disparador. «Avanza» mueve el documento al siguiente paso;
// «cierra» termina la solicitud sin mover existencias (su envío va en rojo). Ninguno es
// primario: el único primario a la vista del modal es «Nueva transferencia».
$tonoAccion=['enviar'=>'avanza','recibir'=>'avanza','retornar'=>'avanza','confirmar'=>'avanza','incidencia'=>'neutra','rechazar'=>'cierra','cancelar'=>'cierra'];
$iconoAccion=['enviar'=>'enviar','recibir'=>'caja','incidencia'=>'alerta','retornar'=>'flecha-izquierda','confirmar'=>'check','rechazar'=>'cerrar','cancelar'=>'detener'];
// El estado se nombra siempre con palabra; el color de la insignia sólo lo refuerza.
$tonoEstado=['solicitada'=>'info','autorizada'=>'info','enviada'=>'info','incidencia'=>'warning','recibida'=>'success','cerrada'=>'neutral','rechazada'=>'neutral','cancelada'=>'neutral'];
?>
<?php // Cuerpo desplazable del modal: la cabecera (título y cerrar) queda fija encima y el
// historial más largo se recorre aquí dentro. data-scrollable deja que motion.js le
// suelte la rueda a Lenis sólo cuando de verdad desborda. ?>
<div class="admin-modal__body almacen-modal__body" data-scrollable>
<?php $esSuper=!empty($transferenciasSuper); ?>
<p class="almacen-modal__intro">Entre almacenes del mismo negocio la transferencia se aplica en un solo paso. Entre negocios distintos pasa por solicitada, enviada y recibida, y lo enviado no está disponible hasta recibirlo.<?php if($esSuper): ?> Como superadministrador, tus transferencias se aplican en un solo paso entre cualquier par de almacenes.<?php endif; ?></p>
<?php // El resultado de la última operación (solicitud o acción sobre un documento) se lee
// antes que nada: el modal se reabre con el foco arriba, y abajo quedaría fuera de vista.
if($vista==='transferencias')include __DIR__.'/../partials/alertas.php'; ?>
<?php if($puedeEditar&&$activos): ?>
<?php // Acción principal del modal, por encima del historial y con forma de botón primario.
// Sigue siendo details/summary: el navegador anuncia expandido/contraído, funciona sin
// JavaScript y no saca a la persona del historial (un diálogo propio cerraría éste).
// Abierta, el botón cede el peso a «Registrar solicitud» (inventario.scss). ?>
<?php // Un solo formulario para los tres casos. El servidor decide el resultado (un paso,
// enviada o solicitada) y inventario.js sólo anticipa en la pista y el botón qué pasará
// con la pareja elegida, para que nadie espere un paso que no existe. ?>
<details class="almacen-transfer-request"><summary class="admin-btn admin-btn--primary almacen-transfer-request__toggle"><?php echo admin_icon('mas',16); ?><span>Nueva transferencia</span><?php echo admin_icon('chevron-abajo',16,'almacen-transfer-request__chevron'); ?></summary>
 <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid" data-transfer-request data-asociaciones="<?php echo $esc(json_encode($asociacionesTransferencia??new stdClass(),JSON_THROW_ON_ERROR)); ?>" data-contexto="<?php echo $esc($ctx); ?>" data-super="<?php echo $esSuper?'1':'0'; ?>">
  <?php $campos('transferencia_solicitar',['volver_vista'=>'transferencias','clave'=>bin2hex(random_bytes(16))]); ?>
  <?php foreach(['origen_id'=>'Almacén origen','destino_id'=>'Almacén destino'] as $campo=>$label): ?>
  <div class="admin-field"><label for="transfer-<?php echo $campo; ?>"><?php echo $label; ?></label><select id="transfer-<?php echo $campo; ?>" name="<?php echo $campo; ?>" required><option value="">Elige un almacén</option><?php foreach($contrapartes as $w): ?><option value="<?php echo (int)$w['id']; ?>" data-contexto="<?php echo $esc($w['contexto']); ?>"><?php echo $esc($w['nombre'].' · '.Contextos::NOMBRES[$w['contexto']]); ?></option><?php endforeach; ?></select></div>
  <?php endforeach; ?>
  <?php // La pista dice qué pasará al enviar (un paso, enviada o solicitada); el botón la
  // cita con aria-describedby para que quien llega con Tab oiga la consecuencia, no
  // sólo «Enviar». Su caja reserva dos renglones: cambiar de pareja no mueve el resto. ?>
  <p id="transfer-pista" class="admin-field__hint almacen-transfer-wide almacen-transfer-hint" data-transfer-hint aria-live="polite"><?php echo $esSuper?'Se aplica en un solo paso entre cualquier par de almacenes.':'Uno de los almacenes debe pertenecer a '.$esc($nombreContexto).'. Dentro del mismo negocio se aplica en un solo paso; entre negocios, el destino registra la recepción.'; ?> Si el destino aún no maneja un artículo, queda asociado al registrar.</p>
  <div class="almacen-transfer-lines almacen-transfer-wide" data-transfer-lines>
   <div class="almacen-transfer-line" data-transfer-line>
    <div class="admin-field"><label for="transfer-articulo-0">Artículo</label><select id="transfer-articulo-0" name="lineas[0][articulo_id]" required><option value="">Elige un artículo</option><?php foreach($catalogoTransferencias as $a): if(!(int)$a['activo'])continue; ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label for="transfer-cantidad-0">Cantidad</label><input id="transfer-cantidad-0" name="lineas[0][cantidad]" type="number" min="0.001" step="0.001" required inputmode="decimal"></div>
    <button type="button" class="admin-btn admin-btn--secondary" data-transfer-remove hidden>Quitar línea</button>
   </div>
  </div>
  <?php // Lo escribe inventario.js al elegir almacenes y artículos: qué artículo quedará
  // asociado al destino (aviso) o falta en el origen (data-tono="error": el servidor lo
  // rechazará). Va debajo de las líneas, no encima: al aparecer no desplaza el selector
  // que se acaba de usar. Sin JavaScript el servidor lo informa después. ?>
  <p id="transfer-asociacion" class="admin-field__hint almacen-transfer-wide almacen-transfer-hint almacen-transfer-asociacion" data-transfer-asociacion role="status" hidden></p>
  <button type="button" class="admin-btn admin-btn--secondary" data-transfer-add><?php echo admin_icon('mas'); ?> Agregar artículo</button>
  <div class="admin-field almacen-transfer-wide"><label for="transfer-motivo">Motivo</label><textarea id="transfer-motivo" name="motivo" maxlength="230" required></textarea></div>
  <button type="submit" class="admin-btn admin-btn--primary" data-transfer-submit aria-describedby="transfer-pista transfer-asociacion"><?php echo $esSuper?'Transferir ahora':'Registrar transferencia'; ?></button>
 </form>
</details>
<?php elseif($puedeEditar): ?>
<p class="admin-field__hint almacen-transfer-request-hint">Para registrar una transferencia, <?php echo $esc($nombreContexto); ?> necesita al menos un almacén propio activo.</p>
<?php endif; ?>
<?php $docsTotal=(int)($transferenciasTotal??0); ?>
<div class="almacen-section-head almacen-transfer-head"><h4>Transferencias e historial</h4><?php if($docsTotal>0): ?><span><span class="admin-num"><?php echo $docsTotal; ?></span> <?php echo $docsTotal===1?'documento':'documentos'; ?> · pendientes primero</span><?php endif; ?></div>
<?php // Sin documentos, el vacío explica qué aparecerá aquí; si se puede pedir, remite al
// botón de arriba en vez de repetir la acción. Una página fuera de rango no es «vacío».
if(!$transferencias): ?><p class="admin-empty"><?php echo $docsTotal>0?'No hay documentos en esta página.':($puedeEditar&&$activos?'Todavía no hay transferencias en este ámbito. Las que registres con «Nueva transferencia» aparecerán aquí, con su estado e historial.':'Todavía no hay transferencias en este ámbito.'); ?></p><?php endif; ?>
<?php foreach($transferencias as $t): $tid=(int)$t['id']; $esRegularizacion=($t['tipo']??'ordinaria')==='regularizacion'; ?>
<details class="almacen-transfer-document" <?php echo in_array($t['estado'],['solicitada','autorizada','enviada','incidencia'],true)?'open':''; ?>>
 <summary><strong><?php echo $esRegularizacion?'Regularización de faltante':'Transferencia'; ?> #<?php echo $tid; ?></strong><span class="admin-badge admin-badge--<?php echo $tonoEstado[$t['estado']]??'neutral'; ?>"><?php echo $esc($estadosTransferencia[$t['estado']]??$t['estado']); ?></span><span class="almacen-transfer-route"><?php echo $esc($t['origen_nombre']); ?><span class="almacen-transfer-route__sep"><?php echo admin_icon('flecha-derecha',14); ?><span class="admin-visually-hidden"> hacia </span></span><?php echo $esc($t['destino_nombre']); ?></span><?php echo admin_icon('chevron-abajo',16,'almacen-transfer-document__chevron'); ?></summary>
 <p class="almacen-transfer-route"><?php echo $esc($t['origen_nombre'].' · '.Contextos::NOMBRES[$t['origen_contexto']]); ?><span class="almacen-transfer-route__sep"><?php echo admin_icon('flecha-derecha',14); ?><span class="admin-visually-hidden"> hacia </span></span><?php echo $esc($t['destino_nombre'].' · '.Contextos::NOMBRES[$t['destino_contexto']]); ?><small> · <?php echo $esc($t['creada_at']); ?></small></p>
 <?php // Entre negocios cada lado tiene que saber a quién le toca. Las acciones que llegan
 // del servidor dicen si el paso es de quien mira; si no, se nombra al negocio que lo
 // debe dar. Sin esta línea, el lado que espera veía un documento sin botones (o sólo
 // «Cancelar») y no sabía si faltaba algo suyo.
 $siguiente='';
 if(!$esRegularizacion){
  $acc=$t['acciones'];$nO=$esc(Contextos::NOMBRES[$t['origen_contexto']]??$t['origen_contexto']);$nD=$esc(Contextos::NOMBRES[$t['destino_contexto']]??$t['destino_contexto']);
  if(in_array($t['estado'],['solicitada','autorizada'],true))$siguiente=in_array('enviar',$acc,true)?'enviarla o rechazarla.':$nO.', dueño del origen, la envía.';
  elseif($t['estado']==='enviada')$siguiente=in_array('recibir',$acc,true)?'registrar la recepción; las cantidades vienen precargadas con lo enviado.':$nD.' registra la recepción.';
  elseif($t['estado']==='incidencia')$siguiente=in_array('retornar',$acc,true)?'registrar lo que regrese a tu almacén.':(in_array('recibir',$acc,true)?$nO.' registra lo que regrese; si llega el resto, regístralo aquí.':$nO.' registra lo que regrese.');
 }
 if($siguiente!==''): ?><p class="almacen-transfer-note almacen-transfer-next"><?php echo $t['estado']==='incidencia'?'Recepción incompleta. ':''; ?><strong>Siguiente paso:</strong> <?php echo $siguiente; ?></p><?php endif; ?>
 <?php if($esRegularizacion): ?><p class="almacen-transfer-note">Origen declarado para un faltante de venta en <?php echo $esc($t['destino_nombre']); ?>.<?php if($t['estado']==='solicitada'&&!$t['acciones']): ?> Espera la confirmación de <?php echo $esc(Contextos::NOMBRES[$t['origen_contexto']]); ?>, dueño del origen.<?php endif; ?></p><?php endif; ?>
 <div class="admin-table-wrap" data-scrollable><table class="admin-table"><caption class="almacen-transfer-caption">Cantidades de la transferencia #<?php echo $tid; ?>, en la unidad de cada artículo</caption><thead><tr><th>Artículo</th><th>Solicitado / enviado</th><th>Recibido / retornado</th><th>En tránsito</th></tr></thead><tbody>
 <?php foreach($t['lineas'] as $l): ?><tr><td data-label="Artículo"><?php echo $esc($l['nombre'].' · '.$l['unidad']); ?></td><td data-label="Solicitado / enviado" class="admin-num"><?php echo $esc($l['cantidad_solicitada'].' / '.$l['cantidad_enviada']); ?></td><td data-label="Recibido / retornado" class="admin-num"><?php echo $esc($l['cantidad_recibida'].' / '.$l['cantidad_retornada']); ?></td><td data-label="En tránsito" class="admin-num"><?php echo $esc($l['transito']); ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <?php // Las acciones del documento forman una barra de botones; cada una despliega su
 // formulario debajo de la barra, a todo el ancho. `name` las vuelve excluyentes: abrir
 // una pliega la otra, y el formulario visible siempre es el del botón hundido.
 if($t['acciones']): ?>
 <div class="almacen-transfer-actions" role="group" aria-label="Acciones de <?php echo $esRegularizacion?'la regularización':'la transferencia'; ?> #<?php echo $tid; ?>">
 <?php endif; ?>
 <?php foreach($t['acciones'] as $accion): [$titulo,$explicacion]=($esRegularizacion?$accionesRegularizacion:$accionesTransferencia)[$accion]; $tono=$tonoAccion[$accion]??'neutra'; ?>
 <details class="almacen-transfer-action almacen-transfer-action--<?php echo $tono; ?>" name="transferencia-<?php echo $tid; ?>-accion"><summary class="admin-btn admin-btn--secondary almacen-transfer-action__toggle"><?php echo admin_icon($iconoAccion[$accion]??'editar',16); ?><span><?php echo $esc($titulo); ?></span><?php echo admin_icon('chevron-abajo',16,'almacen-transfer-action__chevron'); ?></summary>
  <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
   <?php $campos('transferencia_'.$accion,['volver_vista'=>'transferencias','clave'=>bin2hex(random_bytes(16)),'transferencia_id'=>$tid,'version'=>$t['version']]); ?>
   <p class="almacen-transfer-wide"><?php echo $esc($explicacion); ?></p>
   <?php if(in_array($accion,['recibir','retornar'],true)): foreach($t['lineas'] as $n=>$l): if(DecimalInventario::leer($l['transito'])===0)continue;$lid='transfer-'.$tid.'-'.$accion.'-'.$n; ?>
   <div class="admin-field"><label for="<?php echo $lid; ?>"><?php echo $esc($l['nombre'].' · '.$l['unidad']); ?></label><input type="hidden" name="lineas[<?php echo $n; ?>][articulo_id]" value="<?php echo (int)$l['articulo_id']; ?>"><input id="<?php echo $lid; ?>" type="number" name="lineas[<?php echo $n; ?>][cantidad]" min="0" step="0.001" max="<?php echo $esc($l['transito']); ?>"<?php // Recibir llega precargado con todo lo enviado: el caso normal es un clic; retornar se declara a mano.
   if($accion==='recibir'): ?> value="<?php echo $esc($l['transito']); ?>"<?php endif; ?> placeholder="0.000" inputmode="decimal"><p class="admin-field__hint">En tránsito: <?php echo $esc($l['transito'].' '.$l['unidad']); ?></p></div>
   <?php endforeach; endif; ?>
   <?php // El servidor exige motivo en toda acción; confirmar trae uno editable por omisión.
   $etiquetaMotivo=$esRegularizacion?($accion==='confirmar'?'Nota de confirmación':'Motivo del rechazo'):'Motivo o diferencia observada'; ?>
   <div class="admin-field almacen-transfer-wide"><label for="transfer-nota-<?php echo $tid.'-'.$accion; ?>"><?php echo $etiquetaMotivo; ?></label><textarea id="transfer-nota-<?php echo $tid.'-'.$accion; ?>" name="motivo" maxlength="230" required><?php echo $esRegularizacion&&$accion==='confirmar'?'Confirmado':''; ?></textarea></div>
   <button type="submit" class="admin-btn <?php echo $tono==='cierra'?'admin-btn--danger':'admin-btn--primary'; ?>"><?php echo $esc($titulo); ?></button>
  </form>
 </details>
 <?php endforeach; ?>
 <?php if($t['acciones']): ?></div><?php endif; ?>
 <details class="almacen-transfer-history"><summary class="admin-btn admin-btn--secondary admin-btn--small almacen-transfer-history__toggle"><?php echo admin_icon('reloj',16); ?><span>Historial de la transferencia #<?php echo $tid; ?></span><?php echo admin_icon('chevron-abajo',16,'almacen-transfer-history__chevron'); ?></summary><ol><?php foreach($t['historial'] as $h): ?><li><strong><?php echo $esc($estadosTransferencia[$h['estado_nuevo']]??$h['estado_nuevo']); ?></strong> · <?php echo $esc($h['fecha'].' · '.($h['actor_nombre']??'Actor histórico')); ?><p><?php echo $esc($h['motivo']); ?></p></li><?php endforeach; ?></ol></details>
</details>
<?php endforeach; ?>
<?php // Con una sola página, «Página 1 de 1» era ruido al pie del historial. La paginación
// es navegación del historial: botones secundarios pequeños, nunca al nivel de la acción.
$tp=(int)($transferenciasPagina??1);$tmax=max(1,(int)ceil($docsTotal/100));
if($tmax>1): ?>
<nav class="admin-pagination almacen-transfer-pages" aria-label="Paginación de transferencias"><span>Página <?php echo $tp; ?> de <?php echo $tmax; ?></span><?php if($tp>1): ?><a class="admin-btn admin-btn--secondary admin-btn--small" rel="prev" href="<?php echo $esc($url('transferencias',['transferencia_pagina'=>$tp-1])); ?>">Anterior</a><?php endif;if($tp<$tmax): ?><a class="admin-btn admin-btn--secondary admin-btn--small" rel="next" href="<?php echo $esc($url('transferencias',['transferencia_pagina'=>$tp+1])); ?>">Siguiente</a><?php endif; ?></nav>
<?php endif; ?>
</div>
