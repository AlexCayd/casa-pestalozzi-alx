<?php
declare(strict_types=1);
/** Regresión UX5 sobre el harness desechable, después de los diez recorridos UI. */
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/cliente-http-3e.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2).'/includes')->safeLoad();
if(PHP_SAPI!=='cli'||count($argv)!==4||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ARCHIVO_PRIVADO ESCENARIOS_UI_JSON');
$f=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$ui=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
$base=$f['base_temporal']??'';
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$base)||$base===($_ENV['DB_NAME']??''))throw new RuntimeException('Harness no identificado.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$base,(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
$m=$db->query('SELECT entorno,propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_assoc();
$owner=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];
if($m['entorno']!=='test'||!hash_equals($owner,$m['propietario_token']))throw new RuntimeException('Base ajena al harness.');
$n=0;$a=static function(bool $ok,string $message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
$dom=static function(string $html):DOMXPath{$d=new DOMDocument();libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();return new DOMXPath($d);};
$hash=static fn(string $table)=>hash('sha256',json_encode($db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC)));
$c=new ClienteInstalacion3E();$other=new ClienteInstalacion3E();
try{
    $c->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']]);
    $before=[];foreach(['almacen_articulos','movimientos_inventario','articulo_recetas','articulo_receta_componentes','receta_casa_versiones'] as $table)$before[$table]=$hash($table);
    $id=(int)$ui['ingrediente'];
    // El filtro excluye el artículo del catálogo paginado, pero la ficha de origen debe conservarlo.
    $r=$c->request('GET','/admin/global/almacenes?articulo_id='.$id.'&q=Harina');$x=$dom($r['body']);
    $a($r['status']===200&&$x->query('//select[@id="asociar-articulo"]/option[@selected and @value="'.$id.'"]')->length===1,'Artículo contextual seleccionado fuera del resultado de búsqueda');
    $r=$c->request('POST','/admin/global/almacenes/operacion',['admin_csrf'=>ClienteInstalacion3E::token($r['body']),'accion'=>'asociar','almacen_id'=>'99999999','articulo_id'=>$id,'minimo'=>'0.000','volver_vista'=>'existencias','volver_filtros'=>http_build_query(['articulo_id'=>$id,'almacen_ids'=>[1001]])]);
    parse_str(parse_url($r['location'],PHP_URL_QUERY)??'',$return);
    $a($r['status']===303&&(int)($return['articulo_id']??0)===$id&&($return['almacen_ids']??[])===["1001"],'Operación rechazada conserva artículo y selección física');
    $r=$c->request('GET',$r['location']);$a(str_contains($r['body'],'admin-page')&&!str_contains($r['body'],'Operación confirmada correctamente.'),'Error físico dentro del layout sin éxito contradictorio');
    foreach(['restaurante'=>'/admin','panaderia'=>'/admin/panaderia'] as $ctx=>$prefix){
        $offer=(int)$ui[$ctx==='restaurante'?'plato':'pan'];
        $r=$c->request('GET',$prefix.'/menu/presentaciones?id='.$offer);
        $a($r['status']===200,'Presentaciones disponibles en '.$ctx);
        $a(($ctx==='panaderia')===str_contains($r['body'],'su producción física y su punto de venta todavía no están disponibles'),'Limitación de Panadería solo en su contexto');
        $r=$c->request('GET',$prefix.'/menu/edit?id='.$offer);$a($r['status']===200&&str_contains($r['body'],$prefix.'/menu/presentaciones?'),'Ficha conserva editor comercial vigente '.$ctx);
    }
    $r=$c->request('GET','/admin/menu/variantes?id='.(int)$ui['cafe']);
    $a($r['status']===200&&!str_contains($r['body'],'NaN%'),'Variantes se recargan sin porcentaje inválido');
    $cafe=(int)$ui['cafe'];
    $a($db->execute_query('SELECT p.nombre FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id WHERE o.id=?',[$cafe])->fetch_row()[0]==='UX5 Café','Fixture UI sintética identificada antes de simular pendiente');
    $origin=$db->execute_query('SELECT oc.* FROM oferta_consumos oc JOIN ingredientes i ON i.id=oc.articulo_id WHERE oc.oferta_id=? AND i.nombre=?',[$cafe,'Bebida de avena'])->fetch_assoc();
    $a($origin!==null,'Sustitución de avena conserva origen guardado');
    try{
        $db->execute_query('DELETE FROM oferta_consumos WHERE oferta_id=? AND articulo_id=?',[$cafe,$origin['articulo_id']]);
        foreach([1,2] as $load){$r=$c->request('GET','/admin/menu/variantes?id='.$cafe);$a(str_contains($r['body'],'Falta elegir el almacén de consumo de 1 artículo')&&str_contains($r['body'],'#origenes'),'Pendiente derivado persiste al cargar variantes '.$load);}
    }finally{$db->execute_query('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES(?,?,?,?)',[$origin['oferta_id'],$origin['articulo_id'],$origin['unidad_id'],$origin['almacen_id']]);}
    $global='/admin/global/productos/receta?id='.(int)$ui['estandar'];
    $r=$c->request('GET',$global.'&guardado=1');
    $a(!str_contains($r['body'],'role="status"'),'Parámetro guardado no fabrica éxito en receta oficial');
    $a(str_contains($r['body'],'Conservar: Cocina'),'Origen guardado identificado por su nombre');
    $a(str_contains($r['body'],'/admin/menu/casa-receta?id='.(int)$ui['ofertaEstandar']),'Destino oficial enlaza su oferta exacta');
    $x=$dom($r['body']);$a($x->query('//input[contains(@name,"[cantidad]") and @value="10.000"]')->length>0,'Receta oficial conserva diez gramos');
    // Reenvío exacto de la receta sintética: guardar borrador no publica ni cambia sus cantidades.
    $form=$x->query('//form[.//input[@name="accion" and @value="guardar"]]')->item(0);$pairs=[];
    foreach($x->query('.//input[@name]|.//select[@name]|.//textarea[@name]',$form) as $field){
        if($field->hasAttribute('disabled')||in_array($field->getAttribute('type'),['file','submit'],true))continue;
        if(in_array($field->getAttribute('type'),['checkbox','radio'],true)&&!$field->hasAttribute('checked'))continue;
        $value=$field->getAttribute('value');
        if($field->tagName==='select'){$option=$x->query('.//option[@selected]',$field)->item(0)??$x->query('.//option',$field)->item(0);$value=$option?->getAttribute('value')??'';}
        if($field->tagName==='textarea')$value=$field->textContent;
        $pairs[]=urlencode($field->getAttribute('name')).'='.urlencode($value);
    }
    parse_str(implode('&',$pairs),$draft);$r=$c->request('POST',$global,$draft);
    $a($r['status']===303,'Guardado real de borrador conserva contrato PRG');
    $c->request('GET','/admin/global/productos/casa?id='.(int)$ui['estandar']);
    $r=$c->request('GET',$global);$a(str_contains($r['body'],'Borrador de receta guardado'),'Resultado real ligado a ruta e identidad');
    $a(!str_contains($c->request('GET',$global)['body'],'Borrador de receta guardado'),'Resultado consumido una sola vez');
    $local='/admin/menu/casa-receta?id='.(int)$ui['ofertaEstandar'];$r=$c->request('GET',$local.'&guardado=1');$x=$dom($r['body']);
    $a($x->query('//input[contains(@name,"[cantidad]") and @value="12.000"]')->length>0,'Adaptación local conserva doce gramos');
    $a(str_contains($r['body'],'Ver receta oficial Global')&&!str_contains($r['body'],'role="status"'),'Retorno oficial autorizado sin éxito ficticio');
    $path='/admin/global/productos/casa?id='.(int)$ui['estandar'].'&guardado=1';$r=$c->request('GET',$path);
    $a(!str_contains($r['body'],'role="status"'),'Parámetro guardado tampoco fabrica éxito comercial');
    $data=ClienteInstalacion3E::form($r['body'],'guardar');$data['nombre']='';$data['precio']='95.00';$data['descripcion']='Texto válido que debe conservarse';
    $r=$c->request('POST',$path,$data);
    $a($r['status']===422&&!str_contains($r['body'],'role="status"'),'POST comercial rechazado no muestra éxito');
    $a(str_contains($r['body'],'Texto válido que debe conservarse'),'Rechazo conserva descripción válida');
    $other->login($f['cuentas']['lectura_restaurante']);$r=$other->request('GET',$local);
    $a(!str_contains($r['body'],'Ver receta oficial Global'),'Cuenta local no recibe acceso Global');
    $a(in_array($other->request('GET',$global)['status'],[403,303],true),'Acceso directo Global sigue restringido');
    foreach($before as $table=>$fingerprint)$a($fingerprint===$hash($table),'Validación conserva datos y versiones: '.$table);
    echo "UX5 HTTP: {$n} comprobaciones OK.\n";
}finally{$c->close();$other->close();$db->close();}
