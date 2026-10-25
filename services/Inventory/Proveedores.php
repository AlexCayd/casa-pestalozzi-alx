<?php

namespace Services\Inventory;

use Model\ActiveRecord;
use Model\IngredienteProveedor;
use Model\Proveedor;

/**
 * Alta, baja y asignación de proveedores a ingredientes.
 *
 * La regla que justifica que esto sea un servicio y no cuatro llamadas sueltas
 * al modelo es la del preferente: marcar uno tiene que desmarcar al anterior,
 * y eso son dos escrituras que no pueden quedarse a medias.
 */
final class Proveedores
{
    /**
     * Reemplaza la lista de proveedores de un ingrediente con la que llega del
     * formulario.
     *
     * Se sustituye en bloque en vez de ir fila a fila porque el formulario
     * manda el estado completo: quitar una fila allí tiene que borrarla aquí, y
     * comparar altas, bajas y ediciones por separado era más código para el
     * mismo resultado.
     *
     * $filas: [['proveedor_id' => int, 'costo' => float, 'codigo' => ?string,
     *           'preferente' => bool], …]
     */
    public static function asignarAIngrediente(int $ingredienteId, array $filas): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) return self::asignarNuevo($ingredienteId,$filas);
        if ($ingredienteId < 1) {
            return false;
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $db->query(
                'DELETE FROM ingrediente_proveedores WHERE ingrediente_id = ' . $ingredienteId
            );

            $stmt = $db->prepare(
                'INSERT INTO ingrediente_proveedores
                    (ingrediente_id, proveedor_id, costo, codigo, preferente)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            // Un solo preferente: el primero que venga marcado. El resto se
            // guarda igual, pero sin la marca.
            $yaHayPreferente = false;
            $vistos = [];

            foreach ($filas as $fila) {
                $proveedorId = (int) ($fila['proveedor_id'] ?? 0);
                if ($proveedorId < 1 || isset($vistos[$proveedorId])) {
                    // El UNIQUE de la tabla rechazaría el duplicado con un error
                    // que no dice cuál de las filas del formulario sobra.
                    continue;
                }
                $vistos[$proveedorId] = true;

                $costo = (float) ($fila['costo'] ?? 0);
                if ($costo < 0) {
                    $costo = 0;
                }

                $codigo = trim((string) ($fila['codigo'] ?? ''));
                $codigo = $codigo === '' ? null : mb_substr($codigo, 0, 60);

                $preferente = 0;
                if (!empty($fila['preferente']) && !$yaHayPreferente) {
                    $preferente = 1;
                    $yaHayPreferente = true;
                }

                $stmt->bind_param('iidsi', $ingredienteId, $proveedorId, $costo, $codigo, $preferente);
                if (!$stmt->execute()) {
                    throw new \RuntimeException($stmt->error);
                }
            }

            $stmt->close();
            $db->commit();

            return true;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignorado) {
                // Sin transacción viva no hay nada que deshacer.
            }
            error_log('Proveedores::asignarAIngrediente - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lee del POST la tabla repetible de proveedores del formulario de
     * ingrediente. Los tres campos viajan como arrays paralelos, así que el
     * índice es lo que une cada proveedor con su costo.
     */
    public static function filasDesdePost(array $post): array
    {
        $ids = (array) ($post['proveedor_id'] ?? []);
        $costos = (array) ($post['proveedor_costo'] ?? []);
        $codigos = (array) ($post['proveedor_codigo'] ?? []);
        $preferente = (string) ($post['proveedor_preferente'] ?? '');

        $filas = [];
        foreach ($ids as $indice => $id) {
            if ((int) $id < 1) {
                continue;
            }
            $filas[] = [
                'proveedor_id' => (int) $id,
                'costo' => \Services\Security\ContextoNegocio::nuevo()
                    ? self::decimalCosto($costos[$indice] ?? '0')
                    : (float) ($costos[$indice] ?? 0),
                'codigo' => (string) ($codigos[$indice] ?? ''),
                // El radio manda el índice de la fila elegida, no un booleano
                // por fila: sólo puede haber uno.
                'preferente' => (string) $indice === $preferente,
            ];
        }

        return $filas;
    }

    /**
     * Elimina un proveedor. Sus precios se van con él por la llave foránea en
     * cascada, pero el histórico de precios lo conserva con proveedor_id en
     * NULL: la subida de precio ocurrió aunque ya no le compremos.
     */
    public static function eliminar(int $proveedorId): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            try {
                \Services\Security\ContextoNegocio::exigir('inventario','editar','restaurante');
                $p=Proveedor::find($proveedorId);
                return $p ? (bool)$p->eliminar() : false;
            } catch(\Throwable $e) { error_log('Proveedores::eliminar - '.$e->getMessage()); return false; }
        }
        $proveedor = Proveedor::find($proveedorId);
        if (!$proveedor) {
            return false;
        }

        try {
            $proveedor->eliminar();
            return true;
        } catch (\Throwable $e) {
            error_log('Proveedores::eliminar - ' . $e->getMessage());
            return false;
        }
    }

    /** Proveedores de un ingrediente, para pintar su ficha. */
    public static function deIngrediente(int $ingredienteId): array
    {
        return $ingredienteId > 0 ? IngredienteProveedor::porIngrediente($ingredienteId) : [];
    }

    private static function asignarNuevo(int $articuloId,array $filas): bool
    {
        if($articuloId<1) return false;
        try {
            $resultado=\Services\Security\ContextoNegocio::transaccion(function() use($articuloId,$filas) {
                \Services\Security\ContextoNegocio::exigir('inventario','editar','restaurante',true);
                $db=\Services\Security\ContextoNegocio::db(); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
                $s=$db->prepare('SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id WHERE aa.articulo_id=? AND aa.unidad_id=? LIMIT 1 FOR UPDATE');
                $s->execute([$articuloId,$u]); if(!$s->get_result()->fetch_row()) throw new \Services\Inventory\InventarioError('RECURSO_NO_ENCONTRADO','El artículo no está asociado al Restaurante.');
                $reset=$db->prepare('UPDATE ingrediente_proveedores SET preferente=0 WHERE unidad_id=? AND ingrediente_id=?'); $reset->execute([$u,$articuloId]);
                $del=$db->prepare('DELETE ip FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id WHERE ip.unidad_id=? AND ip.ingrediente_id=? AND p.activo=1'); $del->execute([$u,$articuloId]);
                $ins=$db->prepare('INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo,codigo,preferente) SELECT ?,?,?,?, ?,? FROM proveedores WHERE id=? AND unidad_id=?');
                $seen=[]; $preferred=false;
                foreach($filas as $fila) {
                    $pid=(int)($fila['proveedor_id']??0); if($pid<1||isset($seen[$pid])) throw new \Services\Inventory\InventarioError('PROVEEDOR_INVALIDO','Revisa la lista de proveedores.'); $seen[$pid]=true;
                    $costo=self::decimalCosto($fila['costo']??'0'); $codigo=trim((string)($fila['codigo']??'')); $codigo=$codigo===''?null:mb_substr($codigo,0,60);
                    $pref=!empty($fila['preferente'])&&!$preferred?1:0; if($pref)$preferred=true;
                    $ins->execute([$u,$articuloId,$pid,$costo,$codigo,$pref,$pid,$u]);
                    if($ins->affected_rows!==1) throw new \Services\Inventory\InventarioError('PROVEEDOR_INVALIDO','El proveedor no pertenece al Restaurante.');
                }
                return ['ok'=>true,'codigo'=>'PROVEEDORES_ASIGNADOS'];
            });
            // Un savepoint válido queda pendiente del coordinador exterior.
            return ($resultado['ok']??null) !== false && ($resultado['codigo']??'') === 'PROVEEDORES_ASIGNADOS';
        } catch(\Throwable $e) { error_log('Proveedores::asignarNuevo - '.$e->getMessage()); return false; }
    }

    private static function decimalCosto(mixed $valor): string
    {
        if((!is_string($valor)&&!is_int($valor))||!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/D',(string)$valor)) throw new \Services\Inventory\InventarioError('COSTO_INVALIDO','El costo debe ser positivo con hasta cuatro decimales.');
        $p=explode('.',(string)$valor,2); return $p[0].'.'.str_pad($p[1]??'',4,'0');
    }
}
