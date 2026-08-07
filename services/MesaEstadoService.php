<?php

/**
 * Construye el contrato operativo común de las mesas.
 *
 * La clase decide estados y precedencia; MapaVisual sólo recibe el resultado
 * listo para dibujar. Esto mantiene la misma lectura en POS y reservaciones.
 */

namespace Services;

use DateTimeImmutable;

final class MesaEstadoService
{
    public const DISPONIBLE = 'disponible';
    public const OCUPADA = 'ocupada';
    public const BLOQUEADA = 'bloqueada';
    public const NO_RESERVABLE = 'no_reservable';

    /**
     * @param array<int, array<string, mixed>|object> $mesas
     * @param array<int, array<string, mixed>|object> $reservaciones
     * @param array<int, array<string, mixed>|object> $tickets
     * @return array<int, array<string, mixed>>
     */
    public static function normalizarMesas(
        array $mesas,
        array $reservaciones,
        array $tickets,
        string $fecha,
        ?DateTimeImmutable $ahora = null,
        string $hora = '',
        array $evaluacionOcupacion = []
    ): array {
        return self::normalizarMesasCanonicas(
            $mesas,
            $reservaciones,
            $tickets,
            $fecha,
            $ahora,
            $hora,
            $evaluacionOcupacion
        );

    }

