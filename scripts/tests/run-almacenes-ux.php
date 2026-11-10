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
 // Variantes del fixture sin copiarlo: 'ajustar' recibe las existencias y las devuelve cambiadas.
 if(isset($extra['ajustar'])){$existencias=$extra['ajustar']($existencias);unset($extra['ajustar']);}
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
$assert($x->query('//*[@id="almacen-alcance"]//input[@name="almacen_ids[]" and @type="checkbox" and @checked]')->length===3,'Selección inicial múltiple visible.');
$assert($x->query('//input[@name="version" and @value="3"]')->length===2,'Se conserva la versión de la asociación en formularios físicos.');
$assert($x->query('//form[.//input[@name="accion" and (@value="entrada" or @value="merma")]]//input[@name="clave"]')->length===4,'Cada operación física conserva idempotencia.');
foreach(['Entrada','Merma'] as $label)$assert(str_contains($html,$label),'Acción explícita '.$label);
$assert(!str_contains($html,'Conteo físico')&&!str_contains($html,'conteo físico')&&$x->query('//input[@name="accion" and @value="ajuste"]')->length===0&&$x->query('//*[contains(@data-admin-modal-open,"-ajuste")]')->length===0,'El conteo físico se retiró: ni botón, ni diálogo, ni formulario (auditoría A-4).');
// Jerarquía de acciones: la cabecera sólo cambia existencias (una principal), la fila
// ofrece las operaciones físicas como grupo y lo demás va en «Más», un popover nativo.
$cab='//header[contains(@class,"admin-page__header")]';
$assert($x->query($cab.'//*[contains(@class,"admin-btn--primary")]')->length===1&&$x->query($cab.'//button[contains(@class,"admin-btn--primary") and @data-admin-modal-open="almacen-asociar" and contains(.,"Asociar artículo")]')->length===1,'Una sola acción principal en cabecera: Asociar artículo.');
$assert($x->query($cab.'//button[@data-admin-modal-open="almacen-elegir-entrada"]')->length===1&&$x->query($cab.'//*[@data-admin-modal-open="almacen-config" or @data-admin-modal-open="almacen-transferencias"]')->length===0,'La cabecera no mezcla configuración ni consulta con las acciones.');
$assert($x->query('//*[contains(@class,"almacen-tools")]//button[@data-admin-modal-open="almacen-movimientos"]|//*[contains(@class,"almacen-tools")]//button[@data-admin-modal-open="almacen-transferencias"]|//*[contains(@class,"almacen-tools")]//button[@data-admin-modal-open="almacen-config" and contains(.,"Crear o configurar almacenes")]')->length===3,'Movimientos, transferencias y almacenes siguen a un clic en la franja de herramientas.');
$assert($dom($render(true,['puedeNuevoArticulo'=>true]))->query('//section[contains(@class,"almacen-list")]//a[contains(@href,"/catalogo/nuevo") and contains(.,"Nuevo artículo")]')->length===1&&!str_contains($html,'/catalogo/nuevo'),'Nuevo artículo vive junto al catálogo y sólo con su permiso.');
$ops='//div[contains(@class,"almacen-ops") and @role="group" and @aria-label]';
// Cada operación tiene su propio diálogo (articulo-{id}-{operación}) con sólo su
// formulario: el botón ya no lleva data-operation para desplegar un details dentro de
// un modal único, sino que abre directamente el diálogo de esa operación.
$assert($x->query($ops)->length===2&&$x->query($ops.'/button[@data-admin-modal-open]')->length===4,'Entrada y merma forman un grupo etiquetado por fila operable.');
$accionModal=['entrada'=>'entrada','merma'=>'merma','configurar'=>'configurar_asociacion'];
foreach($x->query('//*[@data-admin-modal-open and starts-with(@data-admin-modal-open,"articulo-")]') as $b){if(!$b instanceof DOMElement)continue;$id=$b->getAttribute('data-admin-modal-open');$op=substr($id,strrpos($id,'-')+1);
 $m='//*[@id="'.$id.'" and @data-admin-modal]';
 $assert(isset($accionModal[$op])&&$x->query($m)->length===1,'El botón '.$id.' abre un diálogo existente de una operación conocida.');
 $acciones=array_unique(array_map(static fn($n)=>$n->getAttribute('value'),iterator_to_array($x->query($m.'//input[@name="accion"]'))));
 $assert($acciones===[$accionModal[$op]],'El diálogo '.$id.' sólo contiene formularios de su operación.');
 $assert($x->query($m.'//*[@role="dialog" and @aria-modal="true" and @aria-labelledby="'.$id.'-title"]')->length===1&&$x->query($m.'//button[@data-admin-modal-close and @aria-label="Cerrar ventana"]')->length===1,'El diálogo '.$id.' conserva rol, título y cierre accesibles.');
 $assert($x->query($m.'//form')->length===$x->query($m.'//form//button[@type="button" and @data-admin-modal-close and .="Cancelar"]')->length&&$x->query($m.'//form//button[@type="submit" and contains(@class,"admin-btn--primary")]')->length===$x->query($m.'//form')->length,'Cada formulario de '.$id.' tiene su acción principal y Cancelar.');}
