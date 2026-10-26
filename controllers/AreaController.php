<?php
namespace Controllers;

use Model\TicketItem;
use Model\TicketMesa;
use MVC\Router;
use Services\Security\ContextoNegocio;
use Services\Inventory\InventarioError;

class AreaController {

    private static $AREAS = [
        'cafe'   => ['id' => 1, 'nombre' => 'Barra de Café',    'color' => '#7b5e3a'],
        'jugos'  => ['id' => 2, 'nombre' => 'Barra de Jugos',   'color' => '#e8a920'],
        'cocina' => ['id' => 3, 'nombre' => 'Cocina',            'color' => '#b03a2e'],
        'horno'  => ['id' => 4, 'nombre' => 'Horno Napolitano', 'color' => '#1a5276'],
    ];

    /**
     * Destino de los cocineros al iniciar sesión: eligen estación y de ahí
     * entran al tablero. Sin esta pantalla el rol 'cook' no tenía a dónde ir.
     */
    public static function index(Router $router) {
        $areas = ContextoNegocio::nuevo() ? self::areasNuevas() : self::$AREAS;
        include __DIR__ . '/../views/area/seleccion.php';
    }

    public static function cafe(Router $router)   { self::renderArea('cafe'); }
    public static function jugos(Router $router)  { self::renderArea('jugos'); }
    public static function cocina(Router $router) { self::renderArea('cocina'); }
    public static function horno(Router $router)  { self::renderArea('horno'); }

    private static function renderArea($slug) {
        if (ContextoNegocio::nuevo()) {
            try {
                ContextoNegocio::exigir('area', 'operar', 'restaurante');
                $area = self::areaPorSlug($slug);
                if (!$area) { http_response_code(404); echo 'Área no encontrada.'; return; }
            } catch (\Throwable $e) {
                http_response_code(403); echo 'No tienes permiso para consultar esta área.'; return;
            }
            include __DIR__ . '/../views/area/index.php';
            return;
        }
        $area = self::$AREAS[$slug];
        include __DIR__ . '/../views/area/index.php';
    }

