<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\TicketMesa;

/**
 * Fachada administrativa del mapa compartido.
 *
 * La lectura recibe la proyeccion canonica ya serializada. Las escrituras se
 * ejecutan con el mismo dominio de ocupacion/asignacion, pero con los locks
 * globales de operacion que necesita el mapa administrativo.
 */
final class ReservacionMapaAdministrativaService
{
    /**
     * @param array<int, array<string, mixed>> $reservaciones
     * @param array<int, array<string, mixed>> $reservacionesOperativas
     * @return array{reservaciones: array<int, array<string, mixed>>, reservaciones_admin: array<int, array<string, mixed>>}
     */
    public static function proyectar(
        array $reservaciones,
        array $reservacionesOperativas
    ): array {
        $operativas = [];
        foreach ($reservacionesOperativas as $reservacion) {
            $operativas[(int)($reservacion['id'] ?? 0)] = true;
        }

        $proyeccion = [];
        foreach ($reservaciones as $reservacion) {
            $id = (int)($reservacion['id'] ?? 0);
            $estado = (string)($reservacion['estado'] ?? '');
            $contacto = trim((string)($reservacion['contacto'] ?? ''));
            $contactoTipo = (string)($reservacion['contacto_tipo'] ?? 'ninguno');
            $tieneContacto = $contacto !== '' && $contactoTipo !== 'ninguno';
            $terminal = in_array($estado, ReservacionConfig::ESTADOS_FINALES, true);
            $enListaOperativa = isset($operativas[$id]);
            $ticketAbierto = !empty($reservacion['ticket_abierto']);

            $reservacion['en_lista_operativa'] = $enListaOperativa;
            $reservacion['assignment_snapshot'] = (array)($reservacion['assignment_snapshot'] ?? [
                'mesa_ids' => self::ids($reservacion['mesa_ids'] ?? []),
                'version' => (string)($reservacion['version'] ?? ''),
            ]);
            $reservacion['en_lista_terminal'] = $terminal;
            $reservacion['en_proyeccion_mapa'] = $estado !== 'reemplazada' && $enListaOperativa;
            $reservacion['asignacion_pendiente'] = $estado === 'confirmada'
                && empty($reservacion['mesa_ids']);
            $reservacion['contacto_presente'] = $tieneContacto;
            $reservacion['contacto_visible'] = $tieneContacto ? $contacto : 'Sin contacto';
            $reservacion['origen_visible'] = (string)($reservacion['origen'] ?? 'admin') === 'landing'
                ? 'Publica'
                : 'Administrativa';
            $nota = trim((string)($reservacion['nota'] ?? ''));
            $reservacion['nota_breve'] = function_exists('mb_substr')
                ? mb_substr($nota, 0, 140)
                : substr($nota, 0, 140);
            $reservacion['contexto_admin'] = $terminal
                ? 'terminal'
                : (($estado === 'en_curso' || $ticketAbierto) ? 'en_curso' : 'reservacion');
            $reservacion['puede_liberar_asignacion'] = !$terminal
                && $estado === 'confirmada'
                && (string)($reservacion['origen'] ?? '') === 'admin'
                && !$ticketAbierto
                && !empty($reservacion['mesa_ids']);

            $proyeccion[] = $reservacion;
        }

        $administrativas = array_values(array_filter(
            $proyeccion,
            static fn(array $reservacion): bool => !empty($reservacion['en_lista_operativa'])
                || !empty($reservacion['en_lista_terminal'])
        ));

        return [
            'reservaciones' => $proyeccion,
            'reservaciones_admin' => $administrativas,
        ];
    }

