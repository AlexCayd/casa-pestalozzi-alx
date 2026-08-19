<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Reservacion;

/**
 * Autoridad del seguimiento que nace al cambiar el horario efectivo.
 *
 * La notificación futura se representa en la propia fila afectada: preparar
 * un aviso genera un hash de acceso y una fecha durable. La presentación
 * persistente vive en el buzón genérico y no en una outbox del módulo.
 */
final class HorarioOperacionImpactoService
{
    public const ESTADO_IMPACTO_PENDIENTE = 'pendiente';
    public const ESTADO_IMPACTO_RESUELTO = 'resuelto';
    public const ESTADO_ITEM_PENDIENTE = 'pendiente_notificacion';
    public const ESTADO_ITEM_PREPARADO = 'notificacion_preparada';
    public const ESTADO_ITEM_SIN_CONTACTO = 'sin_contacto';
    public const ESTADO_ITEM_MANUAL = 'atendida_manual';
    public const ESTADO_ITEM_CLIENTE = 'resuelta_por_cliente';

    /** @return array{semanal: array<int, array<string, mixed>>, excepciones: array<string, array<string, mixed>>} */
    public static function snapshotActual(): array
    {
        $semanal = [];
        foreach (\Model\HorarioOperacion::todosOrdenados() as $horario) {
            $semanal[(int)$horario->dia_semana] = [
                'abierto' => (int)$horario->abierto === 1,
                'hora_apertura' => self::hora((string)$horario->hora_apertura),
                'hora_cierre' => self::hora((string)$horario->hora_cierre),
            ];
        }

        $excepciones = [];
        foreach (\Model\ExcepcionOperacion::listarOrdenadas() as $excepcion) {
            $excepciones[(string)$excepcion->fecha] = [
                'id' => (int)$excepcion->id,
                'fecha' => (string)$excepcion->fecha,
                'tipo' => (string)$excepcion->tipo,
                'hora_apertura' => self::hora((string)$excepcion->hora_apertura),
                'hora_cierre' => self::hora((string)$excepcion->hora_cierre),
                'activo' => (int)$excepcion->activo === 1,
            ];
        }

        return ['semanal' => $semanal, 'excepciones' => $excepciones];
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarHorarioSemanal(array $horarios): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;
        $despues['semanal'] = [];
        foreach ($horarios as $horario) {
            $dia = (int)($horario['dia_semana'] ?? -1);
            $despues['semanal'][$dia] = [
                'abierto' => (int)($horario['abierto'] ?? 0) === 1,
                'hora_apertura' => self::hora((string)($horario['hora_apertura'] ?? '')),
                'hora_cierre' => self::hora((string)($horario['hora_cierre'] ?? '')),
            ];
        }
        ksort($despues['semanal']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarExcepcion(?array $datos, ?int $id = null): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;

        if ($id !== null) {
            foreach ($despues['excepciones'] as $fecha => $excepcion) {
                if ((int)($excepcion['id'] ?? 0) === $id) {
                    unset($despues['excepciones'][$fecha]);
                }
            }
        }

        if ($datos !== null) {
            $fecha = (string)($datos['fecha'] ?? '');
            if ($fecha !== '') {
                $despues['excepciones'][$fecha] = [
                    'id' => $id ?? 0,
                    'fecha' => $fecha,
                    'tipo' => (string)($datos['tipo'] ?? 'cerrado'),
                    'hora_apertura' => self::hora((string)($datos['hora_apertura'] ?? '')),
                    'hora_cierre' => self::hora((string)($datos['hora_cierre'] ?? '')),
                    'activo' => (int)($datos['activo'] ?? 0) === 1,
                ];
            }
        }
        ksort($despues['excepciones']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /** @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>} */
    public static function evaluarSnapshots(array $antes, array $despues): array
    {
        $conflictos = [];
        foreach (Reservacion::buscarFuturasActivas(
            ReservacionConfig::fechaActual(),
            ReservacionConfig::horaActual()
        ) as $reservacion) {
            $fecha = (string)$reservacion->fecha;
            $hora = self::hora((string)$reservacion->hora);
            if ($hora === null) {
                continue;
            }
            if (self::estaDentro($fecha, $hora, $antes) && !self::estaDentro($fecha, $hora, $despues)) {
                $conflictos[] = [
                    'id' => (int)$reservacion->id,
                    'reservacion_id' => (int)$reservacion->id,
                    'nombre' => (string)$reservacion->nombre,
                    'fecha' => $fecha,
                    'hora' => substr($hora, 0, 5),
                    'estado' => (string)$reservacion->estado,
                ];
            }
        }

        return ['antes' => $antes, 'despues' => $despues, 'conflictos' => $conflictos];
    }

    /** Persiste el lote y sus filas hijas dentro de la transacción del horario. */
    public static function persistir(
        array $evaluacion,
        string $tipoOrigen,
        ?int $origenId,
        ?int $usuarioId
    ): ?int {
        $conflictos = (array)($evaluacion['conflictos'] ?? []);
        if ($conflictos === []) {
            return null;
        }

        $db = ActiveRecord::getDB();
        $dedupKey = self::dedupKey($tipoOrigen, $origenId, $evaluacion);
        $stmt = $db->prepare(
            "INSERT INTO horario_impactos
                (tipo_origen, origen_id, estado, dedup_key, created_by)
             VALUES (?, NULLIF(?, 0), 'pendiente', ?, NULLIF(?, 0))
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el impacto de horario.');
        }
        $origen = $origenId ?? 0;
        $usuario = $usuarioId ?? 0;
        $stmt->bind_param('sisi', $tipoOrigen, $origen, $dedupKey, $usuario);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $impactoId = (int)$db->insert_id;
        $stmt->close();
        if ($impactoId < 1) {
            throw new \RuntimeException('No fue posible identificar el impacto de horario.');
        }

        $ids = array_values(array_filter(array_unique(array_map(
            static fn(array $conflicto): int => (int)($conflicto['reservacion_id'] ?? $conflicto['id'] ?? 0),
            $conflictos
        )), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new \RuntimeException('El impacto no contiene reservaciones válidas.');
        }

        $listaIds = implode(',', $ids);
        $stmt = $db->prepare(
            "INSERT IGNORE INTO horario_impacto_reservaciones
                (impacto_id, reservacion_id, estado)
             SELECT ?, r.id,
                CASE WHEN r.contacto_tipo IN ('email', 'telefono')
                           AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                     THEN 'pendiente_notificacion' ELSE 'sin_contacto' END
             FROM reservaciones r
             WHERE r.id IN ({$listaIds})"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar las reservaciones afectadas.');
        }
        $stmt->bind_param('i', $impactoId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
        self::clasificarSeguimientosEnTransaccion($db, $impactoId, $usuarioId);
        self::actualizarEstadoImpacto($db, $impactoId);

        return $impactoId;
    }

    /** Una sola consulta para la alerta global y su primer destino. */
    public static function resumenPendientes(): array
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT COUNT(*) AS cantidad, MIN(impacto_id) AS primer_impacto_id
             FROM (
                 SELECT ir.impacto_id
                 FROM horario_impacto_reservaciones ir
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 WHERE i.estado = 'pendiente'
                   AND ir.estado IN ('pendiente_notificacion', 'sin_contacto')
                 GROUP BY ir.id, ir.impacto_id
             ) pendientes"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: [];
        $resultado->free();

        return [
            'cantidad' => (int)($fila['cantidad'] ?? 0),
            'primer_impacto_id' => !empty($fila['primer_impacto_id']) ? (int)$fila['primer_impacto_id'] : null,
        ];
    }

    public static function contarPendientes(): int
    {
        return (int)self::resumenPendientes()['cantidad'];
    }

    public static function contarPendientesReservaciones(): int
    {
        return self::contarPendientes();
    }

    public static function primerPendienteId(): ?int
    {
        return self::resumenPendientes()['primer_impacto_id'];
    }

    public static function esPendiente(int $impactoId): bool
    {
        if ($impactoId < 1) {
            return false;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT 1
             FROM horario_impactos i
             JOIN horario_impacto_reservaciones ir ON ir.impacto_id = i.id
             WHERE i.id = ? AND i.estado = 'pendiente'
               AND ir.estado IN ('pendiente_notificacion', 'sin_contacto')
             LIMIT 1"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $pendiente = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $pendiente;
    }

    /** @return array<string, mixed>|null */
    public static function obtener(int $impactoId): ?array
    {
        if ($impactoId < 1) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT i.id AS impacto_id, i.tipo_origen, i.origen_id, i.estado AS impacto_estado,
                    i.created_at AS impacto_created_at, ir.id AS impacto_reservacion_id,
                    ir.reservacion_id, ir.estado, ir.notification_prepared_at,
                    ir.access_expires_at, ir.access_invalidated_at,
                    ir.resolved_by, ir.resolved_at,
                    r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora, r.comensales
             FROM horario_impactos i
             JOIN horario_impacto_reservaciones ir ON ir.impacto_id = i.id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE i.id = ?
             ORDER BY
                 CASE WHEN r.contacto_tipo IN ('email', 'telefono')
                           AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                      THEN 0 ELSE 1 END,
                 r.fecha ASC, r.hora ASC, r.id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible consultar el seguimiento.');
        }
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        $impacto = null;
        while ($fila = $resultado->fetch_assoc()) {
            $impacto ??= [
                'id' => (int)$fila['impacto_id'],
                'tipo_origen' => (string)$fila['tipo_origen'],
                'origen_id' => $fila['origen_id'] !== null ? (int)$fila['origen_id'] : null,
                'estado' => (string)$fila['impacto_estado'],
                'created_at' => (string)$fila['impacto_created_at'],
            ];
            $tieneContacto = self::filaTieneContacto($fila);
            $filas[] = [
                'id' => (int)$fila['impacto_reservacion_id'],
                'reservacion_id' => (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'comensales' => (int)$fila['comensales'],
                'contacto_tipo' => $tieneContacto ? (string)$fila['contacto_tipo'] : 'ninguno',
                'contacto' => $tieneContacto
                    ? ContactoService::enmascarar((string)$fila['contacto_tipo'], (string)$fila['contacto'])
                    : '',
                'tiene_contacto' => $tieneContacto,
                'estado' => (string)$fila['estado'],
                'notification_prepared_at' => $fila['notification_prepared_at'],
                'access_expires_at' => $fila['access_expires_at'],
                'access_invalidated_at' => $fila['access_invalidated_at'],
                'manual_habilitada' => false,
                'test_link_disponible' => self::esEntornoPruebas()
                    && (string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO
                    && $fila['access_invalidated_at'] === null,
            ];
        }
        $stmt->close();
        if ($impacto === null) {
            return null;
        }

        $manualHabilitada = self::faseManualDisponible($impactoId);
        foreach ($filas as &$fila) {
            $fila['manual_habilitada'] = $manualHabilitada && !$fila['tiene_contacto'];
        }
        unset($fila);
        $impacto['reservaciones'] = $filas;
        $impacto['manual_habilitada'] = $manualHabilitada;
        $impacto['pendientes'] = count(array_filter(
            $filas,
            static fn(array $fila): bool => in_array(
                $fila['estado'], [self::ESTADO_ITEM_PENDIENTE, self::ESTADO_ITEM_SIN_CONTACTO], true
            )
        ));

        return $impacto;
    }

    /** Prepara un aviso y un acceso temporal; el token plano sólo vuelve en development/testing. */
    public static function prepararAviso(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if ((string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO) {
                return [
                    'ok' => true,
                    'codigo' => 'AVISO_PREPARADO',
                    'impacto_id' => (int)$fila['impacto_id'],
                    'impacto_reservacion_id' => (int)$fila['impacto_reservacion_id'],
                    'idempotente' => true,
                ];
            }
            if (!self::filaTieneContacto($fila)
                || (string)$fila['estado'] !== self::ESTADO_ITEM_PENDIENTE
                || (int)($fila['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO
            ) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }

            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expira = ReservacionConfig::ahora()
                ->modify('+' . ReservacionConfig::scheduleChangeAccessTtlMinutes() . ' minutes')
                ->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'notificacion_preparada', notification_prepared_at = NOW(),
                     access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL,
                     resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado = 'pendiente_notificacion'"
            );
            $id = (int)$fila['impacto_reservacion_id'];
            $stmt->bind_param('ssi', $hash, $expira, $id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $stmt->close();
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $id,
                BuzonNotificacionesService::PRIORIDAD_NORMAL,
                $expira
            );
            self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);

            $respuesta = [
                'ok' => true,
                'codigo' => 'AVISO_PREPARADO',
                'impacto_id' => (int)$fila['impacto_id'],
                'impacto_reservacion_id' => $id,
                'idempotente' => false,
                'expires_at' => $expira,
            ];
            if (self::esEntornoPruebas()) {
                $respuesta['test_access_url'] = self::accessUrl($token);
            }
            return $respuesta;
        });
    }

    /** @return array<string, mixed> */
    public static function prepararAvisosDisponibles(int $impactoId, ?int $adminId): array
    {
        $impacto = self::obtener($impactoId);
        if (!$impacto) {
            return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
        }
        $candidatos = array_values(array_filter(
            (array)$impacto['reservaciones'],
            static fn(array $fila): bool => $fila['tiene_contacto']
                && $fila['estado'] === self::ESTADO_ITEM_PENDIENTE
        ));
        $preparadas = 0;
        $fallas = [];
        foreach ($candidatos as $fila) {
            try {
                $resultado = self::prepararAviso($impactoId, (int)$fila['id'], $adminId);
                if (($resultado['ok'] ?? false) === true) {
                    $preparadas++;
                } else {
                    $fallas[] = [
                        'impacto_reservacion_id' => (int)$fila['id'],
                        'codigo' => (string)($resultado['codigo'] ?? 'ERROR_SEGUIMIENTO_HORARIO'),
                    ];
                }
            } catch (\Throwable $e) {
                error_log('HorarioOperacionImpactoService::prepararAvisosDisponibles - ' . $e->getMessage());
                $fallas[] = [
                    'impacto_reservacion_id' => (int)$fila['id'],
                    'codigo' => 'ERROR_SEGUIMIENTO_HORARIO',
                ];
            }
        }
        $fallidas = count($fallas);
        return [
            'ok' => $fallidas === 0,
            'codigo' => $fallidas === 0 ? 'AVISOS_PREPARADOS' : 'AVISOS_PARCIALES',
            'total' => count($candidatos),
            'preparadas' => $preparadas,
            'fallidas' => $fallidas,
            'fallas' => $fallas,
        ];
    }

    public static function agregarContacto(
        int $impactoId,
        int $impactoReservacionId,
        string $tipo,
        string $contacto,
        ?int $adminId
    ): array {
        try {
            $normalizado = ContactoService::normalizar($tipo, $contacto);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'codigo' => 'CONTACTO_INVALIDO'];
        }

        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $tipo, $normalizado, $adminId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_SIN_CONTACTO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            $stmt = $db->prepare(
                "UPDATE reservaciones
                 SET contacto_tipo = ?, contacto = ?
                 WHERE id = ? AND contacto_tipo = 'ninguno'
                   AND (contacto IS NULL OR TRIM(contacto) = '')"
            );
            $reservacion = (int)$fila['reservacion_id'];
            $stmt->bind_param('ssi', $tipo, $normalizado, $reservacion);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                return ['ok' => false, 'codigo' => 'CONTACTO_NO_EDITABLE'];
            }
            $stmt->close();
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'pendiente_notificacion', resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado = 'sin_contacto'"
            );
            $stmt->bind_param('i', $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            self::prepararFilaSiEsElegibleEnTransaccion($db, $impactoReservacionId, $adminId);
            self::actualizarEstadoImpacto($db, $impactoId);
            return ['ok' => true, 'codigo' => 'CONTACTO_AGREGADO'];
        });
    }

