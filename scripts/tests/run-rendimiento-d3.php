<?php
declare(strict_types=1);

// CLI de ensayo: solo servidor y base desechables identificados; nunca carga .env.
if (PHP_SAPI !== 'cli' || !in_array($argc, [2,3], true) || ($argc===3 && $argv[2]!=='--finance-suite')) throw new RuntimeException('Uso: php run-rendimiento-d3.php <directorio-runtime> [--finance-suite]');
$root = dirname(__DIR__, 2);
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
$runtime = realpath($argv[1]);
$tmp = realpath($root . '/tmp');
if (!$runtime || !$tmp || dirname($runtime) !== $tmp || !preg_match('/^d3-rendimiento-[a-f0-9]{32}$/D', basename($runtime))) throw new RuntimeException('Runtime no identificado');
$marker = $runtime . '/d3-owner';
$token = is_file($marker) ? trim((string)file_get_contents($marker)) : '';
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Falta marca privada del runtime');
$expected = realpath($runtime . '/data');
if (!$expected) throw new RuntimeException('Datadir ausente');
require $root . '/vendor/autoload.php';
require $root . '/scripts/multinegocio/SqlArchivo.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', '', 33077);
$db->set_charset('utf8mb4');
$server = $db->query('SELECT @@datadir AS datadir, @@port AS puerto, VERSION() AS version')->fetch_assoc();
$actual = realpath((string)$server['datadir']);
if ($actual !== $expected || (int)$server['puerto'] !== 33077) throw new RuntimeException('Servidor ajeno; abortado antes de DDL');
$name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
$owner = bin2hex(random_bytes(32));
$created = false;
try {
    $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');
    $db->execute_query('INSERT INTO _cp_multinegocio_owner VALUES (?)', [$owner]);
    foreach (['database/ddl.sql', 'database/deploy.sql', 'database/menu-restaurante.sql', 'database/development.sql', 'database/actualizaciones/a1-datos-comerciales-oferta.sql'] as $file) {
        Multinegocio\SqlArchivo::ejecutar($db, $root . '/' . $file);
    }
    $db->query('SET @cp_a3_preservar_locales=1');
    Multinegocio\SqlArchivo::ejecutar($db, $root . '/database/actualizaciones/a3-datos-comerciales-local.sql');
    $counts = [];
    foreach (['tickets', 'ticket_items', 'ofertas_producto'] as $table) $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetch_row()[0];
    require $root . '/scripts/tests/fixture-3b.php';
    Model\ActiveRecord::setDB($db);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $u = FixtureAutorizacion3B::usuario($db, 'd3_bench', [], true);
    $_SESSION = ['id'=>$u['id'], 'auth_nivel'=>'contrasena', '_auth3b'=>true, 'login'=>true];
    $db->query('SET profiling=1');
    $db->query('SET profiling_history_size=100');
    $pct=static function(array $v,float $p): float { sort($v); return round($v[(int)ceil(count($v)*$p)-1],3); };
    $bench=static function(string $label, callable $prepare, callable $action) use ($db,$pct): array {
        $ms=[]; $sqlMs=[]; $sqlCounts=[];
        for ($i=0; $i<35; $i++) {
            $input=$prepare($i);
            $before = $db->query('SHOW PROFILES')->fetch_all(MYSQLI_ASSOC);
            $last = $before ? (int)end($before)['Query_ID'] : 0;
            $start = hrtime(true);
            $action($input, $i);
            $elapsed = (hrtime(true)-$start)/1e6;
            $profiles = $db->query('SHOW PROFILES')->fetch_all(MYSQLI_ASSOC);
            $entries = array_values(array_filter($profiles, static fn($p)=>(int)$p['Query_ID']>$last));
            if (!$entries || (int)end($entries)['Query_ID']-$last !== count($entries) || count($entries)>=100) throw new RuntimeException("Perfil incompleto de $label");
            if ($i>=5) { $ms[]=$elapsed; $sqlMs[]=array_sum(array_map(static fn($p)=>(float)$p['Duration']*1000,$entries)); $sqlCounts[]=count($entries); }
        }
        return ['php_ms_p50'=>$pct($ms,.5),'php_ms_p95'=>$pct($ms,.95),'sql_ms_p50'=>$pct($sqlMs,.5),'sql_ms_p95'=>$pct($sqlMs,.95),'consultas_min'=>min($sqlCounts),'consultas_max'=>max($sqlCounts)];
    };
    $none=static fn(int $i)=>null;
    $medidas=[]; $limites=[];
    $medidas['catalogo_pos'] = $bench('catálogo', $none, static function(): void {
        Model\Producto::catalogoPos();
        Services\Menu\PresentacionesOferta::paraCartas(2);
    });
    try {
        $grupos=[['nombre'=>'D3 complemento','opciones'=>[['nombre'=>'D3 normal','precio_delta'=>'0.00','predeterminada'=>true,'activo'=>true,'ajustes'=>[]],['nombre'=>'D3 extra','precio_delta'=>'1.00','predeterminada'=>false,'activo'=>true,'ajustes'=>[['accion'=>'agregar','tipo'=>'ingrediente','ref_id'=>1001,'cantidad'=>'1.000']]]]]];
        $vr=Services\Menu\Variantes::guardar(1402,$grupos,'restaurante');
        if (empty($vr['ok'])) throw new RuntimeException('No se pudo preparar variante sintética');
        $opciones=Services\Menu\Variantes::deProducto(1402,'restaurante');
        $opcion=(int)$opciones[0]['opciones'][1]['id'];
        $medidas['comanda_multilinea'] = $bench('comanda', static function(int $i) use ($db): int {
            $db->query("INSERT INTO tickets(unidad_id,comensales,nombre,estado) VALUES(2,1,'Ensayo sintético D3','abierto')");
            return (int)$db->insert_id;
        }, static function(int $ticket,int $i) use($opcion): void {
            $res=Services\Pos\Comercial::enviarComanda($ticket,[['oferta_id'=>1402,'cantidad'=>1,'variantes'=>[$opcion]],['oferta_id'=>1402,'cantidad'=>1,'variantes'=>[$opcion]]],sprintf('d3_comanda_sintetica_%04d',$i),'restaurante');
            if(empty($res['ok']) || count($res['item_ids']??[])!==2) throw new RuntimeException('Comanda no completada');
            foreach($res['item_ids'] as $id) if(empty(Services\Pos\Comercial::cancelarItem((int)$id,'restaurante')['ok'])) throw new RuntimeException('Cancelación no completada');
        });
        $limites[]='Comanda: duración de transacción individual no medida; PHP incluye comanda y dos cancelaciones.';
    } catch (Throwable $e) { $limites[]='Comanda sin medición: '.get_class($e).' código '.(int)$e->getCode().' en '.basename($e->getFile()).':'.$e->getLine().'; preparar variante falló antes del recorrido.'; }
    try {
        $medidas['reservas_mapa'] = $bench('reservas', $none, static function(): void {
            $res=Services\Pos\PosReservacionQueryService::paraFecha('2026-10-14','15:00',['ahora'=>new DateTimeImmutable('2026-10-14 15:00:00')]);
            if(empty($res['ok'])) throw new RuntimeException('Mapa no disponible');
            Services\Reservations\DisponibilidadReservacionService::consultarUna('2026-10-15','15:00',2,0,new DateTimeImmutable('2026-10-14 15:00:00'));
        });
    } catch (Throwable $e) { $limites[]='Reservas sin medición: '.get_class($e).' código '.(int)$e->getCode(); }
    try {
        $medidas['busqueda_menu_recetas'] = $bench('búsqueda', $none, static function(): void {
            Services\Security\ContextoNegocio::en('restaurante', static function(): void {
                Model\Producto::buscarAdmin(['q'=>'cafe'],30);
                Model\Producto::buscarRecetas(['q'=>'cafe'],30);
            });
        });
    } catch (Throwable $e) { $limites[]='Búsqueda sin medición: '.get_class($e).' código '.(int)$e->getCode(); }
    $proc=proc_open(['git','show','2e7d763:services/Finance/FinanzasNegocio.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if (!is_resource($proc)) throw new RuntimeException('No se pudo cargar referencia Git');
    fclose($pipes[0]); $ref=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($proc)!==0 || substr_count($ref,'final class FinanzasNegocio')!==1) throw new RuntimeException('Referencia Git inválida');
    $ref=str_replace('final class FinanzasNegocio','final class FinanzasNegocioBaseD3',$ref);
    $refPath=$runtime.'/finanzas-base.php';
    if (file_put_contents($refPath,$ref)===false) throw new RuntimeException('No se pudo preparar referencia');
    require $refPath;
    unlink($refPath);
    $medidas['finanzas_base'] = $bench('finanzas base', $none, static function() use ($db): void {
        Services\Finance\FinanzasNegocioBaseD3::agregados($db,2,'2025-01-01','2027-12-31');
    });
    $medidas['finanzas_actual'] = $bench('finanzas actual', $none, static function() use ($db): void {
        Services\Finance\FinanzasNegocio::agregados($db,2,'2025-01-01','2027-12-31');
    });
    $casos=[];
    foreach ([2,3] as $unidad) {
        $oferta=$db->query('SELECT id,producto_id,area_id FROM ofertas_producto WHERE unidad_id='.(int)$unidad.' ORDER BY id LIMIT 1')->fetch_assoc();
        if (!$oferta) throw new RuntimeException('Falta oferta sintética');
        foreach ([['2022-02-01 00:00:00','cerrado','1.00'],['2022-02-01 23:59:59','cerrado','2.00'],['2022-02-02 00:00:00','cerrado','9.00'],['2022-02-01 12:00:00','abierto','9.00']] as $case) {
            $db->execute_query('INSERT INTO tickets(unidad_id,nombre,hora_apertura,hora_cierre,estado,propina) VALUES(?,?,?,?,?,?)',[$unidad,'D3 sintético',$case[0],$case[0],$case[1],$case[2]]);
            $ticket=(int)$db->insert_id;
            foreach ([['4.00',2,'3.0000','entregado'],['11.00',1,null,'entregado'],['2.00',3,'0.0000','entregado'],['99.00',1,'9.0000','cancelado']] as $item) {
                $db->execute_query('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,area_id,cantidad,costo_unitario,estado) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$unidad,$ticket,(int)$oferta['id'],(int)$oferta['producto_id'],'D3 sintético',$item[0],'D3',(int)$oferta['area_id'],$item[1],$item[2],$item[3]]);
            }
        }
        foreach ([['2022-02-01','2022-02-01'],['2022-02-02','2022-02-02'],['2022-03-01','2022-03-01'],['2025-01-01','2027-12-31']] as [$desde,$hasta]) {
            $base=Services\Finance\FinanzasNegocioBaseD3::agregados($db,$unidad,$desde,$hasta);
            $nuevo=Services\Finance\FinanzasNegocio::agregados($db,$unidad,$desde,$hasta);
            if ($base!==$nuevo) throw new RuntimeException('Diferencia de agregados en caso sintético');
            if ($desde==='2022-02-01' && $nuevo!==['ingresos'=>50.0,'propinas'=>3.0,'tickets'=>2,'cogs'=>12.0,'sinCosto'=>22.0]) throw new RuntimeException('Totales de frontera inesperados');
            if ($desde==='2022-03-01' && $nuevo!==['ingresos'=>0.0,'propinas'=>0.0,'tickets'=>0,'cogs'=>0.0,'sinCosto'=>0.0]) throw new RuntimeException('Totales vacíos inesperados');
            $casos[]='unidad_'.$unidad.'_'.($desde==='2022-02-01'?'fronteras_y_costos':($desde==='2022-03-01'?'vacio':'periodo'));
        }
    }
    $plans=[];
    foreach (['base_ingresos'=>"EXPLAIN FORMAT=JSON SELECT COALESCE(SUM(ti.precio*ti.cantidad),0), COALESCE(SUM(CASE WHEN ti.costo_unitario IS NULL THEN ti.precio*ti.cantidad ELSE 0 END),0) FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id WHERE t.unidad_id=2 AND t.estado='cerrado' AND ti.estado<>'cancelado' AND COALESCE(t.hora_cierre,t.hora_apertura)>='2025-01-01 00:00:00' AND COALESCE(t.hora_cierre,t.hora_apertura)<='2027-12-31 23:59:59'", 'base_costos'=>"EXPLAIN FORMAT=JSON SELECT COALESCE(SUM(ti.costo_unitario*ti.cantidad),0) FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id WHERE t.unidad_id=2 AND t.estado='cerrado' AND ti.estado<>'cancelado' AND ti.costo_unitario IS NOT NULL AND COALESCE(t.hora_cierre,t.hora_apertura)>='2025-01-01 00:00:00' AND COALESCE(t.hora_cierre,t.hora_apertura)<='2027-12-31 23:59:59'"] as $label=>$sql) {
        if ($label==='base_ingresos') $sqlActual=str_replace('SUM(ti.precio*ti.cantidad),0)', 'SUM(ti.precio*ti.cantidad),0), COALESCE(SUM(ti.costo_unitario*ti.cantidad),0)', $sql);
        $extract=static function(array $n) use (&$extract): array { $out=[]; if(isset($n['table_name'])) $out[]=['tabla'=>$n['table_name'],'tipo'=>$n['access_type']??null,'indice'=>$n['key']??null,'filas_estimadas'=>$n['rows']??null]; foreach($n as $v) if(is_array($v)) $out=array_merge($out,$extract($v)); return $out; };
        $plans[$label]=$extract(json_decode((string)$db->query($sql)->fetch_row()[0],true,512,JSON_THROW_ON_ERROR));
    }
    $plans['actual_combinada']=$extract(json_decode((string)$db->query($sqlActual)->fetch_row()[0],true,512,JSON_THROW_ON_ERROR));
    $suite=null;
    if ($argc===3) {
        $sintetico=bin2hex(random_bytes(32));
        $refRealPath=$runtime.'/finanzas-base-suite.php';
        $refOriginal=str_replace('final class FinanzasNegocioBaseD3','final class FinanzasNegocio',$ref);
        if(file_put_contents($refRealPath,$refOriginal)===false) throw new RuntimeException('No se pudo preparar referencia de suite');
        $bootstrap=<<<'PHP'
$keys=['APP_ENV','DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','DEV_SUPERADMIN_PASSWORD','NIP_LOOKUP_SECRET'];
foreach($keys as $key){$value=getenv($key);if($key==='DB_PASS' && $value===false)$value='';if($value===false)throw new RuntimeException('Falta configuración sintética: '.$key);$_ENV[$key]=$value;}
require getenv('D3_ROOT').'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(getenv('D3_ROOT').'/includes')->safeLoad();
if($_ENV['APP_ENV']!=='test'||$_ENV['DB_HOST']!=='127.0.0.1'||$_ENV['DB_PORT']!=='33077'||$_ENV['DB_USER']!=='root'||$_ENV['DB_PASS']!==''||$_ENV['DB_NAME']!==getenv('D3_BASE'))throw new RuntimeException('Configuración aislada alterada');
$check=new mysqli('127.0.0.1','root','','',33077);
$identidad=$check->query('SELECT @@datadir,@@port')->fetch_row();
if(realpath($identidad[0])!==realpath(getenv('D3_DATA'))||(int)$identidad[1]!==33077)throw new RuntimeException('Servidor ajeno');
$check->select_db(getenv('D3_BASE'));
$marca=$check->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row();
if(!$marca||!hash_equals(getenv('D3_OWNER'),$marca[0]))throw new RuntimeException('Base ajena');
$check->close();
if(getenv('D3_BASELINE')==='1') require getenv('D3_REFERENCE_FILE');
require getenv('D3_ROOT').'/scripts/tests/run-finanzas-ambito-db.php';
PHP;
        $execute=static function(bool $base) use ($bootstrap,$root,$runtime,$expected,$name,$owner,$sintetico,$refRealPath): array {
            $env=['APP_ENV'=>'test','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33077','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>$name,'DEV_SUPERADMIN_PASSWORD'=>$sintetico,'NIP_LOOKUP_SECRET'=>$sintetico,'D3_ROOT'=>$root,'D3_BASE'=>$name,'D3_DATA'=>$expected,'D3_OWNER'=>$owner,'D3_BASELINE'=>$base?'1':'0','D3_REFERENCE_FILE'=>$refRealPath];
            foreach(['SystemRoot','PATH','TEMP','TMP'] as $key) { $value=getenv($key); if($value!==false)$env[$key]=$value; }
            $proc=proc_open([PHP_BINARY,'-r',$bootstrap],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
            if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar suite financiera');
            fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($proc);
            file_put_contents($runtime.'/suite-finanzas-'.($base?'base':'actual').'.log',$out."\n".$err);
            return ['exit'=>$status,'completa'=>$status===0 && str_contains($out,'Finanzas de Panadería y Global:') && !str_contains($out,'SKIP:'),'error_1064'=>str_contains($err,'1064')||str_contains($err,'near \'SHARE\'')];
        };
        try { $suite=['actual'=>$execute(false)]; if(!$suite['actual']['completa']) $suite['base']=$execute(true); }
        finally { unlink($refRealPath); }
    }
    $limites[]='Servicios locales PHP, sin HTTP ni render ni red; p50/p95 sobre 30 muestras posteriores a 5 calentamientos. SQL es suma de perfiles de sesión, sin tiempos de transferencia.';
    echo json_encode(['version'=>$server['version'],'fixture'=>$counts,'calentamiento'=>5,'muestras'=>30,'medidas'=>$medidas,'explain_finanzas'=>$plans,'igualdad_finanzas'=>$casos,'suite_finanzas'=>$suite,'limites'=>$limites], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), "\n";

} finally {
    if ($created) {
        $db->select_db($name);
        $row=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row();
        if (!$row || !hash_equals($owner,(string)$row[0])) throw new RuntimeException('Propiedad de base no verificable; no se elimina');
        $db->query('DROP DATABASE `' . $name . '`');
    }
    $db->close();
}

if (isset($suite) && !$suite['actual']['completa']) exit(1);
