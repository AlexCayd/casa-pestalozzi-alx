<?php

/**
 * Fuente única de verdad para disponibilidad y capacidad de reservaciones.
 *
 * Las consultas GET son orientativas. Toda mutación vuelve a ejecutar esta
 * lógica bajo lock de fecha, transacción y bloqueo ordenado de mesas.
 */

namespace Services;

use Model\Mesa;

final class DisponibilidadReservacionService
{
    public const DISPONIBILIDAD_CONSULTADA = 'DISPONIBILIDAD_CONSULTADA';
    public const SIN_DISPONIBILIDAD = 'SIN_DISPONIBILIDAD';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /**
     * Devuelve slots públicos sin exponer IDs, combinaciones o capacidad.
     *
     * @return array<string, mixed>
     */
    public static function consultar(
        string $fecha,
        $personas,
        int $excluirReservacionId = 0
    ): array
    {
        return self::consultarSlots(
            $fecha,
            $personas,
            ReservacionConfig::MAX_PUBLIC_GUESTS,
            max(0, $excluirReservacionId),
            true
        );
    }

    /**
     * La administración comparte la misma ocupación, pero puede evaluar grupos
     * mayores y combinaciones generales sin el límite público de tres mesas.
     *
     * @return array<string, mixed>
     */
    public static function consultarAdministrativa(
        string $fecha,
        $personas,
        int $excluirReservacionId = 0
    ): array
    {
        return self::consultarSlots(
            $fecha,
            $personas,
            ReservacionConfig::MAX_COMENSALES_ADMIN,
            max(0, $excluirReservacionId),
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function consultarSlots(
        string $fecha,
        $personas,
        int $maximoPersonas,
        int $excluirReservacionId,
        bool $asignacionPublica
    ): array
    {
        $personas = filter_var($personas, FILTER_VALIDATE_INT);
        if (
            $personas === false
            || $personas < 1
            || $personas > $maximoPersonas
        ) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'mensaje' => 'El número de comensales debe estar entre 1 y ' . $maximoPersonas . '.',
                'fecha' => trim($fecha),
                'personas' => is_int($personas) ? $personas : null,
                'horarios' => [],
            ];
        }

        $horarios = ReservacionService::obtenerHorariosDisponiblesParaFecha($fecha);
        if (!($horarios['ok'] ?? false)) {
            $horarios['personas'] = $personas;
            return $horarios;
        }

        $slots = [];
        $detallesHorarios = [];
        $hayDisponibilidad = false;
        foreach ($horarios['horarios'] ?? [] as $hora) {
            $evaluacion = $asignacionPublica
                ? self::evaluarHorario(
                    (string)$fecha,
                    (string)$hora,
                    (int)$personas,
                    $excluirReservacionId
                )
                : self::resumenHorario(
                    (string)$fecha,
                    (string)$hora,
                    (int)$personas,
                    $excluirReservacionId,
                    false,
                    false
                );
            $disponible = (bool)($evaluacion['ok'] ?? false);
            $hayDisponibilidad = $hayDisponibilidad || $disponible;
            $horaCorta = substr((string)$hora, 0, 5);
            $detalle = [
                'capacidad_total' => (int)($evaluacion['capacidad_total'] ?? 0),
                'capacidad_realmente_libre' => (int)($evaluacion['capacidad_realmente_libre'] ?? 0),
                'capacidad_proyectada' => (int)($evaluacion['capacidad_proyectada'] ?? 0),
                'capacidad_estimada_horario' => (int)($evaluacion['capacidad_estimada_horario'] ?? 0),
                'depende_liberacion_proyectada' => (bool)($evaluacion['depende_liberacion_proyectada'] ?? false),
                'advertencia' => $evaluacion['advertencia'] ?? null,
            ];
            $slots[] = [
                'hora' => $horaCorta,
                'disponible' => $disponible,
            ] + $detalle;
            $detallesHorarios[$horaCorta] = $detalle;
        }

        $mensaje = null;
        if ($slots === []) {
            $mensaje = $horarios['mensaje'] ?? 'No hay horarios disponibles.';
        } elseif (!$hayDisponibilidad) {
            $mensaje = 'No hay capacidad suficiente para '
                . (int)$personas
                . ((int)$personas === 1 ? ' persona' : ' personas')
                . ' en esta fecha.';
        }

        return [
            'ok' => true,
            'codigo' => $hayDisponibilidad
                ? self::DISPONIBILIDAD_CONSULTADA
                : self::SIN_DISPONIBILIDAD,
            'fecha' => (string)$fecha,
            'personas' => (int)$personas,
            'abierto' => (bool)($horarios['abierto'] ?? false),
            'origen' => $horarios['origen'] ?? null,
            'tipo' => $horarios['tipo'] ?? null,
            'detalle_horario' => $horarios['detalle_horario'] ?? null,
            'detalle_horarios' => $detallesHorarios,
            'horarios' => $slots,
            'disponible' => $hayDisponibilidad,
            'mensaje' => $mensaje,
        ];
    }

