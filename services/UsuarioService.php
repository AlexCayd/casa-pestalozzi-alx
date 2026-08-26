<?php

namespace Services;

use Model\ActiveRecord;
use Model\Usuario;

/**
 * Coordina las escrituras de usuarios y mantiene las reglas de acceso dentro
 * de transacciones. La única credencial de piso se crea aquí mediante
 * NipService; nunca se toma de los datos enviados por un formulario.
 */
class UsuarioService
{
    public const USUARIO_CREADO = 'USUARIO_CREADO';
    public const USUARIO_ACTUALIZADO = 'USUARIO_ACTUALIZADO';
    public const PASSWORD_ACTUALIZADO = 'PASSWORD_ACTUALIZADO';
    public const NIP_REGENERADO = 'NIP_REGENERADO';
    public const CREDENCIAL_ACTUAL_INCORRECTA = 'CREDENCIAL_ACTUAL_INCORRECTA';
    public const USUARIO_ACTIVADO = 'USUARIO_ACTIVADO';
    public const USUARIO_DESACTIVADO = 'USUARIO_DESACTIVADO';
    public const USUARIO_SIN_CAMBIOS = 'USUARIO_SIN_CAMBIOS';
    public const USUARIO_ELIMINADO = 'USUARIO_ELIMINADO';
    public const ADMIN_ACTIVO_REQUERIDO = 'ADMIN_ACTIVO_REQUERIDO';
    public const USUARIO_NO_EXISTE = 'USUARIO_NO_EXISTE';
    public const ROL_INVALIDO = 'ROL_INVALIDO';
    public const NIP_CONFIGURACION_INVALIDA = 'NIP_CONFIGURACION_INVALIDA';
    public const NIP_NO_DISPONIBLE = 'NIP_NO_DISPONIBLE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_GUARDADO = 'ERROR_GUARDADO';