    public static function guardarAsignacion(
        int $reservacionId,
        array $mesaIds,
        array $opciones = []
    ): array {
        $fecha = trim((string)($opciones['fecha_esperada'] ?? ''));
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS];
        }

        return self::conLocksFecha($fecha, static function () use ($reservacionId, $mesaIds, $opciones): array {
            $db = ActiveRecord::getDB();
            if (!$db->begin_transaction()) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }

            try {
                $resultado = AsignacionMesasService::asignarManual(
                    $reservacionId,
                    $mesaIds,
                    false,
                    false,
                    array_merge($opciones, [
                        'modo_administrativo_mapa' => true,
                    ])
                );

                if (!($resultado['ok'] ?? false)) {
                    $db->rollback();
                    return $resultado;
                }

                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar la asignacion administrativa.');
                }

                return $resultado;
            } catch (\Throwable $error) {
                $db->rollback();
                error_log('ReservacionMapaAdministrativaService::guardarAsignacion - ' . $error->getMessage());
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }
        });
    }

    public static function liberarAsignacion(
        int $reservacionId,
        array $opciones = []
    ): array {
        $fecha = trim((string)($opciones['fecha_esperada'] ?? ''));
        if (!HorarioReservacionService::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS];
        }

        return self::conLocksFecha($fecha, static function () use ($reservacionId, $opciones): array {
            $db = ActiveRecord::getDB();
            if (!$db->begin_transaction()) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }

            try {
                $reservacion = self::filaReservacion($reservacionId);
                if (!$reservacion) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => AsignacionMesasService::RESERVACION_NO_EXISTE];
                }

                $codigo = self::validarContexto($reservacion, $reservacionId, $opciones);
                if ($codigo !== null) {
                    $db->rollback();
                    return $codigo;
                }

                if ((string)$reservacion['estado'] !== 'confirmada') {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => AsignacionMesasService::ESTADO_INVALIDO];
                }
                $liberacionOperativa = !empty($opciones['permitir_liberacion_operativa']);
                if (!$liberacionOperativa && (string)($reservacion['origen'] ?? '') !== 'admin') {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => AsignacionMesasService::LIBERACION_NO_AUTORIZADA];
                }

                $ticket = self::fila(
                    "SELECT id FROM tickets WHERE reservacion_id = {$reservacionId} AND "
                    . TicketMesa::condicionSqlAbierto('tickets') . " LIMIT 1 FOR UPDATE"
                );
                if ($ticket) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => AsignacionMesasService::RESERVACION_NO_EDITABLE];
                }

                $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
                Mesa::bloquearPorIds($mesaIds);
                $confirmaciones = self::confirmaciones($opciones['confirmaciones'] ?? []);
                if (!in_array(AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL, $confirmaciones, true)) {
                    $db->rollback();
                    return [
                        'ok' => false,
                        'codigo' => AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL,
                        'requiere_confirmacion' => true,
                        'confirmaciones_requeridas' => [AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL],
                        'mesa_ids' => $mesaIds,
                    ];
                }

                ReservacionMesa::eliminarAsignacion($reservacionId);
                if (!$db->query(
                    "UPDATE reservaciones
                     SET updated_at = CASE
                         WHEN updated_at IS NULL THEN CURRENT_TIMESTAMP
                         ELSE GREATEST(CURRENT_TIMESTAMP, updated_at + INTERVAL 1 SECOND)
                     END
                     WHERE id = {$reservacionId}"
                )) {
                    throw new \RuntimeException('No fue posible actualizar la version de liberacion.');
                }
                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar la liberacion de mesas.');
                }

                return [
                    'ok' => true,
                    'codigo' => AsignacionMesasService::ASIGNACION_GUARDADA,
                    'mesa_ids' => [],
                    'mesas_liberadas' => $mesaIds,
                ];
            } catch (\Throwable $error) {
                $db->rollback();
                error_log('ReservacionMapaAdministrativaService::liberarAsignacion - ' . $error->getMessage());
                return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
            }
        });
    }

    /** @return array<string, mixed>|null */
    private static function validarContexto(array $reservacion, int $reservacionId, array $opciones): ?array
    {
        if (empty($opciones['validar_contexto']) || empty($opciones['contexto_completo'])) {
            return ['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS];
        }

        $mesaIdsActuales = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
        $esperadas = self::ids($opciones['mesa_ids_actuales'] ?? []);
        $comparables = $mesaIdsActuales;
        sort($esperadas, SORT_NUMERIC);
        sort($comparables, SORT_NUMERIC);
        $horaEsperada = HorarioReservacionService::normalizarHoraSql(
            (string)($opciones['hora_esperada'] ?? '')
        );
        $horaActual = HorarioReservacionService::normalizarHoraSql((string)$reservacion['hora']);
        $versionActual = self::version($reservacion, $mesaIdsActuales);

        if (
            (string)($opciones['fecha_esperada'] ?? '') !== (string)$reservacion['fecha']
            || $horaEsperada !== $horaActual
            || $esperadas !== $comparables
        ) {
            return [
                'ok' => false,
                'codigo' => AsignacionMesasService::VERSION_DESACTUALIZADA,
                'version_actual' => $versionActual,
            ];
        }

        $versionEsperada = trim((string)($opciones['version_esperada'] ?? ''));
        if ($versionEsperada === '' || !hash_equals($versionActual, $versionEsperada)) {
            return [
                'ok' => false,
                'codigo' => AsignacionMesasService::VERSION_DESACTUALIZADA,
                'version_actual' => $versionActual,
            ];
        }

        return null;
    }

    private static function conLocksFecha(string $fecha, callable $callback): array
    {
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = false;
        try {
            if (!HorarioConfigLock::adquirir($db)) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::CONFLICTO_CONCURRENTE];
            }
            $lockHorario = true;
            if (!FechaOperacionLock::adquirir($db, $fecha)) {
                return ['ok' => false, 'codigo' => AsignacionMesasService::CONFLICTO_CONCURRENTE];
            }
            $lockFecha = true;
            return $callback();
        } catch (\Throwable $error) {
            error_log('ReservacionMapaAdministrativaService::conLocksFecha - ' . $error->getMessage());
            return ['ok' => false, 'codigo' => AsignacionMesasService::ERROR_INTERNO];
        } finally {
            if ($lockFecha) {
                FechaOperacionLock::liberar($db, $fecha);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function filaReservacion(int $reservacionId): ?array
    {
        return self::fila(
            "SELECT id, fecha, hora, estado, origen, contacto_tipo, contacto, updated_at, created_at
             FROM reservaciones WHERE id = {$reservacionId} LIMIT 1 FOR UPDATE"
        );
    }

    /** @return array<string, mixed>|null */
    private static function fila(string $query): ?array
    {
        $resultado = ActiveRecord::getDB()->query($query);
        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        return $fila;
    }

    private static function version(array $reservacion, array $mesaIds): string
    {
        return ReservacionAsignacionVersionService::calcular(
            (string)($reservacion['updated_at'] ?: $reservacion['created_at']),
            $mesaIds
        );
    }

    /** @return array<int, int> */
    private static function ids($ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<int, string> */
    private static function confirmaciones($confirmaciones): array
    {
        if (!is_array($confirmaciones)) {
            $confirmaciones = [$confirmaciones];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn($codigo): string => trim((string)$codigo),
            $confirmaciones
        ))));
    }
}
