<?php
namespace Model;

class Subreceta extends ActiveRecord {
    protected static $tabla = 'subrecetas';
    protected static $columnasDB = ['id', 'nombre', 'unidad', 'rendimiento', 'activo'];

    public $id;
    public $nombre;
    public $unidad = 'g';
    public $rendimiento = 1;
    public $activo = 1;
    public $created_at;
    public $updated_at;

    public static function todas(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, nombre ASC"
        );
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre de la subreceta es obligatorio');
        }
        if (!in_array($this->unidad, Ingrediente::UNIDADES, true)) {
            static::setAlerta('error', 'La unidad no es válida');
        }
        if (!is_numeric($this->rendimiento) || (float) $this->rendimiento <= 0) {
            static::setAlerta('error', 'El rendimiento debe ser mayor a cero');
        }

        return static::$alertas;
    }
}
