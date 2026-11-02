<?php
declare(strict_types=1);

/** Integridad del menú versionado; no consulta la base local ni el paquete privado. */
final class MenuVersionado3E
{
    public static function archivos(string $root): array
    {
        $manifest=json_decode((string)file_get_contents($root.'/database/menu-integridad.json'),true,512,JSON_THROW_ON_ERROR);
        if (($manifest['formato']??'')!=='casa-pestalozzi.menu-integridad' || ($manifest['version']??0)!==1) throw new RuntimeException('Manifiesto de menú desconocido.');
        $sql=(string)file_get_contents($root.'/database/menu-restaurante.sql');
        if (!hash_equals((string)$manifest['sql_sha256'],hash('sha256',str_replace("\r\n","\n",$sql)))) throw new RuntimeException('El DML del menú cambió respecto de su manifiesto.');
        $checks=1;
        foreach($manifest['imagenes'] as $image) {
            foreach(['fuente','publica'] as $key) {
                $relative=(string)$image[$key];
                if (!preg_match('~^(?:src/images/menu-preservado|public/build/images)/[a-z0-9-]+\.webp$~D',$relative)) throw new RuntimeException('Ruta de imagen fuera del catálogo preservado.');
                $path=$root.'/'.$relative;
                if (!is_file($path) || filesize($path)!==(int)$image['bytes'] || !hash_equals((string)$image['sha256'],(string)hash_file('sha256',$path))) throw new RuntimeException('Imagen preservada ausente o modificada: '.$relative);
                $checks++;
            }
        }
        if(count($manifest['imagenes'])!==13) throw new RuntimeException('Deben conservarse trece imágenes.');
        return ['ok'=>true,'comprobaciones'=>$checks,'manifest'=>$manifest];
    }

    public static function base(mysqli $db,string $root): array
    {
        $verified=self::archivos($root);$manifest=$verified['manifest'];$checks=$verified['comprobaciones'];
        $scope=$db->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row();
        if(!$scope) throw new RuntimeException('Falta la unidad Restaurante.');$unit=(int)$scope[0];
        $queries=[
            'productos'=>'SELECT id,nombre,descripcion,img FROM productos',
            'ofertas'=>'SELECT id,producto_id,categoria_id,precio,area_id,activo,orden FROM ofertas_producto',
            'categorias'=>'SELECT id,nombre,carta,img,activo,orden FROM categorias',
            'areas'=>'SELECT id,nombre,slug,color,orden FROM areas_produccion',
        ];
        foreach($queries as $kind=>$sql) {
            $hashes=$manifest['filas_sha256'][$kind];$ids=array_map('intval',array_keys($hashes));
            if(count($ids)!==(int)$manifest['conteos'][$kind] || min($ids)<1) throw new RuntimeException('Identidades preservadas inválidas.');
            $sql.=' WHERE id IN ('.implode(',',$ids).')'.($kind==='productos'?'':' AND unidad_id='.$unit).' ORDER BY id';
            $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
            if(count($rows)!==count($hashes)) throw new RuntimeException('Cambió el número de filas de '.$kind.'.');
            foreach($rows as $row) {
                foreach($row as &$value) if($value!==null)$value=(string)$value;
                unset($value);
                $actual=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                if(!isset($hashes[(string)$row['id']])||!hash_equals((string)$hashes[(string)$row['id']],$actual)) throw new RuntimeException('Cambió una fila preservada: '.$kind.'/'.$row['id']);
                $checks++;
            }
        }
        $cards=$db->query('SELECT slug FROM cartas WHERE unidad_id='.$unit.' ORDER BY orden,slug')->fetch_all(MYSQLI_NUM);
        if(array_column($cards,0)!==['comida','maridaje','temporada']) throw new RuntimeException('Deben conservarse las tres cartas y su orden.');$checks++;
        return ['ok'=>true,'comprobaciones'=>$checks,'conteos'=>$manifest['conteos']];
    }
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    try {$result=MenuVersionado3E::archivos(dirname(__DIR__,2));unset($result['manifest']);echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;}
    catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL);exit(1);}
}
