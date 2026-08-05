<?php
namespace Model;

class CategoriasMenu extends ActiveRecord {

    protected static $tabla = 'categorias';
    protected static $columnasDB = ['id', 'nombre', 'img', 'activo'];

    public $id;
    public $nombre;
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
     */
    public static function ordenadas(): array {
        return self::consultarSQL("SELECT * FROM " . static::$tabla . " ORDER BY id ASC");
    }

    // Funcion para validaciones de categorias
    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre de la categoría es obligatorio');
        }

        return static::$alertas;
    }
}