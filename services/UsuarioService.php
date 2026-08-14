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
    public const NIP_ACTUALIZADO = 'NIP_ACTUALIZADO';
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
            /*
             * La validación va dentro de la transacción, no antes.
             *
             * La unicidad del NIP no la puede sostener un UNIQUE (bcrypt lleva
             * sal): la sostiene el barrido con FOR UPDATE de
             * Usuario::nipDisponible(). Validando fuera, ese bloqueo se soltaba
             * antes del INSERT y dos altas a la vez con el mismo NIP pasaban las
             * dos. Es la misma disposición que ya usa editar().
             */
            $db->begin_transaction();

            $alertas = $usuario->validarCrear();
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            $usuario->hashPassword();
            $usuario->hashNip();

            $stmt = $db->prepare(
                'INSERT INTO usuarios (username, nombre, fecha_nacimiento, password_hash, nip_hash, rol, activo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $activo = (int)$usuario->activo;
            // Columnas nullables: bind_param con 's' sobre una variable null
            // manda NULL, que es justo lo que quiere un admin (sin NIP) o un
            // alta sin fecha de nacimiento.
            $nacimiento = $usuario->fecha_nacimiento ?: null;
            // Misma regla que en editar(): un administrador nunca guarda NIP.
            // Un hash que el barrido de unicidad ignora, pero que serviría para
            // entrar si mañana pasa a piso, es justo lo que permite duplicados.
            $nipHash = (string) $usuario->rol === 'admin' ? null : ($usuario->nip_hash ?: null);
            $stmt->bind_param(
                'ssssssi',
                $usuario->username,
                $usuario->nombre,
                $nacimiento,
                $usuario->password_hash,
                $nipHash,
                $usuario->rol,
                $activo
            );
            self::ejecutarStatement($stmt);
            $id = (int)$db->insert_id;
            $stmt->close();

            $guardado = self::seleccionarUsuario($id);
            if (!$guardado) {
                throw new \RuntimeException('No fue posible verificar el usuario creado.');
            }
            /*
             * Sólo se verifica la contraseña si el alta traía una.
             *
             * El personal de piso entra con NIP y deja el campo vacío;
             * Usuario::hashPassword() guarda entonces un secreto aleatorio para
             * satisfacer la columna NOT NULL. Comparar la cadena vacía contra
             * ese hash fallaba siempre, así que el alta de meseros y cocineros
             * terminaba en rollback con un error genérico.
             */
            $passwordEnviada = (string)$usuario->password;
            if ($passwordEnviada !== '' && !password_verify($passwordEnviada, (string)$guardado['password_hash'])) {
                throw new \RuntimeException('No fue posible verificar el usuario creado.');
            }
            // El NIP es la única credencial del personal de piso: si se pidió
            // uno y no quedó verificable, el alta no sirve para entrar.
            if ($nipHash !== null && !password_verify((string)$usuario->nip, (string)$guardado['nip_hash'])) {
                throw new \RuntimeException('No fue posible verificar el NIP del usuario creado.');
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

            // El NIP solo se toca cuando llegó uno nuevo (tecleado o derivado
            // del cumpleaños). Dejar el campo vacío significa "conservar el
            // actual", así que la sentencia se arma en dos formas en lugar de
            // escribir NULL encima de un hash válido.
            $usuario->hashNip();
            $escribeNip = $usuario->nip !== null && $usuario->nip !== '';

            /*
             * Ascender a alguien a administrador le borra el NIP.
             *
             * Un admin entra con usuario y contraseña, así que su nip_hash no
             * identifica a nadie y por eso Usuario::nipDisponible() lo ignora.
             * Pero el hash seguía guardado: bastaba con ascender a esa persona,
             * dejar que otra tomara su NIP —ahora libre para el barrido— y
             * devolverle el rol de piso para acabar con dos personas
             * respondiendo al mismo NIP.
             */
            $limpiaNip = (string) $usuario->rol === 'admin';
            // Misma regla que el NIP: la contraseña sólo se reescribe cuando el
            // formulario mandó una. Vacía significa "conservar la actual", así
            // que las columnas opcionales se añaden al UPDATE en vez de pisar un
            // hash válido con uno derivado de una cadena vacía.
            $escribePassword = trim((string) $usuario->password) !== '';
            if ($escribePassword) {
                $usuario->hashPassword();
            }
            $nacimiento = $usuario->fecha_nacimiento ?: null;

            $columnas = ['username = ?', 'nombre = ?', 'fecha_nacimiento = ?'];
            $tipos = 'sss';
            $valores = [$usuario->username, $usuario->nombre, $nacimiento];

            if ($limpiaNip) {
                // Literal en el SQL: no hay valor que enlazar.
                $columnas[] = 'nip_hash = NULL';
                $usuario->nip_hash = null;
            } elseif ($escribeNip) {
                $columnas[] = 'nip_hash = ?';
                $tipos .= 's';
                $valores[] = $usuario->nip_hash;
            }
            if ($escribePassword) {
                $columnas[] = 'password_hash = ?';
                $tipos .= 's';
                $valores[] = $usuario->password_hash;
            }

            $columnas[] = 'rol = ?';
            $columnas[] = 'activo = ?';
            $tipos .= 'si';
            $valores[] = $usuario->rol;
            $valores[] = (int) $usuario->activo;

            $tipos .= 'i';
            $valores[] = $usuarioId;

            $stmt = $db->prepare(
                'UPDATE usuarios SET ' . implode(', ', $columnas) . ' WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param($tipos, ...$valores);
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

    /**
     * Cambia la credencial de un usuario: contrasena si es administrador, NIP
     * de 4 digitos si es personal de piso. El tipo lo decide el rol del
     * destino, no quien opera.
     *
     * $secretoActor es SIEMPRE la contrasena del administrador que ejecuta la
     * accion, nunca el secreto anterior del destino. La pagina vive bajo
     * /admin/, asi que quien la abre es admin por definicion, y un admin no
     * puede conocer el NIP de un mesero (van hasheados): pedirselo seria
     * inimplementable. Pedir el propio sigue defendiendo contra alguien que
     * pase por una terminal desbloqueada.
     *
     * Pendiente: el personal de piso no tiene forma de cambiar su propio NIP,
     * porque Auth::proteger() cierra todo /admin/ a rol admin. Un autoservicio
     * real necesitaria una ruta fuera de /admin/ mas su alta en la allowlist
     * de APIs de staff.
     */
    public static function cambiarCredencial(
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
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::USUARIO_NO_EXISTE];
            }

            $actor = $actorId === $usuarioId ? $fila : self::seleccionarUsuario($actorId);
            if (!$actor || !password_verify($secretoActor, (string)$actor['password_hash'])) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::CREDENCIAL_ACTUAL_INCORRECTA];
            }

            $usuario = self::usuarioDesdeFila($fila);
            $tipo = (string)$fila['rol'] === 'admin' ? 'password' : 'nip';

            if ($tipo === 'nip') {
                $usuario->nip = $nuevo;
                $usuario->nip_confirm = $confirmacion;
            } else {
                $usuario->password = $nuevo;
                $usuario->password_confirm = $confirmacion;
            }

            $alertas = $usuario->validarCambioCredencial($tipo);
            if (!empty($alertas['error'])) {
                $db->rollback();
                return self::resultadoInvalido($usuario, $alertas);
            }

            if ($tipo === 'nip') {
                $usuario->hashNip();
                $columna = 'nip_hash';
                $hash = $usuario->nip_hash;
            } else {
                $usuario->hashPassword();
                $columna = 'password_hash';
                $hash = $usuario->password_hash;
            }

            // $columna sale de un if cerrado, nunca de la entrada del usuario.
            $stmt = $db->prepare("UPDATE usuarios SET {$columna} = ? WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('si', $hash, $usuarioId);
            self::ejecutarStatement($stmt);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('La credencial no produjo una escritura verificable.');
            }
            $stmt->close();

            $guardado = self::seleccionarUsuario($usuarioId);
            if (!$guardado || !password_verify($nuevo, (string)$guardado[$columna])) {
                throw new \RuntimeException('No fue posible verificar la nueva credencial.');
            }

            $db->commit();
            return [
                'ok' => true,
                'codigo' => $tipo === 'nip' ? self::NIP_ACTUALIZADO : self::PASSWORD_ACTUALIZADO,
                'usuario' => $usuario,
            ];
        } catch (\Throwable $e) {
            self::rollbackSeguro($db);
            error_log('UsuarioService::cambiarCredencial - ' . $e->getMessage());
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

    /**
     * Un usuario se puede eliminar salvo que sea el ultimo administrador
     * activo. Un admin si puede eliminar a otro admin, e incluso a si mismo,
     * mientras quede otro admin activo: el invariante es "siempre hay alguien
     * que pueda entrar al panel", no "nadie toca a los admins".
     *
     * Se mide sobre admins ACTIVOS y no sobre cualquier fila con rol admin,
     * coherente con cambiarActivo() y editar(): un admin inactivo no puede
     * iniciar sesion y por tanto no sostiene el invariante.
     */
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
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new \RuntimeException('La eliminacion no afecto una fila.');
            }
            $stmt->close();

            if (self::seleccionarUsuario($usuarioId)) {
                throw new \RuntimeException('El usuario eliminado continua presente.');
            }

            $db->commit();
            // El controlador necesita saberlo para cerrar la sesion: si no,
            // $_SESSION['id'] queda apuntando a una fila que ya no existe.
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
            $ids[] = (int)$fila['id'];
        }
        $resultado->free();
        return $ids;
    }

    private static function seleccionarUsuario(int $usuarioId, bool $forUpdate = false): ?array
    {
        // nip_hash y fecha_nacimiento son obligatorios en el SELECT: sin ellos
        // usuarioDesdeFila() devolvía siempre nip_hash = null y la edición
        // regeneraba el NIP creyendo que el usuario no tenía.
        $sql = 'SELECT id, username, nombre, fecha_nacimiento, password_hash, nip_hash, rol, activo, created_at, updated_at
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
            // NULL en BD y '' en el modelo son el mismo "sin fecha".
            && (string)($fila['fecha_nacimiento'] ?? '') === (string)($usuario->fecha_nacimiento ?? '')
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
