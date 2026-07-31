<?php
/**
 * Controlador principal del panel de administración.
 * Define los módulos, vistas y recursos exclusivos del área admin.
 */

namespace Controllers;

use MVC\Router;
use Services\Analiticas;
use Services\AreasMejora;

class AdminController
{
    public const MODULES = [
        'analytics' => [
            'title' => 'Análisis de datos',
            'path' => '/admin/analytics'
        ],
        'menu' => [
            'title' => 'Gestión de menú',
            'path' => '/admin/menu'
        ],
        'productos' => [
            'title' => 'Productos y recetas',
            'path' => '/admin/productos'
        ],
        'inventario' => [
            'title' => 'Inventario',
            'path' => '/admin/inventario'
        ],
        'finanzas' => [
            'title' => 'Finanzas',
            'path' => '/admin/finanzas'
        ],
        'pdv' => [
            'title' => 'Punto de Venta',
            'path' => '/admin/punto-de-venta'
        ],
        'area' => [
            'title' => 'Producción',
            'path' => '/admin/area'
        ],
        'reservations' => [
            'title' => 'Reservaciones',
            'path' => '/admin/reservations'
        ],
        'feedback' => [
            'title' => 'Feedback de clientes',
            'path' => '/admin/feedback'
        ],
        'tickets' => [
            'title' => 'Tickets',
            'path' => '/admin/tickets'
        ],
        'printers' => [
            'title' => 'Estaciones de impresión',
            'path' => '/admin/printers'
        ],
        'users' => [
            'title' => 'Usuarios',
            'path' => '/admin/usuarios'
        ],
        'configuration' => [
            'title' => 'Configuración',
            'path' => '/admin/configuracion'
        ],
    ];

    public static function index(Router $router): void
    {
        // Fase 1: /admin usa analytics como pantalla inicial del shell interno.
        self::analytics($router);
    }

    public static function analytics(Router $router): void
    {
        // Dashboard con datos reales de la BD. Se arma el objeto que el front
        // (analytics-page.js / charts.js) consume como window.AdminAnalyticsMock:
        // { metrics, tickets, payments, charts }.
        $rango = self::rangoAnalytics();
        $analytics = self::construirAnalytics($rango);

        // Analíticas diagnósticas de Nivel 1 (ANALITICAS.md §3): ingeniería de
        // menú, RevPASH, varianza de inventario y reglas de asociación.
        $analytics['nivel1'] = Analiticas::nivel1($rango['start'], $rango['end']);

        self::render('analytics', [
            'activeModule' => 'analytics',
            'title' => 'Análisis de datos',
            'topbarSection' => 'Análisis',
            'compactTopbar' => true,
            'analytics' => $analytics,
            'rango' => $rango,
            'styles' => [
                '/build/css/admin/analytics.css'
            ],
            'scripts' => [
                '/build/js/vendor/chart.umd.min.js',
                '/build/js/admin/analytics.js'
            ]
        ]);
    }

    /**
     * Resuelve el rango de fechas del dashboard desde $_GET.
     * Acepta ?rango=N (3/7/30/60/365) o ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD.
     * Devuelve ['start','end','preset','label'].
     */
    private static function rangoAnalytics(): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = trim((string) ($_GET['desde'] ?? ''));
        $hasta = trim((string) ($_GET['hasta'] ?? ''));

        // Rango personalizado válido.
        if (preg_match($re, $desde) && preg_match($re, $hasta) && $desde <= $hasta) {
            return [
                'start' => $desde,
                'end' => $hasta,
                'preset' => 0,
                'label' => 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            ];
        }

        // Preset por número de días.
        $permitidos = [3, 7, 30, 60, 365];
        $preset = (int) ($_GET['rango'] ?? 30);
        if (!in_array($preset, $permitidos, true)) {
            $preset = 30;
        }
        $etiquetas = [3 => 'Últimos 3 días', 7 => 'Últimos 7 días', 30 => 'Últimos 30 días',
            60 => 'Últimos 60 días', 365 => 'Último año'];

