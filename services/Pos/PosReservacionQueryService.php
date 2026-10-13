<?php

/**
 * Lector único del contrato del mapa operativo de reservaciones.
 *
 * No asigna mesas ni cambia estados: sólo compone filas, ocupación y la
 * serialización canónica para el mapa. Conserva el nombre y el espacio
 * `Services\Pos` de cuando el punto de venta compartía este contrato.
 */

namespace Services\Pos;
use Services\Tables\MesaEstadoService;
use Services\Tables\OcupacionMesasService;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\CapacidadReservacionesService;
use Services\Reservations\ReservacionVigenciaService;
use Services\Reservations\ReservacionConfig;

use DateTimeImmutable;
use Model\Mesa;
use Model\Reservacion;
use Services\Reservations\HorarioReservacionService;
use Services\Scheduling\HorarioOperacionService;

final class PosReservacionQueryService
{
    /**
     * @return array<string, mixed>
     */
    public static function paraFecha(
        string $fecha,
        string $hora = '',
        array $opciones = []
    ): array {
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return [
                'ok' => false,
                'codigo' => 'FECHA_INVALIDA',
            ];
        }

        $ahora = $opciones['ahora'] ?? ReservacionConfig::ahora();
        if (!$ahora instanceof DateTimeImmutable) {
            $ahora = ReservacionConfig::ahora();
        }
        $horarioEfectivo = HorarioOperacionService::obtenerHorarioEfectivo($fecha);

        $incluirInactivas = (bool)($opciones['incluir_inactivas'] ?? false);
        $mesasLeidas = $incluirInactivas
            ? Mesa::buscarTodasParaMapa()
            : Mesa::consultarSQL(
                "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
                 FROM mesas
                 WHERE activo = 1
                 ORDER BY numero ASC, id ASC"
            );
        $mesas = array_map(
            static fn($mesa): array => PosReservacionSerializer::mesa($mesa),
            $mesasLeidas
        );
        // El POS dibuja sólo mesas activas, pero la decisión de inicio debe
        // poder explicar una asignación que quedó inactiva o no utilizable.
        $mesasContrato = $incluirInactivas
            ? $mesas
            : array_map(
                static fn($mesa): array => PosReservacionSerializer::mesa($mesa),
                Mesa::buscarTodasParaMapa()
            );

        $filasReservaciones = Reservacion::buscarPorDiaOperacionAdmin($fecha);

        $ocupacionPorReservacion = [];
        if (($opciones['calcular_conflictos'] ?? true) === true) {
            $ocupacionPorReservacion = AsignacionMesasService::obtenerOcupacionPorReservacionDelDia(
                $fecha,
                $filasReservaciones
            );
        }

        $horaEvaluacion = HorarioReservacionService::normalizarHoraSql($hora);
        if ($horaEvaluacion === '') {
            $horaEvaluacion = $ahora->format('H:i:s');
        }
        $excluirReservacionId = (int)($opciones['excluir_reservacion_id'] ?? 0);
        $reservacionEnEdicionId = (int)($opciones['reservacion_en_edicion_id'] ?? 0);

        $reservaciones = [];
        foreach ($filasReservaciones as $fila) {
            $reservacionId = (int)($fila->id ?? 0);
            $mesaIds = self::ids($fila->mesa_ids ?? '');
            $mesasBloqueantes = PosReservacionSerializer::bloqueosOperativos(
                $ocupacionPorReservacion[$reservacionId] ?? [],
                $mesaIds,
                $mesasContrato,
                $reservacionId
            );
            $reservacionSerializada = PosReservacionSerializer::reservacion(
                $fila,
                $mesas,
                $ahora,
                [
                    'conflicto_fisico' => $mesasBloqueantes !== [],
                    'mesas_bloqueantes' => $mesasBloqueantes,
                    'hora_consulta' => $horaEvaluacion,
                    'horario_efectivo' => $horarioEfectivo,
                    'incluir_contexto_administrativo' => !empty($opciones['incluir_contexto_administrativo']),
                ]
            );
            $aplicaHoraConsultada = self::intervaloAplica(
                $reservacionSerializada,
                $horaEvaluacion
            );
            $reservacionSerializada['aplica_hora_consultada'] = $aplicaHoraConsultada;
            $reservacionSerializada['bloqueada_en_intervalo'] = $mesasBloqueantes !== [];
            $reservacionSerializada['disponible_para_asignacion'] = $mesaIds !== []
                && $mesasBloqueantes === [];
            $reservaciones[] = $reservacionSerializada;
        }
        $superficie = strtolower(trim((string)($opciones['superficie'] ?? 'pos')));
        $reservacionesMapa = self::reservacionesParaProyeccionVisual($reservaciones, $superficie);
        $asignacionActualIds = [];
        if ($reservacionEnEdicionId > 0) {
            foreach ($reservaciones as $reservacion) {
                if ((int)($reservacion['id'] ?? 0) === $reservacionEnEdicionId) {
                    $asignacionActualIds = self::ids($reservacion['mesa_ids'] ?? []);
                    break;
                }
            }
        }
        $evaluacionOcupacion = [];
        $evaluacionOcupacionAsignacion = [];
        try {
            $superficie = strtolower(trim((string)($opciones['superficie'] ?? 'pos')));
            $contextoOcupacion = OcupacionMesasService::prepararContextoFecha(
                $fecha,
                false,
                $ahora,
                0,
                false,
                $superficie === 'admin'
            );
            $evaluacionOcupacion = OcupacionMesasService::evaluarHorarioConContexto(
                $contextoOcupacion,
                $horaEvaluacion,
                0,
                $ahora
            );
            $evaluacionOcupacionAsignacion = $excluirReservacionId > 0
                ? OcupacionMesasService::evaluarHorarioConContexto(
                    $contextoOcupacion,
                    $horaEvaluacion,
                    $excluirReservacionId,
                    $ahora
                )
                : $evaluacionOcupacion;
        } catch (\Throwable $error) {
            // La lectura del contrato sigue siendo útil aunque el horario no
            // pueda evaluarse; el consumidor recibe una lista vacía de estados.
            $evaluacionOcupacion = [
                'ok' => false,
                'contexto' => OcupacionMesasService::CONTEXTO_HISTORICO,
                'ocupacion_bloqueante' => [],
                'ocupacion_reservaciones' => [],
            ];
            $evaluacionOcupacionAsignacion = $evaluacionOcupacion;
        }

