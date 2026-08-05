<?php

namespace Services;

use DateTimeImmutable;

/**
 * Produce el contrato operativo compartido por POS y operaciÃ³n.
 *
 * Esta clase no consulta la base de datos ni decide asignaciones. Recibe filas
 * y ocupaciÃ³n ya leÃ­das, aplica la vigencia central y presenta una sola forma
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
        $vigencia = ReservacionVigenciaService::clasificar(
            array_merge($datos, [
                'ticket_id' => $ticket['id'] ?? null,
                'ticket_abierto' => $ticket !== null,
            ]),
            $ahora,
            $ticket
        );
        $ventana = (string)($vigencia['ventana_operativa']['estado'] ?? 'futura');
        if (($datos['estado'] ?? '') === 'en_curso') {
            $ventana = 'en_curso';
        }

        $estado = (string)($datos['estado'] ?? '');
        $conflictoFisico = (bool)($opciones['conflicto_fisico'] ?? false);
        $mesasBloqueantes = array_values(array_filter(
            (array)($opciones['mesas_bloqueantes'] ?? []),
            static fn($bloqueo): bool => is_array($bloqueo)
        ));
        $sinMesas = $mesaIds === [];
        $puedeIniciar = (bool)$vigencia['puede_iniciar_servicio']
            && !$sinMesas
            && !$conflictoFisico;
        $puedeAusencia = (bool)$vigencia['elegible_no_show']
            && $ticket === null;
        $accionPendiente = $puedeAusencia ? 'REGISTRAR_AUSENCIA' : null;
        $bloqueaWalkIns = $estado === 'confirmada'
            && in_array($ventana, ['0_30', 'tolerancia', 'tolerancia_vencida'], true)
            && (bool)$vigencia['influye_disponibilidad'];
        $muestraAdvertencia = $estado === 'confirmada'
            && ($ventana === '30_60' || (bool)($opciones['muestra_advertencia'] ?? false));

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
            (bool)$vigencia['puede_iniciar_servicio'],
            (bool)$vigencia['tolerancia_vencida']
        );
        $mensajeBloqueo = self::mensajeBloqueo(
            $motivoBloqueo,
            $mesasBloqueantes
        );
        $accionSugerida = self::accionSugerida($motivoBloqueo, $mesasBloqueantes);
        $minutosRestantes = $vigencia['ventana_operativa']['minutos_restantes'] ?? null;
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
            // identidad canÃ³nica es reservacion_id.
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
            'minutos_retraso' => (int)($vigencia['ventana_operativa']['minutos_retraso'] ?? 0),
            // Alias aditivos para el bloqueo de inicio multimesa. Se conserva
            // puede_iniciar_servicio para los consumidores existentes.
            'puede_iniciar' => $puedeIniciar,
            'puede_iniciar_servicio' => $puedeIniciar,
            'motivo_bloqueo' => $motivoBloqueo,
            'mensaje_bloqueo' => $mensajeBloqueo,
            'accion_sugerida' => $accionSugerida,
            'accion_pendiente' => $accionPendiente,
            'puede_marcar_no_show' => $puedeAusencia,
            'mesas_bloqueantes' => $mesasBloqueantes,
            'puede_registrar_ausencia' => $puedeAusencia,
            'bloquea_walk_ins' => $bloqueaWalkIns,
            'muestra_advertencia' => $muestraAdvertencia,
            'influye_disponibilidad' => (bool)$vigencia['influye_disponibilidad'],
            'motivo' => $motivo,
            'motivo_operativo' => $motivo,
            'conflicto_fisico' => $conflictoFisico,
            'hold_expires_at' => $datos['hold_expires_at'] ?? null,
            'estado_changed_at' => $datos['estado_changed_at'] ?? null,
            'tolerancia_hasta' => $vigencia['limite_tolerancia'],
            'no_show_disponible' => $puedeAusencia,
            'elegible_no_show' => $puedeAusencia,
            'retrasada' => $ventana === 'tolerancia_vencida',
            'minutos_restantes' => $minutosRestantes,
            'editable' => (bool)$vigencia['editable'],
            'motivo_no_editable' => $opciones['motivo_no_editable'] ?? null,
            'version' => $version,
        ];

        // El origen es contexto exclusivo del mapa administrativo. Se agrega
        // sólo cuando ese consumidor lo solicita para no modificar la
        // respuesta canónica que consumen POS y la operación pública.
        if (!empty($opciones['incluir_contexto_administrativo'])) {
            $serializado['origen'] = (string)($datos['origen'] ?? '');
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
                    'descripcion' => $etiqueta . ' ya no está disponible para iniciar el servicio.',
                    'accion_sugerida' => 'Actualiza la asignación de la reservación antes de continuar.',
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
                        'descripcion' => $etiqueta . ' tiene un ticket abierto.',
                        'accion_sugerida' => 'Cierra o mueve ese servicio antes de iniciar la reservación.',
                    ];
                } elseif (in_array($tipo, ['reservacion', 'hold'], true)
                    || $eventoReservacionId > 0) {
                    $bloqueos[] = [
                        'mesa_id' => $mesaId,
                        'numero' => $numero,
                        'motivo' => 'OTRA_OPERACION',
                        'descripcion' => $etiqueta . ' está siendo utilizada por otra operación.',
                        'accion_sugerida' => 'Libera la mesa o actualiza la asignación desde el mapa.',
                    ];
                } else {
                    $bloqueos[] = [
                        'mesa_id' => $mesaId,
                        'numero' => $numero,
                        'motivo' => 'CONFLICTO_ASIGNACION',
                        'descripcion' => $etiqueta . ' presenta un conflicto de disponibilidad.',
                        'accion_sugerida' => 'Actualiza la información antes de volver a intentar.',
                    ];
                }
                break;
            }
        }

        return $bloqueos;
    }

    /** @param array<int, array<string, mixed>> $bloqueos */
    public static function mensajeBloqueoMesas(array $bloqueos): string
    {
        $total = count($bloqueos);
        if ($total <= 1) {
            return 'No se puede iniciar el servicio porque una de las mesas asignadas no está disponible.';
        }
        return 'No se puede iniciar el servicio porque ' . $total
            . ' mesas asignadas no están disponibles.';
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
            '30_60' => 'reservacion_proxima',
            '0_30' => 'reservacion_inminente',
            'tolerancia' => 'tolerancia_vigente',
            'tolerancia_vencida' => 'tolerancia_vencida',
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

    /**
     * @param array<int, array<string, mixed>> $mesasBloqueantes
     */
    private static function mensajeBloqueo(?string $motivo, array $mesasBloqueantes): ?string
    {
        if ($mesasBloqueantes !== []) {
            return self::mensajeBloqueoMesas($mesasBloqueantes);
        }

        return match ($motivo) {
            'MESAS_ASIGNADAS_NO_DISPONIBLES' => 'No se puede iniciar el servicio porque una de las mesas asignadas no está disponible.',
            'TICKET_ABIERTO' => 'Esta reservación ya tiene un ticket abierto; continúa desde ese servicio.',
            'TOLERANCIA_LLEGADA_VENCIDA' => 'La tolerancia de llegada ya venció. Registra la ausencia antes de utilizar la mesa.',
            'MESAS_SIN_ASIGNAR' => 'No se puede iniciar el servicio porque la reservación no tiene mesas asignadas.',
            'VENTANA_NO_PERMITIDA' => 'El servicio aún no puede iniciar porque la reservación está fuera de la ventana permitida.',
            'ESTADO_NO_PERMITE_INICIO' => 'El estado actual de la reservación no permite iniciar el servicio.',
            default => null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $mesasBloqueantes
     */
    private static function accionSugerida(?string $motivo, array $mesasBloqueantes): ?string
    {
        if ($mesasBloqueantes !== []) {
            $acciones = array_values(array_unique(array_filter(array_map(
                static fn(array $bloqueo): string => (string)($bloqueo['accion_sugerida'] ?? ''),
                $mesasBloqueantes
            ))));
            return implode(' ', $acciones);
        }

        return match ($motivo) {
            'MESAS_ASIGNADAS_NO_DISPONIBLES' => 'Actualiza la información de disponibilidad antes de volver a intentar.',
            'TICKET_ABIERTO' => 'Continúa desde el ticket abierto.',
            'TOLERANCIA_LLEGADA_VENCIDA' => 'Registra la ausencia antes de utilizar la mesa.',
            'MESAS_SIN_ASIGNAR' => 'Actualiza la asignación antes de iniciar.',
            'VENTANA_NO_PERMITIDA' => 'Espera a la ventana permitida para iniciar.',
            'ESTADO_NO_PERMITE_INICIO' => 'Actualiza la reservación antes de iniciar.',
            default => null,
        };
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
