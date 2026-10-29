<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Consultas acotadas por 3B. Totales por artículo, nunca entre artículos distintos. */
final class ConsultasAlmacen
{
    public function __construct(private \mysqli $db,private AlcanceInventario $alcance) {}

    public function existencias(string $ctx,array $filtros=[]): array
    {
        return $this->leer(function () use($ctx,$filtros) {
            [$f,$scope,$param]=$this->filtros($ctx,$filtros,['q','negocio','almacen_id','almacen_ids','articulo_id','pagina','por_pagina','solo_alertas','sin_asociacion','estado']);
            $predicado=$scope;
            if(($f['estado']??'todos')==='activos') $predicado.=' AND a.activo=1 AND aa.activo=1 AND i.activo=1';
            elseif(($f['estado']??'todos')==='inactivos') $predicado.=' AND (a.activo=0 OR aa.activo=0 OR i.activo=0)';
            $sub='SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades u ON u.id=a.unidad_id WHERE aa.articulo_id=i.id AND '.$predicado;
            $alertaSql='EXISTS ('.$sub.' AND aa.saldo<aa.minimo AND aa.activo=1 AND a.activo=1 AND i.activo=1)';
            $where=[]; $args=[];
            $sin=$f['sin_asociacion']??false;
            if($sin && $ctx!=='global') throw new InventarioError('FILTRO_INVALIDO','Artículos sin asociación se consultan desde Global.');
            if($sin) {
                $where[]='NOT EXISTS (SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades u ON u.id=a.unidad_id WHERE aa.articulo_id=i.id AND '.$scope.')'; $args=$param;
            } elseif($ctx!=='global' || isset($f['negocio']) || isset($f['almacen_id']) || ($f['estado']??'todos')!=='todos') {
                $where[]='EXISTS ('.$sub.')'; $args=$param;
            }
            if(!empty($f['solo_alertas'])) {
                $where[]=$alertaSql;
                array_push($args,...$param);
            }
            if(isset($f['articulo_id'])) { $where[]='i.id=?'; $args[]=$f['articulo_id']; }
            if(isset($f['q'])) { $where[]="i.nombre LIKE ? ESCAPE '!'"; $args[]=$this->buscar($f['q']); }
            $cond=$where?' WHERE '.implode(' AND ',$where):'';
            $total=(int)$this->sql('SELECT COUNT(*) FROM ingredientes i'.$cond,$args)->get_result()->fetch_row()[0];
            $alertasCond=($where?implode(' AND ',$where).' AND ':'').$alertaSql;
            $totalAlertas=(int)$this->sql('SELECT COUNT(*) FROM ingredientes i WHERE '.$alertasCond,array_merge($args,$param))->get_result()->fetch_row()[0];
            // Ordenar antes de paginar: solo prioriza alertas del alcance elegido.
            $articulos=$this->sql('SELECT i.id AS articulo_id,i.nombre,i.unidad,i.costo,i.activo FROM ingredientes i'.$cond.' ORDER BY '.$alertaSql.' DESC,i.nombre,i.id LIMIT ? OFFSET ?',array_merge($args,$param,[$f['por_pagina'],($f['pagina']-1)*$f['por_pagina']]))->get_result()->fetch_all(MYSQLI_ASSOC);
            $mapa=[];
            foreach($articulos as $i) {
                $id=(int)$i['articulo_id']; $i['articulo_id']=$id;
                $i['saldo_total']='0.000'; $i['minimo_total']='0.000'; $i['alerta']=false; $i['ambitos']=[];
                $mapa[$id]=$i;
            }
            if($mapa) {
                $ids=array_keys($mapa);
                $rows=$this->sql('SELECT aa.*,a.nombre AS almacen_nombre,a.activo AS almacen_activo,u.slug,i.activo AS articulo_activo FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades u ON u.id=a.unidad_id JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.articulo_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND '.$predicado.' ORDER BY aa.articulo_id,u.id,a.id',array_merge($ids,$param))->get_result();
                while($r=$rows->fetch_assoc()) {
                    $id=(int)$r['articulo_id']; $slug=$r['slug'];
                    if(!isset($mapa[$id]['ambitos'][$slug])) $mapa[$id]['ambitos'][$slug]=['unidad_id'=>(int)$r['unidad_id'],'slug'=>$slug,'saldo'=>'0.000','minimo'=>'0.000','almacenes'=>[]];
                    $alerta=(bool)((int)$r['activo'] && (int)$r['almacen_activo'] && (int)$r['articulo_activo'] && DecimalInventario::leer($r['saldo'],3,true)<DecimalInventario::leer($r['minimo']));
                    foreach(['saldo'=>'saldo_total','minimo'=>'minimo_total'] as $key=>$totalKey) {
                        $mapa[$id][$totalKey]=$this->sumarDecimal($mapa[$id][$totalKey],$r[$key]);
                        $mapa[$id]['ambitos'][$slug][$key]=$this->sumarDecimal($mapa[$id]['ambitos'][$slug][$key],$r[$key]);
                    }
                    $mapa[$id]['alerta']=$mapa[$id]['alerta']||$alerta;
                    $mapa[$id]['ambitos'][$slug]['almacenes'][]=['almacen_id'=>(int)$r['almacen_id'],'nombre'=>$r['almacen_nombre'],'saldo'=>$r['saldo'],'minimo'=>$r['minimo'],
                        'activo'=>(bool)$r['almacen_activo'],'asociacion_activa'=>(bool)$r['activo'],'version'=>(int)$r['version'],'alerta'=>$alerta];
                }
            }
            foreach($mapa as &$i) $i['ambitos']=array_values($i['ambitos']);
            unset($i);
            return ['codigo'=>'EXISTENCIAS_CONSULTADAS','contexto'=>$ctx,'total'=>$total,'total_alertas'=>$totalAlertas,'pagina'=>$f['pagina'],'por_pagina'=>$f['por_pagina'],'articulos'=>array_values($mapa)];
        });
    }

