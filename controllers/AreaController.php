<?php
namespace Controllers;

use Model\TicketItem;
use MVC\Router;

class AreaController {

    // GET /admin/api/area-items?area_id=X
    public static function areaItems(Router $router) {
        header('Content-Type: application/json');

        $areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;
        if (!$areaId) {
            echo json_encode(['ok' => false, 'msg' => 'area_id requerido']);
            return;
        }

        try {
            $rows = TicketItem::consultarSQL(
                "SELECT ti.id, ti.nombre, ti.cantidad, ti.comensal, ti.nota, ti.estado, ti.created_at,
                        t.id AS ticket_id, t.nombre AS ticket_nombre,
                        m.nombre AS mesa_nombre, m.numero AS mesa_numero
                 FROM ticket_items ti
                 JOIN tickets t ON t.id = ti.ticket_id AND t.estado = 'abierto'
                 JOIN mesas m ON m.id = t.mesa_id
                 WHERE ti.area_id = {$areaId}
                   AND ti.estado IN ('enviado','en_preparacion','listo')
                 ORDER BY ti.ticket_id ASC, ti.created_at ASC"
            );

            $items = array_map(fn($r) => [
                'id'            => (int)$r->id,
                'nombre'        => $r->nombre,
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
}
