<?php

namespace Controllers;

use Model\Producto;

class MenuController {
    public static function index($router) {
        header('Content-Type: application/json');

        // cartaPublica() ya descarta lo retirado y las categorías sin productos.
        $resultado = [];
        foreach (Producto::cartaPublica() as $categoria) {
            $dishes = array_map(function($p) {
                return [
                    'n'    => $p->nombre,
                    'd'    => $p->descripcion,
                    'p'    => (float) $p->precio,
                    'tags' => $p->tag ? [$p->tag] : []
                ];
            }, $categoria['platillos']);

            $img = $categoria['img'] ? '/' . ltrim($categoria['img'], '/') : null;

            $resultado[] = [
                'id'     => $categoria['id'],
                'label'  => $categoria['nombre'],
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
        // Mismo recorte que la carta web: activo = 1, agrupado por categoría.
        // Cada grupo: ['nombre' => ..., 'platillos' => Producto[]].
        $categorias = Producto::cartaPublica();

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