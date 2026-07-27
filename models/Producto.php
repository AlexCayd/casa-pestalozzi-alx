<?php
namespace Model;

class Producto extends ActiveRecord {
    protected static $tabla = 'productos';
    protected static $columnasDB = ['id', 'nombre', 'categoria_id', 'precio', 'area_id', 'activo'];

    public $id;
    public $nombre;
    public $categoria_id;
    public $precio;
    public $area_id;
    public $activo = 1;

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, categoria_id ASC, nombre ASC"
        );
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre del producto es obligatorio');
        }
        if (!$this->categoria_id || (int) $this->categoria_id < 1) {
            static::setAlerta('error', 'La categoría es obligatoria');
        }
        if ($this->precio === '' || $this->precio === null || !is_numeric($this->precio) || (float) $this->precio < 0) {
            static::setAlerta('error', 'El precio debe ser un número válido');
        }
        if (!$this->area_id) {
            static::setAlerta('error', 'El área de producción es obligatoria');
        }

        return static::$alertas;
    }
}