$assert($x->query('//h3[@id="articulo-10-entrada-title" and .="Registrar entrada · Harina <especial>"]')->length===1&&$x->query('//h3[@id="articulo-12-merma-title" and .="Registrar merma · Vino tinto"]')->length===1&&$x->query('//h3[@id="articulo-10-configurar-title" and .="Configurar asociación · Harina <especial>"]')->length===1,'Cada diálogo se titula con su operación y el artículo.');
$assert($x->query('//*[@data-admin-modal and starts-with(@id,"articulo-")]//details')->length===0,'Las operaciones ya no se apilan en details dentro del diálogo.');
$assert($x->query('//*[@data-warehouse-picker]')->length===0&&$x->query('//*[@data-warehouse-panel and @hidden]')->length===0,'Con un solo almacén propio no hay selector y su formulario está visible.');
$mas='//button[contains(@class,"almacen-more") and @popovertarget and @aria-expanded="false"]';
$assert($x->query($mas)->length===3,'Cada fila tiene un único «Más».');
foreach($x->query($mas) as $b){if(!$b instanceof DOMElement)continue;$id=$b->getAttribute('popovertarget');$assert($b->getAttribute('aria-controls')===$id&&$x->query('//*[@id="'.$id.'" and @popover]')->length===1&&str_starts_with(trim($b->textContent),'Más'),'«Más» controla su popover y su nombre empieza por el texto visible.');}
$assert($x->query('//*[@popover]//button[@data-admin-modal-open="articulo-10-configurar" and contains(.,"Configurar asociación")]')->length===1&&$x->query('//*[@popover]//button[@data-admin-modal-open="articulo-12-configurar"]')->length===1,'Configurar asociación abre su propio diálogo desde «Más».');
$assert($x->query('//*[@id="almacen-elegir-entrada"]//button[@data-admin-modal-open="articulo-10-entrada"]')->length===1&&$x->query('//*[@id="almacen-elegir-entrada"]//button[@data-admin-modal-open="articulo-12-entrada"]')->length===1,'«Registrar entrada» de la cabecera lleva al diálogo de entrada de cada artículo.');
// Varios almacenes propios: cada diálogo trae su selector y un panel oculto por almacén;
// una asociación inactiva sólo se configura, sin entrada ni merma.
$ajustarMulti=static function(array $multi):array{$multi['articulos'][0]['ambitos'][0]['almacenes'][]=['almacen_id'=>4,'nombre'=>'Bodega fría','saldo'=>'5.500','minimo'=>'0.000','activo'=>true,'asociacion_activa'=>true,'version'=>2,'alerta'=>false,'saldo_piezas'=>null];
$multi['articulos'][2]['ambitos'][0]['almacenes'][]=['almacen_id'=>4,'nombre'=>'Bodega fría','saldo'=>'0.000','minimo'=>'0.000','activo'=>true,'asociacion_activa'=>false,'version'=>1,'alerta'=>false,'saldo_piezas'=>null];
$multi['articulos'][]=['articulo_id'=>13,'nombre'=>'Solo inactiva','unidad'=>'g','costo'=>'0.0000','activo'=>1,'contenido_pieza'=>null,'saldo_piezas'=>null,'saldo_total'=>'0.000','minimo_total'=>'0.000','alerta'=>false,'ambitos'=>[['slug'=>'global','almacenes'=>[['almacen_id'=>1,'nombre'=>'Central <seguro>','saldo'=>'0.000','minimo'=>'0.000','activo'=>true,'asociacion_activa'=>false,'version'=>1,'alerta'=>false,'saldo_piezas'=>null]]]]];return $multi;};
$xm=$dom($render(true,['ajustar'=>$ajustarMulti]));
foreach(['entrada','merma','configurar'] as $op){$m='//*[@id="articulo-10-'.$op.'"]';
 $assert($xm->query($m.'//select[@data-warehouse-picker and @id="articulo-10-'.$op.'-almacen"]/option[@value=""]')->length===1&&$xm->query('//label[@for="articulo-10-'.$op.'-almacen" and .="Almacén físico"]')->length===1,'Diálogo '.$op.' con varios almacenes: selector propio etiquetado y sin almacén preelegido.');
 $assert($xm->query($m.'//*[@data-warehouse-panel="1" and @hidden]//p[contains(@class,"almacen-modal__balance") and contains(.,"Saldo 1.000 g")]')->length===1&&$xm->query($m.'//*[@data-warehouse-panel="4" and @hidden]//p[contains(@class,"almacen-modal__balance") and contains(.,"Saldo 5.500 g")]')->length===1,'Diálogo '.$op.': cada almacén muestra su saldo junto a su formulario.');}
