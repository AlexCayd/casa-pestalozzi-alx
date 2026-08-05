<?php

/**
 * Casos de uso mutables del portal público de reservaciones.
 *
 * El orden global es contacto -> fechas ordenadas -> transacción -> filas de
 * reservación -> mesas ordenadas -> asignaciones. Mantenerlo reduce deadlocks
 * y hace efectivo el límite transaccional de cinco.
 */

namespace Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Model\ActiveRecord;
use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Model\VerificacionContacto;

final class ReservacionPublicaService
{
    public const RESERVACION_CONFIRMADA = 'RESERVACION_CONFIRMADA';
    public const RETENCION_CREADA = 'RETENCION_CREADA';
    public const RETENCION_EXPIRADA = 'RETENCION_EXPIRADA';
    public const SIN_DISPONIBILIDAD = 'SIN_DISPONIBILIDAD';
    public const LIMITE_RESERVACIONES_ALCANZADO = 'LIMITE_RESERVACIONES_ALCANZADO';
    public const REQUEST_TOKEN_CONFLICTO = 'REQUEST_TOKEN_CONFLICTO';
    public const CONTACTO_NO_VERIFICADO = 'CONTACTO_NO_VERIFICADO';
    public const CONTACTO_NO_COINCIDE = 'CONTACTO_NO_COINCIDE';
    public const SESION_EXPIRADA = 'SESION_EXPIRADA';
    public const RESERVACION_NO_ENCONTRADA = 'RESERVACION_NO_ENCONTRADA';
    public const RESERVACION_NO_PERTENECE_AL_CONTACTO = 'RESERVACION_NO_PERTENECE_AL_CONTACTO';
    public const MODIFICACION_NO_PERMITIDA = 'MODIFICACION_NO_PERMITIDA';
    public const CANCELACION_NO_PERMITIDA = 'CANCELACION_NO_PERMITIDA';
    public const RESERVACION_MODIFICADA = 'RESERVACION_MODIFICADA';
    public const RESERVACION_CANCELADA = 'RESERVACION_CANCELADA';
    public const RESERVACION_DUPLICADA = 'RESERVACION_DUPLICADA';
    public const REEMPLAZO_CREADO = 'REEMPLAZO_CREADO';
    public const REEMPLAZO_CONFIRMADO = 'REEMPLAZO_CONFIRMADO';
    public const RETENCIONES_EXPIRADAS = 'RETENCIONES_EXPIRADAS';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /** Expone la regla temporal a la presentación sin duplicarla. */
    public static function puedeGestionarse(array $fila): bool
    {
        return self::puedeModificarPublicamente($fila);
    }

    public static function puedeModificarPublicamente(array $fila): bool
    {
        if ((string)($fila['estado'] ?? '') !== 'confirmada') {
            return false;
        }

        $inicio = self::fechaHoraProgramada($fila);
        return $inicio instanceof DateTimeImmutable
            && ReservacionConfig::ahora() <= $inicio->modify('-' . ReservacionConfig::LIMITE_MODIFICACION_MINUTOS . ' minutes');
    }

    public static function puedeCancelarPublicamente(array $fila): bool
    {
        if ((string)($fila['estado'] ?? '') !== 'confirmada') {
            return false;
        }

        $inicio = self::fechaHoraProgramada($fila);
        return $inicio instanceof DateTimeImmutable
            && ReservacionConfig::ahora() <= $inicio->modify('+' . ReservacionConfig::TOLERANCIA_CANCELACION_PUBLICA_MINUTOS . ' minutes');
    }

