<?php

namespace Controllers;

use MVC\Router;

/**
 * Sitio de la Panadería: portada y subpáginas.
 *
 * Por ahora es un andamio: todas las rutas pintan la misma página de prueba
 * para comprobar que el ruteo llega. No cuelgan de /admin ni de /api, así que
 * Auth::proteger las deja públicas sin anotarlas en Permisos.
 *
 * No pasa por Router::render(): views/layout.php es legado (DevWebCamp,
 * Google Fonts, Leaflet). La vista es un documento suelto, como la 404.
 */
class PanaderiaController
{
    /**
     * Ruta → título. Es también el índice que la página de prueba enlaza, así
     * que una subruta nueva se anota aquí y en public/index.php.
     */
    public const SECCIONES = [
        '/panaderia'          => 'Inicio',
        '/panaderia/carta'    => 'Carta',
        '/panaderia/pedidos'  => 'Pedidos',
        '/panaderia/nosotros' => 'Nosotros',
        '/panaderia/contacto' => 'Contacto',
    ];

    public static function index(Router $router): void
    {
        self::pagina('/panaderia');
    }

    public static function carta(Router $router): void
    {
        self::pagina('/panaderia/carta');
    }

    public static function pedidos(Router $router): void
    {
        self::pagina('/panaderia/pedidos');
    }

    public static function nosotros(Router $router): void
    {
        self::pagina('/panaderia/nosotros');
    }

    public static function contacto(Router $router): void
    {
        self::pagina('/panaderia/contacto');
    }

    private static function pagina(string $ruta): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $rutaActual = $ruta;
        $titulo = self::SECCIONES[$ruta];
        $secciones = self::SECCIONES;

        include __DIR__ . '/../views/panaderia/index.php';
    }
}
