<?php

namespace Model;

class Usuario extends ActiveRecord
{
    protected static $tabla = 'usuarios';

    protected static $columnasDB = [
        'id',
        'username',
        'nombre',
        'password_hash',
        'nip_hash',
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
    public $rol = 'observer';
    public $activo = 1;
    public $created_at;
    public $updated_at;

    // Propiedades temporales: no existen en SQL y no se guardan
    public $password;
    public $password_confirm;
    public $nip;

    protected const ROLES_PERMITIDOS = [
        'admin',
        'observer',
        'waiter',
        'cashier'
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
            $rolEscapado = self::escaparString($rol);
            $condiciones[] = "rol = '{$rolEscapado}'";
        }

        if ($status === 'active') {
            $condiciones[] = "activo = 1";
        } elseif ($status === 'inactive') {
            $condiciones[] = "activo = 0";
        }

        return $condiciones;
    }

    public function validarCrear()
    {
        static::$alertas = [];

        $this->normalizarDatos();
        $this->validarDatosBase();
        $this->validarPassword();
        // El NIP solo lo usa el personal de piso; el admin entra con contraseña
        $this->validarNip($this->rol !== 'admin');
        $this->validarUsernameUnico();

        return static::$alertas;
    }

    public function validarEditar()
    {
        static::$alertas = [];

        $this->normalizarDatos();
        $this->validarDatosBase();
        $this->validarNip(false);
        $this->validarUsernameUnico($this->id);

        return static::$alertas;
    }

    public function validarCambioPassword()
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

    private function normalizarDatos()
    {
        $this->nombre = trim($this->nombre ?? '');
        $this->username = trim($this->username ?? '');
        $this->rol = $this->rol ?? 'observer';
        $this->activo = (int) $this->activo;
    }

    private function validarDatosBase()
    {
        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre es obligatorio');
        }

        if (!$this->username) {
            static::setAlerta('error', 'El nombre de usuario es obligatorio');
        } elseif (!filter_var($this->username, FILTER_VALIDATE_REGEXP, [
            'options' => [
                'regexp' => '/^[a-zA-Z0-9_]{3,20}$/'
            ]
        ])) {
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

    private function validarPassword()
    {
        $this->password = trim($this->password ?? '');
        $this->password_confirm = trim($this->password_confirm ?? '');

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

    private function validarUsernameUnico($idActual = null)
    {
        if (!$this->username) {
            return;
        }

        $username = self::escaparString($this->username);
        $usuarioExistente = self::where('username', $username);

        if (!$usuarioExistente) {
            return;
        }

        if ($idActual && (int) $usuarioExistente->id === (int) $idActual) {
            return;
        }

        static::setAlerta('error', 'El nombre de usuario ya está registrado');
    }

    /**
     * NIP de acceso rápido (4 a 6 dígitos) del personal de piso. Obligatorio al
     * crear meseros/cajeros; al editar, dejarlo vacío conserva el actual. Debe
     * ser único entre usuarios porque el login identifica a la persona solo
     * con su NIP. Los administradores no lo usan: entran con contraseña.
     */
    private function validarNip(bool $obligatorio)
    {
        $this->nip = trim((string) ($this->nip ?? ''));

        if ($this->nip === '') {
            if ($obligatorio) {
                static::setAlerta('error', 'El NIP es obligatorio');
            }
            return;
        }

        if (!preg_match('/^\d{4,6}$/', $this->nip)) {
            static::setAlerta('error', 'El NIP debe tener de 4 a 6 dígitos');
            return;
        }

        if (!self::nipDisponible($this->nip, $this->id ? (int) $this->id : null)) {
            static::setAlerta('error', 'Ese NIP ya está asignado a otro usuario');
        }
    }

    /** Verifica que ningún otro usuario tenga ya este NIP (los NIP van hasheados). */
    public static function nipDisponible(string $nip, ?int $idActual = null): bool
    {
        $usuarios = self::consultarSQL(
            "SELECT id, nip_hash FROM " . static::$tabla . " WHERE nip_hash IS NOT NULL"
        );

        foreach ($usuarios as $usuario) {
            if ($idActual && (int) $usuario->id === $idActual) {
                continue;
            }
            if (password_verify($nip, (string) $usuario->nip_hash)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Identifica al usuario activo cuyo NIP coincide. Los NIP se guardan
     * hasheados, así que se recorre al personal activo con password_verify
     * (la plantilla es pequeña; no hay problema de rendimiento).
     * Los administradores quedan fuera: su acceso es por contraseña.
     */
    public static function porNip(string $nip): ?Usuario
    {
        $usuarios = self::consultarSQL(
            "SELECT * FROM " . static::$tabla .
            " WHERE activo = 1 AND nip_hash IS NOT NULL AND rol <> 'admin'"
        );

        foreach ($usuarios as $usuario) {
            if (password_verify($nip, (string) $usuario->nip_hash)) {
                return $usuario;
            }
        }

        return null;
    }

    /** Identifica al usuario activo por usuario + contraseña (login del admin). */
    public static function porCredenciales(string $username, string $password): ?Usuario
    {
        $usernameEscapado = self::escaparString($username);
        $usuario = self::consultarSQL(
            "SELECT * FROM " . static::$tabla .
            " WHERE activo = 1 AND username = '{$usernameEscapado}' LIMIT 1"
        )[0] ?? null;

        if ($usuario && password_verify($password, (string) $usuario->password_hash)) {
            return $usuario;
        }

        return null;
    }

    public function hashPassword()
    {
        $this->password_hash = password_hash($this->password, PASSWORD_DEFAULT);
    }

    public function hashNip()
    {
        if ($this->nip !== null && $this->nip !== '') {
            $this->nip_hash = password_hash($this->nip, PASSWORD_DEFAULT);
        }
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
