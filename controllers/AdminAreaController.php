<?php

namespace Controllers;

use MVC\Router;

class AdminAreaController
{
    private const AREA_CSS = '/build/css/admin/area.css';
    private const AREA_JS = '/build/js/admin/area.js';

    private const AREAS = [
        'cafe' => [
            'id' => 1,
            'nombre' => 'Barra de Cafe',
            'label' => 'Cafe',
            'path' => '/admin/area/cafe',
            'color' => '#7b5e3a',
        ],
        'jugos' => [
            'id' => 2,
            'nombre' => 'Barra de Jugos',
            'label' => 'Jugos',
            'path' => '/admin/area/jugos',
            'color' => '#d28a31',
        ],
        'cocina' => [
            'id' => 3,
            'nombre' => 'Cocina',
            'label' => 'Cocina',
            'path' => '/admin/area/cocina',
            'color' => '#5f7d56',
        ],
        'horno' => [
            'id' => 4,
            'nombre' => 'Horno Napolitano',
            'label' => 'Horno',
            'path' => '/admin/area/horno',
            'color' => '#214b4b',
        ],
    ];

    public static function index(Router $router): void
    {
        AdminController::render('area/index', [
            'activeModule' => 'area',
            'title' => 'Produccion',
            'topbarTitle' => 'Produccion',
            'areas' => self::AREAS,
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
        $area = self::AREAS[$slug] ?? null;

        if (!$area) {
            self::redirect('/admin/area');
        }

        AdminController::render('area/show', [
            'activeModule' => 'area',
            'title' => $area['nombre'],
            'topbarTitle' => $area['nombre'],
            'area' => $area,
            'activeArea' => $slug,
            'areas' => self::AREAS,
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
