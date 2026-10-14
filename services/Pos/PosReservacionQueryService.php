<?php

/**
 * Lector único del contrato POS–reservaciones.
 *
 * Este servicio concentra las lecturas que antes se repetían en POS y en el
 * panel de operación. No asigna mesas ni cambia estados: sólo compone filas,
 * ocupación física y la serialización canónica para sus consumidores.
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
use Model\TicketMesa;
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
                "SELECT id, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable
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
        $ticketsLeidos = TicketMesa::abiertosParaMapa();
        $tickets = array_map(
            static fn(array $ticket): array => PosReservacionSerializer::ticket($ticket),
            $ticketsLeidos
        );
        $ticketsPorReservacion = [];
        foreach ($tickets as $ticket) {
            $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
            if ($reservacionId > 0) {
                $ticketsPorReservacion[$reservacionId] = $ticket;
            }
        }

        $ocupacionPorReservacion = [];
        if (($opciones['calcular_conflictos'] ?? true) === true) {
            $ocupacionPorReservacion = AsignacionMesasService::obtenerOcupacionPorReservacionDelDia(
                $fecha,
                $filasReservaciones,
                $ticketsLeidos
            );
        }

        // El POS declara su contexto (ahora o proyección) y aquí se resuelve;
        // el resto de consumidores conserva el comportamiento de siempre: hora
        // dada o reloj, sin modo.
        $contextoPos = !empty($opciones['contexto_pos']);
        $reservacionEnfocadaId = (int)($opciones['reservacion_enfocada_id'] ?? 0);
        if ($contextoPos && $reservacionEnfocadaId > 0
            && HorarioReservacionService::normalizarHoraSql($hora) === '') {
            // Tocar una reserva en el cajón (o llegar por un enlace a ella) sin
            // hora explícita proyecta a SU hora: es lo que se quiere ver.
            foreach ($filasReservaciones as $fila) {
                if ((int)($fila->id ?? 0) === $reservacionEnfocadaId) {
                    $hora = (string)($fila->hora ?? '');
                    break;
                }
            }
        }
        $contexto = $contextoPos
            ? self::resolverContexto($fecha, $hora, $ahora, $filasReservaciones, $horarioEfectivo)
            : null;
        if ($contexto !== null && $reservacionEnfocadaId > 0) {
            $contexto['reservacion_id'] = $reservacionEnfocadaId;
            $contexto['intencion'] = (string)($opciones['intencion'] ?? '') === 'asignar' ? 'asignar' : 'ver';
        }
        $horaEvaluacion = $contexto !== null
            ? $contexto['hora']
            : HorarioReservacionService::normalizarHoraSql($hora);
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
                $ticketsPorReservacion[$reservacionId] ?? null,
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
        // Insignia «Reubicada de Mesa 9 a Mesa 3»: la última reubicación de
        // cada reserva, en una sola consulta. `reubicada` sólo mientras nadie la
        // haya marcado como revisada; después queda el rastro, sin insignia.
        if ($contextoPos && $reservaciones !== []) {
            try {
                $reubicaciones = \Model\ReservacionReubicacion::ultimasPorReservacion(
                    array_map(static fn(array $r): int => (int)($r['id'] ?? 0), $reservaciones)
                );
            } catch (\Throwable $error) {
                // Sin la tabla (base sin migrar) el POS sigue operando sin insignia.
                error_log('PosReservacionQueryService::reubicaciones - ' . $error->getMessage());
                $reubicaciones = [];
            }
            foreach ($reservaciones as &$reservacionConReubicacion) {
                $reubicacion = $reubicaciones[(int)($reservacionConReubicacion['id'] ?? 0)] ?? null;
                $reservacionConReubicacion['reubicacion'] = $reubicacion;
                $reservacionConReubicacion['reubicada'] = $reubicacion !== null && empty($reubicacion['revisada']);
            }
            unset($reservacionConReubicacion);
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
                $ticketsLeidos,
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
                'tickets_por_mesa' => [],
                'fisica' => [],
                'alertas_operativas' => [],
            ];
            $evaluacionOcupacionAsignacion = $evaluacionOcupacion;
        }

        $reservacionesParaAdvertencias = array_values(array_filter(
            $reservaciones,
            static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') === 'confirmada'
                && !empty($reservacion['muestra_advertencia'])
        ));
        $tickets = self::adjuntarAdvertencias($tickets, $reservacionesParaAdvertencias);
        $mesasEstado = MesaEstadoService::normalizarMesas(
            $mesas,
            $reservacionesMapa,
            $tickets,
            $fecha,
            $ahora,
            $horaEvaluacion,
            $evaluacionOcupacion,
            [
                'reservacion_en_edicion_id' => $reservacionEnEdicionId,
                'current_assignment_ids' => $asignacionActualIds,
                'evaluacion_ocupacion_asignacion' => $evaluacionOcupacionAsignacion,
                'modo' => $contexto['modo'] ?? 'ahora',
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
            'tickets' => $tickets,
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'evaluacion_ocupacion' => $evaluacionOcupacion,
            'capacidad_horario' => $capacidadHorario,
            'contexto' => $contexto ?? [
                'modo' => 'ahora',
                'fecha' => $fecha,
                'hora' => $horaEvaluacion,
                'solo_lectura' => false,
            ],
            'horario' => self::resumenHorario($horarioEfectivo, $reservaciones, $fecha, $ahora),
            'server_time' => $ahora->format(DATE_ATOM),
            'timezone' => $ahora->getTimezone()->getName(),
            'config' => [
                'temporal' => $config,
            ],
            'actualizado_en' => $ahora->format(DATE_ATOM),
        ];

        return strtolower((string)($opciones['superficie'] ?? 'waiter')) === 'waiter'
            ? PosReservacionSerializer::sanitizarParaWaiter($respuesta)
            : $respuesta;
    }

    /**
     * Clasifica las reservaciones que alimentan el presenter del mapa. Las
     * fuera de horario siguen en los cálculos de ocupación/conflictos y en la
     * lista del día (el cajón y la ficha las señalan), pero no alteran por sí
     * solas el color del pin.
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

    /**
     * @param array<int, array<string, mixed>> $tickets
     * @param array<int, array<string, mixed>> $reservaciones
     * @return array<int, array<string, mixed>>
     */
    private static function adjuntarAdvertencias(array $tickets, array $reservaciones): array
    {
        foreach ($tickets as &$ticket) {
            $mesaIdsTicket = self::ids($ticket['mesa_ids'] ?? []);
            $proximas = [];
            foreach ($reservaciones as $reservacion) {
                if (
                    empty($reservacion['muestra_advertencia'])
                    || !self::intersecta($mesaIdsTicket, self::ids($reservacion['mesa_ids'] ?? []))
                ) {
                    continue;
                }
                $proximas[] = [
                    'schema_version' => PosReservacionSerializer::SCHEMA_VERSION,
                    'reservacion_id' => (int)$reservacion['reservacion_id'],
                    'id' => (int)$reservacion['reservacion_id'],
                    'hora' => (string)$reservacion['hora'],
                    'nombre' => (string)$reservacion['nombre'],
                    'mesa_ids' => $reservacion['mesa_ids'],
                    'ventana_operativa' => $reservacion['ventana_operativa'],
                    'minutos_para_reservacion' => $reservacion['minutos_para_reservacion'],
                    'muestra_advertencia' => true,
                ];
            }
            $ticket['reservaciones_proximas'] = $proximas;
            $ticket['muestra_advertencia'] = $proximas !== [];
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * Contexto de la lectura del POS.
     *
     * - Hoy y sin hora: AHORA, la operación en vivo. Es lo único que se opera.
     * - Con hora: PROYECCIÓN a esa hora, lanzada desde el cajón al tocar una
     *   reserva o un horario. Sólo lectura: lo que se ve es cómo estará el
     *   salón, no lo que se puede hacer ya.
     * - Otro día sin hora: no existe «ahora», así que se proyecta al primer
     *   horario con reservas confirmadas o, si no hay, a la apertura.
     *
     * @param array<int, object|array<string, mixed>> $filasReservaciones
     * @param array<string, mixed> $horarioEfectivo
     * @return array{modo:string, fecha:string, hora:string, solo_lectura:bool}
     */
    private static function resolverContexto(
        string $fecha,
        string $hora,
        DateTimeImmutable $ahora,
        array $filasReservaciones,
        array $horarioEfectivo
    ): array {
        $horaPedida = HorarioReservacionService::normalizarHoraSql($hora);
        $esHoy = $fecha === $ahora->format('Y-m-d');
        if ($horaPedida === '' && $esHoy) {
            return [
                'modo' => 'ahora',
                'fecha' => $fecha,
                'hora' => $ahora->format('H:i:s'),
                'solo_lectura' => false,
            ];
        }

        if ($horaPedida === '') {
            $horas = [];
            foreach ($filasReservaciones as $fila) {
                $datos = is_array($fila) ? $fila : get_object_vars($fila);
                if ((string)($datos['estado'] ?? '') !== 'confirmada') {
                    continue;
                }
                $horaReserva = HorarioReservacionService::normalizarHoraSql((string)($datos['hora'] ?? ''));
                if ($horaReserva !== '') {
                    $horas[] = $horaReserva;
                }
            }
            sort($horas);
            $horaPedida = $horas[0]
                ?? (string)($horarioEfectivo['hora_apertura'] ?? '')
                ?: '12:00:00';
        }

        return [
            'modo' => 'proyeccion',
            'fecha' => $fecha,
            'hora' => $horaPedida,
            'solo_lectura' => true,
        ];
    }

    /**
     * Lo que la barra del mapa necesita del horario: si se puede operar el día
     * y cuál es la siguiente llegada. `sin_configurar` es el caso que hay que
     * gritar: sin horario no se ofrece ninguna reserva y nadie se entera.
     *
     * @param array<string, mixed> $horarioEfectivo
     * @param array<int, array<string, mixed>> $reservaciones
     * @return array<string, mixed>
     */
    private static function resumenHorario(
        array $horarioEfectivo,
        array $reservaciones,
        string $fecha,
        DateTimeImmutable $ahora
    ): array {
        $codigo = !empty($horarioEfectivo['abierto'])
            ? 'abierto'
            : (array_key_exists('configurado', $horarioEfectivo) && empty($horarioEfectivo['configurado'])
                ? 'sin_configurar'
                : 'cerrado');

        // La próxima llegada se cuenta desde el reloj en el día de hoy y desde
        // el inicio del día en cualquier otro: es «quién es el siguiente».
        $desde = $fecha === $ahora->format('Y-m-d') ? $ahora->format('H:i:s') : '00:00:00';
        $proxima = null;
        foreach ($reservaciones as $reservacion) {
            if ((string)($reservacion['estado'] ?? '') !== 'confirmada' || !empty($reservacion['ticket_abierto'])) {
                continue;
            }
            $horaReserva = HorarioReservacionService::normalizarHoraSql((string)($reservacion['hora'] ?? ''));
            if ($horaReserva === '' || $horaReserva < $desde) {
                continue;
            }
            if ($proxima === null || $horaReserva < $proxima['hora']) {
                $proxima = [
                    'reservacion_id' => (int)($reservacion['id'] ?? 0),
                    'hora' => $horaReserva,
                    'comensales' => (int)($reservacion['comensales'] ?? 0),
                    'mesa_ids' => self::ids($reservacion['mesa_ids'] ?? []),
                ];
            }
        }

        return [
            'codigo' => $codigo,
            'hora_apertura' => $horarioEfectivo['hora_apertura'] ?? null,
            'hora_cierre' => $horarioEfectivo['hora_cierre'] ?? null,
            'proxima_llegada' => $proxima,
        ];
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

    /** @param array<int, int> $a @param array<int, int> $b */
    private static function intersecta(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }
}