    /**
     * Consume exclusivamente reservaciones ya serializadas por el contrato
     * POS–reservaciones. Aquí sólo se resuelve precedencia visual; no se
     * vuelve a calcular fecha, hora ni ventana operativa.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizarMesasCanonicas(
        array $mesas,
        array $reservaciones,
        array $tickets,
        string $fecha,
        ?DateTimeImmutable $ahora,
        string $hora,
        array $evaluacionOcupacion
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $ticketsPorMesa = self::ticketsPorMesa(
            $tickets,
            $fecha,
            $hora !== '' ? $hora : ReservacionConfig::horaActual(),
            $ahora,
            $evaluacionOcupacion
        );
        // Un ticket abierto ocupa físicamente sus ticket_mesa_ids aunque el
        // horario consultado sea una proyección o su duración estimada haya
        // terminado. La proyección sólo modifica los modificadores.
        foreach ($tickets as $ticket) {
            $proyeccion = TicketTemporalService::proyectar($ticket, $fecha, $hora, $ahora);
            if (!$proyeccion['ticket_abierto'] || !$proyeccion['aplica_fecha']) {
                continue;
            }
            foreach (self::ids($proyeccion['mesa_ids'] ?? []) as $mesaId) {
                if (!isset($ticketsPorMesa[$mesaId])) {
                    $ticketsPorMesa[$mesaId] = $proyeccion;
                }
            }
        }
        $reservacionesPorMesa = [];
        foreach ($reservaciones as $reservacionRaw) {
            $reservacion = self::aArray($reservacionRaw);
            if ((string)($reservacion['estado'] ?? '') !== 'confirmada') {
                continue;
            }
            $ventana = (string)($reservacion['ventana_operativa'] ?? 'futura');
            $visible = !empty($reservacion['muestra_advertencia'])
                || !empty($reservacion['bloquea_walk_ins']);
            if (!$visible) {
                continue;
            }
            foreach (self::ids($reservacion['mesa_ids'] ?? []) as $mesaId) {
                $reservacionesPorMesa[$mesaId][] = $reservacion;
            }
        }

        foreach ($reservacionesPorMesa as &$candidatas) {
            usort($candidatas, static function (array $a, array $b): int {
                return strcmp(
                    (string)($a['fecha'] ?? '') . ' ' . (string)($a['hora'] ?? ''),
                    (string)($b['fecha'] ?? '') . ' ' . (string)($b['hora'] ?? '')
                );
            });
        }
        unset($candidatas);

        $ocupacionPorMesa = (array)($evaluacionOcupacion['mesas'] ?? []);

        return array_map(static function ($mesa) use ($reservacionesPorMesa, $ticketsPorMesa, $ocupacionPorMesa): array {
            $mesaId = (int)self::valor($mesa, 'id', 0);
            $activada = self::booleano(self::valor($mesa, 'activo', true));
            $reservable = self::booleano(self::valor($mesa, 'reservable', true));
            $estadoBase = self::DISPONIBLE;
            $modificadores = [];
            $reservacionProxima = null;
            $reservacionAsociada = null;
            $reservacionContrato = null;
            $ticketAbierto = null;
            $ticketBloqueaEnConsulta = false;
            $ocupacionActual = false;
            $walkIn = false;
            $minutosRestantes = null;
            $motivoBloqueo = null;
            $ausenciaPendiente = false;
            $reservacionesVisualesMapa = [];
            $ocupacionMesa = (array)($ocupacionPorMesa[$mesaId] ?? []);
            $holdVigente = ($ocupacionMesa['fuente'] ?? '') === 'hold';

            if (!$activada || !$reservable) {
                $estadoBase = self::NO_RESERVABLE;
                $motivoBloqueo = !$activada ? 'Mesa fuera de servicio.' : 'Elemento no reservable.';
            } elseif (isset($ticketsPorMesa[$mesaId])) {
                $ticket = $ticketsPorMesa[$mesaId];
                $ticketBloqueaEnConsulta = array_key_exists('bloquea_en_consulta', $ticket)
                    ? self::booleano($ticket['bloquea_en_consulta'])
                    : (array_key_exists('bloquea_disponibilidad', $ticket)
                        ? self::booleano($ticket['bloquea_disponibilidad'])
                        : true);
                $ocupacionActual = !array_key_exists('ocupada_fisicamente', $ticket)
                    || self::booleano($ticket['ocupada_fisicamente']);
                $ticketAbierto = [
                    'id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
                    'reservacion_id' => $ticket['reservacion_id'] ?? null,
                    'mesa_ids' => $ticket['mesa_ids'] ?? [],
                    'estado_proyeccion' => $ticket['estado_proyeccion'] ?? null,
                    'hora_apertura' => $ticket['hora_apertura'] ?? null,
                    'liberacion_estimada' => $ticket['liberacion_estimada'] ?? null,
                    'bloquea_en_consulta' => $ticketBloqueaEnConsulta,
                    'ocupada_fisicamente' => $ocupacionActual,
                ];
                $walkIn = empty($ticket['reservacion_id']);
                if ($ticketBloqueaEnConsulta) {
                    $estadoBase = self::OCUPADA;
                } else {
                    self::agregarUnaVez($modificadores, 'ticket_proyectado_liberado');
                    $motivoBloqueo = 'Disponible después de la liberación estimada del ticket.';
                }
                $modificadores[] = 'ticket_abierto';
                $motivoBloqueo = 'Ocupada por servicio activo.';
                if ($ticketBloqueaEnConsulta && !empty($ticket['conflicto_proximo'])) {
                    $modificadores[] = 'conflicto_proximo';
                    $motivoBloqueo = 'Conflicto próximo: el ticket sigue abierto dentro del bloqueo operativo.';
                }
                if (!empty($ticket['disponible_proyectada'])) {
                    $modificadores[] = 'disponible_proyectada';
                }
                if ($walkIn) {
                    $modificadores[] = 'walk_in';
                }
                if (count((array)($ticket['mesa_ids'] ?? [])) > 1) {
                    $modificadores[] = 'varias_mesas';
                }
                if (!$ticketBloqueaEnConsulta) {
                    $motivoBloqueo = 'Disponible después de la liberación estimada del ticket.';
                }
            }

            if ($holdVigente && $estadoBase !== self::OCUPADA && $estadoBase !== self::NO_RESERVABLE) {
                $estadoBase = self::BLOQUEADA;
                self::agregarUnaVez($modificadores, 'hold_vigente');
                $motivoBloqueo = 'Mesa temporalmente comprometida';
            }

            foreach (($reservacionesPorMesa[$mesaId] ?? []) as $reservacion) {
                if ($holdVigente) {
                    continue;
                }
                $resumen = self::resumenReservacion($reservacion);
                $ventana = (string)($reservacion['ventana_operativa'] ?? 'futura');
                $resumen['ventana_operativa'] = $ventana;
                $resumen['minutos_restantes'] = $reservacion['minutos_para_reservacion'] ?? null;
                $resumen['minutos_retraso'] = (int)($reservacion['minutos_retraso'] ?? 0);
                $accionPendiente = ($reservacion['accion_pendiente'] ?? null) === 'REGISTRAR_AUSENCIA';
                $minutosParaInicio = $resumen['minutos_restantes'] !== null
                    ? (int)$resumen['minutos_restantes']
                    : null;
                $reservacionesVisualesMapa[] = [
                    'minutos_para_inicio' => $minutosParaInicio,
                    'inicio_exacto' => $minutosParaInicio !== null && $minutosParaInicio <= 0
                        && $ventana === '0_30',
                    'en_tolerancia' => $ventana === 'tolerancia',
                    'tolerancia_vencida' => in_array($ventana, ['tolerancia_vencida', 'overdue'], true),
                    'ausencia_pendiente' => $accionPendiente,
                    'bloquea_horario_exactamente' => !empty($reservacion['bloquea_walk_ins'])
                        && $ventana !== '0_30'
                        && $ventana !== 'tolerancia'
                        && !in_array($ventana, ['30_60', 'tolerancia_vencida', 'overdue'], true),
                ];
                if ($accionPendiente) {
                    $ausenciaPendiente = true;
                    self::agregarUnaVez($modificadores, 'accion_pendiente');
                    self::agregarUnaVez($modificadores, 'AUSENCIA_PENDIENTE');
                    $reservacionAsociada = $resumen;
                    $reservacionContrato = $reservacion;
                    $motivoBloqueo = 'Acción pendiente: registrar ausencia.';
                }
                if ($reservacionProxima === null) {
                    $reservacionProxima = $resumen;
                    $minutosRestantes = $resumen['minutos_restantes'] !== null
                        ? (int)$resumen['minutos_restantes']
                        : null;
                }
                self::agregarUnaVez($modificadores, 'reservacion_proxima');
                $modificadorVentana = match ($ventana) {
                    '30_60' => 'reservacion_advertencia',
                    '0_30' => 'reservacion_inminente',
                    'tolerancia' => 'reservacion_tolerancia',
                    'tolerancia_vencida' => 'reservacion_vencida',
                    default => 'reservacion_advertencia',
                };
                self::agregarUnaVez($modificadores, $modificadorVentana);
                if ($accionPendiente) {
                    $reservacionAsociada = $resumen;
                    $reservacionContrato = $reservacion;
                    $motivoBloqueo = 'Acción pendiente: registrar ausencia.';
                } elseif (!empty($reservacion['bloquea_walk_ins'])) {
                    self::agregarUnaVez($modificadores, 'reservacion_bloqueante');
                    if ($estadoBase === self::DISPONIBLE) {
                        $estadoBase = self::BLOQUEADA;
                        $reservacionAsociada = $resumen;
                        $motivoBloqueo = match ($ventana) {
                            '0_30' => 'Reservación inminente a las ' . (string)$resumen['hora'] . '.',
                            'tolerancia' => 'Reservación dentro de la tolerancia.',
                            'tolerancia_vencida' => 'Tolerancia vencida; se requiere registrar ausencia.',
                            default => 'Mesa bloqueada por reservación.',
                        };
                    }
                }
                if (count($resumen['mesa_ids']) > 1) {
                    self::agregarUnaVez($modificadores, 'varias_mesas');
                }
            }

            $modificadores = array_values(array_unique($modificadores));
            $mapaVisual = self::proyeccionVisualMapa(
                $activada && $reservable,
                $ticketAbierto !== null,
                $reservacionesVisualesMapa,
                $estadoBase,
                $modificadores
            );
            $titulo = self::tituloAccesible(
                (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                $estadoBase,
                $reservacionProxima,
                $reservacionAsociada,
                $minutosRestantes,
                $motivoBloqueo,
                $ausenciaPendiente
            );
            if (in_array('varias_mesas', $modificadores, true)) {
                $titulo .= ' Vinculada a varias mesas.';
            }

            $estadoVisual = match ($estadoBase) {
                self::OCUPADA => 'ocupada',
                self::NO_RESERVABLE => 'no-utilizable',
                self::BLOQUEADA => 'reservacion-proxima',
                default => 'libre',
            };

            return [
                'id' => $mesaId,
                'numero' => (int)self::valor($mesa, 'numero', 0),
                'nombre' => (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                'etiqueta' => (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                'tipo' => (string)self::valor($mesa, 'tipo', 'mesa'),
                'pos_x' => (float)self::valor($mesa, 'pos_x', 50),
                'pos_y' => (float)self::valor($mesa, 'pos_y', 50),
                'ancho' => self::valor($mesa, 'ancho'),
                'alto' => self::valor($mesa, 'alto'),
                'capacidad' => (int)self::valor($mesa, 'capacidad', 0),
                'activo' => $activada,
                'reservable' => $activada && $reservable,
                'estado_base' => $estadoBase,
                'estado' => $estadoBase,
                'bloquea' => $estadoBase !== self::DISPONIBLE,
                'es_proyeccion' => in_array(
                    (string)($evaluacionOcupacion['contexto'] ?? ''),
                    [OcupacionMesasService::CONTEXTO_PROYECTADO, OcupacionMesasService::CONTEXTO_FUTURO],
                    true
                ),
                'ocupacion_actual' => $ocupacionActual,
                'disponible_proyectada' => $estadoBase === self::DISPONIBLE,
                'estado_visual' => $estadoVisual,
                'estado_visual_mapa' => $mapaVisual['estado_visual'],
                'modificadores_mapa' => $mapaVisual['modificadores'],
                'titulo_mapa' => (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId)
                    . ', ' . ucfirst($mapaVisual['label']) . '.',
                'precedencia_visual_mapa' => $mapaVisual['precedencia'],
                'modificadores' => $modificadores,
                'reservacion_proxima' => $reservacionProxima,
                'minutos_restantes' => $minutosRestantes,
                'reservacion_asociada' => $reservacionAsociada,
                'reservacion' => $reservacionContrato,
                'hold' => $holdVigente ? [
                    'reservacion_id' => $reservacionAsociada['id'] ?? null,
                    'vigente' => true,
                ] : null,
                'acciones' => $ausenciaPendiente
                    ? [['id' => 'REGISTRAR_AUSENCIA', 'tipo' => 'primary']]
                    : [],
                'accion_pendiente' => $ausenciaPendiente ? 'REGISTRAR_AUSENCIA' : null,
                'ticket_abierto' => $ticketAbierto,
                'walk_in' => $walkIn,
                'seleccion_actual' => false,
                'motivo_bloqueo' => $motivoBloqueo,
                'titulo' => $titulo,
            ];
        }, $mesas);
    }

    /** @param array<int, array<string, mixed>> $reservacionesVisuales */
    private static function proyeccionVisualMapa(
        bool $utilizable,
        bool $ticketAbierto,
        array $reservacionesVisuales,
        string $estadoBase,
        array $modificadores
    ): array {
        $resultado = ReservacionMapaMesaPresenter::presentar([
            'utilizable' => $utilizable,
            'ticket_abierto' => $ticketAbierto,
            'reservaciones' => $reservacionesVisuales,
        ]);
        if ($ticketAbierto) {
            return [
                'estado_visual' => $resultado['estado_visual'],
                'modificadores' => array_values(array_unique(array_merge($modificadores, $resultado['modificadores']))),
                'label' => $resultado['label'],
                'precedencia' => $resultado['precedencia'],
            ];
        }
        if ($reservacionesVisuales === []) {
            $resultado = match ($estadoBase) {
                self::OCUPADA => [
                    'estado_visual' => 'ocupada',
                    'modificadores' => [],
                    'label' => 'ocupada',
                    'precedencia' => 'ocupacion',
                ],
                self::BLOQUEADA => [
                    'estado_visual' => 'reservacion-proxima',
                    'modificadores' => [],
                    'label' => 'comprometida',
                    'precedencia' => 'bloqueo',
                ],
                default => $resultado,
            };
        }
        return [
            'estado_visual' => $resultado['estado_visual'],
            'modificadores' => array_values(array_unique(array_merge($modificadores, $resultado['modificadores']))),
            'label' => $resultado['label'],
            'precedencia' => $resultado['precedencia'],
        ];
    }

