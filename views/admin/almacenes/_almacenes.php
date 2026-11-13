<?php
// Contenido del modal «Almacenes físicos». El título lo pone el marco del modal; aquí no
// se repite. Orden: introducción, la acción de alta (sólo con facultad de crear) y la
// lista agrupada por propietario. El propietario es el dato que decide qué se puede hacer
// con cada almacén, por eso agrupa la tabla en lugar de ser una columna más: el negocio
// actual va primero y los demás se rotulan una sola vez como consulta.
// Espera $filasAlmacen, $almacenes, $opcionesAlmacen, $activos, $ctx, $nombreContexto,
// $puedeEditar, $puedeCrearAlmacen, $base, $campos y $esc de index.php.
$nombresNegocio=\Services\Security\Contextos::NOMBRES;
$totalFiltrado=(int)($almacenes['total']??count($filasAlmacen));
$totalAutorizado=count($opcionesAlmacen??[]);
$porDueno=[];
foreach($filasAlmacen as $a)$porDueno[(string)$a['slug']][]=$a;
// El negocio actual primero; los demás en el orden canónico de los ámbitos.
$ordenDuenos=array_values(array_unique(array_merge([$ctx],array_keys($nombresNegocio))));
$ordenDuenos=array_values(array_filter($ordenDuenos,static fn($s)=>isset($porDueno[$s])));
$plural=static fn(int $n,string $uno,string $varios): string=>$n.' '.($n===1?$uno:$varios);
// Sin almacenes propios activos, crear uno es lo primero que hay que hacer: la cabecera
// abre este modal con ese fin, así que el formulario llega desplegado.
$altaAbierta=$puedeCrearAlmacen&&!$activos;
?>
<p class="almacen-modal__intro">
    <?php if($totalAutorizado>0&&$totalFiltrado<$totalAutorizado): ?>
    Se muestran <span class="admin-num"><?php echo $totalFiltrado; ?></span> de <span class="admin-num"><?php echo $totalAutorizado; ?></span> almacenes: los de la selección actual.
    <?php elseif($totalFiltrado>0): ?>
    <?php echo $esc($plural($totalFiltrado,'almacén','almacenes')); ?> en <?php echo $esc($plural(count($ordenDuenos),'negocio','negocios')); ?>.
    <?php endif; ?>
    El propietario se elige al crear el almacén y no cambia después.<?php if(!$puedeEditar): ?> Solo consulta: tu permiso no incluye configurarlos.<?php endif; ?>
</p>

<?php if($puedeCrearAlmacen): ?>
<?php /* Misma señalética que «Solicitar transferencia»: «Nuevo almacén» (summary) es el primario del
   modal y, abierto, baja a secundario para que a la vista quede un solo primario, el
   envío del formulario. El propietario va primero y con las tres opciones a la vista
   porque es la única decisión que no se puede corregir después; sin valor previo, para
   que nadie cree un almacén de Global por no haber mirado. */ ?>
<details class="almacen-config-alta"<?php echo $altaAbierta?' open':''; ?>>
    <summary class="admin-btn admin-btn--primary almacen-config-alta__toggle"><?php echo admin_icon('mas',16); ?><span>Nuevo almacén</span><?php echo admin_icon('chevron-abajo',16,'almacen-config-alta__chevron'); ?></summary>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid almacen-config-alta__form"><?php $campos('crear_almacen'); ?>
        <fieldset class="admin-pills admin-form-grid__full">
            <legend class="admin-pills__legend">Propietario</legend>
            <div class="admin-pills__group">
                <?php $primero=true; foreach(['global'=>'Global','restaurante'=>'Restaurante','panaderia'=>'Panadería'] as $slug=>$nombre): ?>
                <label class="admin-pill"><input type="radio" name="propietario" value="<?php echo $slug; ?>" required<?php echo $primero&&$altaAbierta?' autofocus':''; ?>><span class="admin-pill__body"><span class="admin-pill__title"><?php echo $nombre; ?></span></span></label>
                <?php $primero=false; endforeach; ?>
            </div>
            <p class="admin-field__hint">No cambia después y no concede permisos de operación sobre ese negocio.</p>
        </fieldset>
        <div class="admin-field admin-form-grid__full"><label class="admin-field__label" for="nuevo-almacen-nombre">Nombre del almacén</label><input id="nuevo-almacen-nombre" name="nombre" maxlength="100" required><p class="admin-field__hint">Ningún almacén admite saldo negativo: lo que una venta no encuentra queda como faltante por conciliar.</p></div>
        <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary"><?php echo admin_icon('mas',16); ?> Crear almacén</button></div>
    </form>
</details>
<?php endif; ?>

<?php /* Sin ningún almacén y con facultad de crear, el formulario desplegado ya es el
   estado vacío: un aviso debajo sólo repetiría la instrucción. */
if(!$filasAlmacen&&$puedeCrearAlmacen&&$totalAutorizado===0): ?>
<?php elseif(!$filasAlmacen): ?>
<div class="admin-empty">
    <?php if($totalAutorizado>0): ?><strong>Sin almacenes para estos filtros</strong><p>Cambia la selección de almacenes en consulta para verlos aquí.</p>
    <?php else: ?><strong>Todavía no hay almacenes</strong><p>La creación física corresponde a Global con facultad delegada.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="admin-table-wrap almacen-config" data-scrollable>
