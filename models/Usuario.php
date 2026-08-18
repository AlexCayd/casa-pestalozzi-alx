<?php

namespace Model;

use Services\NipService;

class Usuario extends ActiveRecord
{
    protected static $tabla = 'usuarios';

    protected static $columnasDB = [
        'id',
        'username',
        'nombre',
        'password_hash',
        'nip_hash',
        'nip_lookup',
        'rol',
        'activo',
        'created_at',
        'updated_at'
    ];

    public $id;
    public $username;
    public $nombre;
    public $password_hash;
    public $nip_hash;
    public $nip_lookup;
    public $rol = 'waiter';
    public $activo = 1;
    public $created_at;
    public $updated_at;

    // Sólo la contraseña administrativa llega desde los formularios.
    public $password;
    public $password_confirm;

    protected const ROLES_PERMITIDOS = [
        'admin',
        'waiter',
        'cook'
    ];

    public static function rolesPermitidos(): array
    {
        return self::ROLES_PERMITIDOS;
    }

    public static function buscarAdmin(array $filtros = []): array
    {
        $condiciones = self::condicionesAdmin($filtros);
        $query = "SELECT * FROM " . static::$tabla;

        if (!empty($condiciones)) {
            $query .= " WHERE " . implode(' AND ', $condiciones);
        }

        $query .= " ORDER BY nombre ASC, id DESC";

        return self::consultarSQL($query);
    }

    private static function condicionesAdmin(array $filtros): array
    {
        $condiciones = [];
        $q = trim((string) ($filtros['q'] ?? ''));
        $rol = (string) ($filtros['role'] ?? '');
        $status = (string) ($filtros['status'] ?? '');

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR username LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
        }

        if (in_array($rol, self::ROLES_PERMITIDOS, true)) {
            $condiciones[] = "rol = '" . self::escaparString($rol) . "'";
        }

        if ($status === 'active') {
            $condiciones[] = 'activo = 1';
        } elseif ($status === 'inactive') {
            $condiciones[] = 'activo = 0';
        }

        return $condiciones;
    }

    public function validarCrear(): array
    {
        static::$alertas = [];

        $this->normalizarDatos();
        $this->validarDatosBase();

        if ($this->rol === 'admin') {
            $this->validarPassword();
        }

        $this->validarUsernameUnico();

        return static::$alertas;
    }

    public function validarEditar(): array
    {
        static::$alertas = [];

        $this->normalizarDatos();
        $this->validarDatosBase();

        if (trim((string) $this->password) !== '' || trim((string) $this->password_confirm) !== '') {
            $this->validarPassword();
        }

        $this->validarUsernameUnico($this->id);

        return static::$alertas;
    }

    public function validarPasswordNueva(): array
    {
        static::$alertas = [];
        $this->validarPassword();

        return static::$alertas;
    }

    public function atributos()
    {
        $atributos = parent::atributos();
        unset($atributos['created_at'], $atributos['updated_at']);

        return $atributos;
    }

    private function normalizarDatos(): void
    {
        $this->nombre = trim((string) ($this->nombre ?? ''));
        $this->username = trim((string) ($this->username ?? ''));
        $this->rol = (string) ($this->rol ?: 'waiter');
        $this->activo = (int) $this->activo;
    }

    private function validarDatosBase(): void
    {
        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre es obligatorio');
        }

        if (!$this->username) {
            static::setAlerta('error', 'El nombre de usuario es obligatorio');
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $this->username)) {
            static::setAlerta(
                'error',
                'El usuario debe tener de 3 a 20 caracteres y solo usar letras, números o guion bajo'
            );
        }

        if (!in_array($this->rol, self::ROLES_PERMITIDOS, true)) {
            static::setAlerta('error', 'El rol no es válido');
        }

        if ($this->activo !== 0 && $this->activo !== 1) {
            static::setAlerta('error', 'El estado del usuario no es válido');
        }
    }

    private function validarPassword(): void
    {
        $this->password = trim((string) ($this->password ?? ''));
        $this->password_confirm = trim((string) ($this->password_confirm ?? ''));

        if (!$this->password) {
            static::setAlerta('error', 'La contraseña es obligatoria');
        } else {
            if (strlen($this->password) < 8) {
                static::setAlerta('error', 'La contraseña debe tener al menos 8 caracteres.');
            }

            if (!preg_match('/[A-Z]/', $this->password)) {
                static::setAlerta('error', 'La contraseña debe incluir al menos una letra mayúscula.');
            }

            if (!preg_match('/[0-9]/', $this->password)) {
                static::setAlerta('error', 'La contraseña debe incluir al menos un número.');
            }
        }

        if ($this->password !== $this->password_confirm) {
            static::setAlerta('error', 'Las contraseñas no coinciden');
        }
    }

    private function validarUsernameUnico($idActual = null): void
    {
        if (!$this->username) {
            return;
        }

        $usuarioExistente = self::where('username', self::escaparString($this->username));
        if (!$usuarioExistente) {
            return;
        }

        if ($idActual && (int) $usuarioExistente->id === (int) $idActual) {
            return;
        }

        static::setAlerta('error', 'El nombre de usuario ya está registrado');
    }

    /** Login directo por HMAC; el hash sólo confirma el candidato recibido. */
    public static function porNip(string $nip): ?Usuario
    {
        try {
            $lookup = NipService::lookup($nip);
            $db = self::getDB();
            $stmt = $db->prepare(
                "SELECT * FROM " . static::$tabla .
                " WHERE nip_lookup = ? AND activo = 1 AND rol IN ('waiter', 'cook') LIMIT 1"
            );

            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $stmt->bind_param('s', $lookup);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }

            $fila = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return password_verify($nip, (string) $fila['nip_hash'])
                ? static::crearObjeto($fila)
                : null;
        } catch (\Throwable $e) {
            // La autenticación debe responder de forma genérica también si la
            // configuración del HMAC está incompleta o la consulta falla.
            return null;
        }
    }

    /** Login administrativo por usuario + contraseña. */
    public static function porCredenciales(string $username, string $password): ?Usuario
    {
        $db = self::getDB();
        $stmt = $db->prepare(
            "SELECT * FROM " . static::$tabla .
            " WHERE activo = 1 AND rol = 'admin' AND username = ? LIMIT 1"
        );

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila && password_verify($password, (string) $fila['password_hash'])
            ? static::crearObjeto($fila)
            : null;
    }

    /** Admins tienen contraseña; el personal recibe un secreto aleatorio no utilizable. */
    public function hashPassword(): void
    {
        $secreto = (string) $this->password;
        if ($secreto === '') {
            $secreto = bin2hex(random_bytes(32));
        }

        $this->password_hash = password_hash($secreto, PASSWORD_DEFAULT);
    }

    public function esAdminActivo(): bool
    {
        return $this->rol === 'admin' && (int) $this->activo === 1;
    }

    public static function contarAdminsActivos(): int
    {
        $query = "SELECT COUNT(*) FROM " . static::$tabla . " WHERE rol = 'admin' AND activo = 1";
        $resultado = self::$db->query($query);

        if (!$resultado) {
            return 0;
        }

        $total = $resultado->fetch_array();
        $resultado->free();

        return (int) array_shift($total);
    }
}
