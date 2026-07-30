<?php
namespace Controllers;

use Model\Mesa;
use Model\Reservacion;
use Model\Ticket;
use Model\TicketItem;
use Model\Usuario;
use Classes\TicketPrinter;
use Classes\Auth;
use MVC\Router;
use Services\HorarioReservacionService;
use Services\Inventario;
use Services\ReservacionService;
use Services\ReservacionConfig;
use Services\PuntoVentaReservacionService;
use Services\Sugerencias;

class PuntoVentaController {

    public static function index(Router $router) {
        include_once __DIR__ . '/../views/punto-de-venta/index.php';
    }

    // GET /admin/api/map?fecha=YYYY-MM-DD
    public static function api(Router $router) {
        header('Content-Type: application/json');

        $fecha = HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ''));

        try {
            $mesas = Mesa::consultarSQL(
                "SELECT * FROM mesas WHERE activo = 1 ORDER BY numero ASC"
            );

            $reservaciones = Reservacion::consultarSQL(
                "SELECT
                    r.id,
                    r.nombre,
                    r.contacto,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.estado,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas_asignadas
                 FROM reservaciones r
                 LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
                 LEFT JOIN mesas m ON m.id = rm.mesa_id
                 WHERE r.fecha = '{$fecha}'
                 GROUP BY r.id, r.nombre, r.contacto, r.hora, r.comensales, r.nota, r.estado
                 ORDER BY r.hora ASC"
            );

            $tickets = Ticket::consultarSQL(
                "SELECT t.id, t.nombre,
                        t.comensales, t.hora_apertura, t.reservacion_id, t.mesero_id,
                        GROUP_CONCAT(tm.mesa_id ORDER BY tm.orden) AS mesa_ids
                 FROM tickets t
                 INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
                 WHERE t.estado = 'abierto'
                 GROUP BY t.id"
            );

            $meseros = Usuario::consultarSQL(
                "SELECT id, nombre FROM usuarios
                 WHERE rol = 'waiter' AND activo = 1
                 ORDER BY nombre ASC"
            );
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::api - ' . $e->getMessage());
            echo json_encode([
                'ok'    => false,
                'error' => 'No se pudo cargar el mapa. Intenta de nuevo.',
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
            $mesaIds = array_values(array_filter(array_map('intval', explode(',', (string)($r->mesa_ids ?? '')))));
            $mesas = array_values(array_filter(array_map('trim', explode(',', (string)($r->mesas_asignadas ?? '')))));

            return [
                'id' => (int)$r->id,
                'nombre' => $r->nombre,
                'contacto' => $r->contacto ?? '',
                'hora' => $r->hora,
                'comensales' => (int)$r->comensales,
                'nota' => $r->nota ?? '',
                'estado' => $r->estado,
                'mesa_ids' => $mesaIds,
                'mesas' => $mesas,
            ];
        }, $reservaciones);

        $ticketsArr = array_map(function($t) {
            $mesaIds = array_values(array_filter(array_map(
                'intval',
                explode(',', (string)($t->mesa_ids ?? ''))
            )));
            return [
                'id'                 => (int)$t->id,
                'nombre'             => $t->nombre ?? null,
                'comensales'         => (int)$t->comensales,
                'hora_apertura'      => $t->hora_apertura,
                'reservacion_id'     => $t->reservacion_id ? (int)$t->reservacion_id : null,
                'mesero_id'          => $t->mesero_id ? (int)$t->mesero_id : null,
                'mesa_ids'           => $mesaIds,
                'origen'             => $t->reservacion_id ? 'reservacion' : 'walk_in',
            ];
        }, $tickets);

        $meserosArr = array_map(function($u) {
            return [
                'id'     => (int)$u->id,
                'nombre' => $u->nombre,
            ];
        }, $meseros);

        echo json_encode([
            'fecha'         => $fecha,
            'mesas'         => $mesasArr,
            'reservaciones' => $reservasArr,
            'tickets'       => $ticketsArr,
            'meseros'       => $meserosArr,
        ]);
    }

    // POST /admin/api/open-ticket
    public static function abrirTicket(Router $router) {
        $data = self::entradaJson();
        $reservacionId = (int)($data['reservacion_id'] ?? 0);
        $resultado = $reservacionId > 0
            ? PuntoVentaReservacionService::comenzar(
                $reservacionId,
                (int)($_SESSION['id'] ?? 0),
                !empty($data['mesero_id']) ? (int)$data['mesero_id'] : null
            )
            : PuntoVentaReservacionService::abrirWalkIn(
                $data,
                (int)($_SESSION['id'] ?? 0)
            );
        if (($resultado['ok'] ?? false) && isset($resultado['ticket_id'])) {
            $resultado['id'] = $resultado['ticket_id'];
        }
        self::responder($resultado);
    }

    // POST /admin/api/release-reservation
    public static function liberarReservacion(Router $router) {
        $data      = self::entradaJson();
        $reservaId = isset($data['reservacion_id']) ? (int)$data['reservacion_id'] : 0;
        self::responder(PuntoVentaReservacionService::cancelar(
            $reservaId,
            (int)($_SESSION['id'] ?? 0),
            trim((string)($data['motivo'] ?? ''))
        ));
    }

    // POST /admin/api/close-ticket
    public static function cerrarTicket(Router $router) {
        header('Content-Type: application/json');

        $data       = json_decode(file_get_contents('php://input'), true) ?: [];
        $ticketId   = isset($data['ticket_id'])   ? (int)$data['ticket_id']              : 0;
        $metodoPago = isset($data['metodo_pago'])  ? trim($data['metodo_pago'])           : '';
        $separar    = !empty($data['separar_comensales']);
        $pagos      = isset($data['pagos']) && is_array($data['pagos']) ? $data['pagos'] : [];
        // Monto recibido en cuenta completa; el excedente sobre el total es propina.
        $recibido   = isset($data['recibido']) ? round((float)$data['recibido'], 2) : null;

        if (!$ticketId) {
            echo json_encode(['ok' => false, 'msg' => 'Ticket no válido']);
            return;
        }

        $ticketExistente = Ticket::consultarSQL(
            "SELECT id, estado FROM tickets WHERE id = {$ticketId} LIMIT 1"
        );
        if (!empty($ticketExistente) && $ticketExistente[0]->estado === 'cerrado') {
            self::responder(PuntoVentaReservacionService::cerrarTicket(
                $ticketId,
                'efectivo',
                0.0,
                [],
                (int)($_SESSION['id'] ?? 0)
            ));
            return;
        }

        // No se cierra una cuenta con productos sin entregar: el mesero debe
        // haber entregado todo (los cancelados no cuentan) antes de cobrar.
        // Se usa ejecutarSQL+fetch_assoc: consultarSQL descarta los alias como
        // "AS n" que no son propiedades del modelo.
        $pendientes = 0;
        try {
            $res = Ticket::ejecutarSQL(
                "SELECT COUNT(*) AS n FROM ticket_items
                 WHERE ticket_id = {$ticketId} AND estado NOT IN ('entregado','cancelado')"
            );
            if ($res && ($row = $res->fetch_assoc())) {
                $pendientes = (int)$row['n'];
            }
        } catch (\Throwable $e) {
            $pendientes = 0;
        }
        if ($pendientes > 0) {
            echo json_encode([
                'ok'  => false,
                'msg' => 'Hay ' . $pendientes . ' producto(s) sin entregar. Entrégalos antes de cerrar la cuenta.',
            ]);
            return;
        }

        // Total real de la cuenta (no se confía en el cliente). Se usa en ambas
        // ramas para validar el pago y calcular la propina.
        try {
            $rows  = TicketItem::consultarSQL(
                "SELECT precio, cantidad FROM ticket_items
                 WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'"
            );
            $total = 0.0;
            foreach ($rows as $r) {
                $total += (float)$r->precio * (int)$r->cantidad;
            }
            $total = round($total, 2);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
            return;
        }
        $totalCents = (int)round($total * 100);

        $allowedMetodos = ['efectivo', 'tarjeta'];
        $pagosLimpios   = [];
        $propina        = 0.0;

        if ($separar && !empty($pagos)) {
            // Cuenta dividida: validar el pago de cada comensal. La suma debe
            // cubrir el total; el excedente es propina.
            $sumaPagos = 0.0;
            foreach ($pagos as $p) {
                $comensal = isset($p['comensal']) ? (int)$p['comensal'] : 0;
                $metodo   = isset($p['metodo'])   ? trim($p['metodo'])  : '';
                $monto    = isset($p['monto'])    ? round((float)$p['monto'], 2) : -1;

                if ($comensal < 1 || !in_array($metodo, $allowedMetodos, true) || $monto < 0) {
                    echo json_encode(['ok' => false, 'msg' => 'Pago no válido para el comensal ' . $comensal]);
                    return;
                }
                if ($monto == 0.0) continue; // comensal sin cargo, no se registra

                $sumaPagos += $monto;
                $pagosLimpios[] = ['comensal' => $comensal, 'metodo' => $metodo, 'monto' => $monto];
            }

            if (empty($pagosLimpios)) {
                echo json_encode(['ok' => false, 'msg' => 'Debes registrar al menos un pago']);
                return;
            }

            // La suma no puede quedar por debajo del total (±1 centavo de holgura).
            $sumaCents = (int)round($sumaPagos * 100);
            if ($sumaCents < $totalCents - 1) {
                echo json_encode([
                    'ok'    => false,
                    'msg'   => 'La suma de los pagos ($' . number_format($sumaPagos, 2) .
                               ') no cubre el total de la cuenta ($' . number_format($total, 2) . ')',
                    'total' => $total,
                ]);
                return;
            }

            $propina = round(max(0.0, $sumaPagos - $total), 2);

            // Toda cuenta dividida se registra como 'dividido' a nivel ticket;
            // el método y monto de cada comensal queda en ticket_pagos.
            $metodoPago = 'dividido';
        } else {
            // Cuenta completa: un único método y el monto recibido. La propina
            // es lo recibido por encima del total.
            if (!in_array($metodoPago, $allowedMetodos, true)) {
                echo json_encode(['ok' => false, 'msg' => 'Método de pago no válido']);
                return;
            }
            // Sin monto recibido se asume pago exacto (sin propina).
            if ($recibido === null) {
                $recibido = $total;
            }
            if ((int)round($recibido * 100) < $totalCents - 1) {
                echo json_encode([
                    'ok'    => false,
                    'msg'   => 'El monto recibido ($' . number_format($recibido, 2) .
                               ') no cubre el total de la cuenta ($' . number_format($total, 2) . ')',
                    'total' => $total,
                ]);
                return;
            }
            $propina = round(max(0.0, $recibido - $total), 2);
        }

        try {
            $cierre = PuntoVentaReservacionService::cerrarTicket(
                $ticketId,
                $metodoPago,
                $propina,
                $pagosLimpios,
                (int)($_SESSION['id'] ?? 0)
            );
            if (!($cierre['ok'] ?? false)) {
                self::responder($cierre);
                return;
            }
            $token = (string)$cierre['token'];

            // Impresión de la cuenta: efecto secundario en su propio try/catch.
            // Un fallo de impresora no debe afectar el cierre ni el token de feedback.
            try {
                $trow = Ticket::consultarSQL(
                    "SELECT t.id, t.nombre AS cliente, t.comensales, t.hora_apertura,
                            m.nombre AS mesa, u.nombre AS mesero
                     FROM tickets t
                     JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
                     JOIN mesas m ON m.id = tm.mesa_id
                     LEFT JOIN usuarios u ON u.id = t.mesero_id
                     WHERE t.id = {$ticketId} LIMIT 1"
                );
                $ticketRow = array_shift($trow);

                $itemsRows = TicketItem::consultarSQL(
                    "SELECT nombre, precio, cantidad, comensal FROM ticket_items
                     WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'"
                );
                $items = array_map(function ($r) {
                    return [
                        'nombre'   => $r->nombre,
                        'precio'   => (float)$r->precio,
                        'cantidad' => (int)$r->cantidad,
                        'comensal' => $r->comensal !== null ? (int)$r->comensal : null,
                    ];
                }, $itemsRows);

                if ($ticketRow) {
                    TicketPrinter::imprimirCuenta([
                        'id'         => (int)$ticketRow->id,
                        'cliente'    => $ticketRow->cliente,
                        'comensales' => $ticketRow->comensales,
                        'mesa'       => $ticketRow->mesa,
                        'mesero'     => $ticketRow->mesero ?? null,
                    ], $items, $metodoPago, $separar);
                }
            } catch (\Throwable $e) {
                error_log('cerrarTicket — impresión de cuenta falló: ' . $e->getMessage());
            }

            echo json_encode(['ok' => true, 'token' => $token, 'propina' => $propina]);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::cerrarTicket - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo cerrar el ticket. Intenta de nuevo.']);
        }
    }

    // POST /admin/api/send-order
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
            // Acumulamos SOLO los items insertados en esta tanda, para imprimir
            // únicamente la comanda nueva (no reimprimir las de envíos anteriores
            // en un re-envío del mismo ticket). Guardamos los valores SIN escapar:
            // el escape es sólo para SQL, no para el papel.
            $itemsComanda = [];
            foreach ($items as $item) {
                $nombreRaw = trim($item['nombre'] ?? '');
                $nombre    = TicketItem::escaparString($nombreRaw);
                $categoria = TicketItem::escaparString($item['categoria'] ?? '');
                $precio    = (float)($item['precio']   ?? 0);
                $areaId    = (int)($item['area_id']    ?? 3);
                $cantidad  = max(1, (int)($item['cantidad'] ?? 1));
                $comensal  = isset($item['comensal']) && $item['comensal'] !== null
                             ? (int)$item['comensal'] : null;
                $notaRaw   = isset($item['nota']) && trim($item['nota'] ?? '') !== ''
                             ? trim($item['nota']) : null;
                $nota      = $notaRaw !== null ? TicketItem::escaparString($notaRaw) : null;

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

                // Descuenta el inventario según la receta del producto (por nombre).
                // Nunca bloquea el pedido: si no hay receta o falla, se ignora.
                $itemId = (int) TicketItem::getDB()->insert_id;
                Inventario::aplicarVenta($nombreRaw, $cantidad, $itemId ?: null);

                $itemsComanda[] = [
                    'nombre'   => $nombreRaw,
                    'cantidad' => $cantidad,
                    'comensal' => $comensal,
                    'nota'     => $notaRaw,
                    'precio'   => $precio,
                    'area_id'  => $areaId,
                ];
            }

            // Impresión de comandas: efecto secundario. Va en su propio try/catch
            // para que un fallo de impresora NUNCA altere la respuesta del endpoint.
            $printOk = true;
            try {
                $tmeta = Ticket::consultarSQL(
                    "SELECT t.nombre AS cliente, t.hora_apertura,
                            m.numero AS mesa_numero, m.nombre AS mesa_nombre,
                            u.nombre AS mesero
                     FROM tickets t
                     JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
                     JOIN mesas m ON m.id = tm.mesa_id
                     LEFT JOIN usuarios u ON u.id = t.mesero_id
                     WHERE t.id = {$ticketId} LIMIT 1"
                );
                $meta = array_shift($tmeta);
                if ($meta && !empty($itemsComanda)) {
                    $resultados = TicketPrinter::imprimirComanda($itemsComanda, [
                        'mesa'        => $meta->mesa_numero ?? '',
                        'mesa_nombre' => $meta->mesa_nombre ?? null,
                        'cliente'     => $meta->cliente     ?? null,
                        'mesero'      => $meta->mesero      ?? null,
                        'hora'        => date('H:i'),
                        'ticket_id'   => $ticketId,
                    ]);
                    // print_ok sólo es informativo; jamás cambia 'ok'.
                    $printOk = !in_array(false, $resultados, true);
                }
            } catch (\Throwable $e) {
                error_log('enviarComanda — impresión falló: ' . $e->getMessage());
                $printOk = false;
            }

            echo json_encode(['ok' => true, 'count' => $count, 'print_ok' => $printOk]);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::enviarComanda - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo enviar la comanda. Intenta de nuevo.']);
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

            // Si realmente se canceló, repone el inventario descontado por la venta.
            if (TicketItem::getDB()->affected_rows > 0) {
                Inventario::revertir($itemId);
            }

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::cancelarItem - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo cancelar el item. Intenta de nuevo.']);
        }
    }

    // POST /admin/api/deliver-item  { item_id: X }
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
            error_log('PuntoVentaController::entregarItem - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo entregar el item. Intenta de nuevo.']);
        }
    }

    // POST /admin/api/update-ticket { ticket_id, nombre }
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
            error_log('PuntoVentaController::actualizarTicket - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo actualizar el ticket. Intenta de nuevo.']);
        }
    }

    // GET /admin/api/ticket-items?ticket_id=X
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
            error_log('PuntoVentaController::ticketItems - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudieron cargar los items. Intenta de nuevo.']);
        }
    }

    /**
     * POST /api/sugerencias { ticket_id }
     * Pide a n8n las sugerencias de venta del ticket al abrir la mesa. El
     * flujo responde en el mismo request: el mesero las necesita en pantalla.
     */
    public static function sugerencias(Router $router) {
        header('Content-Type: application/json');

        $data     = json_decode(file_get_contents('php://input'), true) ?: [];
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        // vistos = producto_id que el modal ya mostró en esta sesión, para no
        // repetirlos. Es la única memoria: no se persiste nada.
        $vistos   = isset($data['vistos']) && is_array($data['vistos']) ? $data['vistos'] : [];

        if (!$ticketId) {
            echo json_encode(['ok' => false, 'estado' => 'error', 'msg' => 'ticket_id requerido']);
            return;
        }

        try {
            $resultado = Sugerencias::paraTicket($ticketId, $vistos);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::sugerencias - ' . $e->getMessage());

            [$estado, $msg] = match ($e->getMessage()) {
                'sin_config'      => ['sin_config', 'Las sugerencias automáticas aún no están configuradas.'],
                'ticket_invalido' => ['error', 'El ticket ya no está abierto.'],
                default           => ['error', 'No pudimos obtener sugerencias. Intenta de nuevo.'],
            };

            echo json_encode(['ok' => false, 'estado' => $estado, 'msg' => $msg]);
            return;
        }

        echo json_encode([
            'ok'          => true,
            'etapa'       => $resultado['etapa'],
            'sugerencias' => $resultado['sugerencias'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** GET /api/punto-de-venta/reservaciones?fecha=YYYY-MM-DD */
    public static function reservaciones(Router $router): void
    {
        $fecha = trim((string)($_GET['fecha'] ?? ReservacionConfig::fechaActual()));
        self::responder(PuntoVentaReservacionService::listar($fecha));
    }

    /** GET /api/punto-de-venta/mesa-contexto?mesa_id=N */
    public static function mesaContexto(Router $router): void
    {
        self::responder(PuntoVentaReservacionService::contextoMesa((int)($_GET['mesa_id'] ?? 0)));
    }

    /** POST /api/punto-de-venta/reservaciones/llegada */
    public static function llegada(Router $router): void
    {
        $datos = self::entradaJson();
        self::responder(PuntoVentaReservacionService::registrarLlegada(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0)
        ));
    }

    /** POST /api/punto-de-venta/reservaciones/comenzar */
    public static function comenzarReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        self::responder(PuntoVentaReservacionService::comenzar(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            !empty($datos['mesero_id']) ? (int)$datos['mesero_id'] : null
        ));
    }

    /** POST /api/punto-de-venta/reservaciones/cancelar */
    public static function cancelarReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        self::responder(PuntoVentaReservacionService::cancelar(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            trim((string)($datos['motivo'] ?? ''))
        ));
    }

    /** POST /api/punto-de-venta/reservaciones/no-show */
    public static function noShowReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        self::responder(PuntoVentaReservacionService::noShow(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            !empty($datos['override']),
            Auth::esAdmin(),
            trim((string)($datos['motivo'] ?? ''))
        ));
    }

    private static function entradaJson(): array
    {
        $datos = json_decode((string)file_get_contents('php://input'), true);
        return is_array($datos) ? $datos : $_POST;
    }

    private static function responder(array $resultado): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!($resultado['ok'] ?? false)) {
            $codigo = (string)($resultado['codigo'] ?? '');
            http_response_code(in_array($codigo, [
                PuntoVentaReservacionService::MESA_OCUPADA,
                PuntoVentaReservacionService::TICKET_ABIERTO,
                PuntoVentaReservacionService::ESTADO_INVALIDO,
            ], true) ? 409 : 422);
            $resultado['msg'] = $resultado['msg'] ?? self::mensajeOperacion($codigo);
        }
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function mensajeOperacion(string $codigo): string
    {
        return match ($codigo) {
            PuntoVentaReservacionService::NO_EXISTE => 'La reservación o ticket no existe.',
            PuntoVentaReservacionService::ESTADO_INVALIDO => 'El estado actual no permite esta acción.',
            PuntoVentaReservacionService::MESA_OCUPADA => 'Una de las mesas ya tiene un ticket abierto.',
            PuntoVentaReservacionService::TOLERANCIA_VIGENTE => 'La tolerancia de 15 minutos sigue vigente.',
            PuntoVentaReservacionService::TICKET_ABIERTO => 'La reservación tiene un ticket abierto y debe resolverse desde la cuenta.',
            PuntoVentaReservacionService::REQUIERE_CONFIRMACION => 'La mesa tiene una reservación próxima. Confirma para continuar.',
            PuntoVentaReservacionService::DATOS_INVALIDOS => 'Los datos enviados no son válidos.',
            default => 'No fue posible completar la operación.',
        };
    }

    /**
     * GET /api/corte-caja
     * Resumen de ventas del día (tickets cerrados hoy): ventas, propinas,
     * desglose por método de pago, top platillos y ventas por área. Solo
     * lectura: no persiste ningún arqueo.
     */
    public static function corteCaja(Router $router) {
        header('Content-Type: application/json');

        try {
            // Tickets cerrados hoy con su consumo (sin ítems cancelados) y propina.
            $resTickets = Ticket::ejecutarSQL(
                "SELECT t.id, t.metodo_pago, COALESCE(t.propina, 0) AS propina,
                        COALESCE((SELECT SUM(ti.precio * ti.cantidad)
                                  FROM ticket_items ti
                                  WHERE ti.ticket_id = t.id AND ti.estado <> 'cancelado'), 0) AS total
                   FROM tickets t
                  WHERE t.estado = 'cerrado' AND DATE(t.hora_apertura) = CURDATE()"
            );

            $numTickets = 0;
            $ventas     = 0.0;
            $propinas   = 0.0;
            $efectivo   = 0.0;
            $tarjeta    = 0.0;

            if ($resTickets) {
                while ($row = $resTickets->fetch_assoc()) {
                    $numTickets++;
                    $total   = (float) $row['total'];
                    $propina = (float) $row['propina'];
                    $ventas   += $total;
                    $propinas += $propina;

                    // El dinero recibido (consumo + propina) se atribuye al método.
                    // Los tickets divididos se detallan luego con ticket_pagos.
                    if ($row['metodo_pago'] === 'efectivo') {
                        $efectivo += $total + $propina;
                    } elseif ($row['metodo_pago'] === 'tarjeta') {
                        $tarjeta += $total + $propina;
                    }
                }
            }

            // Desglose de las cuentas divididas por método (ticket_pagos).
            $resPagos = Ticket::ejecutarSQL(
                "SELECT tp.metodo_pago, COALESCE(SUM(tp.monto), 0) AS monto
                   FROM ticket_pagos tp
                   JOIN tickets t ON t.id = tp.ticket_id
                  WHERE t.estado = 'cerrado' AND t.metodo_pago = 'dividido'
                        AND DATE(t.hora_apertura) = CURDATE()
                  GROUP BY tp.metodo_pago"
            );
            if ($resPagos) {
                while ($row = $resPagos->fetch_assoc()) {
                    if ($row['metodo_pago'] === 'efectivo') {
                        $efectivo += (float) $row['monto'];
                    } elseif ($row['metodo_pago'] === 'tarjeta') {
                        $tarjeta += (float) $row['monto'];
                    }
                }
            }

            // Top platillos del día por unidades vendidas.
            $top = [];
            $resTop = Ticket::ejecutarSQL(
                "SELECT ti.nombre,
                        SUM(ti.cantidad) AS unidades,
                        SUM(ti.precio * ti.cantidad) AS importe
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND DATE(t.hora_apertura) = CURDATE()
                        AND ti.estado <> 'cancelado'
                  GROUP BY ti.nombre
                  ORDER BY unidades DESC, importe DESC
                  LIMIT 6"
            );
            if ($resTop) {
                while ($row = $resTop->fetch_assoc()) {
                    $top[] = [
                        'nombre'   => $row['nombre'],
                        'unidades' => (int) $row['unidades'],
                        'importe'  => (float) $row['importe'],
                    ];
                }
            }

            // Ventas por área de producción.
            $areas = [];
            $resAreas = Ticket::ejecutarSQL(
                "SELECT COALESCE(ap.nombre, 'Sin área') AS area,
                        COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                   LEFT JOIN areas_produccion ap ON ap.id = ti.area_id
                  WHERE t.estado = 'cerrado' AND DATE(t.hora_apertura) = CURDATE()
                        AND ti.estado <> 'cancelado'
                  GROUP BY ap.nombre
                  ORDER BY importe DESC"
            );
            if ($resAreas) {
                while ($row = $resAreas->fetch_assoc()) {
                    $areas[] = [
                        'area'    => $row['area'],
                        'importe' => (float) $row['importe'],
                    ];
                }
            }

            $promedio = $numTickets > 0 ? $ventas / $numTickets : 0.0;

            echo json_encode([
                'ok'      => true,
                'fecha'   => date('Y-m-d'),
                'resumen' => [
                    'tickets'  => $numTickets,
                    'ventas'   => round($ventas, 2),
                    'propinas' => round($propinas, 2),
                    'total'    => round($ventas + $propinas, 2),
                    'promedio' => round($promedio, 2),
                ],
                'metodos' => [
                    'efectivo' => round($efectivo, 2),
                    'tarjeta'  => round($tarjeta, 2),
                ],
                'top'     => $top,
                'areas'   => $areas,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::corteCaja - ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'No se pudo cargar el corte de caja. Intenta de nuevo.']);
        }
    }

    private static function mensajeReservacion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::RESERVACION_NO_EXISTE => 'La reservacion no existe.',
            ReservacionService::ESTADO_INVALIDO => 'La reservacion ya no se puede liberar.',
            default => 'No se pudo liberar la reservacion. Intenta de nuevo.',
        };
    }
}
