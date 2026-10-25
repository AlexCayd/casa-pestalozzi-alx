<?php

/**
 * Aplica las reubicaciones que planeó AsignacionPlanificadorService.
 *
 * Corre DENTRO de la transacción del llamador (la retención pública, el alta
 * o la asignación) y bajo sus locks de fecha: si algo falla lanza una
 * excepción y el rollback del llamador deshace también la reserva que provocó
 * el movimiento. Nunca queda un grupo grande confirmado con el bloque a medias.
 */

namespace Services\Reservations;

use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\ReservacionReubicacion;

final class ReservacionReubicacionService
{
    /**
     * @param array<int, array<string, mixed>> $reubicaciones Plan del planificador.
     * @return array<int, array<string, mixed>> Las reubicaciones aplicadas.
     * @throws \RuntimeException Si una reserva cambió entre el plan y la escritura.
     */
    public static function aplicar(array $reubicaciones, int $causaReservacionId): array
    {
        if ($reubicaciones === []) {
            return [];
        }

        $db = ActiveRecord::getDB();
        $porReservacion = [];
        foreach ($reubicaciones as $reubicacion) {
            $reservacionId = (int)($reubicacion['reservacion_id'] ?? 0);
            $despues = self::ids((array)($reubicacion['mesas_despues'] ?? []));
            if ($reservacionId < 1 || $despues === []) {
                throw new \RuntimeException('Plan de reubicación incompleto.');
            }
            $porReservacion[$reservacionId] = [
                'antes' => self::ids((array)($reubicacion['mesas_antes'] ?? [])),
                'despues' => $despues,
            ];
        }
        ksort($porReservacion, SORT_NUMERIC);

        // Mismo orden de bloqueo que el resto del módulo: filas de reservación
        // por id y luego mesas por id. Los locks de fecha del llamador ya
        // serializan esta ventana; esto es la revalidación final.
        $resultado = $db->query(
            'SELECT id, estado, hold_expires_at FROM reservaciones
              WHERE id IN (' . implode(',', array_keys($porReservacion)) . ')' . \Model\Reservacion::filtroUnidad() . '
              ORDER BY id FOR UPDATE'
        );
        if ($resultado === false) {
            throw new \RuntimeException('No fue posible bloquear las reservaciones a reubicar.');
        }
        $estados = [];
        while ($fila = $resultado->fetch_assoc()) {
            $estados[(int)$fila['id']] = $fila;
        }
        $resultado->free();

        $ahora = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
        foreach ($porReservacion as $reservacionId => $mesas) {
            $fila = $estados[$reservacionId] ?? null;
            $vigente = $fila !== null && (
                $fila['estado'] === 'confirmada'
                || ($fila['estado'] === 'pendiente_verificacion'
                    && $fila['hold_expires_at'] !== null
                    && (string)$fila['hold_expires_at'] > $ahora)
            );
            if (!$vigente) {
                throw new \RuntimeException('Una reservación a reubicar cambió de estado.');
            }
            $actuales = self::ids(ReservacionMesa::obtenerIdsPorReservacion($reservacionId));
            if ($actuales !== $mesas['antes']) {
                throw new \RuntimeException('Una reservación a reubicar cambió de mesas.');
            }
        }

        $destinos = [];
        foreach ($porReservacion as $mesas) {
            $destinos = array_merge($destinos, $mesas['despues']);
        }
        if (count(Mesa::reservablesParaActualizar($destinos)) !== count(array_unique($destinos))) {
            throw new \RuntimeException('Una mesa de destino ya no es reservable.');
        }

        $aplicadas = [];
        foreach ($porReservacion as $reservacionId => $mesas) {
            ReservacionMesa::reemplazarAsignacion($reservacionId, $mesas['despues']);
            ReservacionMesa::avanzarVersion($reservacionId);
            $reubicacionId = ReservacionReubicacion::registrar(
                $reservacionId,
                $causaReservacionId > 0 ? $causaReservacionId : null,
                $mesas['antes'],
                $mesas['despues']
            );
            // Aviso informativo en el buzón, en la misma transacción: si la
            // reubicación se deshace, el aviso también. No pide acción —el
            // sistema ya resolvió—, pero el equipo debe enterarse antes de
            // sentar al cliente en la mesa que creía suya.
            ReservacionBuzonService::crearAvisoReubicacionEnTransaccion($db, $reubicacionId);
            $aplicadas[] = [
                'reservacion_id' => $reservacionId,
                'mesas_antes' => $mesas['antes'],
                'mesas_despues' => $mesas['despues'],
            ];
        }

        return $aplicadas;
    }

    /** @return array<int, int> */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
