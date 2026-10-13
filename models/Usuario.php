<?php

namespace Model;

/**
 * Usuarios del panel. Hay un solo tipo de usuario —administrador— y entra con
 * usuario + contraseña.
 */
class Usuario extends ActiveRecord
{
    protected static $tabla = 'usuarios';

    protected static $columnasDB = [
        'id',
        'username',
        'nombre',
        'password_hash',
        'activo',
        'created_at',
        'updated_at'
    ];

    public $id;
    public $username;
    public $nombre;
    public $password_hash;
    public $activo = 1;
    public $created_at;
    public $updated_at;

    // La contraseña sólo llega desde los formularios; nunca se persiste en claro.
    public $password;
    public $password_confirm;

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
        $status = (string) ($filtros['status'] ?? '');

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR username LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
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
        $this->validarPassword();
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

    /** Login por usuario + contraseña. */
    public static function porCredenciales(string $username, string $password): ?Usuario
    {
        $db = self::getDB();
        $stmt = $db->prepare(
            "SELECT * FROM " . static::$tabla .
            " WHERE activo = 1 AND username = ? LIMIT 1"
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

    public function hashPassword(): void
    {
        $this->password_hash = password_hash((string) $this->password, PASSWORD_DEFAULT);
    }

    public function esAdminActivo(): bool
    {
        return (int) $this->activo === 1;
    }

    public static function contarAdminsActivos(): int
    {
        $query = "SELECT COUNT(*) FROM " . static::$tabla . " WHERE activo = 1";
        $resultado = self::$db->query($query);

        if (!$resultado) {
            return 0;
        }

        $total = $resultado->fetch_array();
        $resultado->free();

        return (int) array_shift($total);
    }
}
