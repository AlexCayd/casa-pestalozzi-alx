<?php

/**
 * Representa la tabla reservaciones y sus consultas administrativas.
 * Las reglas de mesas y transiciones viven en servicios dedicados.
 */

namespace Model;

class Reservacion extends ActiveRecord {
    protected static $tabla = 'reservaciones';
    protected static $columnasDB = ['id', 'nombre', 'email', 'fecha', 'hora', 'comensales', 'nota', 'estado'];

    public $id;
    public $nombre;
    public $email;
    public $fecha;
    public $hora;
    public $comensales = 2;
    public $nota;
    public $comentario_admin = null;
    public $estado = 'pendiente';
    public $created_at = null;
    // Asignación de mesas — no están en $columnasDB para no incluirlos en INSERTs
    public $mesas_asignadas = '';
    public $mesas_count = 0;
    public $capacidad_total = 0;
    public $mesa_ids = '';

    private const ESTADOS_ADMIN = ['pendiente', 'confirmada', 'completada', 'cancelada', 'no_show'];
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

    public static function estadosPermitidosAdmin() {
        return self::ESTADOS_ADMIN;
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

    public static function buscarPorHorarioAdmin($fecha, $hora, $estado = '') {
        $fecha = self::escaparString($fecha);
        $hora = self::escaparString($hora);
        $estado = (string)$estado;
        $estadoSql = '';
        $comentarioSelect = self::comentarioAdminAggregateSelect('r');

        if (in_array($estado, self::ESTADOS_ADMIN, true)) {
            $estado = self::escaparString($estado);
            $estadoSql = " AND r.estado = '{$estado}'";
        }

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
                    AND r.hora = '{$hora}'
                    {$estadoSql}
                  GROUP BY
                    r.id,
                    r.nombre,
                    r.email,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.estado
                  ORDER BY FIELD(r.estado, 'pendiente', 'confirmada', 'completada', 'no_show', 'cancelada'), r.id DESC";

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
                    FIELD(r.estado, 'pendiente', 'confirmada', 'completada', 'no_show', 'cancelada'),
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

        if ($incluirEstado && in_array($estado, self::ESTADOS_ADMIN, true)) {
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
