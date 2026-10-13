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
        'menu' => [
            'title' => 'Menú',
            'path' => '/admin/menu'
        ],
        // La clave se conserva ('productos') porque _sidebar.php ya mapea ese
        // icono; lo que cambió es el módulo: los datos del platillo se editan
        // en Gestión de menú y aquí solo queda la composición de recetas.
        'productos' => [
            'title' => 'Recetas',
            'path' => '/admin/recetas'
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
        'reservations' => [
            'title' => 'Reservaciones',
            'path' => '/admin/reservaciones'
        ],
        'catas' => [
            'title' => 'Catas',
            'path' => '/admin/catas'
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
        // El panel ya no tiene tablero propio: entra directo al primer módulo.
        header('Location: /admin/menu');
        exit;
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
