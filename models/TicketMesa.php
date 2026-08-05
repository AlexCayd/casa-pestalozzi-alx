<?php

/**
 * Acceso a la relación canónica N:M entre tickets y mesas.
 */

namespace Model;

use Services\ReservacionConfig;

class TicketMesa extends ActiveRecord
{
    public const ESTADO_ABIERTO = 'abierto';

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
     * Devuelve todas las mesas físicamente ocupadas por tickets abiertos.
     * La ocupación no depende del horario consultado: sólo el cierre real del
     * ticket puede liberar sus relaciones en ticket_mesas.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ocupacionAbierta(bool $bloquear = false): array
    {
        return self::ocupacionAbiertaDesdeTickets(self::abiertosParaMapa($bloquear));
    }

    /**
     * Fuente canónica de tickets abiertos para POS, reservaciones y validación.
     * Agrupa todas las mesas del ticket para conservar correctamente la N:M.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function abiertosParaMapa(bool $bloquear = false): array
    {
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $condicionAbierto = self::condicionSqlAbierto('t');
        $resultado = self::$db->query(
            "SELECT t.id,
                    t.nombre,
                    t.comensales,
                    t.hora_apertura,
                    t.closed_at,
                    t.estado,
                    t.reservacion_id,
                    t.mesero_id,
                    GROUP_CONCAT(tm.mesa_id ORDER BY tm.orden) AS mesa_ids
             FROM tickets t
             INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
             WHERE {$condicionAbierto}
             GROUP BY t.id, t.nombre, t.comensales, t.hora_apertura, t.closed_at, t.estado,
                      t.reservacion_id, t.mesero_id
             ORDER BY t.id{$lock}"
        );
        if (!$resultado) {
            throw new \RuntimeException(self::$db->error);
        }

        $tickets = [];
        while ($fila = $resultado->fetch_assoc()) {
            $tickets[] = [
                'id' => (int)$fila['id'],
                'nombre' => $fila['nombre'] !== null ? (string)$fila['nombre'] : null,
                'comensales' => (int)$fila['comensales'],
                'hora_apertura' => (string)$fila['hora_apertura'],
                'closed_at' => $fila['closed_at'] !== null ? (string)$fila['closed_at'] : null,
                'estado' => (string)$fila['estado'],
                'reservacion_id' => $fila['reservacion_id'] !== null
                    ? (int)$fila['reservacion_id']
                    : null,
                'mesero_id' => $fila['mesero_id'] !== null ? (int)$fila['mesero_id'] : null,
                'mesa_ids' => self::normalizarIds(explode(',', (string)$fila['mesa_ids'])),
                'origen' => $fila['reservacion_id'] !== null ? 'reservacion' : 'walk_in',
            ];
        }
        $resultado->free();

        return $tickets;
    }

    /**
     * Definición SQL única de un ticket que todavía representa servicio
     * físico. El estado y la marca de cierre deben coincidir.
     */
    public static function condicionSqlAbierto(string $alias = 't'): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Alias SQL de ticket inválido.');
        }

        return "({$alias}.estado = '" . self::ESTADO_ABIERTO . "' AND {$alias}.closed_at IS NULL)";
    }

    /**
     * Convierte una sola lectura canónica en ocupación por mesa. Se reutiliza
     * en el mapa diario sin reinterpretar hora_apertura como disponibilidad.
     *
     * @param array<int, array<string, mixed>> $tickets
     * @return array<int, array<string, mixed>>
     */
    public static function ocupacionAbiertaDesdeTickets(array $tickets): array
    {
        $ahora = ReservacionConfig::ahora();
        $ocupacion = [];
        $vistos = [];
        foreach ($tickets as $ticket) {
            $ticketId = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
            $reservacionId = !empty($ticket['reservacion_id'])
                ? (int)$ticket['reservacion_id']
                : null;
            $liberacionBase = null;
            $liberacion = null;
            try {
                $apertura = new \DateTimeImmutable(
                    (string)($ticket['hora_apertura'] ?? ''),
                    ReservacionConfig::timezone()
                );
                $liberacionBase = $apertura->modify(
                    '+' . ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS . ' minutes'
                )->modify(
                    '+' . ReservacionConfig::MARGEN_PREPARACION_MESA_MINUTOS . ' minutes'
                );
                $seguridad = $ahora->modify(
                    '+' . ReservacionConfig::MARGEN_MINIMO_SEGURIDAD_MINUTOS . ' minutes'
                );
                $liberacion = $liberacionBase > $seguridad
                    ? $liberacionBase
                    : $seguridad;
            } catch (\Throwable $e) {
                // hora_apertura es informativa. Un valor histórico inválido no
                // debe convertir en disponible una mesa físicamente ocupada.
            }

            foreach (self::normalizarIds((array)($ticket['mesa_ids'] ?? [])) as $mesaId) {
                $clave = $ticketId . ':' . $mesaId;
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;

                $ocupacion[] = [
                    'ticket_id' => $ticketId,
                    'reservacion_id' => $reservacionId,
                    'mesa_id' => $mesaId,
                    'walk_in' => $reservacionId === null,
                    'mesa_ids' => self::normalizarIds((array)($ticket['mesa_ids'] ?? [])),
                    'liberacion_base' => $liberacionBase instanceof \DateTimeImmutable
                        ? $liberacionBase->format('Y-m-d H:i:s')
                        : null,
                    'liberacion_estimada' => $liberacion instanceof \DateTimeImmutable
                        ? $liberacion->format('Y-m-d H:i:s')
                        : null,
                ];
            }
        }

        return $ocupacion;
    }

    private static function normalizarIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