        return [
            'start' => date('Y-m-d', strtotime('-' . ($preset - 1) . ' days')),
            'end' => date('Y-m-d'),
            'preset' => $preset,
            'label' => $etiquetas[$preset],
        ];
    }

    /** Construye el dataset real del dashboard desde la BD, filtrado por rango. */
    private static function construirAnalytics(array $rango): array
    {
        $money = static fn($n): string => '$' . number_format((float) $n, 0);
        $metrics = [];
        $ticketsTabla = [];
        $pagosTabla = [];
        $charts = [
            'salesByDay' => ['labels' => [], 'values' => []],
            'salesByCategory' => ['labels' => [], 'values' => []],
            'paymentMethods' => ['labels' => ['Efectivo', 'Tarjeta', 'Dividido'], 'values' => [0, 0, 0]],
            'topProducts' => ['labels' => [], 'values' => []],
            'reservationsByDay' => ['labels' => [], 'values' => []],
            'reservationSources' => ['labels' => [], 'values' => []],
        ];

        // Límites de fecha (validados en rangoAnalytics; seguros de interpolar).
        $start = $rango['start'];
        $end = $rango['end'];
        $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";
        $fRes = "fecha >= '{$start}' AND fecha <= '{$end}'";

        try {
            $db = \Model\Ticket::getDB();

            // ── Agregados de tickets cerrados (en el rango) ────────────
            $ventas = 0.0; $numTickets = 0; $comensales = 0;
            $res = $db->query(
                "SELECT COUNT(*) AS n,
                        COALESCE(SUM(t.comensales), 0) AS comensales,
                        COALESCE(SUM(sub.total), 0) AS ventas
                   FROM tickets t
                   LEFT JOIN (SELECT ticket_id, SUM(precio * cantidad) AS total
                                FROM ticket_items WHERE estado <> 'cancelado'
                               GROUP BY ticket_id) sub ON sub.ticket_id = t.id
                  WHERE t.estado = 'cerrado' {$fTk}"
            );
            if ($res && ($r = $res->fetch_assoc())) {
                $numTickets = (int) $r['n'];
                $comensales = (int) $r['comensales'];
                $ventas = (float) $r['ventas'];
            }

            $propTotal = 0.0; $propTickets = 0;
            $res = $db->query(
                "SELECT COALESCE(SUM(propina), 0) AS total,
                        SUM(CASE WHEN propina > 0 THEN 1 ELSE 0 END) AS con
                   FROM tickets t WHERE t.estado = 'cerrado' {$fTk}"
            );
            if ($res && ($r = $res->fetch_assoc())) {
                $propTotal = (float) $r['total'];
                $propTickets = (int) $r['con'];
            }

            $unidades = 0;
            $res = $db->query(
                "SELECT COALESCE(SUM(ti.cantidad), 0) AS u
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}"
            );
            if ($res && ($r = $res->fetch_assoc())) {
                $unidades = (int) $r['u'];
            }

            $numReservas = 0;
            $res = $db->query("SELECT COUNT(*) AS n FROM reservaciones WHERE {$fRes}");
            if ($res && ($r = $res->fetch_assoc())) {
                $numReservas = (int) $r['n'];
            }

            $ticketProm = $numTickets > 0 ? $ventas / $numTickets : 0.0;
            $propProm = $propTickets > 0 ? $propTotal / $propTickets : 0.0;

            $metrics = [
                ['label' => 'Ventas acumuladas', 'value' => $money($ventas), 'detail' => $numTickets . ' tickets cerrados', 'featured' => true],
                ['label' => 'Propinas', 'value' => $money($propTotal), 'detail' => $propTickets ? ('prom. ' . $money($propProm)) : 'Sin propinas'],
                ['label' => 'Ticket promedio', 'value' => $money($ticketProm), 'detail' => 'Por cuenta cerrada'],
                ['label' => 'Comensales atendidos', 'value' => number_format($comensales), 'detail' => 'En tickets cerrados'],
                ['label' => 'Platillos vendidos', 'value' => number_format($unidades), 'detail' => 'Unidades', 'priority' => 'secondary'],
                ['label' => 'Reservaciones', 'value' => number_format($numReservas), 'detail' => 'Registradas', 'priority' => 'secondary'],
            ];

            // ── Ventas por día (buckets del rango) ─────────────────────
            $dias = [];
            $cursor = strtotime($start);
            $limite = strtotime($end);
            while ($cursor <= $limite) {
                $dias[date('Y-m-d', $cursor)] = 0.0;
                $cursor = strtotime('+1 day', $cursor);
            }
            $res = $db->query(
                "SELECT DATE(t.hora_apertura) AS d, SUM(ti.precio * ti.cantidad) AS total
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY d"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    if (isset($dias[$r['d']])) {
                        $dias[$r['d']] = (float) $r['total'];
                    }
                }
            }
            foreach ($dias as $d => $v) {
                $charts['salesByDay']['labels'][] = date('d/m', strtotime($d));
                $charts['salesByDay']['values'][] = round($v, 2);
            }

            // ── Ventas por categoría ───────────────────────────────────
            $res = $db->query(
                "SELECT ti.categoria AS c, SUM(ti.precio * ti.cantidad) AS total
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY ti.categoria ORDER BY total DESC LIMIT 6"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $charts['salesByCategory']['labels'][] = $r['c'];
                    $charts['salesByCategory']['values'][] = round((float) $r['total'], 2);
                }
            }

            // ── Métodos de pago (conteo de tickets) ────────────────────
            $res = $db->query(
                "SELECT t.metodo_pago, COUNT(*) AS n FROM tickets t
                  WHERE t.estado = 'cerrado' {$fTk} GROUP BY t.metodo_pago"
            );
            if ($res) {
                $mapMet = ['efectivo' => 0, 'tarjeta' => 1, 'dividido' => 2];
                while ($r = $res->fetch_assoc()) {
                    if (isset($mapMet[$r['metodo_pago']])) {
                        $charts['paymentMethods']['values'][$mapMet[$r['metodo_pago']]] = (int) $r['n'];
                    }
                }
            }

            // ── Productos más vendidos ─────────────────────────────────
            $res = $db->query(
                "SELECT ti.nombre, SUM(ti.cantidad) AS u
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY ti.nombre ORDER BY u DESC LIMIT 7"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $charts['topProducts']['labels'][] = $r['nombre'];
                    $charts['topProducts']['values'][] = (int) $r['u'];
                }
            }

            // ── Reservaciones por día (buckets del rango) ──────────────
            $diasR = [];
            $cursorR = strtotime($start);
            while ($cursorR <= $limite) {
                $diasR[date('Y-m-d', $cursorR)] = 0;
                $cursorR = strtotime('+1 day', $cursorR);
            }
            $res = $db->query(
                "SELECT fecha AS d, COUNT(*) AS n FROM reservaciones
                  WHERE {$fRes} GROUP BY fecha"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    if (isset($diasR[$r['d']])) {
                        $diasR[$r['d']] = (int) $r['n'];
                    }
                }
            }
            foreach ($diasR as $d => $v) {
                $charts['reservationsByDay']['labels'][] = date('d/m', strtotime($d));
                $charts['reservationsByDay']['values'][] = $v;
            }

            // ── Reservaciones por estado ───────────────────────────────
            $res = $db->query(
                "SELECT estado, COUNT(*) AS n FROM reservaciones
                  WHERE {$fRes} GROUP BY estado ORDER BY n DESC"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $charts['reservationSources']['labels'][] = ucfirst($r['estado']);
                    $charts['reservationSources']['values'][] = (int) $r['n'];
                }
            }

            // ── Tabla de actividad reciente (últimos tickets CERRADOS) ─
            $estadoMap = ['cerrado' => 'closed', 'abierto' => 'open', 'cancelado' => 'cancelled'];
            $metodoMap = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'dividido' => 'Dividido'];
            $res = $db->query(
                "SELECT t.id, t.estado, t.metodo_pago, t.hora_apertura,
                        COALESCE(sub.total, 0) AS total
                   FROM tickets t
                   LEFT JOIN (SELECT ticket_id, SUM(precio * cantidad) AS total
                                FROM ticket_items WHERE estado <> 'cancelado'
                               GROUP BY ticket_id) sub ON sub.ticket_id = t.id
                  WHERE t.estado = 'cerrado'
                  ORDER BY t.hora_apertura DESC, t.id DESC LIMIT 12"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $folio = 'T-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT);
                    $ticketsTabla[] = [
                        'folio' => $folio,
                        'created_at' => $r['hora_apertura'],
                        'status' => $estadoMap[$r['estado']] ?? $r['estado'],
                        'total' => (float) $r['total'],
                    ];
                    $pagosTabla[] = [
                        'folio' => $folio,
                        'metodo' => $r['estado'] === 'cerrado' ? ($metodoMap[$r['metodo_pago']] ?? '—') : 'Pendiente',
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('AdminController::construirAnalytics - ' . $e->getMessage());
        }

        return [
            'metrics' => $metrics,
            'tickets' => $ticketsTabla,
            'payments' => $pagosTabla,
            'charts' => $charts,
        ];
    }

    public static function reservations(Router $router): void
    {
        self::placeholder('reservations');
    }

    public static function feedback(Router $router): void
    {
        $rows = [];
        $stats = ['total' => 0, 'sabor' => null, 'atencion' => null, 'espera' => null, 'global' => null];

        try {
            $result = \Model\Ticket::ejecutarSQL(
                "SELECT calidad_sabor, atencion_mesero, tiempo_espera, experiencia_global, comentario, created_at
                   FROM feedback
                  ORDER BY created_at DESC
                  LIMIT 100"
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }

            $agg = \Model\Ticket::ejecutarSQL(
                "SELECT COUNT(*) AS total,
                        AVG(calidad_sabor) AS sabor,
                        AVG(atencion_mesero) AS atencion,
                        AVG(tiempo_espera) AS espera,
                        AVG(experiencia_global) AS global_exp
                   FROM feedback"
            );

            if ($agg && ($r = $agg->fetch_assoc())) {
                $stats = [
                    'total' => (int) $r['total'],
                    'sabor' => $r['sabor'] !== null ? round((float) $r['sabor'], 1) : null,
                    'atencion' => $r['atencion'] !== null ? round((float) $r['atencion'], 1) : null,
                    'espera' => $r['espera'] !== null ? round((float) $r['espera'], 1) : null,
                    'global' => $r['global_exp'] !== null ? round((float) $r['global_exp'], 1) : null,
                ];
            }
        } catch (\Throwable $e) {
            // La tabla feedback puede no existir todavía: se muestra el estado vacío.
        }

        // Rendimiento de meseros: atención promedio (del feedback de sus tickets)
        // y % de propina promedio (propina / total de la cuenta). Las dos métricas
        // se calculan en subconsultas separadas para no multiplicar filas al unir
        // feedback (varias reseñas por ticket) con los tickets.
        $meseros = [];
        try {
            $mes = \Model\Ticket::ejecutarSQL(
                "SELECT u.id, u.nombre, u.activo,
                        fb.atencion, fb.n_resenas,
                        tp.tip_pct, tp.n_tickets
                   FROM usuarios u
                   LEFT JOIN (
                        SELECT t.mesero_id, AVG(f.atencion_mesero) AS atencion, COUNT(*) AS n_resenas
                          FROM feedback f
                          JOIN tickets t ON t.id = f.ticket_id
                         WHERE t.mesero_id IS NOT NULL
                         GROUP BY t.mesero_id
                   ) fb ON fb.mesero_id = u.id
                   LEFT JOIN (
                        SELECT t.mesero_id,
                               AVG(t.propina / NULLIF(tot.total, 0)) * 100 AS tip_pct,
                               COUNT(*) AS n_tickets
                          FROM tickets t
                          JOIN (SELECT ticket_id, SUM(precio * cantidad) AS total
                                  FROM ticket_items WHERE estado <> 'cancelado'
                                 GROUP BY ticket_id) tot ON tot.ticket_id = t.id
                         WHERE t.estado = 'cerrado' AND t.mesero_id IS NOT NULL
                         GROUP BY t.mesero_id
                   ) tp ON tp.mesero_id = u.id
                  WHERE u.rol = 'waiter'
                  ORDER BY u.activo DESC, tp.tip_pct DESC, u.nombre ASC"
            );
            if ($mes) {
                while ($row = $mes->fetch_assoc()) {
                    $atencion = $row['atencion'] !== null ? round((float) $row['atencion'], 1) : null;
                    $tipPct   = $row['tip_pct']  !== null ? round((float) $row['tip_pct'], 1)  : null;
                    // Rendimiento combinado 0-100: atención (0-5 -> 0-70) + propina
                    // (0-20% -> 0-30), topado a 100. Solo si hay ambos datos.
                    $rendimiento = null;
                    if ($atencion !== null || $tipPct !== null) {
                        $rendimiento = (int) round(
                            min(70, ($atencion ?? 0) / 5 * 70) +
                            min(30, ($tipPct ?? 0) / 20 * 30)
                        );
                    }
                    $meseros[] = [
                        'nombre'      => $row['nombre'],
                        'activo'      => (int) $row['activo'],
                        'atencion'    => $atencion,
                        'resenas'     => (int) ($row['n_resenas'] ?? 0),
                        'tip_pct'     => $tipPct,
                        'tickets'     => (int) ($row['n_tickets'] ?? 0),
                        'rendimiento' => $rendimiento,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Sin datos de meseros: la sección se muestra vacía.
        }

        self::render('feedback/index', [
            'activeModule' => 'feedback',
            'title' => 'Feedback de clientes',
            'feedbackRows' => $rows,
            'feedbackStats' => $stats,
            'meseros' => $meseros,
            'acciones' => AreasMejora::leer(),
            'accionesActualizadas' => AreasMejora::generadoEn(),
            'n8nConfigurado' => AreasMejora::webhookUrl() !== '',
            'flujoResultado' => $_GET['flujo'] ?? null,
            'styles' => ['/build/css/admin/menu.css'],
            'scripts' => []
        ]);
    }

    /**
     * Dispara el flujo de n8n desde el panel (boton en /admin/feedback).
     * Se invoca por fetch/AJAX y responde JSON. El webhook de n8n responde de
     * inmediato ("Workflow was started"); el flujo continua async y hace POST
     * de vuelta a /api/feedback-n8n con las nuevas areas, que el front detecta
     * mediante polling a feedbackAreas().
     */
    public static function feedbackRefresh(Router $router): void
    {
        header('Content-Type: application/json');

        $webhook = AreasMejora::webhookUrl();
        if ($webhook === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'estado' => 'sin_config',
                'msg' => 'La actualización automática aún no está configurada. Contacta al equipo técnico.']);
            return;
        }

        // El webhook de n8n suele registrarse como GET; se intenta GET y, si el
        // metodo no esta registrado (404/405), se reintenta con POST.
        [$codigo, $error] = self::llamarWebhook($webhook, 'GET');
        if ($codigo === 404 || $codigo === 405) {
            [$codigo, $error] = self::llamarWebhook($webhook, 'POST');
        }

        if ($codigo >= 200 && $codigo < 300) {
            echo json_encode(['ok' => true, 'estado' => 'ok']);
            return;
        }

        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'estado' => 'error',
            'msg' => 'No pudimos conectar con el servicio de análisis. Inténtalo de nuevo en unos minutos.',
        ]);
    }

    /** Ejecuta la peticion al webhook y devuelve [codigoHttp, textoError]. */
    private static function llamarWebhook(string $url, string $metodo): array
    {
        $ch = curl_init($url);
        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ];
        if ($metodo === 'POST') {
            $opciones[CURLOPT_POSTFIELDS] = json_encode(['origen' => 'admin']);
            $opciones[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opciones);
        curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        // curl_close() es un no-op y esta deprecado desde PHP 8.5; omitirlo
        // evita que el warning contamine el cuerpo JSON de la respuesta.

        return [$codigo, $error];
    }

    /**
     * Devuelve en JSON las areas de mejora actuales y su fecha de generacion.
     * Lo consume el polling del front tras disparar el flujo, para refrescar
     * las tarjetas sin recargar la pagina.
     */
    public static function feedbackAreas(Router $router): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'generado_en' => AreasMejora::generadoEn(),
            'acciones'    => AreasMejora::leer(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function tickets(Router $router): void
    {
        $rows = [];
        $stats = ['total' => 0, 'abiertos' => 0, 'cerrados' => 0, 'cancelados' => 0, 'ventas' => 0.0];

        try {
            $result = \Model\Ticket::ejecutarSQL(
                "SELECT t.id, t.nombre, t.comensales, t.estado, t.metodo_pago, t.propina, t.hora_apertura,
                        m.numero AS mesa_numero, m.nombre AS mesa_nombre,
                        COALESCE(SUM(ti.precio * ti.cantidad), 0) AS total,
                        COUNT(ti.id) AS num_items
                   FROM tickets t
                   LEFT JOIN mesas m ON m.id = t.mesa_id
                   LEFT JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
                  GROUP BY t.id
                  ORDER BY t.hora_apertura DESC
                  LIMIT 100"
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                    $stats['total']++;
                    $estado = $row['estado'] ?? 'abierto';

                    if ($estado === 'abierto') {
                        $stats['abiertos']++;
                    } elseif ($estado === 'cerrado') {
                        $stats['cerrados']++;
                        $stats['ventas'] += (float) $row['total'];
                    } elseif ($estado === 'cancelado') {
                        $stats['cancelados']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // La tabla tickets puede no existir todavía: se muestra el estado vacío.
        }

        self::render('tickets/index', [
            'activeModule' => 'tickets',
            'title' => 'Tickets',
            'ticketRows' => $rows,
            'ticketStats' => $stats,
            'styles' => ['/build/css/admin/menu.css'],
            'scripts' => []
        ]);
    }

    public static function printers(Router $router): void
    {
        self::placeholder('printers');
    }

    private static function placeholder(string $module): void
    {
        $moduleData = self::MODULES[$module];

        self::render('dashboard', [
            'activeModule' => $module,
            'title' => $moduleData['title'],
            'placeholderTitle' => $moduleData['title'],
            'styles' => [],
            'scripts' => []
        ]);
    }

    public static function render(string $view, array $data = []): void
    {
        $modules = self::MODULES;
        $styles = [];
        $scripts = [];

        foreach ($data as $key => $value) {
            $$key = $value;
        }

        ob_start();
        include_once __DIR__ . "/../views/admin/{$view}.php";
        $content = ob_get_clean();

        include_once __DIR__ . '/../views/admin/layout.php';
    }

    /**
     * Las rutas administrativas pueden reutilizar la misma consulta y devolver
     * solo el fragmento de resultados cuando el cliente lo solicita.
     */
    public static function isPartialRequest(): bool
    {
        return strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
    }

    public static function renderPartial(string $view, array $data = []): void
    {
        foreach ($data as $key => $value) {
            $$key = $value;
        }

        header('Content-Type: text/html; charset=utf-8');
        if (!empty($data['partialUrl'])) {
            header('X-Filter-URL: ' . (string)$data['partialUrl']);
        }
        include __DIR__ . "/../views/admin/{$view}.php";
    }

    public static function filterUrl(string $path, array $params): string
    {
        $params = array_filter($params, static fn($value): bool => (string)$value !== '');
        $query = http_build_query($params);

        return $path . ($query !== '' ? '?' . $query : '');
    }
}
