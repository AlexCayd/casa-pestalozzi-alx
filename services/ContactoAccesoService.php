<?php

/**
 * Orquesta solicitud, validación y consumo transaccional de códigos OTP.
 */

namespace Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Model\ActiveRecord;
use Model\VerificacionContacto;

class ContactoAccesoService
{
    public const OTP_GENERADO = 'OTP_GENERADO';
    public const OTP_SOLICITADO = 'OTP_SOLICITADO';
    public const CONTACTO_VERIFICADO = 'CONTACTO_VERIFICADO';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const REENVIO_NO_DISPONIBLE = 'REENVIO_NO_DISPONIBLE';
    public const CODIGO_INVALIDO = 'CODIGO_INVALIDO';
    public const CODIGO_EXPIRADO = 'CODIGO_EXPIRADO';
    public const CODIGO_NO_DISPONIBLE = 'CODIGO_NO_DISPONIBLE';
    public const DEMASIADOS_INTENTOS = 'DEMASIADOS_INTENTOS';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /** @return array<string, mixed> */
    public static function solicitarCodigo(
        string $tipo,
        string $contacto,
        ?ContactNotificationProvider $provider = null
    ): array {
        try {
            $tipo = trim($tipo);
            $normalizado = ContactoService::normalizar($tipo, $contacto);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mensaje' => $e->getMessage()];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transacción OTP.');
            }
            $transaccion = true;
            $respuesta = self::emitirCodigoEnTransaccion($tipo, $normalizado, null, $provider);
            if (!($respuesta['ok'] ?? false)) {
                $db->rollback();
                $transaccion = false;
                return $respuesta;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar el código.');
            }
            $transaccion = false;

