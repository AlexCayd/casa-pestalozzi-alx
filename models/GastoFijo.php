<?php
namespace Model;

class GastoFijo extends ActiveRecord {
    protected static $tabla = 'gastos_fijos';
    protected static $columnasDB = ['id', 'nombre', 'categoria', 'monto', 'activo'];

    public const CATEGORIAS = ['renta', 'servicios', 'nomina', 'insumos', 'otros'];

    public $id;
    public $nombre;
    public $categoria = 'otros';
    public $monto = 0;
    public $activo = 1;
    public $created_at;
    public $updated_at;

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, monto DESC"
        );
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre del gasto es obligatorio');
        }
        if (!in_array($this->categoria, self::CATEGORIAS, true)) {
            static::setAlerta('error', 'La categoría no es válida');
        }
        if (!is_numeric($this->monto) || (float) $this->monto < 0) {
            static::setAlerta('error', 'El monto debe ser un número válido');
        }

        return static::$alertas;
    }
}
