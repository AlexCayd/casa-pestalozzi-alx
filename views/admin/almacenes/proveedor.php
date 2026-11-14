<?php
// Ficha de un proveedor (arquitectura §17). Lo primero es lo que el operador viene a
// leer: cada cuánto se le pide, qué y a qué costo. Debajo, lo que se edita: los
// artículos que surte y sus datos de contacto. Sin permiso o siendo de otro ámbito
// (Global consultando a un negocio) todo es de lectura.
require_once __DIR__.'/../partials/_icons.php';
use Services\Security\Contextos;
$esc=static fn($v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$fecha=static fn($v): string=>$v?date('d/m/Y',strtotime((string)$v)):'—';
$p=$proveedor;$nuevo=empty($p['id']);
$editar=$nuevo?$puedeEditar:!empty($puedeEditarFicha);
$historial=$historial??null;$asignaciones=$asignaciones??[];$asignables=$asignables??[];
$campoCsrf='<input type="hidden" name="admin_csrf" value="'.$esc($csrf).'">';
$dueno=Contextos::NOMBRES[$p['slug']??$ctx]??'';
$res=$historial['resumen']??null;
$historialPrecios=$historialPrecios??['filas'=>[],'pagina'=>1,'paginas'=>1,'total'=>0,'limite'=>25];
$articulosHistorialPrecios=$articulosHistorialPrecios??[];$articuloPrecioContexto=$articuloPrecioContexto??null;$comparacionPrecios=$comparacionPrecios??[];
$filtroHistorialArticulo=$filtroHistorialArticulo??null;$graficaPrecio=$graficaPrecio??null;
$tieneHistorialPrecios=$tieneHistorialPrecios??false;
$urlFicha=$base.'/ficha?id='.(int)($p['id']??0);
$urlHistorialArticulo=static fn(int $articuloId):string=>$urlFicha.'&historial_articulo='.$articuloId.'#historial-precios';
$hoy=date('Y-m-d');
?>
<section class="admin-inventario admin-page almacen-proveedores">
  <header class="admin-page__header">
    <div class="admin-page__intro">
      <h2 class="admin-page__title"><?php echo $nuevo?'Nuevo proveedor':$esc($p['nombre']); ?></h2>
      <p class="admin-page__subtitle"><?php if($nuevo): ?>Proveedor de <?php echo $esc($nombreContexto); ?>. Después de crearlo podrás asignarle artículos y ligarle entradas.<?php else: ?>Proveedor de <?php echo $esc($dueno); ?><?php if(empty($p['editable'])): ?> · sólo consulta desde <?php echo $esc($nombreContexto); ?><?php endif; ?><?php endif; ?></p>
    </div>
    <div class="almacen-actions almacen-page-actions">
      <?php if(!$nuevo): ?><span class="admin-badge admin-badge--<?php echo (int)$p['activo']===1?'success':'neutral'; ?>"><?php echo (int)$p['activo']===1?'Activo':'Inactivo'; ?></span><?php endif; ?>
      <a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base.(!$nuevo&&empty($p['editable'])?'?vista=consolidado':'')); ?>"><?php echo admin_icon('flecha-izquierda'); ?> Directorio</a>
    </div>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>

  <?php if($historial!==null): ?>
  <?php /* Cifras del histórico: recepciones (días con entradas), la última, cada
     cuánto y cuándo tocaría la siguiente. Con menos de dos recepciones no hay ritmo
     que calcular y se dice así, sin inventar una cifra. */ ?>
  <div class="almacen-summary">
    <p class="almacen-summary__cifras">
      <span><strong><?php echo (int)$res['recepciones']; ?></strong> <?php echo (int)$res['recepciones']===1?'recepción':'recepciones'; ?></span>
      <span>Última: <strong class="proveedor-cifra-fecha"><?php echo $esc($fecha($res['ultima'])); ?></strong></span>
      <?php if($res['intervalo_dias']!==null): ?>
      <span>Cada <strong><?php echo $esc(rtrim(rtrim(number_format((float)$res['intervalo_dias'],1,'.',''),'0'),'.')); ?></strong> días en promedio</span>
      <span>Siguiente estimada: <strong class="proveedor-cifra-fecha"><?php echo $esc($fecha($res['proximo'])); ?></strong><?php if($res['proximo']<$hoy): ?> <span class="admin-badge admin-badge--warning">Ya pasó</span><?php endif; ?></span>
      <?php else: ?><span>Hacen falta dos recepciones para estimar cada cuánto se le pide.</span><?php endif; ?>
    </p>
  </div>

  <section class="admin-panel almacen-list proveedor-precios" id="historial-precios" aria-labelledby="proveedor-precios-titulo">
    <div class="almacen-section-head"><div><h3 id="proveedor-precios-titulo">Historial de precios</h3><p>Precio de referencia ofrecido por este proveedor. El costo de cada entrada de inventario queda congelado por separado. “Base observada” registra hoy el precio que ya estaba guardado; no afirma cuándo se ofreció originalmente.</p></div></div>
    <form method="GET" action="<?php echo $esc($base.'/ficha'); ?>" class="proveedor-precios__filtros">
      <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
      <label for="historial-articulo">Artículo</label>
      <select id="historial-articulo" name="historial_articulo">
        <option value="">Todos los artículos</option>
        <?php foreach($articulosHistorialPrecios as $op): ?><option value="<?php echo (int)$op['articulo_id']; ?>" <?php echo (int)$filtroHistorialArticulo===(int)$op['articulo_id']?'selected':''; ?>><?php echo $esc($op['nombre'].' · '.$op['unidad'].($op['precio_actual']===null?' · retirado':'')); ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="admin-btn admin-btn--secondary">Consultar</button>
    </form>
    <?php if($filtroHistorialArticulo&&$articuloPrecioContexto): ?><p class="proveedor-precios__contexto"><strong>Proveedor:</strong> <?php echo $esc($p['nombre']); ?><?php if((int)$p['activo']!==1): ?> · Inactivo<?php endif; ?> <span aria-hidden="true">·</span> <strong>Artículo:</strong> <?php echo $esc($articuloPrecioContexto['nombre']); ?> <span aria-hidden="true">·</span> <strong>Unidad:</strong> <?php echo $esc($articuloPrecioContexto['unidad']); ?> <span aria-hidden="true">·</span> <strong>Precio de referencia:</strong> <?php if($articuloPrecioContexto['precio_actual']!==null): ?><span class="admin-num">$<?php echo $esc($articuloPrecioContexto['precio_actual']); ?></span> por <?php echo $esc($articuloPrecioContexto['unidad']); ?><?php else: ?><span class="almacen-unassociated">Sin oferta vigente; asociación retirada</span><?php endif; ?></p><?php endif; ?>
    <?php if($graficaPrecio!==null): ?>
      <figure class="proveedor-precios__grafica">
        <figcaption><?php echo $graficaPrecio['recortada']?'Últimos 40 eventos comparables':'Evolución de '.$graficaPrecio['eventos'].' eventos'; ?> · <?php echo $esc($fecha($graficaPrecio['desde'])); ?> a <?php echo $esc($fecha($graficaPrecio['hasta'])); ?> · precios por <?php echo $esc($graficaPrecio['unidad']); ?>.</figcaption>
        <div class="proveedor-precios__grafica-area" role="img" aria-label="Precio de referencia por <?php echo $esc($graficaPrecio['unidad']); ?> desde <?php echo $esc($fecha($graficaPrecio['desde'])); ?> hasta <?php echo $esc($fecha($graficaPrecio['hasta'])); ?>. Escala de $<?php echo $esc($graficaPrecio['eje_minimo']); ?> a $<?php echo $esc($graficaPrecio['eje_maximo']); ?>.">
          <div class="proveedor-precios__eje-y"><?php foreach($graficaPrecio['eje_y'] as $tick): ?><span>$<?php echo $esc($tick['valor']); ?></span><?php endforeach; ?></div>
          <div class="proveedor-precios__plot">
            <svg viewBox="58 14 292 102" aria-hidden="true" preserveAspectRatio="none">
              <?php foreach($graficaPrecio['eje_y'] as $tick): ?><line class="proveedor-precios__rejilla" x1="58" y1="<?php echo $esc($tick['y']); ?>" x2="350" y2="<?php echo $esc($tick['y']); ?>"/><?php endforeach; ?>
              <line class="proveedor-precios__eje-linea" x1="58" y1="14" x2="58" y2="116"/><line class="proveedor-precios__eje-linea" x1="58" y1="116" x2="350" y2="116"/>
              <polyline points="<?php echo $esc($graficaPrecio['puntos']); ?>"/>
            </svg>
            <div class="proveedor-precios__eje-x"><?php foreach($graficaPrecio['eje_x'] as $tick): $xPct=max(0,min(100,((float)$tick['x']-58)/292*100)); ?><span data-anchor="<?php echo $esc($tick['ancla']); ?>" style="left:<?php echo $esc(number_format($xPct,2,'.','')); ?>%"><?php echo $esc($tick['etiqueta']); ?></span><?php endforeach; ?></div>
          </div>
        </div>
      </figure>
    <?php elseif($filtroHistorialArticulo): ?>
      <p class="proveedor-precios__nota-grafica"><?php echo $historialPrecios['total']===0?'No hay eventos de precio para este artículo y proveedor.':'No hay tres eventos comparables en la unidad actual para mostrar la gráfica. La tabla conserva el historial disponible.'; ?></p>
    <?php endif; ?>
    <?php if(!$historialPrecios['filas']): ?>
      <div class="admin-empty"><strong>Aún no hay cambios registrados</strong><p>Al guardar por primera vez el precio de un artículo, quedará una línea base con la fecha y la persona que hizo el registro.</p></div>
    <?php else: ?>
      <div class="admin-table-wrap" data-scrollable><table class="admin-table proveedor-precios__tabla">
        <thead><tr><th>Fecha y hora</th><th>Tipo de evento</th><th>Artículo y unidad</th><th class="admin-table__num">Precio anterior</th><th class="admin-table__num">Precio nuevo</th><th class="admin-table__num">Diferencia</th><th class="admin-table__num">Variación %</th><th>Responsable</th></tr></thead>
        <tbody><?php foreach($historialPrecios['filas'] as $h):
          $tipo=match($h['tipo']){'inicial'=>'Precio inicial','base_observada'=>'Base observada','cambio'=>'Cambio',default=>'Registro'};
          $dif=$h['diferencia'];$pct=$h['variacion_pct'];$difFmt=$dif===null?'':(((float)$dif>0?'+$':((float)$dif<0?'-$':'$')).ltrim($dif,'-'));
          $artLink=$urlHistorialArticulo((int)$h['articulo_id']);
        ?><tr>
          <td><time datetime="<?php echo $esc(date(DATE_ATOM,strtotime($h['created_at']))); ?>"><?php echo $esc(date('d/m/Y H:i',strtotime($h['created_at']))); ?></time></td>
          <td><?php echo $esc($tipo); ?></td>
          <td><a class="proveedor-precios__articulo" href="<?php echo $esc($artLink); ?>"><?php echo $esc($h['articulo']); ?></a><span class="admin-table__cell-sub">por <?php echo $esc($h['unidad']); ?></span></td>
          <td class="admin-table__num"><?php echo $h['precio_anterior']===null?'—':'$'.$esc($h['precio_anterior']); ?></td>
          <td class="admin-table__num"><span class="admin-num">$<?php echo $esc($h['precio_nuevo']); ?></span></td>
          <td class="admin-table__num"><?php if($dif===null): ?>—<?php else: ?><span class="admin-num <?php echo (float)$dif>0?'proveedor-precios__sube':((float)$dif<0?'proveedor-precios__baja':''); ?>"><?php echo $esc($difFmt); ?></span><?php endif; ?></td>
          <td class="admin-table__num"><?php echo $pct===null?'—':($pct>0?'+':'').$esc(number_format((float)$pct,2,'.','')).' %'; ?></td>
          <td><?php echo $esc($h['responsable']); ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
      <?php if($historialPrecios['paginas']>1): ?><nav class="proveedor-precios__paginacion" aria-label="Páginas del historial de precios">
        <?php if($historialPrecios['pagina']>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($urlFicha.'&historial_pagina='.($historialPrecios['pagina']-1).($filtroHistorialArticulo?'&historial_articulo='.$filtroHistorialArticulo:'')); ?>">Anterior</a><?php endif; ?>
        <span>Página <?php echo (int)$historialPrecios['pagina']; ?> de <?php echo (int)$historialPrecios['paginas']; ?> · <?php echo (int)$historialPrecios['total']; ?> registros</span>
        <?php if($historialPrecios['pagina']<$historialPrecios['paginas']): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($urlFicha.'&historial_pagina='.($historialPrecios['pagina']+1).($filtroHistorialArticulo?'&historial_articulo='.$filtroHistorialArticulo:'')); ?>">Siguiente</a><?php endif; ?>
      </nav><?php endif; ?>
    <?php endif; ?>
    <?php if($filtroHistorialArticulo): ?><section class="proveedor-precios__comparacion" aria-labelledby="proveedor-comparacion-titulo"><h4 id="proveedor-comparacion-titulo">Comparación de precios en <?php echo $esc($dueno); ?></h4>
      <?php if(!$comparacionPrecios): ?><p>No hay precios de referencia vigentes para este artículo en el ámbito consultado.</p><?php else: ?><div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Proveedor</th><th class="admin-table__num">Precio de referencia</th><th>Estado</th></tr></thead><tbody><?php foreach($comparacionPrecios as $c): ?><tr><td><?php echo $esc($c['nombre']); ?><?php if((int)$c['proveedor_id']===(int)$p['id']): ?> <span class="admin-table__cell-sub">Este proveedor</span><?php endif; ?></td><td class="admin-table__num"><span class="admin-num">$<?php echo $esc($c['precio_referencia']); ?></span><span class="admin-table__cell-sub">por <?php echo $esc($c['unidad']); ?></span></td><td><?php echo (int)$c['activo']===1?'<span class="admin-badge admin-badge--success">Activo</span>':'<span class="admin-badge admin-badge--neutral">Inactivo</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section><?php endif; ?>
  </section>

  <section class="admin-panel almacen-list" aria-labelledby="proveedor-pedidos">
    <div class="almacen-section-head"><div><h3 id="proveedor-pedidos">Lo que se le suele pedir</h3><p>De lo que llegó a los almacenes con este proveedor. Costos en la unidad base del artículo.</p></div></div>
    <?php if(!$historial['articulos']): ?>
    <div class="admin-empty"><strong>Sin entradas registradas</strong><p><?php echo $editar?'Al registrar una entrada en Almacenes, elige este proveedor y su costo: aquí se verá qué se le pide y cómo cambia su precio.':'Todavía no llegan entradas con este proveedor.'; ?></p></div>
    <?php else: ?>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table proveedores-tabla">
      <thead><tr><th>Artículo</th><th class="admin-table__num">Recepciones</th><th class="admin-table__num">Total recibido</th><th class="admin-table__num">Por recepción</th><th>Última</th><th class="admin-table__num">Último costo</th><th>Evolución del costo</th></tr></thead>
      <tbody>
      <?php foreach($historial['articulos'] as $a): $var=$a['variacion']; ?>
        <tr>
          <td><span class="admin-table__cell-main"><?php echo $esc($a['nombre']); ?></span></td>
          <td class="admin-table__num"><span class="admin-num"><?php echo (int)$a['veces']; ?></span></td>
          <td class="admin-table__num"><span class="admin-num"><?php echo $esc($a['total'].' '.$a['unidad']); ?></span></td>
          <td class="admin-table__num"><span class="admin-num"><?php echo $esc($a['promedio'].' '.$a['unidad']); ?></span></td>
          <td><?php echo $esc($fecha($a['ultima'])); ?></td>
          <td class="admin-table__num"><?php if($a['costo_ultimo']!==null): ?><span class="admin-num">$<?php echo $esc($a['costo_ultimo']); ?></span><span class="admin-table__cell-sub">por <?php echo $esc($a['unidad']); ?></span><?php else: ?>—<?php endif; ?></td>
          <td>
            <?php if($a['costo_ultimo']!==null): ?>
            <p class="proveedor-evolucion"><span class="admin-num">$<?php echo $esc($a['costo_min']); ?> – $<?php echo $esc($a['costo_max']); ?></span><?php if($var!==null&&count($a['serie'])>1): ?> <span class="proveedor-variacion<?php echo $var>0?' is-sube':($var<0?' is-baja':''); ?>"><?php echo $var!=0?admin_icon($var>0?'sube':'baja',14):''; ?><?php echo $esc(($var>0?'+':'').number_format($var,1,'.','').' %'); ?><span class="admin-visually-hidden"> desde el primer costo registrado</span></span><?php endif; ?></p>
            <?php if(count($a['serie'])>1): ?>
            <details class="proveedor-serie"><summary>Ver <?php echo count($a['serie']); ?> costos</summary><ol><?php foreach($a['serie'] as $s): ?><li><span><?php echo $esc($fecha($s['fecha'])); ?></span><span class="admin-num">$<?php echo $esc($s['costo']); ?></span></li><?php endforeach; ?></ol></details>
            <?php endif; ?>
            <?php else: ?><span class="almacen-unassociated">Sin costo capturado</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <?php if($historial['recientes']): ?>
  <section class="admin-panel almacen-list" aria-labelledby="proveedor-recientes">
    <div class="almacen-section-head"><div><h3 id="proveedor-recientes">Entradas recientes</h3><p>Las últimas quince, de la más reciente a la más antigua.</p></div></div>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table">
      <thead><tr><th>Fecha</th><th>Artículo</th><th>Almacén</th><th class="admin-table__num">Cantidad</th><th class="admin-table__num">Costo</th><th>Nota</th></tr></thead>
      <tbody><?php foreach($historial['recientes'] as $m): ?><tr><td><?php echo $esc(date('d/m/Y H:i',strtotime($m['fecha']))); ?></td><td><?php echo $esc($m['nombre']); ?></td><td><?php echo $esc($m['almacen']); ?></td><td class="admin-table__num"><span class="admin-num"><?php echo $esc($m['cantidad'].' '.$m['unidad']); ?></span></td><td class="admin-table__num"><?php echo $m['costo']!==null?'<span class="admin-num">$'.$esc($m['costo']).'</span>':'—'; ?></td><td><?php echo $esc($m['nota']??''); ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>

  <?php if(!$nuevo): ?>
  <section class="admin-panel almacen-list" id="proveedor-articulos" aria-labelledby="proveedor-articulos-titulo">
    <div class="almacen-section-head"><div><h3 id="proveedor-articulos-titulo">Artículos que surte</h3><p>Costo de referencia, código del proveedor y si es el preferente. La entrada lo propone al elegir este proveedor.</p></div></div>
    <?php if($editar): $disponibles=array_values(array_filter($asignables,static fn($x)=>!in_array((int)$x['id'],array_map('intval',array_column($asignaciones,'articulo_id')),true))); ?>
    <form method="POST" action="<?php echo $esc($base.'/asignaciones'); ?>" class="proveedor-asignaciones">
      <?php echo $campoCsrf; ?><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
      <div class="admin-table-wrap" data-scrollable><table class="admin-table">
        <thead><tr><th>Artículo</th><th>Costo de referencia</th><th>Código</th><th>Preferente</th><th>Quitar</th><th>Historial</th></tr></thead>
        <tbody>
        <?php foreach($asignaciones as $n=>$f): $pre='asig-'.$n; ?>
          <tr>
            <td><span class="admin-table__cell-main"><?php echo $esc($f['nombre']); ?></span><span class="admin-table__cell-sub">Costo maestro $<?php echo $esc($f['costo_maestro'].' por '.$f['unidad']); ?></span><input type="hidden" name="filas[<?php echo $n; ?>][articulo_id]" value="<?php echo (int)$f['articulo_id']; ?>"></td>
            <td><label class="admin-visually-hidden" for="<?php echo $pre; ?>-costo">Costo de referencia de <?php echo $esc($f['nombre']); ?> por <?php echo $esc($f['unidad']); ?></label><input id="<?php echo $pre; ?>-costo" name="filas[<?php echo $n; ?>][costo]" type="number" min="0" step="0.0001" inputmode="decimal" value="<?php echo $esc($f['costo']); ?>" required></td>
            <td><label class="admin-visually-hidden" for="<?php echo $pre; ?>-codigo">Código del proveedor para <?php echo $esc($f['nombre']); ?></label><input id="<?php echo $pre; ?>-codigo" name="filas[<?php echo $n; ?>][codigo]" type="text" maxlength="60" value="<?php echo $esc($f['codigo']??''); ?>"></td>
            <td><label class="proveedor-check"><input type="checkbox" name="filas[<?php echo $n; ?>][preferente]" value="1" <?php echo (int)$f['preferente']===1?'checked':''; ?>><span class="admin-visually-hidden">Preferente para <?php echo $esc($f['nombre']); ?></span></label></td>
            <td><label class="proveedor-check"><input type="checkbox" name="filas[<?php echo $n; ?>][quitar]" value="1"><span class="admin-visually-hidden">Quitar <?php echo $esc($f['nombre']); ?></span></label></td>
            <td><a class="admin-btn admin-btn--secondary proveedor-historial-link" href="<?php echo $esc($urlHistorialArticulo((int)$f['articulo_id'])); ?>">Ver histórico</a></td>
          </tr>
        <?php endforeach; $nueva=count($asignaciones); ?>
        <?php if($disponibles): ?>
          <tr class="proveedor-asignaciones__nueva">
            <td><label class="admin-visually-hidden" for="asig-nuevo-articulo">Agregar artículo</label><select id="asig-nuevo-articulo" name="filas[<?php echo $nueva; ?>][articulo_id]"><option value="">Agregar artículo…</option><?php foreach($disponibles as $x): ?><option value="<?php echo (int)$x['id']; ?>"><?php echo $esc($x['nombre'].' · '.$x['unidad']); ?></option><?php endforeach; ?></select></td>
            <td><label class="admin-visually-hidden" for="asig-nuevo-costo">Costo de referencia del artículo nuevo</label><input id="asig-nuevo-costo" name="filas[<?php echo $nueva; ?>][costo]" type="number" min="0" step="0.0001" inputmode="decimal" placeholder="0.0000"></td>
            <td><label class="admin-visually-hidden" for="asig-nuevo-codigo">Código del artículo nuevo</label><input id="asig-nuevo-codigo" name="filas[<?php echo $nueva; ?>][codigo]" type="text" maxlength="60"></td>
            <td><label class="proveedor-check"><input type="checkbox" name="filas[<?php echo $nueva; ?>][preferente]" value="1"><span class="admin-visually-hidden">Preferente para el artículo nuevo</span></label></td>
            <td></td><td></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table></div>
      <?php if(!$asignaciones&&!$disponibles): ?><p class="admin-field__hint">Este ámbito todavía no tiene artículos en sus almacenes. Asocia artículos en Almacenes para asignarlos a un proveedor.</p><?php endif; ?>
      <p class="admin-field__hint">Marcar preferente desmarca al preferente anterior de ese artículo. Se agrega un artículo por vez.</p>
      <div class="admin-actions"><button type="submit" class="admin-btn admin-btn--primary">Guardar artículos</button></div>
    </form>
    <?php elseif(!$asignaciones): ?>
    <div class="admin-empty"><strong>Sin artículos asignados</strong><p>Todavía no se registró qué artículos surte este proveedor.</p></div>
    <?php else: ?>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table">
      <thead><tr><th>Artículo</th><th class="admin-table__num">Costo de referencia</th><th class="admin-table__num">Costo maestro</th><th>Código</th><th>Preferente</th><th>Historial</th></tr></thead>
      <tbody><?php foreach($asignaciones as $f): ?><tr><td><span class="admin-table__cell-main"><?php echo $esc($f['nombre']); ?></span></td><td class="admin-table__num"><span class="admin-num">$<?php echo $esc($f['costo']); ?></span><span class="admin-table__cell-sub">por <?php echo $esc($f['unidad']); ?></span></td><td class="admin-table__num"><span class="admin-num">$<?php echo $esc($f['costo_maestro']); ?></span></td><td><?php echo $esc($f['codigo']??'—'); ?></td><td><?php echo (int)$f['preferente']===1?'<span class="admin-badge admin-badge--success">Preferente</span>':'—'; ?></td><td><a class="admin-btn admin-btn--secondary proveedor-historial-link" href="<?php echo $esc($urlHistorialArticulo((int)$f['articulo_id'])); ?>">Ver histórico</a></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="admin-panel almacen-list" aria-labelledby="proveedor-datos">
    <div class="almacen-section-head"><div><h3 id="proveedor-datos">Datos del proveedor</h3><p>Contacto y condiciones. Un proveedor inactivo deja de ofrecerse en las entradas, pero conserva su historial.</p></div></div>
    <?php if($editar): ?>
    <form method="POST" action="<?php echo $esc($base.'/guardar'); ?>" class="admin-form-grid">
      <?php echo $campoCsrf; ?><input type="hidden" name="id" value="<?php echo $nuevo?'':(int)$p['id']; ?>">
      <div class="admin-field"><label class="admin-field__label" for="prov-nombre">Nombre del proveedor</label><input id="prov-nombre" name="nombre" type="text" maxlength="120" required value="<?php echo $esc($p['nombre']??''); ?>"></div>
      <div class="admin-field"><label class="admin-field__label" for="prov-contacto">Persona de contacto</label><input id="prov-contacto" name="contacto" type="text" maxlength="120" value="<?php echo $esc($p['contacto']??''); ?>"></div>
      <div class="admin-field"><label class="admin-field__label" for="prov-telefono">Teléfono</label><input id="prov-telefono" name="telefono" type="tel" maxlength="30" value="<?php echo $esc($p['telefono']??''); ?>"></div>
      <div class="admin-field"><label class="admin-field__label" for="prov-correo">Correo</label><input id="prov-correo" name="correo" type="email" maxlength="120" value="<?php echo $esc($p['correo']??''); ?>"></div>
      <div class="admin-field admin-form-grid__full"><label class="admin-field__label" for="prov-notas">Notas</label><textarea id="prov-notas" name="notas" rows="3" maxlength="2000"><?php echo $esc($p['notas']??''); ?></textarea><p class="admin-field__hint">Días de entrega, pedido mínimo, condiciones de pago.</p></div>
      <label class="admin-switch admin-form-grid__full"><input type="checkbox" name="activo" value="1" <?php echo (int)($p['activo']??1)===1?'checked':''; ?>><span class="admin-switch__track"><span class="admin-switch__thumb"></span></span><span class="admin-switch__label">Proveedor activo</span></label>
      <div class="admin-modal__actions admin-form-grid__full"><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base); ?>">Cancelar</a><button type="submit" class="admin-btn admin-btn--primary"><?php echo $nuevo?'Crear proveedor':'Guardar datos'; ?></button></div>
    </form>
    <?php else: ?>
    <dl class="proveedor-datos">
      <div><dt>Contacto</dt><dd><?php echo $esc($p['contacto']??'—'); ?></dd></div>
      <div><dt>Teléfono</dt><dd><?php echo $esc($p['telefono']??'—'); ?></dd></div>
      <div><dt>Correo</dt><dd><?php echo $esc($p['correo']??'—'); ?></dd></div>
      <div class="proveedor-datos__full"><dt>Notas</dt><dd><?php echo nl2br($esc($p['notas']??'—')); ?></dd></div>
    </dl>
    <?php endif; ?>
  </section>

  <?php if(!$nuevo&&$editar): ?>
  <section class="admin-panel almacen-list proveedor-retiro" aria-labelledby="proveedor-retiro">
    <div class="almacen-section-head"><div><h3 id="proveedor-retiro">Retirar proveedor</h3>
    <?php if((int)($res['recepciones']??0)>0||$tieneHistorialPrecios): ?><p><?php echo (int)($res['recepciones']??0)>0?'Tiene entradas registradas. ':'Tiene historial de precios. '; ?>No se elimina: desactívalo para conservar su trazabilidad.</p></div></div>
    <?php else: ?><p>Sin entradas ni historial de precios. Eliminarlo quita también sus artículos asignados.</p></div>
      <form method="POST" action="<?php echo $esc($base.'/eliminar'); ?>" data-confirm-delete data-confirm-eyebrow="Eliminar proveedor" data-confirm-title="¿Eliminar «<?php echo $esc($p['nombre']); ?>»?" data-confirm-require="<?php echo $esc($p['nombre']); ?>" data-confirm-description="Se quitarán sus <?php echo count($asignaciones); ?> artículos asignados." data-confirm-consequence="No tiene entradas ni historial de precios. Esta acción no se puede deshacer.">
        <?php echo $campoCsrf; ?><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
        <button type="submit" class="admin-btn admin-btn--danger"><?php echo admin_icon('basura',16); ?> Eliminar proveedor</button>
      </form>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</section>