            return $respuesta;
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ContactoAccesoService::solicitarCodigo - ' . $e->getMessage());
            return [
                'ok' => false,
                'codigo' => self::ERROR_INTERNO,
                'mensaje' => 'No fue posible solicitar el código.',
            ];
        }
    }

    /** @return array<string, mixed> */
    public static function verificarCodigo(string $tipo, string $contacto, string $codigo): array
    {
        try {
            $tipo = trim($tipo);
            $normalizado = ContactoService::normalizar($tipo, $contacto);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mensaje' => $e->getMessage()];
        }

        $codigo = trim($codigo);
        if (preg_match('/^\d{6}$/', $codigo) !== 1) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'mensaje' => 'Escribe el código de seis dígitos.',
            ];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la validación OTP.');
            }
            $transaccion = true;
            $respuesta = self::validarCodigoEnTransaccion($tipo, $normalizado, $codigo);
            if (!($respuesta['ok'] ?? false)) {
                if (($respuesta['registrar_intento'] ?? false) === true) {
                    if (!$db->commit()) {
                        throw new \RuntimeException('No fue posible registrar el intento.');
                    }
                } else {
                    $db->rollback();
                }
                $transaccion = false;
                unset($respuesta['registrar_intento']);
                return $respuesta;
            }

            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible consumir el código.');
            }
            $transaccion = false;
            ReservationClientSession::crear($tipo, $normalizado);

            return [
                'ok' => true,
                'codigo' => self::CONTACTO_VERIFICADO,
                'mensaje' => 'Contacto verificado.',
                'contacto' => ContactoService::enmascarar($tipo, $normalizado),
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ContactoAccesoService::verificarCodigo - ' . $e->getMessage());
            return [
                'ok' => false,
                'codigo' => self::ERROR_INTERNO,
                'mensaje' => 'No fue posible verificar el código.',
            ];
        }
    }

    /**
     * Emite un OTP dentro de una transacción ya iniciada.
     *
     * @return array<string, mixed>
     */
    public static function emitirCodigoEnTransaccion(
        string $tipo,
        string $contactoNormalizado,
        ?int $reservacionId = null,
        ?ContactNotificationProvider $provider = null
    ): array {
        $reciente = VerificacionContacto::buscarRecienteParaActualizar($tipo, $contactoNormalizado);
        if ($reciente) {
            $creada = new DateTimeImmutable((string)$reciente['created_at'], ReservacionConfig::timezone());
            if (
                (ReservacionConfig::ahora()->getTimestamp() - $creada->getTimestamp())
                < ReservacionConfig::OTP_RESEND_SECONDS
            ) {
                return [
                    'ok' => false,
                    'codigo' => self::REENVIO_NO_DISPONIBLE,
                    'mensaje' => 'Espera un momento antes de solicitar otro código.',
                ];
            }
        }

        VerificacionContacto::invalidarActivas($tipo, $contactoNormalizado);
        $codigo = (string)random_int(100000, 999999);
        $hash = password_hash($codigo, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new \RuntimeException('No fue posible proteger el código.');
        }

        $expiresAt = ReservacionConfig::ahora()
            ->modify('+' . ReservacionConfig::OTP_EXPIRATION_MINUTES . ' minutes');
        VerificacionContacto::crearHash(
            $tipo,
            $contactoNormalizado,
            $hash,
            $expiresAt->format('Y-m-d H:i:s'),
            $reservacionId
        );

        $provider ??= new DevelopmentContactNotificationProvider();
        $notificacion = $provider->sendOtp($tipo, $contactoNormalizado, $codigo);
        if (!($notificacion['ok'] ?? false)) {
            throw new \RuntimeException('El proveedor de notificaciones rechazó la solicitud.');
        }

        $preview = ReservacionConfig::otpPreviewEnabled();
        $respuesta = [
            'ok' => true,
            'codigo' => $preview ? self::OTP_GENERADO : self::OTP_SOLICITADO,
            'mensaje' => $preview
                ? 'Código generado para pruebas.'
                : 'Si el contacto es válido, recibirás un código.',
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ];
        if ($preview) {
            $respuesta['preview_code'] = $codigo;
        }

        return $respuesta;
    }

    /**
     * Única implementación de validación y consumo del OTP.
     *
     * El llamador controla commit/rollback porque confirmar una retención debe
     * consumir el OTP y cambiar la reservación de forma atómica.
     *
     * @return array<string, mixed>
     */
    public static function validarCodigoEnTransaccion(
        string $tipo,
        string $contactoNormalizado,
        string $codigo,
        ?int $reservacionId = null
    ): array {
        $fila = $reservacionId
            ? VerificacionContacto::buscarParaRetencionActualizar($tipo, $contactoNormalizado, $reservacionId)
            : VerificacionContacto::buscarRecienteParaActualizar($tipo, $contactoNormalizado);

        if (!$fila || $fila['used_at'] !== null || $fila['invalidated_at'] !== null) {
            return [
                'ok' => false,
                'codigo' => self::CODIGO_NO_DISPONIBLE,
                'mensaje' => 'El código no está disponible. Solicita uno nuevo.',
            ];
        }

        $attempts = (int)$fila['attempts'];
        $maxAttempts = ReservacionConfig::OTP_MAX_ATTEMPTS;
        if ($attempts >= $maxAttempts) {
            return [
                'ok' => false,
                'codigo' => self::DEMASIADOS_INTENTOS,
                'mensaje' => 'Solicita un código nuevo para continuar.',
            ];
        }

        $expira = new DateTimeImmutable((string)$fila['expires_at'], ReservacionConfig::timezone());
        if ($expira <= ReservacionConfig::ahora()) {
            return [
                'ok' => false,
                'codigo' => self::CODIGO_EXPIRADO,
                'mensaje' => 'El código venció. Solicita uno nuevo.',
            ];
        }

        if (!password_verify($codigo, (string)$fila['codigo_hash'])) {
            $siguienteIntento = $attempts + 1;
            VerificacionContacto::registrarIntentoFallido(
                (int)$fila['id'],
                $siguienteIntento >= $maxAttempts
            );
            return [
                'ok' => false,
                'codigo' => $siguienteIntento >= $maxAttempts
                    ? self::DEMASIADOS_INTENTOS
                    : self::CODIGO_INVALIDO,
                'mensaje' => $siguienteIntento >= $maxAttempts
                    ? 'Solicita un código nuevo para continuar.'
                    : 'El código no es válido.',
                'registrar_intento' => true,
            ];
        }

        VerificacionContacto::marcarUsada((int)$fila['id']);
        return ['ok' => true, 'codigo' => self::CONTACTO_VERIFICADO];
    }
}