    public function almacenes(string $ctx,array $filtros=[]): array
    {
        return $this->leer(function () use($ctx,$filtros) {
            [$f,$scope,$args]=$this->filtros($ctx,$filtros,['q','negocio','almacen_id','almacen_ids','pagina','por_pagina','estado']);
            if(isset($f['q'])) { $scope.=" AND a.nombre LIKE ? ESCAPE '!'"; $args[]=$this->buscar($f['q']); }
            if(($f['estado']??'todos')!=='todos') { $scope.=' AND a.activo=?'; $args[]=($f['estado']==='activos'?1:0); }
            $from=' FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE '.$scope;
            $total=(int)$this->sql('SELECT COUNT(*)'.$from,$args)->get_result()->fetch_row()[0];
            $indicadores="(SELECT COUNT(*) FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=a.id AND aa.unidad_id=a.unidad_id AND aa.activo=1 AND i.activo=1) AS total_articulos,
                (SELECT COUNT(*) FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=a.id AND aa.unidad_id=a.unidad_id AND aa.activo=1 AND i.activo=1 AND a.activo=1 AND aa.saldo<aa.minimo) AS bajo_minimo,
                (SELECT COUNT(*) FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=a.id AND aa.unidad_id=a.unidad_id AND aa.activo=1 AND i.activo=1 AND a.activo=1 AND aa.saldo<=0) AS agotados";
            $rows=$this->sql('SELECT a.id AS almacen_id,a.nombre,a.unidad_id,u.slug,a.activo,a.permitir_negativos,'.$indicadores.$from.' ORDER BY u.id,a.nombre,a.id LIMIT ? OFFSET ?',array_merge($args,[$f['por_pagina'],($f['pagina']-1)*$f['por_pagina']]))->get_result()->fetch_all(MYSQLI_ASSOC);
            return ['codigo'=>'ALMACENES_CONSULTADOS','total'=>$total,'pagina'=>$f['pagina'],'por_pagina'=>$f['por_pagina'],'almacenes'=>$rows];
        });
    }

