<?php

/**
 * Orquestador canónico: horario -> intervalo -> ocupación -> asignación.
 */

namespace Services;

use DateTimeImmutable;
use Model\Mesa;

final class DisponibilidadReservacionService
{
    public const DISPONIBILIDAD_CONSULTADA = 'DISPONIBILIDAD_CONSULTADA';
    public const SIN_DISPONIBILIDAD = 'SIN_DISPONIBILIDAD';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /** Fachada puntual: binaria y sin capacidad, IDs ni motivos internos. */
    public static function respuestaPublica(array $resultado): array
    {
        if (($resultado['disponible'] ?? false) === true) {
            return ['disponible' => true];
        }

        $motivo = self::motivoPublico((string)($resultado['motivo'] ?? self::SIN_DISPONIBILIDAD));
        return [
            'disponible' => false,
            'motivo' => $motivo,
        ];
    }

    /** Respuesta pública de consulta por fecha/hora, también sin detalle interno. */
    private static function respuestaConsultaPublica(array $resultado): array
    {
        $motivo = self::motivoPublico((string)($resultado['motivo'] ?? ''));
        $respuesta = [
            'ok' => (bool)($resultado['ok'] ?? true),
            'fecha' => (string)($resultado['fecha'] ?? ''),
            'abierto' => (bool)($resultado['abierto'] ?? false),
            'horarios' => array_values(array_map(
                static fn(array $slot): array => [
                    'hora' => substr((string)($slot['hora'] ?? ''), 0, 5),
                    'disponible' => (bool)($slot['disponible'] ?? false),
                ],
                array_filter((array)($resultado['horarios'] ?? []), 'is_array')
            )),
            'disponible' => (bool)($resultado['disponible'] ?? false),
            'motivo' => $motivo,
            'alternativas' => array_values(array_slice(
                array_map(static fn($hora): string => substr((string)$hora, 0, 5), (array)($resultado['alternativas'] ?? [])),
                0,
                ReservacionConfig::MAX_HORARIOS_ALTERNATIVOS
            )),
        ];

        if (!empty($resultado['hora'])) {
            $respuesta['hora'] = substr((string)$resultado['hora'], 0, 5);
        }

        if (array_key_exists('detalle_horario', $resultado)) {
            $detalle = (array)($resultado['detalle_horario'] ?? []);
            $respuesta['detalle_horario'] = [
                'es_excepcion' => (bool)($detalle['es_excepcion'] ?? false),
                'tipo' => $detalle['tipo'] ?? null,
                'motivo' => $detalle['motivo'] ?? null,
            ];
        }

        if (!($respuesta['disponible'] ?? false) && $motivo === 'sin_disponibilidad') {
            $respuesta['mensaje'] = 'No encontramos disponibilidad para esa selección.';
        }

        return $respuesta;
    }

    /**
     * Evalúa un horario puntual con el mismo núcleo para consumidores internos.
     *
     * @return array<string, mixed>
     */
    public static function consultarUna(
        string $fecha,
        string $hora,
        $comensales,
        int $excluirReservacionId = 0,
        ?DateTimeImmutable $ahora = null
    ): array {
        return self::evaluarSolicitud(
            $fecha,
            $hora,
            $comensales,
            ReservacionConfig::MAX_COMENSALES_ADMIN,
            $excluirReservacionId,
            false,
            $ahora
        );
    }

    /** Alias nominal para consumidores del núcleo. */
    public static function evaluarDisponibilidad(
        string $fecha,
        string $hora,
        $comensales,
        int $excluirReservacionId = 0,
        ?DateTimeImmutable $ahora = null
    ): array {
        return self::consultarUna($fecha, $hora, $comensales, $excluirReservacionId, $ahora);
    }

    /** Fachada interna explícita para evitar que la API pública reciba detalle. */
    public static function consultarInterna(
        string $fecha,
        string $hora,
        $comensales,
        int $excluirReservacionId = 0,
        ?DateTimeImmutable $ahora = null
    ): array {
        return self::consultarUna($fecha, $hora, $comensales, $excluirReservacionId, $ahora);
    }

