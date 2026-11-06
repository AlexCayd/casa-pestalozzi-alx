<?php /* $versionVisible procede de una publicación inmutable ya autorizada. */ ?>
<section class="admin-panel admin-card">
    <h2>Versión <?= (int)$versionVisible['version'] ?> · Publicada · <?= !empty($versionVisible['global'])&&$ctx!=='global'?'Compartida por Global':((int)$versionVisible['id']===(int)($versiones[0]['id']??0)?'Más reciente':'Anterior') ?></h2>
    <p><?= $esc($versionVisible['created_at'].' · '.$versionVisible['procedencia']) ?></p>
    <p>Rendimiento: <strong><?= $esc($versionVisible['rendimiento'].' '.$versionVisible['unidad']) ?></strong></p>
    <div class="admin-table-wrap" role="region" aria-label="Composición de la versión <?= (int)$versionVisible['version'] ?>" tabindex="0">
        <table class="admin-table"><thead><tr><th>Artículo o preparación</th><th>Cantidad</th><th>Modalidad</th><th>Versión utilizada</th><th>Merma</th></tr></thead><tbody>
        <?php foreach($versionVisible['componentes'] as $componente): ?><tr>
            <td><?= $esc($componente['nombre']) ?></td><td><?= $esc($componente['cantidad'].' '.$componente['unidad']) ?></td>
            <td><?= $componente['modalidad']==='desglosar'?'Preparar usando su elaboración':'Desde existencias del artículo' ?></td>
            <td><?php if($componente['version_receta_id']): $origenVersion=$baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'version'=>$versionConsultada['id'],'comparar'=>$versionComparada['id']??null,'volver'=>$volverContextual])); ?><a href="<?= $esc($baseArticulos.'/editar?'.http_build_query(['id'=>$componente['articulo_id'],'version'=>$componente['version_receta_id'],'volver'=>$origenVersion])) ?>">Versión <?= (int)$componente['version_referenciada'] ?> · <?= $esc($componente['contexto_version']==='global'?'Global':ucfirst($componente['contexto_version'])) ?></a><?php else: ?>No ejecuta elaboración<?php endif; ?></td>
            <td><?= $esc($componente['merma']) ?>%</td>
        </tr><?php endforeach; ?></tbody></table>
    </div>
</section>
