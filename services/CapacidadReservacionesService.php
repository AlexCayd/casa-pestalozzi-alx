<?php

/**
 * Fuente canónica de capacidad física para reservaciones.
 *
 * Este servicio no asigna mesas. Consume la evaluación temporal de
 * OcupacionMesasService y convierte sus hechos en capacidad física,
 * incluyendo la demanda de reservaciones confirmadas sin mesas.
 */

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;

final class CapacidadReservacionesService
{
    public const EVENTO_EVALUADA = 'reservaciones.capacidad_evaluada';

    /**
     * Evalúa un horario completo usando el motor de ocupación canónico.
     *
     * @return array<string, mixed>
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?array $ticketsAbiertos = null,
        ?DateTimeImmutable $ahora = null,
        string $origen = ''
    ): array {
        $evaluacion = OcupacionMesasService::evaluarHorario(
            $fecha,
            $hora,
            $excluirReservacionId,
            $bloquear,
            $ticketsAbiertos,
            $ahora
        );
        $mesas = Mesa::reservables();
        $resumen = self::desdeEvaluacion($mesas, $evaluacion, $excluirReservacionId, $bloquear, $ahora);
        $resumen['ocupacion'] = $evaluacion;
        if ($origen !== '') {
            self::registrarEvaluacion($resumen, $origen);
        }

        return $resumen;
    }

    /**
     * Convierte hechos de ocupación en el resumen canónico. Es deliberadamente
     * pura cuando se le pasan las filas de demanda, lo que permite probar las
     * fórmulas sin tocar la base de datos.
     *
     * @param array<int, object|array<string, mixed>> $mesas
     * @param array<string, mixed> $evaluacion
     * @param array<int, array<string, mixed>>|null $demandaFilas
     * @return array<string, mixed>
     */
    public static function calcular(
        array $mesas,
        array $evaluacion,
        ?array $demandaFilas = null,
        ?string $fecha = null,
        ?string $hora = null
    ): array {
        $mesasFisicas = [];
        foreach ($mesas as $mesa) {
            if (self::mesaFisicaReservable($mesa)) {
                $mesasFisicas[(int)self::valor($mesa, 'id', 0)] = $mesa;
            }
        }

        $disponibles = array_fill_keys(self::ids($evaluacion['mesa_ids_disponibles'] ?? []), true);
        $proyectadas = array_fill_keys(self::ids(
            $evaluacion['mesa_ids_proyectadas'] ?? ($evaluacion['mesas_proyectadas'] ?? [])
        ), true);
        $capacidadTotal = 0;
        $capacidadLibre = 0;
        $capacidadProyectada = 0;
        $mesaIdsLibres = [];
        $mesaIdsBloqueadas = [];
        $mesaIdsProyectadas = [];

        foreach ($mesasFisicas as $mesaId => $mesa) {
            $capacidad = max(0, (int)self::valor($mesa, 'capacidad', 0));
            $capacidadTotal += $capacidad;
            if (isset($disponibles[$mesaId])) {
                $capacidadLibre += $capacidad;
                $mesaIdsLibres[] = $mesaId;
            } else {
                $mesaIdsBloqueadas[] = $mesaId;
            }
            if (isset($proyectadas[$mesaId])) {
                $capacidadProyectada += $capacidad;
                $mesaIdsProyectadas[] = $mesaId;
            }
        }

        $demanda = 0;
        $demandaIds = [];
        foreach ((array)$demandaFilas as $fila) {
            $estado = (string)self::valor($fila, 'estado', 'confirmada');
            if ($estado !== '' && $estado !== 'confirmada') {
                continue;
            }
            if (array_key_exists('influye_disponibilidad', is_array($fila) ? $fila : get_object_vars($fila))
                && !filter_var(self::valor($fila, 'influye_disponibilidad', true), FILTER_VALIDATE_BOOLEAN)
            ) {
                continue;
            }
            $id = (int)self::valor($fila, 'id', 0);
            $demanda += max(0, (int)self::valor($fila, 'comensales', 0));
            if ($id > 0) {
                $demandaIds[] = $id;
            }
        }
        $demandaIds = self::ids($demandaIds);
        $capacidadReal = max(0, $capacidadLibre - $demanda);
        $exceso = max(0, $demanda - $capacidadLibre);
        $dependeProyeccion = $mesaIdsProyectadas !== [];
        $intervalo = (array)($evaluacion['intervalo'] ?? []);
        $fecha = $fecha ?? (string)($evaluacion['fecha'] ?? '');
        $hora = $hora ?? (string)($evaluacion['hora'] ?? '');

        return [
            'fecha' => $fecha,
            'hora' => $hora,
            'intervalo_inicio' => (string)($intervalo['inicio'] ?? ''),
            'intervalo_fin' => (string)($intervalo['fin'] ?? ''),
            'capacidad_fisica_total' => $capacidadTotal,
            'capacidad_fisica_comprometida' => max(0, $capacidadTotal - $capacidadLibre),
            'capacidad_fisica_libre' => $capacidadLibre,
            'demanda_no_asignada' => $demanda,
            'capacidad_real_disponible' => $capacidadReal,
            'exceso_capacidad' => $exceso,
            'capacidad_proyectada' => $capacidadProyectada,
            'depende_liberacion_proyectada' => $dependeProyeccion,
            'mesas_total' => count($mesasFisicas),
            'mesas_bloqueadas' => count($mesaIdsBloqueadas),
            'mesas_libres' => count($mesaIdsLibres),
            'mesa_ids_libres' => $mesaIdsLibres,
            'mesa_ids_bloqueadas' => $mesaIdsBloqueadas,
            'mesa_ids_proyectadas' => $mesaIdsProyectadas,
            'demanda_no_asignada_ids' => $demandaIds,
            // Compatibilidad de lectura con las superficies anteriores.
            'capacidad_total' => $capacidadTotal,
            'capacidad_realmente_libre' => $capacidadLibre,
            'capacidad_estimada_horario' => $capacidadReal,
            'capacidad_disponible' => $capacidadReal,
            'capacidad_estimada' => $capacidadReal,
        ];
    }

