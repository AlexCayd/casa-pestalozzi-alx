<?php

namespace Controllers;

use MVC\Router;
use Services\Configuration\AreasProduccion;
use Services\Security\ContextoNegocio;

class AdminAreaController
{
    private const AREA_CSS = '/build/css/admin/area.css';
    // Sólo lo cargaba el tablero del panel, comentado abajo (show()).
    // private const AREA_JS = '/build/js/admin/area.js';

    // Nombre, etiqueta y color de cada estación salen de areas_produccion
    // (Services\Configuration\AreasProduccion), que se edita en Configuración →
    // Colores de las áreas y sólo admite los diez de la paleta funcional. Antes
    // vivían aquí escritos a mano: cambiar el color en la base no le llegaba a
    // este tablero, y el del piso llevaba otros cuatro distintos.

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
        $areas=self::areasActuales();
        AdminController::render('area/index', [
            'activeModule' => 'area',
            'title' => 'Producción',
            'topbarTitle' => 'Producción',
            'areas' => self::conInk($areas),
            'styles' => [self::AREA_CSS],
            'scripts' => [],
        ]);
    }

    // /admin/area/<slug> ya no pinta un tablero propio: lleva al KDS de piso,
    // que es el que trabaja la estación. Se conservan para que un enlace o una
    // pestaña vieja no acabe en 404.
    public static function cafe(Router $router): void
    {
        self::alTablero('cafe');
    }

    public static function jugos(Router $router): void
    {
        self::alTablero('jugos');
    }

    public static function cocina(Router $router): void
    {
        self::alTablero('cocina');
    }

    public static function horno(Router $router): void
    {
        self::alTablero('horno');
    }

    /** El KDS de piso valida permiso y estación (AreaController::renderArea). */
    private static function alTablero(string $slug): void
    {
        self::redirect('/area/' . $slug);
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

    /*
     * Tablero KDS dentro del panel — COMENTADO.
     *
     * Era un segundo KDS (tres columnas con el sidebar alrededor) con su propio
     * marcado (views/admin/area/show.php) y su propio JS (src/js/admin/area/
     * area.js) para lo mismo que hace el de piso. Producción enlazaba aquí y no
     * al tablero que usa la estación. Se conserva para poder reactivarlo: basta
     * con descomentar esto y AREA_JS y volver a llamar a show() desde las
     * cuatro rutas de arriba. Las APIs /admin/api/* siguen registradas.
     *
    private static function show(string $slug): void
    {
        $areas = self::conInk(self::areasActuales());
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
     */

    /** Áreas dinámicas en el Restaurante nuevo; IDs fijos solo en el esquema legado. */
    private static function areasActuales(): array
    {
        // En el esquema legado, las de la base (colores de Configuración → Colores
        // de las áreas), con respaldo si la base no responde.
        if (!ContextoNegocio::nuevo()) return AreasProduccion::todas();
        ContextoNegocio::exigir('area','operar','restaurante');
        $db=ContextoNegocio::db(); $unidad=ContextoNegocio::unidad('restaurante');
        $stmt=$db->prepare('SELECT id,nombre,slug,color FROM areas_produccion WHERE unidad_id=? ORDER BY orden,id');
        $stmt->bind_param('i',$unidad); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(\MYSQLI_ASSOC); $stmt->close();
        $areas=[];
        foreach($rows as $row) {
            $slug=(string)$row['slug'];
            if(!preg_match('/^[a-z0-9-]{1,20}$/D',$slug)) continue;
            $nombre=(string)$row['nombre'];
            $color=(string)$row['color'];
            if(!preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/',$color)) $color='#8b8b8b';
            // Al KDS de piso, como en el esquema legado (AreasProduccion::todas()).
            $areas[$slug]=['id'=>(int)$row['id'],'nombre'=>$nombre,'label'=>$nombre,'path'=>'/area/'.$slug,'color'=>$color];
        }
        return $areas;
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
