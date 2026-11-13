<?php
declare(strict_types=1);

namespace Services\Shared;

/** Ejecuta únicamente la preparación del documento en una instantánea de lectura. */
final class LecturaConsistente
{
    public static function ejecutar(\mysqli $db, callable $preparar): array
    {
        $estado = $db->query('SELECT @@autocommit');
        if (!$estado || !($fila = $estado->fetch_row()) || (int) $fila[0] !== 1) {
            throw new \RuntimeException('La lectura requiere una conexión sin transacción externa.');
        }
        // SET TRANSACTION es para la próxima transacción y falla si ya hay una activa.
        // No iniciar otra ni revertir la transacción de un llamador externo.
        if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
            throw new \RuntimeException('No se pudo preparar la lectura consistente.');
        }
        $propia = false;
        try {
            if (!$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
                throw new \RuntimeException('No se pudo iniciar la lectura consistente.');
            }
            $propia = true;
            $documento = $preparar($db);
            if (!is_array($documento)) throw new \RuntimeException('El documento preparado no es válido.');
            if (!$db->commit()) throw new \RuntimeException('No se pudo completar la lectura consistente.');
            $propia = false;
            return $documento;
        } catch (\Throwable $e) {
            if ($propia) {
                try { $db->rollback(); } catch (\Throwable $ignorado) { /* Se conserva el error original. */ }
            }
            throw $e;
        }
    }
}
