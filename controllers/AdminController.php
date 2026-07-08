<?php
/**
 * Controlador principal del panel de administración.
 * Define los módulos, vistas y recursos exclusivos del área admin.
 */

namespace Controllers;

use MVC\Router;

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
            'styles' => ['/build/css/admin/menu.css'],
            'scripts' => []
        ]);
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
}
