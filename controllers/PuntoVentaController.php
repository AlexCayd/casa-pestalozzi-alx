<?php
namespace Controllers;

use Model\ConfiguracionPos;
use Model\Mesa;
use Model\Producto;
use Model\Reservacion;
use Model\Ticket;
use Model\TicketItem;
use Model\TicketMesa;
use Model\Usuario;
use Classes\TicketPrinter;
use Classes\Auth;
use Services\Security\Permisos;
use Services\Pos\CorteCaja;
use MVC\Router;
use Services\Menu\Carta;
use Services\Menu\Variantes;
use Services\Reservations\HorarioReservacionService;
use Services\Inventory\Inventario;
use Services\Reservations\ReservacionService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionErrorCatalog;
use Services\Pos\PosReservacionQueryService;
use Services\Pos\ImpresionAlertaService;
use Services\Pos\PosReservacionSerializer;
use Services\Pos\PuntoVentaReservacionService;
use Services\Analytics\Sugerencias;
use Services\Security\StaffCsrfService;

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

        $enlaceInicial = self::enlaceInicial($_GET);

        include_once __DIR__ . '/../views/punto-de-venta/index.php';
    }

    /**
     * GET /admin/reservaciones/operacion (y su alias en inglés). La pantalla de
     * Operación se retiró: todo lo que hacía vive en el POS. La redirección
     * conserva lo que el enlace pedía —fecha, hora, reservación y si era para
     * asignar mesas— para que el buzón viejo o un favorito sigan llevando al
     * mismo sitio. 302 y no 301: un navegador no debe recordar esto para siempre
     * si algún día la dirección vuelve a significar algo.
     */
    public static function redirigirDesdeOperacion(Router $router): void {
        $enlace = self::enlaceInicial($_GET);
        $consulta = ['fecha' => $enlace['fecha']];
        if ($enlace['reservacion_id'] > 0) {
            $consulta['reservation_id'] = $enlace['reservacion_id'];
            if ($enlace['modo'] === 'asignar') {
                $consulta['mode'] = 'assign';
            }
        } elseif ($enlace['hora'] !== '') {
            $consulta['hora'] = $enlace['hora'];
        }
        header('Location: /punto-de-venta?' . http_build_query($consulta), true, 302);
        exit;
    }

    /**
     * Lo que un enlace puede pedirle al POS al abrir: fecha, hora, una
     * reservación enfocada y si se entra a elegirle mesas. Lo usan el buzón, el
     * panel de reservaciones y los favoritos viejos de Operación.
     *
     * Se valida aquí y no en el JS porque lo que llega por query string es
     * ajeno: una fecha mal escrita no debe acabar en el contrato con la API, y
     * una reservación sin fecha se resuelve a la SUYA —abrir la de mañana sobre
     * el mapa de hoy daría una ficha que no está en la lista—.
     *
     * @return array{fecha:string,hora:string,reservacion_id:int,modo:string}
     */
    public static function enlaceInicial(array $consulta): array {
        $hoy = \Services\Reservations\ReservacionConfig::fechaActual();
        $fecha = trim((string)($consulta['fecha'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1 || !checkdate((int)substr($fecha, 5, 2), (int)substr($fecha, 8, 2), (int)substr($fecha, 0, 4))) {
            $fecha = '';
        }
        $hora = substr(trim((string)($consulta['hora'] ?? '')), 0, 5);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
            $hora = '';
        }
        $reservacionId = filter_var(
            $consulta['reservation_id'] ?? ($consulta['reservacion_id'] ?? 0),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) ?: 0;
        $modo = '';

        if ($reservacionId > 0) {
            $reservacion = Reservacion::find($reservacionId);
            if (!$reservacion) {
                $reservacionId = 0;
            } else {
                // La reservación manda sobre la fecha del enlace: es su día.
                $fecha = (string)$reservacion->fecha;
                $modo = in_array((string)($consulta['mode'] ?? ''), ['assign', 'asignar'], true) ? 'asignar' : '';
                // La hora la pone la propia reserva al abrirse su ficha.
                $hora = '';
            }
        }

        return [
            'fecha' => $fecha !== '' ? $fecha : $hoy,
            'hora' => $hora,
            'reservacion_id' => $reservacionId,
            'modo' => $modo,
        ];
    }

    // GET /admin/api/map?fecha=YYYY-MM-DD
    public static function api(Router $router) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: application/json');
        \Classes\Auth::liberarSesion();
        // Antes de leer: la reserva cuyo cliente no llegó al vencer la
        // tolerancia se registra como ausencia y su mesa sale libre en este
        // mismo sondeo.
        \Services\Reservations\ReservacionAusenciaAutomaticaService::procesarEnLectura();
        // Y la reserva por llegar cuya mesa sigue ocupada se mueve a otra
        // libre: el mapa no tiene un estado «ocupada, con reservación».
        \Services\Reservations\ReservacionReubicacionPorTicketService::procesarEnLectura();

        $fecha = HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ''));
        // Hora de proyección: vacía es AHORA. Sólo HH:MM o HH:MM:SS; cualquier
        // otra cosa se descarta en vez de proyectar a una hora inventada.
        $hora = HorarioReservacionService::normalizarHoraSql((string)($_GET['hora'] ?? ''));
        $reservacionId = max(0, (int)($_GET['reservacion_id'] ?? 0));
        $intencion = (string)($_GET['intencion'] ?? '') === 'asignar' ? 'asignar' : '';

        try {
            $lectura = PosReservacionQueryService::paraFecha($fecha, $hora, [
                'incluir_inactivas' => false,
                'calcular_conflictos' => true,
                'superficie' => Auth::puede('reservations', Permisos::EDITAR) ? 'admin' : 'waiter',
                'contexto_pos' => true,
                'reservacion_enfocada_id' => $reservacionId,
                'intencion' => $intencion,
                // Al reasignar, la reserva no compite consigo misma: sus mesas
                // actuales se ven como suyas y no como un conflicto.
                'reservacion_en_edicion_id' => $intencion === 'asignar' ? $reservacionId : 0,
                'excluir_reservacion_id' => $intencion === 'asignar' ? $reservacionId : 0,
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
            // El cajón cuenta el día entero: las que están en mesa, las
            // apartadas por un hold y las resueltas (canceladas, no llegaron,
            // atendidas). Va aparte de `reservaciones` porque la lógica de
            // mesas del POS da por hecho que ésa sólo trae confirmadas.
            'reservaciones_dia' => array_values(array_filter(
                (array)$lectura['reservaciones'],
                static fn(array $reservacion): bool => in_array(
                    (string)($reservacion['estado'] ?? ''),
                    ['confirmada', 'en_curso', 'pendiente_verificacion', 'cancelada', 'no_show', 'completada'],
                    true
                )
            )),
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
            'contexto'      => $lectura['contexto'] ?? null,
            'horario'       => $lectura['horario'] ?? null,
            'capacidad_horario' => $lectura['capacidad_horario'] ?? null,
            // Lo que la pantalla puede ofrecer según quién mira. El servidor
            // revalida cada acción; esto sólo evita enseñar botones muertos.
            'permisos'      => [
                // Los poderes administrativos del POS son los de quien edita
                // Reservaciones (los roles de sistema los tienen todos).
                'es_admin' => Auth::puede('reservations', Permisos::EDITAR),
                'comentar_reservacion' => Auth::puede('reservations', Permisos::EDITAR),
                'ver_contacto' => Auth::puede('reservations'),
                'crear_reservacion' => true,
                'cambiar_mesas' => true,
            ],
            // Vacío con el servicio de impresión apagado: sin papel esperado
            // no hay nada que avisar.
            'alertas_impresion' => ImpresionAlertaService::pendientes(),
            'server_time'   => $lectura['server_time'],
            'timezone'      => $lectura['timezone'],
            // 'temporal' lo calcula PosReservacionQueryService; 'pos' es el
            // ajuste de /admin/configuracion/pos y viaja en cada refresco del
            // mapa para que cambiarlo se note en las tablets del turno sin
            // pedirles que recarguen. Van en la MISMA entrada a propósito: al
            // declarar 'config' dos veces en este array, PHP se queda con la
            // última y el bloque de 'pos' desaparecía sin ningún error.
            'config'        => ($lectura['config'] ?? []) + [
                'pos' => [
                    'mesero_editable' => ConfiguracionPos::meseroEditable(),
                    'max_tickets_barra' => ConfiguracionPos::maxTicketsBarra(),
                ],
                'reservaciones' => [
                    'max_comensales_publico' => ReservacionConfig::maxComensalesPublico(),
                    'umbral_grupo_grande' => ReservacionConfig::umbralGrupoGrande(),
                    'min_comensales_bloque_exterior' => ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR,
                ],
            ],
            'actualizado_en' => $lectura['actualizado_en'],
        ]);
    }

    /**
     * Mesero con el que se abre el ticket.
     *
     * Con la selección manual desactivada (/admin/configuracion/pos) el valor
     * que mande el cliente se ignora y manda la sesión: bloquear el <select> en
     * el navegador no es una garantía, cualquiera puede llamar al endpoint.
     * Si el de la sesión no es un mesero asignable —un admin o un cajero— se
     * respeta lo enviado, porque no hay con qué sustituirlo y el POS deja el
     * campo editable en ese mismo caso.
     */
    private static function meseroDelTicket(array $data): ?int {
        $enviado = !empty($data['mesero_id']) ? (int)$data['mesero_id'] : null;
        if (ConfiguracionPos::meseroEditable()) {
            return $enviado;
        }

        $sesionId = (int)($_SESSION['id'] ?? 0);
        return $sesionId > 0 && self::esMeseroAsignable($sesionId) ? $sesionId : $enviado;
    }

    /** Mismo criterio que la lista `meseros` de la API: rol waiter y activo. */
    private static function esMeseroAsignable(int $usuarioId): bool {
        try {
            $filas = Usuario::consultarSQL(
                "SELECT id FROM usuarios
                  WHERE id = {$usuarioId} AND rol = 'waiter' AND activo = 1
                  LIMIT 1"
            );
        } catch (\Throwable $e) {
            error_log('PuntoVentaController::esMeseroAsignable - ' . $e->getMessage());
            return false;
        }

        return $filas !== [];
    }

    // POST /admin/api/open-ticket
    public static function abrirTicket(Router $router) {
        $data = self::entradaJson();
        $data['mesero_id'] = self::meseroDelTicket($data);
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $reservacionId = (int)($data['reservacion_id'] ?? 0);
        $resultado = $reservacionId > 0
            ? PuntoVentaReservacionService::comenzar(
                $reservacionId,
                (int)($_SESSION['id'] ?? 0),
                $data['mesero_id']
            )
            : PuntoVentaReservacionService::abrirWalkIn(
                $data,
                (int)($_SESSION['id'] ?? 0)
            );
        if (($resultado['ok'] ?? false) && isset($resultado['ticket_id'])) {
            // La apertura de ticket es una mutación confirmada. El código OK
            // genérico conserva commit=false para respuestas informativas;
            // esta ruta expone el éxito específico de ticket.
            $resultado['codigo'] = 'TICKET_CREADO';
            $resultado['id'] = $resultado['ticket_id'];
        }
        if (($resultado['codigo'] ?? '') === 'REQUIERE_CONFIRMACION'
            && is_array($resultado['advertencia'] ?? null)) {
            // La presentación top-level debe usar los mismos hechos que la
            // advertencia anidada, sin volver a calcular la ventana en POS.
            $contextoAdvertencia = (array)($resultado['advertencia']['contexto'] ?? []);
            $resultado['contexto'] = array_intersect_key($contextoAdvertencia, array_flip([
                'hora', 'minutos_restantes', 'hora_objetivo',
            ]));
        }
        self::responder($resultado);
    }

    /**
     * POST /api/impresion/alertas/atender  { ids: [..] } | { todas: true }
     *
     * Cualquier mesero puede marcarlas: la alerta es del piso, no de quien
     * envió la comanda. Devuelve las que siguen pendientes para que la tablet
     * repinte la bandeja sin esperar al siguiente refresco.
     */
    public static function atenderAlertasImpresion(Router $router) {
        $data = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $todas = !empty($data['todas']);
        $ids = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
        if (!$todas && $ids === []) {
            self::errorJson('DATOS_INCOMPLETOS');
            return;
        }

        $atendidas = ImpresionAlertaService::atender(
            $todas ? null : $ids,
            (int)($_SESSION['id'] ?? 0)
        );
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'atendidas' => $atendidas,
            'alertas_impresion' => ImpresionAlertaService::pendientes(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/cancelar-mesa  { ticket_id }
     *
     * Libera una mesa cuyo ticket nunca registró consumo. Borra el ticket en
     * vez de cerrarlo para no dejar cuentas en cero en el corte del día; la
     * regla de "sin consumo" la decide el servicio dentro de la transacción,
     * no el POS.
     */
    public static function cancelarMesa(Router $router) {
        $data = self::entradaJson();
        if (!self::validarCsrfMutacion($data)) {
            return;
        }
        $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
        if (!$ticketId) {
            self::errorJson('TICKET_NO_VALIDO');
            return;
        }

        $resultado = PuntoVentaReservacionService::descartarTicketVacio(
            $ticketId,
            (int)($_SESSION['id'] ?? 0)
        );
        if (($resultado['ok'] ?? false) === true) {
            $resultado['codigo'] = 'MESA_CANCELADA';
            $resultado['id'] = $ticketId;
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
        // En cuenta completa, `propina` es opcional para conservar clientes
        // antiguos: si no llega, el excedente se interpreta como propina.
        $recibido   = isset($data['recibido']) ? round((float)$data['recibido'], 2) : null;
        $propinaSolicitada = array_key_exists('propina', $data)
            ? round((float)$data['propina'], 2)
            : null;

        if ($propinaSolicitada !== null && $propinaSolicitada < 0) {
            self::errorJson('PROPINA_INVALIDA', 422);
            return;
        }

        if (!$ticketId) {
            self::errorJson('TICKET_NO_VALIDO');
            return;
        }

        $ticketExistente = Ticket::consultarSQL(
            "SELECT id, estado FROM tickets WHERE id = {$ticketId} LIMIT 1"
        );
        if (!empty($ticketExistente) && $ticketExistente[0]->estado === 'cerrado') {
            $resultado = PuntoVentaReservacionService::cerrarTicket(
                $ticketId,
                'efectivo',
                0.0,
                [],
                (int)($_SESSION['id'] ?? 0)
            );
            if (($resultado['ok'] ?? false) === true) {
                $resultado['codigo'] = 'TICKET_CERRADO';
                $resultado['id'] = $ticketId;
            }
            self::responder($resultado);
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
            // Cuenta completa: un único método, monto recibido y propina
            // explícita. El excedente que no se deja como propina es cambio.
            if (!in_array($metodoPago, $allowedMetodos, true)) {
                self::errorJson('METODO_PAGO_INVALIDO');
                return;
            }

            // Sin monto recibido se asume que se entregó exactamente total +
            // propina. Esto permite el cierre con tarjeta sin inventar cambio.
            if ($recibido === null) {
                $recibido = round($total + (float)($propinaSolicitada ?? 0.0), 2);
            }

            $recibidoCents = (int)round($recibido * 100);
            if ($propinaSolicitada !== null) {
                $propinaCents = (int)round($propinaSolicitada * 100);
                if ($recibidoCents < $totalCents + $propinaCents - 1) {
                    self::errorJson('PAGO_INSUFICIENTE', 422, [
                        'total' => $total,
                        'propina' => $propinaSolicitada,
                        'recibido' => $recibido,
                    ]);
                    return;
                }
                $propina = $propinaCents / 100;
            } elseif ($recibidoCents < $totalCents - 1) {
                self::errorJson('PAGO_INSUFICIENTE', 422, [
                    'total' => $total,
                    'recibido' => $recibido,
                ]);
                return;
            } else {
                $propina = round(max(0.0, $recibido - $total), 2);
            }
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
            $cierre['codigo'] = 'TICKET_CERRADO';
            $cierre['id'] = $ticketId;
            $cierre['propina'] = $propina;
            $token = (string)$cierre['token'];
            $cierre['alertas_impresion'] = [];

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
                    "SELECT nombre, variante, precio, cantidad, comensal FROM ticket_items
                     WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'"
                );
                $items = array_map(function ($r) {
                    return [
                        'nombre'   => $r->nombre,
                        'variante' => $r->variante ?? null,
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
                    $cierre['alertas_impresion'] = ImpresionAlertaService::registrar(TicketPrinter::fallos(), [
                        'ticket_id'   => $ticketId,
                        'mesa_nombre' => $ticketRow->mesa,
                        'usuario_id'  => (int)($_SESSION['id'] ?? 0),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('cerrarTicket — impresión de cuenta falló: ' . $e->getMessage());
            }

            $cierre['token'] = $token;
            self::responder($cierre);
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

            // Primera pasada: resolver las VARIANTES de toda la tanda antes de
            // insertar nada. Una opción inválida (la tablet tiene el catálogo de
            // antes de un cambio en el panel, o falta elegir el término) rechaza
            // la comanda entera: insertar la mitad dejaría en cocina un pedido
            // que el mesero cree no haber enviado.
            $variantesPorItem = [];
            foreach ($items as $i => $item) {
                $nombreRaw = trim((string)($item['nombre'] ?? ''));
                if ($nombreRaw === '') {
                    continue;
                }
                $pedidas = isset($item['variantes']) && is_array($item['variantes']) ? $item['variantes'] : [];
                $producto = Producto::consultarSQL(
                    "SELECT id, precio FROM productos WHERE nombre = '" . Producto::escaparString($nombreRaw) . "' LIMIT 1"
                );
                $producto = $producto ? array_shift($producto) : null;
                if (!$producto) {
                    continue;
                }
                $resuelta = Variantes::resolver((int) $producto->id, $pedidas);
                if (!$resuelta['ok']) {
                    self::errorJson('VARIANTE_INVALIDA', 409, [
                        'contexto' => ['nombre' => $nombreRaw, 'motivo' => $resuelta['error']],
                    ]);
                    return;
                }
                if ($resuelta['ids'] !== []) {
                    // El precio de una opción lo decide el servidor, nunca la
                    // tablet: base del catálogo + lo que sumen las opciones.
                    $variantesPorItem[$i] = $resuelta + ['precio' => round((float) $producto->precio + $resuelta['delta'], 2)];
                }
            }

            $count = 0;
            // Acumulamos SOLO los items insertados en esta tanda, para imprimir
            // únicamente la comanda nueva (no reimprimir las de envíos anteriores
            // en un re-envío del mismo ticket). Guardamos los valores SIN escapar:
            // el escape es sólo para SQL, no para el papel.
            $itemsComanda = [];
            foreach ($items as $i => $item) {
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

                $variante = $variantesPorItem[$i] ?? null;
                if ($variante !== null) {
                    $precio = $variante['precio'];
                }

                if (!$nombre || $precio <= 0) continue;

                $comensalSql = $comensal !== null ? $comensal : 'NULL';
                $notaSql     = $nota !== null ? "'" . $nota . "'" : 'NULL';
                $varIdsSql   = $variante !== null ? "'" . implode(',', $variante['ids']) . "'" : 'NULL';
                $varEtiqueta = $variante['etiqueta'] ?? null;
                $varSql      = $varEtiqueta !== null ? "'" . TicketItem::escaparString($varEtiqueta) . "'" : 'NULL';
                TicketItem::ejecutarSQL(
                    "INSERT INTO ticket_items
                     (ticket_id, nombre, precio, categoria, area_id, comensal, cantidad, nota, variante_ids, variante, estado)
                     VALUES ({$ticketId}, '{$nombre}', {$precio}, '{$categoria}',
                             {$areaId}, {$comensalSql}, {$cantidad}, {$notaSql}, {$varIdsSql}, {$varSql}, 'enviado')"
                );
                $count++;

                // Descuenta el inventario según la receta del producto (por nombre)
                // más los ajustes de las opciones elegidas. Nunca bloquea el
                // pedido: si no hay receta o falla, se ignora.
                $itemId = (int) TicketItem::getDB()->insert_id;
                Inventario::aplicarVenta($nombreRaw, $cantidad, $itemId ?: null, $variante['ids'] ?? []);

                $itemsComanda[] = [
                    'nombre'   => $nombreRaw,
                    'variante' => $varEtiqueta,
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
            $alertasImpresion = [];
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
                    $alertasImpresion = ImpresionAlertaService::registrar(TicketPrinter::fallos(), [
                        'ticket_id'   => $ticketId,
                        'mesa_nombre' => $meta->mesa_nombre ?? null,
                        'usuario_id'  => (int)($_SESSION['id'] ?? 0),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('enviarComanda — impresión falló: ' . $e->getMessage());
                $printOk = false;
            }

            // `alertas_impresion` viaja en la respuesta para que quien envió
            // se entere en ese mismo instante; el resto de tablets la recibe
            // en su siguiente refresco del mapa.
            echo json_encode([
                'ok' => true,
                'count' => $count,
                'print_ok' => $printOk,
                'alertas_impresion' => $alertasImpresion,
            ], JSON_UNESCAPED_UNICODE);
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

    // POST /api/entregar-item  { item_id: X }
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
            // Entregable desde cualquier estado vivo, no sólo desde 'listo': si el
            // plato ya salió y el tablero de área va un paso atrás, esperar a que
            // alguien lo avance retrasa al mesero. Sólo cuentan tickets abiertos,
            // para no reescribir la historia de una cuenta ya cobrada.
            TicketItem::ejecutarSQL(
                "UPDATE ticket_items ti
                 JOIN tickets t ON t.id = ti.ticket_id
                   AND " . TicketMesa::condicionSqlAbierto('t') . "
                 SET ti.estado = 'entregado'
                 WHERE ti.id = {$itemId}
                   AND ti.estado IN ('enviado','en_preparacion','listo')"
            );

            // Antes respondía ok aunque el UPDATE no tocara nada, y la fila se
            // quedaba igual sin explicación.
            if (TicketItem::getDB()->affected_rows < 1) {
                self::errorJson('ITEM_NO_ENTREGABLE');
                return;
            }

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
                    'variante'    => $r->variante ?? null,
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
        self::responder(PuntoVentaReservacionService::listar(
            $fecha,
            Auth::puede('reservations') ? 'admin' : 'waiter'
        ));
    }

    /** GET /api/punto-de-venta/mesa-contexto?mesa_id=N */
    public static function mesaContexto(Router $router): void
    {
        self::responder(PuntoVentaReservacionService::contextoMesa(
            (int)($_GET['mesa_id'] ?? 0),
            Auth::puede('reservations') ? 'admin' : 'waiter'
        ));
    }

    /** POST /api/punto-de-venta/reservaciones/comenzar */
    public static function comenzarReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
        $resultado = PuntoVentaReservacionService::comenzar(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            !empty($datos['mesero_id']) ? (int)$datos['mesero_id'] : null
        );
        if (($resultado['ok'] ?? false) && isset($resultado['ticket_id'])) {
            $resultado['codigo'] = 'TICKET_CREADO';
            $resultado['id'] = $resultado['ticket_id'];
        }
        self::responder($resultado);
    }

    /** POST /api/punto-de-venta/reservaciones/cancelar */
    public static function cancelarReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
        $resultado = PuntoVentaReservacionService::cancelar(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            trim((string)($datos['motivo'] ?? ''))
        );
        if (($resultado['ok'] ?? false) === true) {
            $resultado['codigo'] = 'CANCELADA';
        }
        self::responder($resultado);
    }

    /** POST /api/punto-de-venta/reservaciones/no-show */
    public static function noShowReservacion(Router $router): void
    {
        $datos = self::entradaJson();
        if (!self::validarCsrfMutacion($datos)) {
            return;
        }
        $resultado = PuntoVentaReservacionService::noShow(
            (int)($datos['reservacion_id'] ?? 0),
            (int)($_SESSION['id'] ?? 0),
            !empty($datos['override']),
            Auth::puede('reservations', Permisos::EDITAR),
            trim((string)($datos['motivo'] ?? ''))
        );
        if (($resultado['ok'] ?? false) === true) {
            $resultado['codigo'] = 'NO_SHOW';
        }
        self::responder($resultado);
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
        if (!Auth::puede('reservations')) {
            $resultado = PosReservacionSerializer::sanitizarParaWaiter($resultado);
        }
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
     * GET /api/corte-caja[?fecha=YYYY-MM-DD&turno=N|dia]
     * Corte de un turno de Configuración → POS o del día entero (tickets
     * cerrados en la ventana): ventas, propinas, métodos de pago, los cinco
     * más pedidos y ventas por área. Sólo lectura: no persiste ningún arqueo.
     * Sin parámetros abre el turno en curso (Services\Pos\CorteCaja).
     */
    public static function corteCaja(Router $router) {
        header('Content-Type: application/json');
        \Classes\Auth::liberarSesion();

        try {
            $turnos = ConfiguracionPos::turnosCaja();
            $ahora = new \DateTimeImmutable();
            $vigente = CorteCaja::turnoVigente($turnos, $ahora);

            $fecha = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : '';
            $fechaValida = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
                $fecha = $vigente['fecha'];
            }
            $pedido = $_GET['turno'] ?? null;
            if ($pedido === null) {
                $indice = $vigente['indice'];
            } else {
                // 'dia' (o cualquier otra cosa) es el día completo.
                $indice = is_string($pedido) && ctype_digit($pedido) && isset($turnos[(int) $pedido]) ? (int) $pedido : null;
            }

            $turno = $indice !== null ? $turnos[$indice] : null;
            $ventana = CorteCaja::ventana($fecha, $turno);

            echo json_encode(array_merge([
                'ok'      => true,
                'fecha'   => $fecha,
                'turnos'  => $turnos,
                'turno'   => $indice,
                // Las cifras del turno en curso todavía se mueven: el modal lo dice.
                'en_curso' => $ahora->format('Y-m-d H:i:s') >= $ventana['desde']
                    && $ahora->format('Y-m-d H:i:s') < $ventana['hasta'],
                'ventana' => $ventana,
            ], CorteCaja::resumen($ventana['desde'], $ventana['hasta'])), JSON_UNESCAPED_UNICODE);
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
