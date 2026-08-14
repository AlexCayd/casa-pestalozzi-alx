<?php
namespace Model;

/**
 * Un cambio de precio de venta (productos) o de costo de insumo (ingredientes).
 *
 * Sólo se escribe: nada edita ni borra filas de aquí. Quien registra es
 * Services\HistorialPrecios, que además decide cuándo un guardado es realmente
 * un cambio de precio.
 */
class HistorialPrecio extends ActiveRecord {
    protected static $tabla = 'historial_precios';
    protected static $columnasDB = [
        'id', 'entidad', 'ref_id', 'precio_anterior', 'precio_nuevo',
        'motivo', 'proveedor_id', 'usuario_id',
    ];

    public const ENTIDAD_PRODUCTO = 'producto';
    public const ENTIDAD_INGREDIENTE = 'ingrediente';

    public const ENTIDADES = [self::ENTIDAD_PRODUCTO, self::ENTIDAD_INGREDIENTE];
    public const MOTIVOS = ['alta', 'edicion', 'proveedor'];

    public $id;
    public $entidad;
    public $ref_id;
    public $precio_anterior;
    public $precio_nuevo;
    public $motivo = 'edicion';
    public $proveedor_id;
    public $usuario_id;
    public $created_at;

    // Llegan por JOIN; fuera de $columnasDB para no intentar guardarlas.
    public $usuario_nombre;
    public $proveedor_nombre;

    /**
     * Inserta una fila con sentencia preparada, no con ActiveRecord::crear().
     *
     * crear() envuelve todos los valores entre comillas, así que un NULL entra
     * como cadena vacía: precio_anterior quedaría en 0 —"el precio de antes era
     * cero", que es falso en un alta— y usuario_id en 0, que ni existe en
     * usuarios y hace fallar la llave foránea.
     */
    public static function registrar(array $datos): bool
    {
        $sql = 'INSERT INTO ' . static::$tabla .
            ' (entidad, ref_id, precio_anterior, precio_nuevo, motivo, proveedor_id, usuario_id)
              VALUES (?, ?, ?, ?, ?, ?, ?)';

        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException(self::getDB()->error);
        }

        $entidad = (string) $datos['entidad'];
        $refId = (int) $datos['ref_id'];
        $anterior = $datos['precio_anterior'];
        $nuevo = (float) $datos['precio_nuevo'];
        $motivo = (string) $datos['motivo'];
        $proveedorId = $datos['proveedor_id'];
        $usuarioId = $datos['usuario_id'];

        // s-i-d-d-s-i-i, en el orden de los ? de arriba. 'd' sobre una variable
        // null manda NULL, que es justo lo que se quiere en un alta (sin precio
        // anterior) y sin proveedor ni sesión.
        $stmt->bind_param(
            'siddsii',
            $entidad,
            $refId,
            $anterior,
            $nuevo,
            $motivo,
            $proveedorId,
            $usuarioId
        );

        $ok = $stmt->execute();
        $stmt->close();

        return (bool) $ok;
    }

    /**
     * Últimos cambios de una entidad, del más reciente al más antiguo. El
     * límite lo pone quien llama porque la ficha muestra unos pocos y no
     * tiene sentido traerse años de historia para pintar cinco filas.
     */
    public static function historial(string $entidad, int $refId, int $limite = 20): array
    {
        if (!in_array($entidad, self::ENTIDADES, true)) {
            return [];
        }

        $entidadEscapada = self::escaparString($entidad);
        $limite = max(1, min(200, $limite));

        return self::consultarSQL(
            "SELECT h.*, u.nombre AS usuario_nombre, p.nombre AS proveedor_nombre
               FROM " . static::$tabla . " h
               LEFT JOIN usuarios u ON u.id = h.usuario_id
               LEFT JOIN proveedores p ON p.id = h.proveedor_id
              WHERE h.entidad = '{$entidadEscapada}' AND h.ref_id = " . $refId . "
              ORDER BY h.created_at DESC, h.id DESC
              LIMIT " . $limite
        );
    }
}
