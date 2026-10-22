<?php

namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\Security\Contextos;

final class AdminContextosController
{
    public static function cambiar(Router $router): void
    {
        $contexto = $_GET['contexto'] ?? '';
        if (!is_string($contexto) || !in_array($contexto, Auth::contextos(), true)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $destinoContexto = Auth::destinoPorRol();
            require __DIR__ . '/../views/auth/contexto-denegado.php';
            return;
        }
        header('Location: ' . Contextos::inicio($contexto), true, 302);
    }

    public static function navegacion(string $contexto, array $restaurante): array
    {
        if ($contexto === 'restaurante') {
            return $restaurante;
        }
        if ($contexto === 'global') {
            $entradas = ['inicio' => 'Negocios', 'catalogo' => 'Catálogo maestro', 'inventario' => 'Inventario consolidado', 'usuarios' => 'Usuarios', 'configuracion' => 'Configuración general'];
        } else {
            $entradas = ['inicio' => 'Inicio', 'pdv' => 'Punto de venta', 'menu' => 'Menú', 'productos' => 'Recetas', 'inventario' => 'Inventarios', 'transferencias' => 'Transferencias', 'area' => 'Producción'];
        }
        $modulos = [];
        foreach ($entradas as $clave => $titulo) {
            if ($contexto === 'panaderia' && in_array($clave, ['menu', 'productos', 'inventario'], true) && !Auth::puede($clave)) {
                continue;
            }
            $pendiente = $contexto === 'panaderia' && in_array($clave, ['pdv', 'transferencias', 'area'], true);
            $segmento = $clave === 'productos' ? 'recetas' : $clave;
            $modulos[$clave] = ['title' => $titulo, 'path' => '/admin/' . $contexto . ($clave === 'inicio' ? '' : '/' . $segmento), 'pendiente' => $pendiente];
        }
        return $modulos;
    }

    public static function pagina(Router $router): void
    {
        header('Cache-Control: private, no-store');
        $ruta = $_SERVER['PATH_INFO'] ?? '';
        $contexto = Contextos::deRuta($ruta);
        $seccion = basename($ruta);
        $clave = $seccion === $contexto ? 'inicio' : ($seccion === 'recetas' ? 'productos' : $seccion);
        $modulos = self::navegacion($contexto, []);
        $demo = $contexto === 'panaderia' && Contextos::demoHabilitada();
        $fixture = $demo ? require __DIR__ . '/../database/fixtures/panaderia.php' : null;
        AdminController::render('contextos/index', [
            'activeModule' => $clave,
            'title' => $modulos[$clave]['title'] ?? 'Inicio',
            'contexto' => $contexto,
            'seccion' => $clave,
            'demo' => $demo,
            'fixture' => $fixture,
        ]);
    }
}
