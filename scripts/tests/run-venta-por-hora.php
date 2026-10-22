<?php

declare(strict_types=1);

/*
 * Contrato de la venta por hora (Analiticas §3.5): la aritmética de
 * agregarPorHora(), las fechas del panel y su endpoint con permiso.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Analytics\Analiticas;
use Services\Security\Permisos;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};

// Un mismo platillo puede llegar en dos renglones (la consulta agrupa por
// hora y nombre, pero la prueba no debe depender de eso).
$filas = Analiticas::agregarPorHora([
    ['h' => 21, 'nombre' => 'Limonada', 'u' => 6],
    ['h' => 9, 'nombre' => 'Chilaquiles', 'u' => 8],
    ['h' => 9, 'nombre' => 'Café', 'u' => 12],
    ['h' => 9, 'nombre' => 'Molletes', 'u' => 3],
    ['h' => 9, 'nombre' => 'Jugo verde', 'u' => 2],
    ['h' => 21, 'nombre' => 'Café', 'u' => 2],
    ['h' => 21, 'nombre' => 'Limonada', 'u' => 1],
]);

$assert(Analiticas::agregarPorHora([]) === [], 'Sin ventas no hay filas.');
$assert(array_column($filas, 'hora') === ['09:00', '21:00'], 'Una fila por hora con venta, en orden.');
$assert($filas[0]['top'] === [['n' => 'Café', 'u' => 12], ['n' => 'Chilaquiles', 'u' => 8], ['n' => 'Molletes', 'u' => 3]],
    'El top son los tres que más salieron, de más a menos.');
$assert($filas[0]['total'] === 25, '«Total» suma todos los platillos de la hora, no sólo los tres.');
$assert($filas[1]['top'][0] === ['n' => 'Limonada', 'u' => 7], 'Los renglones del mismo platillo se suman.');
$assert(Analiticas::ventasPorHora("2026-01-01' OR 1=1 --", '2026-01-31') === [], 'Una fecha mal formada no llega al SQL.');

// La vista: dos fechas, sin filtros de canal ni día, y sin mapas.
$vista = (string) file_get_contents($root . '/views/admin/analytics.php');
$panel = substr($vista, (int) strpos($vista, 'data-n1-horas>'), 3000);
$assert(substr_count($panel, 'type="date"') === 2 && str_contains($panel, 'data-n1-horas-desde') && str_contains($panel, 'data-n1-horas-hasta'),
    'El panel elige fechas con dos entradas de tipo date.');
$assert(!str_contains($vista, 'data-n1-horas-canal') && !str_contains($vista, 'data-n1-horas-dia') && !str_contains($vista, 'data-n1-horas-mapa'),
    'Sin filtro de canal ni de día, y sin mapas.');

// El endpoint existe y lo abre quien ve Análisis.
$rutas = (string) file_get_contents($root . '/public/index.php');
$assert(str_contains($rutas, "'/admin/api/analytics/venta-por-hora'"), 'La ruta del endpoint está registrada.');
$rol = static fn (array $permisos): array => ['sistema' => false, 'permisos' => $permisos];
$assert(Permisos::decidir('/admin/api/analytics/venta-por-hora', 'GET', $rol(['analytics' => 'ver']))['resultado'] === 'permitir',
    'Ver Análisis basta para pedir la tabla.');
$assert(Permisos::decidir('/admin/api/analytics/venta-por-hora', 'GET', $rol(['menu' => 'editar']))['resultado'] === 'negar',
    'Sin Análisis no se puede.');

fwrite(STDOUT, "Venta por hora: {$assertions} comprobaciones OK\n");
