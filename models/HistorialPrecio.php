<?php
namespace Model;

use Services\Security\ContextoNegocio;

/**
 * Un cambio de precio de venta (productos) o de costo de insumo (ingredientes).
 *
 * Sólo se escribe: nada edita ni borra filas de aquí. Quien registra es
 * Services\Inventory\HistorialPrecios, que además decide cuándo un guardado es realmente
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
    public static function registrar(array $datos, bool $coordinada = false): bool
    {
        if (ContextoNegocio::nuevo()) return HistorialPrecioMultinegocio::registrar($datos, $coordinada);
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
        if (ContextoNegocio::nuevo()) return HistorialPrecioMultinegocio::historial($entidad, $refId, $limite);
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

/** Branch del esquema 3B/3C; el modelo anterior queda intacto si no está activo. */
class HistorialPrecioMultinegocio extends HistorialPrecio
{
    protected static $tabla = 'historial_precios';
    protected static $columnasDB = ['id','unidad_id','oferta_id','articulo_id','precio_anterior','precio_nuevo','motivo','proveedor_id','usuario_id'];

    public $unidad_id;
    public $oferta_id;
    public $articulo_id;

    public static function registrar(array $datos, bool $coordinada = false): bool
    {
        $unidad=(int)($datos['unidad_id']??0);
        $oferta=$datos['oferta_id']??null;
        $articulo=$datos['articulo_id']??null;
        if ($unidad<1 || (($oferta===null)==($articulo===null))) {
            throw new \Services\Inventory\InventarioError('DATOS_INVALIDOS','El histórico debe pertenecer a una oferta o artículo y a una unidad.');
        }
        if (!is_numeric($datos['precio_nuevo']??null) || (float)$datos['precio_nuevo']<0 || !in_array((string)($datos['motivo']??''),self::MOTIVOS,true)) {
            throw new \Services\Inventory\InventarioError('DATOS_INVALIDOS','El precio y el motivo del histórico no son válidos.');
        }

        $db=self::getDB();
        $resultado=ContextoNegocio::transaccion(function () use ($db,$datos,$unidad,$oferta,$articulo): array {
            $ctx=ContextoNegocio::slug();
            $unidadActual=ContextoNegocio::unidad($ctx);
            if ($unidad!==$unidadActual) {
                throw new \Services\Inventory\InventarioError('AMBITO_DENEGADO','La unidad del histórico no coincide con el contexto autorizado.');
            }
            ContextoNegocio::exigir($oferta!==null?'menu':'inventario','editar',$ctx,true);

            $tabla=$oferta!==null?'ofertas_producto':'ingredientes';
            $sql='SELECT id FROM '.$tabla.' WHERE id=?'.($oferta!==null?' AND unidad_id=?':'').' LIMIT 1 FOR SHARE';
            $stmt=$db->prepare($sql);
            if (!$stmt) throw new \RuntimeException($db->error);
            if ($oferta!==null) { $ref=(int)$oferta; $stmt->bind_param('ii',$ref,$unidad); }
            else { $ref=(int)$articulo; $stmt->bind_param('i',$ref); }
            if (!$stmt->execute() || !$stmt->get_result()->fetch_row()) {
                $stmt->close();
                throw new \Services\Inventory\InventarioError('RECURSO_NO_ENCONTRADO','No se encontró la oferta o artículo dentro del ámbito permitido.');
            }
            $stmt->close();

            $proveedor=$datos['proveedor_id']??null;
            if ($proveedor!==null) {
                $stmt=$db->prepare('SELECT id FROM proveedores WHERE id=? AND unidad_id=? LIMIT 1 FOR SHARE');
                if (!$stmt) throw new \RuntimeException($db->error);
                $proveedor=(int)$proveedor; $stmt->bind_param('ii',$proveedor,$unidad);
                if (!$stmt->execute() || !$stmt->get_result()->fetch_row()) {
                    $stmt->close();
                    throw new \Services\Inventory\InventarioError('AMBITO_DENEGADO','El proveedor no pertenece a la unidad del histórico.');
                }
                $stmt->close();
            }

            $stmt=$db->prepare('INSERT INTO historial_precios (unidad_id,oferta_id,articulo_id,precio_anterior,precio_nuevo,motivo,proveedor_id,usuario_id) VALUES (?,?,?,?,?,?,?,?)');
            if (!$stmt) throw new \RuntimeException($db->error);
            $anterior=$datos['precio_anterior']??null;
            $nuevo=(string)$datos['precio_nuevo'];
            $motivo=(string)$datos['motivo'];
            $usuario=ContextoNegocio::actorId() ?: null;
            $stmt->bind_param('iiisssii',$unidad,$oferta,$articulo,$anterior,$nuevo,$motivo,$proveedor,$usuario);
            if (!$stmt->execute()) { $error=$stmt->error; $stmt->close(); throw new \RuntimeException($error); }
            $stmt->close();
            return ['registrado'=>true];
        });

        // El coordinador comercial publica éxito únicamente después de su commit.
        $confirmada = !empty($resultado['ok']) && !empty($resultado['confirmado']);
        $preparada = $coordinada && !empty($resultado['pendiente']) && ($resultado['ok'] ?? null) === null;
        return ($confirmada || $preparada) && !empty($resultado['registrado']);
    }

    public static function historial(string $entidad,int $refId,int $limite=20): array
    {
        if (!in_array($entidad,self::ENTIDADES,true) || $refId<1) return [];
        $ctx=ContextoNegocio::slug();
        $unidad=ContextoNegocio::unidad($ctx);
        ContextoNegocio::exigir($entidad===self::ENTIDAD_PRODUCTO?'menu':'inventario','ver',$ctx);
        $limite=max(1,min(200,$limite));
        $columna=$entidad===self::ENTIDAD_PRODUCTO?'h.oferta_id':'h.articulo_id';
        return self::consultarSQL("SELECT h.*, '{$entidad}' AS entidad, {$columna} AS ref_id, u.nombre AS usuario_nombre, p.nombre AS proveedor_nombre FROM historial_precios h LEFT JOIN usuarios u ON u.id=h.usuario_id LEFT JOIN proveedores p ON p.id=h.proveedor_id WHERE h.unidad_id={$unidad} AND {$columna}={$refId} ORDER BY h.created_at DESC,h.id DESC LIMIT {$limite}");
    }
}
