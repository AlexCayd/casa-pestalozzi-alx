<?php

/**
 * Representa la tabla reservaciones y sus consultas administrativas.
 * Las reglas de mesas y transiciones viven en servicios dedicados.
 */

namespace Model;

use Services\ReservacionConfig;

class Reservacion extends ActiveRecord {
    protected static $tabla = 'reservaciones';
    /** @var array<string, string> Columna de retención detectada por conexión/base. */
    private static array $columnasVencimientoRetencion = [];
    protected static $columnasDB = [
        'id',
        'nombre',
        'email',
        'telefono',
        'contacto_tipo',
        'contacto_valor',
        'contacto_normalizado',
        'fecha',
        'hora',
        'comensales',
        'nota',
        'request_token',
        'request_fingerprint',
        'verification_expires_at',
        'confirmed_at',
        'expired_at',
        'cancelled_at',
        'arrived_at',
        'seated_at',
        'completed_at',
        'no_show_at',
        'cancelled_by',
        'no_show_by',
        'estado',
    ];

    public $id;
    public $nombre;
    public $email;
    public $telefono = null;
    public $contacto_tipo = 'email';
    public $contacto_valor;
    public $contacto_normalizado;
    public $fecha;
    public $hora;
    public $comensales = 2;
    public $nota;
    public $comentario_admin = null;
    public $request_token = null;
    public $request_fingerprint = null;
    public $verification_expires_at = null;
    public $confirmed_at = null;
    public $expired_at = null;
    public $cancelled_at = null;
    public $arrived_at = null;
    public $seated_at = null;
    public $completed_at = null;
    public $no_show_at = null;
    public $cancelled_by = null;
    public $no_show_by = null;
    public $estado = 'pendiente';
    public $created_at = null;
    // Asignación de mesas — no están en $columnasDB para no incluirlos en INSERTs
    public $mesas_asignadas = '';
    public $mesas_count = 0;
    public $capacidad_total = 0;
    public $mesa_ids = '';

    private static $comentarioAdminExiste = null;

    public static function findWithMesas($id) {
        $id = (int)$id;

        if ($id < 1) {
            return null;
        }

        $comentarioSelect = self::comentarioAdminAggregateSelect('r');

        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    {$comentarioSelect},
                    r.estado,
                    r.created_at,
                    COUNT(rm.id) AS mesas_count,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas_asignadas,
                    COALESCE(SUM(m.capacidad), 0) AS capacidad_total
                  FROM reservaciones r
                  LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
                  LEFT JOIN mesas m ON m.id = rm.mesa_id
                  WHERE r.id = {$id}
                  GROUP BY
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.estado,
                    r.created_at
                  LIMIT 1";

        $resultado = self::consultarSQL($query);

        return array_shift($resultado) ?: null;
    }

