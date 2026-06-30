<?php
namespace Controllers;

use Model\Mesa;
use Model\Reservacion;
use Model\Ticket;
use Model\TicketItem;
use MVC\Router;

class MapaController {

    public static function index(Router $router) {
        include_once __DIR__ . '/../views/mapa/index.php';
    }

    // GET /api/mapa?fecha=YYYY-MM-DD
    public static function api(Router $router) {
        header('Content-Type: application/json');

        $fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');
        }

        try {
            $mesas = Mesa::consultarSQL(
                "SELECT * FROM mesas WHERE activo = 1 ORDER BY numero ASC"
            );

            $reservaciones = Reservacion::consultarSQL(
                "SELECT id, nombre, hora, comensales, nota, estado, mesa_id, mesa_secundaria_id
                 FROM reservaciones
                 WHERE fecha = '{$fecha}'
                 ORDER BY hora ASC"
            );

            $tickets = Ticket::consultarSQL(
                "SELECT id, mesa_id, mesa_secundaria_id, nombre, comensales, hora_apertura, reservacion_id
                 FROM tickets
                 WHERE estado = 'abierto'"
            );
        } catch (\Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'Error de base de datos: ' . $e->getMessage(),
                'hint'  => 'Ejecuta el SQL actualizado en database/reservaciones.sql.',
            ]);
            return;
        }

        $mesasArr = array_map(function($m) {
            return [
                'id'         => (int)$m->id,
                'numero'     => (int)$m->numero,
                'nombre'     => $m->nombre,
                'tipo'       => $m->tipo,
                'capacidad'  => (int)$m->capacidad,
                'pos_x'      => (float)$m->pos_x,
                'pos_y'      => (float)$m->pos_y,
                'reservable' => (int)$m->reservable,
            ];
        }, $mesas);

        $reservasArr = array_map(function($r) {
            return [
                'id'                 => (int)$r->id,
                'nombre'             => $r->nombre,
                'hora'               => $r->hora,
                'comensales'         => (int)$r->comensales,
                'nota'               => $r->nota ?? '',
                'estado'             => $r->estado,
                'mesa_id'            => $r->mesa_id ? (int)$r->mesa_id : null,
                'mesa_secundaria_id' => $r->mesa_secundaria_id ? (int)$r->mesa_secundaria_id : null,
            ];
        }, $reservaciones);

        $ticketsArr = array_map(function($t) {
            return [
                'id'                 => (int)$t->id,
                'mesa_id'            => (int)$t->mesa_id,
                'mesa_secundaria_id' => $t->mesa_secundaria_id ? (int)$t->mesa_secundaria_id : null,
                'nombre'             => $t->nombre ?? null,
                'comensales'         => (int)$t->comensales,
                'hora_apertura'      => $t->hora_apertura,
                'reservacion_id'     => $t->reservacion_id ? (int)$t->reservacion_id : null,
            ];
        }, $tickets);

        echo json_encode([
            'fecha'         => $fecha,
            'mesas'         => $mesasArr,
            'reservaciones' => $reservasArr,
            'tickets'       => $ticketsArr,
        ]);
    }

    // POST /api/abrir-ticket
    public static function abrirTicket(Router $router) {
        header('Content-Type: application/json');

        $data       = json_decode(file_get_contents('php://input'), true) ?: [];
        $mesaId        = isset($data['mesa_id'])        ? (int)$data['mesa_id']        : 0;
        $comensales    = isset($data['comensales'])      ? (int)$data['comensales']     : 1;
        $mesa2Id       = isset($data['mesa2_id'])        ? (int)$data['mesa2_id']       : null;
        $reservaId     = isset($data['reservacion_id'])  ? (int)$data['reservacion_id'] : null;
        $allowMultiple = !empty($data['allow_multiple']);
        $nombre        = isset($data['nombre']) && trim($data['nombre'] ?? '') !== ''
                         ? trim($data['nombre']) : null;

        if (!$mesaId) {
            echo json_encode(['ok' => false, 'msg' => 'Mesa no válida']);
            return;
        }

        try {
            if (!$allowMultiple) {
                $existentes = Ticket::consultarSQL(
                    "SELECT id FROM tickets WHERE mesa_id = {$mesaId} AND estado = 'abierto' LIMIT 1"
                );
                if (!empty($existentes)) {
                    echo json_encode(['ok' => false, 'msg' => 'Esta mesa ya tiene un ticket abierto']);
                    return;
                }
            }

            $ticket            = new Ticket();
            $ticket->mesa_id   = $mesaId;
            $ticket->comensales = $comensales;
            $ticket->estado    = 'abierto';

            $resultado = $ticket->guardar();

            if (!$resultado || !$resultado['resultado']) {
                echo json_encode(['ok' => false, 'msg' => 'No se pudo crear el ticket']);
                return;
            }

            $ticketId = (int)$resultado['id'];

            // Asignar nullable FKs via UPDATE para evitar problemas con el ORM
            $updates = [];
            if ($mesa2Id)   $updates[] = "mesa_secundaria_id = {$mesa2Id}";
            if ($reservaId) $updates[] = "reservacion_id = {$reservaId}";
            if ($nombre)    $updates[] = "nombre = '" . Ticket::escaparString($nombre) . "'";
            if (!empty($updates)) {
                Ticket::ejecutarSQL(
                    "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = {$ticketId}"
                );
            }

            echo json_encode(['ok' => true, 'id' => $ticketId]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
        }
    }

    // POST /api/liberar-reservacion
    public static function liberarReservacion(Router $router) {
        header('Content-Type: application/json');

        $data      = json_decode(file_get_contents('php://input'), true) ?: [];
        $reservaId = isset($data['reservacion_id']) ? (int)$data['reservacion_id'] : 0;

        if (!$reservaId) {
            echo json_encode(['ok' => false, 'msg' => 'Reservación no válida']);
            return;
        }

        try {
            Reservacion::ejecutarSQL(
                "UPDATE reservaciones
                 SET estado = 'cancelada', mesa_id = NULL, mesa_secundaria_id = NULL
                 WHERE id = {$reservaId}"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
        }
    }

    // POST /api/cerrar-ticket
    public static function cerrarTicket(Router $router) {
        header('Content-Type: application/json');

        $data       = json_decode(file_get_contents('php://input'), true) ?: [];
        $ticketId   = isset($data['ticket_id'])   ? (int)$data['ticket_id']              : 0;
        $metodoPago = isset($data['metodo_pago'])  ? trim($data['metodo_pago'])           : '';

        if (!$ticketId) {
            echo json_encode(['ok' => false, 'msg' => 'Ticket no válido']);
            return;
        }

        $allowedMetodos = ['efectivo', 'tarjeta'];
        if (!in_array($metodoPago, $allowedMetodos, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Método de pago no válido']);
            return;
        }

        try {
            $mp = Ticket::escaparString($metodoPago);
            Ticket::ejecutarSQL(
                "UPDATE tickets SET estado = 'cerrado', metodo_pago = '{$mp}' WHERE id = {$ticketId}"
            );

            $token = bin2hex(random_bytes(16));
            Ticket::ejecutarSQL(
                "INSERT INTO feedback_tokens (ticket_id, token) VALUES ({$ticketId}, '{$token}')"
            );

            echo json_encode(['ok' => true, 'token' => $token]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
        }
    }

    // POST /api/enviar-comanda
    public static function enviarComanda(Router $router) {
        header('Content-Type: application/json');

        $data     = json_decode(file_get_contents('php://input'), true) ?: [];
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        $items    = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if (!$ticketId || empty($items)) {
            echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']);
            return;
        }

        try {
            $open = Ticket::consultarSQL(
                "SELECT id FROM tickets WHERE id = {$ticketId} AND estado = 'abierto' LIMIT 1"
            );
            if (empty($open)) {
                echo json_encode(['ok' => false, 'msg' => 'Ticket no válido o ya cerrado']);
                return;
            }

            $count = 0;
            foreach ($items as $item) {
                $nombre    = TicketItem::escaparString($item['nombre']    ?? '');
                $categoria = TicketItem::escaparString($item['categoria'] ?? '');
                $precio    = (float)($item['precio']   ?? 0);
                $areaId    = (int)($item['area_id']    ?? 3);
                $cantidad  = max(1, (int)($item['cantidad'] ?? 1));
                $comensal  = isset($item['comensal']) && $item['comensal'] !== null
                             ? (int)$item['comensal'] : null;
                $nota      = isset($item['nota']) && trim($item['nota'] ?? '') !== ''
                             ? TicketItem::escaparString(trim($item['nota'])) : null;

                if (!$nombre || $precio <= 0) continue;

                $comensalSql = $comensal !== null ? $comensal : 'NULL';
                $notaSql     = $nota !== null ? "'" . $nota . "'" : 'NULL';
                TicketItem::ejecutarSQL(
                    "INSERT INTO ticket_items
                     (ticket_id, nombre, precio, categoria, area_id, comensal, cantidad, nota, estado)
                     VALUES ({$ticketId}, '{$nombre}', {$precio}, '{$categoria}',
                             {$areaId}, {$comensalSql}, {$cantidad}, {$notaSql}, 'enviado')"
                );
                $count++;
            }

            echo json_encode(['ok' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
        }
    }

    // POST /api/cancelar-item  { item_id: X }
    public static function cancelarItem(Router $router) {
        header('Content-Type: application/json');

        $data   = json_decode(file_get_contents('php://input'), true) ?: [];
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            echo json_encode(['ok' => false, 'msg' => 'item_id requerido']);
            return;
        }

        try {
            TicketItem::ejecutarSQL(
                "UPDATE ticket_items SET estado = 'cancelado'
                 WHERE id = {$itemId} AND estado NOT IN ('entregado','cancelado')"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // POST /api/entregar-item  { item_id: X }
    public static function entregarItem(Router $router) {
        header('Content-Type: application/json');

        $data   = json_decode(file_get_contents('php://input'), true) ?: [];
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            echo json_encode(['ok' => false, 'msg' => 'item_id requerido']);
            return;
        }

        try {
            TicketItem::ejecutarSQL(
                "UPDATE ticket_items SET estado = 'entregado'
                 WHERE id = {$itemId} AND estado = 'listo'"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // POST /api/actualizar-ticket { ticket_id, nombre }
    public static function actualizarTicket(Router $router) {
        header('Content-Type: application/json');

        $data     = json_decode(file_get_contents('php://input'), true) ?: [];
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        $nombre   = isset($data['nombre']) && trim($data['nombre'] ?? '') !== ''
                    ? trim($data['nombre']) : null;

        if (!$ticketId) {
            echo json_encode(['ok' => false, 'msg' => 'ticket_id requerido']);
            return;
        }

        try {
            $val = $nombre ? "'" . Ticket::escaparString($nombre) . "'" : 'NULL';
            Ticket::ejecutarSQL(
                "UPDATE tickets SET nombre = {$val} WHERE id = {$ticketId} AND estado = 'abierto'"
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // GET /api/ticket-items?ticket_id=X
    public static function ticketItems(Router $router) {
        header('Content-Type: application/json');

        $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
        if (!$ticketId) {
            echo json_encode(['ok' => false, 'msg' => 'ticket_id requerido']);
            return;
        }

        try {
            $rows = TicketItem::consultarSQL(
                "SELECT ti.*, ap.nombre AS area_nombre, ap.slug AS area_slug, ap.color AS area_color
                 FROM ticket_items ti
                 JOIN areas_produccion ap ON ap.id = ti.area_id
                 WHERE ti.ticket_id = {$ticketId}
                 ORDER BY ti.area_id ASC, ti.created_at ASC"
            );

            $items = array_map(function($r) {
                return [
                    'id'          => (int)$r->id,
                    'nombre'      => $r->nombre,
                    'precio'      => (float)$r->precio,
                    'categoria'   => $r->categoria,
                    'area_id'     => (int)$r->area_id,
                    'area_nombre' => $r->area_nombre,
                    'area_slug'   => $r->area_slug,
                    'area_color'  => $r->area_color,
                    'comensal'    => $r->comensal !== null ? (int)$r->comensal : null,
                    'cantidad'    => (int)$r->cantidad,
                    'nota'        => $r->nota ?? null,
                    'estado'      => $r->estado,
                    'created_at'  => $r->created_at,
                ];
            }, $rows);

            echo json_encode(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}
