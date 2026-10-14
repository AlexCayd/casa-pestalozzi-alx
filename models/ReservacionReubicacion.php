<?php

/**
 * Bitácora de reubicaciones automáticas (reservacion_reubicaciones).
 *
 * Una fila por reservación movida para liberar el bloque exterior. Las mesas
 * se guardan como ids en texto («8,9») porque son el registro de lo que pasó,
 * no una relación viva.
 */

namespace Model;

class ReservacionReubicacion extends ActiveRecord
{
    public const MOTIVO_BLOQUE_EXTERIOR = 'bloque_exterior';
    /** Su mesa seguía con un ticket abierto cuando la reserva estaba por llegar. */
    public const MOTIVO_TICKET_ABIERTO = 'ticket_abierto';

    protected static $tabla = 'reservacion_reubicaciones';
    protected static $columnasDB = [
        'id',
        'reservacion_id',
        'causa_reservacion_id',
        'motivo',
        'mesas_antes',
        'mesas_despues',
        'revisada_at',
        'revisada_por',
        'created_at',
    ];

    public $id;
    public $reservacion_id;
    public $causa_reservacion_id = null;
    public $motivo = self::MOTIVO_BLOQUE_EXTERIOR;
    public $mesas_antes = '';
    public $mesas_despues = '';
    public $revisada_at = null;
    public $revisada_por = null;
    public $created_at = null;

    /**
     * @param array<int, int> $antes @param array<int, int> $despues
     * @return int Id de la fila: el aviso del buzón apunta a ella.
     */
    public static function registrar(
        int $reservacionId,
        ?int $causaReservacionId,
        array $antes,
        array $despues,
        string $motivo = self::MOTIVO_BLOQUE_EXTERIOR
    ): int {
        $stmt = self::$db->prepare(
            'INSERT INTO reservacion_reubicaciones
               (reservacion_id, causa_reservacion_id, motivo, mesas_antes, mesas_despues)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el registro de la reubicación.');
        }
        $textoAntes = self::csv($antes);
        $textoDespues = self::csv($despues);
        $stmt->bind_param('iisss', $reservacionId, $causaReservacionId, $motivo, $textoAntes, $textoDespues);
        $ok = $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('No fue posible registrar la reubicación.');
        }

        return $id;
    }

    /** @return array<string, mixed>|null Reubicación con su reservación, para el buzón. */
    public static function buscarConReservacion(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $resultado = self::$db->query(
            'SELECT rr.id, rr.reservacion_id, rr.causa_reservacion_id, rr.mesas_antes, rr.mesas_despues,
                    rr.revisada_at, rr.created_at
               FROM reservacion_reubicaciones rr
              WHERE rr.id = ' . $id . ' LIMIT 1'
        );
        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        if ($fila === null) {
            return null;
        }

        return [
            'id' => (int)$fila['id'],
            'reservacion_id' => (int)$fila['reservacion_id'],
            'causa_reservacion_id' => $fila['causa_reservacion_id'] !== null ? (int)$fila['causa_reservacion_id'] : null,
            'mesas_antes' => self::ids((string)$fila['mesas_antes']),
            'mesas_despues' => self::ids((string)$fila['mesas_despues']),
            'revisada' => $fila['revisada_at'] !== null,
            'created_at' => (string)$fila['created_at'],
        ];
    }

    /**
     * Última reubicación de cada reservación pedida, para pintar la insignia
     * «Reubicada de Mesa 9 a Mesa 3» sin una consulta por tarjeta.
     *
     * @param array<int, int> $reservacionIds
     * @return array<int, array<string, mixed>> reservacion_id => reubicación
     */
    public static function ultimasPorReservacion(array $reservacionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservacionIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $resultado = self::$db->query(
            'SELECT rr.id, rr.reservacion_id, rr.causa_reservacion_id, rr.motivo,
                    rr.mesas_antes, rr.mesas_despues, rr.revisada_at, rr.created_at
               FROM reservacion_reubicaciones rr
               JOIN (SELECT reservacion_id, MAX(id) AS ultima
                       FROM reservacion_reubicaciones
                      WHERE reservacion_id IN (' . implode(',', $ids) . ')
                      GROUP BY reservacion_id) u ON u.ultima = rr.id'
        );
        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $porReservacion = [];
        while ($fila = $resultado->fetch_assoc()) {
            $porReservacion[(int)$fila['reservacion_id']] = [
                'id' => (int)$fila['id'],
                'causa_reservacion_id' => $fila['causa_reservacion_id'] !== null ? (int)$fila['causa_reservacion_id'] : null,
                'motivo' => (string)$fila['motivo'],
                'mesas_antes' => self::ids((string)$fila['mesas_antes']),
                'mesas_despues' => self::ids((string)$fila['mesas_despues']),
                'revisada' => $fila['revisada_at'] !== null,
                'created_at' => (string)$fila['created_at'],
            ];
        }
        $resultado->free();

        return $porReservacion;
    }

    public static function marcarRevisada(int $reubicacionId, ?int $usuarioId): bool
    {
        $stmt = self::$db->prepare(
            'UPDATE reservacion_reubicaciones
                SET revisada_at = CURRENT_TIMESTAMP, revisada_por = ?
              WHERE id = ? AND revisada_at IS NULL'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la revisión de la reubicación.');
        }
        $stmt->bind_param('ii', $usuarioId, $reubicacionId);
        $stmt->execute();
        $marcada = $stmt->affected_rows > 0;
        $stmt->close();

        return $marcada;
    }

    /** @param array<int, int> $ids */
    private static function csv(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);

        return implode(',', $ids);
    }

    /** @return array<int, int> */
    private static function ids(string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }
}
