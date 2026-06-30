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
            'title' => 'Mapa / Mesas',
            'path' => '/admin/map'
        ],
        'area' => [
            'title' => 'Produccion',
            'path' => '/admin/area'
        ],
        'reservations' => [
            'title' => 'Reservaciones',
            'path' => '/admin/reservations'
        ],
        'tables' => [
            'title' => 'Mesas',
            'path' => '/admin/tables'
        ],
        'products' => [
            'title' => 'Productos',
            'path' => '/admin/products'
        ],
        'categories' => [
            'title' => 'Categorías',
            'path' => '/admin/categories'
        ],
        'tickets' => [
            'title' => 'Tickets / Ventas',
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
            'path' => '/admin/users'
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

    public static function tables(Router $router): void
    {
        self::placeholder('tables');
    }

    public static function products(Router $router): void
    {
        self::placeholder('products');
    }

    public static function categories(Router $router): void
    {
        self::placeholder('categories');
    }

    public static function tickets(Router $router): void
    {
        self::placeholder('tickets');
    }

    public static function payments(Router $router): void
    {
        self::placeholder('payments');
    }

    public static function printers(Router $router): void
    {
        self::placeholder('printers');
    }

    public static function users(Router $router): void
    {
        self::placeholder('users');
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
