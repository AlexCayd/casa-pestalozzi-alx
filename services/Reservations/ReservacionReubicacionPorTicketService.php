<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\ReservacionMesa;
use Model\ReservacionReubicacion;
use Model\TicketMesa;
use Services\Scheduling\HorarioConfigLock;
use Services\Shared\FechaOperacionLock;

/**
 * Una reserva por llegar cuya mesa sigue con un ticket abierto se reubica sola.
 *
 * El mapa tiene tres estados —verde, rojo, azul— y ninguno compuesto. Antes,
 * una mesa ocupada con una reserva encima salía roja con un aro azul y la hora
 * («ocupada, con reservación»): había que leerla y resolverla a mano. Ahora, en
 * cuanto la reserva entra en su ventana (una hora antes) y su mesa sigue
 * ocupada, se le busca otro lugar libre AHORA en el salón y se mueve; la mesa
 * ocupada queda en rojo y la reserva, en azul en su mesa nueva.
 *
 * Corre en lectura (el sondeo del POS), como las ausencias automáticas. Si no
 * hay a dónde moverla —salón lleno, o un grupo que necesita el bloque
 * exterior— se queda donde estaba y lo vuelve a intentar en la siguiente
 * lectura: puede que el ticket se cierre o que se libere otra mesa.
 *
 * Cada movimiento queda en `reservacion_reubicaciones` (motivo `ticket_abierto`)
 * y en el buzón, igual que las reubicaciones del bloque exterior: la ficha del
 * POS dice «Reubicada de … a …».
 */
final class ReservacionReubicacionPorTicketService
{
    private const LOTE = 20;

    private static bool $procesadoEnPeticion = false;

    /** @return array{reubicadas: array<int, array{id:int, antes:array<int,int>, despues:array<int,int>}>} */
    public static function procesar(?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $fecha = ActiveRecord::escaparString($ahora->format('Y-m-d'));
        $desde = ActiveRecord::escaparString(
            $ahora->modify('-' . ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS . ' minutes')->format('Y-m-d H:i:s')
        );
        $hasta = ActiveRecord::escaparString(
            $ahora->modify('+' . ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS . ' minutes')->format('Y-m-d H:i:s')
        );
        $abiertoT = TicketMesa::condicionSqlAbierto('t');
        $abiertoPropio = TicketMesa::condicionSqlAbierto('tp');
        $lote = self::LOTE;

        // Confirmadas de hoy en su ventana (de una hora antes al fin de la
        // tolerancia), sin ticket propio, con alguna mesa ocupada por OTRO.
        $resultado = ActiveRecord::getDB()->query(
            "SELECT DISTINCT r.id, r.hora
             FROM reservaciones r
             JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
             JOIN ticket_mesas tm ON tm.mesa_id = rm.mesa_id
             JOIN tickets t ON t.id = tm.ticket_id
             WHERE r.estado = 'confirmada'
               AND r.fecha = '{$fecha}'
               AND TIMESTAMP(r.fecha, r.hora) BETWEEN '{$desde}' AND '{$hasta}'
               AND {$abiertoT}
               AND (t.reservacion_id IS NULL OR t.reservacion_id <> r.id)
               AND NOT EXISTS (
                   SELECT 1 FROM tickets tp WHERE tp.reservacion_id = r.id AND {$abiertoPropio}
               )
             ORDER BY r.hora ASC, r.id ASC
             LIMIT {$lote}"
        );
        if ($resultado === false) {
            error_log('ReservacionReubicacionPorTicketService::procesar - ' . ActiveRecord::getDB()->error);
            return ['reubicadas' => []];
        }
        $candidatas = array_map('intval', array_column($resultado->fetch_all(MYSQLI_ASSOC), 'id'));
        $resultado->free();

        $reubicadas = [];
        foreach ($candidatas as $id) {
            $movida = self::reubicar($id, $ahora->format('Y-m-d'));
            if ($movida !== null) {
                $reubicadas[] = $movida;
            }
        }

        return ['reubicadas' => $reubicadas];
    }

    /** Una pasada por petición, y nunca un error que tumbe la pantalla. */
    public static function procesarEnLectura(): void
    {
        if (self::$procesadoEnPeticion) {
            return;
        }
        self::$procesadoEnPeticion = true;
        try {
            self::procesar();
        } catch (\Throwable $error) {
            error_log('ReservacionReubicacionPorTicketService::procesarEnLectura - ' . $error->getMessage());
        }
    }

    /** @return array{id:int, antes:array<int,int>, despues:array<int,int>}|null */
    private static function reubicar(int $id, string $fecha): ?array
    {
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = false;
        $transaccion = false;
        try {
            // El mismo orden de locks que el resto de mutaciones de mesas.
            $lockHorario = HorarioConfigLock::adquirir($db, 5);
            if (!$lockHorario || !FechaOperacionLock::adquirir($db, $fecha, 5)) {
                return null;
            }
            $lockFecha = true;
            $db->begin_transaction();
            $transaccion = true;

            $antes = ReservacionMesa::obtenerIdsPorReservacion($id);
            $asignacion = AsignacionMesasService::reasignarEvitandoTicketsAbiertos($id, false);
            $despues = ReservacionMesa::obtenerIdsPorReservacion($id);
            sort($antes);
            sort($despues);
            if (($asignacion['ok'] ?? false) !== true || $despues === [] || $despues === $antes) {
                // Sin lugar libre ahora: se queda donde estaba (nunca sin mesas).
                $db->rollback();
                $transaccion = false;
                return null;
            }

            $reubicacionId = ReservacionReubicacion::registrar(
                $id,
                null,
                $antes,
                $despues,
                ReservacionReubicacion::MOTIVO_TICKET_ABIERTO
            );
            ReservacionBuzonService::crearAvisoReubicacionEnTransaccion($db, $reubicacionId);
            $db->commit();
            $transaccion = false;

            return ['id' => $id, 'antes' => $antes, 'despues' => $despues];
        } catch (\Throwable $error) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionReubicacionPorTicketService::reubicar - ' . $error->getMessage());
            return null;
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
