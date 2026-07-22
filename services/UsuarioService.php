<?php

/**
 * Coordina las escrituras del modulo de usuarios y protege la regla del
 * ultimo administrador activo dentro de la misma transaccion.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Usuario;

class UsuarioService
{
    public const USUARIO_CREADO = 'USUARIO_CREADO';
    public const USUARIO_ACTUALIZADO = 'USUARIO_ACTUALIZADO';
    public const PASSWORD_ACTUALIZADO = 'PASSWORD_ACTUALIZADO';
    public const USUARIO_ACTIVADO = 'USUARIO_ACTIVADO';
    public const USUARIO_DESACTIVADO = 'USUARIO_DESACTIVADO';
    public const USUARIO_SIN_CAMBIOS = 'USUARIO_SIN_CAMBIOS';
    public const USUARIO_ELIMINADO = 'USUARIO_ELIMINADO';
    public const ADMIN_ACTIVO_REQUERIDO = 'ADMIN_ACTIVO_REQUERIDO';
    public const AUTOELIMINACION = 'AUTOELIMINACION';
    public const USUARIO_NO_EXISTE = 'USUARIO_NO_EXISTE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    public static function crear(Usuario $usuario): array
    {
        $alertas = $usuario->validarCrear();
        if (!empty($alertas['error'])) {
            return self::resultadoInvalido($usuario, $alertas);
        }

        $usuario->hashPassword();
        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();
            $stmt = $db->prepare(
                'INSERT INTO usuarios (username, nombre, password_hash, rol, activo)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $activo = (int)$usuario->activo;
            $stmt->bind_param(
                'ssssi',
                $usuario->username,
                $usuario->nombre,
                $usuario->password_hash,
                $usuario->rol,
                $activo
            );
            self::ejecutarStatement($stmt);
            $id = (int)$db->insert_id;
            $stmt->close();

            $guardado = self::seleccionarUsuario($id);
            if (!$guardado || !password_verify((string)$usuario->password, (string)$guardado['password_hash'])) {
                throw new \RuntimeException('No fue posible verificar el usuario creado.');
            }

            $db->commit();
            $usuario->id = $id;

            return ['ok' => true, 'codigo' => self::USUARIO_CREADO, 'usuario' => $usuario];
        } catch (\mysqli_sql_exception $e) {
            self::rollbackSeguro($db);
            if ((int)$e->getCode() === 1062) {
                return self::resultadoInvalido($usuario, ['error' => ['El nombre de usuario ya esta registrado.']]);
            }
            error_log('UsuarioService::crear - ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::crear - ' . $e->getMessage());
        }

        return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'usuario' => $usuario];
    }

    public static function editar(int $usuarioId, array $datos): array
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

            $usuario = self::usuarioDesdeFila($fila);
            $usuario->sincronizar($datos);
            $usuario->activo = (int)($datos['activo'] ?? $usuario->activo);

            $reduceAdmins = self::filaEsAdminActivo($fila)
                && ((string)$usuario->rol !== 'admin' || (int)$usuario->activo !== 1);
            if ($reduceAdmins && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO, 'usuario' => $usuario];
            }

            $alertas = $usuario->validarEditar();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $stmt = $db->prepare(
                'UPDATE usuarios SET username = ?, nombre = ?, rol = ?, activo = ? WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('sssii', $usuario->username, $usuario->nombre, $usuario->rol, $usuario->activo, $usuarioId);
            self::ejecutarStatement($stmt);
            $filasAfectadas = $stmt->affected_rows;
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!self::coincideUsuario($guardado, $usuario)) {
                throw new \RuntimeException('El estado persistido no coincide con la edicion solicitada.');
            }

            $db->commit();
            return [
                'ok' => true,
                'codigo' => $filasAfectadas === 0 ? self::USUARIO_SIN_CAMBIOS : self::USUARIO_ACTUALIZADO,
                'usuario' => $usuario,
            ];
        } catch (\mysqli_sql_exception $e) {
            self::rollbackSeguro($db);
            if ((int)$e->getCode() === 1062) {
                $usuario = isset($usuario) ? $usuario : new Usuario();
                return self::resultadoInvalido($usuario, ['error' => ['El nombre de usuario ya esta registrado.']]);
            }
            error_log('UsuarioService::editar - ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::editar - ' . $e->getMessage());
        }

        return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'usuario' => $usuario ?? null];
    }

    public static function cambiarPassword(int $usuarioId, string $password, string $confirmacion): array
    {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();
            $fila = self::seleccionarUsuario($usuarioId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }

            $usuario = self::usuarioDesdeFila($fila);
            $usuario->password = $password;
            $usuario->password_confirm = $confirmacion;
            $alertas = $usuario->validarCambioPassword();
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
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('La contrasena no produjo una escritura verificable.');
            }
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!$guardado || !password_verify($password, (string)$guardado['password_hash'])) {
                throw new \RuntimeException('No fue posible verificar la nueva contrasena.');
            }

            $db->commit();
            return ['ok' => true, 'codigo' => self::PASSWORD_ACTUALIZADO, 'usuario' => $usuario];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::cambiarPassword - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'usuario' => $usuario ?? null];
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
            if ((int)$fila['activo'] === $nuevoActivo) {
                $db->commit();
                return ['ok' => true, 'codigo' => self::USUARIO_SIN_CAMBIOS];
            }

            if (!$activo && self::filaEsAdminActivo($fila) && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO];
            }

            $stmt = $db->prepare('UPDATE usuarios SET activo = ? WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('ii', $nuevoActivo, $usuarioId);
            self::ejecutarStatement($stmt);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('El cambio de estado no afecto una fila.');
            }
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!$guardado || (int)$guardado['activo'] !== $nuevoActivo) {
                throw new \RuntimeException('El estado final del usuario no coincide con el solicitado.');
            }

            $db->commit();
            return [
                'ok' => true,
                'codigo' => $activo ? self::USUARIO_ACTIVADO : self::USUARIO_DESACTIVADO,
            ];
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
        if ($usuarioActualId > 0 && $usuarioActualId === $usuarioId) {
            return ['ok' => false, 'codigo' => self::AUTOELIMINACION];
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

            if (self::filaEsAdminActivo($fila) && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO];
            }

            $stmt = $db->prepare('DELETE FROM usuarios WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $usuarioId);
            self::ejecutarStatement($stmt);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('La eliminacion no afecto una fila.');
            }
            $stmt->close();

            if (self::seleccionarUsuario($usuarioId)) {
                throw new \RuntimeException('El usuario eliminado continua presente.');
            }

            $db->commit();
            return ['ok' => true, 'codigo' => self::USUARIO_ELIMINADO];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::eliminar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_GUARDADO];
        }
    }

    private static function bloquearAdminsActivos(): array
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id FOR UPDATE"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['id'];
        }
        $resultado->free();
        return $ids;
    }

    private static function seleccionarUsuario(int $usuarioId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, username, nombre, password_hash, rol, activo, created_at, updated_at
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

    private static function coincideUsuario(?array $fila, Usuario $usuario): bool
    {
        return $fila !== null
            && (string)$fila['username'] === (string)$usuario->username
            && (string)$fila['nombre'] === (string)$usuario->nombre
            && (string)$fila['rol'] === (string)$usuario->rol
            && (int)$fila['activo'] === (int)$usuario->activo;
    }

    private static function filaEsAdminActivo(array $fila): bool
    {
        return (string)$fila['rol'] === 'admin' && (int)$fila['activo'] === 1;
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
            throw new \RuntimeException($stmt->error);
        }
    }

    private static function rollbackSeguro(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('UsuarioService rollback - ' . $e->getMessage());
        }
    }
}
