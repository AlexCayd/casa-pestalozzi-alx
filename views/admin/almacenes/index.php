<?php
$esc=static fn($v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$ctx=(string)$contextoAlmacenes;
$filasAlmacen=$almacenes['almacenes']??[];
$propios=array_values(array_filter($opcionesAlmacen??[],static fn($a)=>(string)$a['slug']===$ctx));
$activos=array_values(array_filter($propios,static fn($a)=>(int)$a['activo']===1));
$url=static fn(string $tab,array $cambios=[]): string=>$base.'?'.http_build_query(array_merge($filtros,['vista'=>$tab],$cambios));
$campos=static function(string $accion,array $extra=[]) use($esc,$csrf,$vista): void {
    foreach(array_merge(['admin_csrf'=>$csrf,'accion'=>$accion,'volver_vista'=>$vista],$extra) as $campo=>$valor) echo '<input type="hidden" name="'.$esc($campo).'" value="'.$esc($valor).'">';
};
$tabs=['existencias'=>'Existencias','almacenes'=>'Almacenes','movimientos'=>'Movimientos'];
if($ctx==='global') $tabs['catalogo']='Catálogo maestro';
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow"><?php echo $esc($nombreContexto); ?></span>
            <h2 class="admin-page__title">Almacenes</h2>
            <p class="admin-page__subtitle"><?php echo $ctx==='global'?'Consulta la distribución de artículos de la organización. Las operaciones físicas corresponden a los almacenes de Global.':'Consulta y administra las existencias de tus almacenes físicos.'; ?></p>
        </div>
    </header>
    <?php include __DIR__.'/../partials/alertas.php'; ?>
    <nav class="admin-page__actions" aria-label="Vistas de Almacenes">
        <?php foreach($tabs as $tab=>$tituloTab): ?><a class="admin-btn <?php echo $vista===$tab?'admin-btn--primary':'admin-btn--secondary'; ?>" href="<?php echo $esc($url($tab,['pagina'=>1])); ?>" <?php echo $vista===$tab?'aria-current="page"':''; ?>><?php echo $esc($tituloTab); ?></a><?php endforeach; ?>
    </nav>
    <section class="admin-panel admin-card" aria-label="Filtros de almacenes">
        <form method="GET" action="<?php echo $esc($base); ?>" class="admin-form-grid">
            <input type="hidden" name="vista" value="<?php echo $esc($vista); ?>">
            <div class="admin-field"><label class="admin-field__label" for="almacenes-q">Buscar <?php echo $vista==='almacenes'?'almacén':'artículo'; ?></label><input id="almacenes-q" name="q" type="search" maxlength="120" value="<?php echo $esc($filtros['q']??''); ?>"></div>
            <?php if($ctx==='global' && $vista!=='catalogo'): ?><div class="admin-field"><label class="admin-field__label" for="almacenes-negocio">Ámbito propietario</label><select id="almacenes-negocio" name="negocio"><option value="">Todos los ámbitos</option><?php foreach(['global'=>'Global','restaurante'=>'Restaurante','panaderia'=>'Panadería'] as $slug=>$nombre): ?><option value="<?php echo $slug; ?>" <?php echo ($filtros['negocio']??'')===$slug?'selected':''; ?>><?php echo $nombre; ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <?php if($vista!=='catalogo'): ?><div class="admin-field"><label class="admin-field__label" for="almacenes-fisico">Almacén físico</label><select id="almacenes-fisico" name="almacen_id"><option value="">Todos los autorizados</option><?php foreach(($opcionesAlmacen??[]) as $a): ?><option value="<?php echo (int)$a['almacen_id']; ?>" <?php echo (int)($filtros['almacen_id']??0)===(int)$a['almacen_id']?'selected':''; ?>><?php echo $esc($a['nombre'].' · '.\Services\Security\Contextos::NOMBRES[$a['slug']]); ?></option><?php endforeach; ?></select><?php if(($totalOpcionesAlmacen??0)>100): ?><p class="admin-field__hint">¿No ves el almacén? Encuéntralo en la lista de Almacenes y abre sus artículos.</p><?php endif; ?></div><?php endif; ?>
            <?php if($vista==='movimientos'): ?>
            <div class="admin-field"><label class="admin-field__label" for="almacenes-desde">Desde</label><input id="almacenes-desde" type="date" name="desde" value="<?php echo $esc($filtros['desde']??''); ?>"></div>
            <div class="admin-field"><label class="admin-field__label" for="almacenes-hasta">Hasta</label><input id="almacenes-hasta" type="date" name="hasta" value="<?php echo $esc($filtros['hasta']??''); ?>"></div>
            <div class="admin-field"><label class="admin-field__label" for="almacenes-tipo">Tipo</label><select id="almacenes-tipo" name="tipo"><option value="">Todos</option><?php foreach(['entrada'=>'Entrada','ajuste'=>'Ajuste','merma'=>'Merma','venta'=>'Venta','cancelacion'=>'Compensación'] as $tipo=>$nombreTipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($filtros['tipo']??'')===$tipo?'selected':''; ?>><?php echo $nombreTipo; ?></option><?php endforeach; ?></select></div>
            <?php elseif($vista==='existencias'): ?><div class="admin-field"><label><input type="checkbox" name="solo_alertas" value="1" <?php echo !empty($filtros['solo_alertas'])?'checked':''; ?>> Solo asociaciones bajo su mínimo</label></div><?php endif; ?>
            <div class="admin-form-grid__actions"><button class="admin-btn admin-btn--primary" type="submit">Aplicar filtros</button><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base.'?vista='.$vista); ?>">Limpiar</a></div>
        </form>
    </section>

    <?php if($vista==='existencias'): ?>
    <section class="admin-panel admin-card">
        <div class="admin-panel__header"><h3>Existencias por artículo</h3><p><?php echo (int)$existencias['total']; ?> artículos. Los totales suman almacenes del mismo artículo; las alertas se evalúan por asociación física.</p></div>
        <?php if(empty($existencias['articulos'])): ?><div class="admin-empty"><strong>Sin existencias para estos filtros</strong><p>Asocia un artículo a un almacén propio para comenzar, o cambia los filtros.</p></div><?php else: ?>
        <div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Artículo</th><th>Saldo autorizado</th><th>Mínimos asociados</th><th>Distribución por ámbito y almacén</th></tr></thead><tbody>
        <?php foreach($existencias['articulos'] as $i): ?><tr><td><strong><?php echo $esc($i['nombre']); ?></strong><?php if(!(int)$i['activo']): ?><small>Inactivo</small><?php endif; ?></td><td class="admin-num"><?php echo $esc($i['saldo_total'].' '.$i['unidad']); ?></td><td class="admin-num"><?php echo $esc($i['minimo_total'].' '.$i['unidad']); ?><?php if($i['alerta']): ?><span class="admin-badge admin-badge--warning">Bajo mínimo en un almacén</span><?php endif; ?></td><td>
            <?php foreach($i['ambitos'] as $ambito): ?><strong><?php echo $esc(\Services\Security\Contextos::NOMBRES[$ambito['slug']]); ?></strong><ul>
            <?php foreach($ambito['almacenes'] as $asociacion): ?><li>
                <?php echo $esc($asociacion['nombre']); ?>: <span class="admin-num"><?php echo $esc($asociacion['saldo'].' / mínimo '.$asociacion['minimo'].' '.$i['unidad']); ?></span>
                <?php if($asociacion['alerta']): ?><span class="admin-badge admin-badge--warning">Por surtir</span><?php endif; ?>
                <?php if(!$asociacion['activo']||!$asociacion['asociacion_activa']): ?><small>Asociación o almacén inactivo</small><?php endif; ?>
                <?php if($puedeEditar && $ambito['slug']===$ctx && $asociacion['activo']): ?>
                <details><summary>Operar esta asociación</summary>
                    <?php include __DIR__.'/_asociacion.php'; ?>
                </details>
                <?php endif; ?>
            </li><?php endforeach; ?></ul><?php endforeach; ?>
            <?php if(empty($i['ambitos'])): ?><span>Sin asociaciones físicas</span><?php endif; ?>
        </td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <?php if($puedeEditar): ?><section class="admin-panel admin-card"><h3>Asociar artículo existente</h3><p>La asociación inicia sin saldo. Registra después una entrada con su referencia.</p>
        <?php if(!$activos): ?><div class="admin-empty">No hay almacenes propios activos. Un administrador autorizado de Global puede crear uno.</div><?php else: ?>
        <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('asociar'); ?>
            <div class="admin-field"><label class="admin-field__label" for="asociar-almacen">Almacén propio</label><select id="asociar-almacen" name="almacen_id" required><option value="">Elige el almacén</option><?php foreach($activos as $a): ?><option value="<?php echo (int)$a['almacen_id']; ?>"><?php echo $esc($a['nombre']); ?></option><?php endforeach; ?></select></div>
            <div class="admin-field"><label class="admin-field__label" for="asociar-articulo">Artículo compartido</label><select id="asociar-articulo" name="articulo_id" required><option value="">Elige el artículo</option><?php foreach($catalogo['articulos'] as $a): if(!(int)$a['activo']) continue; ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select><p class="admin-field__hint">La búsqueda de artículo filtra esta lista. <?php if($catalogo['total']>100): ?>Se muestran hasta 100 coincidencias; afina la búsqueda para encontrar otro artículo. <?php endif; ?>El catálogo maestro lo administra Global.</p></div>
            <div class="admin-field"><label class="admin-field__label" for="asociar-minimo">Mínimo en este almacén</label><input id="asociar-minimo" name="minimo" type="number" min="0" step="0.001" value="0.000" required inputmode="decimal"></div>
            <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary">Asociar artículo</button></div>
        </form><?php endif; ?>
    </section><?php endif; ?>
    <?php elseif($vista==='almacenes'): ?>
        <?php include __DIR__.'/_almacenes.php'; ?>
    <?php elseif($vista==='movimientos'): ?>
    <section class="admin-panel admin-card"><h3>Movimientos trazables</h3><p><?php echo (int)$movimientos['total']; ?> movimientos autorizados. Las correcciones se registran mediante nuevas operaciones.</p>
        <?php if(!$movimientos['movimientos']): ?><div class="admin-empty"><strong>Sin movimientos para estos filtros</strong></div><?php else: ?>
        <div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Fecha</th><th>Artículo</th><th>Ámbito / almacén</th><th>Tipo</th><th>Cantidad</th><th>Costo histórico</th><th>Referencia / actor</th></tr></thead><tbody>
        <?php foreach($movimientos['movimientos'] as $m): ?><tr><td><?php echo $esc($m['fecha']); ?></td><td><?php echo $esc($m['articulo_nombre']); ?></td><td><?php echo $esc(\Services\Security\Contextos::NOMBRES[$m['slug']].' · '.$m['almacen_nombre']); ?></td><td><?php echo $esc($m['tipo']); ?></td><td class="admin-num"><?php echo $esc($m['delta'].' '.$m['unidad']); ?></td><td class="admin-num"><?php echo $m['costo_unitario']===null?'Sin costo registrado':$esc('$'.$m['costo_unitario']); ?></td><td><?php echo $esc($m['referencia']); ?><small><?php echo $esc($m['actor_tipo']); ?> #<?php echo (int)$m['usuario_id']; ?></small><?php if($m['nota']): ?><p><?php echo $esc($m['nota']); ?></p><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <?php else: ?>
        <?php include __DIR__.'/_catalogo.php'; ?>
    <?php endif; ?>
    <?php
    $paginaDatos=match($vista){'movimientos'=>$movimientos,'catalogo'=>$catalogo,'almacenes'=>$almacenes,default=>$existencias};
    $pagina=max(1,(int)($paginaDatos['pagina']??1)); $paginas=max(1,(int)ceil(($paginaDatos['total']??0)/($paginaDatos['por_pagina']??25)));
    ?>
    <nav class="admin-pagination" aria-label="Paginación de almacenes"><span>Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span><?php if($pagina>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url($vista,['pagina'=>$pagina-1])); ?>">Anterior</a><?php endif; ?><?php if($pagina<$paginas): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url($vista,['pagina'=>$pagina+1])); ?>">Siguiente</a><?php endif; ?></nav>
</section>