    public static function contactoCoincideConSesion(array $entrada, array $sesion): bool
    {
        $tipoSesion = (string)($sesion['contacto_tipo'] ?? '');
        $contactoSesion = (string)($sesion['contacto'] ?? '');
        $tipoEntrada = trim((string)($entrada['tipo_contacto'] ?? $entrada['tipo'] ?? ''));

        try {
            $contactoEntrada = ContactoService::normalizar(
                $tipoEntrada,
                (string)($entrada['contacto'] ?? '')
            );
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return $tipoEntrada === $tipoSesion
            && $contactoSesion !== ''
            && hash_equals($contactoSesion, $contactoEntrada);
    }

    /**
     * Autoriza la exclusión orientativa usada por el editor público sin
     * aceptar IDs arbitrarios enviados por el navegador.
     */
    public static function reservacionPerteneceASesion(int $id, array $sesion): bool
    {
        if ($id < 1) {
            return false;
        }

        $fila = self::buscarPorId($id);
        if (!$fila) {
            return false;
        }

        return self::mismoContacto(
            $fila,
            (string)($sesion['contacto_tipo'] ?? ''),
            (string)($sesion['contacto'] ?? '')
        );
    }

    /** @return array<string, mixed> */
    public static function crearRetencion(array $entrada): array
    {
        $validacion = self::validarSolicitud($entrada, true);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }
        $datos = $validacion['datos'];

        // La comprobación preliminar mejora la respuesta, pero sólo la segunda,
        // dentro del lock por contacto, protege contra solicitudes simultáneas.
        if (self::contarActivas($datos['tipo'], $datos['contacto']) >= ReservacionConfig::MAX_ACTIVE_RESERVATIONS) {
            return self::limiteAlcanzado();
        }

        return self::conLocks($datos['tipo'], $datos['contacto'], [$datos['fecha']], function (\mysqli $db) use ($datos): array {
            $transaccion = false;
            try {
                if (!$db->begin_transaction()) {
                    throw new \RuntimeException('No fue posible iniciar la retención.');
                }
                $transaccion = true;

                $existente = self::buscarPorTokenParaActualizar($datos['request_token']);
                if ($existente) {
                    $resultado = self::coincidePayloadPublico($existente, $datos)
                        ? self::resolverIdempotencia($existente, true)
                        : self::tokenEnConflicto();
                    $db->commit();
                    $transaccion = false;
                    return $resultado;
                }

                if (self::buscarDuplicadaActiva(
                    $datos['tipo'],
                    $datos['contacto'],
                    $datos['fecha'],
                    $datos['hora']
                )) {
                    $db->rollback();
                    $transaccion = false;
                    return self::duplicada();
                }

                if (self::contarActivas($datos['tipo'], $datos['contacto']) >= ReservacionConfig::MAX_ACTIVE_RESERVATIONS) {
                    $db->rollback();
                    $transaccion = false;
                    return self::limiteAlcanzado();
                }

                $disponibilidad = DisponibilidadReservacionService::evaluarHorario(
                    $datos['fecha'],
                    $datos['hora'],
                    $datos['personas'],
                    0,
                    true,
                    true
                );
                if (!($disponibilidad['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::sinDisponibilidad();
                }

                $vence = ReservacionConfig::ahora()
                    ->modify('+' . ReservacionConfig::RESERVATION_HOLD_MINUTES . ' minutes')
                    ->format('Y-m-d H:i:s');
                $reservacionId = self::insertarReservacion(
                    $datos,
                    'pendiente_verificacion',
                    $vence,
                    ReservacionConfig::ahora()->format('Y-m-d H:i:s')
                );
                ReservacionMesa::reemplazarAsignacion($reservacionId, $disponibilidad['mesa_ids']);
                if (!ReservacionMesa::tieneMesasAsignadas($reservacionId)) {
                    throw new \RuntimeException('La retención quedó sin mesas.');
                }

                // OTP y retención se insertan en la misma transacción. Un
                // rollback invalida ambos y también cualquier asignación.
                $otp = ContactoAccesoService::emitirCodigoEnTransaccion(
                    $datos['tipo'],
                    $datos['contacto'],
                    $reservacionId
                );
                if (!($otp['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return $otp;
                }

                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar la retención.');
                }
                $transaccion = false;

                return array_merge([
                    'ok' => true,
                    'codigo' => self::RETENCION_CREADA,
                    'mensaje' => 'Conservaremos tus mesas durante 15 minutos mientras verificas el contacto.',
                    'request_token' => $datos['request_token'],
                    'hold_expires_at' => self::fechaAtom($vence),
                    'idempotente' => false,
                ], self::camposPreviewOtp($otp));
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::crearRetencion - ' . $e->getMessage());
                return self::errorInterno();
            }
        });
    }

    /** @return array<string, mixed> */
    public static function confirmarRetencion(array $entrada): array
    {
        $requestToken = trim((string)($entrada['request_token'] ?? ''));
        $codigo = preg_replace('/\D+/', '', (string)($entrada['codigo'] ?? '')) ?? '';
        try {
            $tipo = trim((string)($entrada['tipo'] ?? $entrada['tipo_contacto'] ?? ''));
            $contacto = ContactoService::normalizar($tipo, (string)($entrada['contacto'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return self::datosInvalidos($e->getMessage());
        }
        if (!self::tokenValido($requestToken) || preg_match('/^\d{6}$/', $codigo) !== 1) {
            return self::datosInvalidos('Escribe el código de seis dígitos y conserva el identificador de solicitud.');
        }

        return self::conLocks($tipo, $contacto, [], function (\mysqli $db) use ($tipo, $contacto, $requestToken, $codigo): array {
            $transaccion = false;
            try {
                if (!$db->begin_transaction()) {
                    throw new \RuntimeException('No fue posible iniciar la confirmación.');
                }
                $transaccion = true;
                $retencion = self::buscarPorTokenParaActualizar($requestToken);

                if (!$retencion || !self::mismoContacto($retencion, $tipo, $contacto)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::noEncontrada();
                }
                if ((string)$retencion['estado'] === 'confirmada') {
                    $db->commit();
                    $transaccion = false;
                    ReservationClientSession::crear($tipo, $contacto);
                    return self::resultadoReservacion($retencion, true);
                }
                if (
                    (string)$retencion['estado'] !== 'pendiente_verificacion'
                    || self::timestampVencido((string)$retencion['hold_expires_at'])
                ) {
                    if ((string)$retencion['estado'] === 'pendiente_verificacion') {
                        self::marcarExpirada((int)$retencion['id']);
                        VerificacionContacto::invalidarPorReservaciones([(int)$retencion['id']]);
                        $db->commit();
                    } else {
                        $db->rollback();
                    }
                    $transaccion = false;
                    return self::retencionExpirada();
                }
                if (self::contarActivas($tipo, $contacto, (int)$retencion['id']) >= ReservacionConfig::MAX_ACTIVE_RESERVATIONS) {
                    $db->rollback();
                    $transaccion = false;
                    return self::limiteAlcanzado();
                }
                if (self::buscarDuplicadaActiva(
                    $tipo,
                    $contacto,
                    (string)$retencion['fecha'],
                    (string)$retencion['hora'],
                    (int)$retencion['id']
                )) {
                    $db->rollback();
                    $transaccion = false;
                    return self::duplicada();
                }

                $otp = ContactoAccesoService::validarCodigoEnTransaccion(
                    $tipo,
                    $contacto,
                    $codigo,
                    (int)$retencion['id']
                );
                if (!($otp['ok'] ?? false)) {
                    if (($otp['registrar_intento'] ?? false) === true) {
                        $db->commit();
                    } else {
                        $db->rollback();
                    }
                    $transaccion = false;
                    unset($otp['registrar_intento']);
                    return $otp;
                }

                $estadoChangedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
                $stmt = $db->prepare(
                    "UPDATE reservaciones
                     SET estado = 'confirmada',
                         estado_changed_at = ?
                     WHERE id = ? AND estado = 'pendiente_verificacion'"
                );
                self::ejecutarStmt($stmt, 'si', [$estadoChangedAt, (int)$retencion['id']]);
                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar la reservación.');
                }
                $transaccion = false;
                ReservationClientSession::crear($tipo, $contacto);
                $retencion['estado'] = 'confirmada';
                $retencion['estado_changed_at'] = $estadoChangedAt;

                return self::resultadoReservacion($retencion, false);
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::confirmarRetencion - ' . $e->getMessage());
                return self::errorInterno();
            }
        });
    }

    /** @return array<string, mixed> */
    public static function reenviarOtpRetencion(array $entrada): array
    {
        $requestToken = trim((string)($entrada['request_token'] ?? ''));
        try {
            $tipo = trim((string)($entrada['tipo'] ?? ''));
            $contacto = ContactoService::normalizar($tipo, (string)($entrada['contacto'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return self::datosInvalidos($e->getMessage());
        }
        if (!self::tokenValido($requestToken)) {
            return self::datosInvalidos('El identificador de solicitud no es válido.');
        }

        return self::conLocks($tipo, $contacto, [], function (\mysqli $db) use ($tipo, $contacto, $requestToken): array {
            $transaccion = false;
            try {
                $db->begin_transaction();
                $transaccion = true;
                $retencion = self::buscarPorTokenParaActualizar($requestToken);
                if (
                    !$retencion
                    || !self::mismoContacto($retencion, $tipo, $contacto)
                    || (string)$retencion['estado'] !== 'pendiente_verificacion'
                ) {
                    $db->rollback();
                    return self::noEncontrada();
                }
                if (self::timestampVencido((string)$retencion['hold_expires_at'])) {
                    self::marcarExpirada((int)$retencion['id']);
                    VerificacionContacto::invalidarPorReservaciones([(int)$retencion['id']]);
                    $db->commit();
                    $transaccion = false;
                    return self::retencionExpirada();
                }

                $otp = ContactoAccesoService::emitirCodigoEnTransaccion(
                    $tipo,
                    $contacto,
                    (int)$retencion['id']
                );
                if (!($otp['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return $otp;
                }
                $db->commit();
                $transaccion = false;
                return $otp;
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::reenviarOtpRetencion - ' . $e->getMessage());
                return self::errorInterno();
            }
        });
    }

    /** Crea directamente usando exclusivamente la identidad de sesión. */
    public static function crearConfirmada(array $entrada, array $sesion): array
    {
        $tipo = (string)($sesion['contacto_tipo'] ?? '');
        $contacto = (string)($sesion['contacto'] ?? '');
        if ($tipo === '' || $contacto === '') {
            return [
                'ok' => false,
                'codigo' => self::SESION_EXPIRADA,
                'mensaje' => 'Verifica nuevamente tu contacto.',
            ];
        }
        if (!self::contactoCoincideConSesion($entrada, $sesion)) {
            return [
                'ok' => false,
                'codigo' => self::CONTACTO_NO_COINCIDE,
                'mensaje' => 'El contacto cambió. Verifícalo nuevamente para confirmar la reservación.',
            ];
        }
        $entrada['tipo_contacto'] = $tipo;
        $entrada['contacto'] = $contacto;
        $validacion = self::validarSolicitud($entrada, true, true);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }
        $datos = $validacion['datos'];

        return self::conLocks($tipo, $contacto, [$datos['fecha']], function (\mysqli $db) use ($datos): array {
            $transaccion = false;
            try {
                $db->begin_transaction();
                $transaccion = true;
                $existente = self::buscarPorTokenParaActualizar($datos['request_token']);
                if ($existente) {
                    $resultado = self::coincidePayloadPublico($existente, $datos)
                        ? self::resolverIdempotencia($existente, false)
                        : self::tokenEnConflicto();
                    $db->commit();
                    $transaccion = false;
                    return $resultado;
                }
                if (self::buscarDuplicadaActiva(
                    $datos['tipo'],
                    $datos['contacto'],
                    $datos['fecha'],
                    $datos['hora']
                )) {
                    $db->rollback();
                    $transaccion = false;
                    return self::duplicada();
                }
                if (self::contarActivas($datos['tipo'], $datos['contacto']) >= ReservacionConfig::MAX_ACTIVE_RESERVATIONS) {
                    $db->rollback();
                    $transaccion = false;
                    return self::limiteAlcanzado();
                }
                $disponibilidad = DisponibilidadReservacionService::evaluarHorario(
                    $datos['fecha'],
                    $datos['hora'],
                    $datos['personas'],
                    0,
                    true,
                    true
                );
                if (!($disponibilidad['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::sinDisponibilidad();
                }

                $reservacionId = self::insertarReservacion(
                    $datos,
                    'confirmada',
                    null,
                    ReservacionConfig::ahora()->format('Y-m-d H:i:s')
                );
                ReservacionMesa::reemplazarAsignacion($reservacionId, $disponibilidad['mesa_ids']);
                if (!ReservacionMesa::tieneMesasAsignadas($reservacionId)) {
                    throw new \RuntimeException('La reservación confirmada quedó sin mesas.');
                }
                $db->commit();
                $transaccion = false;
                $fila = self::buscarPorId($reservacionId);

                return self::resultadoReservacion($fila ?: ['id' => $reservacionId] + $datos, false);
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::crearConfirmada - ' . $e->getMessage());
                return self::errorInterno();
            }
        });
    }

    /**
     * Crea una nueva versión pendiente. La reservación original y sus mesas
     * permanecen intactas hasta que la sesión verificada confirme el cambio.
     */
    public static function crearReemplazo(array $entrada, array $sesion): array
    {
        $id = (int)($entrada['reservacion_id'] ?? $entrada['id'] ?? 0);
        $tipo = (string)($sesion['contacto_tipo'] ?? '');
        $contacto = (string)($sesion['contacto'] ?? '');
        $fecha = trim((string)($entrada['fecha'] ?? ''));
        $hora = HorarioReservacionService::normalizarHoraSql((string)($entrada['hora'] ?? ''));
        $personas = filter_var($entrada['personas'] ?? $entrada['comensales'] ?? null, FILTER_VALIDATE_INT);
        $nota = trim((string)($entrada['notas'] ?? $entrada['nota'] ?? ''));
        $token = trim((string)($entrada['request_token'] ?? ''));

        if ($id < 1 || $tipo === '' || $contacto === '' || !self::tokenValido($token)) {
            return self::datosInvalidos('Selecciona una reservación y conserva el identificador de operación.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $hora === '') {
            return self::datosInvalidos('Selecciona una fecha y un horario válidos.');
        }
        if ($personas === false || $personas < 1 || $personas > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return self::datosInvalidos('Las reservaciones en línea son de 1 a 12 personas.');
        }
        if (self::longitud($nota) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            return self::datosInvalidos('Las notas no pueden exceder 500 caracteres.');
        }

        $original = self::buscarPorId($id);
        if (!$original) {
            return self::noEncontrada();
        }
        if (!self::mismoContacto($original, $tipo, $contacto)) {
            return self::noPertenece();
        }
        $conservaHorarioOriginal = (string)$original['fecha'] === $fecha
            && HorarioReservacionService::normalizarHoraSql((string)$original['hora']) === $hora;
        $fechas = [(string)$original['fecha'], $fecha];

        return self::conLocks($tipo, $contacto, $fechas, function (\mysqli $db) use (
            $id,
            $tipo,
            $contacto,
            $fecha,
            $hora,
            $personas,
            $nota,
            $token,
            $conservaHorarioOriginal
        ): array {
            $transaccion = false;
            try {
                if (!$db->begin_transaction()) {
                    throw new \RuntimeException('No fue posible iniciar el reemplazo.');
                }
                $transaccion = true;

                $existente = self::buscarPorTokenParaActualizar($token);
                if ($existente) {
                    if ((int)($existente['reemplaza_reservacion_id'] ?? 0) !== $id) {
                        $db->rollback();
                        $transaccion = false;
                        return self::tokenEnConflicto();
                    }
                    if (!self::coincidePayloadReemplazo($existente, $fecha, $hora, $personas, $nota)) {
                        $db->rollback();
                        $transaccion = false;
                        return self::tokenEnConflicto();
                    }
                    if ((string)$existente['estado'] === 'pendiente_verificacion'
                        && !self::timestampVencido((string)$existente['hold_expires_at'])) {
                        $db->commit();
                        $transaccion = false;
                        return self::resultadoReemplazoPendiente($existente, true);
                    }
                    if ((string)$existente['estado'] === 'confirmada') {
                        $db->commit();
                        $transaccion = false;
                        return self::resultadoReemplazoConfirmado($existente, true);
                    }
                    $db->rollback();
                    $transaccion = false;
                    return self::reemplazoExpirado();
                }

                $fila = self::buscarPorIdParaActualizar($id);
                if (!$fila || !self::mismoContacto($fila, $tipo, $contacto)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::noPertenece();
                }
                if ((string)$fila['estado'] !== 'confirmada') {
                    $db->rollback();
                    $transaccion = false;
                    return self::modificacionNoPermitida();
                }
                if (!self::puedeModificarPublicamente($fila)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::modificacionNoPermitida('Puedes modificar esta reservación hasta 30 minutos antes.');
                }
                $conservaHorarioOriginal = (string)$fila['fecha'] === $fecha
                    && HorarioReservacionService::normalizarHoraSql((string)$fila['hora']) === $hora;

                $pendiente = self::buscarReemplazoPendienteParaActualizar($id);
                if ($pendiente) {
                    if (self::timestampVencido((string)$pendiente['hold_expires_at'])) {
                        self::marcarReemplazoExpirado((int)$pendiente['id']);
                    } else {
                        // Un intento distinto invalida explícitamente el hold
                        // anterior antes de crear el nuevo, evitando cadenas.
                        self::marcarReemplazoExpirado((int)$pendiente['id']);
                    }
                    VerificacionContacto::invalidarPorReservaciones([(int)$pendiente['id']]);
                }

                $horario = $conservaHorarioOriginal
                    ? HorarioReservacionService::validarHoraParaModificacion($fecha, $hora)
                    : ReservacionService::validarHorarioDisponible($fecha, $hora);
                if (!($horario['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::datosInvalidos('La fecha u hora seleccionada ya no está disponible.');
                }

                $disponibilidad = DisponibilidadReservacionService::evaluarHorario(
                    $fecha,
                    $hora,
                    (int)$personas,
                    $id,
                    true,
                    true,
                    $conservaHorarioOriginal
                );
                if (!($disponibilidad['ok'] ?? false)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::sinDisponibilidad('Ese nuevo horario ya no está disponible. Tu reservación original sigue confirmada.');
                }

                $vence = ReservacionConfig::ahora()
                    ->modify('+' . ReservacionConfig::VIGENCIA_HOLD_MINUTOS . ' minutes')
                    ->format('Y-m-d H:i:s');
                $reemplazoId = self::insertarReemplazo(
                    $fila,
                    $fecha,
                    $hora,
                    (int)$personas,
                    $nota,
                    $vence,
                    $token
                );
                ReservacionMesa::reemplazarAsignacion($reemplazoId, $disponibilidad['mesa_ids']);
                if (!ReservacionMesa::tieneMesasAsignadas($reemplazoId)) {
                    throw new \RuntimeException('El reemplazo quedó sin mesas.');
                }

                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar el reemplazo.');
                }
                $transaccion = false;

                $reemplazo = self::buscarPorId($reemplazoId) ?: [
                    'id' => $reemplazoId,
                    'nombre' => $fila['nombre'],
                    'contacto_tipo' => $tipo,
                    'contacto' => $contacto,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'comensales' => $personas,
                    'nota' => $nota,
                    'estado' => 'pendiente_verificacion',
                    'hold_expires_at' => $vence,
                ];
                return self::resultadoReemplazoPendiente($reemplazo, false, $fila);
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::crearReemplazo - ' . $e->getMessage());
                return self::errorInterno('No fue posible crear el cambio; tu reservación original sigue confirmada.');
            }
        });
    }

    /** Alias conservado para consumidores heredados; ya no actualiza la original. */
    public static function modificar(array $entrada, array $sesion): array
    {
        return self::crearReemplazo($entrada, $sesion);
    }

    /** Confirma el reemplazo y mueve ambas filas de estado atómicamente. */
    public static function confirmarReemplazo(array $entrada, array $sesion): array
    {
        $token = trim((string)($entrada['request_token'] ?? ''));
        $tipo = (string)($sesion['contacto_tipo'] ?? '');
        $contacto = (string)($sesion['contacto'] ?? '');
        if (!self::tokenValido($token)) {
            return self::datosInvalidos('La operación de cambio no es válida.');
        }
        if ($tipo === '' || $contacto === '') {
            return ['ok' => false, 'codigo' => self::SESION_EXPIRADA, 'mensaje' => 'Verifica nuevamente tu contacto.'];
        }

        $previsualizacion = self::buscarPorToken($token);
        $fechas = [];
        if ($previsualizacion) {
            $fechas[] = (string)($previsualizacion['fecha'] ?? '');
            $originalPrevio = self::buscarPorId((int)($previsualizacion['reemplaza_reservacion_id'] ?? 0));
            if ($originalPrevio) {
                $fechas[] = (string)($originalPrevio['fecha'] ?? '');
            }
        }

        return self::conLocks($tipo, $contacto, $fechas, function (\mysqli $db) use ($token, $tipo, $contacto): array {
            $transaccion = false;
            try {
                if (!$db->begin_transaction()) {
                    throw new \RuntimeException('No fue posible iniciar la confirmación del cambio.');
                }
                $transaccion = true;
                $reemplazo = self::buscarPorTokenParaActualizar($token);
                if (!$reemplazo || !self::mismoContacto($reemplazo, $tipo, $contacto)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::noEncontrada();
                }
                $originalId = (int)($reemplazo['reemplaza_reservacion_id'] ?? 0);
                $original = $originalId > 0 ? self::buscarPorIdParaActualizar($originalId) : null;
                if (!$original || !self::mismoContacto($original, $tipo, $contacto)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::noPertenece();
                }

                if ((string)$reemplazo['estado'] === 'confirmada'
                    && (string)$original['estado'] === 'reemplazada') {
                    $db->commit();
                    $transaccion = false;
                    return self::resultadoReemplazoConfirmado($reemplazo, true);
                }
                if ((string)$reemplazo['estado'] !== 'pendiente_verificacion') {
                    $db->rollback();
                    $transaccion = false;
                    return self::reemplazoExpirado();
                }
                if (self::timestampVencido((string)$reemplazo['hold_expires_at'])) {
                    self::marcarReemplazoExpirado((int)$reemplazo['id']);
                    VerificacionContacto::invalidarPorReservaciones([(int)$reemplazo['id']]);
                    $db->commit();
                    $transaccion = false;
                    return self::reemplazoExpirado();
                }
                if ((string)$original['estado'] !== 'confirmada') {
                    self::marcarReemplazoExpirado((int)$reemplazo['id']);
                    VerificacionContacto::invalidarPorReservaciones([(int)$reemplazo['id']]);
                    $db->commit();
                    $transaccion = false;
                    return self::modificacionNoPermitida('Los cambios ya no pueden aplicarse; tu reservación original no está vigente.');
                }
                if (!ReservacionMesa::tieneMesasAsignadas((int)$reemplazo['id'])) {
                    $db->rollback();
                    $transaccion = false;
                    return self::sinDisponibilidad('El cambio ya no tiene una asignación válida. Tu reservación original sigue confirmada.');
                }

                $reemplazoId = (int)$reemplazo['id'];
                $mesasProvisionales = ReservacionMesa::obtenerIdsPorReservacion($reemplazoId);
                sort($mesasProvisionales, SORT_NUMERIC);
                $mesas = Mesa::reservablesParaActualizar($mesasProvisionales);
                $conservaHorarioOriginal = (string)$original['fecha'] === (string)$reemplazo['fecha']
                    && HorarioReservacionService::normalizarHoraSql((string)$original['hora'])
                        === HorarioReservacionService::normalizarHoraSql((string)$reemplazo['hora']);
                $disponibilidad = DisponibilidadReservacionService::evaluarHorario(
                    (string)$reemplazo['fecha'],
                    (string)$reemplazo['hora'],
                    (int)$reemplazo['comensales'],
                    [$originalId, $reemplazoId],
                    true,
                    true,
                    $conservaHorarioOriginal
                );
                $ocupacion = (array)($disponibilidad['ocupacion'] ?? []);
                if (!($disponibilidad['ok'] ?? false)
                    || count($mesas) !== count($mesasProvisionales)
                    || AsignacionMesasService::hayConflictoHorario(
                        (array)($ocupacion['ocupacion_bloqueante'] ?? []),
                        $mesasProvisionales
                    )
                    || !AsignacionMesasService::agrupacionPublicaValida(
                        $mesas,
                        (int)$reemplazo['comensales']
                    )) {
                    $db->rollback();
                    $transaccion = false;
                    return self::sinDisponibilidad('El cambio ya no estÃƒÂ¡ disponible. Tu reservaciÃƒÂ³n original sigue confirmada.');
                }

                $estadoChangedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
                $stmt = $db->prepare(
                    "UPDATE reservaciones
                     SET estado = CASE
                         WHEN id = ? THEN 'confirmada'
                         ELSE 'reemplazada'
                     END,
                     estado_changed_at = ?
                     WHERE (id = ? AND estado = 'pendiente_verificacion')
                        OR (id = ? AND estado = 'confirmada')"
                );
                self::ejecutarStmt($stmt, 'isii', [
                    (int)$reemplazo['id'],
                    $estadoChangedAt,
                    (int)$reemplazo['id'],
                    $originalId,
                ]);
                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar el cambio.');
                }
                $transaccion = false;
                $reemplazo['estado'] = 'confirmada';
                $reemplazo['estado_changed_at'] = $estadoChangedAt;
                return self::resultadoReemplazoConfirmado($reemplazo, false);
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::confirmarReemplazo - ' . $e->getMessage());
                return self::errorInterno('No fue posible aplicar los cambios; tu reservación original sigue confirmada.');
            }
        });
    }

    /** Compatibilidad de ruta: una sesión verificada no necesita otro OTP. */
    public static function reenviarOtpModificacion(array $entrada, array $sesion): array
    {
        $token = trim((string)($entrada['request_token'] ?? ''));
        $tipo = (string)($sesion['contacto_tipo'] ?? '');
        $contacto = (string)($sesion['contacto'] ?? '');
        if (!self::tokenValido($token) || $tipo === '' || $contacto === '') {
            return self::datosInvalidos('La operación de cambio no es válida.');
        }

        return self::datosInvalidos('La sesión verificada ya autoriza este cambio. Revisa la comparación y confirma la modificación.');
    }

    /** Cancelación lógica; conserva reservación y relaciones históricas. */
    public static function cancelar(int $id, array $sesion): array
    {
        $tipo = (string)($sesion['contacto_tipo'] ?? '');
        $contacto = (string)($sesion['contacto'] ?? '');
        if ($id < 1 || $tipo === '' || $contacto === '') {
            return self::datosInvalidos('Selecciona una reservación válida.');
        }
        $actual = self::buscarPorId($id);
        if (!$actual) {
            return self::noEncontrada();
        }
        if (!self::mismoContacto($actual, $tipo, $contacto)) {
            return self::noPertenece();
        }

        return self::conLocks($tipo, $contacto, [(string)$actual['fecha']], function (\mysqli $db) use ($id, $tipo, $contacto): array {
            $transaccion = false;
            try {
                $db->begin_transaction();
                $transaccion = true;
                $fila = self::buscarPorIdParaActualizar($id);
                if (!$fila || !self::mismoContacto($fila, $tipo, $contacto)) {
                    $db->rollback();
                    $transaccion = false;
                    return self::noPertenece();
                }
                if ((string)$fila['estado'] === 'cancelada') {
                    $db->commit();
                    $transaccion = false;
                    return [
                        'ok' => true,
                        'codigo' => self::RESERVACION_CANCELADA,
                        'mensaje' => 'La reservación ya estaba cancelada.',
                        'idempotente' => true,
                    ];
                }
                if ((string)$fila['estado'] !== 'confirmada' || !self::puedeCancelarPublicamente($fila)) {
                    $db->rollback();
                    $transaccion = false;
                    return [
                        'ok' => false,
                        'codigo' => self::CANCELACION_NO_PERMITIDA,
                        'mensaje' => 'Ya no es posible cancelar en línea. Contacta al restaurante.',
                    ];
                }
                $ticket = $db->query(
                    "SELECT t.id FROM tickets t
                     WHERE t.reservacion_id = {$id}
                       AND " . TicketMesa::condicionSqlAbierto('t') . "
                     LIMIT 1 FOR UPDATE"
                );
                if ($ticket === false) {
                    throw new \RuntimeException($db->error);
                }
                $ticketAbierto = $ticket->num_rows > 0;
                $ticket->free();
                if ($ticketAbierto) {
                    $db->rollback();
                    $transaccion = false;
                    return [
                        'ok' => false,
                        'codigo' => self::CANCELACION_NO_PERMITIDA,
                        'mensaje' => 'El servicio ya comenzó y no puede cancelarse en línea.',
                    ];
                }

                $pendiente = self::buscarReemplazoPendienteParaActualizar($id);
                if ($pendiente) {
                    self::marcarReemplazoExpirado((int)$pendiente['id']);
                    VerificacionContacto::invalidarPorReservaciones([(int)$pendiente['id']]);
                }

                $stmt = $db->prepare(
                    "UPDATE reservaciones
                     SET estado = 'cancelada',
                         estado_changed_at = ?
                     WHERE id = ? AND estado = 'confirmada'"
                );
                self::ejecutarStmt($stmt, 'si', [
                    ReservacionConfig::ahora()->format('Y-m-d H:i:s'),
                    $id,
                ]);
                $db->commit();
                $transaccion = false;
                return [
                    'ok' => true,
                    'codigo' => self::RESERVACION_CANCELADA,
                    'mensaje' => 'La reservación fue cancelada.',
                    'idempotente' => false,
                ];
            } catch (\Throwable $e) {
                if ($transaccion) {
                    $db->rollback();
                }
                error_log('ReservacionPublicaService::cancelar - ' . $e->getMessage());
                return self::errorInterno();
            }
        });
    }

    /**
     * Materializa retenciones vencidas por lotes sin borrar sus mesas.
     * La disponibilidad ya las ignora por timestamp aun antes de este proceso.
     */
    public static function expirarRetenciones(int $limite = 100, bool $simulacion = false): array
    {
        $limite = max(1, min(1000, $limite));
        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;
            $estadoChangedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
            $estadoChangedAtSql = $db->real_escape_string($estadoChangedAt);
            $resultado = $db->query(
                "SELECT id
                 FROM reservaciones
                 WHERE estado = 'pendiente_verificacion'
                   AND hold_expires_at <= '{$estadoChangedAtSql}'
                 ORDER BY hold_expires_at ASC, id ASC
                 LIMIT {$limite}
                 FOR UPDATE SKIP LOCKED"
            );
            if ($resultado === false) {
                throw new \RuntimeException($db->error);
            }
            $ids = [];
            while ($fila = $resultado->fetch_assoc()) {
                $ids[] = (int)$fila['id'];
            }
            $resultado->free();

            if ($ids !== [] && !$simulacion) {
                $lista = implode(',', $ids);
                if ($db->query(
                    "UPDATE reservaciones
                     SET estado = 'expirada',
                         estado_changed_at = '{$estadoChangedAtSql}'
                     WHERE id IN ({$lista})
                       AND estado = 'pendiente_verificacion'"
                ) === false) {
                    throw new \RuntimeException($db->error);
                }
                VerificacionContacto::invalidarPorReservaciones($ids);
            }
            if ($simulacion) {
                $db->rollback();
            } else {
                $db->commit();
            }
            $transaccion = false;

            return [
                'ok' => true,
                'codigo' => self::RETENCIONES_EXPIRADAS,
                'procesadas' => count($ids),
                'simulacion' => $simulacion,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionPublicaService::expirarRetenciones - ' . $e->getMessage());
            return self::errorInterno();
        }
    }

    /** @return array<string, mixed> */
    private static function validarSolicitud(
        array $entrada,
        bool $requiereContacto,
        bool $contactoYaNormalizado = false
    ): array {
        $nombre = trim((string)($entrada['nombre'] ?? ''));
        $fecha = trim((string)($entrada['fecha'] ?? ''));
        $hora = HorarioReservacionService::normalizarHoraSql((string)($entrada['hora'] ?? ''));
        $personas = filter_var($entrada['personas'] ?? $entrada['comensales'] ?? null, FILTER_VALIDATE_INT);
        $notas = trim((string)($entrada['notas'] ?? $entrada['nota'] ?? ''));
        $requestToken = trim((string)($entrada['request_token'] ?? ''));
        $tipo = trim((string)($entrada['tipo_contacto'] ?? $entrada['tipo'] ?? ''));
        $contactoValor = trim((string)($entrada['contacto'] ?? ''));

        if ($nombre === '' || self::longitud($nombre) > ReservacionConfig::NOMBRE_MAX_CARACTERES) {
            return self::datosInvalidos('Escribe un nombre válido.');
        }
        if ($personas === false || $personas < 1 || $personas > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return self::datosInvalidos('Las reservaciones en línea son de 1 a 12 personas.');
        }
        if (self::longitud($notas) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            return self::datosInvalidos('Las notas no pueden exceder 500 caracteres.');
        }
        if (!self::tokenValido($requestToken)) {
            return self::datosInvalidos('El identificador de solicitud no es válido.');
        }
        $horario = ReservacionService::validarHorarioDisponible($fecha, $hora);
        if (!($horario['ok'] ?? false)) {
            $esPasado = ($horario['codigo'] ?? '') === HorarioReservacionService::HORARIO_PASADO;
            $field = in_array(($horario['codigo'] ?? ''), [
                HorarioReservacionService::FECHA_INVALIDA,
                HorarioReservacionService::FECHA_PASADA,
                HorarioReservacionService::DIA_INACTIVO,
            ], true) ? 'fecha' : 'hora';
            $mensaje = $esPasado
                ? 'Ese horario ya pasó. Elige un horario posterior.'
                : 'La fecha u hora seleccionada no está disponible.';
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'mensaje' => $mensaje,
                'errores' => [$field => [$mensaje]],
                'errors' => [$field => [$mensaje]],
                'field_codes' => [
                    $field => [(string)($horario['codigo'] ?? HorarioReservacionService::HORARIO_INVALIDO)],
                ],
                'siguiente_horario_valido' => $horario['siguiente_horario_valido'] ?? null,
            ];
        }

        try {
            $contacto = $contactoYaNormalizado
                ? ContactoService::normalizar($tipo, $contactoValor)
                : ContactoService::normalizar($tipo, $contactoValor);
        } catch (InvalidArgumentException $e) {
            if ($requiereContacto) {
                return self::datosInvalidos($e->getMessage());
            }
            $contacto = '';
        }

        $payload = [
            'nombre' => $nombre,
            'tipo' => $tipo,
            'contacto' => $contacto,
            'fecha' => (string)$horario['fecha'],
            'hora' => (string)$horario['hora'],
            'personas' => (int)$personas,
            'notas' => $notas,
        ];
        $payload['request_token'] = $requestToken;
        return ['ok' => true, 'datos' => $payload];
    }

    private static function insertarReservacion(
        array $datos,
        string $estado,
        ?string $holdExpiresAt,
        ?string $estadoChangedAt = null
    ): int {
        $db = ActiveRecord::getDB();
        $estadoChangedAt = $estadoChangedAt
            ?? ReservacionConfig::ahora()->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO reservaciones
                (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
                 origen, estado, hold_expires_at, request_token, estado_changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "landing", ?, ?, ?, ?)'
        );
        self::ejecutarStmt($stmt, 'sssssisssss', [
            $datos['nombre'],
            $datos['tipo'],
            $datos['contacto'],
            $datos['fecha'],
            $datos['hora'],
            $datos['personas'],
            $datos['notas'],
            $estado,
            $holdExpiresAt,
            $datos['request_token'],
            $estadoChangedAt,
        ]);

        return (int)$db->insert_id;
    }

    private static function contarActivas(string $tipo, string $contacto, int $excluirReservacionId = 0): int
    {
        return Reservacion::contarActivasPorContacto(
            $tipo,
            $contacto,
            ReservacionConfig::fechaActual(),
            ReservacionConfig::horaActual(),
            $excluirReservacionId
        );
    }

    /**
     * El lock canónico de contacto y fecha serializa esta consulta con la
     * inserción. Así dos pestañas con tokens distintos no crean el mismo turno.
     */
    private static function buscarDuplicadaActiva(
        string $tipo,
        string $contacto,
        string $fecha,
        string $hora,
        int $excluirReservacionId = 0
    ): ?array {
        $condicionActiva = ReservacionConfig::condicionSqlOcupacionActiva('r');
        $sql = "SELECT r.id, r.request_token
                FROM reservaciones r
                WHERE r.contacto_tipo = ?
                  AND r.contacto = ?
                  AND r.fecha = ?
                  AND r.hora = ?
                  AND {$condicionActiva}";
        if ($excluirReservacionId > 0) {
            $sql .= ' AND r.id <> ?';
        }
        $sql .= ' ORDER BY r.id LIMIT 1 FOR UPDATE';

        $stmt = ActiveRecord::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la validación de duplicado.');
        }
        if ($excluirReservacionId > 0) {
            $stmt->bind_param('ssssi', $tipo, $contacto, $fecha, $hora, $excluirReservacionId);
        } else {
            $stmt->bind_param('ssss', $tipo, $contacto, $fecha, $hora);
        }
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila;
    }

    /**
     * Adquiere contacto y fechas en orden estable, liberando siempre en finally.
     *
     * @param array<int, string> $fechas
     */
    private static function conLocks(string $tipo, string $contacto, array $fechas, callable $operacion): array
    {
        $db = ActiveRecord::getDB();
        $horarioLock = false;
        $contactoLock = false;
        $adquiridas = [];
        try {
            $horarioLock = HorarioConfigLock::adquirir($db);
            if (!$horarioLock) {
                return self::errorInterno('La configuración de horarios está siendo actualizada.');
            }
            $contactoLock = ContactoOperacionLock::adquirir($db, $tipo, $contacto);
            if (!$contactoLock) {
                return self::errorInterno('La reservación está siendo actualizada. Intenta de nuevo.');
            }
            $fechas = array_values(array_unique(array_filter(array_map('trim', $fechas))));
            sort($fechas, SORT_STRING);
            foreach ($fechas as $fecha) {
                if (!FechaOperacionLock::adquirir($db, $fecha)) {
                    return self::sinDisponibilidad('La disponibilidad cambió. Intenta nuevamente.');
                }
                $adquiridas[] = $fecha;
            }

            return $operacion($db);
        } finally {
            foreach (array_reverse($adquiridas) as $fecha) {
                FechaOperacionLock::liberar($db, $fecha);
            }
            if ($contactoLock) {
                ContactoOperacionLock::liberar($db, $tipo, $contacto);
            }
            if ($horarioLock) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    private static function resolverIdempotencia(array $fila, bool $retencion): array
    {
        if ($retencion) {
            if ((string)$fila['estado'] === 'pendiente_verificacion' && !self::timestampVencido((string)$fila['hold_expires_at'])) {
                return [
                    'ok' => true,
                    'codigo' => self::RETENCION_CREADA,
                    'mensaje' => 'La retención ya existe.',
                    'request_token' => (string)$fila['request_token'],
                    'hold_expires_at' => self::fechaAtom((string)$fila['hold_expires_at']),
                    'idempotente' => true,
                ];
            }
            if ((string)$fila['estado'] === 'confirmada') {
                return self::resultadoReservacion($fila, true);
            }
            return self::retencionExpirada();
        }

        return self::resultadoReservacion($fila, true);
    }

    private static function buscarPorToken(string $token): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare('SELECT * FROM reservaciones WHERE request_token = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la idempotencia.');
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function buscarPorTokenParaActualizar(string $token): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare('SELECT * FROM reservaciones WHERE request_token = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la idempotencia.');
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function buscarPorIdParaActualizar(int $id): ?array
    {
        $resultado = ActiveRecord::getDB()->query("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1 FOR UPDATE");
        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        return $fila;
    }

    private static function buscarPorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $resultado = ActiveRecord::getDB()->query("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1");
        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        return $fila;
    }

    private static function buscarReemplazoPendienteParaActualizar(int $originalId): ?array
    {
        if ($originalId < 1) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT *
             FROM reservaciones
             WHERE reemplaza_reservacion_id = ?
               AND estado = 'pendiente_verificacion'
             ORDER BY id DESC
             LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible consultar el cambio pendiente.');
        }
        $stmt->bind_param('i', $originalId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function insertarReemplazo(
        array $original,
        string $fecha,
        string $hora,
        int $personas,
        string $nota,
        string $holdExpiresAt,
        string $requestToken
    ): int {
        $stmt = ActiveRecord::getDB()->prepare(
            "INSERT INTO reservaciones
                (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
                 origen, estado, hold_expires_at, reemplaza_reservacion_id,
                 request_token, estado_changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'landing', 'pendiente_verificacion', ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el reemplazo.');
        }
        $nombre = (string)$original['nombre'];
        $tipo = (string)$original['contacto_tipo'];
        $contacto = (string)$original['contacto'];
        $estadoChangedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
        $originalId = (int)$original['id'];
        $stmt->bind_param(
            'sssssississ',
            $nombre,
            $tipo,
            $contacto,
            $fecha,
            $hora,
            $personas,
            $nota,
            $holdExpiresAt,
            $originalId,
            $requestToken,
            $estadoChangedAt
        );
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private static function coincidePayloadReemplazo(
        array $fila,
        string $fecha,
        string $hora,
        int $personas,
        string $nota
    ): bool {
        return (string)($fila['fecha'] ?? '') === $fecha
            && substr((string)($fila['hora'] ?? ''), 0, 5) === substr($hora, 0, 5)
            && (int)($fila['comensales'] ?? 0) === $personas
            && (string)($fila['nota'] ?? '') === $nota;
    }

    private static function marcarReemplazoExpirado(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "UPDATE reservaciones
             SET estado = 'expirada', estado_changed_at = ?
             WHERE id = ? AND estado = 'pendiente_verificacion'"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible expirar el cambio pendiente.');
        }
        $estadoChangedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
        $stmt->bind_param('si', $estadoChangedAt, $id);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
    }

    private static function fechaHoraProgramada(array $fila): ?DateTimeImmutable
    {
        $fecha = trim((string)($fila['fecha'] ?? ''));
        $hora = trim((string)($fila['hora'] ?? ''));
        if ($fecha === '' || $hora === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($fecha . ' ' . $hora, ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function mismoContacto(array $fila, string $tipo, string $contacto): bool
    {
        return hash_equals((string)($fila['contacto_tipo'] ?? ''), $tipo)
            && hash_equals((string)($fila['contacto'] ?? ''), $contacto);
    }

    private static function marcarExpirada(int $id): void
    {
        $estadoChangedAt = ActiveRecord::getDB()->real_escape_string(
            ReservacionConfig::ahora()->format('Y-m-d H:i:s')
        );
        if (ActiveRecord::getDB()->query(
            "UPDATE reservaciones
             SET estado = 'expirada',
                 estado_changed_at = '{$estadoChangedAt}'
             WHERE id = {$id} AND estado = 'pendiente_verificacion'"
        ) === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
    }

    private static function coincidePayloadPublico(array $fila, array $datos): bool
    {
        return (string)($fila['origen'] ?? 'landing') === 'landing'
            && (string)($fila['nombre'] ?? '') === (string)($datos['nombre'] ?? '')
            && (string)($fila['contacto_tipo'] ?? '') === (string)($datos['tipo'] ?? '')
            && (string)($fila['contacto'] ?? '') === (string)($datos['contacto'] ?? '')
            && (string)($fila['fecha'] ?? '') === (string)($datos['fecha'] ?? '')
            && substr((string)($fila['hora'] ?? ''), 0, 5) === substr((string)($datos['hora'] ?? ''), 0, 5)
            && (int)($fila['comensales'] ?? 0) === (int)($datos['personas'] ?? 0)
            && (string)($fila['nota'] ?? '') === (string)($datos['notas'] ?? '');
    }

    private static function resultadoReservacion(array $fila, bool $idempotente): array
    {
        return [
            'ok' => true,
            'codigo' => self::RESERVACION_CONFIRMADA,
            'mensaje' => $idempotente
                ? 'La reservación ya estaba confirmada.'
                : 'La reservación quedó confirmada.',
            'idempotente' => $idempotente,
            'reservation' => self::publicar($fila),
        ];
    }

    private static function resultadoReemplazoPendiente(
        array $fila,
        bool $idempotente,
        ?array $original = null
    ): array
    {
        if (!$original && (int)($fila['reemplaza_reservacion_id'] ?? 0) > 0) {
            $original = self::buscarPorId((int)$fila['reemplaza_reservacion_id']);
        }

        return [
            'ok' => true,
            'codigo' => self::REEMPLAZO_CREADO,
            'mensaje' => $idempotente
                ? 'El cambio sigue pendiente de confirmación.'
                : 'Revisa el cambio y confírmalo para aplicarlo. Tu reservación original sigue confirmada.',
            'idempotente' => $idempotente,
            'request_token' => (string)($fila['request_token'] ?? ''),
            'hold_expires_at' => self::fechaAtom((string)($fila['hold_expires_at'] ?? '')),
            'hold_minutes' => ReservacionConfig::VIGENCIA_HOLD_MINUTOS,
            'original' => $original ? self::publicar($original) : null,
            'propuesta' => self::publicar($fila),
            'replacement' => self::publicar($fila),
            'original_still_confirmed' => true,
        ];
    }

    private static function resultadoReemplazoConfirmado(array $fila, bool $idempotente): array
    {
        return [
            'ok' => true,
            'codigo' => self::REEMPLAZO_CONFIRMADO,
            'mensaje' => $idempotente
                ? 'El cambio ya estaba aplicado.'
                : 'Tu reservación fue actualizada.',
            'idempotente' => $idempotente,
            'reservation' => self::publicar($fila),
        ];
    }

    private static function modificacionNoPermitida(
        string $mensaje = 'La reservación ya no puede modificarse.'
    ): array {
        return [
            'ok' => false,
            'codigo' => self::MODIFICACION_NO_PERMITIDA,
            'mensaje' => $mensaje,
        ];
    }

    private static function reemplazoExpirado(): array
    {
        return [
            'ok' => false,
            'codigo' => self::RETENCION_EXPIRADA,
            'mensaje' => 'El tiempo para confirmar el cambio terminó. Tu reservación original continúa vigente.',
        ];
    }

    private static function publicar(array $fila): array
    {
        $estado = (string)($fila['estado'] ?? 'confirmada');
        return [
            'id' => (int)($fila['id'] ?? 0),
            'nombre' => (string)($fila['nombre'] ?? ''),
            'fecha' => (string)($fila['fecha'] ?? ''),
            'hora' => substr((string)($fila['hora'] ?? ''), 0, 5),
            'comensales' => (int)($fila['comensales'] ?? $fila['personas'] ?? 0),
            'nota' => (string)($fila['nota'] ?? $fila['notas'] ?? ''),
            'estado_label' => ReservacionConfig::ESTADO_LABELS[$estado] ?? 'Confirmada',
            'can_modify' => self::puedeModificarPublicamente($fila),
            'can_cancel' => self::puedeCancelarPublicamente($fila),
        ];
    }

    private static function camposPreviewOtp(array $otp): array
    {
        $campos = ['otp_expires_at' => $otp['expires_at'] ?? null];
        if (array_key_exists('preview_code', $otp)) {
            $campos['preview_code'] = $otp['preview_code'];
        }
        return $campos;
    }

    private static function fechaAtom(string $fecha): string
    {
        try {
            return (new DateTimeImmutable($fecha, ReservacionConfig::timezone()))->format(DATE_ATOM);
        } catch (\Throwable $e) {
            return $fecha;
        }
    }

    private static function timestampVencido(string $fecha): bool
    {
        if ($fecha === '') {
            return true;
        }
        try {
            return new DateTimeImmutable($fecha, ReservacionConfig::timezone())
                <= ReservacionConfig::ahora();
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function ejecutarStmt(?\mysqli_stmt $stmt, string $tipos, array $valores): void
    {
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la operación.');
        }
        $stmt->bind_param($tipos, ...$valores);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
    }

    private static function tokenValido(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $token) === 1;
    }

    private static function longitud(string $valor): int
    {
        return function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
    }

    private static function datosInvalidos(string $mensaje): array
    {
        return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mensaje' => $mensaje];
    }

    private static function tokenEnConflicto(): array
    {
        return [
            'ok' => false,
            'codigo' => self::REQUEST_TOKEN_CONFLICTO,
            'mensaje' => 'La solicitud ya está asociada con otros datos. Inicia una nueva reservación.',
        ];
    }

    private static function limiteAlcanzado(): array
    {
        return [
            'ok' => false,
            'codigo' => self::LIMITE_RESERVACIONES_ALCANZADO,
            'mensaje' => 'Alcanzaste cinco reservaciones activas.',
        ];
    }

    private static function duplicada(): array
    {
        return [
            'ok' => false,
            'codigo' => self::RESERVACION_DUPLICADA,
            'mensaje' => 'Ya existe una reservación activa para este contacto en el horario seleccionado.',
        ];
    }

    private static function sinDisponibilidad(string $mensaje = 'El horario ya no está disponible.'): array
    {
        return ['ok' => false, 'codigo' => self::SIN_DISPONIBILIDAD, 'mensaje' => $mensaje];
    }

    private static function retencionExpirada(): array
    {
        return ['ok' => false, 'codigo' => self::RETENCION_EXPIRADA, 'mensaje' => 'La retención venció.'];
    }

    private static function noEncontrada(): array
    {
        return [
            'ok' => false,
            'codigo' => self::RESERVACION_NO_ENCONTRADA,
            'mensaje' => 'No fue posible localizar la reservación.',
        ];
    }

    private static function noPertenece(): array
    {
        return [
            'ok' => false,
            'codigo' => self::RESERVACION_NO_PERTENECE_AL_CONTACTO,
            'mensaje' => 'La reservación no pertenece al contacto verificado.',
        ];
    }

    private static function errorInterno(string $mensaje = 'No fue posible completar la operación.'): array
    {
        return ['ok' => false, 'codigo' => self::ERROR_INTERNO, 'mensaje' => $mensaje];
    }
}
