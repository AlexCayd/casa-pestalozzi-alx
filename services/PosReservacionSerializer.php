<?php

namespace Services;

use DateTimeImmutable;

/**
 * Produce el contrato operativo compartido por POS y operación.
 *
 * Esta clase no consulta la base de datos ni decide asignaciones. Recibe filas
 * y ocupación ya leídas, aplica la vigencia central y presenta una sola forma
 * estable para los consumidores.
 */
final class PosReservacionSerializer
{
    public const SCHEMA_VERSION = 'pos-reservacion.v1';

    /**
     * @param array<string, mixed>|object $fila
     * @param array<string, mixed>|null $ticket
     * @param array<int, array<string, mixed>> $mesas
     * @param array<string, mixed> $opciones
     * @return array<string, mixed>
     */
    public static function reservacion(
        $fila,
        ?array $ticket,
        array $mesas,
        ?DateTimeImmutable $ahora = null,
        array $opciones = []
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $datos = self::aArray($fila);
        $reservacionId = (int)($datos['id'] ?? $datos['reservacion_id'] ?? 0);
        $mesaIds = self::ids($datos['mesa_ids'] ?? []);
        $mesasAsignadas = self::mesasPorIds($mesaIds, $mesas, $datos);
        $ticket = $ticket !== null ? self::ticket($ticket) : null;
        $estado = (string)($datos['estado'] ?? '');
        $conflictoFisico = (bool)($opciones['conflicto_fisico'] ?? false);
        $mesasBloqueantes = array_values(array_filter(
            (array)($opciones['mesas_bloqueantes'] ?? []),
            static fn($bloqueo): bool => is_array($bloqueo)
        ));
        $sinMesas = $mesaIds === [];
        $horaConsulta = self::fechaHoraConsulta(
            (string)($opciones['hora_consulta'] ?? ''),
            (string)($datos['fecha'] ?? '')
        );
        $politica = ReservacionPoliticaPosService::evaluar(
            array_merge($datos, [
                'ticket_id' => $ticket['id'] ?? null,
                'ticket_abierto' => $ticket !== null,
            ]),
            $ahora,
            $ticket,
            $horaConsulta,
            [
                'sin_mesas' => $sinMesas,
                'conflicto_fisico' => $conflictoFisico,
            ]
        );
        $ventana = (string)($politica['ventana_pos'] ?? 'futura');
        $puedeIniciar = (bool)$politica['puede_iniciar_reservacion'];
        $puedeAusencia = (bool)$politica['puede_marcar_no_show'];
        $accionPendiente = $politica['accion_pendiente'];
        $acciones = (array)($politica['acciones'] ?? []);
        $muestraAdvertencia = (bool)$politica['muestra_advertencia'];

        $motivo = self::motivoOperativo(
            $estado,
            $ventana,
            $sinMesas,
            $conflictoFisico,
            $ticket !== null
        );
        $motivoBloqueo = self::motivoBloqueo(
            $estado,
            $sinMesas,
            $conflictoFisico,
            $mesasBloqueantes,
            $ticket !== null,
            (bool)$politica['puede_iniciar_reservacion'],
            (bool)$politica['tolerancia_vencida']
        );
        $codigoBloqueo = $mesasBloqueantes[0]['codigo'] ?? self::codigoBloqueo($motivoBloqueo);
        $bloqueo = $codigoBloqueo !== null
            ? ReservacionErrorCatalog::presentar($codigoBloqueo)
            : null;
        $minutosRestantes = $politica['minutos_para_inicio'];
        $minutosPara = $minutosRestantes === null
            ? null
            : max(0, (int)$minutosRestantes);
        $capacidad = (int)($datos['capacidad_total'] ?? $datos['capacidad_asignada'] ?? 0);
        if ($capacidad < 1) {
            $capacidad = array_sum(array_map(
                static fn(array $mesa): int => (int)($mesa['capacidad'] ?? 0),
                $mesasAsignadas
            ));
        }
        $nombresMesas = array_values(array_map(
            static fn(array $mesa): string => (string)($mesa['nombre'] ?? ''),
            $mesasAsignadas
        ));
        $updatedAt = (string)($datos['updated_at'] ?? $datos['created_at'] ?? '');
        $version = hash('sha256', $updatedAt . '|' . implode(',', $mesaIds));

        $serializado = [
            'schema_version' => self::SCHEMA_VERSION,
            'reservacion_id' => $reservacionId,
            // Alias de transporte conservado para consumidores existentes; la
            // identidad canónica es reservacion_id.
            'id' => $reservacionId,
            'estado' => $estado,
            'fecha' => (string)($datos['fecha'] ?? ''),
            'hora' => (string)($datos['hora'] ?? ''),
            'date' => (string)($datos['fecha'] ?? ''),
            'time' => (string)($datos['hora'] ?? ''),
            'nombre' => (string)($datos['nombre'] ?? ''),
            'contacto_tipo' => (string)($datos['contacto_tipo'] ?? 'ninguno'),
            'contacto' => array_key_exists('contacto', $datos) && $datos['contacto'] !== null
                ? (string)$datos['contacto']
                : null,
            'comensales' => (int)($datos['comensales'] ?? $datos['personas'] ?? 0),
            'personas' => (int)($datos['comensales'] ?? $datos['personas'] ?? 0),
            'nota' => (string)($datos['nota'] ?? ''),
            'comment' => (string)($datos['nota'] ?? ''),
            'comentario' => (string)($datos['nota'] ?? ''),
            'comentario_admin' => $datos['comentario_admin'] ?? null,
            'mesa_ids' => $mesaIds,
            'mesas' => $mesasAsignadas,
            'mesas_asignadas' => $nombresMesas,
            'mesas_count' => count($mesaIds),
            'capacidad_asignada' => $capacidad,
            'ticket_id' => $ticket['id'] ?? null,
            'ticket_abierto' => $ticket !== null,
            'ticket_mesa_ids' => $ticket['mesa_ids'] ?? [],
            'ticket' => $ticket,
            'ventana_operativa' => $ventana,
            'minutos_para_reservacion' => $minutosPara,
            'minutos_retraso' => (int)($politica['minutos_desde_inicio'] ?? 0),
            // Alias aditivos para el bloqueo de inicio multimesa. Se conserva
            // puede_iniciar_servicio para los consumidores existentes.
            'puede_iniciar' => $puedeIniciar,
            'puede_iniciar_servicio' => $puedeIniciar,
            'motivo_bloqueo' => $codigoBloqueo,
            'bloqueo' => $bloqueo,
            'accion_pendiente' => $accionPendiente,
            'acciones' => $acciones,
            'puede_marcar_no_show' => $puedeAusencia,
            'mesas_bloqueantes' => $mesasBloqueantes,
            'puede_registrar_ausencia' => $puedeAusencia,
            'bloquea_walk_ins' => (bool)$politica['bloquea_walk_ins'],
            'muestra_advertencia' => $muestraAdvertencia,
            'advertencia' => $muestraAdvertencia
                ? ReservacionErrorCatalog::presentar('RESERVACION_PROXIMA', [
                    'hora' => substr((string)($datos['hora'] ?? ''), 0, 5),
                    'minutos_restantes' => $minutosPara ?? 0,
                ])
                : null,
            'influye_disponibilidad' => (bool)$politica['influye_disponibilidad'],
            'dentro_tolerancia' => (bool)$politica['en_tolerancia'],
            'tolerancia_vencida' => (bool)$politica['tolerancia_vencida'],
            'ausencia_pendiente' => (bool)$politica['ausencia_pendiente'],
            'ocupada_fisicamente' => (bool)$politica['ocupada_fisicamente'],
            'bloqueada_en_intervalo' => false,
            'disponible_para_asignacion' => false,
            'disponible_para_ticket' => (bool)$politica['disponible_para_ticket'],
            'requiere_advertencia_ticket' => (bool)$politica['requiere_advertencia_ticket'],
            'puede_iniciar_reservacion' => (bool)$politica['puede_iniciar_reservacion'],
            'accion_primaria' => (string)$politica['accion_primaria'],
            'ventana_pos' => $politica['ventana_pos'],
            'ventana_visual_pos' => $politica['ventana_visual_pos'],
            'minutos_para_inicio' => $politica['minutos_para_inicio'],
            'proyeccion_mapa' => $politica['proyeccion_mapa'] ?? null,
            'conflicto_fisico' => $conflictoFisico,
            'hold_expires_at' => $datos['hold_expires_at'] ?? null,
            'estado_changed_at' => $datos['estado_changed_at'] ?? null,
            'tolerancia_hasta' => $politica['tolerancia_hasta'],
            'no_show_disponible' => $puedeAusencia,
            'elegible_no_show' => $puedeAusencia,
            'retrasada' => $ventana === 'ausencia_pendiente',
            'minutos_restantes' => $minutosRestantes,
            'editable' => (bool)ReservacionVigenciaService::clasificar($datos, $ahora)['editable'],
            'motivo_no_editable' => $opciones['motivo_no_editable'] ?? null,
            'version' => $version,
        ];

        // El origen es contexto exclusivo del mapa administrativo. Se agrega
        // sólo cuando ese consumidor lo solicita para no modificar la
        // respuesta canónica que consumen POS y la operación pública.
        if (!empty($opciones['incluir_contexto_administrativo'])) {
            $serializado['origen'] = (string)($datos['origen'] ?? '');
            $serializado['assignment_snapshot'] = [
                'mesa_ids' => $mesaIds,
                'version' => $version,
            ];
        }

        return $serializado;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    public static function ticket(array $ticket): array
    {
        return [
            'id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
            'nombre' => $ticket['nombre'] ?? null,
            'comensales' => (int)($ticket['comensales'] ?? 0),
            'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
            'estado' => (string)($ticket['estado'] ?? 'abierto'),
            'closed_at' => $ticket['closed_at'] ?? null,
            'reservacion_id' => !empty($ticket['reservacion_id'])
                ? (int)$ticket['reservacion_id']
                : null,
            'mesero_id' => $ticket['mesero_id'] ?? null,
            'mesa_ids' => self::ids($ticket['mesa_ids'] ?? []),
            'origen' => (string)($ticket['origen'] ?? 'walk_in'),
            'ticket_abierto' => true,
            'muestra_advertencia' => false,
            'reservaciones_proximas' => [],
        ];
    }

    /**
     * Devuelve el detalle seguro de cada mesa que impide iniciar una
     * reservación. No expone ids de tickets ni identidad de otras reservas.
     *
     * @param array<int, array<string, mixed>> $ocupacion
     * @param array<int, int> $mesaIds
     * @param array<int, array<string, mixed>> $mesas
     * @return array<int, array<string, mixed>>
     */
    public static function bloqueosOperativos(
        array $ocupacion,
        array $mesaIds,
        array $mesas,
        int $reservacionId = 0
    ): array {
        $mesaIds = self::ids($mesaIds);
        $porId = [];
        foreach ($mesas as $mesa) {
            $porId[(int)($mesa['id'] ?? 0)] = $mesa;
        }

        $eventosPorMesa = [];
        foreach ($ocupacion as $clave => $evento) {
            $candidatos = is_array($evento) && array_key_exists('mesa_id', $evento)
                ? [$evento]
                : (is_array($evento) ? $evento : []);
            foreach ($candidatos as $candidato) {
                if (!is_array($candidato)) {
                    continue;
                }
                $mesaId = (int)($candidato['mesa_id'] ?? $clave);
                if ($mesaId > 0) {
                    $eventosPorMesa[$mesaId][] = $candidato;
                }
            }
        }

        $bloqueos = [];
        foreach ($mesaIds as $mesaId) {
            $mesa = $porId[$mesaId] ?? null;
            $numero = (string)($mesa['numero'] ?? $mesaId);
            $nombre = trim((string)($mesa['nombre'] ?? ''));
            $etiqueta = $nombre !== '' ? $nombre : 'Mesa ' . $numero;

            if (!self::mesaUtilizable($mesa)) {
                $bloqueos[] = [
                    'mesa_id' => $mesaId,
                    'numero' => $numero,
                    'motivo' => 'MESA_NO_UTILIZABLE',
                ];
                continue;
            }

            foreach ($eventosPorMesa[$mesaId] ?? [] as $evento) {
                if (array_key_exists('bloquea_disponibilidad', $evento)
                    && !$evento['bloquea_disponibilidad']) {
                    continue;
                }
                $eventoReservacionId = (int)($evento['reservacion_id'] ?? 0);
                $ticketReservacionId = (int)($evento['ticket_reservacion_id'] ?? 0);
                if (($reservacionId > 0 && $eventoReservacionId === $reservacionId)
                    || ($reservacionId > 0 && $ticketReservacionId === $reservacionId)) {
                    continue;
                }

                $tipo = (string)($evento['tipo'] ?? $evento['fuente'] ?? '');
                if ($tipo === 'ticket_abierto' || (string)($evento['fuente'] ?? '') === 'ticket_abierto') {
                    $bloqueos[] = [
                        'mesa_id' => $mesaId,
                        'numero' => $numero,
                        'motivo' => 'TICKET_ABIERTO',
                    ];
                } elseif (in_array($tipo, ['reservacion', 'hold'], true)
                    || $eventoReservacionId > 0) {
                    $bloqueos[] = [
                        'mesa_id' => $mesaId,
                        'numero' => $numero,
                        'motivo' => 'OTRA_OPERACION',
                    ];
                } else {
                    $bloqueos[] = [
                        'mesa_id' => $mesaId,
                        'numero' => $numero,
                        'motivo' => 'CONFLICTO_ASIGNACION',
                    ];
                }
                break;
            }
        }

        return array_map(
            static fn(array $bloqueo): array => self::presentarBloqueo($bloqueo),
            $bloqueos
        );
    }

    private static function codigoBloqueo(?string $motivo): ?string
    {
        return match ($motivo) {
            'MESAS_ASIGNADAS_NO_DISPONIBLES' => 'MESA_OCUPADA',
            'TICKET_ABIERTO' => 'TICKET_ABIERTO',
            'TOLERANCIA_LLEGADA_VENCIDA' => 'TOLERANCIA_LLEGADA_VENCIDA',
            'MESAS_SIN_ASIGNAR' => 'SIN_ASIGNACION',
            'VENTANA_NO_PERMITIDA' => 'RESERVACION_PROXIMA',
            'ESTADO_NO_PERMITE_INICIO' => 'ESTADO_INVALIDO',
            default => null,
        };
    }

    /** @param array<string, mixed> $bloqueo @return array<string, mixed> */
    private static function presentarBloqueo(array $bloqueo): array
    {
        $motivo = (string)($bloqueo['motivo'] ?? '');
        $codigo = match ($motivo) {
            'MESA_NO_UTILIZABLE' => 'MESA_NO_RESERVABLE',
            'TICKET_ABIERTO' => 'TICKET_ABIERTO',
            'OTRA_OPERACION' => 'RESERVACION_BLOQUEANTE',
            'CONFLICTO_ASIGNACION' => 'CONFLICTO_DE_ASIGNACION',
            default => 'ERROR_INTERNO',
        };
        $contexto = [
            'mesa_id' => (int)($bloqueo['mesa_id'] ?? 0),
            'mesa_numero' => (string)($bloqueo['numero'] ?? ''),
        ];
        $presentacion = ReservacionErrorCatalog::presentar($codigo, $contexto);

        return [
            'mesa_id' => (int)($bloqueo['mesa_id'] ?? 0),
            'numero' => (string)($bloqueo['numero'] ?? ''),
            'codigo' => $presentacion['codigo'],
            'presentacion' => $presentacion,
        ];
    }

    /**
     * Serializa una mesa para los dos lectores operativos.
     *
     * @param array<string, mixed>|object $mesa
     * @return array<string, mixed>
     */
    public static function mesa($mesa): array
    {
        $datos = self::aArray($mesa);

        return [
            'id' => (int)($datos['id'] ?? 0),
            'numero' => (int)($datos['numero'] ?? 0),
            'nombre' => (string)($datos['nombre'] ?? ''),
            'etiqueta' => (string)($datos['nombre'] ?? ''),
            'tipo' => (string)($datos['tipo'] ?? 'mesa'),
            'capacidad' => (int)($datos['capacidad'] ?? 0),
            'pos_x' => (float)($datos['pos_x'] ?? 50),
            'pos_y' => (float)($datos['pos_y'] ?? 50),
            'ancho' => $datos['ancho'] ?? null,
            'alto' => $datos['alto'] ?? null,
            'activo' => filter_var($datos['activo'] ?? true, FILTER_VALIDATE_BOOL),
            'reservable' => filter_var($datos['reservable'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array<string, mixed>|object $registro
     * @return array<string, mixed>
     */
    private static function aArray($registro): array
    {
        return is_array($registro) ? $registro : get_object_vars($registro);
    }

    private static function fechaHoraConsulta(string $hora, string $fecha): ?DateTimeImmutable
    {
        $hora = HorarioReservacionService::normalizarHoraSql($hora);
        if ($fecha === '' || $hora === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($fecha . ' ' . $hora, ReservacionConfig::timezone());
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * @param mixed $valor
     * @return array<int, int>
     */
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

    /**
     * @param array<int, int> $ids
     * @param array<int, array<string, mixed>> $mesas
     * @param array<string, mixed> $datos
     * @return array<int, array<string, mixed>>
     */
    private static function mesasPorIds(array $ids, array $mesas, array $datos): array
    {
        $porId = [];
        foreach ($mesas as $mesa) {
            $porId[(int)($mesa['id'] ?? 0)] = $mesa;
        }

        $nombres = self::nombres($datos['mesas_asignadas'] ?? $datos['mesas'] ?? '');
        $resultado = [];
        foreach ($ids as $indice => $id) {
            $resultado[] = $porId[$id] ?? [
                'id' => $id,
                'numero' => 0,
                'nombre' => $nombres[$indice] ?? 'Mesa ' . $id,
                'tipo' => 'mesa',
                'capacidad' => 0,
                'pos_x' => 0,
                'pos_y' => 0,
                'activo' => true,
                'reservable' => true,
            ];
        }

        return $resultado;
    }

    /**
     * @param mixed $valor
     * @return array<int, string>
     */
    private static function nombres($valor): array
    {
        if (is_array($valor)) {
            return array_values(array_filter(array_map(
                static fn($nombre): string => is_array($nombre)
                    ? (string)($nombre['nombre'] ?? '')
                    : (string)$nombre,
                $valor
            )));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$valor))));
    }

    private static function motivoOperativo(
        string $estado,
        string $ventana,
        bool $sinMesas,
        bool $conflicto,
        bool $ticketAbierto
    ): string {
        if ($ticketAbierto && $estado === 'en_curso') {
            return 'ticket_abierto';
        }
        if ($sinMesas && $estado === 'confirmada') {
            return 'sin_mesas';
        }
        if ($conflicto) {
            return 'conflicto_fisico';
        }

        return match ($ventana) {
            'advertencia' => 'reservacion_proxima',
            'bloqueo' => 'reservacion_inminente',
            'tolerancia' => 'tolerancia_vigente',
            'ausencia_pendiente' => 'tolerancia_vencida',
            'en_curso' => 'ticket_abierto',
            default => 'reservacion_futura',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $mesasBloqueantes
     */
    private static function motivoBloqueo(
        string $estado,
        bool $sinMesas,
        bool $conflictoFisico,
        array $mesasBloqueantes,
        bool $ticketAbierto,
        bool $ventanaPermitida,
        bool $toleranciaVencida
    ): ?string {
        if ($mesasBloqueantes !== [] || $conflictoFisico) {
            return 'MESAS_ASIGNADAS_NO_DISPONIBLES';
        }
        if ($ticketAbierto) {
            return 'TICKET_ABIERTO';
        }
        if ($estado === 'confirmada' && $toleranciaVencida) {
            return 'TOLERANCIA_LLEGADA_VENCIDA';
        }
        if ($sinMesas && $estado === 'confirmada') {
            return 'MESAS_SIN_ASIGNAR';
        }
        if ($estado === 'confirmada' && !$ventanaPermitida) {
            return 'VENTANA_NO_PERMITIDA';
        }
        if ($estado !== 'confirmada' && $estado !== 'en_curso') {
            return 'ESTADO_NO_PERMITE_INICIO';
        }

        return null;
    }

    /** @param array<string, mixed>|null $mesa */
    private static function mesaUtilizable(?array $mesa): bool
    {
        return $mesa !== null
            && filter_var($mesa['activo'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($mesa['reservable'] ?? false, FILTER_VALIDATE_BOOL)
            && (string)($mesa['tipo'] ?? '') === 'mesa'
            && (int)($mesa['capacidad'] ?? 0) > 0;
    }
}