        $mesasEstado = MesaEstadoService::normalizarMesas(
            $mesas,
            $reservacionesMapa,
            $fecha,
            $ahora,
            $horaEvaluacion,
            $evaluacionOcupacion,
            [
                'reservacion_en_edicion_id' => $reservacionEnEdicionId,
                'current_assignment_ids' => $asignacionActualIds,
                'evaluacion_ocupacion_asignacion' => $evaluacionOcupacionAsignacion,
            ]
        );
        $capacidadHorario = OcupacionMesasService::resumenCapacidad(
            Mesa::reservables(),
            $evaluacionOcupacion
        );
        CapacidadReservacionesService::registrarEvaluacion(
            $capacidadHorario + [
                'fecha' => $fecha,
                'hora' => $horaEvaluacion,
            ],
            'pos',
            0,
            null,
            'consulta_pos'
        );

        $config = ReservacionConfig::configuracionOperacion();
        $config['server_time'] = $ahora->format(DATE_ATOM);
        $config['timezone'] = $ahora->getTimezone()->getName();

        $reservacionesOperativas = array_values(array_filter(
            ReservacionVigenciaService::filtrarPendientesOperacion(
                array_values(array_filter(
                    $reservaciones,
                    static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') === 'confirmada'
                )),
                $fecha,
                []
            ),
            static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') !== 'reemplazada'
        ));

        $respuesta = [
            'ok' => true,
            'codigo' => 'OK',
            'schema_version' => PosReservacionSerializer::SCHEMA_VERSION,
            'fecha' => $fecha,
            'hora' => $horaEvaluacion,
            'mesas' => $mesas,
            'mesas_estado' => $mesasEstado,
            'reservaciones' => $reservaciones,
            'reservaciones_operativas' => $reservacionesOperativas,
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'evaluacion_ocupacion' => $evaluacionOcupacion,
            'capacidad_horario' => $capacidadHorario,
            'server_time' => $ahora->format(DATE_ATOM),
            'timezone' => $ahora->getTimezone()->getName(),
            'config' => [
                'temporal' => $config,
            ],
            'actualizado_en' => $ahora->format(DATE_ATOM),
        ];

        return $respuesta;
    }

    /**
     * Clasifica las reservaciones que alimentan el presenter de cada mapa.
     * En el mapa administrativo, las fuera de horario siguen en los cálculos
     * de ocupación/conflictos y en las listas, pero no alteran por sí solas la
     * proyección de color. ReservacionMapaAdministrativaService usa el mismo
     * hecho serializado para marcar `en_proyeccion_mapa`.
     *
     * @param array<int, array<string, mixed>> $reservaciones
     * @return array<int, array<string, mixed>>
     */
    public static function reservacionesParaProyeccionVisual(array $reservaciones, string $superficie): array
    {
        $mapaAdministrativo = in_array(strtolower(trim($superficie)), ['admin', 'waiter'], true);

        return array_values(array_filter(
            $reservaciones,
            static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') === 'confirmada'
                && (!empty($reservacion['aplica_hora_consultada'])
                    || !empty($reservacion['proyeccion_mapa']['reservacion_cercana_mapa'])
                    || ($mapaAdministrativo
                        && (string)($reservacion['proyeccion_mapa']['ventana_mapa'] ?? '') === 'irrelevante'))
                && (!$mapaAdministrativo || empty($reservacion['fuera_horario_operacion']))
        ));
    }

    private static function intervaloAplica(array $reservacion, string $horaConsultada): bool
    {
        $fecha = (string)($reservacion['fecha'] ?? '');
        $horaReserva = HorarioReservacionService::normalizarHoraSql((string)($reservacion['hora'] ?? ''));
        $horaMapa = HorarioReservacionService::normalizarHoraSql($horaConsultada);
        if ($fecha === '' || $horaReserva === '' || $horaMapa === '') {
            return false;
        }

        $inicioReserva = new DateTimeImmutable($fecha . ' ' . $horaReserva, ReservacionConfig::timezone());
        $inicioMapa = new DateTimeImmutable($fecha . ' ' . $horaMapa, ReservacionConfig::timezone());

        return OcupacionMesasService::intervalosSeTraslapan($inicioReserva, $inicioMapa);
    }

    /** @return array<int, int> */
    private static function ids($valor): array
    {
        if (is_string($valor)) {
            $valor = explode(',', $valor);
        }
        if (!is_array($valor)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $valor))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

}
