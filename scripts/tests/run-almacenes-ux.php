<?php
declare(strict_types=1);
/** Render real de la vista, sin DB; cantidades exactas y permisos visibles. */
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$count=0;$assert=static function(bool $ok,string $msg)use(&$count):void{if(!$ok)throw new RuntimeException($msg);$count++;};
$render=static function(bool $editar)use($root):string{
 $contextoAlmacenes='global';$nombreContexto='Global';$base='/admin/global/almacenes';$vista='existencias';$filtros=['almacen_ids'=>[1,2,3]];$csrf='fixture-csrf';$alertas=[];$puedeEditar=$editar;$puedeCrearAlmacen=$editar;$puedeCatalogo=$editar;
 $opcionesAlmacen=[['almacen_id'=>1,'nombre'=>'Central <seguro>','slug'=>'global','activo'=>1,'permitir_negativos'=>1],['almacen_id'=>2,'nombre'=>'Cocina','slug'=>'restaurante','activo'=>1,'permitir_negativos'=>0],['almacen_id'=>3,'nombre'=>'Obrador','slug'=>'panaderia','activo'=>1,'permitir_negativos'=>0]];
 $existencias=['total'=>2,'total_alertas'=>1,'pagina'=>1,'por_pagina'=>25,'articulos'=>[['articulo_id'=>10,'nombre'=>'Harina <especial>','unidad'=>'g','costo'=>'0.1234','activo'=>1,'saldo_total'=>'1.001','minimo_total'=>'3.000','alerta'=>true,'ambitos'=>[
  ['slug'=>'global','almacenes'=>[['almacen_id'=>1,'nombre'=>'Central <seguro>','saldo'=>'-1.000','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>3,'alerta'=>true]]],
  ['slug'=>'restaurante','almacenes'=>[['almacen_id'=>2,'nombre'=>'Cocina','saldo'=>'2.001','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>1,'alerta'=>false]]],
  ['slug'=>'panaderia','almacenes'=>[['almacen_id'=>3,'nombre'=>'Obrador','saldo'=>'0.000','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>1,'alerta'=>true]]]]],
  ['articulo_id'=>11,'nombre'=>'Sin asociación','unidad'=>'g','costo'=>'0.0000','activo'=>1,'saldo_total'=>'0.000','minimo_total'=>'0.000','alerta'=>false,'ambitos'=>[]]]];
 $almacenes=['almacenes'=>$opcionesAlmacen,'total'=>3];$movimientos=['movimientos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>25];$catalogo=['articulos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>100];
 ob_start();include $root.'/views/admin/almacenes/index.php';return (string)ob_get_clean();
};
$html=$render(true);$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();$x=new DOMXPath($doc);
$assert(!str_contains($html,'Harina <especial>')&&str_contains($html,'Harina &lt;especial&gt;'),'La vista escapa nombres.');
$assert(str_contains($html,'1.001 g')&&str_contains($html,'-1.000 g')&&str_contains($html,'0.000 g'),'Saldo neto, negativo y cero tienen lectura textual exacta.');
$assert($x->query('//button[@data-stock-tip and contains(@class,"almacen-segment")]')->length===3,'Cada almacén de Global tiene segmento accesible.');
$assert($x->query('//button[contains(@class,"is-negative")]')->length===1,'El saldo negativo tiene trama propia.');
$assert($x->query('//button[@data-stock-tip and @aria-label and @popovertarget]')->length===6,'Segmentos y etiquetas permiten detalle incluso cuando el saldo es cero.');
$assert(str_contains($html,'Sin asociación en la selección'),'Estado sin asociación explícito.');
$assert($x->query('//input[@name="almacen_ids[]" and @type="checkbox" and @checked]')->length===3,'Selección inicial múltiple visible.');
$assert($x->query('//input[@name="version" and @value="3"]')->length===3,'Se conserva la versión de la asociación en formularios físicos.');
$assert($x->query('//input[@name="clave"]')->length===3,'Cada operación conserva idempotencia.');
foreach(['Entrada','Merma','Conteo físico'] as $label)$assert(str_contains($html,$label),'Acción explícita '.$label);
$read=$render(false);$assert(!str_contains($read,'data-operation="entrada"')&&!str_contains($read,'data-admin-modal-open="almacen-asociar"'),'Solo lectura no tiene operaciones ejecutables.');
$assert(str_contains($read,'Movimientos')&&str_contains($read,'data-stock-tip'),'Consulta conserva historial y distribución.');
$assert(!str_contains($html,'accion" value="transfer'),'No se inventan formularios de transferencia.');
echo "Almacenes representación: {$count} comprobaciones OK.\n";
