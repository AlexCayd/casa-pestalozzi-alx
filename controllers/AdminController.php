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
        'map' => [
            'title' => 'Mapa',
            'path' => '/admin/mapa'
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
        self::render('analytics', [
            'activeModule' => 'analytics',
            'title' => 'Análisis de datos',
            'topbarSection' => 'Análisis',
            'compactTopbar' => true,
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

        self::render('feedback/index', [
            'activeModule' => 'feedback',
            'title' => 'Feedback de clientes',
            'feedbackRows' => $rows,
            'feedbackStats' => $stats,
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
                "SELECT t.id, t.nombre, t.comensales, t.estado, t.metodo_pago, t.hora_apertura,
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
