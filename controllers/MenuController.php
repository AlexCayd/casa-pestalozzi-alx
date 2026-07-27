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

            $img = $cat->img ? '/' . ltrim($cat->img, '/') : null;

            $resultado[] = [
                'id'     => (int) $cat->id,
                'label'  => $cat->nombre,
                'img'    => $img,
                'dishes' => $dishes
            ];
        }

        echo json_encode($resultado);
        exit;
    }

    /**
     * GET /menu/pdf — genera el PDF público de la carta al vuelo con Dompdf.
     * Solo incluye platillos activos (los ocultos no se muestran al cliente),
     * agrupados por categoría igual que en la vista del index.
     */
    public static function pdf($router) {
        // Agrupa el menu por categoria (solo categorias y platillos activos).
        // Cada grupo: ['nombre' => ..., 'platillos' => Menu[]].
        $cats = CategoriasMenu::consultarSQL(
            "SELECT * FROM categorias WHERE activo = 1 ORDER BY id ASC"
        );
        $categorias = [];
        foreach ($cats as $cat) {
            $platillos = Menu::consultarSQL(
                "SELECT * FROM menu WHERE categoria_id = {$cat->id} AND activo = 1 ORDER BY id ASC"
            );
            if (!empty($platillos)) {
                $categorias[] = ['nombre' => $cat->nombre, 'platillos' => $platillos];
            }
        }

        // Ruta absoluta (con / ) a las fuentes del proyecto para los @font-face.
        $projectRoot = realpath(__DIR__ . '/..');
        $fontsDir = str_replace('\\', '/', $projectRoot . '/public/build/fonts');

        // Reutiliza la misma plantilla que el PDF del panel de administración.
        ob_start();
        $generado = date('d\m\Y H:i');
        include __DIR__ . '/../views/admin/menu/items-pdf.php';
        $html = ob_get_clean();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Montserrat');
        // Permite a Dompdf leer los .ttf locales referenciados en los @font-face.
        $options->setChroot([$projectRoot]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Attachment => false: se abre en el navegador (nueva pestaña).
        $dompdf->stream('menu-casa-pestalozzi.pdf', ['Attachment' => false]);
        exit;
    }
}