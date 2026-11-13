<?php
declare(strict_types=1);

// Vista real `proveedor.php` con contenido sintético; no conecta a ninguna DB.
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$ctx = 'restaurante';
$nombreContexto = 'Restaurante';
$base = '/admin/almacenes/proveedores';
$baseAlmacenes = '/admin/almacenes';
$csrf = 'solo-fixture';
$puedeEditar = true;
$puedeEditarFicha = true;
$editar = true;
$nuevo = false;
$p = ['id'=>1001,'nombre'=>'Lácteos del Valle','activo'=>1,'editable'=>true,'unidad_id'=>2,'slug'=>'restaurante','contacto'=>'Equipo de pruebas','telefono'=>'555-0100','correo'=>'pruebas@example.test','notas'=>'Entrega martes y jueves.'];
$hoy = date('Y-m-d');
$historial = [
    'resumen'=>['recepciones'=>3,'primera'=>'2026-11-01','ultima'=>'2026-11-12','intervalo_dias'=>5.5,'proximo'=>'2026-11-18'],
    'articulos'=>[['articulo_id'=>1002,'nombre'=>'Leche entera','unidad'=>'ml','veces'=>3,'total'=>'205.500','promedio'=>'68.500','ultima'=>'2026-11-12 08:30:00','costo_ultimo'=>'0.0216','costo_primero'=>'0.0180','costo_min'=>'0.0180','costo_max'=>'0.0216','variacion'=>20.0,'serie'=>[['fecha'=>'2026-11-12 08:30:00','costo'=>'0.0216'],['fecha'=>'2026-11-06 09:00:00','costo'=>'0.0190'],['fecha'=>'2026-11-01 08:00:00','costo'=>'0.0180']]]],
    'recientes'=>[['fecha'=>'2026-11-12 08:30:00','almacen'=>'Almacén principal','nombre'=>'Leche entera','unidad'=>'ml','cantidad'=>'30.000','costo'=>'0.0216','nota'=>'Fixture sintético']],
];
$historialPrecios = ['pagina'=>1,'paginas'=>1,'total'=>3,'limite'=>25,'filas'=>[
    ['articulo_id'=>1002,'articulo'=>'Leche entera','unidad'=>'ml','unidad_actual'=>'ml','precio_anterior'=>'0.0190','precio_nuevo'=>'0.0216','diferencia'=>'0.0026','variacion_pct'=>13.68,'tipo'=>'cambio','created_at'=>'2026-11-12 08:30:00','responsable'=>'Ana de prueba','precio_actual'=>'0.0216'],
    ['articulo_id'=>1002,'articulo'=>'Leche entera','unidad'=>'ml','unidad_actual'=>'ml','precio_anterior'=>null,'precio_nuevo'=>'0.0190','diferencia'=>null,'variacion_pct'=>null,'tipo'=>'base_observada','created_at'=>'2026-11-06 09:00:00','responsable'=>'Luis de prueba','precio_actual'=>'0.0216'],
    ['articulo_id'=>1002,'articulo'=>'Leche entera','unidad'=>'ml','unidad_actual'=>'ml','precio_anterior'=>null,'precio_nuevo'=>'0.0180','diferencia'=>null,'variacion_pct'=>null,'tipo'=>'inicial','created_at'=>'2026-11-01 08:00:00','responsable'=>'Ana de prueba','precio_actual'=>'0.0216'],
]];
$articulosHistorialPrecios = [['articulo_id'=>1002,'nombre'=>'Leche entera','unidad'=>'ml']];
$filtroHistorialArticulo = 1002;
$graficaPrecio = ['eventos'=>3,'desde'=>'2026-11-01','hasta'=>'2026-11-12','unidad'=>'ml','minimo'=>'0.0180','maximo'=>'0.0216','puntos'=>'8,48 50,28 92,8'];
$comparacionPrecios = [
    ['proveedor_id'=>1001,'nombre'=>'Lácteos del Valle','unidad_id'=>2,'activo'=>1,'precio_referencia'=>'0.0216','unidad'=>'ml'],
    ['proveedor_id'=>1004,'nombre'=>'Distribuidora del Centro','unidad_id'=>2,'activo'=>1,'precio_referencia'=>'0.0224','unidad'=>'ml'],
];
$tieneHistorialPrecios = true;
$asignaciones = [['articulo_id'=>1002,'nombre'=>'Leche entera','unidad'=>'ml','costo_maestro'=>'0.0200','costo'=>'0.0216','codigo'=>'LEC-48','preferente'=>1,'activo'=>1]];
$asignables = [];
$alertas = ['error'=>[],'exito'=>[],'advertencia'=>[]];
$title = 'Lácteos del Valle';
$proveedor = $p;
ob_start();
include $root . '/views/admin/almacenes/proveedor.php';
$contenido = ob_get_clean();
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fixture sintética · Historial de precios</title><link rel="stylesheet" href="/build/css/admin.css"><link rel="stylesheet" href="/build/css/admin/inventario.css"></head><body class="admin-body is-sidebar-collapsed"><div class="admin-shell"><aside class="admin-sidebar" aria-hidden="true"></aside><button class="admin-sidebar-backdrop" type="button" aria-hidden="true"></button><div class="admin-main"><main class="admin-content">'.$contenido.'</main></div></div><script src="/build/js/admin.js" defer></script><script src="/build/js/admin/inventario.js" defer></script></body></html>';
file_put_contents($root . '/public/_qa-precios-proveedores-fixture.html', $html);
echo "Preview fixture sintética generado en public/_qa-precios-proveedores-fixture.html\n";
