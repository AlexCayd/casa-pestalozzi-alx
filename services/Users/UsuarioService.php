<?php

namespace Services\Users;

use Model\ActiveRecord;
use Model\Usuario;

/**
 * Coordina las escrituras de usuarios y mantiene las reglas de acceso dentro
 * de transacciones. Todos los usuarios son administradores, así que la regla
 * que hay que proteger es que siempre quede al menos uno activo.
 */
class UsuarioService
{
    public const USUARIO_CREADO = 'USUARIO_CREADO';
    public const USUARIO_ACTUALIZADO = 'USUARIO_ACTUALIZADO';
    public const PASSWORD_ACTUALIZADO = 'PASSWORD_ACTUALIZADO';
    public const CREDENCIAL_ACTUAL_INCORRECTA = 'CREDENCIAL_ACTUAL_INCORRECTA';
    public const USUARIO_ACTIVADO = 'USUARIO_ACTIVADO';
    public const USUARIO_DESACTIVADO = 'USUARIO_DESACTIVADO';
    public const USUARIO_SIN_CAMBIOS = 'USUARIO_SIN_CAMBIOS';
    public const USUARIO_ELIMINADO = 'USUARIO_ELIMINADO';
    public const ADMIN_ACTIVO_REQUERIDO = 'ADMIN_ACTIVO_REQUERIDO';
    public const USUARIO_NO_EXISTE = 'USUARIO_NO_EXISTE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    public static function crear(Usuario $usuario): array
    {
        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $alertas = $usuario->validarCrear();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $usuario->hashPassword();
            $stmt = $db->prepare(
                'INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $activo = (int) $usuario->activo;
            $stmt->bind_param('sssi', $usuario->username, $usuario->nombre, $usuario->password_hash, $activo);
            self::ejecutarStatement($stmt);
            $id = (int) $db->insert_id;
            $stmt->close();

            $guardado = self::seleccionarUsuario($id);
            if (!$guardado || !password_verify((string) $usuario->password, (string) $guardado['password_hash'])) {
                throw new \RuntimeException('No fue posible verificar el usuario creado.');
            }

            $db->commit();
            $usuario->id = $id;

            return [
                'ok' => true,
                'codigo' => self::USUARIO_CREADO,
                'usuario' => $usuario,
            ];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            if ((int) $e->getCode() === 1062) {
                return self::resultadoInvalido($usuario, ['error' => ['El nombre de usuario ya está registrado.']]);
            }
            error_log('UsuarioService::crear - ' . $e->getMessage());
        }

        return [
            'ok' => false,
            'codigo' => self::ERROR_GUARDADO,
            'usuario' => $usuario,
        ];
    }

    public static function editar(int $usuarioId, array $datos): array
    {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        $usuario = null;

        try {
            $db->begin_transaction();
            $adminsActivos = self::bloquearAdminsActivos();
            $fila = self::seleccionarUsuario($usuarioId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }

            $usuario = self::usuarioDesdeFila($fila);
            $usuario->sincronizar($datos);
            $usuario->activo = (int) ($datos['activo'] ?? $usuario->activo);

            $desactivaUltimo = (int) $fila['activo'] === 1
                && (int) $usuario->activo !== 1
                && count($adminsActivos) <= 1;
            if ($desactivaUltimo) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO, 'usuario' => $usuario];
            }

            $alertas = $usuario->validarEditar();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $columnas = ['username = ?', 'nombre = ?', 'activo = ?'];
            $tipos = 'ssi';
            $valores = [$usuario->username, $usuario->nombre, (int) $usuario->activo];

            $escribePassword = trim((string) $usuario->password) !== '';
            if ($escribePassword) {
                $usuario->hashPassword();
                $columnas[] = 'password_hash = ?';
                $tipos .= 's';
                $valores[] = $usuario->password_hash;
            }

            $tipos .= 'i';
            $valores[] = $usuarioId;
            $stmt = $db->prepare('UPDATE usuarios SET ' . implode(', ', $columnas) . ' WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param($tipos, ...$valores);
            self::ejecutarStatement($stmt);
            $filasAfectadas = $stmt->affected_rows;
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (
                !$guardado
                || (string) $guardado['username'] !== (string) $usuario->username
                || (string) $guardado['nombre'] !== (string) $usuario->nombre
                || (int) $guardado['activo'] !== (int) $usuario->activo
            ) {
                throw new \RuntimeException('El estado persistido no coincide con la edición solicitada.');
            }

            $db->commit();
            return [
                'ok' => true,
                'codigo' => $filasAfectadas === 0 ? self::USUARIO_SIN_CAMBIOS : self::USUARIO_ACTUALIZADO,
                'usuario' => $usuario,
            ];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            if ((int) $e->getCode() === 1062) {
                return self::resultadoInvalido($usuario ?? new Usuario(), ['error' => ['El nombre de usuario ya está registrado.']]);
            }
            error_log('UsuarioService::editar - ' . $e->getMessage());
        }

