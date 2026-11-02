<?php
declare(strict_types=1);

/** HTTP real contra el harness 3D.4; sin conexiones SQL ni secretos versionados. */
if (PHP_SAPI !== 'cli') exit(1);

final class ClienteRestaurante3D4
{
    private CurlHandle $curl;
    private string $jar;
    public string $csrf = '';

    public function __construct()
    {
        $jar=tempnam(sys_get_temp_dir(),'cp3d4-http-');
        if($jar===false) throw new RuntimeException('No se creó la sesión temporal HTTP.');
        $this->jar=$jar;
        $this->curl=curl_init();
        curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>40,
            CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,
            CURLOPT_USERAGENT=>'Casa-Pestalozzi-3D4-Isolated-HTTP/1.0']);
    }

    public function request(string $method,string $path,?array $data=null,bool $json=false): array
    {
        if(!str_starts_with($path,'/')||str_starts_with($path,'//')) throw new RuntimeException('Ruta fuera del harness.');
        $headers=['Accept: '.($json?'application/json':'text/html,application/pdf')];
        if($method==='POST') {
            $headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');
            if($this->csrf!=='') $headers[]='X-CSRF-TOKEN: '.$this->csrf;
        }
        curl_setopt_array($this->curl,[CURLOPT_URL=>'http://127.0.0.1:8093'.$path,
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$method==='POST'?($json?json_encode($data??[],JSON_THROW_ON_ERROR):http_build_query($data??[])):null,
            CURLOPT_HTTPHEADER=>$headers]);
        $raw=curl_exec($this->curl);
        if(!is_string($raw)) throw new RuntimeException('No respondió el harness HTTP: '.curl_error($this->curl));
        $size=(int)curl_getinfo($this->curl,CURLINFO_HEADER_SIZE);
        $body=substr($raw,$size);$head=substr($raw,0,$size);
        preg_match('/^Location:\s*([^\r\n]+)/mi',$head,$location);
        return ['status'=>(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'headers'=>$head,
            'body'=>$body,'json'=>json_decode($body,true),'location'=>trim($location[1]??'')];
    }

    public function login(array $account,bool $nip=false): array
    {
        $form=$this->request('GET','/login');
        if(!preg_match('/name="login_csrf"\s+value="([^"]+)"/',$form['body'],$m)) throw new RuntimeException('Login sin CSRF.');
        $data=['login_csrf'=>html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')];
        if(!$nip) $data['username']=$account['username'];
        $data[$nip?'nip':'password']=$account[$nip?'nip':'password'];
        return $this->request('POST',$nip?'/login':'/admin/login',$data);
    }

    public function close(): void
    {
        curl_close($this->curl);
        unset($this->curl); // CurlHandle debe liberar el jar antes de eliminarlo.
        if(is_file($this->jar)) unlink($this->jar);
    }
}

$clients=[];$checks=[];$phase='argumentos';$error=null;$ticket=0;$cleanup=null;$onlyRead=false;
$assert=static function(bool $condition,string $name) use (&$checks): void {
    if(!$condition) throw new RuntimeException($name);
    $checks[]=$name;
};
$okay=static fn(array $r): bool=>$r['status']===200&&($r['json']['ok']??false)===true;
try {
    $onlyRead=count($argv)===4&&$argv[3]==='--solo-lecturas';
    if((count($argv)!==3&&!$onlyRead)||$argv[1]!=='--fixture'||!is_file($argv[2])) throw new RuntimeException('Uso: --fixture ABS_JSON_PRIVADO [--solo-lecturas]');
    $data=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    if(($data['url']??'')!=='http://127.0.0.1:8093/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',(string)($data['base_temporal']??''))) throw new RuntimeException('El archivo no identifica un harness 3D.4 aislado.');
    $accounts=$data['cuentas'];$fixture=$data['fixture'];
    foreach(['oferta_restaurante','oferta_panaderia','mesa_restaurante','articulo_receta','articulo_variante','variante_id','area_panaderia'] as $key) {
        if(!isset($fixture[$key])||(int)$fixture[$key]<1) throw new RuntimeException('Falta dato de fixture: '.$key);
    }
    $admin=new ClienteRestaurante3D4();$clients[]=$admin;
    $public=new ClienteRestaurante3D4();$clients[]=$public;
    $reader=new ClienteRestaurante3D4();$clients[]=$reader;
    $pan=new ClienteRestaurante3D4();$clients[]=$pan;
    $floor=new ClienteRestaurante3D4();$clients[]=$floor;
    $phase='autenticación y lecturas';
    $login=$admin->login($accounts['admin_restaurante']);
    $assert(in_array($login['status'],[302,303],true)&&str_starts_with($login['location'],'/admin'),'Contraseña local inicia sesión');
    $readFailures=[];
    foreach(['/admin/menu','/admin/recetas','/admin/inventario','/admin/inventario/proveedores','/admin/analytics','/admin/finanzas',
        '/admin/configuracion','/admin/configuracion/horarios','/admin/configuracion/reservaciones','/admin/configuracion/pos',
        '/admin/configuracion/anuncio','/admin/configuracion/paleta','/admin/configuracion/reportes',
        '/admin/reservaciones','/admin/reservaciones/grupos-grandes','/admin/reservaciones/casos','/admin/tickets','/admin/printers','/admin/catas','/admin/feedback','/area','/area/cafe'] as $path) {
        $r=$admin->request('GET',$path);
        preg_match('/(?:Fatal error|Warning:|Uncaught \w+Exception)[^\r\n]{0,220}/',$r['body'],$issue);
        $label='Lectura funcional '.$path.' HTTP '.$r['status'].($issue?': '.strip_tags($issue[0]):'');
        if($r['status']!==200||$issue!==[]) $readFailures[]=$label;
        else $assert(true,$label);
    }
    if($readFailures!==[]) throw new RuntimeException(implode('; ',$readFailures));
    foreach(['/','/menu/pdf','/maridaje/pdf'] as $path) {
        $r=$public->request('GET',$path);
        $assert($r['status']===200,'Público '.$path.' HTTP '.$r['status']);
        if(str_ends_with($path,'/pdf')) $assert(str_starts_with($r['body'],'%PDF-')&&strlen($r['body'])>1500,'PDF real '.$path);
    }
    $anon=$public->request('GET','/api/ticket-items?ticket_id=1',null,true);
    $assert($anon['status']===401,'API privada rechaza sesión ausente');
    $login=$reader->login($accounts['solo_lectura_restaurante']);
    $assert(in_array($login['status'],[302,303],true),'Lectura inicia con contraseña');
    $ro=$reader->request('POST','/api/enviar-comanda',['ticket_id'=>1,'items'=>[]],true);
    $assert($ro['status']===403,'Solo lectura no obtiene permiso operativo');
    $ro=$reader->request('POST','/admin/configuracion/pos',['mesero_editable'=>'1'],true);
    $assert($ro['status']===403,'Solo lectura no modifica configuración');
    $login=$pan->login($accounts['admin_menu_panaderia']);
    $assert(in_array($login['status'],[302,303],true),'Panadería autentica su identidad');
    $future=$pan->request('GET','/admin/panaderia/menu',null,true);
    $assert($future['status']===503,'Menú futuro Panadería permanece bloqueado');
    $foreign=$pan->request('GET','/admin/menu',null,true);
    $assert($foreign['status']===403,'Membresía Panadería no consulta Restaurante');
    $login=$floor->login($accounts['piso_nip'],true);
    $assert(in_array($login['status'],[302,303],true),'Cuenta exclusivamente operativa autentica con NIP');
    $floorPanel=$floor->request('GET','/admin/finanzas',null,true);
    $assert($floorPanel['status']===403,'NIP operativo no abre Finanzas');
    $foreignKds=$admin->request('GET','/api/area-items?area_id='.(int)$fixture['area_panaderia'],null,true);
    $assert(($foreignKds['json']['codigo']??'')==='AREA_NO_AUTORIZADA','KDS rechaza un área real de Panadería por propiedad');

    if(!$onlyRead) {
    $phase='POS real y aislamiento de ofertas';
    $pos=$admin->request('GET','/punto-de-venta');
    $assert($pos['status']===200&&preg_match('/data-staff-csrf="([^"]+)"/',$pos['body'],$m)===1,'POS entrega CSRF del personal');
    $admin->csrf=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
    $stock=static function(int $article) use($admin,$okay): float {
        $r=$admin->request('GET','/admin/api/consultas?intencion=stock&id='.$article.'&periodo=hoy',null,true);
        if(!$okay($r)) throw new RuntimeException('No respondió consulta de existencia HTTP '.$r['status']);
        return (float)str_replace(',','',(string)($r['json']['respuesta']['cifra']??'-1'));
    };
    $initial=$stock((int)$fixture['articulo_receta']);$initialVariant=$stock((int)$fixture['articulo_variante']);
    $open=$admin->request('POST','/api/abrir-ticket',['mesa_id'=>(int)$fixture['mesa_restaurante'],'comensales'=>1,'nombre'=>'QA recorrido HTTP 3D4'],true);
    $assert($okay($open),'Apertura confirma ticket: '.($open['json']['codigo']??$open['status']));
    $ticket=(int)($open['json']['ticket_id']??$open['json']['id']??0);
    $assert($ticket>0&&($open['json']['commit']??false)===true,'Apertura publica ID y commit');
    $wrong=$admin->request('POST','/api/enviar-comanda',['ticket_id'=>$ticket,'clave_idempotencia'=>'foreign-'.bin2hex(random_bytes(12)),
        'items'=>[['oferta_id'=>(int)$fixture['oferta_panaderia'],'cantidad'=>1,'comensal'=>1,'variantes'=>[]]]],true);
    $assert(!$okay($wrong)&&($wrong['json']['ok']??true)===false,'POS rechaza oferta de Panadería');
    $key='http3d4-'.bin2hex(random_bytes(12));
    $payload=['ticket_id'=>$ticket,'clave_idempotencia'=>$key,'items'=>[
        ['oferta_id'=>(int)$fixture['oferta_restaurante'],'cantidad'=>1,'comensal'=>1,'variantes'=>[],'precio'=>'0.01'],
        ['oferta_id'=>(int)$fixture['oferta_restaurante'],'cantidad'=>1,'comensal'=>1,'variantes'=>[(int)$fixture['variante_id']],'precio'=>'0.01']]];
    $send=$admin->request('POST','/api/enviar-comanda',$payload,true);
    $assert($okay($send)&&($send['json']['commit']??false)===true,'Comanda confirma inventario y venta: '.($send['json']['codigo']??$send['status']));
    $assert(abs($stock((int)$fixture['articulo_receta'])-($initial-20))<0.001,'Comanda descuenta receta por dos líneas');
    $assert(abs($stock((int)$fixture['articulo_variante'])-($initialVariant-5))<0.001,'Variante descuenta su artículo propio');
    $replay=$admin->request('POST','/api/enviar-comanda',$payload,true);
    $assert($okay($replay)&&($replay['json']['reintento']??false)===true,'Reintento de comanda recupera operación original');
    $assert(abs($stock((int)$fixture['articulo_receta'])-($initial-20))<0.001,'Reintento no duplica consumo');
    $changed=$payload;$changed['items'][0]['cantidad']=2;
    $conflict=$admin->request('POST','/api/enviar-comanda',$changed,true);
    $assert(($conflict['json']['codigo']??'')==='CONFLICTO_IDEMPOTENCIA','Misma clave y distinto contenido se rechazan');
    $insufficient=$payload;$insufficient['clave_idempotencia']='insuf-'.bin2hex(random_bytes(12));$insufficient['items']=[['oferta_id'=>(int)$fixture['oferta_restaurante'],'cantidad'=>3,'comensal'=>1,'variantes'=>[]]];
    $denied=$admin->request('POST','/api/enviar-comanda',$insufficient,true);
    $assert(($denied['json']['codigo']??'')==='SALDO_INSUFICIENTE','Comanda sin saldo se revierte');
    $items=$admin->request('GET','/api/ticket-items?ticket_id='.$ticket,null,true);
    $rows=$items['json']['items']??[];
    $assert($okay($items)&&count($rows)===2,'Rechazos y reintentos conservan solo dos líneas');
    $baseItem=null;$variantItem=null;
    foreach($rows as $row) { if(abs((float)$row['precio']-31.5)<0.001)$baseItem=$row; if(abs((float)$row['precio']-34.5)<0.001)$variantItem=$row; }
    $assert($baseItem!==null&&$variantItem!==null,'Precio y variante se resuelven en servidor, ignorando precio enviado');
    $kds=$admin->request('GET','/api/area-items?area_id='.(int)$baseItem['area_id'],null,true);
    $assert($okay($kds)&&in_array((int)$baseItem['id'],array_map(static fn($r)=>(int)$r['id'],$kds['json']['items']??[]),true),'KDS lee la comanda confirmada');
    $cancel=$admin->request('POST','/api/cancelar-item',['item_id'=>(int)$baseItem['id']],true);
    $assert($okay($cancel),'Cancelar item confirma compensación original');
    $assert(abs($stock((int)$fixture['articulo_receta'])-($initial-10))<0.001,'Cancelación repone solo la receta cancelada');
    $again=$admin->request('POST','/api/cancelar-item',['item_id'=>(int)$baseItem['id']],true);
    $assert($okay($again)&&abs($stock((int)$fixture['articulo_receta'])-($initial-10))<0.001,'Cancelar dos veces no duplica compensación');
    $deliver=$admin->request('POST','/api/entregar-item',['item_id'=>(int)$variantItem['id']],true);
    $assert($okay($deliver),'Entrega confirma estado vivo');
    $close=$admin->request('POST','/api/cerrar-ticket',['ticket_id'=>$ticket,'metodo_pago'=>'efectivo','recibido'=>'34.50','propina'=>'0'],true);
    $assert($okay($close)&&($close['json']['commit']??false)===true,'Cobro cierra ticket con total de snapshots: '.($close['json']['codigo']??$close['status']));
    $closedCancel=$admin->request('POST','/api/cancelar-item',['item_id'=>(int)$variantItem['id']],true);
    $assert(($closedCancel['json']['ok']??true)===false,'Ticket cerrado impide nuevas cancelaciones');
    $assert(abs($stock((int)$fixture['articulo_receta'])-($initial-10))<0.001,'Cobro y cancelación inválida conservan inventario');
    }
} catch(Throwable $e) { $error=$e->getMessage(); }
finally {
    if($error!==null&&$ticket>0&&isset($admin)&&$admin->csrf!=='') {
        try {
            $remaining=$admin->request('GET','/api/ticket-items?ticket_id='.$ticket,null,true);
            $pay=0.0;
            foreach(($remaining['json']['items']??[]) as $item) {
                if(in_array($item['estado'],['enviado','en_preparacion','listo'],true)) $admin->request('POST','/api/cancelar-item',['item_id'=>(int)$item['id']],true);
                elseif($item['estado']==='entregado') $pay+=(float)$item['precio']*(int)$item['cantidad'];
            }
            $closed=$admin->request('POST','/api/cerrar-ticket',['ticket_id'=>$ticket,'metodo_pago'=>'efectivo','recibido'=>number_format($pay,2,'.',''),'propina'=>'0'],true);
            $cleanup=($closed['json']['ok']??false)===true;
        } catch(Throwable) { $cleanup=false; }
    }
    foreach($clients as $client) $client->close();
}
$out=['ok'=>$error===null,'fase'=>$phase,'comprobaciones'=>count($checks),'comprobaciones_aprobadas'=>$checks];
$out['alcance']=$onlyRead?'lecturas y autorización':'lecturas, autorización y ciclo POS';
if($error!==null)$out['error']=$error;
if($ticket>0)$out['ticket_fixture']=$ticket;
if($cleanup!==null)$out['ticket_fixture_liberado']=$cleanup;
fwrite($error===null?STDOUT:STDERR,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
exit($error===null?0:1);
