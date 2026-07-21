<?php
/**
 * Controlador principal del panel de administración.
 * Define los módulos, vistas y recursos exclusivos del área admin.
 */

namespace Controllers;

use MVC\Router;
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
        'reservations_operation' => [
            'title' => 'Operación de reservaciones',
            'path' => '/admin/reservations/operation'
        ],
        'feedback' => [
            'title' => 'Feedback de clientes',
            'path' => '/admin/feedback'
        ],
        'tickets' => [
            'title' => 'Tickets',
            'path' => '/admin/tickets'
        ],
        'payments' => [
            'title' => 'Pagos',
            'path' => '/admin/payments'
        ],
        'printers' => [
            'title' => 'Estaciones de impresión',
            'path' => '/admin/printers'
        ],
        'users' => [
            'title' => 'Usuarios',
            'path' => '/admin/usuarios'
        ],
    ];

    public static function index(Router $router): void
    {
        // Fase 1: /admin usa analytics como pantalla inicial del shell interno.
        self::analytics($router);
    }

    public static function analytics(Router $router): void
    {
        // Propinas reales de tickets cerrados: la única métrica con respaldo en
        // BD dentro de analytics (el resto son datos mock del front por ahora).
        $propinas = ['total' => 0.0, 'tickets' => 0, 'promedio' => 0.0];
        try {
            $res = \Model\Ticket::ejecutarSQL(
                "SELECT COALESCE(SUM(propina), 0) AS total,
                        SUM(CASE WHEN propina > 0 THEN 1 ELSE 0 END) AS con_propina
                   FROM tickets
                  WHERE estado = 'cerrado'"
            );
            if ($res && ($r = $res->fetch_assoc())) {
                $total   = round((float) $r['total'], 2);
                $tickets = (int) $r['con_propina'];
                $propinas = [
                    'total'    => $total,
                    'tickets'  => $tickets,
                    'promedio' => $tickets > 0 ? round($total / $tickets, 2) : 0.0,
                ];
            }
        } catch (\Throwable $e) {
            // Sin datos: se muestra 0.
        }

        self::render('analytics', [
            'activeModule' => 'analytics',
            'title' => 'Análisis de datos',
            'topbarSection' => 'Análisis',
            'compactTopbar' => true,
            'propinas' => $propinas,
            'styles' => [
                '/build/css/admin/analytics.css'
            ],
            'scripts' => [
                '/build/js/vendor/chart.umd.min.js',
                '/build/js/admin/analytics.js'
            ]
        ]);
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

    public static function payments(Router $router): void
    {
        self::placeholder('payments');
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
}
