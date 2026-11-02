<?php
namespace Model;

class Subreceta extends ActiveRecord {
    protected static $tabla = 'subrecetas';
    // `stock` NO está aquí a propósito: sólo lo mueven los movimientos
    // (producir, vender, cancelar, ajustar, mermar). Si viajara con las
    // columnas, guardar el formulario reescribiría la existencia con la que se
    // leyó al abrirlo y se comería lo vendido mientras tanto.
    protected static $columnasDB = ['id', 'nombre', 'unidad', 'rendimiento', 'por_lote', 'stock_minimo', 'activo'];

    public $id;
    public $nombre;
    public $unidad = 'g';
    public $rendimiento = 1;
    // Por lote: se produce por adelantado y la venta descuenta la subreceta.
    // Al momento: la venta descuenta sus ingredientes. Ver database/ddl.sql.
    public $por_lote = 1;
    public $stock = 0;
    public $stock_minimo = 0;
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
        if (!is_numeric($this->stock_minimo) || (float) $this->stock_minimo < 0) {
            static::setAlerta('error', 'La existencia mínima debe ser un número igual o mayor a cero');
        }

        return static::$alertas;
    }
}