    /** Compatibilidad de dominio para mutaciones ya existentes. */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false,
        bool $asignacionPublica = false
    ): array {
        $resultado = self::evaluarSolicitud(
            $fecha,
            $hora,
            $personas,
            $asignacionPublica ? ReservacionConfig::MAX_PUBLIC_GUESTS : ReservacionConfig::MAX_COMENSALES_ADMIN,
            $excluirReservacionId,
            $asignacionPublica,
            null,
            $bloquear
        );
        return $resultado + [
            'ok' => (bool)($resultado['disponible'] ?? false),
            'codigo' => ($resultado['disponible'] ?? false) ? self::DISPONIBILIDAD_CONSULTADA : self::SIN_DISPONIBILIDAD,
        ];
    }

    /** Resumen interno utilizado por creación y reasignación. */
    public static function resumenHorario(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false,
        bool $asignacionPublica = false
    ): array {
        $resultado = self::evaluarHorario($fecha, $hora, $personas, $excluirReservacionId, $bloquear, $asignacionPublica);
        $ocupacion = (array)($resultado['ocupacion'] ?? []);
        $capacidad = OcupacionMesasService::resumenCapacidad(Mesa::reservables(), $ocupacion);
        return $resultado + [
            'capacidad_disponible' => $capacidad['capacidad_estimada_horario'],
            'capacidad_realmente_libre' => $capacidad['capacidad_realmente_libre'],
            'capacidad_proyectada' => $capacidad['capacidad_proyectada'],
            'capacidad_estimada_horario' => $capacidad['capacidad_estimada_horario'],
            'mesas_proyectadas' => $ocupacion['mesa_ids_proyectadas'] ?? [],
            'depende_liberacion_proyectada' => $resultado['depende_liberacion_proyectada'] ?? false,
        ];
    }

    /**
     * Consulta pública por todos los horarios candidatos. No devuelve detalles
     * de capacidad, mesas, tickets ni reservaciones.
     */
    public static function consultar(
        string $fecha,
        $personas,
        int $excluirReservacionId = 0,
        ?string $hora = null
    ): array {
        return self::consultarSlots(
            $fecha,
            $personas,
            ReservacionConfig::MAX_PUBLIC_GUESTS,
            max(0, $excluirReservacionId),
            true,
            null,
            $hora
        );
    }

    /** Consulta interna administrativa con el mismo motor de ocupación. */
    public static function consultarAdministrativa(
        string $fecha,
        $personas,
        int $excluirReservacionId = 0,
        ?string $hora = null
    ): array {
        return ReservacionAdministrativaService::consultarDisponibilidad(
            $fecha,
            $personas,
            max(0, $excluirReservacionId),
            $hora
        );
    }

    /** @return array<string, mixed> */
    private static function consultarSlots(
        string $fecha,
        $personas,
        int $maximoPersonas,
        int $excluirReservacionId,
        bool $publico,
        ?DateTimeImmutable $ahora,
        ?string $horaSolicitada = null
    ): array {
        $fecha = trim($fecha);
        $personasValidas = filter_var($personas, FILTER_VALIDATE_INT);
        if ($personasValidas === false || $personasValidas < 1 || $personasValidas > $maximoPersonas) {
            $invalida = [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'disponible' => false,
                'motivo' => $publico
                    && $personasValidas !== false
                    && $personasValidas > ReservacionConfig::MAX_PUBLIC_GUESTS
                    ? 'requiere_contactar_restaurante'
                    : 'comensales_invalidos',
                'fecha' => trim($fecha),
                'personas' => $personasValidas === false ? null : (int)$personasValidas,
                'horarios' => [],
                'alternativas' => [],
            ];
            return $publico ? self::respuestaConsultaPublica($invalida) : $invalida;
        }

        $calendario = HorarioReservacionService::resolverFecha($fecha, $ahora);
        $base = [
            'ok' => !in_array($calendario['codigo'] ?? '', [
                HorarioReservacionService::FECHA_INVALIDA,
                HorarioReservacionService::FECHA_PASADA,
                'FECHA_FUERA_DE_HORIZONTE',
                HorarioReservacionService::ERROR_INTERNO,
            ], true),
            'codigo' => $calendario['codigo'] ?? self::ERROR_INTERNO,
            'fecha' => $calendario['fecha'] ?? trim($fecha),
            'personas' => (int)$personasValidas,
            'hora' => null,
            'abierto' => (bool)($calendario['abierto'] ?? false),
            'reservable' => (bool)($calendario['reservable'] ?? false),
            'motivo' => $calendario['motivo_no_disponible'] ?? null,
            'horarios' => [],
            'disponible' => false,
            'alternativas' => [],
            'detalle_horario' => $calendario['detalle_horario'] ?? null,
        ];
        if (!$base['ok'] || !$base['reservable']) {
            return $publico ? self::respuestaConsultaPublica($base) : $base;
        }

        $slots = [];
        $alternativas = [];
        foreach ($calendario['horarios'] as $hora) {
            $evaluacion = self::evaluarSolicitud(
                (string)$fecha,
                (string)$hora,
                (int)$personasValidas,
                $maximoPersonas,
                $excluirReservacionId,
                $publico,
                $ahora
            );
            $disponible = (bool)($evaluacion['disponible'] ?? false);
            $horaCorta = substr((string)$hora, 0, 5);
            $slots[] = [
                'hora' => $horaCorta,
                'disponible' => $disponible,
            ];
            if ($disponible && count($alternativas) < ReservacionConfig::MAX_HORARIOS_ALTERNATIVOS) {
                $alternativas[] = $horaCorta;
            }
        }

        $base['horarios'] = $slots;
        $disponibles = array_values(array_filter($slots, static fn(array $slot): bool => $slot['disponible']));
        $horaSolicitada = $horaSolicitada !== null
            ? HorarioReservacionService::normalizarHoraCorta($horaSolicitada)
            : '';
        $base['hora'] = $horaSolicitada !== '' ? $horaSolicitada : null;
        $slotSolicitado = null;
        if ($horaSolicitada !== '') {
            foreach ($slots as $slot) {
                if ($slot['hora'] === $horaSolicitada) {
                    $slotSolicitado = $slot;
                    break;
                }
            }
        }
        $base['disponible'] = $horaSolicitada !== ''
            ? ($slotSolicitado !== null && (bool)$slotSolicitado['disponible'])
            : $disponibles !== [];
        $base['motivo'] = $base['disponible'] ? 'disponible' : 'sin_combinacion_fisica';
        $base['alternativas'] = $horaSolicitada !== '' && !($slotSolicitado['disponible'] ?? false)
            ? $alternativas
            : [];

        if ($publico) {
            return self::respuestaConsultaPublica($base);
        }

        $base['horarios_alternativos'] = $alternativas;
        return self::agregarDetallesAdministrativos($base, $fecha, $personasValidas, $excluirReservacionId, $ahora);
    }

    private static function motivoPublico(string $motivo): string
    {
        return match ($motivo) {
            'disponible' => 'disponible',
            'requiere_asignacion_manual', 'requiere_contactar_restaurante' => 'requiere_contactar_restaurante',
            default => 'sin_disponibilidad',
        };
    }

    /** @return array<string, mixed> */
    private static function evaluarSolicitud(
        string $fecha,
        string $hora,
        $personas,
        int $maximoPersonas,
        int $excluirReservacionId,
        bool $asignacionPublica,
        ?DateTimeImmutable $ahora,
        bool $bloquear = false
    ): array {
        $personasValidas = filter_var($personas, FILTER_VALIDATE_INT);
        $horaValidada = HorarioReservacionService::validarHora($fecha, $hora, $ahora);
        $base = [
            'disponible' => false,
            'motivo' => null,
            'fecha' => $fecha,
            'hora' => HorarioReservacionService::normalizarHoraCorta($hora),
            'comensales' => $personasValidas === false ? null : (int)$personasValidas,
            'mesa_ids' => [],
            'tipo_combinacion' => null,
            'capacidad_total' => 0,
            'requiere_asignacion_manual' => false,
            'asignacion_automatica' => false,
        ];

        if ($personasValidas === false || $personasValidas < 1 || $personasValidas > $maximoPersonas) {
            $base['motivo'] = 'comensales_invalidos';
            return $base;
        }
        if ($personasValidas > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            $base['motivo'] = $asignacionPublica
                ? 'requiere_contactar_restaurante'
                : 'requiere_asignacion_manual';
            $base['requiere_asignacion_manual'] = true;
            return $base;
        }
        if (!($horaValidada['ok'] ?? false)) {
            $base['motivo'] = $horaValidada['motivo_no_disponible']
                ?? self::motivoHorario($horaValidada['codigo'] ?? '');
            return $base + ['codigo_horario' => $horaValidada['codigo'] ?? null];
        }

        $ocupacion = OcupacionMesasService::evaluarHorario(
            $fecha,
            (string)$horaValidada['hora'],
            $excluirReservacionId,
            $bloquear,
            null,
            $ahora
        );
        $mesas = Mesa::reservables();
        $disponibles = array_values(array_filter(
            $mesas,
            static fn($mesa): bool => !empty($ocupacion['mesas'][(int)$mesa->id]['disponible'])
        ));
        usort($disponibles, static fn($a, $b): int => ((int)$a->numero <=> (int)$b->numero) ?: ((int)$a->id <=> (int)$b->id));

        $seleccion = $asignacionPublica
            ? AsignacionMesasService::seleccionarMesasPublicas($disponibles, (int)$personasValidas, (array)($ocupacion['mesa_ids_proyectadas'] ?? []))
            : AsignacionMesasService::seleccionarMesasGeneral($disponibles, (int)$personasValidas, (array)($ocupacion['mesa_ids_proyectadas'] ?? []));
        if ($seleccion === []) {
            $base['motivo'] = 'sin_combinacion_fisica';
            $base['ocupacion'] = $ocupacion;
            return $base;
        }

        $mesaIds = array_map(static fn($mesa): int => (int)$mesa->id, $seleccion);
        $capacidad = array_sum(array_map(static fn($mesa): int => (int)$mesa->capacidad, $seleccion));
        $base['disponible'] = true;
        $base['motivo'] = 'disponible';
        $base['mesa_ids'] = $mesaIds;
        $base['capacidad_total'] = $capacidad;
        $base['tipo_combinacion'] = count($mesaIds) === 1
            ? 'mesa_individual'
            : (count($mesaIds) === 2 ? 'par_predefinido' : 'trio_predefinido');
        $base['asignacion_automatica'] = true;
        $base['ocupacion'] = $ocupacion;
        $base['depende_liberacion_proyectada'] = array_intersect($mesaIds, (array)($ocupacion['mesa_ids_proyectadas'] ?? [])) !== [];
        return $base;
    }

    /** @return array<string, mixed> */
    private static function agregarDetallesAdministrativos(
        array $base,
        string $fecha,
        int $personas,
        int $excluirReservacionId,
        ?DateTimeImmutable $ahora
    ): array {
        $detalles = [];
        foreach ($base['horarios'] as $slot) {
            $evaluacion = self::evaluarSolicitud($fecha, $slot['hora'], $personas, ReservacionConfig::MAX_COMENSALES_ADMIN, $excluirReservacionId, false, $ahora);
            $ocupacion = (array)($evaluacion['ocupacion'] ?? []);
            $capacidad = OcupacionMesasService::resumenCapacidad(Mesa::reservables(), $ocupacion);
            $detalles[$slot['hora']] = [
                'disponible' => (bool)($evaluacion['disponible'] ?? false),
                'mesa_ids' => $evaluacion['mesa_ids'] ?? [],
                'capacidad_total' => $capacidad['capacidad_total'],
                'capacidad_realmente_libre' => $capacidad['capacidad_realmente_libre'],
                'capacidad_proyectada' => $capacidad['capacidad_proyectada'],
                'capacidad_estimada_horario' => $capacidad['capacidad_estimada_horario'],
                'depende_liberacion_proyectada' => $capacidad['depende_liberacion_proyectada'],
                'tipo_combinacion' => $evaluacion['tipo_combinacion'] ?? null,
            ];
        }
        $base['detalle_horarios'] = $detalles;
        return $base;
    }

    private static function motivoHorario(string $codigo): string
    {
        return match ($codigo) {
            HorarioReservacionService::FECHA_PASADA => 'fecha_pasada',
            'FECHA_FUERA_DE_HORIZONTE' => 'fecha_fuera_de_horizonte',
            HorarioReservacionService::DIA_INACTIVO => 'dia_no_operativo',
            HorarioReservacionService::HORARIO_PASADO => 'anticipacion_insuficiente',
            default => 'horario_fuera_de_operacion',
        };
    }
}