    public function movimientos(string $ctx,array $filtros=[]): array
    {
        return $this->leer(function () use($ctx,$filtros) {
            [$f,$scope,$args]=$this->filtros($ctx,$filtros,['q','negocio','almacen_id','almacen_ids','articulo_id','pagina','por_pagina','desde','hasta','tipo']);
            if(isset($f['articulo_id'])) { $scope.=' AND m.articulo_id=?'; $args[]=$f['articulo_id']; }
            if(isset($f['q'])) { $scope.=" AND i.nombre LIKE ? ESCAPE '!'"; $args[]=$this->buscar($f['q']); }
            foreach(['desde','hasta'] as $limite) if(isset($f[$limite])) {
                $fecha=$this->fecha($f[$limite]);
                $scope.=$limite==='desde'?' AND m.fecha>=?':' AND m.fecha<?';
                $args[]=($limite==='desde'?$fecha:$fecha->modify('+1 day'))->format('Y-m-d H:i:s');
            }
            if(isset($f['desde'],$f['hasta']) && $f['desde']>$f['hasta']) throw new InventarioError('FILTRO_INVALIDO','El intervalo de fechas no es válido.');
            if(isset($f['tipo'])) {
                if(!in_array($f['tipo'],['entrada','ajuste','merma','salida','cancelacion','venta','transferencia_salida','transferencia_entrada'],true)) throw new InventarioError('FILTRO_INVALIDO','El tipo de movimiento no es válido.');
                $scope.=' AND m.tipo=?'; $args[]=$f['tipo'];
            }
            $from=' FROM movimientos_inventario m JOIN almacenes a ON a.id=m.almacen_id AND a.unidad_id=m.unidad_id JOIN unidades u ON u.id=m.unidad_id JOIN ingredientes i ON i.id=m.articulo_id JOIN operaciones_inventario o ON o.id=m.operacion_id AND o.unidad_id=m.unidad_id WHERE '.$scope;
            $total=(int)$this->sql('SELECT COUNT(*)'.$from,$args)->get_result()->fetch_row()[0];
            $rows=$this->sql('SELECT m.id,m.operacion_id,m.almacen_id,a.nombre AS almacen_nombre,a.activo AS almacen_activo,m.articulo_id,i.nombre AS articulo_nombre,i.unidad,u.slug,m.tipo,m.delta,m.costo_unitario,m.motivo,m.nota,m.movimiento_origen_id,m.fecha,o.actor_tipo,o.usuario_id,o.origen,o.referencia'.$from.' ORDER BY m.fecha DESC,m.id DESC LIMIT ? OFFSET ?',array_merge($args,[$f['por_pagina'],($f['pagina']-1)*$f['por_pagina']]))->get_result()->fetch_all(MYSQLI_ASSOC);
            return ['codigo'=>'MOVIMIENTOS_CONSULTADOS','total'=>$total,'pagina'=>$f['pagina'],'por_pagina'=>$f['por_pagina'],'movimientos'=>$rows];
        });
    }