    public static function buscarPorRequestToken(string $token): ?self
    {
        $stmt = self::getDB()->prepare('SELECT * FROM reservaciones WHERE request_token = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de idempotencia.');
        }
        $stmt->bind_param('s', $token);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    /**
     * Reservaciones activas posteriores a la fecha y hora local indicadas.
     * La relación con dia_semana se resuelve en el servicio, no en SQL.
     */
    public static function buscarFuturasActivas(string $fechaActual, string $horaActual): array
    {
        $columnaVencimiento = self::columnaVencimientoRetencion();
        $stmt = self::getDB()->prepare(
            "SELECT id, nombre, fecha, hora, estado
             FROM " . static::$tabla . "
             WHERE (
                    estado IN ('pendiente', 'confirmada', 'llego', 'en_curso')
                    OR (
                        estado = 'pendiente_verificacion'
                        AND {$columnaVencimiento} > NOW()
                    )
                  )
               AND (fecha > ? OR (fecha = ? AND hora > ?))
             ORDER BY fecha ASC, hora ASC, id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de reservaciones futuras.');
        }

        $stmt->bind_param('sss', $fechaActual, $fechaActual, $horaActual);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar las reservaciones futuras.');
        }
        $resultado = $stmt->get_result();
        $reservaciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $reservaciones[] = static::crearObjeto($fila);
        }
        $stmt->close();

        return $reservaciones;
    }

    /** Reservaciones pendientes o confirmadas de una fecha concreta. */
    public static function buscarActivasPorFecha(string $fecha): array
    {
        $columnaVencimiento = self::columnaVencimientoRetencion();
        $stmt = self::getDB()->prepare(
            "SELECT id, nombre, fecha, hora, estado
             FROM " . static::$tabla . "
             WHERE fecha = ?
               AND (
                    estado IN ('pendiente', 'confirmada', 'llego', 'en_curso')
                    OR (
                        estado = 'pendiente_verificacion'
                        AND {$columnaVencimiento} > NOW()
                    )
               )
             ORDER BY hora ASC, id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de reservaciones de la fecha.');
        }

        $stmt->bind_param('s', $fecha);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar las reservaciones de la fecha.');
        }
        $resultado = $stmt->get_result();
        $reservaciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $reservaciones[] = static::crearObjeto($fila);
        }
        $stmt->close();

        return $reservaciones;
    }

    /**
     * El DDL canónico usa `verification_expires_at`. Una base de desarrollo
     * histórica usó `confirmacion_expires_at`; esta compatibilidad de lectura
     * evita que esa diferencia bloquee Configuración y disponibilidad.
     */
    public static function columnaVencimientoRetencion(): string
    {
        $db = self::getDB();
        $database = (string)($db->query('SELECT DATABASE() db')->fetch_assoc()['db'] ?? '');
        $cacheKey = spl_object_id($db) . ':' . $database;
        if (isset(self::$columnasVencimientoRetencion[$cacheKey])) {
            return self::$columnasVencimientoRetencion[$cacheKey];
        }

        foreach (['verification_expires_at', 'confirmacion_expires_at'] as $columna) {
            $resultado = $db->query(
                "SELECT COUNT(*) total
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'reservaciones'
                   AND column_name = '{$columna}'"
            );
            if ($resultado && (int)$resultado->fetch_assoc()['total'] === 1) {
                return self::$columnasVencimientoRetencion[$cacheKey] = $columna;
            }
        }

        throw new \RuntimeException(
            'El esquema de reservaciones no contiene una columna de vencimiento compatible.'
        );
    }

    /**
     * Consulta pública de solo lectura por la identidad obtenida de sesión.
     *
     * No acepta mesas, notas ni campos administrativos en el SELECT para que
     * esos datos no puedan filtrarse accidentalmente en la respuesta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buscarActivasPorContacto(
        string $tipo,
        string $contactoNormalizado,
        string $fechaActual,
        string $horaActual,
        int $limite
    ): array {
        $sql = "SELECT id, nombre, fecha, hora, comensales, nota, estado, contacto_tipo
                FROM reservaciones
                WHERE contacto_tipo = ?
                  AND contacto_normalizado = ?
                  AND estado IN ('pendiente', 'confirmada', 'llego', 'en_curso')
                  AND TIMESTAMP(fecha, hora) + INTERVAL " . ReservacionConfig::DURACION_RESERVACION_MINUTOS . " MINUTE
                      > TIMESTAMP(?, ?)
                ORDER BY fecha ASC, hora ASC, id ASC
                LIMIT ?";
        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta por contacto.');
        }

        $stmt->bind_param(
            'ssssi',
            $tipo,
            $contactoNormalizado,
            $fechaActual,
            $horaActual,
            $limite
        );
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }

        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        $stmt->close();

        return $filas;
    }

    /**
     * Cuenta todas las activas futuras para aplicar el máximo aunque la lista
     * visual esté limitada a cinco tarjetas.
     */
    public static function contarActivasPorContacto(
        string $tipo,
        string $contactoNormalizado,
        string $fechaActual,
        string $horaActual
    ): int {
        $sql = "SELECT COUNT(*) AS total
                FROM reservaciones
                WHERE contacto_tipo = ?
                  AND contacto_normalizado = ?
                  AND estado IN ('pendiente', 'confirmada', 'llego', 'en_curso')
                  AND TIMESTAMP(fecha, hora) + INTERVAL " . ReservacionConfig::DURACION_RESERVACION_MINUTOS . " MINUTE
                      > TIMESTAMP(?, ?)";
        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el conteo por contacto.');
        }

        $stmt->bind_param('ssss', $tipo, $contactoNormalizado, $fechaActual, $horaActual);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }

        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($fila['total'] ?? 0);
    }

    public static function buscarAdmin(array $filtros = []) {
        $condiciones = self::condicionesAdmin($filtros, true);
        $having = self::havingAsignacionAdmin($filtros);
        $comentarioSelect = self::comentarioAdminAggregateSelect('r');

        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    {$comentarioSelect},
                    r.estado,
                    COUNT(rm.id) AS mesas_count,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas_asignadas
                  FROM reservaciones r
                  LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
                  LEFT JOIN mesas m ON m.id = rm.mesa_id";

        if (!empty($condiciones)) {
            $query .= " WHERE " . implode(' AND ', $condiciones);
        }

        $query .= " GROUP BY
                        r.id,
                        r.nombre,
                        r.email,
                        r.fecha,
                        r.hora,
                        r.comensales,
                        r.nota,
                        r.estado";

        if ($having) {
            $query .= " HAVING {$having}";
        }

        $query .= " ORDER BY r.fecha ASC, r.hora ASC, r.id DESC";

        return self::consultarSQL($query);
    }

    public static function buscarPorDiaOperacionAdmin($fecha) {
        $fecha = self::escaparString($fecha);
        $comentarioSelect = self::comentarioAdminAggregateSelect('r');

        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    {$comentarioSelect},
                    r.estado,
                    COUNT(rm.id) AS mesas_count,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas_asignadas,
                    COALESCE(SUM(m.capacidad), 0) AS capacidad_total
                  FROM reservaciones r
                  LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
                  LEFT JOIN mesas m ON m.id = rm.mesa_id
                  WHERE r.fecha = '{$fecha}'
                  GROUP BY
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.estado
                  ORDER BY r.hora ASC,
                    FIELD(r.estado, " . self::estadosSql(ReservacionConfig::ORDEN_ESTADOS) . "),
                    r.id ASC";

        return self::consultarSQL($query);
    }

    public static function metricasAdmin(array $filtros = []) {
        $condiciones = self::condicionesAdmin($filtros, false);
        $having = self::havingAsignacionAdmin($filtros);

        $subquery = "SELECT r.id, r.estado, COUNT(rm.id) AS mesas_count
                     FROM reservaciones r
                     LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id";

        if (!empty($condiciones)) {
            $subquery .= " WHERE " . implode(' AND ', $condiciones);
        }

        $subquery .= " GROUP BY r.id, r.estado";

        if ($having) {
            $subquery .= " HAVING {$having}";
        }

        $query = "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(estado = 'pendiente'), 0) AS pendientes,
                    COALESCE(SUM(estado = 'confirmada'), 0) AS confirmadas,
                    COALESCE(SUM(estado = 'completada'), 0) AS completadas,
                    COALESCE(SUM(estado = 'cancelada'), 0) AS canceladas,
                    COALESCE(SUM(estado = 'no_show'), 0) AS no_show,
                    COALESCE(SUM(mesas_count = 0), 0) AS sin_mesa
                  FROM ({$subquery}) resumen";

        $resultado = self::$db->query($query);

        if (!$resultado) {
            return self::metricasAdminVacias();
        }

        $fila = $resultado->fetch_assoc() ?: [];
        $resultado->free();

        return [
            'total' => (int)($fila['total'] ?? 0),
            'pendientes' => (int)($fila['pendientes'] ?? 0),
            'confirmadas' => (int)($fila['confirmadas'] ?? 0),
            'completadas' => (int)($fila['completadas'] ?? 0),
            'canceladas' => (int)($fila['canceladas'] ?? 0),
            'no_show' => (int)($fila['no_show'] ?? 0),
            'sin_mesa' => (int)($fila['sin_mesa'] ?? 0),
        ];
    }

    public static function tieneComentarioAdmin() {
        if (self::$comentarioAdminExiste !== null) {
            return self::$comentarioAdminExiste;
        }

        $resultado = self::$db->query("SHOW COLUMNS FROM reservaciones LIKE 'comentario_admin'");

        self::$comentarioAdminExiste = $resultado && $resultado->num_rows > 0;

        if ($resultado) {
            $resultado->free();
        }

        return self::$comentarioAdminExiste;
    }

    private static function estadosSql(array $estados): string
    {
        return implode(', ', array_map(
            static fn(string $estado): string => "'" . self::escaparString($estado) . "'",
            $estados
        ));
    }

    private static function condicionesAdmin(array $filtros, $incluirEstado = true) {
        $condiciones = [];
        $q = trim((string)($filtros['q'] ?? ''));
        $fechaInicio = (string)($filtros['fecha_inicio'] ?? '');
        $fechaFin = (string)($filtros['fecha_fin'] ?? '');
        $estado = (string)($filtros['estado'] ?? '');

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(r.nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR r.email LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
        }

        if (self::fechaValidaAdmin($fechaInicio)) {
            $fechaInicio = self::escaparString($fechaInicio);
            $condiciones[] = "r.fecha >= '{$fechaInicio}'";
        }

        if (self::fechaValidaAdmin($fechaFin)) {
            $fechaFin = self::escaparString($fechaFin);
            $condiciones[] = "r.fecha <= '{$fechaFin}'";
        }

        if ($incluirEstado && in_array($estado, ReservacionConfig::estadosPermitidos(), true)) {
            $estado = self::escaparString($estado);
            $condiciones[] = "r.estado = '{$estado}'";
        }

        return $condiciones;
    }

    private static function comentarioAdminAggregateSelect($alias) {
        if (self::tieneComentarioAdmin()) {
            return "MAX({$alias}.comentario_admin) AS comentario_admin";
        }

        return "NULL AS comentario_admin";
    }

    private static function havingAsignacionAdmin(array $filtros) {
        $asignacion = (string)($filtros['asignacion'] ?? '');

        if ($asignacion === 'con_mesa') {
            return 'COUNT(rm.id) > 0';
        }

        if ($asignacion === 'sin_mesa') {
            return 'COUNT(rm.id) = 0';
        }

        return '';
    }

    private static function fechaValidaAdmin($fecha) {
        if (!is_string($fecha) || $fecha === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        $errors = \DateTimeImmutable::getLastErrors();
        $sinErrores = $errors === false
            || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0);

        return $date instanceof \DateTimeImmutable
            && $sinErrores
            && $date->format('Y-m-d') === $fecha;
    }

    private static function metricasAdminVacias() {
        return [
            'total' => 0,
            'pendientes' => 0,
            'confirmadas' => 0,
            'completadas' => 0,
            'canceladas' => 0,
            'no_show' => 0,
            'sin_mesa' => 0,
        ];
    }

    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre es obligatorio');
        }
        if (!$this->email) {
            static::setAlerta('error', 'El correo es obligatorio');
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            static::setAlerta('error', 'El correo no tiene un formato válido');
        }
        if (!$this->fecha) {
            static::setAlerta('error', 'La fecha es obligatoria');
        }
        if (!$this->hora) {
            static::setAlerta('error', 'La hora es obligatoria');
        }
        if (!$this->comensales || $this->comensales < 1) {
            static::setAlerta('error', 'El número de comensales es obligatorio');
        }

        return static::$alertas;
    }
}
