<?php
declare(strict_types=1);

// Fixture solo de render para capturas: no abre conexión ni inserta datos.
// Las series persistentes viven exclusivamente en database/development.sql.
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$uiPath = __DIR__ . '/ui-fixture-seed-sql.json';
if (!is_file($uiPath)) { fwrite(STDERR, "Falta ui-fixture-seed-sql.json; ejecutar antes run-proveedores.php.\n"); exit(1); }
$ui = json_decode((string) file_get_contents($uiPath), true, 512, JSON_THROW_ON_ERROR);
$ctx='restaurante';$nombreContexto='Restaurante';$base='/admin/almacenes/proveedores';$baseAlmacenes='/admin/almacenes';$csrf='solo-fixture';
$puedeEditar=true;$nuevo=false;$hoy=date('Y-m-d');$p=$ui['proveedor'];$proveedor=$p;
$historial=['resumen'=>['recepciones'=>3,'primera'=>'2026-11-01','ultima'=>'2026-11-12','intervalo_dias'=>5.5,'proximo'=>'2026-11-18'],'articulos'=>[['articulo_id'=>1002,'nombre'=>'Leche compartida','unidad'=>'ml','veces'=>3,'total'=>'205.500','promedio'=>'68.500','ultima'=>'2026-11-12 08:30:00','costo_ultimo'=>'0.0150','costo_primero'=>'0.0142','costo_min'=>'0.0142','costo_max'=>'0.0150','variacion'=>5.63,'serie'=>[]]],'recientes'=>[['fecha'=>'2026-11-12 08:30:00','almacen'=>'Cocina','nombre'=>'Leche compartida','unidad'=>'ml','cantidad'=>'30.000','costo'=>'0.0150','nota'=>'Fixture sintético']]];
$historialPrecios=$ui['historialPrecios'];$historialPrecios['pagina']=1;$articulosHistorialPrecios=$ui['articulosHistorialPrecios'];
$filtroHistorialArticulo=1002;$articuloPrecioContexto=$ui['articuloPrecioContexto'];$graficaPrecio=$ui['graficaPrecio'];
$historial=$ui['historial'];$comparacionPrecios=$ui['comparacionPrecios'];
$tieneHistorialPrecios=true;$asignaciones=$ui['asignaciones'];$asignables=[];$alertas=['error'=>[],'exito'=>[],'advertencia'=>[]];$title=$p['nombre'];

foreach(['edicion'=>true,'consulta'=>false] as $modo=>$editable){
    $p['editable']=$editable;$puedeEditarFicha=$editable;
    ob_start();include $root.'/views/admin/almacenes/proveedor.php';$contenido=ob_get_clean();
    $html='<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fixture de captura · '.$modo.'</title><link rel="stylesheet" href="/build/css/admin.css"><link rel="stylesheet" href="/build/css/admin/inventario.css"></head><body class="admin-body is-sidebar-collapsed"><div class="admin-shell"><aside class="admin-sidebar" aria-hidden="true"></aside><button class="admin-sidebar-backdrop" type="button" aria-hidden="true"></button><div class="admin-main"><main class="admin-content">'.$contenido.'</main></div></div><script src="/build/js/admin.js" defer></script><script src="/build/js/admin/inventario.js" defer></script></body></html>';
    file_put_contents($root.'/public/_qa-precios-proveedores-'.$modo.'.html',$html);
}
echo "Fixtures solo de render generados; no conexión ni escritura DB.\n";