    /**
     * GET /api/area-items?area_id=X
     *
     * Incluye los 'entregado' a propósito: el mesero puede entregar un plato
     * sin que producción lo haya marcado listo, y si el registro desapareciera
     * del tablero la cocina no se enteraría de que ya salió. Se pintan en la
     * columna de Listos con badge de entregado y sin acciones. Se van solos al
     * cerrar la cuenta, porque la consulta ya se limita a tickets abiertos.
     */
    public static function areaItems(Router $router) {
        header('Content-Type: application/json');

        if (ContextoNegocio::nuevo()) {
            self::areaItemsNuevo();
            return;
        }

        $areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
        if (!$areaId) {
            echo json_encode(['ok' => false, 'msg' => 'area_id requerido']);
            return;
        }

        try {
            $rows = TicketItem::consultarSQL(
                "SELECT ti.id, ti.nombre, ti.variante, ti.cantidad, ti.comensal, ti.nota, ti.estado, ti.created_at,
                        t.id AS ticket_id, t.nombre AS ticket_nombre,
                        m.nombre AS mesa_nombre, m.numero AS mesa_numero
                 FROM ticket_items ti
                 JOIN tickets t ON t.id = ti.ticket_id
                   AND " . TicketMesa::condicionSqlAbierto('t') . "
                 JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
                 JOIN mesas m ON m.id = tm.mesa_id
                 WHERE ti.area_id = {$areaId}
                   AND ti.estado IN ('enviado','en_preparacion','listo','entregado')
                 ORDER BY ti.ticket_id ASC, ti.created_at ASC"
            );

            $items = array_map(fn($r) => [
                'id'            => (int)$r->id,
                'nombre'        => $r->nombre,
                'variante'      => $r->variante ?? null,
                'cantidad'      => (int)$r->cantidad,
                'comensal'      => $r->comensal !== null ? (int)$r->comensal : null,
                'nota'          => $r->nota ?? null,
                'estado'        => $r->estado,
                'created_at'    => $r->created_at,
                'ticket_id'     => (int)$r->ticket_id,
                'ticket_nombre' => $r->ticket_nombre ?? null,
                'mesa_nombre'   => $r->mesa_nombre,
                'mesa_numero'   => (int)$r->mesa_numero,
            ], $rows);

            echo json_encode(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // POST /admin/api/rollback-item  { item_id: X }
    // listo -> en_preparacion -> enviado
    public static function retrocederItem(Router $router) {
        header('Content-Type: application/json');

        if (ContextoNegocio::nuevo()) {
            self::transicionarNuevo('retroceder');
            return;
        }

        $data   = json_decode(file_get_contents('php://input'), true) ?: [];
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            echo json_encode(['ok' => false, 'msg' => 'item_id requerido']);
            return;
        }

        try {
            TicketItem::ejecutarSQL(
                "UPDATE ticket_items
                 SET estado = CASE
                   WHEN estado = 'listo'          THEN 'en_preparacion'
                   WHEN estado = 'en_preparacion' THEN 'enviado'
                   ELSE estado
                 END
                 WHERE id = {$itemId}"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // POST /admin/api/advance-item  { item_id: X }
    // enviado -> en_preparacion -> listo (listo -> entregado es responsabilidad del mesero)
    public static function avanzarItem(Router $router) {
        header('Content-Type: application/json');

        if (ContextoNegocio::nuevo()) {
            self::transicionarNuevo('avanzar');
            return;
        }

        $data   = json_decode(file_get_contents('php://input'), true) ?: [];
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            echo json_encode(['ok' => false, 'msg' => 'item_id requerido']);
            return;
        }

        try {
            TicketItem::ejecutarSQL(
                "UPDATE ticket_items
                 SET estado = CASE
                   WHEN estado = 'enviado'        THEN 'en_preparacion'
                   WHEN estado = 'en_preparacion' THEN 'listo'
                   ELSE estado
                 END
                 WHERE id = {$itemId}"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /** @return array<string,array{id:int,nombre:string,color:string}> */
    private static function areasNuevas(): array
    {
        ContextoNegocio::exigir('area', 'operar', 'restaurante');
        $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad('restaurante');
        $stmt=$db->prepare('SELECT id,nombre,slug,color FROM areas_produccion WHERE unidad_id=? ORDER BY orden,id');
        $stmt->bind_param('i',$unidad); $stmt->execute(); $filas=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $areas=[];
        foreach($filas as $fila) {
            $slug=(string)$fila['slug'];
            if (!preg_match('/^[a-z0-9-]{1,20}$/D',$slug)) continue;
            $areas[$slug]=['id'=>(int)$fila['id'],'nombre'=>(string)$fila['nombre'],'color'=>self::color((string)$fila['color'])];
        }
        return $areas;
    }

    private static function areaPorSlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9-]{1,20}$/D',$slug)) return null;
        $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad('restaurante');
        $stmt=$db->prepare('SELECT id,nombre,slug,color FROM areas_produccion WHERE unidad_id=? AND slug=? LIMIT 1');
        $stmt->bind_param('is',$unidad,$slug); $stmt->execute(); $fila=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $fila ? ['id'=>(int)$fila['id'],'nombre'=>(string)$fila['nombre'],'color'=>self::color((string)$fila['color'])] : null;
    }

    private static function areaItemsNuevo(): void
    {
        try {
            ContextoNegocio::exigir('area','operar','restaurante');
            $areaId=filter_var($_GET['area_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$areaId) throw new InventarioError('AREA_INVALIDA','Indica un área válida.');
            $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad('restaurante');
            self::exigirAreaLocal($db,(int)$areaId,$unidad);
            $stmt=$db->prepare(
                "SELECT ti.id,ti.nombre,ti.variante,ti.cantidad,ti.comensal,ti.nota,ti.estado,ti.created_at,
                        t.id AS ticket_id,t.nombre AS ticket_nombre,m.nombre AS mesa_nombre,m.numero AS mesa_numero
                 FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id
                   AND t.estado='abierto' AND t.closed_at IS NULL
                 JOIN ticket_mesas tm ON tm.ticket_id=t.id AND tm.unidad_id=t.unidad_id AND tm.orden=1
                 JOIN mesas m ON m.id=tm.mesa_id AND m.unidad_id=tm.unidad_id
                 WHERE ti.unidad_id=? AND ti.area_id=? AND ti.estado IN ('enviado','en_preparacion','listo','entregado')
                 ORDER BY ti.ticket_id ASC,ti.created_at ASC"
            );
            $stmt->bind_param('ii',$unidad,$areaId); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $items=array_map(static fn($r)=>[
                'id'=>(int)$r['id'],'nombre'=>$r['nombre'],'variante'=>$r['variante']??null,'cantidad'=>(int)$r['cantidad'],
                'comensal'=>$r['comensal']!==null?(int)$r['comensal']:null,'nota'=>$r['nota']??null,'estado'=>$r['estado'],
                'created_at'=>$r['created_at'],'ticket_id'=>(int)$r['ticket_id'],'ticket_nombre'=>$r['ticket_nombre']??null,
                'mesa_nombre'=>$r['mesa_nombre'],'mesa_numero'=>(int)$r['mesa_numero'],
            ],$rows);
            echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);
        } catch(\Throwable $e) { self::errorNuevo($e); }
    }

    private static function transicionarNuevo(string $accion): void
    {
        try {
            $data=json_decode(file_get_contents('php://input'),true);
            $itemId=is_array($data)?filter_var($data['item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
            if(!$itemId) throw new InventarioError('DATOS_INVALIDOS','Indica un item válido.');
            $resultado=ContextoNegocio::transaccion(function() use($accion,$itemId): array {
                ContextoNegocio::exigir('area','operar','restaurante',true);
                $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad('restaurante');
                $stmt=$db->prepare("SELECT ti.id,ti.estado,ti.area_id,t.estado AS ticket_estado,t.closed_at FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id WHERE ti.id=? AND ti.unidad_id=? FOR UPDATE");
                $stmt->bind_param('ii',$itemId,$unidad); $stmt->execute(); $item=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if(!$item) throw new InventarioError('ITEM_NO_AUTORIZADO','El item no pertenece al Restaurante.');
                $areaId=(int)$item['area_id'];
                self::exigirAreaLocal($db,$areaId,$unidad,true);
                if($item['ticket_estado']!=='abierto' || $item['closed_at']!==null) throw new InventarioError('TICKET_NO_ABIERTO','El ticket ya no está abierto.');
                $de=$accion==='avanzar'?'enviado':'listo';
                $a=$accion==='avanzar'?'en_preparacion':'en_preparacion';
                if($accion==='avanzar' && $item['estado']==='en_preparacion') { $de='en_preparacion'; $a='listo'; }
                if($accion==='retroceder' && $item['estado']==='en_preparacion') { $de='en_preparacion'; $a='enviado'; }
                if($item['estado']!==$de) throw new InventarioError('ESTADO_ITEM_INVALIDO','El item ya no está en el estado esperado para esta acción.');
                $upd=$db->prepare('UPDATE ticket_items SET estado=? WHERE id=? AND unidad_id=? AND area_id=?');
                $upd->bind_param('siii',$a,$itemId,$unidad,$areaId); $upd->execute();
                if($upd->affected_rows!==1) { $upd->close(); throw new InventarioError('TRANSICION_FALLIDA','No se pudo actualizar el estado del item.'); }
                $upd->close();
                return ['resultado'=>true,'estado'=>$a];
            });
            if(($resultado['ok']??false)===false) throw new InventarioError((string)($resultado['codigo']??'TRANSICION_FALLIDA'),(string)($resultado['mensaje']??'No se pudo actualizar el item.'));
            echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
        } catch(\Throwable $e) { self::errorNuevo($e); }
    }

    private static function exigirAreaLocal(\mysqli $db,int $areaId,int $unidad,bool $bloquear=false): array
    {
        $stmt=$db->prepare('SELECT id FROM areas_produccion WHERE id=? AND unidad_id=? LIMIT 1'.($bloquear?' FOR UPDATE':''));
        $stmt->bind_param('ii',$areaId,$unidad); $stmt->execute(); $fila=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$fila) throw new InventarioError('AREA_NO_AUTORIZADA','El área no pertenece al Restaurante.');
        return $fila;
    }

    private static function errorNuevo(\Throwable $e): void
    {
        $codigo=$e instanceof InventarioError?$e->codigo:'ERROR_KDS';
        http_response_code(in_array($codigo,['PERMISO_DENEGADO','NIVEL_AUTH_REQUERIDO','NO_AUTORIZADO','AMBITO_DENEGADO'],true)?403:422);
        echo json_encode(['ok'=>false,'codigo'=>$codigo,'msg'=>$e instanceof InventarioError?$e->getMessage():'No se pudo consultar o actualizar el tablero.'],JSON_UNESCAPED_UNICODE);
    }

    private static function color(string $color): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/',$color)?$color:'#8b8b8b';
    }
}
