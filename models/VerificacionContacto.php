<?php

/**
 * Persistencia de desafíos OTP de un solo uso.
 *
 * Esta entidad nunca expone ni almacena el código original: únicamente recibe
 * el hash producido con password_hash().
 */

namespace Model;

class VerificacionContacto extends ActiveRecord
{
    protected static $tabla = 'verificaciones_contacto';

    /**
     * Devuelve la verificación más reciente y bloquea la fila durante el
     * intento, evitando que dos peticiones consuman simultáneamente el OTP.
     *
     * @return array<string, mixed>|null
     */
    public static function buscarRecienteParaActualizar(string $tipo, string $contacto): ?array
    {
        $stmt = self::getDB()->prepare(
            'SELECT id, contacto_tipo, contacto, codigo_hash, expires_at,
                    attempts, used_at, invalidated_at, created_at
             FROM verificaciones_contacto
             WHERE contacto_tipo = ? AND contacto = ?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de verificación.');
        }

        $stmt->bind_param('ss', $tipo, $contacto);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }

        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila;
    }

    /**
     * Bloquea el OTP vinculado a una retención concreta.
     */
    public static function buscarParaRetencionActualizar(
        string $tipo,
        string $contacto,
        int $reservacionId
    ): ?array {
        $stmt = self::getDB()->prepare(
            'SELECT id, reservacion_id, contacto_tipo, contacto, codigo_hash,
                    expires_at, attempts, used_at, invalidated_at, created_at
             FROM verificaciones_contacto
             WHERE contacto_tipo = ?
               AND contacto = ?
               AND reservacion_id = ?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la verificación de la retención.');
        }
        $stmt->bind_param('ssi', $tipo, $contacto, $reservacionId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila;
    }

    /**
     * Invalida desafíos anteriores aún utilizables antes de emitir uno nuevo.
     */
    public static function invalidarActivas(string $tipo, string $contacto): void
    {
        $stmt = self::getDB()->prepare(
            'UPDATE verificaciones_contacto
             SET invalidated_at = NOW()
             WHERE contacto_tipo = ?
               AND contacto = ?
               AND used_at IS NULL
               AND invalidated_at IS NULL'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la invalidación de códigos anteriores.');
        }

        $stmt->bind_param('ss', $tipo, $contacto);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
    }

    /**
     * Persiste exclusivamente el hash del código.
     */
    public static function crearHash(
        string $tipo,
        string $contacto,
        string $codigoHash,
        string $expiresAt,
        ?int $reservacionId = null
    ): int {
        $stmt = self::getDB()->prepare(
            'INSERT INTO verificaciones_contacto
                (reservacion_id, contacto_tipo, contacto, codigo_hash, expires_at, attempts)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el registro del código.');
        }

        $stmt->bind_param(
            'issss',
            $reservacionId,
            $tipo,
            $contacto,
            $codigoHash,
            $expiresAt
        );
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }

        $id = (int)self::getDB()->insert_id;
        $stmt->close();

        return $id;
    }

    /** Invalida OTP utilizables ligados a retenciones materializadas como vencidas. */
    public static function invalidarPorReservaciones(array $reservacionIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservacionIds))));
        if ($ids === []) {
            return;
        }

        $sql = 'UPDATE verificaciones_contacto
                SET invalidated_at = NOW()
                WHERE reservacion_id IN (' . implode(',', $ids) . ')
                  AND used_at IS NULL
                  AND invalidated_at IS NULL';
        if (self::getDB()->query($sql) === false) {
            throw new \RuntimeException(self::getDB()->error);
        }
    }

    /**
     * Registra un fallo; al alcanzar el máximo invalida el desafío.
     */
    public static function registrarIntentoFallido(int $id, bool $invalidar): void
    {
        $sql = $invalidar
            ? 'UPDATE verificaciones_contacto
               SET attempts = attempts + 1, invalidated_at = NOW()
               WHERE id = ?'
            : 'UPDATE verificaciones_contacto
               SET attempts = attempts + 1
               WHERE id = ?';
        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el registro del intento.');
        }

        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
    }

    /**
     * Marca el OTP como consumido dentro de la misma transacción de validación.
     */
    public static function marcarUsada(int $id): void
    {
        $stmt = self::getDB()->prepare(
            'UPDATE verificaciones_contacto
             SET used_at = NOW()
             WHERE id = ? AND used_at IS NULL AND invalidated_at IS NULL'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el consumo del código.');
        }

        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $mensaje = $stmt->error ?: 'El código dejó de estar disponible.';
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();
    }
}
