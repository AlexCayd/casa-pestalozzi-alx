<?php
namespace Model;

class CategoriasMenu extends ActiveRecord {

    protected static $tabla = 'categorias';
    protected static $columnasDB = ['id', 'nombre', 'carta', 'img', 'activo'];

    /**
     * Las dos piezas impresas de la casa.
     *
     * `carta` es un ENUM en base, así que la lista se escribe aquí una sola vez
     * y de ella salen las pastillas del panel, la validación y el filtro de
     * Services\Menu\Carta. Agregar una tercera pieza es tocar el ENUM del DDL y
     * este arreglo — nada más.
     */
    public const CARTA_COMIDA   = 'comida';
    public const CARTA_MARIDAJE = 'maridaje';

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
    ];

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
     * Con $carta se acota a una sola pieza. Sin argumento devuelve las dos,
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
