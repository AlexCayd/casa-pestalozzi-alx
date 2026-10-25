<?php
declare(strict_types=1);

use MenuPreservacion\VerificadorMenu;

$repo = dirname(__DIR__, 2);
require_once $repo . '/scripts/menu/bootstrap.php';
$base = $repo . '/tmp/menu-preservacion';
if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) throw new RuntimeException('No se pudo crear tmp/menu-preservacion.');
$run = $base . '/verificador-' . bin2hex(random_bytes(6));
if (!mkdir($run, 0777, true)) throw new RuntimeException('No se pudo crear fixture aislado.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$writeJson = static function (string $file, array $value): void {
    file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};
$fixture = static function (string $dir) use ($writeJson): array {
    if (!mkdir($dir . '/archivos', 0777, true)) throw new RuntimeException('No se pudo crear carpeta de fixture.');
    file_put_contents($dir . '/archivos/foto.jpg', 'fixture-image');
    $menu = [
        'formato'=>'casa-pestalozzi.menu','version'=>1,'negocio'=>'restaurante',
        'productos'=>[
            ['id'=>'1','nombre'=>'Comida','descripcion'=>null,'img'=>'foto.jpg','categoria_id'=>'2','precio'=>'125.00','area_id'=>'1','activo'=>'1'],
            ['id'=>'2','nombre'=>'Inactivo','descripcion'=>'','img'=>null,'categoria_id'=>'10','precio'=>'0.00','area_id'=>'1','activo'=>'0'],
            ['id'=>'10','nombre'=>'Sin estado SQL','descripcion'=>'Texto','img'=>null,'categoria_id'=>'1','precio'=>'9.50','area_id'=>'1','activo'=>'1'],
        ],
        'categorias'=>[
            ['id'=>'1','nombre'=>'Comida','carta'=>'comida','img'=>'foto.jpg','activo'=>'1'],
            ['id'=>'2','nombre'=>'Maridaje','carta'=>'maridaje','img'=>null,'activo'=>'0'],
            ['id'=>'10','nombre'=>'Temporada','carta'=>'temporada','img'=>null,'activo'=>'1'],
        ],
        'areas'=>[['id'=>'1','nombre'=>'Cocina','slug'=>'cocina','color'=>'#123456']],
        'orden'=>['productos'=>['1','2','10'],'categorias'=>['1','2','10']],
    ];
    $map = ['version'=>1,'productos'=>['1'=>'1','2'=>'2','10'=>'10'],'categorias'=>['1'=>'1','2'=>'2','10'=>'10'],'areas'=>['1'=>'1'],'archivos'=>['foto.jpg'=>'foto.jpg']];
    $writeJson($dir . '/menu.json', $menu); $writeJson($dir . '/correspondencias.json', $map);
    $file = $dir . '/archivos/foto.jpg';
    $tablas = ['productos'=>['create'=>'fixture ddl'],'categorias'=>['create'=>'fixture ddl'],'areas_produccion'=>['create'=>'fixture ddl']];
    $mf = ['formato'=>'casa-pestalozzi.menu.manifiesto','version'=>1,'origen'=>['host'=>'fixture'],'esquema'=>['tablas'=>$tablas,'sha256'=>hash('sha256', MenuPreservacion\Comun::json($tablas))],'conteos'=>['productos'=>3,'categorias'=>3,'areas'=>1],
        'hashes'=>['menu.json'=>hash_file('sha256',$dir.'/menu.json'),'correspondencias.json'=>hash_file('sha256',$dir.'/correspondencias.json')],
        'archivos'=>[['ruta'=>'foto.jpg','sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($file)]]];
    $writeJson($dir . '/manifiesto.json', $mf);
    return [$menu,$map,$mf];
};
$rehash = static function (string $dir): void {
    $menuFile=$dir.'/menu.json'; $mapFile=$dir.'/correspondencias.json'; $mfFile=$dir.'/manifiesto.json';
    $mf=json_decode((string)file_get_contents($mfFile),true,512,JSON_THROW_ON_ERROR);
    $mf['hashes']['menu.json']=hash_file('sha256',$menuFile);
    $mf['hashes']['correspondencias.json']=hash_file('sha256',$mapFile);
    $write = json_encode($mf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    file_put_contents($mfFile,$write);
};
$copy = static function (string $from, string $to): void {
    if (!mkdir($to, 0777, true)) throw new RuntimeException('No se pudo crear copia de fixture.');
    foreach (['menu.json','manifiesto.json','correspondencias.json'] as $f) copy($from.'/'.$f,$to.'/'.$f);
    if (!mkdir($to.'/archivos',0777,true)) throw new RuntimeException('No se pudo copiar archivos del fixture.');
    copy($from.'/archivos/foto.jpg',$to.'/archivos/foto.jpg');
};
try {
    [$menu] = $fixture($run . '/valido');
    $valid = VerificadorMenu::verificar($run . '/valido', $menu);
    $assert($valid['ok'] === true, 'El paquete válido no pasó: ' . implode(' | ', $valid['errores']));
    $assert($valid['conteos']['productos'] === 3, 'Conteo de productos incorrecto.');
    $diff = VerificadorMenu::comparar(['a'=>null,'b'=>'','ids'=>['1','2']], ['a'=>'','b'=>null,'ids'=>['2','1']]);
    $assert(count($diff) === 4, 'comparar debe diferenciar NULL, vacío y orden.');

    $cases = [
        'producto duplicado' => static function (&$m,&$mp,&$mf) { $m['productos'][1]['id']='1'; },
        'categoría duplicada' => static function (&$m,&$mp,&$mf) { $m['categorias'][1]['id']='1'; },
        'área duplicada' => static function (&$m,&$mp,&$mf) { $m['areas'][]=$m['areas'][0]; },
        'precio' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['precio']='125'; },
        'activo' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['activo']=true; },
        'activo NULL' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['activo']=null; },
        'categoría huérfana' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['categoria_id']='999'; },
        'carta' => static function (&$m,&$mp,&$mf) { $m['categorias'][0]['carta']='otra'; },
        'mapa incompleto' => static function (&$m,&$mp,&$mf) { unset($mp['productos']['10']); },
        'mapa alterado' => static function (&$m,&$mp,&$mf) { $mp['areas']['1']='2'; },
        'extra de esquema' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['legado']='x'; },
        'null por vacío' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['descripcion']=''; },
        'id con cero inicial' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['id']='01'; },
        'id cero' => static function (&$m,&$mp,&$mf) { $m['areas'][0]['id']='0'; },
        'precio válido cambiado' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['precio']='125.01'; },
        'activo válido cambiado' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['activo']='0'; },
        'carta válida cambiada' => static function (&$m,&$mp,&$mf) { $m['categorias'][0]['carta']='maridaje'; },
        'producto inactivo faltante' => static function (&$m,&$mp,&$mf) { unset($m['productos'][1],$m['orden']['productos'][1],$mp['productos']['2']); $m['productos']=array_values($m['productos']); $m['orden']['productos']=array_values($m['orden']['productos']); },
        'área sin correspondencia' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['area_id']='2'; },
        'imagen ausente' => static function (&$m,&$mp,&$mf) { $m['productos'][0]['img']='no-existe.jpg'; $mp['archivos']['no-existe.jpg']='no-existe.jpg'; $f=$mf['archivos'][0]; $f['ruta']='no-existe.jpg'; $mf['archivos'][]=$f; },
    ];
    $expectedPaths = [
        'null por vacío'=>'$.productos[0].descripcion',
        'precio válido cambiado'=>'$.productos[0].precio',
        'activo válido cambiado'=>'$.productos[0].activo',
        'carta válida cambiada'=>'$.categorias[0].carta',
        'producto inactivo faltante'=>'$.productos[2]',
        'área sin correspondencia'=>'$.productos[0].area_id',
        'imagen ausente'=>'$.productos[0].img',
    ];
    foreach ($cases as $label=>$mutate) {
        $dir=$run.'/caso-'.$assertions.'-'.bin2hex(random_bytes(3)); $copy($run.'/valido',$dir);
        $m=json_decode((string)file_get_contents($dir.'/menu.json'),true,512,JSON_THROW_ON_ERROR);
        $mp=json_decode((string)file_get_contents($dir.'/correspondencias.json'),true,512,JSON_THROW_ON_ERROR);
        $mf=json_decode((string)file_get_contents($dir.'/manifiesto.json'),true,512,JSON_THROW_ON_ERROR);
        $mutate($m,$mp,$mf); $writeJson($dir.'/menu.json',$m); $writeJson($dir.'/correspondencias.json',$mp); $writeJson($dir.'/manifiesto.json',$mf); $rehash($dir);
        $result=VerificadorMenu::verificar($dir, isset($expectedPaths[$label]) ? $menu : null);
        $assert(!$result['ok'], "Aceptó mutación semántica: $label.");
        if (isset($expectedPaths[$label])) {
            $assert((bool) array_filter($result['errores'], static fn(string $error): bool => str_contains($error, $expectedPaths[$label])), "No reportó la diferencia esperada para $label: " . implode(' | ', $result['errores']));
        }
    }
    $schemaDir=$run.'/schema-sha256'; $copy($run.'/valido',$schemaDir);
    $mf=json_decode((string)file_get_contents($schemaDir.'/manifiesto.json'),true,512,JSON_THROW_ON_ERROR);
    $mf['esquema']['tablas']['productos']['create']='alterado'; $writeJson($schemaDir.'/manifiesto.json',$mf);
    $assert(!VerificadorMenu::verificar($schemaDir)['ok'], 'Aceptó SHA256 de esquema que no corresponde a las tablas canonizadas.');
    $bytesDir=$run.'/bytes'; $copy($run.'/valido',$bytesDir); file_put_contents($bytesDir.'/archivos/foto.jpg','cambiado');
    $assert(!VerificadorMenu::verificar($bytesDir)['ok'], 'Aceptó bytes de imagen alterados.');
    $pathDir=$run.'/path'; $copy($run.'/valido',$pathDir);
    $m=json_decode((string)file_get_contents($pathDir.'/menu.json'),true,512,JSON_THROW_ON_ERROR); $m['productos'][0]['img']='../escape.jpg'; $m['categorias'][0]['img']='../escape.jpg'; $writeJson($pathDir.'/menu.json',$m);
    $mp=json_decode((string)file_get_contents($pathDir.'/correspondencias.json'),true,512,JSON_THROW_ON_ERROR); unset($mp['archivos']['foto.jpg']); $mp['archivos']['../escape.jpg']='../escape.jpg'; $writeJson($pathDir.'/correspondencias.json',$mp);
    $mf=json_decode((string)file_get_contents($pathDir.'/manifiesto.json'),true,512,JSON_THROW_ON_ERROR); $mf['archivos'][0]['ruta']='../escape.jpg'; $writeJson($pathDir.'/manifiesto.json',$mf); $rehash($pathDir);
    $assert(!VerificadorMenu::verificar($pathDir)['ok'], 'Aceptó traversal de ruta de imagen.');
    $compare=VerificadorMenu::verificar($run.'/valido', array_replace($menu,['productos'=>[]]));
    $assert(!$compare['ok'], 'No encontró discrepancia contra snapshot esperado.');
    fwrite(STDOUT, "OK: $assertions aserciones de preservación del menú. Fixture: $run\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\nFixture conservado para inspección: $run\n");
    exit(1);
}