    private function filtros(string $ctx,array $filtros,array $permitidos): array
    {
        if(array_diff(array_keys($filtros),$permitidos)) throw new InventarioError('FILTRO_INVALIDO','Hay filtros no admitidos.');
        $ambito=$this->alcance->autorizar($ctx,'ver',null,true);
        $scope="u.slug IN ('global','restaurante','panaderia')"; $args=[];
        if($ctx!=='global') { $scope.=' AND a.unidad_id=?'; $args[]=$ambito['unidad_id']; }
        $f=$filtros;
        foreach(['negocio','q','desde','hasta','tipo'] as $campo) if(isset($f[$campo])) {
            if(!is_string($f[$campo]) || mb_strlen($f[$campo])>120) throw new InventarioError('FILTRO_INVALIDO','El filtro de texto no es válido.');
            $f[$campo]=trim($f[$campo]);
            if($f[$campo]==='') unset($f[$campo]);
        }
        if(isset($f['negocio'])) {
            if(!in_array($f['negocio'],['global','restaurante','panaderia'],true) || ($ctx!=='global' && $f['negocio']!==$ctx)) throw new InventarioError('AMBITO_DENEGADO','El filtro no pertenece al ámbito autorizado.');
            $scope.=' AND u.slug=?'; $args[]=$f['negocio'];
        }
        foreach(['almacen_id','articulo_id'] as $id) if(isset($f[$id])) $f[$id]=$this->entero($f[$id],1,PHP_INT_MAX);
        if(array_key_exists('almacen_ids',$f)) {
            if(!is_array($f['almacen_ids']) || count($f['almacen_ids'])>1000 || isset($f['almacen_id'])) throw new InventarioError('FILTRO_INVALIDO','Selecciona al menos un almacén y usa un único filtro de selección.');
            if(!$f['almacen_ids']) {
                $total=(int)$this->sql('SELECT COUNT(*) FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE '.$scope,$args)->get_result()->fetch_row()[0];
                if($total>0) throw new InventarioError('FILTRO_INVALIDO','Mantén al menos un almacén seleccionado.');
                $scope.=' AND 1=0';
            } else {
            $ids=array_values(array_unique(array_map(fn($id)=>$this->entero($id,1,PHP_INT_MAX),$f['almacen_ids'])));
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=$this->sql("SELECT a.id,a.unidad_id,u.slug FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.id IN ({$marks})",$ids)->get_result()->fetch_all(MYSQLI_ASSOC);
            if(count($rows)!==count($ids)) throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró alguno de los almacenes autorizados.');
            foreach($rows as $alm) if(!in_array($alm['slug'],['global','restaurante','panaderia'],true) || ($ctx!=='global' && (int)$alm['unidad_id']!==$ambito['unidad_id']) || (isset($f['negocio']) && $f['negocio']!==$alm['slug'])) throw new InventarioError('AMBITO_DENEGADO','La selección contiene un almacén ajeno al ámbito autorizado.');
            $f['almacen_ids']=$ids;
            $scope.=' AND a.id IN ('.$marks.')'; array_push($args,...$ids);
            }
        }
        if(isset($f['almacen_id'])) {
            $alm=$this->sql("SELECT a.unidad_id,u.slug FROM almacenes a JOIN unidades u ON u.id=a.unidad_id WHERE a.id=? AND u.slug IN ('global','restaurante','panaderia')",[$f['almacen_id']])->get_result()->fetch_assoc();
            if(!$alm || ($ctx!=='global' && (int)$alm['unidad_id']!==$ambito['unidad_id']) || (isset($f['negocio']) && $f['negocio']!==$alm['slug'])) throw new InventarioError('RECURSO_NO_ENCONTRADO','No se encontró el almacén autorizado.');
            $scope.=' AND a.id=?'; $args[]=$f['almacen_id'];
        }
        foreach(['solo_alertas','sin_asociacion'] as $b) if(isset($f[$b]) && !is_bool($f[$b])) throw new InventarioError('FILTRO_INVALIDO','El filtro debe ser booleano.');
        if(isset($f['estado']) && !in_array($f['estado'],['todos','activos','inactivos'],true)) throw new InventarioError('FILTRO_INVALIDO','El estado del filtro no es válido.');
        $f['pagina']=$this->entero($f['pagina']??1,1,100000);
        $f['por_pagina']=$this->entero($f['por_pagina']??25,1,100);
        return [$f,$scope,$args];
    }

    private function leer(callable $consulta): array
    {
        return TransaccionInventario::ejecutar($this->db,$consulta,'REPEATABLE READ');
    }
    private function sql(string $sql,array $args=[]): \mysqli_stmt { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private function buscar(string $q): string { return '%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%'; }
    private function entero(mixed $v,int $min,int $max): int
    {
        if((!is_int($v)&&!is_string($v)) || !preg_match('/^[0-9]{1,18}$/D',(string)$v) || (int)$v<$min || (int)$v>$max) throw new InventarioError('FILTRO_INVALIDO','La paginación o el identificador no es válido.');
        return (int)$v;
    }
    private function sumarDecimal(string $a,string $b): string
    {
        // SUM puede superar el rango de una sola asociación; se conserva precisión.
        $scaled=static function(string $s): int {
            if(!preg_match('/^-?[0-9]+\.[0-9]{3}$/D',$s)) throw new InventarioError('DECIMAL_INVALIDO','El total decimal no es válido.');
            $cifras=ltrim(str_replace(['-','.'],'',$s),'0');
            if(strlen($cifras)>18) throw new InventarioError('DECIMAL_FUERA_RANGO','El total supera el rango exacto admitido.');
            return (str_starts_with($s,'-')?-1:1)*(int)($cifras===''?'0':$cifras);
        };
        return DecimalInventario::formato(DecimalInventario::sumar($scaled($a),$scaled($b)));
    }
    private function fecha(mixed $v): \DateTimeImmutable
    {
        if(!is_string($v) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)) throw new InventarioError('FILTRO_INVALIDO','La fecha no es válida.');
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if(!$d || $d->format('Y-m-d')!==$v || (int)$d->format('Y')>9998) throw new InventarioError('FILTRO_INVALIDO','La fecha no es válida.');
        return $d;
    }
}
