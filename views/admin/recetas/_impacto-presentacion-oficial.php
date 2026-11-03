<?php
$nombres=array_column($opcionesArticulos,'nombre','id');$versionesPorId=array_column($opcionesVersiones,null,'id');
$composicionTexto=static function(array $rows)use($nombres,$versionesPorId):string{
    $out=[];foreach($rows as $h){$v=$versionesPorId[$h['version_receta_id']??0]??null;$out[]=($nombres[$h['articulo_id']]??'Artículo').' · '.$h['cantidad'].' '.$h['unidad'].' · '.($h['modalidad']==='desglosar'?'Elaboración versión '.($v['version']??'seleccionada'):'Desde existencias');}return implode('; ',$out);
};
?>
<section class="admin-panel admin-card"><h2>Revisar cambios y destinos</h2>
<p>La publicación crea versiones nuevas en una sola operación. Un destino inválido cancela la publicación completa.</p>
<div class="admin-table-wrap" tabindex="0" role="region" aria-label="Cambios de presentación oficial"><table class="admin-table"><thead><tr><th>Campo</th><th>Oficial vigente</th><th>Propuesta</th></tr></thead><tbody>
<?php foreach(['nombre'=>'Nombre','precio'=>'Precio oficial','activo'=>'Disponibilidad','componentes'=>'Composición'] as $campo=>$titulo): ?>
<tr><th scope="row"><?= $esc($titulo) ?></th><?php foreach([$configuracionOficial,$raw] as $valores): ?><td><?php if($valores===null): ?>Sin publicación anterior<?php elseif($campo==='componentes'): ?><?= $esc($composicionTexto($valores['componentes'])) ?><?php elseif($campo==='activo'): ?><?= !empty($valores['activo'])?'Habilitada':'Fuera de venta' ?><?php else: ?><?= $esc($valores[$campo]) ?><?php endif; ?></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table></div>
<div class="admin-table-wrap" tabindex="0" role="region" aria-label="Impacto por negocio"><table class="admin-table"><thead><tr><th>Destino</th><th>Versión actual → nueva</th><th>Precio actual → efectivo</th><th>Composición efectiva</th><th>Personalizaciones conservadas</th></tr></thead><tbody>
<?php foreach($impactoOficial as $impacto): ?><tr><th scope="row"><?= $esc($impacto['negocio']==='panaderia'?'Panadería':'Restaurante') ?></th><td><?= $impacto['anterior']?(int)$impacto['anterior']['version']:'Sin versión' ?> → <?= (int)($impacto['anterior']['version']??0)+1 ?></td><td><?= $esc(($impacto['anterior']['precio']??'Sin precio').' → '.$impacto['nuevo']['precio']) ?></td><td><?= $esc($composicionTexto($impacto['nuevo']['componentes'])) ?></td><td><?= $esc($impacto['conservados']?implode(', ',$impacto['conservados']):'Se aplican los valores oficiales revisados') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
</section>
