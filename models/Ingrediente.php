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

    /** Catálogo maestro: en Restaurante sólo se lee para asociar artículos; nunca se escribe. */
    public static function find($id) {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::find($id);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
        $s=\Services\Security\ContextoNegocio::db()->prepare('SELECT id,nombre,unidad,costo,activo FROM ingredientes WHERE id=?');
        $s->execute([(int)$id]); $r=$s->get_result()->fetch_assoc();
        return $r ? static::crearObjeto($r) : null;
    }

    public static function todos(): array
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
            return self::consultarSQL("SELECT id,nombre,unidad,costo,activo FROM ingredientes ORDER BY activo DESC,nombre ASC");
        }
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, nombre ASC"
        );
    }

    public static function all() { return \Services\Security\ContextoNegocio::nuevo() ? self::todos() : parent::all(); }
    public static function total($columna='', $valor='') {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::total($columna,$valor);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
        return (int)self::getDB()->query('SELECT COUNT(*) FROM ingredientes')->fetch_row()[0];
    }

    public function guardar() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::guardar();
        throw new \Services\Inventory\InventarioError('CATALOGO_GLOBAL','El catálogo maestro solo se edita desde el ámbito Global.');
    }

    public function crear() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::crear(); throw new \Services\Inventory\InventarioError('CATALOGO_GLOBAL','El catálogo maestro solo se edita desde Global.'); }
    public function actualizar() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::actualizar(); throw new \Services\Inventory\InventarioError('CATALOGO_GLOBAL','El catálogo maestro solo se edita desde Global.'); }

    public function eliminar() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::eliminar();
        throw new \Services\Inventory\InventarioError('CATALOGO_GLOBAL','Los artículos compartidos no se eliminan desde Restaurante.');
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
