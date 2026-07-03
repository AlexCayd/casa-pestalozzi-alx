<?php
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
    public $mesa_id            = null;
    public $mesa_secundaria_id = null;
    public $mesas_asignadas = '';
    public $mesas_count = 0;
    public $capacidad_total = 0;

    private const ESTADOS_ADMIN = ['pendiente', 'confirmada', 'completada', 'cancelada', 'no_show'];
    private static $comentarioAdminExiste = null;

    private static function minutosDesdeHora($hora) {
        $partes = explode(':', (string)$hora);
        $horas  = isset($partes[0]) ? (int)$partes[0] : 0;
        $min    = isset($partes[1]) ? (int)$partes[1] : 0;

        return ($horas * 60) + $min;
    }

    public static function obtenerMesasDisponibles($fecha, $hora, $excluirReservacionId = null) {
        $mesas = Mesa::consultarSQL(
            "SELECT id, numero, nombre, capacidad
             FROM mesas
             WHERE reservable = 1 AND activo = 1
             ORDER BY numero ASC"
        );

        $ocupadas = self::ocupacionMesasParaHorario($fecha, $hora, $excluirReservacionId);

        return array_values(array_filter($mesas, function($mesa) use ($ocupadas) {
            return empty($ocupadas[(int)$mesa->id]);
        }));
    }

    public static function ocupacionMesasParaHorario($fecha, $hora, $excluirReservacionId = null) {
        $fecha = self::escaparString($fecha);
        $horaMin = self::minutosDesdeHora($hora);
        $excluirSql = $excluirReservacionId ? ' AND r.id != ' . (int)$excluirReservacionId : '';

        $resultado = self::$db->query(
            "SELECT rm.mesa_id, r.hora
                    , r.id AS reservacion_id
                    , r.nombre
                    , r.email
                    , r.comensales
                    , r.estado
             FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             WHERE r.fecha = '{$fecha}'
               {$excluirSql}
               AND r.estado IN ('pendiente','confirmada')"
        );

        if (!$resultado) {
            return [];
        }

        $ocupadas = [];
        while ($reserva = $resultado->fetch_assoc()) {
            $reservaMin = self::minutosDesdeHora($reserva['hora'] ?? '');
            $inicio = $reservaMin - 30;
            $fin = $reservaMin + 90;

            if ($horaMin >= $inicio && $horaMin < $fin && !empty($reserva['mesa_id'])) {
                $ocupadas[(int)$reserva['mesa_id']] = [
                    'reservacion_id' => (int)$reserva['reservacion_id'],
                    'nombre' => (string)$reserva['nombre'],
                    'email' => (string)$reserva['email'],
                    'hora' => (string)$reserva['hora'],
                    'comensales' => (int)$reserva['comensales'],
                    'estado' => (string)$reserva['estado'],
                ];
            }
        }

        $resultado->free();

        return $ocupadas;
    }

    public static function seleccionarMesasParaComensales($mesasDisponibles, $comensales) {
        $comensales = max(1, (int)$comensales);

        if (empty($mesasDisponibles)) {
            return [];
        }

        if ($comensales > 4 && $comensales <= 8) {
            $paresPrioridad = [[2, 4], [5, 11], [10, 11], [8, 9]];
            $porNumero = [];

            foreach ($mesasDisponibles as $mesa) {
                $porNumero[(int)$mesa->numero] = $mesa;
            }

            foreach ($paresPrioridad as $par) {
                if (!isset($porNumero[$par[0]], $porNumero[$par[1]])) {
                    continue;
                }

                $seleccion = [$porNumero[$par[0]], $porNumero[$par[1]]];
                $capacidad = array_reduce($seleccion, function($total, $mesa) {
                    return $total + (int)$mesa->capacidad;
                }, 0);

                if ($capacidad >= $comensales) {
                    return $seleccion;
                }
            }
        }

        $seleccionadas = [];
        $capacidadTotal = 0;
        $idsAgregados = [];

        foreach ($mesasDisponibles as $mesa) {
            $mesaId = (int)$mesa->id;
            if (isset($idsAgregados[$mesaId])) {
                continue;
            }

            $seleccionadas[] = $mesa;
            $idsAgregados[$mesaId] = true;
            $capacidadTotal += (int)$mesa->capacidad;

            if ($capacidadTotal >= $comensales) {
                return $seleccionadas;
            }
        }

        return [];
    }

    public static function asignarMesas($reservacionId, array $mesaIds) {
        $reservacionId = (int)$reservacionId;
        $mesaIds = array_values(array_unique(array_filter(array_map('intval', $mesaIds))));

        self::ejecutarSQL("DELETE FROM reservacion_mesas WHERE reservacion_id = {$reservacionId}");

        if (!empty($mesaIds)) {
            $valores = [];
            foreach ($mesaIds as $index => $mesaId) {
                $orden = $index + 1;
                $valores[] = "({$reservacionId}, {$mesaId}, {$orden})";
            }

            self::ejecutarSQL(
                "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
                 VALUES " . implode(', ', $valores)
            );
        }

        $mesa1 = isset($mesaIds[0]) ? (int)$mesaIds[0] : 'NULL';
        $mesa2 = isset($mesaIds[1]) ? (int)$mesaIds[1] : 'NULL';

        return self::ejecutarSQL(
            "UPDATE reservaciones
             SET mesa_id = {$mesa1}, mesa_secundaria_id = {$mesa2}
             WHERE id = {$reservacionId}"
        );
    }

    public static function obtenerMesasAsignadas($reservacionId) {
        $reservacionId = (int)$reservacionId;

        return Mesa::consultarSQL(
            "SELECT m.id, m.numero, m.nombre, m.capacidad
             FROM reservacion_mesas rm
             INNER JOIN mesas m ON m.id = rm.mesa_id
             WHERE rm.reservacion_id = {$reservacionId}
             ORDER BY rm.orden ASC"
        );
    }

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
                    r.mesa_id,
                    r.mesa_secundaria_id,
                    COUNT(rm.id) AS mesas_count,
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
                    r.created_at,
                    r.mesa_id,
                    r.mesa_secundaria_id
                  LIMIT 1";

        $resultado = self::consultarSQL($query);

        return array_shift($resultado) ?: null;
    }

    public static function capacidadAsignada($reservacionId) {
        $reservacionId = (int)$reservacionId;

        if ($reservacionId < 1) {
            return 0;
        }

        $resultado = self::$db->query(
            "SELECT COALESCE(SUM(m.capacidad), 0) AS capacidad_total
             FROM reservacion_mesas rm
             INNER JOIN mesas m ON m.id = rm.mesa_id
             WHERE rm.reservacion_id = {$reservacionId}"
        );

        if (!$resultado) {
            return 0;
        }

        $fila = $resultado->fetch_assoc() ?: ['capacidad_total' => 0];
        $resultado->free();

        return (int)$fila['capacidad_total'];
    }

    public static function limpiarMesasAsignadas($reservacionId) {
        return self::asignarMesas($reservacionId, []);
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
                    r.mesa_id,
                    r.mesa_secundaria_id,
                    COUNT(rm.id) AS mesas_count,
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
                        r.estado,
                        r.mesa_id,
                        r.mesa_secundaria_id";

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
                    r.mesa_id,
                    r.mesa_secundaria_id,
                    COUNT(rm.id) AS mesas_count,
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
                    r.estado,
                    r.mesa_id,
                    r.mesa_secundaria_id
                  ORDER BY FIELD(r.estado, 'pendiente', 'confirmada', 'completada', 'no_show', 'cancelada'), r.id DESC";

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

    public static function cambiarEstado($id, $estado) {
        $id = (int)$id;
        $estado = (string)$estado;

        if ($id < 1 || !in_array($estado, self::ESTADOS_ADMIN, true)) {
            return false;
        }

        $estado = self::escaparString($estado);

        return self::ejecutarSQL(
            "UPDATE reservaciones SET estado = '{$estado}' WHERE id = {$id} LIMIT 1"
        );
    }

    public static function actualizarComentarioAdmin($id, $comentario) {
        $id = (int)$id;

        if ($id < 1 || !self::tieneComentarioAdmin()) {
            return false;
        }

        $comentario = self::escaparString($comentario);

        return self::ejecutarSQL(
            "UPDATE reservaciones
             SET comentario_admin = '{$comentario}'
             WHERE id = {$id}
             LIMIT 1"
        );
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

    public static function tieneMesasAsignadas($id) {
        $id = (int)$id;
        if ($id < 1) {
            return false;
        }

        $resultado = self::$db->query(
            "SELECT COUNT(*) AS total FROM reservacion_mesas WHERE reservacion_id = {$id}"
        );

        if (!$resultado) {
            return false;
        }

        $fila = $resultado->fetch_assoc() ?: ['total' => 0];
        $resultado->free();

        return (int)$fila['total'] > 0;
    }

    public static function obtenerResumenMesasPorReservacion($ids = []) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));

        if (empty($ids)) {
            return [];
        }

        $query = "SELECT
                    rm.reservacion_id,
                    GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', ') AS mesas_asignadas
                  FROM reservacion_mesas rm
                  INNER JOIN mesas m ON m.id = rm.mesa_id
                  WHERE rm.reservacion_id IN (" . implode(',', $ids) . ")
                  GROUP BY rm.reservacion_id";

        $resultado = self::$db->query($query);

        if (!$resultado) {
            return [];
        }

        $resumen = [];
        while ($fila = $resultado->fetch_assoc()) {
            $resumen[(int)$fila['reservacion_id']] = (string)($fila['mesas_asignadas'] ?? '');
        }

        $resultado->free();

        return $resumen;
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
        return is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha);
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
