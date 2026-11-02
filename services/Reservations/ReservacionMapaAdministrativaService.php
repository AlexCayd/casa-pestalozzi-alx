<?php

namespace Services\Reservations;

use Model\ActiveRecord;
use Services\Scheduling\HorarioConfigLock;
use Services\Shared\FechaOperacionLock;

/**
 * Escritura de la asignación de mesas desde el mapa del POS («Cambiar mesas»).
 *
 * Usa el mismo dominio de ocupación y asignación que el resto, pero con los
 * locks globales de operación que necesita un cambio hecho sobre el mapa. La
 * lectura y «liberar mesas» vivían aquí para la pantalla de Operación, que se
 * retiró.
 */
final class ReservacionMapaAdministrativaService
{
    public static function guardarAsignacion(
        int $reservacionId,
        array $mesaIds,
        array $opciones = []
    ): array {
        $fecha = trim((string)($opciones['fecha_esperada'] ?? ''));
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS];
        }

        return self::conLocksFecha($fecha, static function () use ($reservacionId, $mesaIds, $opciones): array {
            $db = ActiveRecord::getDB();
            if (!$db->begin_transaction()) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }
            try {
                if (\Services\Security\ContextoNegocio::nuevo()) \Services\Security\ContextoNegocio::exigirUno(['reservations'=>'editar','pdv'=>'operar','reservations_map'=>'operar'],'restaurante',true);
                $resultado = AsignacionMesasService::asignarManual(
                    $reservacionId,
                    $mesaIds,
                    false,
                    false,
                    array_merge($opciones, [
                        'modo_administrativo_mapa' => true,
                    ])
                );

                if (!($resultado['ok'] ?? false)) {
                    $db->rollback();
                    return $resultado;
                }

                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar la asignacion administrativa.');
                }

                return $resultado;
            } catch (\Throwable $error) {
                $db->rollback();
                error_log('ReservacionMapaAdministrativaService::guardarAsignacion - ' . $error->getMessage());
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }
        });
    }

    private static function conLocksFecha(string $fecha, callable $callback): array
    {
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = false;
        try {
            if (!HorarioConfigLock::adquirir($db)) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::CONFLICTO_CONCURRENTE];
            }
            $lockHorario = true;
            if (!FechaOperacionLock::adquirir($db, $fecha)) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::CONFLICTO_CONCURRENTE];
            }
            $lockFecha = true;
            return $callback();
        } catch (\Throwable $error) {
            error_log('ReservacionMapaAdministrativaService::conLocksFecha - ' . $error->getMessage());
            return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
        } finally {
            if ($lockFecha) {
                FechaOperacionLock::liberar($db, $fecha);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }
}