$assert($xm->query('//*[@id="articulo-12-configurar"]//select[@data-warehouse-picker]/option[@value!=""]')->length===2&&$xm->query('//*[@id="articulo-12-entrada"]//select[@data-warehouse-picker]')->length===0&&$xm->query('//*[@id="articulo-12-entrada"]//input[@name="almacen_id" and @value="4"]')->length===0,'La asociación inactiva se configura pero no admite operaciones físicas.');
$assert($xm->query('//*[@id="articulo-13-configurar"]')->length===1&&$xm->query('//*[@id="articulo-13-entrada"]')->length===0&&$xm->query('//tr[.//strong[.="Solo inactiva"]]//*[contains(@class,"almacen-ops")]')->length===0&&$xm->query('//tr[.//strong[.="Solo inactiva"]]//*[contains(@class,"almacen-unassociated") and .="Asociación inactiva"]')->length===1,'Sin asociaciones activas la fila lo dice y no ofrece un diálogo vacío.');
$assert($x->query('//*[@popover]//a[contains(@href,"vista=movimientos") and contains(.,"Movimientos")]')->length===3,'Movimientos del artículo desde «Más» en cada fila.');
$assert($x->query('//a[contains(@href,"asociar=11") and contains(@href,"articulo_id=11") and contains(.,"Asociar a un almacén")]')->length===1&&$x->query('//a[contains(@href,"asociar=10") or contains(@href,"asociar=12")]')->length===0,'Sólo el artículo sin almacén propio ofrece asociarse desde su fila (ruta ?asociar existente).');
$assert(!preg_match('/[\x{2190}-\x{21FF}\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}]/u',$html),'Sin glifos ni emojis como iconos.');
$sinAlmacen=$dom($render(true,['opcionesAlmacen'=>[['almacen_id'=>1,'nombre'=>'Central','slug'=>'global','activo'=>0,'permitir_negativos'=>0]]]));
$assert($sinAlmacen->query($cab.'//button[contains(@class,"admin-btn--primary") and @data-admin-modal-open="almacen-config" and contains(.,"Crear almacén")]')->length===1&&$sinAlmacen->query($cab.'//*[@data-admin-modal-open="almacen-asociar"]')->length===0,'Sin almacenes propios activos, crear uno es la acción principal.');
// Piezas (Fase 1): lectura junto al saldo y captura en piezas sólo si hay contenido.
$assert($x->query('//td[@data-label="Saldo seleccionado"]//small[contains(@class,"almacen-piezas-lectura") and .="8 piezas + 450 ml"]')->length===1,'El saldo total muestra su lectura en piezas.');
$assert($x->query('//ul[contains(@class,"almacen-balances")]//small[.="8 piezas + 450 ml"]')->length===1,'El desglose por almacén muestra su lectura en piezas.');
$assert($x->query('//form[@data-stock-contenido="750.000"]//input[@name="piezas"]')->length===2&&$x->query('//form[.//input[@name="articulo_id" and @value="10"]]//input[@name="piezas"]')->length===0,'Entrada y merma ofrecen piezas sólo al artículo con contenido.');
$assert($x->query('//form[@data-stock-contenido and .//input[@name="accion" and @value="entrada"]]//input[@name="costo_por"]')->length===2&&$x->query('//form[.//input[@name="articulo_id" and @value="10"]]//input[@name="costo_por"]')->length===0,'El costo por pieza sólo se ofrece con contenido; sin él no se envía costo_por.');
// Aviso de faltantes: sólo con pendientes, antes de la selección y con enlace a la bandeja.
$assert($x->query('//*[contains(@class,"almacen-faltantes-aviso")]')->length===0,'Sin faltantes pendientes no hay aviso.');
$aviso=$render(true,['faltantesPendientes'=>3]);$xa=$dom($aviso);
$assert($xa->query('//aside[contains(@class,"almacen-faltantes-aviso") and contains(normalize-space(.),"3 faltantes por conciliar")]')->length===1,'El aviso dice cuántos faltantes hay por conciliar.');
$assert($xa->query('//aside[contains(@class,"almacen-faltantes-aviso")]//a[contains(@href,"vista=faltantes") and .="Conciliar faltantes"]')->length===1,'El aviso enlaza a la bandeja de faltantes.');
$assert(strpos($aviso,'almacen-faltantes-aviso')<strpos($aviso,'data-warehouse-selection'),'El aviso va antes que la selección de almacenes.');
$assert(str_contains($render(true,['faltantesPendientes'=>1]),'faltante por conciliar</p>'),'Singular correcto con un faltante.');
$assert($dom($render(false,['faltantesPendientes'=>2]))->query('//aside//a[.="Ver faltantes"]')->length===1,'Solo consulta ve los faltantes sin la acción de conciliar.');
$read=$render(false);$assert(!str_contains($read,'-entrada"')&&!str_contains($read,'accion" value="entrada"')&&!str_contains($read,'articulo-10-')&&!str_contains($read,'data-admin-modal-open="almacen-asociar"'),'Solo lectura no tiene operaciones ejecutables.');
$assert(str_contains($read,'Movimientos')&&str_contains($read,'data-stock-tip'),'Consulta conserva historial y distribución.');
$xr=$dom($read);
$assert($xr->query('//*[contains(@class,"almacen-ops")]')->length===0&&$xr->query('//a[contains(@href,"asociar=")]')->length===0&&$xr->query('//header[contains(@class,"admin-page__header")]//*[contains(@class,"admin-btn--primary")]')->length===0,'Solo consulta: sin operaciones, sin asociar y sin acción principal.');
$assert($xr->query('//*[@popover]//a[contains(@href,"vista=movimientos")]')->length===3&&$xr->query('//*[contains(@class,"almacen-tools")]//button[@data-admin-modal-open="almacen-config" and contains(.,"Ver almacenes")]')->length===1,'Solo consulta conserva historial por fila y la consulta de almacenes.');
$assert(str_contains($html,'accion" value="transferencia_solicitar"')&&str_contains($html,'name="lineas[0][cantidad]"'),'Solicitud de transferencia explícita con cantidades por línea.');
$assert(!str_contains($read,'accion" value="transferencia_'),'Solo lectura consulta transferencias sin formularios de escritura.');
// Modal «Almacenes físicos»: título una sola vez, alta como acción principal sólo con
// facultad, tabla agrupada por propietario (el propio primero) y estado con insignia.
$cfg='//*[@id="almacen-config"]';
$assert($x->query($cfg.'//h3')->length===1,'El título del modal no se repite dentro.');
$assert($x->query($cfg.'//details[contains(@class,"almacen-config-alta")]/summary[contains(@class,"admin-btn--primary") and contains(.,"Nuevo almacén")]')->length===1&&strpos($html,'almacen-config-alta')<strpos($html,'almacen-config__tabla'),'Con facultad de crear, «Nuevo almacén» es el primario del modal y va antes de la lista.');
$assert($x->query($cfg.'//input[@type="radio" and @name="propietario" and @required]')->length===3&&$x->query($cfg.'//input[@name="propietario" and @checked]')->length===0&&$x->query($cfg.'//select[@name="propietario"]')->length===0,'El propietario se elige a la vista, sin valor previo.');
$duenos=array_map(static fn($n)=>trim(preg_replace('/\s+/',' ',$n->textContent)),iterator_to_array($x->query($cfg.'//tr[contains(@class,"almacen-config__dueno")]/th')));
$assert(count($duenos)===3&&str_starts_with($duenos[0],'Global Propio')&&str_starts_with($duenos[1],'Restaurante Otro negocio')&&str_starts_with($duenos[2],'Panadería Otro negocio'),'Un grupo por propietario: el propio primero, los demás como otro negocio.');
$assert(substr_count($html,'Solo consulta: los opera y configura')===2&&!str_contains($html,'Consulta autorizada; la operación pertenece a su ámbito.'),'La nota de consulta va una vez por grupo ajeno, no en cada fila.');
$assert($x->query($cfg.'//td[contains(@class,"almacen-config__estado")]/span[contains(@class,"admin-status") and .="Activo"]')->length===3,'El estado se muestra como insignia con texto.');
$assert($x->query($cfg.'//summary[contains(@class,"admin-btn") and contains(.,"Configurar")]')->length===1&&$x->query($cfg.'//a[contains(@class,"admin-btn") and contains(@href,"almacen_id=")]')->length===3,'Configurar es botón sólo en el almacén propio; Ver artículos es botón en todos.');
$assert($x->query($cfg.'//form[@data-confirm-delete and @data-confirm-eyebrow="Eliminar almacén" and @data-confirm-primary="Eliminar almacén" and @data-confirm-require="Central <seguro>" and @data-confirm-description][.//input[@name="accion" and @value="eliminar_almacen"]]')->length===1,'Eliminar pide confirmación propia de almacén (no la de usuarios) y escribir el nombre.');
$rest=$dom($render(true,['contextoAlmacenes'=>'restaurante','nombreContexto'=>'Restaurante','base'=>'/admin/almacenes','puedeCrearAlmacen'=>false]));
$assert($rest->query($cfg.'//details[contains(@class,"almacen-config-alta")]')->length===0&&$rest->query($cfg.'//input[@name="accion" and @value="crear_almacen"]')->length===0,'Sin facultad de crear no hay alta en el modal.');
$assert(str_starts_with(trim(preg_replace('/\s+/',' ',$rest->query($cfg.'//tr[contains(@class,"almacen-config__dueno")]/th')->item(0)->textContent)),'Restaurante Propio')&&$rest->query($cfg.'//input[@name="accion" and @value="configurar_almacen"]')->length===1,'Restaurante ve primero lo propio y sólo configura lo suyo.');
$assert($xr->query($cfg.'//details|'.$cfg.'//form')->length===0&&$xr->query($cfg.'//a[contains(@href,"almacen_id=")]')->length===3&&str_contains($read,'Solo consulta: tu permiso no incluye configurarlos.'),'Solo consulta: sin alta ni configuración, con Ver artículos y una sola nota.');
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
$assert($xt->query('//form[.//input[@name="accion" and (@value="transferencia_enviar" or @value="transferencia_recibir")]]')->length===0,'Una regularización no ofrece el flujo ordinario.');
$assert($xt->query('//*[contains(@class,"almacen-transfer-route__sep")]/span[contains(@class,"admin-visually-hidden")]')->length===2,'La ruta usa el SVG del catálogo con texto para lectores, no una flecha Unicode.');
// «Nueva transferencia»: acción principal del modal, con forma de botón y por encima del historial.
$toggle='//details[contains(@class,"almacen-transfer-request")]/summary[contains(@class,"admin-btn") and contains(@class,"admin-btn--primary")]';
$assert($xt->query($toggle.'[normalize-space(.)="Nueva transferencia"]')->length===1&&$xt->query($toggle.'/svg[@aria-hidden="true"]')->length===2,'La transferencia nueva es un summary nativo con forma de botón primario e iconos SVG del catálogo.');
$xc=$dom($render(true,['transferencias'=>[],'transferenciasTotal'=>0,'contrapartes'=>[['id'=>1,'nombre'=>'Central','contexto'=>'global'],['id'=>2,'nombre'=>'Cocina','contexto'=>'restaurante']]]));
$assert($xc->query('//form[@data-transfer-request and @data-super="0" and @data-contexto]//select[@name="origen_id"]/option[@value="2" and @data-contexto="restaurante"]')->length===1&&$xt->query('//form[@data-transfer-request]//*[@data-transfer-hint and @aria-live="polite"]')->length===1&&$xt->query('//form[@data-transfer-request]//button[@data-transfer-submit and normalize-space(.)="Registrar transferencia"]')->length===1,'El formulario anticipa el resultado con el negocio de cada almacén.');
$assert($dom($render(true,['transferencias'=>[],'transferenciasTotal'=>0,'transferenciasSuper'=>true]))->query('//form[@data-transfer-request and @data-super="1"]//button[normalize-space(.)="Transferir ahora"]')->length===1,'Al superadministrador se le anuncia el paso único.');
// Entre negocios: sin «autorizar»; el destino recibe con todo lo enviado precargado.
$enviada=[['id'=>12,'tipo'=>'ordinaria','estado'=>'enviada','version'=>1,'origen_nombre'=>'Central','origen_contexto'=>'global','destino_nombre'=>'Producción','destino_contexto'=>'panaderia','creada_at'=>'2026-11-08 09:00:00','lineas'=>[['articulo_id'=>12,'nombre'=>'Vino tinto','unidad'=>'ml','cantidad_solicitada'=>'750.000','cantidad_enviada'=>'750.000','cantidad_recibida'=>'0.000','cantidad_retornada'=>'0.000','transito'=>'750.000']],'historial'=>[],'acciones'=>['recibir','incidencia']]];
$xe=$dom($render(true,['transferencias'=>$enviada,'transferenciasTotal'=>1]));
$assert($xe->query('//form[.//input[@name="accion" and @value="transferencia_recibir"]]//input[@name="lineas[0][cantidad]" and @value="750.000" and @max="750.000"]')->length===1,'La recepción llega precargada con lo enviado.');
$assert($xe->query('//*[contains(@class,"admin-badge") and normalize-space(.)="Enviada"]')->length===1&&$xe->query('//input[@value="transferencia_autorizar"]')->length===0,'Estado «Enviada» y ningún paso de autorización.');
$assert($xt->query('//*[@id="almacen-transferencias"]//*[contains(@class,"admin-btn--primary") and not(ancestor::details[contains(@class,"almacen-transfer-request")]) and not(ancestor::details[contains(@class,"almacen-transfer-action")])]')->length===0,'Fuera de los formularios plegados, el botón de solicitud es el único primario del modal.');
$conAlerta=$render(true,['vista'=>'transferencias','transferencias'=>$transferencias,'transferenciasTotal'=>1,'alertas'=>['exito'=>['Solicitud registrada.']]]);
$pAlerta=strpos($conAlerta,'Solicitud registrada.');$pAccion=strpos($conAlerta,'almacen-transfer-request');$pHistorial=strpos($conAlerta,'Transferencias e historial');
$assert($pAlerta!==false&&$pAlerta<$pAccion&&$pAccion<$pHistorial,'Resultado, acción y después el historial.');
$solicitud='//form[.//input[@name="accion" and @value="transferencia_solicitar"]]';$nombres=[];
foreach($xt->query($solicitud.'//*[@name]') as $n)$nombres[]=$n->getAttribute('name');sort($nombres);
$assert($nombres===['accion','admin_csrf','clave','destino_id','lineas[0][articulo_id]','lineas[0][cantidad]','motivo','origen_id','volver_contextual','volver_filtros','volver_vista'],'La solicitud envía exactamente los mismos campos: '.implode(',',$nombres));
$assert($xt->query($solicitud.'//input[@name="volver_vista" and @value="transferencias"]')->length===1,'La solicitud vuelve al modal de transferencias.');
$assert(str_contains($conAlerta,'>1</span> documento · pendientes primero'),'Un documento en singular.');
$assert($xt->query('//nav[@aria-label="Paginación de transferencias"]')->length===0,'Con una sola página no hay paginación.');
$vacio=$render(true,['transferencias'=>[],'transferenciasTotal'=>0]);$xv=$dom($vacio);
$assert($xv->query('//*[contains(@class,"almacen-transfer-head")]/span')->length===0&&str_contains($vacio,'Las que registres con «Nueva transferencia» aparecerán aquí'),'Vacío sin contador y remitiendo a la acción.');
$assert(str_contains($render(false,['transferencias'=>[],'transferenciasTotal'=>0]),'<p class="admin-empty">Todavía no hay transferencias en este ámbito.</p>'),'Vacío de solo consulta sin acción que no puede ejecutar.');
$xp=$dom($render(true,['transferencias'=>$transferencias,'transferenciasTotal'=>240,'transferenciasPagina'=>2]));
$assert($xp->query('//nav[@aria-label="Paginación de transferencias"]/a[contains(@class,"admin-btn--secondary") and contains(@class,"admin-btn--small")]')->length===2&&$xp->query('//nav[@aria-label="Paginación de transferencias"]/a[@rel="prev"]')->length===1,'Paginación secundaria pequeña, sólo con varias páginas.');
$inactivos=array_map(static fn(array $a):array=>['activo'=>0]+$a,[['almacen_id'=>1,'nombre'=>'Central','slug'=>'global','permitir_negativos'=>0]]);
$sinActivos=$render(true,['opcionesAlmacen'=>$inactivos,'transferencias'=>[],'transferenciasTotal'=>0]);
$assert(!str_contains($sinActivos,'transferencia_solicitar')&&str_contains($sinActivos,'Para registrar una transferencia, Global necesita al menos un almacén propio activo.'),'Sin almacén propio activo se explica por qué no hay acción.');
// Asociar y dar entrada en almacenes de otros negocios donde el usuario edita (§5).
$editables=[['almacen_id'=>1,'nombre'=>'Central <seguro>','slug'=>'global','asociado'=>false],['almacen_id'=>2,'nombre'=>'Cocina','slug'=>'restaurante','asociado'=>false],['almacen_id'=>3,'nombre'=>'Obrador','slug'=>'panaderia','asociado'=>false]];
$xa=$dom($render(true,['almacenesEditables'=>$editables,'contextosEditables'=>['global','restaurante']]));
$asociar='//*[@id="almacen-asociar"]//form[.//input[@name="accion" and @value="asociar"]]';
$assert($xa->query($asociar.'//fieldset[@data-asociar-almacenes]//*[@role="group"]')->length===3&&$xa->query($asociar.'//input[@type="checkbox" and @name="almacen_ids[]"]')->length===3&&$xa->query($asociar.'//select[@name="almacen_id"]')->length===0,'Asociar ofrece casillas por negocio para varios almacenes a la vez.');
$assert($xa->query($asociar.'//*[@role="group" and @aria-label="Restaurante"]//input[@value="2"]')->length===1&&$xa->query($asociar.'//input[@type="checkbox" and @checked]')->length===0,'Cada almacén va bajo su negocio y ninguno llega marcado si hay varios.');
$assert($x->query($asociar.'//input[@type="checkbox" and @name="almacen_ids[]" and @value="1" and @checked]')->length===1,'Con un solo almacén disponible llega marcado.');
$assert($xa->query('//*[@id="articulo-10-entrada"]//select[@data-warehouse-picker]/option[.="Cocina · Restaurante"]')->length===1&&$xa->query('//*[@id="articulo-10-entrada"]//*[@data-warehouse-panel="2"]//input[@name="accion" and @value="entrada"]')->length===1,'La entrada alcanza el almacén de otro negocio donde el usuario edita, rotulado con su dueño.');
$assert($xa->query('//*[@id="articulo-10-entrada"]//*[@data-warehouse-panel="3"]')->length===0&&$xa->query('//*[@id="articulo-10-merma"]//*[@data-warehouse-panel="2"]')->length===0&&$xa->query('//*[@id="articulo-10-configurar"]//*[@data-warehouse-panel="2"]')->length===0,'Sin edición en Panadería no hay entrada ahí; merma y configuración siguen en el contexto actual.');
$xs=$dom($render(true,['transferencias'=>[],'transferenciasTotal'=>0,'asociacionesTransferencia'=>[10=>[1,2],12=>[1]]]));
$assert($xs->query('//form[@data-transfer-request and @data-asociaciones=\'{"10":[1,2],"12":[1]}\']')->length===1&&$xs->query('//form[@data-transfer-request]//p[@data-transfer-asociacion and @role="status" and @hidden]')->length===1&&!str_contains($render(true,['transferencias'=>[],'transferenciasTotal'=>0]),'deben estar asociados en ambos'),'La transferencia ya no exige asociación previa y lleva el aviso de asociación automática.');
echo "Almacenes representación: {$count} comprobaciones OK.\n";