<table class="admin-table almacen-config__tabla">
    <caption class="admin-visually-hidden">Almacenes físicos por propietario</caption>
    <thead><tr><th scope="col">Almacén</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
    <?php foreach($ordenDuenos as $slug): $grupo=$porDueno[$slug]; $propio=$slug===$ctx; $dueno=$nombresNegocio[$slug]; ?>
    <tbody class="almacen-config__grupo<?php echo $propio?' is-propio':''; ?>">
        <tr class="almacen-config__dueno"><th scope="rowgroup" colspan="3">
            <span class="almacen-config__dueno-fila">
                <span class="almacen-config__dueno-nombre"><?php echo $esc($dueno); ?></span>
                <?php /* La relación con el ámbito actual dicha con palabra: propio o de otro
                   negocio. Sustituye al aviso que antes se repetía en cada fila ajena. */ ?>
                <span class="admin-badge almacen-config__relacion<?php echo $propio?' almacen-config__relacion--propio':' admin-badge--outline'; ?>"><?php echo $propio?'Propio':'Otro negocio'; ?></span>
                <span class="almacen-config__cuenta"><?php echo $esc($plural(count($grupo),'almacén','almacenes')); ?></span>
            </span>
            <?php if(!$propio): ?><span class="almacen-config__nota">Solo consulta: los opera y configura <?php echo $esc($dueno); ?>.</span><?php endif; ?>
        </th></tr>
        <?php foreach($grupo as $a): $aId=(int)$a['almacen_id']; $activo=(int)$a['activo']===1; $nombreAlmacen=(string)$a['nombre']; ?>
        <tr class="almacen-config__fila<?php echo $activo?'':' is-inactive'; ?>">
            <td class="almacen-config__nombre"><strong><?php echo $esc($nombreAlmacen); ?></strong><?php if(isset($a['total_articulos'])): ?><small><?php echo $esc($plural((int)$a['total_articulos'],'artículo activo','artículos activos')); ?></small><?php endif; ?></td>
            <?php /* Estado con punto y palabra (admin-status); inactivo además con borde
               discontinuo, la misma forma que en la selección de almacenes. */ ?>
            <td class="almacen-config__estado"><span class="admin-status<?php echo $activo?'':' admin-status--neutral almacen-config__inactivo'; ?>"><?php echo $activo?'Activo':'Inactivo'; ?></span></td>
            <td class="almacen-config__acciones-celda"><div class="almacen-config__acciones">
                <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $esc($base.'?vista=existencias&almacen_id='.$aId); ?>"><?php echo admin_icon('inventario',16); ?> Ver artículos<span class="admin-visually-hidden"> de <?php echo $esc($nombreAlmacen); ?></span></a>
                <?php if($puedeEditar&&$propio): ?>
                <details class="almacen-config__detalle">
                    <summary class="admin-btn admin-btn--secondary admin-btn--small almacen-config__toggle"><?php echo admin_icon('configuration',16); ?><span>Configurar<span class="admin-visually-hidden"> <?php echo $esc($nombreAlmacen); ?></span></span><?php echo admin_icon('chevron-abajo',16,'almacen-config__chevron'); ?></summary>
                    <div class="almacen-config__panel">
                        <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid">
                            <?php $campos('configurar_almacen',['almacen_id'=>$aId]); ?>
                            <div class="admin-field admin-form-grid__full"><label class="admin-field__label" for="almacen-<?php echo $aId; ?>-nombre">Nombre</label><input id="almacen-<?php echo $aId; ?>-nombre" name="nombre" maxlength="100" value="<?php echo $esc($nombreAlmacen); ?>" required></div>
                            <div class="admin-field admin-form-grid__full"><label class="almacen-config__activo"><input type="checkbox" name="activo" value="1" <?php echo $activo?'checked':''; ?>> Almacén activo</label></div>
                            <div class="admin-form-grid__actions admin-form-grid__full"><button type="submit" class="admin-btn admin-btn--primary">Guardar configuración</button></div>
                        </form>
                        <?php /* Lo que destruye va aparte, tras un filete y a tamaño pequeño: rojo
                           pleno porque es destructivo, pero un escalón por debajo de Guardar.
                           El diálogo compartido pide escribir el nombre antes de enviar. */ ?>
                        <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="almacen-config__baja" data-confirm-delete data-confirm-eyebrow="Eliminar almacén" data-confirm-title="<?php echo $esc('¿Eliminar '.$nombreAlmacen.'?'); ?>" data-confirm-description="Solo se elimina un almacén sin saldo, historial ni operaciones pendientes. Si tiene alguno, se rechaza y queda intacto." data-confirm-consequence="Esta acción no se puede deshacer." data-confirm-primary="Eliminar almacén" data-confirm-require="<?php echo $esc($nombreAlmacen); ?>">
                            <?php $campos('eliminar_almacen',['almacen_id'=>$aId]); ?><p class="admin-field__hint">Solo se elimina un almacén sin saldo, historial ni operaciones pendientes.</p><button type="submit" class="admin-btn admin-btn--danger admin-btn--small"><?php echo admin_icon('basura',16); ?> Eliminar almacén vacío</button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <?php endforeach; ?>
</table>
</div>
<?php endif; ?>
