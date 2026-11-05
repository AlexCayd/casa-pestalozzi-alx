<?php
declare(strict_types=1);
/** Render real de la vista, sin DB; cantidades exactas y permisos visibles. */
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$count=0;$assert=static function(bool $ok,string $msg)use(&$count):void{if(!$ok)throw new RuntimeException($msg);$count++;};
/*
 * M3 (§16) retiró los saldos negativos: la venta deja el almacén en 0 y registra
 * el resto como faltante, y m4-faltantes.sql convirtió los negativos históricos.
 * Las aserciones de «-1.000 g» e `is-negative` se sustituyen por las de su
 * ausencia (sin trama, sin casilla de política) y por el aviso de faltantes.
 */
$render=static function(bool $editar,array $extra=[])use($root):string{
 $contextoAlmacenes='global';$nombreContexto='Global';$base='/admin/global/almacenes';$vista='existencias';$filtros=['almacen_ids'=>[1,2,3]];$csrf='fixture-csrf';$alertas=[];$puedeEditar=$editar;$puedeCrearAlmacen=$editar;$puedeCatalogo=$editar;
 $opcionesAlmacen=[['almacen_id'=>1,'nombre'=>'Central <seguro>','slug'=>'global','activo'=>1,'permitir_negativos'=>0],['almacen_id'=>2,'nombre'=>'Cocina','slug'=>'restaurante','activo'=>1,'permitir_negativos'=>0],['almacen_id'=>3,'nombre'=>'Obrador','slug'=>'panaderia','activo'=>1,'permitir_negativos'=>0]];
 $existencias=['total'=>3,'total_alertas'=>1,'pagina'=>1,'por_pagina'=>25,'articulos'=>[['articulo_id'=>10,'nombre'=>'Harina <especial>','unidad'=>'g','costo'=>'0.1234','activo'=>1,'contenido_pieza'=>null,'saldo_piezas'=>null,'saldo_total'=>'3.001','minimo_total'=>'3.000','alerta'=>true,'ambitos'=>[
  ['slug'=>'global','almacenes'=>[['almacen_id'=>1,'nombre'=>'Central <seguro>','saldo'=>'1.000','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>3,'alerta'=>false,'saldo_piezas'=>null]]],
  ['slug'=>'restaurante','almacenes'=>[['almacen_id'=>2,'nombre'=>'Cocina','saldo'=>'2.001','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>1,'alerta'=>false,'saldo_piezas'=>null]]],
  ['slug'=>'panaderia','almacenes'=>[['almacen_id'=>3,'nombre'=>'Obrador','saldo'=>'0.000','minimo'=>'1.000','activo'=>true,'asociacion_activa'=>true,'version'=>1,'alerta'=>true,'saldo_piezas'=>null]]]]],
  ['articulo_id'=>11,'nombre'=>'Sin asociación','unidad'=>'g','costo'=>'0.0000','activo'=>1,'contenido_pieza'=>null,'saldo_piezas'=>null,'saldo_total'=>'0.000','minimo_total'=>'0.000','alerta'=>false,'ambitos'=>[]],
  ['articulo_id'=>12,'nombre'=>'Vino tinto','unidad'=>'ml','costo'=>'0.3000','activo'=>1,'contenido_pieza'=>'750.000','saldo_piezas'=>'8 piezas + 450 ml','saldo_total'=>'6450.000','minimo_total'=>'0.000','alerta'=>false,'ambitos'=>[
  ['slug'=>'global','almacenes'=>[['almacen_id'=>1,'nombre'=>'Central <seguro>','saldo'=>'6450.000','minimo'=>'0.000','activo'=>true,'asociacion_activa'=>true,'version'=>7,'alerta'=>false,'saldo_piezas'=>'8 piezas + 450 ml']]]]]]];
 $almacenes=['almacenes'=>$opcionesAlmacen,'total'=>3];$movimientos=['movimientos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>25];$catalogo=['articulos'=>[],'total'=>0,'pagina'=>1,'por_pagina'=>100];
 extract($extra);
 ob_start();include $root.'/views/admin/almacenes/index.php';return (string)ob_get_clean();
};
$dom=static function(string $html):DOMXPath{$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();return new DOMXPath($doc);};
$html=$render(true);$x=$dom($html);
$assert(!str_contains($html,'Harina <especial>')&&str_contains($html,'Harina &lt;especial&gt;'),'La vista escapa nombres.');
$assert(str_contains($html,'3.001 g')&&str_contains($html,'2.001 g')&&str_contains($html,'0.000 g'),'Saldo total, por almacén y cero tienen lectura textual exacta.');
$assert($x->query('//button[@data-stock-tip and contains(@class,"almacen-segment")]')->length===4,'Cada almacén asociado tiene segmento accesible.');
$assert($x->query('//*[contains(@class,"is-negative")]')->length===0&&!str_contains($html,'Rayado'),'Sin negativos no queda trama ni leyenda de saldo negativo.');
$assert($x->query('//input[@name="permitir_negativos"]')->length===0&&!str_contains($html,'Permitir existencias negativas')&&!str_contains($html,'<th>Negativos</th>'),'La política de negativos ya no se ofrece ni se envía.');
$assert($x->query('//button[@data-stock-tip and @aria-label and @popovertarget]')->length===8,'Segmentos y etiquetas permiten detalle incluso cuando el saldo es cero.');
$assert(!str_contains($html,'↓')&&$x->query('//*[contains(@class,"almacen-balance-alert")]/svg[@aria-hidden="true"]')->length===1&&$x->query('//*[contains(@class,"almacen-balance-alert")]/span[contains(@class,"admin-visually-hidden") and contains(.,"Bajo mínimo")]')->length===1,'Bajo mínimo usa el SVG del catálogo con texto para lectores, no un glifo.');
$assert(str_contains($html,'Sin asociación en la selección'),'Estado sin asociación explícito.');
$assert($x->query('//input[@name="almacen_ids[]" and @type="checkbox" and @checked]')->length===3,'Selección inicial múltiple visible.');
$assert($x->query('//input[@name="version" and @value="3"]')->length===3,'Se conserva la versión de la asociación en formularios físicos.');
$assert($x->query('//form[.//input[@name="accion" and (@value="entrada" or @value="merma" or @value="ajuste")]]//input[@name="clave"]')->length===6,'Cada operación física conserva idempotencia.');
foreach(['Entrada','Merma','Conteo físico'] as $label)$assert(str_contains($html,$label),'Acción explícita '.$label);
// Piezas (Fase 1): lectura junto al saldo y captura en piezas sólo si hay contenido.
$assert($x->query('//td[@data-label="Saldo seleccionado"]//small[contains(@class,"almacen-piezas-lectura") and .="8 piezas + 450 ml"]')->length===1,'El saldo total muestra su lectura en piezas.');
$assert($x->query('//ul[contains(@class,"almacen-balances")]//small[.="8 piezas + 450 ml"]')->length===1,'El desglose por almacén muestra su lectura en piezas.');
$assert($x->query('//form[@data-stock-contenido="750.000"]//input[@name="piezas"]')->length===3&&$x->query('//form[.//input[@name="articulo_id" and @value="10"]]//input[@name="piezas"]')->length===0,'Entrada, merma y conteo ofrecen piezas sólo al artículo con contenido.');
$assert($x->query('//form[@data-stock-contenido and .//input[@name="accion" and @value="entrada"]]//input[@name="costo_por"]')->length===2&&$x->query('//form[.//input[@name="articulo_id" and @value="10"]]//input[@name="costo_por"]')->length===0,'El costo por pieza sólo se ofrece con contenido; sin él no se envía costo_por.');
$assert($x->query('//form[@data-stock-contenido and .//input[@name="accion" and @value="ajuste"]][.//input[@name="piezas" and @value="8"]][.//input[@name="cantidad" and @value="450.000"]]')->length===1,'El conteo en piezas parte del saldo vigente: 8 piezas + 450.000 ml.');
$assert($x->query('//form[.//input[@name="accion" and @value="ajuste"] and .//input[@name="articulo_id" and @value="10"]]//input[@name="cantidad" and @min="0"]')->length===1,'El conteo ya no admite cantidades negativas.');
// Aviso de faltantes: sólo con pendientes, antes de la selección y con enlace a la bandeja.
$assert($x->query('//*[contains(@class,"almacen-faltantes-aviso")]')->length===0,'Sin faltantes pendientes no hay aviso.');
$aviso=$render(true,['faltantesPendientes'=>3]);$xa=$dom($aviso);
$assert($xa->query('//aside[contains(@class,"almacen-faltantes-aviso") and contains(normalize-space(.),"3 faltantes por conciliar")]')->length===1,'El aviso dice cuántos faltantes hay por conciliar.');
$assert($xa->query('//aside[contains(@class,"almacen-faltantes-aviso")]//a[contains(@href,"vista=faltantes") and .="Conciliar faltantes"]')->length===1,'El aviso enlaza a la bandeja de faltantes.');
$assert(strpos($aviso,'almacen-faltantes-aviso')<strpos($aviso,'data-warehouse-selection'),'El aviso va antes que la selección de almacenes.');
$assert(str_contains($render(true,['faltantesPendientes'=>1]),'faltante por conciliar</p>'),'Singular correcto con un faltante.');
$assert($dom($render(false,['faltantesPendientes'=>2]))->query('//aside//a[.="Ver faltantes"]')->length===1,'Solo consulta ve los faltantes sin la acción de conciliar.');
$read=$render(false);$assert(!str_contains($read,'data-operation="entrada"')&&!str_contains($read,'data-admin-modal-open="almacen-asociar"'),'Solo lectura no tiene operaciones ejecutables.');
$assert(str_contains($read,'Movimientos')&&str_contains($read,'data-stock-tip'),'Consulta conserva historial y distribución.');
$assert(str_contains($html,'accion" value="transferencia_solicitar"')&&str_contains($html,'name="lineas[0][cantidad]"'),'Solicitud de transferencia explícita con cantidades por línea.');
$assert(!str_contains($read,'accion" value="transferencia_'),'Solo lectura consulta transferencias sin formularios de escritura.');
// Bandeja de faltantes (Fase 2).
$faltantes=[
 ['id'=>41,'version'=>2,'estado'=>'pendiente','origen'=>'venta','articulo_id'=>12,'articulo_nombre'=>'Vino tinto','unidad'=>'ml','contenido_pieza'=>'750.000','almacen_id'=>2,'almacen_nombre'=>'Barra <1>','cantidad'=>'1500.000','cantidad_conciliada'=>'0.000','cantidad_en_confirmacion'=>'0.000','restante'=>'1500.000','ticket_id'=>77,'item_nombre'=>'Botella de tinto','item_fecha'=>'2026-11-04 14:32:10','creado_at'=>'2026-11-04 14:32:10','asignaciones'=>[['almacen_nombre'=>'Central','contexto'=>'global','cantidad'=>'250.000','estado'=>'rechazada','motivo'=>'Sin existencias <reales>','transferencia_id'=>9]],
  'origenes'=>[['almacen_id'=>2,'nombre'=>'Barra <1>','contexto'=>'global','propio'=>true,'requiere_confirmacion'=>false,'saldo'=>'300.000'],['almacen_id'=>6,'nombre'=>'Vacío','contexto'=>'global','propio'=>true,'requiere_confirmacion'=>false,'saldo'=>'0.000'],['almacen_id'=>5,'nombre'=>'Bodega Panadería','contexto'=>'panaderia','propio'=>false,'requiere_confirmacion'=>true,'saldo'=>'987.654']],'disponible_propio'=>'300.000','cubierto_por_propios'=>false],
 ['id'=>42,'version'=>1,'estado'=>'en_confirmacion','origen'=>'migracion','articulo_id'=>10,'articulo_nombre'=>'Harina','unidad'=>'g','contenido_pieza'=>null,'almacen_id'=>2,'almacen_nombre'=>'Cocina','cantidad'=>'1.000','cantidad_conciliada'=>'0.000','cantidad_en_confirmacion'=>'1.000','restante'=>'0.000','ticket_id'=>null,'item_nombre'=>null,'item_fecha'=>null,'creado_at'=>'2026-11-03 09:00:00','asignaciones'=>[['almacen_nombre'=>'Central','contexto'=>'global','cantidad'=>'1.000','estado'=>'en_confirmacion','motivo'=>null,'transferencia_id'=>10]],'origenes'=>[],'disponible_propio'=>'0.000','cubierto_por_propios'=>false]];
$bandeja=$render(true,['vista'=>'faltantes','faltantes'=>$faltantes,'faltantesPendientes'=>1]);$xb=$dom($bandeja);
$assert($xb->query('//*[@id="almacen-faltantes" and @data-admin-modal]')->length===1&&str_contains($bandeja,'data-initial-view="faltantes"'),'La vista faltantes abre su bandeja en el modal compartido.');
$assert($dom($html)->query('//*[@id="almacen-faltantes"]')->length===0,'La bandeja sólo existe cuando se consulta.');
$form='//form[.//input[@name="accion" and @value="conciliar_faltante"]]';
$assert($xb->query($form)->length===1,'Sólo el faltante pendiente ofrece conciliación.');
foreach(['admin_csrf'=>'fixture-csrf','faltante_id'=>'41','version'=>'2','volver_vista'=>'faltantes','asignaciones[0][almacen_id]'=>'2','asignaciones[2][almacen_id]'=>'5'] as $campo=>$valor)$assert($xb->query($form.'//input[@name="'.$campo.'" and @value="'.$valor.'"]')->length===1,'Conciliar envía '.$campo.'.');
$assert(preg_match('/^[0-9a-f]{32}$/',(string)$xb->query($form.'//input[@name="clave"]')->item(0)?->getAttribute('value'))===1,'Clave idempotente hex de 32 por render.');
$assert($xb->query($form.'//input[@name="asignaciones[0][cantidad]" and @data-faltante-cantidad and @max="300.000"]')->length===1&&$xb->query($form.'//input[@name="asignaciones[2][cantidad]" and not(@max)]')->length===1,'Una fila por origen; el propio acota a su saldo.');
$assert(str_contains($bandeja,'Saldo 300.000 ml')&&!str_contains($bandeja,'987.654'),'Sólo se muestra el saldo de almacenes propios, nunca el ajeno.');
$assert(str_contains($bandeja,'Requiere su confirmación')&&str_contains($bandeja,'Otros negocios · su dueño confirma'),'El origen ajeno explica que su dueño confirma.');
$assert(str_contains($bandeja,'Ningún almacén propio cubre este faltante.'),'Se marca cuando los propios no alcanzan.');
$assert(str_contains($bandeja,'2 piezas')&&str_contains($bandeja,'Ticket #77')&&str_contains($bandeja,'Botella de tinto'),'El faltante muestra piezas, ticket e item de origen.');
$assert(str_contains($bandeja,'Saldo negativo convertido al retirar existencias negativas.'),'El faltante de migración se explica.');
$assert(str_contains($bandeja,'Sin existencias &lt;reales&gt;')&&str_contains($bandeja,'Barra &lt;1&gt;'),'Motivos y nombres se escapan.');
$assert($xb->query('//*[@data-faltante-suma and @role="status"]')->length===1,'La suma del reparto se anuncia.');
$assert($xb->query($form.'//input[@value="6"]')->length===0&&str_contains($bandeja,'Sin saldo disponible'),'Un origen propio sin saldo no envía fila (un almacen_id sin cantidad se rechazaría).');
$soloVer=$render(false,['vista'=>'faltantes','faltantes'=>$faltantes]);
$assert(!str_contains($soloVer,'conciliar_faltante')&&str_contains($soloVer,'Conciliar requiere permiso de edición'),'Solo consulta ve la bandeja sin formulario.');
$assert(str_contains($render(true,['vista'=>'faltantes','faltantes'=>[]]),'No hay faltantes por conciliar'),'Bandeja vacía con estado explícito.');
// Regularización: el dueño del origen confirma o rechaza con motivo.
$transferencias=[['id'=>9,'tipo'=>'regularizacion','estado'=>'solicitada','version'=>4,'origen_nombre'=>'Central','origen_contexto'=>'global','destino_nombre'=>'Barra','destino_contexto'=>'restaurante','creada_at'=>'2026-11-04 15:00:00','lineas'=>[['articulo_id'=>12,'nombre'=>'Vino tinto','unidad'=>'ml','cantidad_solicitada'=>'250.000','cantidad_enviada'=>'0.000','cantidad_recibida'=>'0.000','cantidad_retornada'=>'0.000','transito'=>'0.000']],'historial'=>[],'acciones'=>['confirmar','rechazar']]];
$xt=$dom($render(true,['transferencias'=>$transferencias,'transferenciasTotal'=>1]));
$assert($xt->query('//summary[contains(.,"Regularización de faltante #9")]')->length===1,'La regularización se rotula como tal.');
$assert($xt->query('//form[.//input[@name="accion" and @value="transferencia_confirmar"] and .//input[@name="version" and @value="4"]]//textarea[@name="motivo" and @required and .="Confirmado"]')->length===1,'Confirmar trae un motivo editable por omisión.');
$assert($xt->query('//form[.//input[@name="accion" and @value="transferencia_rechazar"]]//textarea[@name="motivo" and @required and .=""]')->length===1,'Rechazar pide el motivo.');
$assert($xt->query('//form[.//input[@name="accion" and (@value="transferencia_autorizar" or @value="transferencia_enviar")]]')->length===0,'Una regularización no ofrece el flujo ordinario.');
$assert($xt->query('//*[contains(@class,"almacen-transfer-route__sep")]/span[contains(@class,"admin-visually-hidden")]')->length===2,'La ruta usa el SVG del catálogo con texto para lectores, no una flecha Unicode.');
echo "Almacenes representación: {$count} comprobaciones OK.\n";
