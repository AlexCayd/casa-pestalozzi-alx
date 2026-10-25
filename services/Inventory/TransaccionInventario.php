<?php
declare(strict_types=1);
namespace Services\Inventory;

/** Coordina una llamada independiente o un savepoint del coordinador comercial. */
final class TransaccionInventario
{
    public static function ejecutar(\mysqli $db, callable $accion): array
    {
        for ($intento = 0; $intento < 3; $intento++) {
            $propia = false;
            $savepoint = null;
            try {
                $externa = (int)$db->query('SELECT @@autocommit')->fetch_row()[0] === 0;
                if (!$externa) {
                    try { $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
                    catch (\mysqli_sql_exception $e) {
                        if ($e->getCode() !== 1568) throw $e;
                        $externa = true;
                    }
                }
                if ($externa) {
                    $savepoint = 'inv_' . bin2hex(random_bytes(8));
                    $db->query('SAVEPOINT ' . $savepoint);
                } else {
                    $db->begin_transaction();
                    $propia = true;
                }
                $resultado = $accion();
                if ($propia) $db->commit();
                else $db->query('RELEASE SAVEPOINT ' . $savepoint);
                // Un coordinador externo debe confirmar antes de publicar éxito.
                return array_merge($resultado, ['ok' => $propia ? true : null, 'confirmado' => $propia, 'pendiente' => !$propia]);
            } catch (\Throwable $e) {
                try {
                    if ($propia) $db->rollback();
                    elseif ($savepoint !== null) {
                        $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $db->query('RELEASE SAVEPOINT ' . $savepoint);
                    }
                } catch (\Throwable) {
                    return ['ok' => false, 'confirmado' => false, 'codigo' => 'TRANSACCION_ABORTADA', 'mensaje' => 'El coordinador debe revertir su transacción completa.'];
                }
                if ($propia && $e instanceof \mysqli_sql_exception && in_array($e->getCode(), [1205,1213], true) && $intento < 2) continue;
                return ['ok' => false, 'confirmado' => false,
                    'codigo' => $e instanceof InventarioError ? $e->codigo : ($e instanceof \mysqli_sql_exception && in_array($e->getCode(), [1205,1213], true) ? 'CONCURRENCIA_CONFLICTO' : 'ERROR_INVENTARIO'),
                    'mensaje' => $e instanceof InventarioError ? $e->getMessage() : 'No se pudo completar la operación de inventario.'];
            }
        }
        return ['ok'=>false, 'confirmado'=>false, 'codigo'=>'CONCURRENCIA_CONFLICTO'];
    }
}
