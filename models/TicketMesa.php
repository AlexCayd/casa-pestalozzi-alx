<?php

/**
 * Acceso a la relación canónica N:M entre tickets y mesas.
 */

namespace Model;

use DateTimeImmutable;
use Services\ReservacionConfig;

class TicketMesa extends ActiveRecord
{
    protected static $tabla = 'ticket_mesas';
    protected static $columnasDB = ['id', 'ticket_id', 'mesa_id', 'orden'];

    public $id;
    public $ticket_id;
    public $mesa_id;
    public $orden = 1;
    public $created_at = null;

    /**
     * Inserta la ocupación completa dentro de la transacción del ticket.
     * El orden estable evita deadlocks cuando dos empleados compiten por mesas.
     */
    public static function insertarTodas(int $ticketId, array $mesaIds): void
    {
        $mesaIds = self::normalizarIds($mesaIds);
        if ($ticketId < 1 || $mesaIds === []) {
            throw new \DomainException('El ticket debe ocupar al menos una mesa válida.');
        }

        $valores = [];
        foreach ($mesaIds as $index => $mesaId) {
            $valores[] = sprintf('(%d, %d, %d)', $ticketId, $mesaId, $index + 1);
        }

        if (!self::$db->query(
            'INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ' . implode(', ', $valores)
        )) {
            throw new \RuntimeException(self::$db->error);
        }
    }

    /** @return array<int, int> */
    public static function idsPorTicket(int $ticketId): array
    {
        if ($ticketId < 1) {
            return [];
        }

        $resultado = self::$db->query(
            "SELECT mesa_id FROM ticket_mesas WHERE ticket_id = {$ticketId} ORDER BY orden"
        );
        if (!$resultado) {
            throw new \RuntimeException(self::$db->error);
        }

        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['mesa_id'];
        }
        $resultado->free();

        return $ids;
    }

    /**
     * Devuelve las mesas físicamente ocupadas que todavía pueden interferir
     * con el inicio solicitado. Se unen filas N:M y columnas legacy sin contar
     * dos veces la misma mesa del mismo ticket.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ocupacionAbierta(
        string $fecha,
        string $hora,
        int $excluirReservacionId = 0,
        bool $bloquear = false
    ): array {
        $inicio = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $hora,
            ReservacionConfig::timezone()
        );
        if (!$inicio instanceof DateTimeImmutable) {
            return [];
        }

        $excluir = $excluirReservacionId > 0
            ? "AND (t.reservacion_id IS NULL OR t.reservacion_id <> {$excluirReservacionId})"
            : '';
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $resultado = self::$db->query(
            "SELECT t.id AS ticket_id,
                    t.reservacion_id,
                    t.hora_apertura,
                    COALESCE(tm.mesa_id, legacy.mesa_id) AS mesa_id,
                    CASE WHEN tm.id IS NULL THEN 1 ELSE 0 END AS legacy
             FROM tickets t
             LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id
             LEFT JOIN (
                 SELECT id AS ticket_id, mesa_id FROM tickets
                 UNION ALL
                 SELECT id AS ticket_id, mesa_secundaria_id AS mesa_id
                 FROM tickets WHERE mesa_secundaria_id IS NOT NULL
             ) legacy ON legacy.ticket_id = t.id
                        AND NOT EXISTS (
                            SELECT 1 FROM ticket_mesas canonical
                            WHERE canonical.ticket_id = t.id
                        )
             WHERE t.estado = 'abierto'
               {$excluir}
               AND COALESCE(tm.mesa_id, legacy.mesa_id) IS NOT NULL
             ORDER BY t.id, mesa_id{$lock}"
        );
        if (!$resultado) {
            throw new \RuntimeException(self::$db->error);
        }

        $ahora = new DateTimeImmutable('now', ReservacionConfig::timezone());
        $ocupacion = [];
        $vistos = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ticketId = (int)$fila['ticket_id'];
            $mesaId = (int)$fila['mesa_id'];
            $clave = $ticketId . ':' . $mesaId;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $apertura = new DateTimeImmutable((string)$fila['hora_apertura'], ReservacionConfig::timezone());
            $porDuracion = $apertura->modify(
                '+' . ReservacionConfig::DURACION_SERVICIO_ESTIMADA_MINUTOS . ' minutes'
            );
            $porPreparacion = $ahora->modify(
                '+' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes'
            );
            // La estimación es conservadora; el cierre real sigue siendo la
            // única liberación inmediata y definitiva de la mesa.
            $liberacion = $porDuracion > $porPreparacion ? $porDuracion : $porPreparacion;
            if ($inicio >= $liberacion) {
                continue;
            }

            $ocupacion[] = [
                'ticket_id' => $ticketId,
                'reservacion_id' => $fila['reservacion_id'] !== null ? (int)$fila['reservacion_id'] : null,
                'mesa_id' => $mesaId,
                'legacy' => (bool)$fila['legacy'],
                'liberacion_estimada' => $liberacion->format('Y-m-d H:i:s'),
            ];
        }
        $resultado->free();

        return $ocupacion;
    }

    private static function normalizarIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
