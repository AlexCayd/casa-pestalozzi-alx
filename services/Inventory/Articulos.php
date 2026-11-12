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
    /** Página de la lista: quince artículos caben en una pantalla sin desplazar (§18). */
    public const POR_PAGINA = 15;
    /** Respuestas de «¿Cómo se obtiene?». No son un discriminador guardado: se traducen a capacidades. */
    public const TIPOS = ['insumo','preparacion'];
    public const ETIQUETAS_TIPO = ['insumo'=>'Se compra','preparacion'=>'Se prepara en casa'];

    public static function catalogo(string $ctx,string $q='',int $pagina=1,string $estado='todos',string $tipo='todos'): array
    {
        C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);
        if(mb_strlen($q)>120 || $pagina<1 || $pagina>100000)self::error('FILTRO_INVALIDO','Revisa la búsqueda y página.');
        if(!in_array($estado,['todos','activos','inactivos'],true))self::error('FILTRO_INVALIDO','Selecciona un estado válido.');
        $verUso=\Classes\Auth::puede('productos','ver',$ctx);
        // «Reventa» lee recetas del negocio: sin permiso de Artículos no se filtra por ellas.
        if(!in_array($tipo,['todos','insumo','preparacion','reventa'],true)||($tipo==='reventa'&&!$verUso))self::error('FILTRO_INVALIDO','Selecciona un tipo válido.');
        $u=C::unidad($ctx);$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($q)).'%';
        // El filtro usa el mismo estado efectivo que la columna (cargarLote): activo en la
        // casa y no apagado en este negocio, con la configuración general como respaldo.
        $activoAqui="(i.activo=1 AND COALESCE((SELECT ca.activo FROM articulo_configuraciones ca WHERE ca.articulo_id=i.id AND ca.unidad_id=?),(SELECT cg.activo FROM articulo_configuraciones cg JOIN unidades ug ON ug.id=cg.unidad_id WHERE cg.articulo_id=i.id AND ug.slug='global'),1)=1)";
        $where="i.nombre LIKE ? ESCAPE '!'";$params=[$like];
        if($estado!=='todos'){$where.=($estado==='activos'?' AND ':' AND NOT ').$activoAqui;$params[]=$u;}
        // Preparación: elaboración habilitada aquí o una versión publicada utilizable (propia o de la casa).
        $preparacion="(EXISTS(SELECT 1 FROM articulo_configuraciones ce WHERE ce.articulo_id=i.id AND ce.unidad_id=? AND ce.elaborable=1) OR EXISTS(SELECT 1 FROM articulo_recetas re JOIN unidades ue ON ue.id=re.unidad_id WHERE re.articulo_id=i.id AND re.publicada=1 AND (re.unidad_id=? OR ue.slug='global')))";
        if($tipo==='preparacion'){$where.=" AND {$preparacion}";array_push($params,$u,$u);}
        elseif($tipo==='insumo'){$where.=" AND NOT {$preparacion}";array_push($params,$u,$u);}
        elseif($tipo==='reventa'){[$vigente,$pv]=self::usoVigente($ctx,null);$where.=" AND i.id IN (SELECT x.articulo_id FROM ({$vigente}) x JOIN ofertas_producto o ON o.id=x.oferta_id AND o.unidad_id=x.unidad_id WHERE o.tipo_venta='reventa')";$params=array_merge($params,$pv);}
        $total=(int)self::sql("SELECT COUNT(*) FROM ingredientes i WHERE {$where}",$params)->get_result()->fetch_row()[0];
        $rows=self::sql("SELECT i.id,i.nombre,i.unidad,i.contenido_pieza,i.activo,c.comprable,c.componente,c.elaborable,c.almacenable,c.revision,(SELECT MAX(version) FROM articulo_recetas r WHERE r.articulo_id=i.id AND r.unidad_id=? AND publicada=1) version,(SELECT MAX(rg.version) FROM articulo_recetas rg JOIN unidades ug ON ug.id=rg.unidad_id WHERE rg.articulo_id=i.id AND ug.slug='global' AND rg.publicada=1) version_casa FROM ingredientes i LEFT JOIN articulo_configuraciones c ON c.articulo_id=i.id AND c.unidad_id=? WHERE {$where} ORDER BY i.nombre,i.id LIMIT ".self::POR_PAGINA." OFFSET ?",array_merge([$u,$u],$params,[($pagina-1)*self::POR_PAGINA]))->get_result()->fetch_all(MYSQLI_ASSOC);
        if($rows){
            $ids=array_map('intval',array_column($rows,'id'));$in=implode(',',array_fill(0,count($ids),'?'));
            $efectivos=array_column(self::cargarLote($ids,$u),null,'id');
            $configuraciones=array_column(self::sql("SELECT * FROM articulo_configuraciones WHERE unidad_id=? AND articulo_id IN ($in)",array_merge([$u],$ids))->get_result()->fetch_all(MYSQLI_ASSOC),null,'articulo_id');
            $metadatos=array_column(self::sql("SELECT i.id,EXISTS(SELECT 1 FROM articulo_configuraciones c JOIN unidades u ON u.id=c.unidad_id WHERE c.articulo_id=i.id AND u.slug='global') compartida,EXISTS(SELECT 1 FROM articulo_recetas r WHERE r.articulo_id=i.id AND r.unidad_id=? AND r.publicada=1) elaboracion,EXISTS(SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE aa.articulo_id=i.id AND aa.unidad_id=? AND aa.activo=1 AND a.activo=1) asociacion,EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id=i.id AND aa.unidad_id=? AND aa.saldo<>0) existencia FROM ingredientes i WHERE i.id IN ($in)",array_merge([$u,$u,$u],$ids))->get_result()->fetch_all(MYSQLI_ASSOC),null,'id');
            $verFisico=\Classes\Auth::puede('inventario','ver',$ctx);
            $usos=$verUso?self::usoLote($ctx,$ids):[];
            // En Global el conteo de almacenes es el consolidado, igual que la ficha.
            $almacenes=$verFisico?array_column(self::sql("SELECT aa.articulo_id,COUNT(*) n FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades ua ON ua.id=aa.unidad_id WHERE aa.activo=1 AND a.activo=1 AND aa.articulo_id IN ($in) AND (aa.unidad_id=? OR (?='global' AND ua.slug IN('global','restaurante','panaderia'))) GROUP BY aa.articulo_id",array_merge($ids,[$u,$ctx]))->get_result()->fetch_all(MYSQLI_ASSOC),'n','articulo_id'):[];
            foreach($rows as &$row){
                $row['estadoEfectivo']=self::proyectarEstado($efectivos[$row['id']],$configuraciones[$row['id']]??null,$metadatos[$row['id']],$verFisico);
                $propia=!empty($row['elaborable']);
                $row['tipo']=$propia||$row['version']||$row['version_casa']?'preparacion':'insumo';
                // «De la casa»: la elabora Global y este negocio sólo la usa.
                $row['de_casa']=!$propia&&!$row['version']&&$row['version_casa']&&$ctx!=='global';
                $row['uso']=$verUso?$usos[(int)$row['id']]:null;
                $row['almacenes']=$verFisico?(int)($almacenes[$row['id']]??0):null;
            }unset($row);
        }
        return ['articulos'=>$rows,'total'=>$total,'pagina'=>$pagina,'paginas'=>max(1,(int)ceil($total/self::POR_PAGINA)),'tipo'=>$tipo];
    }
    /**
     * Composición vigente por oferta: la última versión publicada de cada presentación
     * o, si la oferta no tiene presentaciones, su receta activa (la misma regla de
     * ComposicionComercial). En Global es la lectura consolidada de los negocios (§18).
     * @return array{0:string,1:list<int>} subconsulta (articulo_id, oferta_id, unidad_id, cantidad, unidad, origen, ref_id, ref_nombre) y sus parámetros
     */
    private static function usoVigente(string $ctx,?array $ids): array
    {
        $u=C::unidad($ctx);$global=$ctx==='global';$in=$ids?implode(',',array_fill(0,count($ids),'?')):'';$ids=$ids??[];
        $presentaciones="SELECT pc.articulo_id,p.oferta_id,p.unidad_id,pc.cantidad,pc.unidad,'presentacion' origen,p.id ref_id,p.nombre ref_nombre FROM presentacion_componentes pc JOIN presentacion_versiones v ON v.id=pc.version_id AND v.publicada=1 JOIN oferta_presentaciones p ON p.id=v.presentacion_id AND p.unidad_id=v.unidad_id WHERE v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1)".($global?'':' AND p.unidad_id=?').($ids?" AND pc.articulo_id IN ($in)":'');
        $recetas="SELECT ia.id articulo_id,r.oferta_id,r.unidad_id,rc.cantidad,ia.unidad,'receta' origen,r.id ref_id,NULL ref_nombre FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id JOIN ingredientes ia ON ia.id=COALESCE(ca.articulo_id,rc.articulo_id) WHERE r.activo=1 AND NOT EXISTS(SELECT 1 FROM oferta_presentaciones p2 JOIN presentacion_versiones v3 ON v3.presentacion_id=p2.id AND v3.publicada=1 WHERE p2.oferta_id=r.oferta_id AND p2.unidad_id=r.unidad_id)".($global?'':' AND r.unidad_id=?').($ids?" AND ia.id IN ($in)":'');
        $alcance=$global?[]:[$u];
        return ["{$presentaciones} UNION ALL {$recetas}",array_merge($alcance,$ids,$alcance,$ids)];
    }
    /** Conteos de uso vigente para una página de la lista: tres consultas, sin importar cuántas filas. */
    private static function usoLote(string $ctx,array $ids): array
    {
        $u=C::unidad($ctx);$global=$ctx==='global';$in=implode(',',array_fill(0,count($ids),'?'));
        $out=array_fill_keys($ids,['recetas'=>0,'reventa'=>0,'preparaciones'=>0]);
        [$vigente,$pv]=self::usoVigente($ctx,$ids);
        foreach(self::sql("SELECT x.articulo_id,o.tipo_venta,COUNT(DISTINCT o.id) n FROM ({$vigente}) x JOIN ofertas_producto o ON o.id=x.oferta_id AND o.unidad_id=x.unidad_id GROUP BY x.articulo_id,o.tipo_venta",$pv)->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$out[(int)$r['articulo_id']][$r['tipo_venta']==='reventa'?'reventa':'recetas']=(int)$r['n'];
        foreach(self::sql("SELECT c.articulo_id,COUNT(DISTINCT r.articulo_id,r.unidad_id) n FROM articulo_receta_componentes c JOIN articulo_recetas r ON r.id=c.receta_id AND r.publicada=1 JOIN unidades u ON u.id=r.unidad_id WHERE c.articulo_id IN ($in) AND r.version=(SELECT MAX(r2.version) FROM articulo_recetas r2 WHERE r2.articulo_id=r.articulo_id AND r2.unidad_id=r.unidad_id AND r2.publicada=1)".($global?'':" AND (r.unidad_id=? OR u.slug='global')")." GROUP BY c.articulo_id",array_merge($ids,$global?[]:[$u]))->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$out[(int)$r['articulo_id']]['preparaciones']+=(int)$r['n'];
        // Una preparación anterior ya vinculada se cuenta por su elaboración, no dos veces.
        foreach(self::sql("SELECT si.articulo_id,COUNT(DISTINCT s.id) n FROM subreceta_ingredientes si JOIN subrecetas s ON s.id=si.subreceta_id AND s.unidad_id=si.unidad_id WHERE s.activo=1 AND si.articulo_id IN ($in) AND NOT EXISTS(SELECT 1 FROM articulo_correspondencias k WHERE k.subreceta_id=s.id AND k.unidad_id=s.unidad_id)".($global?'':' AND s.unidad_id=?')." GROUP BY si.articulo_id",array_merge($ids,$global?[]:[$u]))->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$out[(int)$r['articulo_id']]['preparaciones']+=(int)$r['n'];
        return $out;
    }
    /**
     * Módulo informativo «Dónde se usa»: sólo composición vigente, con el tipo de venta
     * de cada producto. No resuelve consumo ni escribe. En Global consolida Restaurante
     * y Panadería (consulta, nunca escritura); el enlace sólo existe donde hay acceso.
     */
    public static function dondeSeUsa(string $ctx,int $id): array
    {
        C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);$u=C::unidad($ctx);$global=$ctx==='global';
        $puede=static fn(string $modulo,string $slug):bool=>\Classes\Auth::puede($modulo,'ver',$slug);
        $raiz=static fn(string $slug):string=>$slug==='restaurante'?'/admin':'/admin/'.$slug;
        $out=['consolidado'=>$global,'visible'=>false,'productos'=>[],'preparaciones'=>[],'variantes'=>[],'almacenes'=>null,'recortado'=>false,'resumen'=>['recetas'=>0,'reventa'=>0,'preparaciones'=>0,'variantes'=>0]];
        $limite=60;
        if($puede('productos',$ctx)){
            $out['visible']=true;
            [$vigente,$pv]=self::usoVigente($ctx,[$id]);
            $productos=self::sql("SELECT x.oferta_id,x.cantidad,x.unidad,x.origen,x.ref_id,x.ref_nombre,o.tipo_venta,o.tipo_venta_estado,po.nombre producto,u.slug FROM ({$vigente}) x JOIN ofertas_producto o ON o.id=x.oferta_id AND o.unidad_id=x.unidad_id JOIN productos_oferta po ON po.oferta_id=o.id JOIN unidades u ON u.id=o.unidad_id ORDER BY u.slug,po.nombre,x.ref_nombre,x.ref_id LIMIT ".($limite+1),$pv)->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($productos as &$p){
                $p['url']=$p['origen']==='presentacion'
                    ?($puede('menu',$p['slug'])&&$puede('productos',$p['slug'])?$raiz($p['slug']).'/menu/presentaciones?'.http_build_query(['id'=>$p['oferta_id'],'presentacion'=>$p['ref_id']]):null)
                    :($puede('productos',$p['slug'])?$raiz($p['slug']).'/recetas/editar?'.http_build_query(['id'=>$p['oferta_id']]):null);
            }unset($p);
            $elaboraciones=self::sql("SELECT r.id version_id,r.articulo_id,r.version,i.nombre,u.slug,c.cantidad,c.unidad,0 anterior FROM articulo_receta_componentes c JOIN articulo_recetas r ON r.id=c.receta_id AND r.publicada=1 JOIN ingredientes i ON i.id=r.articulo_id JOIN unidades u ON u.id=r.unidad_id WHERE c.articulo_id=? AND r.version=(SELECT MAX(r2.version) FROM articulo_recetas r2 WHERE r2.articulo_id=r.articulo_id AND r2.unidad_id=r.unidad_id AND r2.publicada=1)".($global?'':" AND (r.unidad_id=? OR u.slug='global')")." ORDER BY i.nombre,r.id LIMIT ".($limite+1),$global?[$id]:[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($elaboraciones as &$e){
                // Una elaboración de la casa se consulta desde el negocio como versión de sólo lectura.
                $propia=$e['slug']===$ctx;
                $e['url']=$propia?self::base($ctx).'/editar?'.http_build_query(['id'=>$e['articulo_id']]).'#elaboracion'
                    :($e['slug']==='global'?self::base($ctx).'/editar?'.http_build_query(['id'=>$e['articulo_id'],'version'=>$e['version_id']])
                    :($puede('productos',$e['slug'])?self::base($e['slug']).'/editar?'.http_build_query(['id'=>$e['articulo_id']]).'#elaboracion':null));
            }unset($e);
            $anteriores=self::sql("SELECT s.id version_id,NULL articulo_id,s.version,s.nombre,u.slug,si.cantidad,i.unidad,1 anterior FROM subreceta_ingredientes si JOIN subrecetas s ON s.id=si.subreceta_id AND s.unidad_id=si.unidad_id JOIN ingredientes i ON i.id=si.articulo_id JOIN unidades u ON u.id=s.unidad_id WHERE si.articulo_id=? AND s.activo=1 AND NOT EXISTS(SELECT 1 FROM articulo_correspondencias k WHERE k.subreceta_id=s.id AND k.unidad_id=s.unidad_id)".($global?'':' AND s.unidad_id=?')." ORDER BY s.nombre,s.id LIMIT ".($limite+1),$global?[$id]:[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($anteriores as &$e)$e['url']=$puede('productos',$e['slug'])?$raiz($e['slug']).'/recetas/subrecetas/edit?'.http_build_query(['id'=>$e['version_id']]):null;unset($e);
            $variantes=self::sql("SELECT v.id,v.oferta_id,v.nombre,a.accion,a.cantidad,a.nueva_cantidad,a.articulo_id,a.articulo_nuevo_id,i.unidad,po.nombre producto,u.slug FROM variante_ajustes a JOIN producto_variantes v ON v.id=a.variante_id AND v.unidad_id=a.unidad_id JOIN productos_oferta po ON po.oferta_id=v.oferta_id JOIN ingredientes i ON i.id=? JOIN unidades u ON u.id=a.unidad_id WHERE (a.articulo_id=? OR a.articulo_nuevo_id=?)".($global?'':' AND a.unidad_id=?')." ORDER BY u.slug,po.nombre,v.nombre,a.id LIMIT ".($limite+1),$global?[$id,$id,$id]:[$id,$id,$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($variantes as &$v)$v['url']=$puede('menu',$v['slug'])?$raiz($v['slug']).'/menu/variantes?'.http_build_query(['id'=>$v['oferta_id']]):null;unset($v);
            foreach(['productos'=>$productos,'preparaciones'=>array_merge($elaboraciones,$anteriores),'variantes'=>$variantes] as $k=>$lista){if(count($lista)>$limite){$out['recortado']=true;$lista=array_slice($lista,0,$limite);}$out[$k]=$lista;}
            $vistos=['recetas'=>[],'reventa'=>[]];foreach($out['productos'] as $p)$vistos[$p['tipo_venta']==='reventa'?'reventa':'recetas'][$p['slug'].':'.$p['oferta_id']]=true;
            $out['resumen']=['recetas'=>count($vistos['recetas']),'reventa'=>count($vistos['reventa']),'preparaciones'=>count($out['preparaciones']),'variantes'=>count($out['variantes'])];
        }
        if($puede('inventario',$ctx))$out['almacenes']=self::sql("SELECT a.id,a.nombre,aa.activo,u.slug FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades u ON u.id=aa.unidad_id WHERE aa.articulo_id=? AND (aa.unidad_id=? OR (?='global' AND u.slug IN('global','restaurante','panaderia'))) ORDER BY u.slug,a.nombre,a.id LIMIT 61",[$id,$u,$ctx])->get_result()->fetch_all(MYSQLI_ASSOC);
        return $out;
    }
    /** Relaciones guardadas, acotadas y autorizadas; no resuelve ni modifica consumo. */
    public static function usos(string $ctx,int $id): array
    {
        C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);$u=C::unidad($ctx);$out=[];
        if(\Classes\Auth::puede('productos','ver',$ctx)){
            $out['elaboraciones']=self::sql("SELECT DISTINCT r.id,r.articulo_id,r.version,i.nombre FROM articulo_receta_componentes c JOIN articulo_recetas r ON r.id=c.receta_id JOIN ingredientes i ON i.id=r.articulo_id JOIN unidades u ON u.id=r.unidad_id WHERE c.articulo_id=? AND r.publicada=1 AND (r.unidad_id=? OR u.slug='global') ORDER BY r.id DESC LIMIT 21",[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            $out['anteriores']=self::sql('SELECT DISTINCT s.id,s.nombre FROM subreceta_ingredientes c JOIN subrecetas s ON s.id=c.subreceta_id AND s.unidad_id=c.unidad_id WHERE c.articulo_id=? AND s.unidad_id=? ORDER BY s.id DESC LIMIT 21',[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            if(\Classes\Auth::puede('menu','ver',$ctx)&&$ctx!=='global'){
                $out['presentaciones']=self::sql('SELECT DISTINCT p.id,p.oferta_id,p.nombre,v.version FROM presentacion_componentes c JOIN presentacion_versiones v ON v.id=c.version_id JOIN oferta_presentaciones p ON p.id=v.presentacion_id AND p.unidad_id=v.unidad_id WHERE c.articulo_id=? AND p.unidad_id=? AND v.publicada=1 ORDER BY v.version DESC,p.id LIMIT 21',[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
                $out['variantes']=self::sql('SELECT DISTINCT v.id,v.oferta_id,v.nombre FROM variante_ajustes a JOIN producto_variantes v ON v.id=a.variante_id AND v.unidad_id=a.unidad_id WHERE (a.articulo_id=? OR a.articulo_nuevo_id=?) AND v.unidad_id=? ORDER BY v.id DESC LIMIT 21',[$id,$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
                $out['recetas']=self::sql('SELECT DISTINCT r.id,r.oferta_id,r.version,p.nombre FROM receta_componentes c LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=c.id JOIN recetas r ON r.id=c.receta_id AND r.unidad_id=c.unidad_id JOIN productos_oferta p ON p.oferta_id=r.oferta_id WHERE (c.articulo_id=? OR ca.articulo_id=?) AND r.unidad_id=? ORDER BY r.version DESC,r.id DESC LIMIT 21',[$id,$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }
        if(\Classes\Auth::puede('inventario','ver',$ctx))$out['asociaciones']=self::sql("SELECT a.id,a.nombre,aa.activo,u.slug FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades u ON u.id=aa.unidad_id WHERE aa.articulo_id=? AND (aa.unidad_id=? OR (?='global' AND u.slug IN('global','restaurante','panaderia'))) ORDER BY a.nombre,a.id LIMIT 21",[$id,$u,$ctx])->get_result()->fetch_all(MYSQLI_ASSOC);
        return $out;
    }
    public static function ficha(string $ctx,int $id): array
    {
        C::exigir('productos','ver',$ctx);$u=C::unidad($ctx);
        // El costo maestro sólo se muestra en Global, igual que en el catálogo.
        $a=self::sql('SELECT id,nombre,unidad,contenido_pieza,activo'.($ctx==='global'?',costo':'').' FROM ingredientes WHERE id=?',[$id])->get_result()->fetch_assoc();
        if(!$a)self::error('ARTICULO_INVALIDO','El artículo no existe.');
        $versiones=self::sql('SELECT id,version,rendimiento,unidad,procedencia,created_at FROM articulo_recetas WHERE articulo_id=? AND unidad_id=? AND publicada=1 ORDER BY version DESC',[$id,$u])->get_result()->fetch_all(MYSQLI_ASSOC);
        $estado=self::estadoAdministrativo($ctx,$id);
        // Receta de la casa: la publica Global y un negocio la usa sin tener la suya (§18).
        $casa=$ctx==='global'?null:(self::sql("SELECT r.id,r.version FROM articulo_recetas r JOIN unidades u ON u.id=r.unidad_id WHERE r.articulo_id=? AND u.slug='global' AND r.publicada=1 ORDER BY r.version DESC LIMIT 1",[$id])->get_result()->fetch_assoc()?:null);
        return ['articulo'=>$a,'configuracion'=>$estado['configuracion'],'estadoEfectivo'=>$estado,'versiones'=>$versiones,'versionCasa'=>$casa];
    }
    /** Proyección administrativa del mismo lector efectivo que usa el motor.
     * No materializa compatibilidad ni infiere capacidades por nombre o saldo. */
    public static function estadoAdministrativo(string $ctx,int $id): array
    {
        C::exigirUno(['productos'=>'ver','inventario'=>'ver'],$ctx);$u=C::unidad($ctx);
        $efectivo=self::cargarArticulo($id,$u);if(!$efectivo)self::error('ARTICULO_INVALIDO','El artículo no existe.');
        $c=self::sql('SELECT * FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=?',[$id,$u])->get_result()->fetch_assoc();
        $m=self::sql("SELECT EXISTS(SELECT 1 FROM articulo_configuraciones c JOIN unidades u ON u.id=c.unidad_id WHERE c.articulo_id=? AND u.slug='global') compartida,EXISTS(SELECT 1 FROM articulo_recetas r WHERE r.articulo_id=? AND r.unidad_id=? AND r.publicada=1) elaboracion,EXISTS(SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE aa.articulo_id=? AND aa.unidad_id=? AND aa.activo=1 AND a.activo=1) asociacion,EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id=? AND aa.unidad_id=? AND aa.saldo<>0) existencia",[$id,$id,$u,$id,$u,$id,$u])->get_result()->fetch_assoc();
        return self::proyectarEstado($efectivo,$c?:null,$m,\Classes\Auth::puede('inventario','ver',$ctx));
    }
    private static function proyectarEstado(array $efectivo,?array $c,array $m,bool $verFisico): array
    {
        $configuracion=$c?:['revision'=>0,'comprable'=>0,'componente'=>(int)$efectivo['componente'],'elaborable'=>0,'almacenable'=>(int)$efectivo['almacenable'],'produccion_previa'=>(int)$efectivo['produccion_previa'],'costo_conocido'=>$efectivo['costo']!==null?1:0,'activo'=>(int)$efectivo['activo']];
        $origen=$c?'explicita':($m['compartida']?'compartida':'heredada');
        $estado=$origen;$etiqueta=match($origen){'explicita'=>'Configuración explícita','compartida'=>'Configuración compartida efectiva',default=>'Configuración heredada efectiva'};
        $detalle=$c?'Configuración propia de este negocio.':'Usa la configuración general de la casa. Al guardar un cambio aquí, este negocio tendrá la suya; consultar no modifica el artículo.';
        if(!$efectivo['activo']||($efectivo['produccion_previa']&&!$efectivo['almacenable'])){$estado='incompatible';$etiqueta='Configuración incompatible';$detalle='El artículo está inactivo o su configuración impide el consumo. Revísala antes de utilizarlo.';}
        elseif(!empty($configuracion['elaborable'])&&!$m['elaboracion']){$estado='incompleta';$etiqueta='Elaboración pendiente de publicar';$detalle='Es una preparación sin receta publicada. Captura y publica su receta para poder elaborarla.';}
        elseif(!$efectivo['componente']&&!$efectivo['almacenable']){$estado='incompleta';$etiqueta='Configuración incompleta';$detalle='No se puede usar en recetas ni lleva existencias. Revisa sus opciones avanzadas.';}
        $fisico=null;if($verFisico)$fisico=$m['asociacion']?($m['existencia']?'Con asociación y existencias registradas.':'Con asociación física, sin existencias registradas.'):'Sin asociación física activa en este negocio.';
        // Lectura de «¿Cómo se obtiene?»: se deriva de `elaborable`, no se guarda (§18).
        $tipo=!empty($configuracion['elaborable'])?'preparacion':'insumo';
        return compact('configuracion','efectivo','origen','estado','etiqueta','detalle','fisico','tipo');
    }
    /** La consulta no resuelve ingredientes actuales ni escribe versiones. */
    public static function consultarVersion(string $ctx,int $articulo,int $version): array
    {
        C::exigir('productos','ver',$ctx);$r=self::cargarReceta($version,C::unidad($ctx));
        if(!$r||!(int)$r['publicada']||(int)$r['articulo_id']!==$articulo)self::error('VERSION_DENEGADA','La versión no pertenece a este artículo o no está disponible en el negocio.');
        $r['componentes']=self::sql("SELECT c.*,i.nombre,r.version version_referenciada,u.slug contexto_version FROM articulo_receta_componentes c JOIN ingredientes i ON i.id=c.articulo_id LEFT JOIN articulo_recetas r ON r.id=c.version_receta_id LEFT JOIN unidades u ON u.id=r.unidad_id WHERE c.receta_id=? ORDER BY c.id",[$version])->get_result()->fetch_all(MYSQLI_ASSOC);
        return $r;
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
    /**
     * Captura simple de la ficha y del alta (§18): «¿Cómo se obtiene?» y las opciones
     * avanzadas se traducen a las siete capacidades y se guardan con `configurar()`,
     * que conserva permisos, revisión y reglas. Parte del estado efectivo, no de los
     * valores por omisión: crear la primera fila nunca apaga almacenamiento ni costo.
     */
    public static function clasificar(string $ctx,int $id,array $raw,int $revision): array
    {
        if(array_diff(array_keys($raw),['tipo','activo','componente','almacenable','costo_conocido']))self::error('CAMPO_INVALIDO','La configuración contiene campos no admitidos.');
        if(!in_array($raw['tipo']??null,self::TIPOS,true))self::error('TIPO_INVALIDO','Elige si el artículo se compra o se prepara en casa.');
        $actual=self::estadoAdministrativo($ctx,$id)['configuracion'];
        $datos=[];foreach(['comprable','componente','elaborable','almacenable','produccion_previa','costo_conocido','activo'] as $k)$datos[$k]=(int)($actual[$k]??0);
        foreach(['activo','componente','almacenable','costo_conocido'] as $k)if(array_key_exists($k,$raw)){if(!in_array($raw[$k],[0,1,'0','1'],true))self::error('CAPACIDAD_INVALIDA','Las opciones deben estar activadas o desactivadas.');$datos[$k]=(int)$raw[$k];}
        $datos['elaborable']=$raw['tipo']==='preparacion'?1:0;
        // `comprable` es informativa y pide permiso de Almacenes: sólo se deriva cuando lo hay.
        if($raw['tipo']==='insumo'&&\Classes\Auth::puede('inventario','editar',$ctx))$datos['comprable']=1;
        if(!$datos['elaborable']||!$datos['almacenable'])$datos['produccion_previa']=0;
        return self::configurar($ctx,$id,$datos,$revision);
    }
    /** Lectores internos no autorizan al cliente: los invoca un servicio que ya
     * comprobó actor/negocio, y limitan a configuración propia o compartida. */
    public static function cargarArticulo(int $id,int $u): ?array
    {
        return self::cargarLote([$id],$u)[0]??null;
    }
    /** Misma lectura efectiva para ficha y página acotada del catálogo. */
    private static function cargarLote(array $ids,int $u): array
    {
        $in=implode(',',array_fill(0,count($ids),'?'));
        return self::sql("SELECT i.id,i.nombre,i.unidad,IF(i.activo=1 AND COALESCE(c.activo,g.activo,1)=1,1,0) activo,COALESCE(c.componente,g.componente,1) componente,COALESCE(c.almacenable,g.almacenable,1) almacenable,COALESCE(c.produccion_previa,g.produccion_previa,0) produccion_previa,CASE WHEN COALESCE(c.costo_conocido,g.costo_conocido,IF(i.costo>0,1,0))=1 THEN i.costo ELSE NULL END costo FROM ingredientes i LEFT JOIN articulo_configuraciones c ON c.articulo_id=i.id AND c.unidad_id=? LEFT JOIN articulo_configuraciones g ON g.articulo_id=i.id AND g.unidad_id=(SELECT id FROM unidades WHERE slug='global') WHERE i.id IN ($in)",array_merge([$u],$ids))->get_result()->fetch_all(MYSQLI_ASSOC);
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
            C::exigir('productos','editar',$ctx,true);if($ctx==='global'){C::exigir('inventario','editar','global',true);C::facultad('catalogo.editar','global','inventario',true);}
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
