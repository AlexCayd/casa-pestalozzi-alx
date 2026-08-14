<?php
namespace Model;

/**
 * Precio de compra de un ingrediente con un proveedor concreto.
 *
 * El costo va en la unidad de inventario del ingrediente, la misma que
 * ingredientes.costo: así se compara con el costo vigente sin convertir.
 */
class IngredienteProveedor extends ActiveRecord {
    protected static $tabla = 'ingrediente_proveedores';
    protected static $columnasDB = ['id', 'ingrediente_id', 'proveedor_id', 'costo', 'codigo', 'preferente'];

    public $id;
    public $ingrediente_id;
    public $proveedor_id;
    public $costo = 0;
    public $codigo;
    public $preferente = 0;
    public $created_at;
    public $updated_at;

    /*
     * Columnas que llegan por JOIN o por agregación, fuera de $columnasDB para
     * que no entren en el INSERT/UPDATE. Tienen que estar declaradas: ActiveRecord
     * las descarta al hidratar si la propiedad no existe.
     */
    public $proveedor_nombre;
    public $proveedor_activo;
    public $ingrediente_nombre;
    public $ingrediente_unidad;
    public $ingrediente_costo;
    public $total;

    /**
     * Proveedores de un ingrediente con su precio, el preferente primero y
     * luego del más barato al más caro: el orden en el que se decide a quién
     * comprarle.
     */
    public static function porIngrediente(int $ingredienteId): array
    {
        return self::consultarSQL(
            "SELECT ip.*, p.nombre AS proveedor_nombre, p.activo AS proveedor_activo
               FROM " . static::$tabla . " ip
               JOIN proveedores p ON p.id = ip.proveedor_id
              WHERE ip.ingrediente_id = " . $ingredienteId . "
              ORDER BY ip.preferente DESC, ip.costo ASC, p.nombre ASC"
        );
    }

    /** Ingredientes que surte un proveedor, para su ficha. */
    public static function porProveedor(int $proveedorId): array
    {
        return self::consultarSQL(
            "SELECT ip.*, i.nombre AS ingrediente_nombre, i.unidad AS ingrediente_unidad,
                    i.costo AS ingrediente_costo
               FROM " . static::$tabla . " ip
               JOIN ingredientes i ON i.id = ip.ingrediente_id
              WHERE ip.proveedor_id = " . $proveedorId . "
              ORDER BY i.nombre ASC"
        );
    }

    /** Cuántos ingredientes surte cada proveedor, para el listado. */
    public static function conteoPorProveedor(): array
    {
        $filas = self::consultarSQL(
            "SELECT proveedor_id, COUNT(*) AS total
               FROM " . static::$tabla . "
              GROUP BY proveedor_id"
        );

        $conteo = [];
        foreach ($filas as $fila) {
            $conteo[(int) $fila->proveedor_id] = (int) $fila->total;
        }

        return $conteo;
    }

    public static function preferenteDe(int $ingredienteId): ?self
    {
        $fila = self::consultarSQL(
            "SELECT * FROM " . static::$tabla .
            " WHERE ingrediente_id = " . $ingredienteId . " AND preferente = 1 LIMIT 1"
        )[0] ?? null;

        return $fila instanceof self ? $fila : null;
    }
}