    public static function atenderManual(
        int $impactoId,
        int $impactoReservacionId,
        ?int $adminId,
        string $motivo = 'mantener_reservacion'
    ): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $adminId, $motivo): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || !in_array((string)$fila['estado'], [
                self::ESTADO_ITEM_PENDIENTE,
                self::ESTADO_ITEM_PREPARADO,
                self::ESTADO_ITEM_SIN_CONTACTO,
            ], true)) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            $motivo = in_array($motivo, ['mantener_reservacion', 'coordinacion_externa'], true)
                ? $motivo
                : 'mantener_reservacion';
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'atendida_manual', resolved_by = NULLIF(?, 0),
                     resolved_at = NOW(), access_invalidated_at = NOW()
                 WHERE id = ? AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')"
            );
            $usuario = $adminId ?? 0;
            $stmt->bind_param('ii', $usuario, $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            BuzonNotificacionesService::cerrarEnTransaccion(
                $db,
                (int)$fila['impacto_reservacion_id'],
                $adminId,
                $motivo
            );
            self::actualizarEstadoImpacto($db, $impactoId);
            return [
                'ok' => true,
                'codigo' => $motivo === 'coordinacion_externa'
                    ? 'AFECTACION_COORDINADA_EXTERNAMENTE'
                    : 'AFECTACION_ATENDIDA_MANUALMENTE',
            ];
        });
    }

    public static function regenerarAccesoDePrueba(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        if (!self::esEntornoPruebas()) {
            return ['ok' => false, 'codigo' => 'NO_DISPONIBLE'];
        }
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_PREPARADO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if (!self::filaTieneContacto($fila)
                || (int)($fila['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO
            ) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expira = ReservacionConfig::ahora()
                ->modify('+' . ReservacionConfig::scheduleChangeAccessTtlMinutes() . ' minutes')
                ->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL
                 WHERE id = ? AND estado = 'notificacion_preparada'"
            );
            $stmt->bind_param('ssi', $hash, $expira, $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            return [
                'ok' => true,
                'codigo' => 'LINK_PRUEBA_GENERADO',
                'test_access_url' => self::accessUrl($token),
                'expires_at' => $expira,
            ];
        });
    }

    public static function resolverPorCliente(int $reservacionId, string $fecha, string $hora, ?int $adminId = null): bool
    {
        return self::conTransaccion(function (\mysqli $db) use ($reservacionId, $fecha, $hora): bool {
            return self::resolverPorClienteEnTransaccion($db, $reservacionId, $fecha, $hora);
        }) === true;
    }

    /** Debe llamarse dentro de la transacción que confirma el reemplazo. */
    public static function accesoValidoEnTransaccion(\mysqli $db, int $impactoReservacionId, int $reservacionId): bool
    {
        if ($impactoReservacionId < 1 || $reservacionId < 1) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT id
             FROM horario_impacto_reservaciones
             WHERE id = ? AND reservacion_id = ?
               AND estado = 'notificacion_preparada'
               AND access_invalidated_at IS NULL
               AND access_expires_at > NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $impactoReservacionId, $reservacionId);
        $stmt->execute();
        $valido = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $valido;
    }

    /** Debe llamarse dentro de la transacción que confirma el reemplazo. */
    public static function resolverPorClienteEnTransaccion(\mysqli $db, int $reservacionId, string $fecha, string $hora): bool
    {
        if ($reservacionId < 1 || !HorarioOperacionService::estaAbierto($fecha, $hora)) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT ir.id, ir.impacto_id
             FROM horario_impacto_reservaciones ir
             WHERE ir.reservacion_id = ? AND ir.estado = 'notificacion_preparada'
             FOR UPDATE"
        );
        $stmt->bind_param('i', $reservacionId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $stmt->close();
        foreach ($filas as $fila) {
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'resuelta_por_cliente', resolved_by = NULL,
                     resolved_at = NOW(), access_invalidated_at = NOW()
                 WHERE id = ? AND estado = 'notificacion_preparada'"
            );
            $id = (int)$fila['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            BuzonNotificacionesService::cerrarEnTransaccion(
                $db,
                $id,
                null,
                'resuelta_por_cliente'
            );
            self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
        }
        return $filas !== [];
    }

    public static function faseManualDisponible(int $impactoId): bool
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT COUNT(*) AS total
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.impacto_id = ? AND ir.estado = 'pendiente_notificacion'
               AND r.contacto_tipo IN ('email', 'telefono')
               AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total === 0;
    }

    public static function horarioValidoEnSnapshot(string $fecha, string $hora, array $snapshot): bool
    {
        $excepcion = $snapshot['excepciones'][$fecha] ?? null;
        if (is_array($excepcion) && !empty($excepcion['activo'])) {
            return ($excepcion['tipo'] ?? '') === 'horario_especial'
                && self::entre($hora, $excepcion['hora_apertura'] ?? null, $excepcion['hora_cierre'] ?? null);
        }
        $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        if (!$fechaObjeto instanceof DateTimeImmutable) {
            return false;
        }
        $horario = $snapshot['semanal'][(int)$fechaObjeto->format('w')] ?? null;
        return is_array($horario) && !empty($horario['abierto'])
            && self::entre($hora, $horario['hora_apertura'] ?? null, $horario['hora_cierre'] ?? null);
    }

    /**
     * Clasifica de inmediato las filas nuevas. El único caso que obtiene
     * acceso automático es 1–12 con contacto; los demás reciben un aviso
     * visible ahora y quedan para acción administrativa.
     */
    private static function clasificarSeguimientosEnTransaccion(\mysqli $db, int $impactoId, ?int $adminId): void
    {
        $stmt = $db->prepare(
            "SELECT ir.id, ir.estado, ir.access_expires_at,
                    r.comensales, r.contacto_tipo, r.contacto
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.impacto_id = ?
             FOR UPDATE"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible clasificar las reservaciones afectadas.');
        }
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $stmt->close();

        foreach ($filas as $fila) {
            $id = (int)$fila['id'];
            $estado = (string)$fila['estado'];
            $tieneContacto = self::filaTieneContacto($fila);
            $comensales = (int)$fila['comensales'];
            $expira = $fila['access_expires_at'] !== null
                ? (string)$fila['access_expires_at']
                : null;

            if ($estado === self::ESTADO_ITEM_PENDIENTE
                && $tieneContacto
                && $comensales <= ReservacionConfig::MAX_COMENSALES_PUBLICO
            ) {
                $expira = self::prepararFilaEnTransaccion($db, $id);
            }

            $prioridad = $comensales > ReservacionConfig::MAX_COMENSALES_PUBLICO
                ? BuzonNotificacionesService::PRIORIDAD_ALTA
                : BuzonNotificacionesService::PRIORIDAD_NORMAL;
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $id,
                $prioridad,
                $expira
            );
        }
    }

    private static function prepararFilaSiEsElegibleEnTransaccion(\mysqli $db, int $impactoReservacionId, ?int $adminId): void
    {
        $stmt = $db->prepare(
            "SELECT ir.id, ir.estado, r.comensales, r.contacto_tipo, r.contacto
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible revisar la afectación.');
        }
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_PENDIENTE) {
            return;
        }
        if (self::filaTieneContacto($fila) && (int)$fila['comensales'] <= ReservacionConfig::MAX_COMENSALES_PUBLICO) {
            $expira = self::prepararFilaEnTransaccion($db, $impactoReservacionId);
            ReservacionBuzonService::crearSeguimientoHorarioEnTransaccion(
                $db,
                $impactoReservacionId,
                BuzonNotificacionesService::PRIORIDAD_NORMAL,
                $expira
            );
        }
    }

    private static function prepararFilaEnTransaccion(\mysqli $db, int $impactoReservacionId): string
    {
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $expira = ReservacionConfig::ahora()
            ->modify('+' . ReservacionConfig::scheduleChangeAccessTtlMinutes() . ' minutes')
            ->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "UPDATE horario_impacto_reservaciones
             SET estado = 'notificacion_preparada', notification_prepared_at = NOW(),
                 access_token_hash = ?, access_expires_at = ?, access_invalidated_at = NULL,
                 resolved_by = NULL, resolved_at = NULL
             WHERE id = ? AND estado = 'pendiente_notificacion'"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el acceso temporal.');
        }
        $stmt->bind_param('ssi', $hash, $expira, $impactoReservacionId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje !== '' ? $mensaje : 'La afectación ya no está pendiente.');
        }
        $stmt->close();
        return $expira;
    }

    /** @return array<string, mixed>|null */
    public static function obtenerPorItem(int $impactoReservacionId): ?array
    {
        if ($impactoReservacionId < 1) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT i.id AS impacto_id, ir.id AS impacto_reservacion_id,
                    ir.reservacion_id, ir.estado, ir.notification_prepared_at,
                    ir.access_expires_at, ir.access_invalidated_at,
                    r.nombre, r.fecha, r.hora, r.comensales,
                    r.contacto_tipo, r.contacto, i.estado AS impacto_estado
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila) {
            return null;
        }
        $tieneContacto = self::filaTieneContacto($fila);
        return [
            'impacto_id' => (int)$fila['impacto_id'],
            'impacto_reservacion_id' => (int)$fila['impacto_reservacion_id'],
            'reservacion_id' => (int)$fila['reservacion_id'],
            'estado' => (string)$fila['estado'],
            'impacto_estado' => (string)$fila['impacto_estado'],
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => substr((string)$fila['hora'], 0, 5),
            'comensales' => (int)$fila['comensales'],
            'tiene_contacto' => $tieneContacto,
            'access_expires_at' => $fila['access_expires_at'],
            'access_invalidated_at' => $fila['access_invalidated_at'],
            'test_link_disponible' => self::esEntornoPruebas()
                && (string)$fila['estado'] === self::ESTADO_ITEM_PREPARADO
                && $fila['access_invalidated_at'] === null,
        ];
    }

    /** Reconciliación idempotente después de una edición/cancelación administrativa. */
    public static function reconciliarReservacion(int $reservacionId, ?int $adminId = null): int
    {
        if ($reservacionId < 1) {
            return 0;
        }
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $stmt = $db->prepare('SELECT id, estado, fecha, hora FROM reservaciones WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new \RuntimeException('No fue posible bloquear la reservación.');
            }
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $reservacion = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$reservacion) {
                $db->commit();
                return 0;
            }
            $resuelta = in_array((string)$reservacion['estado'], ReservacionConfig::ESTADOS_FINALES, true)
                || HorarioOperacionService::estaAbierto((string)$reservacion['fecha'], (string)$reservacion['hora']);
            if (!$resuelta) {
                $db->commit();
                return 0;
            }

            $stmt = $db->prepare(
                "SELECT id, impacto_id
                 FROM horario_impacto_reservaciones
                 WHERE reservacion_id = ?
                   AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')
                 FOR UPDATE"
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible bloquear las afectaciones.');
            }
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $filas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $filas[] = $fila;
            }
            $stmt->close();
            foreach ($filas as $fila) {
                $id = (int)$fila['id'];
                $stmt = $db->prepare(
                    "UPDATE horario_impacto_reservaciones
                     SET estado = 'atendida_manual', resolved_by = NULLIF(?, 0),
                         resolved_at = NOW(), access_invalidated_at = NOW()
                     WHERE id = ? AND estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')"
                );
                if (!$stmt) {
                    throw new \RuntimeException('No fue posible reconciliar la afectación.');
                }
                $admin = $adminId ?? 0;
                $stmt->bind_param('ii', $admin, $id);
                if (!$stmt->execute()) {
                    $mensaje = $stmt->error;
                    $stmt->close();
                    throw new \RuntimeException($mensaje);
                }
                $stmt->close();
                BuzonNotificacionesService::cerrarEnTransaccion($db, $id, $adminId, 'fuente_resuelta');
                self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
            }
            $db->commit();
            return count($filas);
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('HorarioOperacionImpactoService::reconciliarReservacion - ' . $e->getMessage());
            return 0;
        }
    }

    private static function actualizarEstadoImpacto(\mysqli $db, int $impactoId): void
    {
        $stmt = $db->prepare(
            "UPDATE horario_impactos i
             LEFT JOIN (
                 SELECT impacto_id,
                        SUM(estado IN ('pendiente_notificacion', 'sin_contacto')) AS pendientes
                 FROM horario_impacto_reservaciones
                 GROUP BY impacto_id
             ) pendientes ON pendientes.impacto_id = i.id
             SET i.estado = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                 THEN 'resuelto' ELSE 'pendiente' END,
                 i.resolved_at = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                      THEN COALESCE(i.resolved_at, NOW()) ELSE NULL END
             WHERE i.id = ?"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string, mixed> */
    private static function bloquearFilaImpacto(\mysqli $db, int $impactoId, int $impactoReservacionId): ?array
    {
        $stmt = $db->prepare(
            "SELECT ir.id AS impacto_reservacion_id, ir.impacto_id, ir.reservacion_id,
                    ir.estado, r.contacto_tipo, r.contacto, r.comensales
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? AND ir.impacto_id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $impactoReservacionId, $impactoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function accessUrl(string $token): string
    {
        $base = ReservacionConfig::reservationPublicBaseUrl();
        if ($base === '') {
            throw new \RuntimeException('RESERVATION_PUBLIC_BASE_URL no está configurada.');
        }
        return $base . '/reservaciones/cambio-horario?access=' . rawurlencode($token);
    }

    private static function filaTieneContacto(array $fila): bool
    {
        return in_array((string)($fila['contacto_tipo'] ?? ''), ['email', 'telefono'], true)
            && trim((string)($fila['contacto'] ?? '')) !== '';
    }

    private static function estaDentro(string $fecha, string $hora, array $snapshot): bool
    {
        return self::horarioValidoEnSnapshot($fecha, $hora, $snapshot);
    }

    private static function entre(string $hora, ?string $apertura, ?string $cierre): bool
    {
        return $apertura !== null && $cierre !== null && $hora >= $apertura && $hora < $cierre;
    }

    private static function hora(string $hora): ?string
    {
        $hora = trim($hora);
        if ($hora === '') {
            return null;
        }
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $hora) === 1
            ? substr($hora, 0, 5) . ':00'
            : null;
    }

    private static function dedupKey(string $tipo, ?int $id, array $evaluacion): string
    {
        return hash('sha256', $tipo . '|' . ($id ?? 0) . '|'
            . json_encode($evaluacion['antes'] ?? []) . '|'
            . json_encode($evaluacion['despues'] ?? []));
    }

    private static function esEntornoPruebas(): bool
    {
        return in_array(ReservacionConfig::appEnvironment(), ['development', 'testing'], true);
    }

    /** @param callable(\mysqli): array|bool $callback */
    private static function conTransaccion(callable $callback): array|bool
    {
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $resultado = $callback($db);
            $fallo = is_array($resultado) ? (($resultado['ok'] ?? true) === false) : $resultado === false;
            if ($fallo) {
                $db->rollback();
                return $resultado;
            }
            $db->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('HorarioOperacionImpactoService - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => 'ERROR_SEGUIMIENTO_HORARIO'];
        }
    }
}
