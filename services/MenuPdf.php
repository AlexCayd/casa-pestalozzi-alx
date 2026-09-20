<?php

namespace Services;

use Model\CategoriasMenu;

/**
 * Genera el PDF de una carta con Dompdf.
 *
 * Extraído de AdminMenuController::itemsPdf para que lo compartan la ruta de
 * admin (/admin/menu/pdf) y la pública (/menu/pdf, la que enlaza la landing).
 *
 * De paso queda corregido el orden: la versión anterior armaba los grupos con
 * ActiveRecord::all(), que es ORDER BY id DESC, así que las secciones salían
 * de "Jugos & Smoothies" hacia "Desayunos". Ahora reutiliza Carta::publica(),
 * que ya viene en el orden de la carta.
 *
 * Son DOS piezas impresas con UNA sola plantilla, y es a propósito: la carta de
 * maridaje es la misma casa en el mismo papel, así que lo único que cambia
 * entre ellas es el rótulo de la banda, la bajada y el nombre del archivo. Un
 * segundo layout habría empezado igual y se habría separado del primero al
 * segundo cambio de marca.
 */
class MenuPdf
{
    /**
     * Rótulo, bajada, nombre de archivo y aviso de vacío de cada pieza.
     *
     * El rótulo va en caja alta porque así lo imprime la banda; la bajada es la
     * misma línea que la landing usa para presentar la sección.
     */
    private const PIEZAS = [
        CategoriasMenu::CARTA_COMIDA => [
            'rotulo'   => 'MENÚ',
            'bajada'   => 'Cocina Mediterránea con corazón mexicano',
            'archivo'  => 'menu-casa-pestalozzi.pdf',
            'vacio'    => 'No hay platillos registrados en el menú.',
        ],
        CategoriasMenu::CARTA_MARIDAJE => [
            'rotulo'   => 'MARIDAJE',
            'bajada'   => 'Destilados, coctelería de autor y cervezas de la casa',
            'archivo'  => 'maridaje-casa-pestalozzi.pdf',
            'vacio'    => 'No hay bebidas registradas en la carta de maridaje.',
        ],
    ];

    public static function stream(
        string $carta = CategoriasMenu::CARTA_COMIDA,
        ?string $nombreArchivo = null
    ): void {
        $carta = CategoriasMenu::normalizarCarta($carta);
        $pieza = self::PIEZAS[$carta];

        // La plantilla (views/admin/menu/items-pdf.php) recibe los grupos en
        // $categorias: ['nombre' => string, 'platillos' => object[]].
        $categorias = [];

        foreach (Carta::publica($carta) as $categoria) {
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
        // El logotipo del pie. La plantilla lo reescala y lo tinta, asi que
        // recibe la ruta y no una imagen ya resuelta.
        $logoRuta = str_replace('\\', '/', $projectRoot . '/public/build/images/logo.svg');

        // Textos de la pieza. La plantilla los toma tal cual: no decide nada
        // sobre qué carta está imprimiendo.
        $pdfRotulo = $pieza['rotulo'];
        $pdfBajada = $pieza['bajada'];
        $pdfVacio = $pieza['vacio'];
        $pdfTitulo = pathinfo($pieza['archivo'], PATHINFO_FILENAME);

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
        $dompdf->stream($nombreArchivo ?: $pieza['archivo'], ['Attachment' => false]);
        exit;
    }
}
