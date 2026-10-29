<?php
declare(strict_types=1);
namespace Services\Menu;

use Model\ActiveRecord;
use Model\HistorialPrecio;
use Services\Security\ContextoNegocio as C;
use Services\Inventory\InventarioError;

/** Coordinador de borradores, política y publicación comercial, sin escribir stock. */
final class ProductosCasa
{
    public const CAMPOS=['nombre'=>'Nombre','descripcion'=>'Descripción','img'=>'Imagen','precio'=>'Precio','receta'=>'Receta','activo'=>'Disponibilidad'];
    public const TIPOS=['estandar'=>'Estándar de la casa','compartido'=>'Compartido con ajustes','exclusivo'=>'Exclusivo de un negocio'];
    public static function sql(string $sql,array $args=[]): \mysqli_stmt { $s=ActiveRecord::getDB()->prepare($sql);$s->execute($args);return $s; }
    public static function json(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public static function dato(?string $v): mixed { return $v===null?null:json_decode($v,true,512,JSON_THROW_ON_ERROR); }
    public static function error(string $codigo,string $mensaje): never { throw new InventarioError($codigo,$mensaje); }
    public static function autorizar(): void { C::facultad(CatalogoComercial::FACULTAD,'global','menu',true); }
    public static function configuracion(int $id,bool $lock=false): ?array {
        return self::sql('SELECT e.*,p.datos AS publicados,p.version FROM producto_estandares e LEFT JOIN producto_publicaciones p ON p.id=e.publicacion_id WHERE e.producto_id=?'.($lock?' FOR UPDATE':''),[$id])->get_result()->fetch_assoc()?:null;
    }
    public static function publicada(int $id): ?array { return self::dato(self::configuracion($id)['publicados']??null); }
    public static function permite(int $id,string $campo): bool { $p=self::publicada($id);return $p===null||!empty($p['reglas'][$campo]); }
    public static function exigirDestino(int $id,string $ctx): void {
        $p=self::publicada($id);if($p!==null&&!isset($p['negocios'][$ctx]))self::error('DESTINO_PROTEGIDO','Global no ofrece este producto en el negocio seleccionado.');
    }
    public static function normalizar(array $d): array {
        foreach(['nombre','descripcion','img','precio','activo','tipo','receta_version_id','requiere_receta'] as $campo)if(isset($d[$campo])&&!is_scalar($d[$campo]))self::error('DATOS_INVALIDOS','El formulario contiene un dato inválido.');
        if(isset($d['reglas'])&&(!is_array($d['reglas'])||array_diff(array_keys($d['reglas']),array_keys(self::CAMPOS))))self::error('REGLA_INVALIDA','La política contiene campos desconocidos.');
        foreach($d['reglas']??[] as $valor)if(!is_scalar($valor))self::error('REGLA_INVALIDA','La política no es válida.');
        $nombre=trim((string)($d['nombre']??''));$descripcion=(string)($d['descripcion']??'');
        if($nombre===''||mb_strlen($nombre)>120||mb_strlen($descripcion)>10000)self::error('DATOS_INVALIDOS','Indica un nombre de hasta 120 caracteres y una descripción de hasta 10000.');
        $tipo=(string)($d['tipo']??'estandar');if(!isset(self::TIPOS[$tipo]))self::error('TIPO_INVALIDO','Elige una administración válida.');
        $reglas=[];foreach(self::CAMPOS as $campo=>$label)$reglas[$campo]=!empty($d['reglas'][$campo]);
        $negocios=$d['negocios']??[];if(!is_array($negocios)||array_diff(array_keys($negocios),['restaurante','panaderia']))self::error('NEGOCIO_INVALIDO','Elige solo negocios vendedores.');
        if($tipo==='exclusivo'&&count($negocios)!==1)self::error('EXCLUSIVIDAD_INVALIDA','Un producto exclusivo requiere un único negocio.');
        foreach($negocios as $ctx=>&$n){if(!is_array($n))self::error('DESTINO_INVALIDO','Completa las condiciones del negocio.');$n=['categoria_id'=>(int)($n['categoria_id']??0),'area_id'=>(int)($n['area_id']??0),'precio'=>ImporteOferta::normalizar($n['precio']??$d['precio']??'0.00'),'activo'=>(int)!empty($n['activo']),'orden'=>(int)($n['orden']??0)];}unset($n);
        $img=$d['img']??null;if($img!==null&&(!is_string($img)||!preg_match('~^/?(?:build/images/|assets/img/)[a-zA-Z0-9_./-]+$~D',$img)||str_contains($img,'..')))self::error('IMAGEN_INVALIDA','La imagen debe proceder del cargador de imágenes.');
        return ['nombre'=>$nombre,'descripcion'=>$descripcion,'img'=>$img,'precio'=>ImporteOferta::normalizar($d['precio']??'0.00'),'activo'=>(int)!empty($d['activo']),'tipo'=>$tipo,'reglas'=>$reglas,'negocios'=>$negocios,'receta_version_id'=>(int)($d['receta_version_id']??0),'requiere_receta'=>!empty($d['requiere_receta'])];
    }
    public static function crear(array $d,string $clave,int $producto=0): array {
        self::autorizar();$d=self::normalizar($d);self::clave($clave);$hash=hash('sha256',self::json([$producto,$d]));
        return C::transaccion(static function()use($d,$clave,$producto,$hash){
            self::autorizar();$prev=self::sql('SELECT producto_id,creacion_hash FROM producto_estandares WHERE clave_creacion=? FOR UPDATE',[$clave])->get_result()->fetch_assoc();
            if($prev){if(!hash_equals($prev['creacion_hash'],$hash))self::error('CONFLICTO_IDEMPOTENCIA','La solicitud corresponde a otro contenido.');return ['producto_id'=>(int)$prev['producto_id']];}
            if($producto){self::bloquear($producto);foreach(self::ofertas($producto) as $o)C::exigir('menu','editar',$o['slug'],true);}
            else {self::sql('INSERT INTO productos(nombre,descripcion,img) VALUES (?,?,?)',[$d['nombre'],$d['descripcion'],$d['img']]);$producto=(int)ActiveRecord::getDB()->insert_id;}
            self::sql('INSERT INTO producto_estandares(producto_id,borrador,clave_creacion,creacion_hash) VALUES (?,?,?,?)',[$producto,self::json($d),$clave,$hash]);
            return ['producto_id'=>$producto];
        });
    }
    public static function guardar(int $id,array $d,int $revision): array {
        self::autorizar();$d=self::normalizar($d);
        return C::transaccion(static function()use($id,$d,$revision){self::autorizar();self::bloquear($id);$c=self::configuracion($id,true);if(!$c||(int)$c['revision']!==$revision)self::error('VERSION_CONFLICTO','El borrador cambió. Recarga el producto.');self::sql('UPDATE producto_estandares SET borrador=?,revision=revision+1 WHERE producto_id=?',[self::json($d),$id]);return ['revision'=>$revision+1];});
    }
    public static function bloquear(int $id): array {
        $p=self::sql('SELECT * FROM productos WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();if(!$p)self::error('PRODUCTO_INVALIDO','El producto no existe.');return $p;
    }
    public static function ofertas(int $id,bool $lock=false): array {return self::sql('SELECT o.*,u.slug,p.nombre,p.descripcion,p.img FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id JOIN productos_oferta p ON p.oferta_id=o.id WHERE o.producto_id=? ORDER BY o.id'.($lock?' FOR UPDATE':''),[$id])->get_result()->fetch_all(MYSQLI_ASSOC);}
    public static function vistaPrevia(int $id): array {
        C::exigir('menu','ver','global');$c=self::configuracion($id);if(!$c)self::error('PRODUCTO_INVALIDO','El producto no tiene configuración de la casa.');
        $ofertas=self::ofertas($id);$d=self::dato($c['borrador']);$anterior=self::dato($c['publicados']);
        foreach(array_unique([...array_keys($d['negocios']),...array_column($ofertas,'slug')]) as $ctx)C::exigir('menu','ver',$ctx);
        $personalizaciones=self::sql('SELECT l.* FROM oferta_personalizaciones l JOIN ofertas_producto o ON o.id=l.oferta_id WHERE o.producto_id=? ORDER BY l.oferta_id,l.campo',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        $p=self::sql('SELECT * FROM productos WHERE id=?',[$id])->get_result()->fetch_assoc();
        return ['configuracion'=>$c,'borrador'=>$d,'anterior'=>$anterior,'ofertas'=>$ofertas,'personalizaciones'=>$personalizaciones,'huella'=>hash('sha256',self::json([$c,$p,$ofertas,$personalizaciones]))];
    }
    public static function publicar(int $id,int $revision,string $huella,string $clave,bool $confirmado): array {
        self::autorizar();self::clave($clave);$hash=hash('sha256',self::json([$id,$revision,$huella]));
        return C::transaccion(static function()use($id,$revision,$huella,$clave,$confirmado,$hash){
            self::autorizar();self::bloquear($id);$c=self::configuracion($id,true);
            $prev=self::sql('SELECT id,producto_id,solicitud_hash FROM producto_publicaciones WHERE clave=?',[$clave])->get_result()->fetch_assoc();
            if($prev){if((int)$prev['producto_id']!==$id||!hash_equals($prev['solicitud_hash'],$hash))self::error('CONFLICTO_IDEMPOTENCIA','La solicitud ya se utilizó para otro contenido.');return ['publicacion_id'=>(int)$prev['id']];}
            self::ofertas($id,true);$v=self::vistaPrevia($id);
            if(!$c||(int)$c['revision']!==$revision||!hash_equals($v['huella'],$huella))self::error('VERSION_CONFLICTO','Cambió el producto o alguna oferta. Revisa de nuevo el impacto.');
            if(!$confirmado)self::error('CONFIRMACION_REQUERIDA','Confirma la publicación tras revisar los negocios afectados.');
            $d=$v['borrador'];foreach(array_unique([...array_keys($d['negocios']),...array_column($v['ofertas'],'slug')]) as $ctx)C::exigir('menu','editar',$ctx,true);
            if($d['requiere_receta']&&$d['activo']&&!$d['receta_version_id'])self::error('RECETA_PENDIENTE','Completa la receta antes de activar este producto.');
            $version=(int)($c['version']??0)+1;
            self::sql('INSERT INTO producto_publicaciones(producto_id,version,datos,usuario_id,clave,solicitud_hash) VALUES (?,?,?,?,?,?)',[$id,$version,self::json($d),C::actorId(),$clave,$hash]);$pub=(int)ActiveRecord::getDB()->insert_id;
            self::sql('UPDATE producto_estandares SET publicacion_id=?,revision=revision+1 WHERE producto_id=?',[$pub,$id]);
            self::sql('UPDATE productos SET nombre=?,descripcion=?,img=? WHERE id=?',[$d['nombre'],$d['descripcion'],$d['img'],$id]);
            $existentes=[];foreach($v['ofertas'] as $o)$existentes[$o['slug']]=$o;
            foreach($existentes as $ctx=>$o)if(!isset($d['negocios'][$ctx]))self::sql('UPDATE ofertas_producto SET activo=0 WHERE id=?',[$o['id']]);
            foreach($d['negocios'] as $ctx=>$n){
                $u=C::vendedora($ctx);Ofertas::validarLocal(ActiveRecord::getDB(),$n['categoria_id'],$n['area_id'],$u);
                if(!isset($existentes[$ctx])){ $r=Ofertas::crear($id,$ctx,$n);if(empty($r['id']))self::error('OFERTA_INVALIDA','No se pudo preparar la oferta.');$o=self::sql('SELECT * FROM ofertas_producto WHERE id=?',[$r['id']])->get_result()->fetch_assoc(); }
                else $o=$existentes[$ctx];
                // Adoptar no destruye condiciones locales cuando la política permite conservarlas.
                foreach(['precio','activo'] as $campo)if($c['publicacion_id']===null&&isset($existentes[$ctx])&&$d['reglas'][$campo])self::personalizacion((int)$o['id'],$campo,$o[$campo]);
                foreach(self::CAMPOS as $campo=>$label)if(!$d['reglas'][$campo])self::sql('DELETE FROM oferta_personalizaciones WHERE oferta_id=? AND campo=?',[$o['id'],$campo]);
                $local=self::locales((int)$o['id']);$precio=ImporteOferta::normalizar($local['precio']??($d['reglas']['precio']?$n['precio']:$d['precio']));$activo=(int)($local['activo']??($d['reglas']['activo']?$n['activo']:$d['activo']));
                self::precio((int)$o['id'],(int)$o['unidad_id'],(string)$o['precio'],$precio);
                // Las categorías, áreas y orden de ofertas existentes no se reemplazan al publicar.
                self::sql('UPDATE ofertas_producto SET precio=?,activo=? WHERE id=?',[$precio,$activo,$o['id']]);
                if($d['receta_version_id']&&!isset($local['receta']))RecetasCasa::aplicar($id,(int)$o['id'],$ctx,(int)$d['receta_version_id']);
                if(!$d['receta_version_id']&&isset($v['anterior']['receta_version_id'])&&$v['anterior']['receta_version_id']&&!isset($local['receta']))self::sql('UPDATE recetas SET activo=0 WHERE oferta_id=?',[$o['id']]);
            }
            return ['publicacion_id'=>$pub,'version'=>$version];
        });
    }
    public static function locales(int $oferta): array { $out=[];foreach(self::sql('SELECT campo,valor FROM oferta_personalizaciones WHERE oferta_id=?',[$oferta])->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$out[$r['campo']]=self::dato($r['valor']);return $out; }
    public static function personalizacion(int $oferta,string $campo,mixed $valor): void {self::sql('INSERT INTO oferta_personalizaciones(oferta_id,campo,valor,usuario_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor),usuario_id=VALUES(usuario_id)',[$oferta,$campo,self::json($valor),C::actorId()]);}
    public static function precio(int $oferta,int $unidad,string $antes,string $nuevo): void {
        if(ImporteOferta::normalizar($antes)===$nuevo)return;
        $ctx=self::sql('SELECT slug FROM unidades WHERE id=?',[$unidad])->get_result()->fetch_row()[0];
        if(!C::en($ctx,static fn()=>HistorialPrecio::registrar(['unidad_id'=>$unidad,'oferta_id'=>$oferta,'articulo_id'=>null,'precio_anterior'=>$antes,'precio_nuevo'=>$nuevo,'motivo'=>'edicion'],true)))self::error('HISTORIAL_INVALIDO','No se pudo registrar el precio.');
    }
    public static function guardarLocal(string $ctx,int $oferta,array $datos,array $restaurar=[]): array {
        C::exigir('menu','editar',$ctx);if(array_diff($restaurar,array_keys(self::CAMPOS)))self::error('CAMPO_INVALIDO','La personalización no es válida.');
        return C::transaccion(static function()use($ctx,$oferta,$datos,$restaurar){
            C::exigir('menu','editar',$ctx,true);$u=C::vendedora($ctx);
            $o=self::sql('SELECT * FROM ofertas_producto WHERE id=? AND unidad_id=?',[$oferta,$u])->get_result()->fetch_assoc();if(!$o)self::error('OFERTA_DENEGADA','La oferta no pertenece al negocio.');
            self::bloquear((int)$o['producto_id']);$c=self::configuracion((int)$o['producto_id'],true);self::exigirDestino((int)$o['producto_id'],$ctx);
            self::sql('SELECT id FROM ofertas_producto WHERE id=? FOR UPDATE',[$oferta]);
            $d=self::dato($c['publicados']??null);if(!$d)self::error('SIN_ESTANDAR','El producto todavía no tiene una publicación.');
            $ef=self::sql('SELECT * FROM productos_oferta WHERE oferta_id=?',[$oferta])->get_result()->fetch_assoc();$antes=$o;
            foreach(['nombre','descripcion','img','precio','activo'] as $campo){
                $actual=$campo==='precio'?ImporteOferta::normalizar($o[$campo]):($campo==='activo'?(int)$o[$campo]:$ef[$campo]);
                if(in_array($campo,$restaurar,true)){self::sql('DELETE FROM oferta_personalizaciones WHERE oferta_id=? AND campo=?',[$oferta,$campo]);$valor=$d[$campo];}
                elseif(array_key_exists($campo,$datos)){
                    $valor=$datos[$campo];if($campo==='precio')$valor=ImporteOferta::normalizar($valor);elseif($campo==='activo')$valor=(int)(bool)$valor;
                    if($valor!==$actual){if(empty($d['reglas'][$campo]))self::error('CAMPO_PROTEGIDO',self::CAMPOS[$campo].': Definido por Global.');
                        $validado=self::normalizar(array_replace($d,[$campo=>$valor]));$valor=$validado[$campo];self::personalizacion($oferta,$campo,$valor);
                    }
                } else $valor=$actual;
                if(in_array($campo,['precio','activo'],true))$o[$campo]=$valor;
            }
            $categoria=(int)($datos['categoria_id']??$o['categoria_id']);$area=(int)($datos['area_id']??$o['area_id']);$orden=(int)($datos['orden']??$o['orden']);
            Ofertas::validarLocal(ActiveRecord::getDB(),$categoria,$area,$u);self::precio($oferta,$u,$antes['precio'],(string)$o['precio']);
            self::sql('UPDATE ofertas_producto SET categoria_id=?,area_id=?,orden=?,precio=?,activo=? WHERE id=? AND unidad_id=?',[$categoria,$area,$orden,$o['precio'],$o['activo'],$oferta,$u]);
            return ['resultado'=>true,'id'=>$oferta];
        });
    }
    private static function clave(string $v): void {if(!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$v))self::error('CLAVE_INVALIDA','Recarga el formulario para obtener una solicitud válida.');}
}