        return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'usuario' => $usuario];
    }

    /** El actor confirma con su propia contraseña antes de cambiar una. */
    public static function cambiarPassword(
        int $usuarioId,
        int $actorId,
        string $secretoActor,
        string $nuevo,
        string $confirmacion
    ): array {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $fila = self::seleccionarUsuario($usuarioId, true);
            $actor = self::seleccionarUsuario($actorId);

            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }
            if (
                !$actor
                || (int) $actor['activo'] !== 1
                || !password_verify($secretoActor, (string) $actor['password_hash'])
            ) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::CREDENCIAL_ACTUAL_INCORRECTA];
            }

            $usuario = self::usuarioDesdeFila($fila);
            $usuario->password = $nuevo;
            $usuario->password_confirm = $confirmacion;
            $alertas = $usuario->validarPasswordNueva();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $usuario->hashPassword();
            $stmt = $db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('si', $usuario->password_hash, $usuarioId);
            self::ejecutarStatement($stmt);
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!$guardado || !password_verify($nuevo, (string) $guardado['password_hash'])) {
                throw new \RuntimeException('No fue posible verificar la nueva contraseña.');
            }

            $db->commit();
            return ['ok' => true, 'codigo' => self::PASSWORD_ACTUALIZADO, 'usuario' => $usuario];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::cambiarPassword - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }

    public static function cambiarActivo(int $usuarioId, bool $activo): array
    {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $adminsActivos = self::bloquearAdminsActivos();
            $fila = self::seleccionarUsuario($usuarioId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }

            $nuevoActivo = $activo ? 1 : 0;
            if ((int) $fila['activo'] === $nuevoActivo) {
                $db->commit();
                return ['ok' => true, 'codigo' => self::USUARIO_SIN_CAMBIOS];
            }

            if (!$activo && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO];
            }

            $stmt = $db->prepare('UPDATE usuarios SET activo = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('ii', $nuevoActivo, $usuarioId);
            self::ejecutarStatement($stmt);
            $stmt->close();
            $db->commit();

            return ['ok' => true, 'codigo' => $activo ? self::USUARIO_ACTIVADO : self::USUARIO_DESACTIVADO];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::cambiarActivo - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }

    public static function eliminar(int $usuarioId, int $usuarioActualId = 0): array
    {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $adminsActivos = self::bloquearAdminsActivos();
            $fila = self::seleccionarUsuario($usuarioId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }

            if ((int) $fila['activo'] === 1 && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO];
            }

            $stmt = $db->prepare('DELETE FROM usuarios WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $usuarioId);
            self::ejecutarStatement($stmt);
            $stmt->close();
            $db->commit();

            return [
                'ok' => true,
                'codigo' => self::USUARIO_ELIMINADO,
                'autoeliminacion' => $usuarioActualId > 0 && $usuarioActualId === $usuarioId,
            ];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::eliminar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }

    private static function bloquearAdminsActivos(): array
    {
        $resultado = ActiveRecord::getDB()->query(
            'SELECT id FROM usuarios WHERE activo = 1 ORDER BY id FOR UPDATE'
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int) $fila['id'];
        }
        $resultado->free();
        return $ids;
    }

    private static function seleccionarUsuario(int $usuarioId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, username, nombre, password_hash, activo, created_at, updated_at
                FROM usuarios WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = ActiveRecord::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $stmt->bind_param('i', $usuarioId);
        self::ejecutarStatement($stmt);
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function usuarioDesdeFila(array $fila): Usuario
    {
        $usuario = new Usuario();
        foreach ($fila as $campo => $valor) {
            if (property_exists($usuario, $campo)) {
                $usuario->{$campo} = $valor;
            }
        }
        return $usuario;
    }

    private static function resultadoInvalido(Usuario $usuario, array $alertas): array
    {
        return [
            'ok' => false,
            'codigo' => self::DATOS_INVALIDOS,
            'alertas' => $alertas,
            'usuario' => $usuario,
        ];
    }

    private static function ejecutarStatement(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error, (int) $stmt->errno);
        }
    }

    private static function rollbackSeguro(\mysqli $db): void
    {
        try {
            if ($db->thread_id) {
                $db->rollback();
            }
        } catch (\Throwable $e) {
            error_log('UsuarioService rollback - ' . $e->getMessage());
        }
    }
}