    public static function crear(Usuario $usuario): array
    {
        $db = ActiveRecord::getDB();
        $nip = null;

        if ($usuario->rol !== 'admin' && !NipService::secretoConfigurado()) {
            return self::errorNip($usuario, self::NIP_CONFIGURACION_INVALIDA);
        }

        try {
            $db->begin_transaction();

            $alertas = $usuario->validarCrear();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $usuario->hashPassword();
            $credential = null;
            $id = 0;

            for ($intento = 1; $intento <= NipService::MAX_INTENTOS; $intento++) {
                if ($usuario->rol === 'admin') {
                    $nipHash = null;
                    $nipLookup = null;
                } else {
                    $nip = NipService::generar();
                    $credential = NipService::credencial($nip);
                    $nipHash = $credential['hash'];
                    $nipLookup = $credential['lookup'];
                }

                $stmt = $db->prepare(
                    'INSERT INTO usuarios (username, nombre, password_hash, nip_hash, nip_lookup, rol, activo)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }

                $activo = (int) $usuario->activo;
                $stmt->bind_param(
                    'ssssssi',
                    $usuario->username,
                    $usuario->nombre,
                    $usuario->password_hash,
                    $nipHash,
                    $nipLookup,
                    $usuario->rol,
                    $activo
                );

                try {
                    self::ejecutarStatement($stmt);
                } catch (\Throwable $e) {
                    $stmt->close();
                    if (NipService::esColision($e) && $usuario->rol !== 'admin') {
                        $db->rollback();
                        $db->begin_transaction();
                        continue;
                    }
                    throw $e;
                }

                $id = (int) $db->insert_id;
                $stmt->close();
                break;
            }

            if ($id < 1) {
                throw new \RuntimeException('Se agotaron los intentos de reserva de NIP.');
            }

            $guardado = self::seleccionarUsuario($id);
            if (!$guardado) {
                throw new \RuntimeException('No fue posible verificar el usuario creado.');
            }

            if ($usuario->rol === 'admin') {
                if ($guardado['nip_hash'] !== null || $guardado['nip_lookup'] !== null) {
                    throw new \RuntimeException('El administrador quedó con una credencial de piso.');
                }
                if (!password_verify((string) $usuario->password, (string) $guardado['password_hash'])) {
                    throw new \RuntimeException('No fue posible verificar la contraseña creada.');
                }
            } elseif (
                !password_verify((string) $nip, (string) $guardado['nip_hash'])
                || !hash_equals((string) $credential['lookup'], (string) $guardado['nip_lookup'])
            ) {
                throw new \RuntimeException('No fue posible verificar el NIP creado.');
            }

            $db->commit();
            $usuario->id = $id;

            $resultado = [
                'ok' => true,
                'codigo' => self::USUARIO_CREADO,
                'usuario' => $usuario,
            ];
            if ($nip !== null) {
                $resultado['nip'] = $nip;
            }

            return $resultado;
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            if ((int) $e->getCode() === 1062 && !NipService::esColision($e)) {
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

            $rolAnterior = (string) $fila['rol'];
            $usuario = self::usuarioDesdeFila($fila);
            $usuario->sincronizar($datos);
            $usuario->activo = (int) ($datos['activo'] ?? $usuario->activo);

            $reduceAdmins = self::filaEsAdminActivo($fila)
                && ((string) $usuario->rol !== 'admin' || (int) $usuario->activo !== 1);
            if ($reduceAdmins && count($adminsActivos) <= 1) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ADMIN_ACTIVO_REQUERIDO, 'usuario' => $usuario];
            }

            $alertas = $usuario->validarEditar();
            $promocionaAAdmin = $rolAnterior !== 'admin' && $usuario->rol === 'admin';
            if ($promocionaAAdmin && trim((string) $usuario->password) === '') {
                Usuario::setAlerta('error', 'Define una contraseña administrativa antes de cambiar el rol.');
                $alertas = Usuario::getAlertas();
            }
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $generaNip = $usuario->rol !== 'admin'
                && ($rolAnterior === 'admin' || !$fila['nip_hash'] || !$fila['nip_lookup']);
            if ($generaNip && !NipService::secretoConfigurado()) {
                $db->rollback();
                return self::errorNip($usuario, self::NIP_CONFIGURACION_INVALIDA);
            }

            $escribePassword = $usuario->rol === 'admin' && trim((string) $usuario->password) !== '';
            if ($escribePassword) {
                $usuario->hashPassword();
            }

            $nip = null;
            $credential = null;
            $filasAfectadas = 0;

            for ($intento = 1; $intento <= NipService::MAX_INTENTOS; $intento++) {
                $columnas = ['username = ?', 'nombre = ?', 'rol = ?', 'activo = ?'];
                $tipos = 'sssi';
                $valores = [$usuario->username, $usuario->nombre, $usuario->rol, (int) $usuario->activo];

                if ($usuario->rol === 'admin') {
                    $columnas[] = 'nip_hash = NULL';
                    $columnas[] = 'nip_lookup = NULL';
                    $usuario->nip_hash = null;
                    $usuario->nip_lookup = null;
                } elseif ($generaNip) {
                    $nip = NipService::generar();
                    $credential = NipService::credencial($nip);
                    $columnas[] = 'nip_hash = ?';
                    $columnas[] = 'nip_lookup = ?';
                    $tipos .= 'ss';
                    $valores[] = $credential['hash'];
                    $valores[] = $credential['lookup'];
                    $usuario->nip_hash = $credential['hash'];
                    $usuario->nip_lookup = $credential['lookup'];
                }

                if ($escribePassword) {
                    $columnas[] = 'password_hash = ?';
                    $tipos .= 's';
                    $valores[] = $usuario->password_hash;
                }

                $tipos .= 'i';
                $valores[] = $usuarioId;
                $stmt = $db->prepare(
                    'UPDATE usuarios SET ' . implode(', ', $columnas) . ' WHERE id = ? LIMIT 1'
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $stmt->bind_param($tipos, ...$valores);

                try {
                    self::ejecutarStatement($stmt);
                } catch (\Throwable $e) {
                    $stmt->close();
                    if (NipService::esColision($e) && $generaNip) {
                        $db->rollback();
                        $db->begin_transaction();
                        continue;
                    }
                    throw $e;
                }

                $filasAfectadas = $stmt->affected_rows;
                $stmt->close();
                break;
            }

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!self::coincideEdicion($guardado, $usuario, $fila, $generaNip, $nip)) {
                throw new \RuntimeException('El estado persistido no coincide con la edición solicitada.');
            }

            $db->commit();
            $resultado = [
                'ok' => true,
                'codigo' => $filasAfectadas === 0 ? self::USUARIO_SIN_CAMBIOS : self::USUARIO_ACTUALIZADO,
                'usuario' => $usuario,
            ];
            if ($nip !== null) {
                $resultado['nip'] = $nip;
            }

            return $resultado;
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            if ((int) $e->getCode() === 1062 && !NipService::esColision($e)) {
                return self::resultadoInvalido($usuario ?? new Usuario(), ['error' => ['El nombre de usuario ya está registrado.']]);
            }
            error_log('UsuarioService::editar - ' . $e->getMessage());
        }

        return ['ok' => false, 'codigo' => self::ERROR_GUARDADO, 'usuario' => $usuario];
    }

