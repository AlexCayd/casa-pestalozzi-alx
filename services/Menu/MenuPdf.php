<?php

namespace Services\Menu;

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
            'bajada'   => 'Una mesa italiana con alma mexicana',
            'archivo'  => 'menu-casa-pestalozzi.pdf',
            'vacio'    => 'No hay platillos registrados en el menú.',
            // La despedida al pie de la última sección, en la voz italiana
            // de los rótulos de la landing.
            'cierre'   => 'Buon appetito',
        ],
        CategoriasMenu::CARTA_MARIDAJE => [
            'rotulo'   => 'MARIDAJE',
            'bajada'   => 'Destilados, coctelería de autor y cervezas de la casa',
            'archivo'  => 'maridaje-casa-pestalozzi.pdf',
            'vacio'    => 'No hay bebidas registradas en la carta de maridaje.',
            'cierre'   => 'Salute',
        ],
    ];

    public static function stream(
        string $carta = CategoriasMenu::CARTA_COMIDA,
        ?string $nombreArchivo = null
    ): void {
        if (!isset(self::PIEZAS[$carta])) {
            throw new \Services\Inventory\InventarioError('CARTA_INVALIDA','La carta solicitada no tiene un documento PDF.');
        }
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

        self::emitir($pieza,$categorias,$nombreArchivo,\Services\Configuration\AvisosCarta::paraPdf($carta));
    }

    /** Documento privado: el propietario es una constante de ruta, nunca un parámetro GET. */
    public static function datosAdministrativos(string $contexto, string $carta): array
    {
        \Services\Security\ContextoNegocio::exigir('menu','ver',$contexto);
        $unidad=\Services\Security\ContextoNegocio::vendedora($contexto);
        if (($contexto==='panaderia' && $carta!=='comida') || !isset(self::PIEZAS[$carta])) throw new \Services\Inventory\InventarioError('CARTA_INVALIDA','La carta no tiene un PDF disponible.');
        $pieza=$contexto==='panaderia'?[
            'rotulo'=>'MENÚ DE PANADERÍA','bajada'=>'Panes y dulces de la casa',
            'archivo'=>'menu-panaderia-casa-pestalozzi.pdf','vacio'=>'No hay ofertas disponibles para imprimir.',
            // Faltaba: emitir() la lee y la plantilla caía a su respaldo con
            // un aviso de índice indefinido.
            'cierre'=>'Buon appetito',
        ]:self::PIEZAS[$carta];
        $db=\Model\ActiveRecord::getDB();
        $s=$db->prepare('SELECT slug FROM cartas WHERE unidad_id=? AND slug=? AND activo=1');$s->execute([$unidad,$carta]);
        if (!$s->get_result()->fetch_row()) throw new \Services\Inventory\InventarioError('CARTA_INVALIDA','La carta no existe o está inactiva en este negocio.');
        $s=$db->prepare('SELECT c.id,c.nombre AS categoria,p.nombre,p.descripcion,o.precio FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN cartas ca ON ca.unidad_id=c.unidad_id AND ca.slug=c.carta JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id WHERE o.unidad_id=? AND ca.slug=? AND ca.activo=1 AND c.activo=1 AND o.activo=1 ORDER BY c.orden,c.id,o.orden,o.id');
        $s->execute([$unidad,$carta]);$grupos=[];
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $id=(int)$row['id'];if(!isset($grupos[$id]))$grupos[$id]=['nombre'=>$row['categoria'],'platillos'=>[]];
            $grupos[$id]['platillos'][]=(object)['nombre'=>$row['nombre'],'descripcion'=>$row['descripcion'],'precio'=>$row['precio']];
        }
        if (!$grupos) throw new \Services\Inventory\InventarioError('CARTA_VACIA','No hay ofertas disponibles para imprimir esta carta.');
        return ['pieza'=>$pieza,'categorias'=>array_values($grupos),'contexto'=>$contexto,'carta'=>$carta];
    }

    public static function streamAdministrativo(string $contexto, string $carta): void
    {
        try {
            if (isset($_GET['carta']) && (!is_string($_GET['carta']) || $_GET['carta']!==$carta)) throw new \Services\Inventory\InventarioError('CARTA_INVALIDA','La carta no corresponde al documento solicitado.');
            $datos=self::datosAdministrativos($contexto,$carta);
            header('Cache-Control: private, no-store');
            // Los avisos son del Restaurante (Configuración → Avisos de la
            // carta): la Panadería imprime su carta sin ellos.
            $avisos=$datos['contexto']==='restaurante'?\Services\Configuration\AvisosCarta::paraPdf($datos['carta']):[];
            self::emitir($datos['pieza'],$datos['categorias'],null,$avisos);
        } catch (\Services\Inventory\InventarioError $e) {
            http_response_code(in_array($e->codigo,['CARTA_INVALIDA','CARTA_VACIA','AMBITO_NO_VENDEDOR'],true)?404:403);
            echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
        }
    }

    public static function biblioteca(): array
    {
        $perfil=\Services\Security\ContextoNegocio::exigir('menu','ver','global');$docs=[];
        foreach (self::definiciones() as $doc) {
            if (!\Services\Security\PermisosUsuario::permitido($perfil,$doc['contexto'],'menu','ver')) continue;
            try { self::datosAdministrativos($doc['contexto'],$doc['carta']); }
            catch (\Services\Inventory\InventarioError $e) { if(in_array($e->codigo,['CARTA_VACIA','CARTA_INVALIDA'],true))continue; throw $e; }
            $docs[]=$doc+['titulo'=>($doc['contexto']==='panaderia'?'Panadería':'Restaurante').' · '.$doc['nombre'],'descripcion'=>'Carta del negocio, solo consulta.','icono'=>'menu','accion'=>'Ver PDF'];
        }
        return $docs;
    }

    private static function definiciones(): array
    {
        return [
            ['contexto'=>'restaurante','carta'=>'comida','nombre'=>'Menú principal','ruta'=>'/admin/global/menus/restaurante'],
            ['contexto'=>'restaurante','carta'=>'maridaje','nombre'=>'Maridaje','ruta'=>'/admin/global/menus/maridaje'],
            ['contexto'=>'panaderia','carta'=>'comida','nombre'=>'Menú de Panadería','ruta'=>'/admin/global/menus/panaderia'],
        ];
    }

    private static function emitir(array $pieza,array $categorias,?string $nombreArchivo=null,array $avisos=[]): void
    {
        $dompdf = self::documento($pieza,$categorias,$avisos);

        // 'Attachment' => false => se abre en el navegador; true => fuerza descarga.
        $dompdf->stream($nombreArchivo ?: $pieza['archivo'], ['Attachment' => false]);
        exit;
    }

    /**
     * La carta ya compuesta, sin enviarla: emitir() la transmite y las pruebas
     * la escriben a un archivo para revisarla.
     *
     * `$avisos` es lo que da AvisosCarta::paraPdf(): la lista en orden, cada
     * aviso con su título y su HTML ya escapado. Todos van al final.
     */
    public static function documento(array $pieza,array $categorias,array $avisos=[]): \Dompdf\Dompdf
    {
        // La clase vive en services/Menu; la raíz del proyecto está dos niveles arriba.
        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot === false) {
            throw new \RuntimeException('No se pudo resolver la raíz del proyecto para generar el PDF.');
        }

        // Ruta absoluta (con /) a las fuentes del proyecto para los @font-face.
        $fontsDir = str_replace('\\', '/', $projectRoot . '/public/build/fonts');
        // El logotipo del pie. La plantilla lo reescala y lo tinta, asi que
        // recibe la ruta y no una imagen ya resuelta.
        $logoRuta = str_replace('\\', '/', $projectRoot . '/public/build/images/logo.svg');

        // Textos de la pieza. La plantilla los toma tal cual: no decide nada
        // sobre qué carta está imprimiendo.
        $pdfRotulo = $pieza['rotulo'];
        $pdfBajada = $pieza['bajada'];
        $pdfVacio = $pieza['vacio'];
        $pdfCierre = $pieza['cierre'] ?? 'Buon appetito';
        $pdfTitulo = pathinfo($pieza['archivo'], PATHINFO_FILENAME);
        $pdfAvisos = array_values($avisos);

        ob_start();
        $generado = date('d/m/Y H:i');
        include $projectRoot . '/views/admin/menu/items-pdf.php';
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
        self::folios($dompdf);

        return $dompdf;
    }

    /**
     * El folio de cada hoja («2 / 6»), centrado entre el pie y el marco.
     *
     * No puede ir en la plantilla: el HTML no sabe cuántas hojas saldrán.
     * page_text() lo escribe en todas al final, con los marcadores que Dompdf
     * sustituye por hoja. Si la fuente no se resolviera, la carta sale sin
     * folio: no es motivo para no imprimirla.
     */
    private static function folios(\Dompdf\Dompdf $dompdf): void
    {
        $metricas = $dompdf->getFontMetrics();
        $fuente = $metricas->getFont('Montserrat', 'normal');
        if ($fuente === null) {
            return;
        }

        $canvas = $dompdf->getCanvas();
        $tamano = 6.5;
        $espaciado = 1.2;
        // El ancho se mide sobre una muestra de la misma forma: con hasta dos
        // cifras por lado, el folio no se desplaza más de medio carácter.
        $ancho = $metricas->getTextWidth('0 / 0', $fuente, $tamano, 0.0, $espaciado);
        $x = ($canvas->get_width() - $ancho) / 2;
        // 29pt sobre el canto: por debajo del teléfono del pie y por encima
        // del doble filete del marco (a 13.5pt).
        $y = $canvas->get_height() - 29;

        $canvas->page_text($x, $y, '{PAGE_NUM} / {PAGE_COUNT}', $fuente, $tamano, self::rgb(
            \Services\Configuration\PaletaLanding::paraPdf()['txt_faint']
        ), 0.0, $espaciado);
    }

    /** @return array{0: float, 1: float, 2: float} el color en 0..1, como lo pide el lienzo */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
