<?php

namespace Services;

/**
 * Genera el PDF de la carta con Dompdf.
 *
 * Extraído de AdminMenuController::itemsPdf para que lo compartan la ruta de
 * admin (/admin/menu/pdf) y la pública (/menu/pdf, la que enlaza la landing).
 *
 * De paso queda corregido el orden: la versión anterior armaba los grupos con
 * ActiveRecord::all(), que es ORDER BY id DESC, así que las secciones salían
 * de "Jugos & Smoothies" hacia "Desayunos". Ahora reutiliza Carta::publica(),
 * que ya viene en el orden de la carta.
 */
class MenuPdf
{
    public static function stream(string $nombreArchivo = 'menu-casa-pestalozzi.pdf'): void
    {
        // La plantilla (views/admin/menu/items-pdf.php) recibe los grupos en
        // $categorias: ['nombre' => string, 'platillos' => object[]].
        $categorias = [];

        foreach (Carta::publica() as $categoria) {
            $delGrupo = [];

            foreach ($categoria['dishes'] as $dish) {
                // La plantilla accede por propiedad ($platillo->nombre).
                $obj = (object) [
                    'nombre'      => $dish['n'],
                    'descripcion' => $dish['d'],
                    'precio'      => $dish['p'],
                ];
                $delGrupo[] = $obj;
            }

            if (empty($delGrupo)) {
                continue;
            }

            $categorias[] = [
                'nombre'    => $categoria['label'],
                'platillos' => $delGrupo,
            ];
        }

        // Ruta absoluta (con /) a las fuentes del proyecto para los @font-face.
        $projectRoot = realpath(__DIR__ . '/..');
        $fontsDir = str_replace('\\', '/', $projectRoot . '/public/build/fonts');

        ob_start();
        $generado = date('d/m/Y H:i');
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

        // 'Attachment' => false => se abre en el navegador; true => fuerza descarga.
        $dompdf->stream($nombreArchivo, ['Attachment' => false]);
        exit;
    }
}
