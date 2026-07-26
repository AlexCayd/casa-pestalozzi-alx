<?php
namespace Model;

class Ingrediente extends ActiveRecord {
    protected static $tabla = 'ingredientes';
    protected static $columnasDB = ['id', 'nombre', 'unidad', 'stock', 'stock_minimo', 'costo', 'activo'];

    public const UNIDADES = ['g', 'kg', 'ml', 'l', 'pza'];

    public $id;
    public $nombre;
    public $unidad = 'g';
    public $stock = 0;
    public $stock_minimo = 0;
    public $costo = 0;
    public $activo = 1;
    public $created_at;
    public $updated_at;

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, nombre ASC"
        );
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre del ingrediente es obligatorio');
        }
        if (!in_array($this->unidad, self::UNIDADES, true)) {
            static::setAlerta('error', 'La unidad no es válida');
        }
        if (!is_numeric($this->stock)) {
            static::setAlerta('error', 'El stock debe ser un número');
        }
        if (!is_numeric($this->costo) || (float) $this->costo < 0) {
            static::setAlerta('error', 'El costo debe ser un número válido');
        }
        if (!is_numeric($this->stock_minimo) || (float) $this->stock_minimo < 0) {
            static::setAlerta('error', 'El stock mínimo debe ser un número válido');
        }

        return static::$alertas;
    }
}
