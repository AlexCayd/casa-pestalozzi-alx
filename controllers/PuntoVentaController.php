<?php
namespace Controllers;

use Model\Ticket;
use Model\TicketItem;
use Model\TicketMesa;
use Model\Usuario;
use Classes\TicketPrinter;
use Classes\Auth;
use MVC\Router;
use Services\Carta;
use Services\HorarioReservacionService;
use Services\Inventario;
use Services\ReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionErrorCatalog;
use Services\PosReservacionQueryService;
use Services\PuntoVentaReservacionService;
use Services\Sugerencias;
use Services\StaffCsrfService;

class PuntoVentaController {

    public static function index(Router $router) {
        // El menú se emite en línea en la vista (window.CP_MENU / CP_AREAS).
        // Va inline y no por fetch porque punto-de-venta.js lee CP_MENU de
        // forma síncrona al abrir una mesa: con una petición asíncrona, tocar
        // una mesa en los primeros milisegundos daría un modal sin platillos.
        $menuJson = json_encode(
            Carta::paraPos(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $areasJson = json_encode(
            Carta::areasPos(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        include_once __DIR__ . '/../views/punto-de-venta/index.php';
    }

    // GET /admin/api/map?fecha=YYYY-MM-DD
    public static function api(Router $router) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: application/json');
        \Classes\Auth::liberarSesion();

        $fecha = HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ''));

        try {
            $lectura = PosReservacionQueryService::paraFecha($fecha, '', [
                'incluir_inactivas' => false,
                'calcular_conflictos' => true,
            ]);
            if (!($lectura['ok'] ?? false)) {
                self::errorJson((string)($lectura['codigo'] ?? 'MAPA_CARGA_FALLIDA'));
                return;
            }

            $meseros = Usuario::consultarSQL(
                "SELECT id, nombre FROM usuarios
                 WHERE rol = 'waiter' AND activo = 1
                 ORDER BY nombre ASC"
            );
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::api - ' . $e->getMessage());
            self::errorJson('MAPA_CARGA_FALLIDA');
            return;
        }

        $meserosArr = array_map(function($u) {
            return [
                'id'     => (int)$u->id,
                'nombre' => $u->nombre,
            ];
        }, $meseros);

        echo json_encode([
            'ok'            => true,
            'schema_version'=> $lectura['schema_version'],
            'fecha'         => $fecha,
            'mesas'         => $lectura['mesas'],
            'mesas_estado'  => $lectura['mesas_estado'],
            'reservaciones' => array_values(array_filter(
                (array)$lectura['reservaciones'],
                static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') === 'confirmada'
            )),
            'reservaciones_operativas' => $lectura['reservaciones_operativas'],
            // PosReservacionQueryService already reads the canonical open
            // projection. Keep the response contract defensive at the HTTP
            // boundary so a stale/legacy adapter cannot resurrect a closed
            // ticket in the client.
            'tickets'       => array_values(array_filter(
                (array)$lectura['tickets'],
                static fn(array $ticket): bool => (string)($ticket['estado'] ?? '') === 'abierto'
                    && ($ticket['closed_at'] ?? null) === null
            )),
            'meseros'       => $meserosArr,
            'server_time'   => $lectura['server_time'],
            'timezone'      => $lectura['timezone'],
            'config'        => $lectura['config'],
            'actualizado_en' => $lectura['actualizado_en'],
        ]);
    }

    // POST /admin/api/open-ticket
    public static function abrirTicket(Router $router) {
        $data = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
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

    // POST /admin/api/close-ticket
    public static function cerrarTicket(Router $router) {
        header('Content-Type: application/json');

        $data       = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $ticketId   = isset($data['ticket_id'])   ? (int)$data['ticket_id']              : 0;
        $metodoPago = isset($data['metodo_pago'])  ? trim($data['metodo_pago'])           : '';
        $separar    = !empty($data['separar_comensales']);
        $pagos      = isset($data['pagos']) && is_array($data['pagos']) ? $data['pagos'] : [];
        // Monto recibido en cuenta completa; el excedente sobre el total es propina.
        $recibido   = isset($data['recibido']) ? round((float)$data['recibido'], 2) : null;

        if (!$ticketId) {
            self::errorJson('TICKET_NO_VALIDO');
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
            self::errorJson('TICKET_ITEMS_PENDIENTES', 422, ['pendientes' => $pendientes]);
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
            error_log('PuntoVentaController::cerrarTicket total - ' . $e->getMessage());
            self::errorJson('TOTAL_TICKET_NO_DISPONIBLE');
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
                    self::errorJson('PAGO_INVALIDO', 422, ['comensal' => $comensal]);
                    return;
                }
                if ($monto == 0.0) continue; // comensal sin cargo, no se registra

                $sumaPagos += $monto;
                $pagosLimpios[] = ['comensal' => $comensal, 'metodo' => $metodo, 'monto' => $monto];
            }

            if (empty($pagosLimpios)) {
                self::errorJson('PAGO_REQUERIDO');
                return;
            }

            // La suma no puede quedar por debajo del total (±1 centavo de holgura).
            $sumaCents = (int)round($sumaPagos * 100);
            if ($sumaCents < $totalCents - 1) {
                self::errorJson('PAGO_INSUFICIENTE', 422, [
                    'total' => $total,
                    'recibido' => $sumaPagos,
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
                self::errorJson('METODO_PAGO_INVALIDO');
                return;
            }
            // Sin monto recibido se asume pago exacto (sin propina).
            if ($recibido === null) {
                $recibido = $total;
            }
            if ((int)round($recibido * 100) < $totalCents - 1) {
                self::errorJson('PAGO_INSUFICIENTE', 422, [
                    'total' => $total,
                    'recibido' => $recibido,
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
            self::errorJson('TICKET_CIERRE_FALLIDO');
        }
    }

    // POST /admin/api/send-order
    public static function enviarComanda(Router $router) {
        header('Content-Type: application/json');

        $data     = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        $items    = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if (!$ticketId || empty($items)) {
            self::errorJson('DATOS_INCOMPLETOS');
            return;
        }

        try {
            $open = Ticket::consultarSQL(
                "SELECT t.id FROM tickets t
                 WHERE t.id = {$ticketId}
                   AND " . TicketMesa::condicionSqlAbierto('t') . "
                 LIMIT 1"
            );
            if (empty($open)) {
                self::errorJson('TICKET_NO_VALIDO');
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
            self::errorJson('COMANDA_ENVIO_FALLIDO');
        }
    }

    // POST /api/cancelar-item  { item_id: X }
    public static function cancelarItem(Router $router) {
        header('Content-Type: application/json');

        $data   = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            self::errorJson('ITEM_ID_REQUERIDO');
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
            self::errorJson('ITEM_CANCELACION_FALLIDA');
        }
    }

    // POST /admin/api/deliver-item  { item_id: X }
    public static function entregarItem(Router $router) {
        header('Content-Type: application/json');

        $data   = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;

        if (!$itemId) {
            self::errorJson('ITEM_ID_REQUERIDO');
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
            self::errorJson('ITEM_ENTREGA_FALLIDA');
        }
    }

    // POST /admin/api/update-ticket { ticket_id, nombre }
    public static function actualizarTicket(Router $router) {
        header('Content-Type: application/json');

        $data     = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        $nombre   = isset($data['nombre']) && trim($data['nombre'] ?? '') !== ''
                    ? trim($data['nombre']) : null;

        if (!$ticketId) {
            self::errorJson('TICKET_ID_REQUERIDO');
            return;
        }

        try {
            $val = $nombre ? "'" . Ticket::escaparString($nombre) . "'" : 'NULL';
            Ticket::ejecutarSQL(
                "UPDATE tickets
                 SET nombre = {$val}
                 WHERE id = {$ticketId}
                   AND " . TicketMesa::condicionSqlAbierto('tickets')
            );
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::actualizarTicket - ' . $e->getMessage());
            self::errorJson('TICKET_ACTUALIZACION_FALLIDA');
        }
    }

    // GET /admin/api/ticket-items?ticket_id=X
    public static function ticketItems(Router $router) {
        header('Content-Type: application/json');
        \Classes\Auth::liberarSesion();

        $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
        if (!$ticketId) {
            self::errorJson('TICKET_ID_REQUERIDO');
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
            self::errorJson('TICKET_ITEMS_NO_DISPONIBLES');
        }
    }

    /**
     * POST /api/sugerencias { ticket_id }
     * Pide a n8n las sugerencias de venta del ticket al abrir la mesa. El
     * flujo responde en el mismo request: el mesero las necesita en pantalla.
     */
    public static function sugerencias(Router $router) {
        header('Content-Type: application/json');
        // Crítico: este handler puede tardar segundos esperando a n8n. Sin
        // soltar el candado, bloquea al resto de peticiones del mismo mesero.
        \Classes\Auth::liberarSesion();

        $data     = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        // vistos = producto_id que el modal ya mostró en esta sesión, para no
        // repetirlos. Es la única memoria: no se persiste nada.
        $vistos   = isset($data['vistos']) && is_array($data['vistos']) ? $data['vistos'] : [];

        if (!$ticketId) {
            self::errorJson('TICKET_ID_REQUERIDO', 422, ['estado' => 'error']);
            return;
        }

        try {
            $resultado = Sugerencias::paraTicket($ticketId, $vistos);
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::sugerencias - ' . $e->getMessage());

            [$estado, $codigo] = match ($e->getMessage()) {
                'sin_config'      => ['sin_config', 'SUGERENCIAS_NO_CONFIGURADAS'],
                'ticket_invalido' => ['error', 'SUGERENCIAS_TICKET_INVALIDO'],
                default           => ['error', 'SUGERENCIAS_ERROR'],
            };

            self::errorJson($codigo, null, ['estado' => $estado]);
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

    /** POST /api/punto-de-venta/reservaciones/comenzar */
    public static function comenzarReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
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
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
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
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
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

    private static function validarCsrfMutacion(array $datos): bool
    {
        if (StaffCsrfService::validarRequest($datos)) {
            return true;
        }

        http_response_code(419);
        echo json_encode(ReservacionErrorCatalog::enriquecer([
            'ok' => false,
            'codigo' => 'CSRF_INVALIDO',
        ], ['superficie' => 'pos']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false;
    }

    private static function responder(array $resultado): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (array_key_exists('codigo', $resultado)) {
            $resultado = ReservacionErrorCatalog::enriquecer($resultado, ['superficie' => 'pos']);
        }
        if (!($resultado['ok'] ?? false)) {
            $codigo = (string)($resultado['codigo'] ?? '');
            http_response_code(ReservacionErrorCatalog::httpStatus($codigo, 422));
        }
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/corte-caja
     * Resumen de ventas del día (tickets cerrados hoy): ventas, propinas,
     * desglose por método de pago, top platillos y ventas por área. Solo
     * lectura: no persiste ningún arqueo.
     */
    public static function corteCaja(Router $router) {
        header('Content-Type: application/json');
        \Classes\Auth::liberarSesion();

        try {
            // Tickets cerrados hoy con su consumo (sin ítems cancelados) y propina.
            $resTickets = Ticket::ejecutarSQL(
                "SELECT t.id, t.metodo_pago, COALESCE(t.propina, 0) AS propina,
                        COALESCE((SELECT SUM(ti.precio * ti.cantidad)
                                  FROM ticket_items ti
                                  WHERE ti.ticket_id = t.id AND ti.estado <> 'cancelado'), 0) AS total
                   FROM tickets t
                  WHERE t.estado = 'cerrado' AND DATE(COALESCE(t.hora_cierre, t.hora_apertura)) = CURDATE()"
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
                        AND DATE(COALESCE(t.hora_cierre, t.hora_apertura)) = CURDATE()
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
                  WHERE t.estado = 'cerrado' AND DATE(COALESCE(t.hora_cierre, t.hora_apertura)) = CURDATE()
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
                  WHERE t.estado = 'cerrado' AND DATE(COALESCE(t.hora_cierre, t.hora_apertura)) = CURDATE()
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
            self::errorJson('CORTE_CAJA_ERROR');
        }
    }

    /** @param array<string, mixed> $extra */
    private static function errorJson(string $codigo, ?int $status = null, array $extra = []): void
    {
        $payload = ReservacionErrorCatalog::enriquecer(
            array_merge(['ok' => false, 'codigo' => $codigo], $extra),
            ['superficie' => 'pos']
        );
        http_response_code($status ?? ReservacionErrorCatalog::httpStatus($codigo, 422));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}
