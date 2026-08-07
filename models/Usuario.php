<?php

namespace Model;

class Usuario extends ActiveRecord
{
    protected static $tabla = 'usuarios';

    protected static $columnasDB = [
        'id',
        'username',
        'nombre',
        'fecha_nacimiento',
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
    public $fecha_nacimiento;
    public $password_hash;
    public $nip_hash;
    public $rol = 'waiter';
    public $activo = 1;
    public $created_at;
    public $updated_at;

    // Propiedades temporales: no existen en SQL y no se guardan
    public $password;
    public $password_confirm;
    public $nip;
    public $nip_confirm;

    /**
     * Queda en true cuando el NIP se derivó del cumpleaños en lugar de
     * escribirse a mano, para que el controlador pueda mostrárselo al admin:
     * es la única vez que ese valor es visible (después solo existe hasheado).
     */
    public $nipGenerado = false;

    /** Longitud fija del NIP del personal de piso. */
    public const NIP_LONGITUD = 4;

    /**
     * Los tres roles de la operación. 'admin' entra con contraseña; 'waiter' y
     * 'cook' con NIP, y cada uno solo alcanza su zona (ver Classes\Auth).
     */
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
        // Credencial por rol, no las dos: el admin entra con contraseña y el
        // personal de piso con NIP. Pedirle contraseña a un mesero bloqueaba el
        // alta por un campo que esa persona no va a usar nunca.
        if ($this->rol === 'admin') {
            $this->validarPassword();
        }
        $this->generarNipDesdeNacimiento();
        $this->validarNip($this->rol !== 'admin');
        $this->validarUsernameUnico();

        return static::$alertas;
    }

    public function validarEditar()
    {
        static::$alertas = [];

        $this->normalizarDatos();
        $this->validarDatosBase();
        $this->generarNipDesdeNacimiento();
        $this->validarNip(false);
        $this->validarUsernameUnico($this->id);

        return static::$alertas;
    }

    /**
     * Cambio de credencial desde /admin/usuarios/cambiar-credencial. El tipo lo
     * decide el rol del usuario destino, no quien opera: 'password' para
     * administradores, 'nip' para el personal de piso.
     */
    public function validarCambioCredencial(string $tipo)
    {
        static::$alertas = [];

        if ($tipo === 'nip') {
            $this->validarNipNuevo();
        } else {
            $this->validarPassword();
        }

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
        $this->rol = $this->rol ?? 'waiter';
        $this->activo = (int) $this->activo;

        // El <input type="date"> manda '' cuando se deja vacío; la columna es
        // nullable, así que se normaliza a NULL antes de tocar la BD.
        $this->fecha_nacimiento = trim((string) ($this->fecha_nacimiento ?? ''));
        if ($this->fecha_nacimiento === '') {
            $this->fecha_nacimiento = null;
        }
        $this->validarFechaNacimiento();
    }

    private function validarFechaNacimiento()
    {
        if ($this->fecha_nacimiento === null) {
            return;
        }

        $fecha = \DateTime::createFromFormat('Y-m-d', $this->fecha_nacimiento);
        // El round-trip descarta fechas que PHP "corrige" sola (31 de febrero).
        if (!$fecha || $fecha->format('Y-m-d') !== $this->fecha_nacimiento) {
            static::setAlerta('error', 'La fecha de nacimiento no es válida');
            $this->fecha_nacimiento = null;
            return;
        }

        $anio = (int) $fecha->format('Y');
        if ($fecha > new \DateTime('today') || $anio < 1900) {
            static::setAlerta('error', 'La fecha de nacimiento no es válida');
            $this->fecha_nacimiento = null;
        }
    }

