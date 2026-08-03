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
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $reservacionesPorMesa = self::reservacionesPorMesa($reservaciones, $ahora);
        $ticketsPorMesa = self::ticketsPorMesa(
            $tickets,
            $fecha,
            $hora !== '' ? $hora : $ahora->format('H:i:s'),
            $ahora,
            $evaluacionOcupacion
        );

        return array_map(static function ($mesa) use (
            $reservacionesPorMesa,
            $ticketsPorMesa,
            $ahora
        ): array {
            $mesaId = (int)self::valor($mesa, 'id', 0);
            $activada = (int)self::valor($mesa, 'activo', 1) === 1;
            $reservable = (int)self::valor($mesa, 'reservable', 0) === 1;
            $estadoBase = self::DISPONIBLE;
            $modificadores = [];
            $reservacionProxima = null;
            $reservacionAsociada = null;
            $ticketAbierto = null;
            $walkIn = false;
            $minutosRestantes = null;
            $motivoBloqueo = null;

            // La precedencia comienza por elementos no operables del plano.
            if (!$activada || !$reservable) {
                $estadoBase = self::NO_RESERVABLE;
                $motivoBloqueo = !$activada ? 'Mesa fuera de servicio.' : 'Elemento no reservable.';
            } elseif (isset($ticketsPorMesa[$mesaId])) {
                $ticket = $ticketsPorMesa[$mesaId];
                $ticketAbierto = [
                    'id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
                    'reservacion_id' => $ticket['reservacion_id'],
                    'mesa_ids' => $ticket['mesa_ids'],
                    'estado_proyeccion' => $ticket['estado_proyeccion'] ?? null,
                    'liberacion_proyectada' => $ticket['liberacion_proyectada'] ?? null,
                ];
                $walkIn = $ticket['reservacion_id'] === null;
                $modificadores[] = 'ticket_abierto';
                if (!empty($ticket['conflicto_proximo'])) {
                    $estadoBase = self::OCUPADA;
                    $modificadores[] = 'conflicto_proximo';
                    $motivoBloqueo = 'Conflicto próximo: el ticket sigue abierto dentro del bloqueo operativo.';
                } elseif (!empty($ticket['disponible_proyectada'])) {
                    $estadoBase = self::DISPONIBLE;
                    $modificadores[] = 'disponible_proyectada';
                } else {
                    $estadoBase = self::OCUPADA;
                    $motivoBloqueo = 'Ocupada por servicio activo.';
                }
                if ($walkIn) {
                    $modificadores[] = 'walk_in';
                }
                if (count($ticket['mesa_ids']) > 1) {
                    $modificadores[] = 'varias_mesas';
                }
            }

            $candidatas = $estadoBase === self::NO_RESERVABLE
                ? []
                : ($reservacionesPorMesa[$mesaId] ?? []);
            foreach ($candidatas as $candidata) {
                $clasificacion = self::clasificarReservacion($candidata, $ahora);
                if ($clasificacion === null) {
                    continue;
                }

                $resumen = self::resumenReservacion($candidata);
                if (count($resumen['mesa_ids']) > 1 && !in_array('varias_mesas', $modificadores, true)) {
                    $modificadores[] = 'varias_mesas';
                }

                if (in_array($clasificacion['tipo'], ['proxima', 'bloqueada'], true)) {
                    $ventana = (string)($clasificacion['ventana'] ?? 'warning');
                    $modificadores[] = 'reservacion_proxima';
                    $reservacionTemporal = array_merge($resumen, [
                        'ventana_operativa' => $ventana,
                        'minutos_restantes' => $clasificacion['minutos_restantes'],
                        'minutos_retraso' => $clasificacion['minutos_retraso'],
                    ]);
                    if ($reservacionProxima === null) {
                        $reservacionProxima = $reservacionTemporal;
                        $minutosRestantes = $clasificacion['minutos_restantes'];
                    }
                    $modificadores[] = match ($ventana) {
                        'warning' => 'reservacion_advertencia',
                        'service_window' => 'reservacion_inminente',
                        'tolerance' => 'reservacion_tolerancia',
                        'overdue' => 'reservacion_vencida',
                        default => 'reservacion_advertencia',
                    };
                    if ($clasificacion['tipo'] === 'bloqueada') {
                        $modificadores[] = 'reservacion_bloqueante';
                    }
                }

                if ($clasificacion['tipo'] === 'ocupada') {
                    // Sólo un ticket o un elemento no reservable tienen mayor
                    // precedencia que una reservación actualmente en ventana.
                    if ($estadoBase === self::DISPONIBLE || $estadoBase === self::BLOQUEADA) {
                        $estadoBase = self::OCUPADA;
                        $reservacionAsociada = $resumen;
                        $motivoBloqueo = 'Ocupada por reservación en curso.';
                    }
                    continue;
                }

                if ($clasificacion['tipo'] === 'bloqueada') {
                    if ($estadoBase === self::DISPONIBLE) {
                        $estadoBase = self::BLOQUEADA;
                        $reservacionAsociada = $resumen;
                        $minutosRestantes = $clasificacion['minutos_restantes'];
                        $motivoBloqueo = sprintf(
                            '%s por reservación #%d a las %s.',
                            match ((string)($clasificacion['ventana'] ?? '')) {
                                'service_window' => 'Puede iniciar servicio',
                                'tolerance' => 'Dentro de la tolerancia',
                                'overdue' => 'Tolerancia vencida',
                                default => 'Bloqueada',
                            },
                            (int)$resumen['id'],
                            (string)$resumen['hora']
                        );
                    }
                    continue;
                }

            }

            $modificadores = array_values(array_unique($modificadores));
            $titulo = self::tituloAccesible(
                (string)self::valor($mesa, 'nombre', 'Mesa ' . $mesaId),
                $estadoBase,
                $reservacionProxima,
                $reservacionAsociada,
                $minutosRestantes,
                $motivoBloqueo
            );
            if (in_array('varias_mesas', $modificadores, true)) {
                // El vínculo N:M también queda disponible para lectores de pantalla.
                $titulo .= ' Vinculada a varias mesas.';
            }

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
                'modificadores' => $modificadores,
                'reservacion_proxima' => $reservacionProxima,
                'minutos_restantes' => $minutosRestantes,
                'reservacion_asociada' => $reservacionAsociada,
                'ticket_abierto' => $ticketAbierto,
                'walk_in' => $walkIn,
                'seleccion_actual' => false,
                'motivo_bloqueo' => $motivoBloqueo,
                'titulo' => $titulo,
            ];
        }, $mesas);
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
        $vigencia = ReservacionVigenciaService::clasificar($reservacion, $ahora);
        if (!$vigencia['influye_disponibilidad']) {
            return null;
        }

        $ventana = ReservacionVigenciaService::resolverVentanaOperativa($reservacion, $ahora);
        if (($ventana['estado'] ?? 'future') === 'future') {
            return null;
        }

        if (in_array((string)($reservacion['estado'] ?? ''), ['llego', 'en_curso'], true)) {
            return ['tipo' => 'ocupada', 'minutos_restantes' => 0];
        }

        return [
            'tipo' => ($ventana['estado'] ?? '') === 'warning' ? 'proxima' : 'bloqueada',
            'ventana' => (string)($ventana['estado'] ?? 'warning'),
            'minutos_restantes' => $ventana['minutos_restantes'],
            'minutos_retraso' => $ventana['minutos_retraso'],
        ];
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private static function reservacionesPorMesa(array $reservaciones, DateTimeImmutable $ahora): array
    {
        $porMesa = [];
        foreach ($reservaciones as $reservacionRaw) {
            $reservacion = self::aArray($reservacionRaw);
            if (!ReservacionVigenciaService::clasificar(
                $reservacion,
                $ahora
            )['influye_disponibilidad']) {
                continue;
            }

            $reservacion['mesa_ids'] = self::ids($reservacion['mesa_ids'] ?? []);
            foreach ($reservacion['mesa_ids'] as $mesaId) {
                $porMesa[$mesaId][] = $reservacion;
            }
        }

        foreach ($porMesa as &$candidatas) {
            usort($candidatas, static function (array $a, array $b): int {
                return strcmp(
                    (string)($a['fecha'] ?? '') . ' ' . (string)($a['hora'] ?? ''),
                    (string)($b['fecha'] ?? '') . ' ' . (string)($b['hora'] ?? '')
                );
            });
        }
        unset($candidatas);

        return $porMesa;
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
        ?string $motivo
    ): string {
        $partes = [$nombre . '.'];
        $ventanaProxima = $proxima
            ? (string)($proxima['ventana_operativa'] ?? 'warning')
            : null;
        if ($proxima && $ventanaProxima === 'warning' && $estado === self::DISPONIBLE) {
            return sprintf(
                '%s, disponible, reservación dentro de %d minutos.',
                $nombre,
                (int)$minutos
            );
        }
        $partes[] = match ($estado) {
            self::OCUPADA => 'Ocupada por servicio activo.',
            self::BLOQUEADA => $motivo ?: 'Mesa bloqueada.',
            self::NO_RESERVABLE => $motivo ?: 'No reservable.',
            default => 'Disponible.',
        };
        if ($proxima) {
            $partes[] = match ($ventanaProxima) {
                'warning' => sprintf(
                    'Disponible; reservación dentro de %d minutos.',
                    (int)$minutos
                ),
                'service_window' => sprintf(
                    'Reservación a las %s. Puede iniciar servicio.',
                    (string)$proxima['hora']
                ),
                'tolerance' => sprintf(
                    'Cliente con %d minutos de retraso. Se encuentra dentro del tiempo de tolerancia.',
                    (int)($proxima['minutos_retraso'] ?? 0)
                ),
                'overdue' => 'Reservación con tolerancia vencida, sin servicio iniciado.',
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
