<?php
require_once __DIR__.'/../partials/_icons.php';
$esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$fecha=static fn($v):string=>$v?date('d/m/Y',strtotime((string)$v)):'—';
$hora=static fn($v):string=>$v?date('d/m/Y H:i',strtotime((string)$v)):'—';
$p=$proveedor;$actual=$contextoPrecio;$ultimo=$actual['ultimo_evento']??null;
$dueno=Services\Security\Contextos::NOMBRES[$p['slug']??$ctx]??$nombreContexto;
$historialPrecios=$historialPrecios??['filas'=>[],'pagina'=>1,'paginas'=>1,'total'=>0,'limite'=>25];
$graficaPrecio=$graficaPrecio??null;$comparacionPrecios=$comparacionPrecios??[];
$baseHistorial=$base.'/historial?'.http_build_query(['id'=>(int)($p['id']??0),'articulo'=>(int)($actual['articulo_id']??0)]);
$baseArticulos=$base.'/ficha?'.http_build_query(['id'=>(int)($p['id']??0),'vista'=>'articulos']);
?>
<section class="admin-inventario admin-page almacen-proveedores proveedor-historial">
  <header class="admin-page__header">
    <div class="admin-page__intro">
      <h2 class="admin-page__title"><?php echo $actual?$esc($actual['articulo']):'Historial de precios'; ?></h2>
      <p class="admin-page__subtitle"><?php echo $esc($p['nombre']); ?> · Proveedor de <?php echo $esc($dueno); ?><?php if($dueno!==$nombreContexto): ?> · consulta desde <?php echo $esc($nombreContexto); ?><?php endif; ?><?php if((int)($p['activo']??0)!==1): ?> · Proveedor inactivo<?php endif; ?></p>
    </div>
    <div class="almacen-actions almacen-page-actions"><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($baseArticulos); ?>"><?php echo admin_icon('flecha-izquierda'); ?> Volver a Artículos</a></div>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>

  <?php if($actual): ?>
  <section class="proveedor-precios__resumen" aria-label="Referencia y último registro">
    <p><strong>Proveedor:</strong> <?php echo $esc($p['nombre']); ?> <span aria-hidden="true">·</span> <strong>Artículo:</strong> <?php echo $esc($actual['articulo']); ?> <span aria-hidden="true">·</span> <strong>Unidad vigente:</strong> <?php echo $esc($actual['unidad_actual']); ?></p>
    <dl class="proveedor-precios__resumen-datos">
      <div><dt>Precio de referencia vigente</dt><dd><?php if($actual['precio_actual']!==null): ?><span class="admin-num">$<?php echo $esc($actual['precio_actual']); ?></span> por <?php echo $esc($actual['unidad_actual']); ?><?php else: ?><span class="almacen-unassociated">Sin oferta vigente; asociación retirada</span><?php endif; ?></dd></div>
      <div><dt>Último precio registrado</dt><dd><?php if($ultimo): ?><span class="admin-num">$<?php echo $esc($ultimo['precio_nuevo']); ?></span> por <?php echo $esc($ultimo['unidad_base_snapshot']); ?> · <?php echo $esc($hora($ultimo['created_at'])); ?> · <?php echo $esc($ultimo['usuario_nombre_snapshot']); ?><?php else: ?>Sin eventos de precio<?php endif; ?></dd></div>
      <div><dt>Precio anterior comparable</dt><dd><?php if($ultimo&&$ultimo['comparable_con_vigente']&&$ultimo['tipo']==='cambio'&&$ultimo['precio_anterior']!==null): ?><span class="admin-num">$<?php echo $esc($ultimo['precio_anterior']); ?></span> por <?php echo $esc($actual['unidad_actual']); ?><?php elseif(!$ultimo): ?>Aún no hay una referencia anterior<?php elseif(!$ultimo['comparable_con_vigente']): ?>No hay precio anterior comparable en <?php echo $esc($actual['unidad_actual']); ?>; el último registro corresponde a <?php echo $esc($ultimo['unidad_base_snapshot']); ?>.<?php else: ?>El último registro inicia una base; no tiene precio anterior comparable.<?php endif; ?></dd></div>
    </dl>
  </section>

  <?php if($graficaPrecio!==null): ?>
  <figure class="proveedor-precios__grafica">
    <figcaption><?php echo $graficaPrecio['recortada']?'Últimos 40 eventos comparables':'Evolución de '.$graficaPrecio['eventos'].' eventos'; ?> · <?php echo $esc($fecha($graficaPrecio['desde'])); ?> a <?php echo $esc($fecha($graficaPrecio['hasta'])); ?> · unidad de la serie: <?php echo $esc($graficaPrecio['unidad']); ?>.</figcaption>
    <?php if($graficaPrecio['unidad']!==$actual['unidad_actual']): ?><p class="proveedor-precios__nota-grafica">La unidad vigente es <?php echo $esc($actual['unidad_actual']); ?>. Esta serie histórica corresponde a <?php echo $esc($graficaPrecio['unidad']); ?> y no se compara entre unidades.</p><?php endif; ?>
    <div class="proveedor-precios__grafica-area" role="img" aria-label="Precio histórico por <?php echo $esc($graficaPrecio['unidad']); ?> desde <?php echo $esc($fecha($graficaPrecio['desde'])); ?> hasta <?php echo $esc($fecha($graficaPrecio['hasta'])); ?>.">
      <div class="proveedor-precios__eje-y"><?php foreach($graficaPrecio['eje_y'] as $tick): ?><span>$<?php echo $esc($tick['valor']); ?></span><?php endforeach; ?></div>
      <div class="proveedor-precios__plot"><svg viewBox="58 14 292 102" aria-hidden="true" preserveAspectRatio="none">
        <?php foreach($graficaPrecio['eje_y'] as $tick): ?><line class="proveedor-precios__rejilla" x1="58" y1="<?php echo $esc($tick['y']); ?>" x2="350" y2="<?php echo $esc($tick['y']); ?>"/><?php endforeach; ?>
        <line class="proveedor-precios__eje-linea" x1="58" y1="14" x2="58" y2="116"/><line class="proveedor-precios__eje-linea" x1="58" y1="116" x2="350" y2="116"/>
        <polyline points="<?php echo $esc($graficaPrecio['puntos']); ?>"/>
      </svg><div class="proveedor-precios__eje-x"><?php foreach($graficaPrecio['eje_x'] as $tick): $xPct=max(0,min(100,((float)$tick['x']-58)/292*100)); ?><span data-anchor="<?php echo $esc($tick['ancla']); ?>" style="left:<?php echo $esc(number_format($xPct,2,'.','')); ?>%"><?php echo $esc($tick['etiqueta']); ?></span><?php endforeach; ?></div></div>
    </div>
  </figure>
  <?php else: ?><p class="proveedor-precios__nota-grafica"><?php echo $historialPrecios['total']===0?'Todavía no hay registros de precio para esta asociación.':'No hay tres eventos comparables en la unidad de la serie actual. La tabla conserva el historial.'; ?></p><?php endif; ?>

  <section class="admin-panel almacen-list proveedor-precios" aria-labelledby="historial-precios-titulo">
    <div class="almacen-section-head"><div><h3 id="historial-precios-titulo">Eventos de precio</h3><p>Las líneas base se registran hoy; no atribuyen al pasado una fecha o persona que no quedó guardada.</p></div></div>
    <?php if(!$historialPrecios['filas']): ?><div class="admin-empty"><strong>Sin historial de precio</strong><p>Al guardar por primera vez la referencia, se registrará la fecha y la persona que la capturó.</p></div>
    <?php else: ?><div class="admin-table-wrap" data-scrollable><table class="admin-table proveedor-precios__tabla">
      <thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Unidad</th><th class="admin-table__num">Precio anterior</th><th class="admin-table__num">Precio nuevo</th><th class="admin-table__num">Diferencia</th><th class="admin-table__num">Variación %</th><th>Responsable</th></tr></thead>
      <tbody><?php foreach($historialPrecios['filas'] as $h): $dif=$h['diferencia'];$pct=$h['variacion_pct'];$difFmt=$dif===null?'':(((float)$dif>0?'+$':((float)$dif<0?'-$':'')).ltrim((string)$dif,'-'));$tipo=match($h['tipo']){'inicial'=>'Precio inicial','base_observada'=>'Base observada','cambio'=>'Cambio',default=>'Registro'}; ?>
        <tr><td><time datetime="<?php echo $esc(date(DATE_ATOM,strtotime($h['created_at']))); ?>"><?php echo $esc($hora($h['created_at'])); ?></time></td><td><?php echo $esc($tipo); ?></td><td><?php echo $esc($h['unidad']); ?></td><td class="admin-table__num"><?php echo $h['precio_anterior']===null?'—':'$'.$esc($h['precio_anterior']); ?></td><td class="admin-table__num"><span class="admin-num">$<?php echo $esc($h['precio_nuevo']); ?></span></td><td class="admin-table__num"><?php if($dif===null): ?>—<?php else: ?><span class="admin-num <?php echo (float)$dif>0?'proveedor-precios__sube':((float)$dif<0?'proveedor-precios__baja':''); ?>"><?php echo $esc($difFmt); ?></span><?php endif; ?></td><td class="admin-table__num"><?php echo $pct===null?'—':($pct>0?'+':'').$esc(number_format((float)$pct,2,'.','')).' %'; ?></td><td><?php echo $esc($h['responsable']); ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if($historialPrecios['paginas']>1): ?><nav class="proveedor-precios__paginacion" aria-label="Páginas del historial">
      <?php if($historialPrecios['pagina']>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($baseHistorial.'&pagina='.($historialPrecios['pagina']-1)); ?>">Anterior</a><?php endif; ?>
      <span>Página <?php echo (int)$historialPrecios['pagina']; ?> de <?php echo (int)$historialPrecios['paginas']; ?> · <?php echo (int)$historialPrecios['total']; ?> eventos</span>
      <?php if($historialPrecios['pagina']<$historialPrecios['paginas']): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($baseHistorial.'&pagina='.($historialPrecios['pagina']+1)); ?>">Siguiente</a><?php endif; ?>
    </nav><?php endif; ?><?php endif; ?>
  </section>

  <section class="proveedor-precios__comparacion" aria-labelledby="comparacion-precios-titulo"><h3 id="comparacion-precios-titulo">Precios vigentes en <?php echo $esc($dueno); ?></h3>
    <?php if(!$comparacionPrecios): ?><p>No hay otras ofertas vigentes para este artículo en el ámbito consultado.</p><?php else: ?><div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Proveedor</th><th class="admin-table__num">Precio por unidad</th><th>Estado</th></tr></thead><tbody><?php foreach($comparacionPrecios as $c): ?><tr><td><?php echo $esc($c['nombre']); ?><?php if((int)$c['proveedor_id']===(int)$p['id']): ?> <span class="admin-table__cell-sub">Este proveedor</span><?php endif; ?></td><td class="admin-table__num"><span class="admin-num">$<?php echo $esc($c['precio_referencia']); ?></span> <?php echo $esc($c['unidad']); ?></td><td><?php echo (int)$c['activo']===1?'Activo':'Inactivo'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <?php endif; ?>
</section>
