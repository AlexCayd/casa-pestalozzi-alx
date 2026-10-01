<?php

/**
 * Encapsula el acceso a la tabla pivote reservacion_mesas.
 * La validacion de disponibilidad se conserva en AsignacionMesasService.
 */

namespace Model;

use Services\Reservations\ReservacionVigenciaService;

class ReservacionMesa extends ActiveRecord
{
    protected static $tabla = 'reservacion_mesas';
    protected static $columnasDB = ['id', 'reservacion_id', 'mesa_id', 'orden'];

    public $id;
    public $reservacion_id;
    public $mesa_id;
    public $orden = 1;
    public $created_at = null;

    public static function obtenerPorReservacion(int $reservacionId): array
    {
        if ($reservacionId < 1) {
            return [];
        }

        return Mesa::consultarSQL(
            "SELECT m.id, m.numero, m.nombre, m.tipo, m.capacidad, m.pos_x, m.pos_y, m.activo, m.reservable
             FROM reservacion_mesas rm
             INNER JOIN mesas m ON m.id = rm.mesa_id
             WHERE rm.reservacion_id = {$reservacionId}
             ORDER BY rm.orden ASC"
        );
    }

    public static function obtenerIdsPorReservacion(int $reservacionId): array
    {
        if ($reservacionId < 1) {
            return [];
        }

        $resultado = self::$db->query(
            "SELECT mesa_id
             FROM reservacion_mesas
             WHERE reservacion_id = {$reservacionId}
             ORDER BY orden ASC"
        );

        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['mesa_id'];
        }

        $resultado->free();

        return $ids;
    }

    public static function reemplazarAsignacion(int $reservacionId, array $mesaIds): void
    {
        if ($reservacionId < 1) {
            return;
        }

        self::eliminarAsignacion($reservacionId);

        $mesaIds = array_values(array_unique(array_filter(array_map('intval', $mesaIds))));

        if (empty($mesaIds)) {
            return;
        }

        $valores = [];
        foreach ($mesaIds as $index => $mesaId) {
            $orden = $index + 1;
            $valores[] = "({$reservacionId}, {$mesaId}, {$orden})";
        }

        self::ejecutar(
            "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
             VALUES " . implode(', ', $valores)
        );
    }

    public static function eliminarAsignacion(int $reservacionId): void
    {
        if ($reservacionId < 1) {
            return;
        }

        self::ejecutar("DELETE FROM reservacion_mesas WHERE reservacion_id = {$reservacionId}");
    }

    /** Elimina relaciones en lote, conservando una sola escritura SQL. */
    public static function eliminarAsignaciones(array $reservacionIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $reservacionIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return;
        }

        self::ejecutar(
            'DELETE FROM reservacion_mesas WHERE reservacion_id IN (' . implode(',', $ids) . ')'
        );
    }

    /**
     * Devuelve asignaciones activas del dia. Los estados finales quedan fuera
     * para conservar historial sin bloquear disponibilidad futura.
     */
    public static function obtenerOcupacionDelDia(
        string $fecha,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?\DateTimeImmutable $ahora = null
    ): array {
        $fecha = self::escaparString($fecha);
        $exclusiones = is_array($excluirReservacionId) ? $excluirReservacionId : [$excluirReservacionId];
        $exclusiones = array_values(array_unique(array_filter(array_map('intval', $exclusiones), static fn(int $id): bool => $id > 0)));
        $excluirSql = $exclusiones !== [] ? 'AND r.id NOT IN (' . implode(',', $exclusiones) . ')' : '';
        $bloqueoSql = $bloquear ? ' FOR UPDATE' : '';
        $condicionOcupacion = ReservacionVigenciaService::condicionSqlInfluyeDisponibilidad('r', $ahora);
        $resultado = self::$db->query(
            "SELECT rm.mesa_id,
                    r.id AS reservacion_id,
                    r.nombre,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.estado,
                    r.hold_expires_at
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             WHERE r.fecha = '{$fecha}'
               {$excluirSql}
               AND {$condicionOcupacion}
             ORDER BY r.hora ASC, rm.mesa_id ASC{$bloqueoSql}"
        );

        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $ocupacion = [];
        while ($fila = $resultado->fetch_assoc()) {
            $asignacion = [
                'mesa_id' => (int)$fila['mesa_id'],
                'reservacion_id' => (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'contacto' => (string)$fila['contacto'],
                'fecha' => (string)$fila['fecha'],
                'hora' => (string)$fila['hora'],
                'comensales' => (int)$fila['comensales'],
                'estado' => (string)$fila['estado'],
                'hold_expires_at' => $fila['hold_expires_at'] !== null
                    ? (string)$fila['hold_expires_at']
                    : null,
                'fuente' => (string)$fila['estado'] === 'pendiente_verificacion'
                    ? 'hold'
                    : 'reservacion',
                'reservacion_influye_en_disponibilidad' => true,
            ];
            $vigencia = ReservacionVigenciaService::clasificar($asignacion, $ahora);
            if ((bool)($vigencia['influye_disponibilidad'] ?? false)) {
                $ocupacion[] = $asignacion;
            }
        }

        $resultado->free();

        return $ocupacion;
    }

    /**
     * Hechos diarios de demanda confirmada aún sin relación de mesa.
     * El intervalo se filtra en OcupacionMesasService, autoridad del traslape.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function obtenerDemandaNoAsignadaDelDia(
        string $fecha,
        ?\DateTimeImmutable $ahora = null
    ): array {
        $fecha = self::escaparString($fecha);
        $condicionOcupacion = ReservacionVigenciaService::condicionSqlInfluyeDisponibilidad('r', $ahora);
        $resultado = self::$db->query(
            "SELECT r.id, r.fecha, r.hora, r.comensales, r.estado
             FROM reservaciones r
             WHERE r.fecha = '{$fecha}'
               AND r.estado = 'confirmada'
               AND NOT EXISTS (
                   SELECT 1
                   FROM reservacion_mesas rm
                   WHERE rm.reservacion_id = r.id
               )
               AND {$condicionOcupacion}
             ORDER BY r.hora ASC, r.id ASC"
        );
        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = [
                'id' => (int)$fila['id'],
                'fecha' => (string)$fila['fecha'],
                'hora' => (string)$fila['hora'],
                'comensales' => (int)$fila['comensales'],
                'estado' => (string)$fila['estado'],
                'influye_disponibilidad' => true,
            ];
        }
        $resultado->free();
        return $filas;
    }

    public static function tieneMesasAsignadas(int $reservacionId): bool
    {
        if ($reservacionId < 1) {
            return false;
        }

        $resultado = self::$db->query(
            "SELECT COUNT(*) AS total
             FROM reservacion_mesas
             WHERE reservacion_id = {$reservacionId}"
        );

        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $fila = $resultado->fetch_assoc() ?: ['total' => 0];
        $resultado->free();

        return (int)$fila['total'] > 0;
    }

    private static function ejecutar(string $query): void
    {
        if (self::$db->query($query) === false) {
            throw new \RuntimeException(self::$db->error);
        }
    }
}
