<?php
declare(strict_types=1);
namespace Services\Inventory;

use Services\Security\ContextoNegocio as C;

/** Catálogo operativo único y elaboraciones versionadas. No escribe saldos. */
final class Articulos
{
    public static function sql(string $sql,array $params=[]): \mysqli_stmt
    {
        $s=C::db()->prepare($sql); $s->execute($params); return $s;
    }
    public static function base(string $ctx): string { return ($ctx==='restaurante'?'/admin':'/admin/'.$ctx).'/recetas/articulos'; }
    public static function catalogo(string $ctx,string $q='',int $pagina=1): array
    {
        C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);
        if(mb_strlen($q)>120 || $pagina<1 || $pagina>100000)self::error('FILTRO_INVALIDO','Revisa la búsqueda y página.');
        $u=C::unidad($ctx);$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($q)).'%';
        $total=(int)self::sql("SELECT COUNT(*) FROM ingredientes WHERE nombre LIKE ? ESCAPE '!'",[$like])->get_result()->fetch_row()[0];
        $rows=self::sql("SELECT i.id,i.nombre,i.unidad,i.activo,c.comprable,c.componente,c.elaborable,c.almacenable,c.revision,(SELECT MAX(version) FROM articulo_recetas r WHERE r.articulo_id=i.id AND r.unidad_id=? AND publicada=1) version FROM ingredientes i LEFT JOIN articulo_configuraciones c ON c.articulo_id=i.id AND c.unidad_id=? WHERE i.nombre LIKE ? ESCAPE '!' ORDER BY i.nombre,i.id LIMIT 30 OFFSET ?",[$u,$u,$like,($pagina-1)*30])->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['articulos'=>$rows,'total'=>$total,'pagina'=>$pagina,'paginas'=>max(1,(int)ceil($total/30))];
    }
    public static function ficha(string $ctx,int $id): array
    {
        C::exigir('productos','ver',$ctx);$u=C::unidad($ctx);
        $a=self::sql('SELECT id,nombre,unidad,activo FROM ingredientes WHERE id=?',[$id])->get_result()->fetch_assoc();
        if(!$a)self::error('ARTICULO_INVALIDO','El artículo no existe.');
        $c=self::sql('SELECT * FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=?',[$id,$u])->get_result()->fetch_assoc();
        $versiones=self::sql('SELECT id,version,rendimiento,unidad,procedencia,created_at FROM articulo_recetas WHERE articulo_id=? AND unidad_id=? AND publicada=1 ORDER BY version DESC',[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['articulo'=>$a,'configuracion'=>$c?:['revision'=>0,'comprable'=>0,'componente'=>1,'elaborable'=>0,'almacenable'=>0,'produccion_previa'=>0,'costo_conocido'=>0,'activo'=>1],'versiones'=>$versiones];
    }
    public static function configurar(string $ctx,int $id,array $raw,int $revision): array
    {
        return C::transaccion(static function()use($ctx,$id,$raw,$revision){
            C::exigir('productos','editar',$ctx,true);$u=C::unidad($ctx);
            if($ctx==='global')C::facultad('catalogo.editar','global','inventario',true);
            if(array_diff(array_keys($raw),['comprable','componente','elaborable','almacenable','produccion_previa','costo_conocido','activo']))self::error('CAMPO_INVALIDO','La configuración contiene campos no admitidos.');
            $a=self::sql('SELECT id FROM ingredientes WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();if(!$a)self::error('ARTICULO_INVALIDO','El artículo no existe.');
            $antes=self::sql('SELECT * FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=? FOR UPDATE',[$id,$u])->get_result()->fetch_assoc();
            if((int)($antes['revision']??0)!==$revision)self::error('REVISION_OBSOLETA','La configuración cambió. Recarga antes de guardar.');
            $datos=[];foreach(['comprable'=>0,'componente'=>1,'elaborable'=>0,'almacenable'=>0,'produccion_previa'=>0,'costo_conocido'=>0,'activo'=>1] as $k=>$default){$v=$raw[$k]??($antes[$k]??$default);if(!in_array($v,[0,1,'0','1'],true))self::error('CAPACIDAD_INVALIDA','Las capacidades deben estar activadas o desactivadas.');$datos[$k]=(int)$v;}
            foreach(['comprable','almacenable','produccion_previa','costo_conocido'] as $k)if($datos[$k]!== (int)($antes[$k]??0))C::exigir('inventario','editar',$ctx,true);
            if($datos['produccion_previa']&&(!$datos['elaborable']||!$datos['almacenable']))self::error('CAPACIDADES_INCOMPATIBLES','La producción previa requiere elaboración y almacenamiento.');
            if($antes && (int)$antes['almacenable'] && !$datos['almacenable'] && self::sql('SELECT 1 FROM almacen_articulos WHERE articulo_id=? AND unidad_id=? AND (activo=1 OR saldo<>0) LIMIT 1',[$id,$u])->get_result()->fetch_row())self::error('ARTICULO_ASOCIADO','Retira las asociaciones físicas antes de deshabilitar almacenamiento.');
            self::sql('INSERT INTO articulo_configuraciones(articulo_id,unidad_id,comprable,componente,elaborable,almacenable,produccion_previa,costo_conocido,activo,revision) VALUES(?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE comprable=VALUES(comprable),componente=VALUES(componente),elaborable=VALUES(elaborable),almacenable=VALUES(almacenable),produccion_previa=VALUES(produccion_previa),costo_conocido=VALUES(costo_conocido),activo=VALUES(activo),revision=revision+1',array_merge([$id,$u],array_values($datos)));
            return ['ok'=>true,'revision'=>$revision+1];
        });
    }
    /** Lectores internos no autorizan al cliente: los invoca un servicio que ya
     * comprobó actor/negocio, y limitan a configuración propia o compartida. */
    public static function cargarArticulo(int $id,int $u): ?array
    {
        $r=self::sql("SELECT i.id,i.nombre,i.unidad,IF(i.activo=1 AND COALESCE(c.activo,g.activo,1)=1,1,0) activo,COALESCE(c.componente,g.componente,1) componente,COALESCE(c.almacenable,g.almacenable,1) almacenable,COALESCE(c.produccion_previa,g.produccion_previa,0) produccion_previa,CASE WHEN COALESCE(c.costo_conocido,g.costo_conocido,IF(i.costo>0,1,0))=1 THEN i.costo ELSE NULL END costo FROM ingredientes i LEFT JOIN articulo_configuraciones c ON c.articulo_id=i.id AND c.unidad_id=? LEFT JOIN articulo_configuraciones g ON g.articulo_id=i.id AND g.unidad_id=(SELECT id FROM unidades WHERE slug='global') WHERE i.id=?",[$u,$id])->get_result()->fetch_assoc();
        return $r?:null;
    }
    public static function cargarReceta(int $id,int $u): ?array
    {
        $r=self::sql("SELECT r.*,IF(u.slug='global',1,0) global FROM articulo_recetas r JOIN unidades u ON u.id=r.unidad_id WHERE r.id=? AND (r.unidad_id=? OR u.slug='global')",[$id,$u])->get_result()->fetch_assoc();
        if(!$r)return null;
        $r['componentes']=self::sql('SELECT articulo_id,cantidad,unidad,modalidad,version_receta_id,merma FROM articulo_receta_componentes WHERE receta_id=? ORDER BY id',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        return $r;
    }
    public static function prever(string $ctx,int $id,array $raw): array
    {
        C::exigir('productos','ver',$ctx);$u=C::unidad($ctx);$f=self::ficha($ctx,$id);
        $rendimiento=self::positivo($raw['rendimiento']??null);$medida=self::medida($raw['unidad']??null);$componentes=self::componentes($raw['componentes']??null);
        $temporal=PHP_INT_MAX;
        $r=['articulo_id'=>$id,'unidad_id'=>$u,'publicada'=>1,'rendimiento'=>$rendimiento,'unidad'=>$medida,'componentes'=>$componentes];
        $plan=ResolucionArticulos::resolver([['articulo_id'=>$id,'cantidad'=>$rendimiento,'unidad'=>$medida,'modalidad'=>'desglosar','version_receta_id'=>$temporal,'etapa'=>'produccion']],$u,static fn($ref)=>self::cargarArticulo($ref,$u),static fn($ref)=>$ref===$temporal?$r:self::cargarReceta($ref,$u),[],false);
        return ['plan'=>$plan,'huella'=>self::huella([$id,$f['configuracion']['revision'],$rendimiento,$medida,$componentes])];
    }
    public static function publicar(string $ctx,int $id,array $raw,int $revision,string $clave,bool $confirmado): array
    {
        return C::transaccion(static function()use($ctx,$id,$raw,$revision,$clave,$confirmado){
            C::exigir('productos','editar',$ctx,true);if($ctx==='global')C::facultad('comercial.identidad.editar','global','menu',true);
            $u=C::unidad($ctx);$hash=hash('sha256',json_encode([C::actorId(),'receta',$id,$revision,$raw],JSON_THROW_ON_ERROR));
            $anterior=self::comando($u,$clave,$hash);if($anterior!==null)return $anterior+['reintento'=>true];
            if(!$confirmado)self::error('CONFIRMACION_REQUERIDA','Confirma la nueva versión y su composición.');
            $c=self::sql('SELECT * FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=? FOR UPDATE',[$id,$u])->get_result()->fetch_assoc();
            if(!$c || !(int)$c['elaborable'] || !(int)$c['activo'])self::error('ELABORACION_NO_HABILITADA','Habilita elaboración en la configuración del artículo.');
            $repetido=self::comando($u,$clave,$hash);if($repetido!==null)return $repetido+['reintento'=>true];
            if((int)$c['revision']!==$revision)self::error('REVISION_OBSOLETA','Otra persona cambió el artículo. Recarga la ficha.');
            $rendimiento=self::positivo($raw['rendimiento']??null);$unidad=self::medida($raw['unidad']??null);$componentes=self::componentes($raw['componentes']??null);
            if($ctx==='global' && !hash_equals(self::huella([$id,$revision,$rendimiento,$unidad,$componentes]),(string)($raw['huella']??'')))self::error('HUELLA_OBSOLETA','Revisa la composición Global antes de publicarla.');
            $a=self::cargarArticulo($id,$u);ResolucionArticulos::conversion($unidad,(string)($a['unidad']??''));
            $procedencia=self::texto($raw['procedencia']??'Elaboración propia',160);
            $version=(int)self::sql('SELECT COALESCE(MAX(version),0)+1 FROM articulo_recetas WHERE articulo_id=? AND unidad_id=?',[$id,$u])->get_result()->fetch_row()[0];
            self::sql('INSERT INTO articulo_recetas(articulo_id,unidad_id,version,rendimiento,unidad,procedencia,usuario_id) VALUES(?,?,?,?,?,?,?)',[$id,$u,$version,$rendimiento,$unidad,$procedencia,C::actorId()]);$rid=(int)C::db()->insert_id;
            foreach($componentes as $h)self::sql('INSERT INTO articulo_receta_componentes(receta_id,articulo_id,cantidad,unidad,modalidad,version_receta_id,merma) VALUES(?,?,?,?,?,?,?)',[$rid,$h['articulo_id'],$h['cantidad'],$h['unidad'],$h['modalidad'],$h['version_receta_id'],$h['merma']]);
            $loader=static function($ref)use($u,$rid){$r=self::cargarReceta($ref,$u);if($ref===$rid)$r['publicada']=1;return $r;};
            $plan=ResolucionArticulos::resolver([['articulo_id'=>$id,'cantidad'=>$rendimiento,'unidad'=>$unidad,'modalidad'=>'desglosar','version_receta_id'=>$rid,'etapa'=>'produccion']],$u,static fn($ref)=>self::cargarArticulo($ref,$u),$loader,[],false);
            self::sql('UPDATE articulo_recetas SET publicada=1 WHERE id=?',[$rid]);self::sql('UPDATE articulo_configuraciones SET revision=revision+1 WHERE articulo_id=? AND unidad_id=?',[$id,$u]);
            $result=['ok'=>true,'version_id'=>$rid,'version'=>$version,'revision'=>$revision+1,'plan'=>$plan];self::recordar($u,$clave,$hash,$result);return $result;
        });
    }
    public static function componentes(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>100)self::error('COMPONENTES_INVALIDOS','Agrega de uno a cien componentes.');
        $out=[];
        foreach($raw as $h){
            if(!is_array($h))self::error('COMPONENTE_INVALIDO','Revisa cada componente.');
            $id=filter_var($h['articulo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)self::error('ARTICULO_INVALIDO','Selecciona un artículo existente.');
            $modo=$h['modalidad']??'';if(!in_array($modo,['almacenado','desglosar'],true))self::error('MODALIDAD_INVALIDA','Elige cómo se consume el componente.');
            $v=$h['version_receta_id']??null;$v=$v===''?null:$v;
            if($modo==='desglosar' && !filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))self::error('VERSION_REQUERIDA','Selecciona la versión de elaboración.');
            if($modo==='almacenado' && $v!==null)self::error('MODALIDAD_INVALIDA','Un artículo almacenado no ejecuta una versión de receta.');
            $merma=DecimalInventario::leer($h['merma']??'0.000');if($merma>=100000)self::error('MERMA_INVALIDA','La merma debe ser menor que cien por ciento.');
            $out[]=['articulo_id'=>(int)$id,'cantidad'=>self::positivo($h['cantidad']??null),'unidad'=>self::medida($h['unidad']??null),'modalidad'=>$modo,'version_receta_id'=>$v===null?null:(int)$v,'merma'=>DecimalInventario::cantidad($merma)];
        }
        return $out;
    }
    public static function preparacionesPendientes(string $ctx): array
    {
        C::exigir('productos','ver',$ctx);$u=C::unidad($ctx);
        return self::sql('SELECT s.id,s.nombre,s.rendimiento,s.unidad FROM subrecetas s LEFT JOIN articulo_correspondencias c ON c.subreceta_id=s.id AND c.unidad_id=s.unidad_id WHERE s.unidad_id=? AND c.subreceta_id IS NULL ORDER BY s.nombre',[$u])->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public static function vincularPreparacion(string $ctx,int $sub,int $articulo,bool $confirmado): array
    {
        return C::transaccion(static function()use($ctx,$sub,$articulo,$confirmado){
            C::exigir('productos','editar',$ctx,true);$u=C::vendedora($ctx);
            if(!$confirmado)self::error('CONFIRMACION_REQUERIDA','Confirma la asociación de la preparación con el artículo elegido.');
            $s=self::sql('SELECT * FROM subrecetas WHERE id=? AND unidad_id=? FOR UPDATE',[$sub,$u])->get_result()->fetch_assoc();if(!$s||!(int)$s['activo'])self::error('SUBRECETA_INVALIDA','La preparación no pertenece al negocio o está inactiva.');
            $m=self::sql('SELECT articulo_id FROM articulo_correspondencias WHERE subreceta_id=? AND unidad_id=?',[$sub,$u])->get_result()->fetch_assoc();
            if($m){if((int)$m['articulo_id']!==$articulo)self::error('CORRESPONDENCIA_EXISTENTE','Esta preparación ya está vinculada con otro artículo.');return ['ok'=>true,'reintento'=>true,'articulo_id'=>$articulo];}
            $f=self::ficha($ctx,$articulo);ResolucionArticulos::conversion($s['unidad'],$f['articulo']['unidad']);if($s['unidad']!==$f['articulo']['unidad'])self::error('UNIDAD_INCOMPATIBLE','La preparación existente y su artículo deben usar la misma unidad para conservar las cantidades históricas.');
            if($f['versiones'])self::error('ELABORACION_EXISTENTE','El artículo ya tiene elaboración. Revisa la correspondencia antes de sustituir una identidad existente.');
            $cfg=self::configurar($ctx,$articulo,['elaborable'=>1,'componente'=>1],(int)$f['configuracion']['revision']);if(($cfg['ok']??null)===false)return $cfg;
            $componentes=[];foreach(self::sql('SELECT si.articulo_id,si.cantidad,i.unidad FROM subreceta_ingredientes si JOIN ingredientes i ON i.id=si.articulo_id WHERE si.subreceta_id=? AND si.unidad_id=? ORDER BY si.id FOR SHARE',[$sub,$u])->get_result()->fetch_all(MYSQLI_ASSOC) as $h)$componentes[]=$h+['modalidad'=>'almacenado','version_receta_id'=>null];
            $r=self::publicar($ctx,$articulo,['rendimiento'=>$s['rendimiento'],'unidad'=>$s['unidad'],'procedencia'=>'Preparación existente: '.$s['nombre'],'componentes'=>$componentes],(int)$cfg['revision'],'vincular_'.bin2hex(random_bytes(16)),true);if(($r['ok']??null)===false)return $r;
            self::sql('INSERT INTO articulo_correspondencias(subreceta_id,unidad_id,articulo_id) VALUES(?,?,?)',[$sub,$u,$articulo]);
            self::sql("UPDATE variante_ajustes SET modalidad_destino='desglosar',receta_destino_id=? WHERE unidad_id=? AND ((accion='agregar' AND subreceta_id=?) OR (accion='sustituir' AND subreceta_nueva_id=?))",[$r['version_id'],$u,$sub,$sub]);
            // Se añaden referencias, nunca se reescriben cantidades históricas.
            self::sql('INSERT INTO receta_componentes_articulos(componente_id,articulo_id,version_receta_id) SELECT rc.id,?,? FROM receta_componentes rc JOIN recetas r ON r.id=rc.receta_id AND r.unidad_id=rc.unidad_id WHERE rc.subreceta_id=? AND rc.unidad_id=? AND r.activo=1',[$articulo,$r['version_id'],$sub,$u]);
            return ['ok'=>true,'articulo_id'=>$articulo,'version_id'=>$r['version_id']];
        });
    }

    public static function comando(int $u,string $clave,string $hash): ?array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$clave))self::error('CLAVE_INVALIDA','Recarga el formulario para obtener una solicitud válida.');
        $r=self::sql('SELECT huella,resultado FROM articulo_comandos WHERE unidad_id=? AND clave=? FOR UPDATE',[$u,$clave])->get_result()->fetch_assoc();
        if(!$r)return null;if(!hash_equals($r['huella'],$hash))self::error('CLAVE_REUTILIZADA','La solicitud ya se utilizó con otro contenido.');return json_decode($r['resultado'],true,512,JSON_THROW_ON_ERROR);
    }
    public static function recordar(int $u,string $clave,string $hash,array $r): void {self::sql('INSERT INTO articulo_comandos(unidad_id,clave,huella,resultado) VALUES(?,?,?,?)',[$u,$clave,$hash,json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);}
    /** MySQL normaliza el orden de claves JSON: la huella es semántica. */
    public static function huella(array $datos): string
    {
        $ordenar=static function(array $v)use(&$ordenar):array{if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$h)if(is_array($h))$h=$ordenar($h);unset($h);return $v;};
        return hash('sha256',json_encode($ordenar($datos),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public static function positivo(mixed $v): string { $n=DecimalInventario::leer($v);if($n<=0)self::error('CANTIDAD_INVALIDA','Usa una cantidad positiva con hasta tres decimales.');return DecimalInventario::cantidad($n); }
    public static function medida(mixed $v): string {if(!is_string($v)||!in_array($v,['g','kg','ml','l','pza'],true))self::error('UNIDAD_INVALIDA','Selecciona una unidad válida.');return $v;}
    public static function texto(mixed $v,int $max): string {if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max||str_contains($v,"\0"))self::error('TEXTO_INVALIDO','Revisa el nombre o la procedencia.');return trim($v);}
    private static function error(string $codigo,string $mensaje): never {throw new InventarioError($codigo,$mensaje);}
}