    /**
     * NIP por defecto a partir del cumpleaños, en formato DDMM (14 de marzo →
     * "1403"). Devuelve string, nunca int: el 3 de marzo tiene que quedar
     * "0303" y un cast se comería el cero inicial.
     */
    public function nipDesdeNacimiento(): ?string
    {
        if (!$this->fecha_nacimiento) {
            return null;
        }

        $fecha = \DateTime::createFromFormat('Y-m-d', $this->fecha_nacimiento);

        return $fecha ? $fecha->format('dm') : null;
    }

    /**
     * Si el admin dejó el NIP vacío, lo deriva del cumpleaños. Se asigna a
     * $this->nip para que el hasheo, la comprobación de unicidad y el guardado
     * sigan un único camino.
     *
     * Al editar solo aplica cuando el usuario todavía no tiene NIP: de lo
     * contrario, cambiarle el nombre a un mesero le regeneraría el NIP en
     * silencio y lo dejaría fuera del sistema.
     */
    private function generarNipDesdeNacimiento()
    {
        $this->nip = trim((string) ($this->nip ?? ''));

        if ($this->nip !== '' || $this->rol === 'admin') {
            return;
        }
        if ($this->id && $this->nip_hash) {
            return;
        }

        $generado = $this->nipDesdeNacimiento();
        if ($generado === null) {
            return;
        }

        $this->nip = $generado;
        $this->nipGenerado = true;
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
     * NIP de acceso rápido (4 dígitos exactos) del personal de piso.
     * Obligatorio al crear meseros y cocineros; al editar, dejarlo vacío conserva
     * el actual. Debe ser único entre usuarios porque el login identifica a la
     * persona solo con su NIP. Los administradores no lo usan.
     */
    private function validarNip(bool $obligatorio)
    {
        $this->nip = trim((string) ($this->nip ?? ''));

        if ($this->nip === '') {
            if ($obligatorio) {
                static::setAlerta(
                    'error',
                    'El NIP es obligatorio: escribe 4 dígitos o captura la fecha de nacimiento para generarlo'
                );
            }
            return;
        }

        if (!preg_match('/^\d{4}$/', $this->nip)) {
            static::setAlerta('error', 'El NIP debe tener exactamente 4 dígitos');
            return;
        }

        if (!self::nipDisponible($this->nip, $this->id ? (int) $this->id : null)) {
            // Un NIP generado y uno tecleado fallan por motivos distintos, así
            // que el admin necesita mensajes distintos: en el generado no
            // escribió nada y sin esta pista no sabría de dónde salió el 1403.
            static::setAlerta('error', $this->nipGenerado
                ? "El NIP {$this->nip}, generado desde la fecha de nacimiento, ya está asignado a otra persona. Escribe un NIP de 4 dígitos manualmente."
                : 'Ese NIP ya está asignado a otro usuario');
        }
    }

    /** NIP nuevo capturado en el cambio de credencial (nuevo + confirmación). */
    private function validarNipNuevo()
    {
        $this->nip = trim((string) ($this->nip ?? ''));
        $this->nip_confirm = trim((string) ($this->nip_confirm ?? ''));

        if ($this->nip === '') {
            static::setAlerta('error', 'El NIP nuevo es obligatorio');
            return;
        }

        if (!preg_match('/^\d{4}$/', $this->nip)) {
            static::setAlerta('error', 'El NIP debe tener exactamente 4 dígitos');
            return;
        }

        if ($this->nip !== $this->nip_confirm) {
            static::setAlerta('error', 'Los NIP no coinciden');
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

    /**
     * password_hash de usuarios.password es NOT NULL, pero el personal de piso
     * no tiene contraseña: entra por NIP. Para esos casos se guarda el hash de
     * un secreto aleatorio que nadie conoce, así la columna queda satisfecha y
     * la vía de usuario+contraseña permanece cerrada para ellos.
     */
    public function hashPassword()
    {
        $secreto = (string) $this->password;

        if ($secreto === '') {
            $secreto = bin2hex(random_bytes(32));
        }

        $this->password_hash = password_hash($secreto, PASSWORD_DEFAULT);
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