    /**
     * Clasifica una reservación usando la ventana temporal central:
     * 60..31 como advertencia, 30..0 como bloqueada azul, tolerancia como
     * bloqueada azul y vencida como bloqueada azul oscura.
     *
     * @return array{tipo:string,ventana?:string,minutos_restantes:int|null,minutos_retraso:int}|null
     */
    public static function clasificarReservacion(array $reservacion, DateTimeImmutable $ahora): ?array
    {
        if (
            empty($reservacion['muestra_advertencia'])
            && empty($reservacion['bloquea_walk_ins'])
        ) {
            return null;
        }

        $ventana = (string)($reservacion['ventana_operativa'] ?? 'futura');

        return [
            'tipo' => !empty($reservacion['bloquea_walk_ins']) ? 'bloqueada' : 'proxima',
            'ventana' => $ventana,
            'minutos_restantes' => $reservacion['minutos_para_reservacion'] ?? null,
            'minutos_retraso' => (int)($reservacion['minutos_retraso'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function ticketsPorMesa(
        array $tickets,
        string $fecha,
        string $hora,
        DateTimeImmutable $ahora,
        array $evaluacionOcupacion = []
    ): array
    {
        $evaluacion = $evaluacionOcupacion !== []
            ? $evaluacionOcupacion
            : OcupacionMesasService::evaluarTickets($tickets, $fecha, $hora, $ahora);
        $porMesa = [];
        foreach ((array)($evaluacion['tickets_por_mesa'] ?? $evaluacion['por_mesa'] ?? []) as $mesaId => $ticketRaw) {
            $ticket = self::aArray($ticketRaw);
            if (empty($ticket['aplica_fecha'])) {
                continue;
            }
            $ticket['id'] = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
            $ticket['reservacion_id'] = !empty($ticket['reservacion_id'])
                ? (int)$ticket['reservacion_id']
                : null;
            $ticket['mesa_ids'] = self::ids($ticket['mesa_ids'] ?? []);
            $porMesa[(int)$mesaId] = $ticket;
        }

        return $porMesa;
    }

    /** @return array<string, mixed> */
    private static function resumenReservacion(array $reservacion): array
    {
        $mesas = $reservacion['mesas_asignadas'] ?? $reservacion['mesas'] ?? [];
        if (is_string($mesas)) {
            $mesas = array_values(array_filter(array_map('trim', explode(',', $mesas))));
        }

        return [
            'id' => (int)($reservacion['id'] ?? $reservacion['reservacion_id'] ?? 0),
            'folio' => '#' . (int)($reservacion['id'] ?? $reservacion['reservacion_id'] ?? 0),
            'nombre' => (string)($reservacion['nombre'] ?? ''),
            'hora' => substr((string)($reservacion['hora'] ?? ''), 0, 5),
            'comensales' => (int)($reservacion['comensales'] ?? $reservacion['personas'] ?? 0),
            'estado' => (string)($reservacion['estado'] ?? ''),
            'mesa_ids' => self::ids($reservacion['mesa_ids'] ?? []),
            'mesas' => is_array($mesas) ? array_values($mesas) : [],
            'presentacion' => $reservacion['advertencia'] ?? null,
        ];
    }

    private static function fechaHoraReservacion(array $reservacion): ?DateTimeImmutable
    {
        $valor = trim((string)($reservacion['fecha'] ?? ''))
            . ' '
            . trim((string)($reservacion['hora'] ?? ''));
        try {
            return new DateTimeImmutable(trim($valor), ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function tituloAccesible(
        string $nombre,
        string $estado,
        ?array $proxima,
        ?array $asociada,
        ?int $minutos,
        ?string $motivo,
        bool $accionPendiente = false
    ): string {
        $partes = [$nombre . '.'];
        $ventanaProxima = $proxima
            ? (string)($proxima['ventana_operativa'] ?? 'warning')
            : null;
        if ($proxima && $ventanaProxima === '30_60' && $estado === self::DISPONIBLE) {
            return sprintf(
                '%s, disponible, reservación dentro de %d minutos.',
                $nombre,
                (int)$minutos
            );
        }
        if ($accionPendiente && $estado === self::DISPONIBLE) {
            $partes[] = 'Disponible, acción pendiente: registrar ausencia.';
        } else {
            $partes[] = match ($estado) {
                self::OCUPADA => 'Ocupada por servicio activo.',
                self::BLOQUEADA => $motivo ?: 'Mesa bloqueada.',
                self::NO_RESERVABLE => $motivo ?: 'No reservable.',
                default => 'Disponible.',
            };
        }
        if ($proxima) {
            $partes[] = match ($ventanaProxima) {
                '30_60' => sprintf(
                    'Disponible; reservación dentro de %d minutos.',
                    (int)$minutos
                ),
                '0_30' => sprintf(
                    'Reservación a las %s. Puede iniciar servicio.',
                    (string)$proxima['hora']
                ),
                'tolerancia' => sprintf(
                    'Cliente con %d minutos de retraso. Se encuentra dentro del tiempo de tolerancia.',
                    (int)($proxima['minutos_retraso'] ?? 0)
                ),
                'tolerancia_vencida' => 'La tolerancia de llegada venció. Acción pendiente: registrar ausencia.',
                'overdue' => 'La tolerancia de llegada venció. Acción pendiente: registrar ausencia.',
                default => 'Reservación próxima.',
            };
        }
        if ($asociada && $estado === self::OCUPADA) {
            $partes[] = 'Reservación asociada ' . (string)$asociada['folio'] . '.';
        }

        return implode(' ', $partes);
    }

    /** @return array<string, mixed> */
    private static function aArray($registro): array
    {
        return is_array($registro) ? $registro : get_object_vars($registro);
    }

    private static function valor($registro, string $campo, $default = null)
    {
        if (is_array($registro)) {
            return $registro[$campo] ?? $default;
        }

        return $registro->{$campo} ?? $default;
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }

    private static function agregarUnaVez(array &$valores, string $valor): void
    {
        if (!in_array($valor, $valores, true)) {
            $valores[] = $valor;
        }
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

        return array_values(array_unique(array_filter(array_map('intval', $valor))));
    }
}
