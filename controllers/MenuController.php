<?php

namespace Controllers;

use Model\CategoriasMenu;
use Model\Menu;

class MenuController {
    public static function index($router) {
        header('Content-Type: application/json');

        $categorias = CategoriasMenu::consultarSQL(
            "SELECT * FROM categorias WHERE activo = 1 ORDER BY id"
        );

        $resultado = [];
        foreach ($categorias as $cat) {
            $platillos = Menu::consultarSQL(
                "SELECT * FROM menu WHERE categoria_id = {$cat->id} AND activo = 1 ORDER BY id"
            );

            $dishes = array_map(function($m) {
                return [
                    'n'    => $m->nombre,
                    'd'    => $m->descripcion,
                    'p'    => (float) $m->precio,
                    'tags' => $m->tag ? [$m->tag] : []
                ];
            }, $platillos);

            $resultado[] = [
                'id'     => (int) $cat->id,
                'label'  => $cat->nombre,
                'img'    => $cat->img,
                'dishes' => $dishes
            ];
        }

        echo json_encode($resultado);
        exit;
    }
}