    /**
     * Completa un resumen a partir de una evaluación de ocupación. La consulta
     * de demanda siempre usa NOT EXISTS para evitar duplicados de la pivote.
     *
     * @return array<string, mixed>
     */
    public static function desdeEvaluacion(
        array $mesas,
        array $evaluacion,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?DateTimeImmutable $ahora = null
    ): array {
        $filas = array_key_exists('demanda_no_asignada_reservaciones', $evaluacion)
            ? (array)$evaluacion['demanda_no_asignada_reservaciones']
            : self::consultarDemandaNoAsignada(
                (string)($evaluacion['fecha'] ?? ''),
                (array)($evaluacion['intervalo'] ?? []),
                $excluirReservacionId,
                $bloquear,
                $ahora
            );
        $resumen = self::calcular($mesas, $evaluacion, $filas);
        $resumen['demanda_no_asignada_reservaciones'] = $filas;
        return $resumen;
    }

    /**
     * Consulta únicamente confirmadas activas y sin mesas asignadas que se
     * traslapan con el intervalo solicitado.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function consultarDemandaNoAsignada(
        string $fecha,
        array $intervalo,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?DateTimeImmutable $ahora = null
    ): array {
        $db = ActiveRecord::getDB();
        if (!$db || $fecha === '' || empty($intervalo['inicio']) || empty($intervalo['fin'])) {
            return [];
        }
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $fechaSql = $db->real_escape_string($fecha);
        $inicioSql = $db->real_escape_string((string)$intervalo['inicio']);
        $finSql = $db->real_escape_string((string)$intervalo['fin']);
        $exclusiones = self::ids(is_array($excluirReservacionId) ? $excluirReservacionId : [$excluirReservacionId]);
        $excluir = $exclusiones === [] ? '' : 'AND r.id NOT IN (' . implode(',', $exclusiones) . ')';
        $condicionInfluye = ReservacionVigenciaService::condicionSqlInfluyeDisponibilidad('r', $ahora);
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $sql = "SELECT r.id, r.fecha, r.hora, r.comensales, r.estado
                FROM reservaciones r
                WHERE r.fecha = '{$fechaSql}'
                  AND r.estado = 'confirmada'
                  AND TIMESTAMP(r.fecha, r.hora) < '{$finSql}'
                  AND TIMESTAMPADD(MINUTE, " . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ", TIMESTAMP(r.fecha, r.hora)) > '{$inicioSql}'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM reservacion_mesas rm
                      WHERE rm.reservacion_id = r.id
                  )
                  AND {$condicionInfluye}
                  {$excluir}
                ORDER BY r.hora ASC, r.id ASC{$lock}";
        $resultado = $db->query($sql);
        if (!$resultado) {
            throw new \RuntimeException($db->error);
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

    /** Registra sólo hechos de capacidad, sin datos personales ni secretos. */
    public static function registrarEvaluacion(
        array $resumen,
        string $origen,
        int $comensalesSolicitados = 0,
        ?bool $asignacionAutomatica = null,
        string $resultado = 'evaluada'
    ): void {
        $payload = [
            'evento' => self::EVENTO_EVALUADA,
            'fecha' => (string)($resumen['fecha'] ?? ''),
            'hora' => (string)($resumen['hora'] ?? ''),
            'intervalo_inicio' => (string)($resumen['intervalo_inicio'] ?? ''),
            'intervalo_fin' => (string)($resumen['intervalo_fin'] ?? ''),
            'capacidad_total' => (int)($resumen['capacidad_fisica_total'] ?? 0),
            'capacidad_comprometida' => (int)($resumen['capacidad_fisica_comprometida'] ?? 0),
            'capacidad_fisica_libre' => (int)($resumen['capacidad_fisica_libre'] ?? 0),
            'demanda_no_asignada' => (int)($resumen['demanda_no_asignada'] ?? 0),
            'capacidad_real_disponible' => (int)($resumen['capacidad_real_disponible'] ?? 0),
            'comensales_solicitados' => max(0, $comensalesSolicitados),
            'asignacion_automatica_disponible' => $asignacionAutomatica,
            'depende_liberacion_proyectada' => (bool)($resumen['depende_liberacion_proyectada'] ?? false),
            'resultado' => $resultado,
            'origen' => $origen,
        ];
        error_log(self::EVENTO_EVALUADA . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function mesaFisicaReservable($mesa): bool
    {
        return (int)self::valor($mesa, 'activo', 0) === 1
            && (int)self::valor($mesa, 'reservable', 0) === 1
            && (string)self::valor($mesa, 'tipo', '') === 'mesa'
            && (int)self::valor($mesa, 'capacidad', 0) > 0;
    }

    private static function valor($item, string $campo, $default = null)
    {
        if (is_array($item)) {
            return $item[$campo] ?? $default;
        }
        if (is_object($item)) {
            return $item->{$campo} ?? $default;
        }
        return $default;
    }

    /** @return array<int, int> */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
