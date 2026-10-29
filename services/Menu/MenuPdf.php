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

        self::emitir($pieza,$categorias,$nombreArchivo);
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
            self::emitir($datos['pieza'],$datos['categorias']);
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

    private static function emitir(array $pieza,array $categorias,?string $nombreArchivo=null): void
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
        $pdfTitulo = pathinfo($pieza['archivo'], PATHINFO_FILENAME);

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

        // 'Attachment' => false => se abre en el navegador; true => fuerza descarga.
        $dompdf->stream($nombreArchivo ?: $pieza['archivo'], ['Attachment' => false]);
        exit;
    }
}