    /** Sólo cambia contraseñas administrativas; el NIP tiene su propia rotación. */
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
                || $actor['rol'] !== 'admin'
                || (int) $actor['activo'] !== 1
                || !password_verify($secretoActor, (string) $actor['password_hash'])
            ) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::CREDENCIAL_ACTUAL_INCORRECTA];
            }
            if ((string) $fila['rol'] !== 'admin') {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ROL_INVALIDO];
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

    /** Genera y reemplaza un NIP; el anterior queda inválido al hacer commit. */
    public static function regenerarNip(int $usuarioId): array
    {
        if ($usuarioId < 1) {
            return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
        }
        if (!NipService::secretoConfigurado()) {
            return ['ok' => false, 'codigo' => self::NIP_CONFIGURACION_INVALIDA];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $fila = self::seleccionarUsuario($usuarioId, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }
            if (!in_array((string) $fila['rol'], ['waiter', 'cook'], true)) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ROL_INVALIDO];
            }

            $nip = null;
            $credential = null;
            for ($intento = 1; $intento <= NipService::MAX_INTENTOS; $intento++) {
                $nip = NipService::generar();
                $credential = NipService::credencial($nip);
                if (
                    (string) $fila['nip_lookup'] !== ''
                    && hash_equals((string) $fila['nip_lookup'], (string) $credential['lookup'])
                ) {
                    continue;
                }
                $stmt = $db->prepare('UPDATE usuarios SET nip_hash = ?, nip_lookup = ? WHERE id = ? LIMIT 1');
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $stmt->bind_param('ssi', $credential['hash'], $credential['lookup'], $usuarioId);
                try {
                    self::ejecutarStatement($stmt);
                } catch (\Throwable $e) {
                    $stmt->close();
                    if (NipService::esColision($e)) {
                        $db->rollback();
                        $db->begin_transaction();
                        continue;
                    }
                    throw $e;
                }
                $stmt->close();
                break;
            }

            $guardado = self::seleccionarUsuario($usuarioId);
            if (
                !$guardado
                || !password_verify((string) $nip, (string) $guardado['nip_hash'])
                || !hash_equals((string) $credential['lookup'], (string) $guardado['nip_lookup'])
            ) {
                throw new \RuntimeException('No fue posible verificar el NIP regenerado.');
            }

            $db->commit();
            return ['ok' => true, 'codigo' => self::NIP_REGENERADO, 'nip' => $nip];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::regenerarNip - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::NIP_NO_DISPONIBLE];
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
            "SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id FOR UPDATE"
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
        $sql = 'SELECT id, username, nombre, password_hash, nip_hash, nip_lookup, rol, activo, created_at, updated_at
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

    private static function coincideEdicion(
        ?array $fila,
        Usuario $usuario,
        array $filaAnterior,
        bool $generaNip,
        ?string $nip
    ): bool {
        if (
            !$fila
            || (string) $fila['username'] !== (string) $usuario->username
            || (string) $fila['nombre'] !== (string) $usuario->nombre
            || (string) $fila['rol'] !== (string) $usuario->rol
            || (int) $fila['activo'] !== (int) $usuario->activo
        ) {
            return false;
        }

        if ($usuario->rol === 'admin') {
            return $fila['nip_hash'] === null && $fila['nip_lookup'] === null;
        }

        if ($generaNip) {
            return $nip !== null
                && password_verify($nip, (string) $fila['nip_hash'])
                && hash_equals((string) $usuario->nip_lookup, (string) $fila['nip_lookup']);
        }

        return (string) $fila['nip_hash'] === (string) $filaAnterior['nip_hash']
            && (string) $fila['nip_lookup'] === (string) $filaAnterior['nip_lookup'];
    }

    private static function filaEsAdminActivo(array $fila): bool
    {
        return (string) $fila['rol'] === 'admin' && (int) $fila['activo'] === 1;
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

    private static function errorNip(Usuario $usuario, string $codigo): array
    {
        return [
            'ok' => false,
            'codigo' => $codigo,
            'alertas' => ['error' => ['No fue posible generar un NIP disponible. Intenta nuevamente.']],
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
