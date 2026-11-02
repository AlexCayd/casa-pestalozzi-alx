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
    public $unidad_id;

    public static function all() {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::all();
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
        $s=self::getDB()->prepare('SELECT DISTINCT ip.* FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN almacen_articulos aa ON aa.articulo_id=ip.ingrediente_id AND aa.unidad_id=ip.unidad_id WHERE ip.unidad_id=? ORDER BY ip.id DESC');
        $s->execute([$u]); return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public static function find($id) {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::find($id);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
        $s=self::getDB()->prepare('SELECT ip.* FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN almacen_articulos aa ON aa.articulo_id=ip.ingrediente_id AND aa.unidad_id=ip.unidad_id WHERE ip.id=? AND ip.unidad_id=? LIMIT 1');
        $s->execute([(int)$id,$u]); $r=$s->get_result()->fetch_assoc(); return $r?static::crearObjeto($r):null;
    }

    public function guardar() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::guardar(); throw new \Services\Inventory\InventarioError('OPERACION_REQUERIDA','Las asignaciones se guardan mediante el servicio de proveedores.'); }
    public function crear() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::crear(); throw new \Services\Inventory\InventarioError('OPERACION_REQUERIDA','Las asignaciones se guardan mediante el servicio de proveedores.'); }
    public function actualizar() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::actualizar(); throw new \Services\Inventory\InventarioError('OPERACION_REQUERIDA','Las asignaciones se guardan mediante el servicio de proveedores.'); }
    public function eliminar() { if(!\Services\Security\ContextoNegocio::nuevo()) return parent::eliminar(); throw new \Services\Inventory\InventarioError('OPERACION_REQUERIDA','Las asignaciones se actualizan mediante el servicio de proveedores.'); }

    /**
     * Proveedores de un ingrediente con su precio, el preferente primero y
     * luego del más barato al más caro: el orden en el que se decide a quién
     * comprarle.
     */
    public static function porIngrediente(int $ingredienteId): array
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
            $u=\Services\Security\ContextoNegocio::unidad('restaurante');
            $sql='SELECT DISTINCT ip.*,p.nombre AS proveedor_nombre,p.activo AS proveedor_activo FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN almacen_articulos aa ON aa.articulo_id=ip.ingrediente_id AND aa.unidad_id=ip.unidad_id JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE ip.unidad_id=? AND ip.ingrediente_id=? AND p.activo=1 ORDER BY ip.preferente DESC,ip.costo ASC,p.nombre ASC';
            $s=self::getDB()->prepare($sql); $s->execute([$u,$ingredienteId]);
            return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
        }
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
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
            $s=self::getDB()->prepare('SELECT DISTINCT ip.*,i.nombre AS ingrediente_nombre,i.unidad AS ingrediente_unidad,i.costo AS ingrediente_costo FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN ingredientes i ON i.id=ip.ingrediente_id JOIN almacen_articulos aa ON aa.articulo_id=i.id AND aa.unidad_id=ip.unidad_id JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE ip.unidad_id=? AND ip.proveedor_id=? ORDER BY i.nombre ASC');
            $s->execute([$u,$proveedorId]); return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
        }
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
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
            $s=self::getDB()->prepare('SELECT ip.proveedor_id,COUNT(DISTINCT ip.ingrediente_id) AS total FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN almacen_articulos aa ON aa.articulo_id=ip.ingrediente_id AND aa.unidad_id=ip.unidad_id JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE ip.unidad_id=? GROUP BY ip.proveedor_id');
            $s->execute([$u]); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $conteo=[]; foreach($rows as $r)$conteo[(int)$r['proveedor_id']]=(int)$r['total']; return $conteo;
        }
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
        if (\Services\Security\ContextoNegocio::nuevo()) return self::porIngrediente($ingredienteId)[0]??null;
        $fila = self::consultarSQL(
            "SELECT * FROM " . static::$tabla .
            " WHERE ingrediente_id = " . $ingredienteId . " AND preferente = 1 LIMIT 1"
        )[0] ?? null;

        return $fila instanceof self ? $fila : null;
    }
}