    /**
     * Usa la misma selección pública que la asignación definitiva.
     *
     * @return array{ok: bool, codigo: string, mesa_ids: array<int, int>}
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false
    ): array {
        if ($personas < 1 || $personas > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mesa_ids' => []];
        }

        $resumen = self::resumenHorario(
            $fecha,
            $hora,
            $personas,
            $excluirReservacionId,
            $bloquear,
            true
        );
        if (!($resumen['ok'] ?? false)) {
            return $resumen + [
                'ok' => false,
                'codigo' => (string)($resumen['codigo'] ?? self::SIN_DISPONIBILIDAD),
                'mesa_ids' => [],
            ];
        }
        if (count($resumen['mesa_ids']) > ReservacionConfig::MAX_PUBLIC_TABLES) {
            return ['ok' => false, 'codigo' => self::SIN_DISPONIBILIDAD, 'mesa_ids' => []];
        }

        return $resumen + [
            'ok' => true,
            'codigo' => self::DISPONIBILIDAD_CONSULTADA,
            'mesa_ids' => $resumen['mesa_ids'],
        ];
    }

    /**
     * Fuente compartida de capacidad para landing, administración y mapa.
     * La unidad es la mesa reservable única después de combinar reservaciones,
     * bloqueos y tickets abiertos.
     *
     * @return array<string, mixed>
     */
    public static function resumenHorario(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false,
        bool $asignacionPublica = false
    ): array {
        if ($personas < 1) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mesa_ids' => []];
        }

        $horario = ReservacionService::validarHorarioDisponible($fecha, $hora);
        if (!($horario['ok'] ?? false)) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'codigo_horario' => $horario['codigo'] ?? null,
                'mesa_ids' => [],
            ];
        }

        // Este filtro de dominio excluye barras, Caja, Llevar, elementos
        // inactivos, no reservables y capacidades no positivas.
        $mesas = $bloquear ? Mesa::reservablesParaActualizar() : Mesa::reservables();
        $evaluacionOcupacion = OcupacionMesasService::evaluarHorario(
            $fecha,
            (string)$horario['hora'],
            $excluirReservacionId,
            $bloquear
        );
        $capacidad = OcupacionMesasService::resumenCapacidad($mesas, $evaluacionOcupacion);
        $estimadas = array_fill_keys(
            array_map('intval', (array)($capacidad['mesa_ids_estimadas'] ?? [])),
            true
        );
        $disponibles = array_values(array_filter(
            $mesas,
            static fn($mesa): bool => isset($estimadas[(int)$mesa->id])
        ));
        usort($disponibles, static function ($a, $b): int {
            return ((int)$a->numero <=> (int)$b->numero)
                ?: ((int)$a->id <=> (int)$b->id);
        });

        $mesasProyectadas = array_map(
            'intval',
            (array)($capacidad['mesa_ids_proyectadas'] ?? [])
        );
        $seleccion = $asignacionPublica
            ? AsignacionMesasService::seleccionarMesasPublicas(
                $disponibles,
                $personas,
                $mesasProyectadas
            )
            : AsignacionMesasService::seleccionarMesasGeneral(
                $disponibles,
                $personas,
                $mesasProyectadas
            );
        $mesaIds = array_map(static fn($mesa): int => (int)$mesa->id, $seleccion);
        $dependeProyeccion = array_intersect($mesaIds, $mesasProyectadas) !== [];
        $advertencia = $dependeProyeccion
            ? 'La disponibilidad depende de que una mesa con ticket abierto se libere antes del bloqueo operativo.'
            : null;

        return $capacidad + [
            'ok' => $seleccion !== [],
            'codigo' => $seleccion !== [] ? self::DISPONIBILIDAD_CONSULTADA : self::SIN_DISPONIBILIDAD,
            'fecha' => (string)$horario['fecha'],
            'hora' => (string)$horario['hora'],
            'personas' => $personas,
            'contexto_ocupacion' => (string)($evaluacionOcupacion['contexto'] ?? ''),
            'mesa_ids' => $mesaIds,
            'mesas_proyectadas' => $mesasProyectadas,
            'depende_liberacion_proyectada' => $dependeProyeccion,
            'advertencia' => $advertencia,
            'alertas_operativas' => (array)($evaluacionOcupacion['alertas_operativas'] ?? []),
            'requiere_asignacion_manual' => $seleccion === [],
        ];
    }
}
