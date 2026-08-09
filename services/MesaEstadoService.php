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
        array $evaluacionOcupacion = [],
        array $opciones = []
    ): array {
        return self::normalizarMesasCanonicas(
            $mesas,
            $reservaciones,
            $tickets,
            $fecha,
            $ahora,
            $hora,
            $evaluacionOcupacion,
            $opciones
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
        array $evaluacionOcupacion,
        array $opciones
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
        $mesaIdsBloqueadas = array_fill_keys(
            self::ids((array)($evaluacionOcupacion['mesa_ids_bloqueadas'] ?? [])),
            true
        );
        $causasBloqueoPorMesa = (array)($evaluacionOcupacion['causas_bloqueo_por_mesa'] ?? []);
        $tieneBloqueoCanonico = array_key_exists('mesa_ids_bloqueadas', $evaluacionOcupacion);
        $asignacionActualIds = array_fill_keys(
            self::ids((array)($opciones['current_assignment_ids'] ?? [])),
            true
        );
        $reservacionEnEdicionId = (int)($opciones['reservacion_en_edicion_id'] ?? 0);

        return array_map(static function ($mesa) use (
            $reservacionesPorMesa,
            $ticketsPorMesa,
            $ocupacionPorMesa,
            $mesaIdsBloqueadas,
            $causasBloqueoPorMesa,
            $tieneBloqueoCanonico,
            $asignacionActualIds,
            $reservacionEnEdicionId,
            $fecha,
            $hora,
            $ahora
        ): array {
            $mesaId = (int)self::valor($mesa, 'id', 0);
            $asignadaActualmente = isset($asignacionActualIds[$mesaId]);
            $activada = self::booleano(self::valor($mesa, 'activo', true));
            $reservable = self::booleano(self::valor($mesa, 'reservable', true));
            $tipoMesa = (string)self::valor($mesa, 'tipo', 'mesa');
            $utilizable = $activada && $reservable && $tipoMesa === 'mesa';
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
            $bloqueadaEnIntervalo = isset($mesaIdsBloqueadas[$mesaId]);
            $causasBloqueo = self::idsStrings($causasBloqueoPorMesa[$mesaId] ?? []);
            if ($bloqueadaEnIntervalo && $causasBloqueo === []) {
                $causasBloqueo = self::causasBloqueoDesdeEstado($ocupacionMesa, $ticketsPorMesa[$mesaId] ?? null);
            }
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
                $hechos = ReservacionPoliticaPosService::evaluar(
                    $reservacion,
                    $ahora,
                    !empty($reservacion['ticket_abierto']) ? (array)($reservacion['ticket'] ?? []) : null,
                    self::fechaHoraConsulta($fecha, $hora)
                );
                $mapa = (array)($hechos['proyeccion_mapa'] ?? []);
                $resumen['ventana_operativa'] = (string)($hechos['ventana_pos'] ?? 'futura');
                $resumen['ventana_pos'] = $resumen['ventana_operativa'];
                $resumen['ventana_mapa'] = (string)($mapa['ventana_mapa'] ?? 'futura');
                $resumen['minutos_restantes'] = $hechos['minutos_para_inicio'];
                $resumen['minutos_para_inicio'] = $hechos['minutos_para_inicio'];
                $resumen['minutos_desde_inicio'] = $hechos['minutos_desde_inicio'];
                $resumen['inicio_reservacion'] = $hechos['inicio_reservacion'];
                $resumen['minutos_retraso'] = (int)($reservacion['minutos_retraso'] ?? 0);
                $resumen = array_merge($resumen, $hechos, [
                    'minutos_para_inicio_mapa' => $mapa['minutos_para_inicio_mapa'] ?? null,
                    'minutos_desde_inicio_mapa' => $mapa['minutos_desde_inicio_mapa'] ?? null,
                    'ventana_mapa' => $mapa['ventana_mapa'] ?? 'futura',
                    'reservacion_influye_mapa' => $mapa['reservacion_influye_mapa'] ?? false,
                    'reservacion_influye_en_consulta' => $mapa['reservacion_influye_en_consulta'] ?? false,
                    'ausencia_pendiente_mapa' => $mapa['ausencia_pendiente_mapa'] ?? false,
                    'en_inicio_exacto_mapa' => $mapa['en_inicio_exacto_mapa'] ?? false,
                ]);
                $accionPendiente = (bool)$hechos['ausencia_pendiente'];
                $reservacionesVisualesMapa[] = array_merge($resumen, [
                    'id' => $resumen['id'],
                    'hora' => $resumen['hora'],
                    'comensales' => $resumen['comensales'],
                    'estado' => $resumen['estado'],
                    'mesa_ids' => $resumen['mesa_ids'],
                ]);
                if ($accionPendiente) {
                    $ausenciaPendiente = true;
                    self::agregarUnaVez($modificadores, 'ausencia_pendiente');
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
                $modificadorVentana = match (true) {
                    $hechos['ventana_visual_pos'] === 'inicio' => 'reservacion_bloqueante',
                    $hechos['ventana_visual_pos'] === 'tolerancia' => 'reservacion_tolerancia',
                    $hechos['ventana_visual_pos'] === 'ausencia_pendiente' => 'reservacion_vencida',
                    $hechos['ventana_visual_pos'] === 'bloqueo' => 'reservacion_inminente',
                    $hechos['ventana_visual_pos'] === 'advertencia' => 'reservacion_advertencia',
                    default => 'reservacion_advertencia',
                };
                self::agregarUnaVez($modificadores, $modificadorVentana);
                if ($accionPendiente) {
                    $reservacionAsociada = $resumen;
                    $reservacionContrato = $reservacion;
                    $motivoBloqueo = 'Acción pendiente: registrar ausencia.';
                } elseif (!empty($hechos['bloquea_walk_ins'])) {
                    self::agregarUnaVez($modificadores, 'reservacion_bloqueante');
                    if ($estadoBase === self::DISPONIBLE) {
                        $estadoBase = self::BLOQUEADA;
                        $reservacionAsociada = $resumen;
                        $motivoBloqueo = match ($hechos['ventana_visual_pos']) {
                            'bloqueo', 'inicio' => 'Reservación inminente a las ' . (string)$resumen['hora'] . '.',
                            'tolerancia' => 'Reservación dentro de la tolerancia.',
                            'ausencia_pendiente' => 'Tolerancia vencida; se requiere registrar ausencia.',
                            default => 'Mesa bloqueada por reservación.',
                        };
                    }
                }
                if (count($resumen['mesa_ids']) > 1) {
                    self::agregarUnaVez($modificadores, 'varias_mesas');
                }
            }

            $modificadores = array_values(array_unique($modificadores));
            $reservacionPrincipal = self::reservacionPrincipal($reservacionesVisualesMapa);
            $mapaVisual = self::proyeccionVisualMapa(
                $utilizable,
                $bloqueadaEnIntervalo,
                $causasBloqueo,
                $reservacionPrincipal ?? null,
                $ticketBloqueaEnConsulta
            );
            $disponibleParaAsignacion = $utilizable && !$bloqueadaEnIntervalo;
            $causaConflictoAsignacion = null;
            if ($asignadaActualmente && $ticketAbierto !== null
                && (int)($ticketAbierto['reservacion_id'] ?? 0) !== $reservacionEnEdicionId) {
                $causaConflictoAsignacion = 'ticket_abierto';
            } elseif ($asignadaActualmente && !$disponibleParaAsignacion) {
                $causaConflictoAsignacion = $causasBloqueo[0] ?? 'no_disponible';
            }
            $hechosMesa = self::hechosMesa(
                $mesaId,
                $utilizable,
                $ticketAbierto,
                $ticketBloqueaEnConsulta,
                $reservacionPrincipal,
                $mapaVisual,
                $bloqueadaEnIntervalo,
                $causasBloqueo,
                $ocupacionActual,
                $asignadaActualmente,
                $causaConflictoAsignacion
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
                'utilizable' => $utilizable,
                'estado_base' => $estadoBase,
                'estado' => $estadoBase,
                'bloquea' => $estadoBase !== self::DISPONIBLE,
                'bloqueada_en_intervalo' => $bloqueadaEnIntervalo,
                'causas_bloqueo' => $causasBloqueo,
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
                'modificadores_visual_mapa' => $mapaVisual['modificadores'],
                'estado_visual_pos' => $hechosMesa['estado_visual_pos'],
                'modificadores_visual_pos' => $hechosMesa['modificadores_visual_pos'],
                'aria_label_mapa' => self::ariaLabelMapa(
                    (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                    $hechosMesa
                ),
                'titulo_mapa' => self::ariaLabelMapa(
                    (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                    $hechosMesa
                ),
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
                'ticket_abierto' => $ticketAbierto !== null,
                'ticket' => $ticketAbierto,
                'walk_in' => $walkIn,
                'seleccion_actual' => false,
                'asignada_actualmente' => $asignadaActualmente,
                'causa_conflicto_asignacion' => $causaConflictoAsignacion,
                'motivo_bloqueo' => $motivoBloqueo,
                'titulo' => $titulo,
            ] + $hechosMesa;
        }, $mesas);
    }

    private static function proyeccionVisualMapa(
        bool $utilizable,
        bool $bloqueadaEnIntervalo,
        array $causasBloqueo,
        ?array $reservacionPrincipal = null,
        bool $ticketBloqueaEnConsulta = false
    ): array {
        // El presenter recibe hechos ya calculados. La asignabilidad por
        // intervalo no se convierte por sí sola en rojo: a las 12:00 una
        // reservación de las 13:00 bloquea capacidad, pero el mapa comunica
        // proximidad con verde y borde azul.
        $resultado = ReservacionMapaMesaPresenter::presentar([
            'utilizable' => $utilizable,
            'bloqueada_en_intervalo' => $bloqueadaEnIntervalo,
            'causas_bloqueo' => $causasBloqueo,
            'ticket_bloquea_consulta' => $ticketBloqueaEnConsulta,
            'reservacion' => $reservacionPrincipal,
        ]);
        return [
            'estado_visual' => $resultado['estado_visual'],
            'modificadores' => $resultado['modificadores'],
            'label' => $resultado['label'],
            'precedencia' => $resultado['precedencia'],
        ];

    }

    /** @param array<int, array<string, mixed>> $reservaciones */
    private static function reservacionPrincipal(array $reservaciones): ?array
    {
        $principal = null;
        $rangoPrincipal = -1;
        foreach ($reservaciones as $reservacion) {
            $rango = (int)($reservacion['prioridad_pos'] ?? 100);
            if ($principal === null || $rango > $rangoPrincipal) {
                $principal = $reservacion;
                $rangoPrincipal = $rango;
            }
        }

        return $principal;
    }

    /** @return array<string, mixed> */
    private static function hechosMesa(
        int $mesaId,
        bool $utilizable,
        ?array $ticketAbierto,
        bool $ticketBloqueaEnConsulta,
        ?array $reservacionPrincipal,
        array $mapaVisual,
        bool $bloqueadaEnIntervalo,
        array $causasBloqueo,
        bool $ocupadaFisicamente,
        bool $asignadaActualmente,
        ?string $causaConflictoAsignacion
    ): array {
        $presentacionPos = PosMesaProjectionPresenter::presentar([
            'mesa_id' => $mesaId,
            'utilizable' => $utilizable,
            'ticket_abierto' => $ticketAbierto !== null,
            'ticket_bloquea_consulta' => $ticketBloqueaEnConsulta,
            'reservacion' => $reservacionPrincipal,
        ]);
        $reservacion = $reservacionPrincipal ?? [];
        $disponibleParaAsignacion = $utilizable && !$bloqueadaEnIntervalo;
        $disponibleParaTicket = $reservacion === []
            ? $utilizable && !$ticketBloqueaEnConsulta
            : self::booleano($reservacion['disponible_para_ticket'] ?? false);
        if ($ticketBloqueaEnConsulta) {
            $disponibleParaTicket = false;
        }

        return [
            'mesa_id' => $mesaId,
            'asignada_actualmente' => $asignadaActualmente,
            'causa_conflicto_asignacion' => $causaConflictoAsignacion,
            'utilizable' => $utilizable,
            'ocupada_fisicamente' => $ocupadaFisicamente,
            'ticket_abierto_hecho' => $ticketAbierto !== null,
            'ticket_bloquea_consulta' => $ticketBloqueaEnConsulta,
            'bloqueada_en_intervalo' => $bloqueadaEnIntervalo,
            'disponible_para_asignacion' => $disponibleParaAsignacion,
            'disponible_para_ticket' => $disponibleParaTicket,
            'causas_bloqueo' => $causasBloqueo,
            'reservacion_id' => $reservacion['id'] ?? null,
            'reservacion_estado' => $reservacion['estado'] ?? null,
            'inicio_reservacion' => $reservacion['inicio_reservacion'] ?? null,
            'estado_temporal' => $reservacion['estado_temporal'] ?? null,
            'ventana_pos' => $reservacion['ventana_pos'] ?? $reservacion['ventana_operativa'] ?? null,
            'ventana_mapa' => $reservacion['ventana_mapa'] ?? null,
            'reservacion_influye_en_consulta' => self::booleano($reservacion['reservacion_influye_en_consulta'] ?? false),
            'minutos_para_inicio' => $reservacion['minutos_para_inicio'] ?? null,
            'minutos_desde_inicio' => $reservacion['minutos_desde_inicio'] ?? null,
            'minutos_para_inicio_mapa' => $reservacion['minutos_para_inicio_mapa'] ?? null,
            'minutos_desde_inicio_mapa' => $reservacion['minutos_desde_inicio_mapa'] ?? null,
            'en_inicio_exacto' => self::booleano($reservacion['en_inicio_exacto'] ?? false),
            'en_tolerancia' => self::booleano($reservacion['en_tolerancia'] ?? false),
            'tolerancia_vencida' => self::booleano($reservacion['tolerancia_vencida'] ?? false),
            'ausencia_pendiente' => self::booleano($reservacion['ausencia_pendiente'] ?? false),
            'bloquea_intervalo_reservacion' => self::booleano($reservacion['bloquea_intervalo_reservacion'] ?? false),
            'requiere_advertencia_ticket' => self::booleano($reservacion['requiere_advertencia_ticket'] ?? false),
            'puede_abrir_ticket' => $disponibleParaTicket,
            'puede_iniciar_reservacion' => self::booleano($reservacion['puede_iniciar_reservacion'] ?? false),
            'puede_marcar_no_show' => self::booleano($reservacion['puede_marcar_no_show'] ?? false),
            'accion_primaria' => (string)($reservacion['accion_primaria'] ?? (
                $disponibleParaTicket
                    ? ReservacionPoliticaPosService::ACCION_ABRIR_TICKET
                    : ReservacionPoliticaPosService::ACCION_RESERVACION_PROXIMA
            )),
            'bloquea_capacidad' => $bloqueadaEnIntervalo,
            'estado_visual_pos' => $presentacionPos['estado_visual'],
            'modificadores_visual_pos' => $presentacionPos['modificadores'],
            'precedencia_visual_pos' => $presentacionPos['precedencia'],
            'aria_label_pos' => $presentacionPos['aria_label'],
            'estado_visual_mapa_detalle' => $mapaVisual,
        ];
    }

    /** @param array<string, mixed> $hechos */
    private static function ariaLabelMapa(string $nombre, array $hechos): string
    {
        if (!self::booleano($hechos['utilizable'] ?? true)) {
            return $nombre . ', no utilizable.';
        }

        $detalle = is_array($hechos['estado_visual_mapa_detalle'] ?? null)
            ? $hechos['estado_visual_mapa_detalle']
            : [];
        $estado = (string)($detalle['estado_visual'] ?? '');
        $modificadores = array_map('strval', (array)($detalle['modificadores'] ?? []));
        if ($estado === 'ocupada') {
            $label = self::booleano($hechos['ticket_bloquea_consulta'] ?? false)
                ? $nombre . ', ocupada por ticket abierto.'
                : $nombre . ', ocupada.';
        } elseif ($estado === 'reservacion-proxima') {
            $label = $nombre . ', reservación próxima.';
        } elseif (in_array('reservacion_advertencia', $modificadores, true)) {
            $label = $nombre . ', disponible con reservación cercana.';
        } elseif (self::booleano($hechos['bloqueada_en_intervalo'] ?? false)) {
            $causas = self::idsStrings($hechos['causas_bloqueo'] ?? []);
            if (in_array('reservacion', $causas, true)) {
                $label = $nombre . ', no disponible por reservación.';
            } else {
                $label = $nombre . ', no disponible para el intervalo seleccionado.';
            }
        } else {
            $label = $nombre . ', disponible para el intervalo seleccionado.';
        }

        if (self::booleano($hechos['ausencia_pendiente'] ?? false)
            || in_array('ausencia_pendiente', $modificadores, true)) {
            $label .= ' Acción pendiente: registrar ausencia.';
        }

        return $label;

    }

    private static function enteroNulo($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (int)$valor;
    }

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

    private static function fechaHoraConsulta(string $fecha, string $hora): ?DateTimeImmutable
    {
        $horaNormalizada = HorarioReservacionService::normalizarHoraSql(
            $hora !== '' ? $hora : ReservacionConfig::horaActual()
        );
        if ($fecha === '' || $horaNormalizada === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(
                $fecha . ' ' . $horaNormalizada,
                ReservacionConfig::timezone()
            );
        } catch (\Throwable $error) {
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
        if ($proxima && in_array($ventanaProxima, ['advertencia', 'futura'], true) && $estado === self::DISPONIBLE) {
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
                'advertencia' => sprintf(
                    'Disponible; reservación dentro de %d minutos.',
                    (int)$minutos
                ),
                'bloqueo' => sprintf(
                    'Reservación a las %s. Puede iniciar servicio.',
                    (string)$proxima['hora']
                ),
                'inicio' => sprintf(
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

    /** @return array<int, string> */
    private static function idsStrings($valor): array
    {
        if (is_string($valor)) {
            $valor = explode(',', $valor);
        }
        if (!is_array($valor)) {
            return [];
        }

        $valores = array_map(static fn($item): string => trim((string)$item), $valor);
        $valores = array_values(array_filter($valores, static fn(string $item): bool => $item !== ''));
        return array_values(array_unique($valores));
    }

    /** @return array<int, string> */
    private static function causasBloqueoDesdeEstado(array $ocupacionMesa, ?array $ticket): array
    {
        $causas = [];
        if ($ticket !== null && self::booleano($ticket['bloquea_en_consulta'] ?? false)) {
            $causas[] = 'ticket';
        }
        $fuente = (string)($ocupacionMesa['fuente'] ?? '');
        if ($fuente === 'hold') {
            $causas[] = 'hold';
        } elseif ($fuente === 'reservacion') {
            $causas[] = 'reservacion';
        }
        return array_values(array_unique($causas));
    }
}
