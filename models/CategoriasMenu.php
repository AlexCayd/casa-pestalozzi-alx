<?php
namespace Model;

class CategoriasMenu extends ActiveRecord {

    protected static $tabla = 'categorias';
    protected static $columnasDB = ['id', 'nombre', 'carta', 'img', 'activo'];

    /**
     * Dónde se publica cada categoría: las dos piezas impresas de la casa y
     * la vitrina de especiales de la landing.
     *
     * `carta` es un ENUM en base, así que la lista se escribe aquí una sola vez
     * y de ella salen las pastillas del panel, la validación y el filtro de
     * Services\Menu\Carta. Agregar otro destino es tocar el ENUM del DDL y
     * este arreglo — y, si se imprime, CARTAS_IMPRESAS y MenuPdf::PIEZAS.
     */
    public const CARTA_COMIDA    = 'comida';
    public const CARTA_MARIDAJE  = 'maridaje';
    /**
     * Especiales de temporada: no es una pieza impresa sino la vitrina de la
     * landing («Especiales de temporada», views/home/_firma.php). Vive en el
     * mismo ENUM porque responde a la misma pregunta —dónde se publica esta
     * sección— y así los especiales se dan de alta como cualquier platillo,
     * sin módulo propio. Por el filtro de Carta::publica() no entran en la
     * sección de menú de la landing ni en ningún PDF: cambian cada semana; el
     * papel no.
     */
    public const CARTA_TEMPORADA = 'temporada';

    public const CARTAS = [
        self::CARTA_COMIDA => [
            'titulo' => 'Carta de comida',
            'corto'  => 'Comida',
            'ayuda'  => 'Sale en la sección de menú de la landing y en su PDF',
        ],
        self::CARTA_MARIDAJE => [
            'titulo' => 'Carta de maridaje',
            'corto'  => 'Maridaje',
            'ayuda'  => 'Sale sólo en el PDF de maridaje (barra)',
        ],
        self::CARTA_TEMPORADA => [
            'titulo' => 'Especiales de temporada',
            'corto'  => 'Temporada',
            'ayuda'  => 'Sale en «Especiales de temporada» de la landing; no entra en ningún PDF',
        ],
    ];

    /** Las cartas que se imprimen: las que tienen PDF (Services\Menu\MenuPdf). */
    public const CARTAS_IMPRESAS = [self::CARTA_COMIDA, self::CARTA_MARIDAJE];

    public $id;
    public $nombre;
    public $carta = self::CARTA_COMIDA;
    public $img;
    public $activo = 1;

    /**
     * Categorías en el mismo orden en que las ve el comensal.
     *
     * ActiveRecord::all() ordena por id DESC, así que el admin las mostraba al
     * revés que el landing y el PDF, que salen de Carta::publica() con
     * ORDER BY c.id ASC. No hay columna `orden`: el orden es el de alta, y
     * mientras esa siga siendo la regla del negocio ambos lados deben leerlo
     * igual desde aquí.
     *
     * Con $carta se acota a una sola pieza. Sin argumento devuelve todas,
     * que es lo que quiere el panel: se administran juntas.
     */
    public static function ordenadas(?string $carta = null): array {
        $sql = "SELECT * FROM " . static::$tabla;

        if ($carta !== null && isset(self::CARTAS[$carta])) {
            // El valor sale de una lista cerrada, pero se escapa igual: es lo
            // que exige la casa para cualquier literal que llegue de fuera.
            $sql .= " WHERE carta = '" . self::escaparString($carta) . "'";
        }

        return self::consultarSQL($sql . " ORDER BY id ASC");
    }

    /** Normaliza lo que llegue del formulario a un valor del ENUM. */
    public static function normalizarCarta($valor): string {
        $valor = is_string($valor) ? $valor : '';

        return isset(self::CARTAS[$valor]) ? $valor : self::CARTA_COMIDA;
    }

    // Funcion para validaciones de categorias
    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre de la categoría es obligatorio');
        }

        // No se confía en el <input>: un valor fuera del ENUM lo rechaza MySQL
        // con un warning y guarda cadena vacía, así que la categoría dejaría de
        // salir en las dos cartas sin decir por qué.
        if (!isset(self::CARTAS[(string) $this->carta])) {
            static::setAlerta('error', 'Elige a qué carta pertenece la categoría');
        }

        return static::$alertas;
    }
}
