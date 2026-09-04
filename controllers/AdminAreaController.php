<?php

namespace Controllers;

use MVC\Router;

class AdminAreaController
{
    private const AREA_CSS = '/build/css/admin/area.css';
    private const AREA_JS = '/build/js/admin/area.js';

    // Colores alineados con areas_produccion (BD) y CP_AREAS (POS): son la
    // fuente de verdad del color de cada área, para que tabs, tarjetas y la
    // línea vertical del tablero coincidan.
    //
    // Los cuatro salen de la paleta funcional de CLAUDE.md, que está validada
    // para daltonismo. Antes eran hex de fantasía (café, mostaza, ladrillo,
    // azul marino) que no existían en ningún otro sitio del sistema.
    //
    // Tres conservan la mnemotecnia —jugos amarillo, cocina rojo, horno azul
    // profundo— y el café pasa a turquesa: la paleta no tiene un marrón, y de
    // los tonos que quedaban libres el turquesa es el que más se separa de los
    // otros tres bajo deuteranopia (ámbar y rojo comparten hue percibido, así
    // que la cuarta área tenía que irse al lado frío, y frente al índigo del
    // horno se distingue por luminosidad).
    private const AREAS = [
        'cafe' => [
            'id' => 1,
            'nombre' => 'Barra de Café',
            'label' => 'Café',
            'path' => '/area/cafe',
            'color' => '#46bdc6', // --c-turquesa
        ],
        'jugos' => [
            'id' => 2,
            'nombre' => 'Barra de Jugos',
            'label' => 'Jugos',
            'path' => '/area/jugos',
            'color' => '#f5b400', // --c-ambar
        ],
        'cocina' => [
            'id' => 3,
            'nombre' => 'Cocina',
            'label' => 'Cocina',
            'path' => '/area/cocina',
            'color' => '#e51022', // --c-rojo
        ],
        'horno' => [
            'id' => 4,
            'nombre' => 'Horno Napolitano',
            'label' => 'Horno',
            'path' => '/area/horno',
            'color' => '#4267ac', // --c-indigo
        ],
    ];

    /**
     * Color de texto (tinta) que contrasta con un color de fondo. Cada área
     * conserva su color distintivo, pero el texto encima usa negro o blanco
     * según la luminancia, para que todos los botones lean igual de bien.
     */
    private static function contrastInk(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminancia = 0.299 * $r + 0.587 * $g + 0.114 * $b; // 0-255 percibida
        return $luminancia > 150 ? '#0b0c0d' : '#f5f3f0';
    }

    /** Agrega a cada área su tinta de contraste ('ink'). */
    private static function conInk(array $areas): array
    {
        foreach ($areas as $slug => $a) {
            $areas[$slug]['ink'] = self::contrastInk($a['color']);
        }
        return $areas;
    }

    public static function index(Router $router): void
    {
        AdminController::render('area/index', [
            'activeModule' => 'area',
            'title' => 'Producción',
            'topbarTitle' => 'Producción',
            'areas' => self::conInk(self::AREAS),
            'styles' => [self::AREA_CSS],
            'scripts' => [],
        ]);
    }

    public static function cafe(Router $router): void
    {
        self::show('cafe');
    }

    public static function jugos(Router $router): void
    {
        self::show('jugos');
    }

    public static function cocina(Router $router): void
    {
        self::show('cocina');
    }

    public static function horno(Router $router): void
    {
        self::show('horno');
    }

    public static function areaItems(Router $router): void
    {
        AreaController::areaItems($router);
    }

    public static function advanceItem(Router $router): void
    {
        AreaController::avanzarItem($router);
    }

    public static function rollbackItem(Router $router): void
    {
        AreaController::retrocederItem($router);
    }

    private static function show(string $slug): void
    {
        $areas = self::conInk(self::AREAS);
        $area  = $areas[$slug] ?? null;

        if (!$area) {
            self::redirect('/admin/area');
        }

        AdminController::render('area/show', [
            'activeModule' => 'area',
            'title' => $area['nombre'],
            'topbarTitle' => $area['nombre'],
            'area' => $area,
            'activeArea' => $slug,
            'areas' => $areas,
            'styles' => [self::AREA_CSS],
            'scripts' => [self::AREA_JS],
        ]);
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
