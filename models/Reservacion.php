<?php

/**
 * Representa la tabla reservaciones y sus consultas administrativas.
 * Las reglas de mesas y transiciones viven en servicios dedicados.
 */

namespace Model;

    use Services\ReservacionConfig;
    use Services\ContactoService;
    use Services\ReservacionVigenciaService;

class Reservacion extends ActiveRecord {
    protected static $tabla = 'reservaciones';
    protected static $columnasDB = [
        'id',
        'nombre',
        'contacto_tipo',
        'contacto',
        'fecha',
        'hora',
        'comensales',
        'nota',
        'comentario_admin',
        'origen',
        'estado',
        'hold_expires_at',
        'reemplaza_reservacion_id',
        'request_token',
        'estado_changed_at',
    ];

    public $id;
    public $nombre;
    public $contacto_tipo = 'ninguno';
    public $contacto = null;
    public $fecha;
    public $hora;
    public $comensales = 2;
    public $nota;
    public $comentario_admin = null;
    public $origen = 'admin';
    public $reemplaza_reservacion_id = null;
    public $request_token = null;
    public $hold_expires_at = null;
    public $estado_changed_at = null;
    public $estado = 'pendiente_verificacion';
    public $created_at = null;
    public $updated_at = null;
    // Asignación de mesas — no están en $columnasDB para no incluirlos en INSERTs
    public $mesas_asignadas = '';
    public $mesas_count = 0;
    public $capacidad_total = 0;
    public $mesa_ids = '';

    public static function findWithMesas($id) {
        $id = (int)$id;

        if ($id < 1) {
            return null;
        }

        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.contacto_tipo,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.comentario_admin,
                    r.origen,
                    r.estado,
                    r.hold_expires_at,
                    r.reemplaza_reservacion_id,
                    r.request_token,
                    r.estado_changed_at,
                    r.created_at,
                    r.updated_at,
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
                    r.contacto_tipo,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.comentario_admin,
                    r.origen,
                    r.estado,
                    r.hold_expires_at,
                    r.reemplaza_reservacion_id,
                    r.request_token,
                    r.estado_changed_at,
                    r.created_at,
                    r.updated_at
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
     * Persiste la creación administrativa sin convertir columnas DATETIME
     * opcionales a cadenas vacías. El ActiveRecord genérico entrecomilla todos
     * los valores y no puede representar NULL de forma segura.
     *
     * @return array{resultado: bool, id: int}
     */
    public function crearAdministrativa(): array
    {
        $stmt = self::getDB()->prepare(
            "INSERT INTO reservaciones
                (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
                 comentario_admin, origen, estado, request_token)
             VALUES
                (?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''),
                 'admin', 'confirmada', NULLIF(?, ''))"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la reservación administrativa.');
        }

        $nombre = (string)$this->nombre;
        $contactoTipo = (string)$this->contacto_tipo;
        $contacto = (string)($this->contacto ?? '');
        $fecha = (string)$this->fecha;
        $hora = (string)$this->hora;
        $comensales = (int)$this->comensales;
        $nota = (string)($this->nota ?? '');
        $comentarioAdmin = (string)($this->comentario_admin ?? '');
        $requestToken = (string)$this->request_token;

        $stmt->bind_param(
            'sssssisss',
            $nombre,
            $contactoTipo,
            $contacto,
            $fecha,
            $hora,
            $comensales,
            $nota,
            $comentarioAdmin,
            $requestToken,
        );
        $resultado = $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return ['resultado' => $resultado, 'id' => $id];
    }

    /**
     * Reservaciones activas posteriores a la fecha y hora local indicadas.
     * La relación con dia_semana se resuelve en el servicio, no en SQL.
     */
    public static function buscarFuturasActivas(string $fechaActual, string $horaActual): array
    {
        // Reutiliza la misma definición de ocupación que los mapas y validadores.
        $condicionOcupacion = ReservacionConfig::condicionSqlOcupacionActiva(static::$tabla);
        $stmt = self::getDB()->prepare(
            "SELECT id, nombre, fecha, hora, estado
             FROM " . static::$tabla . "
             WHERE {$condicionOcupacion}
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
        // Mantiene sincronizado el tratamiento de estados finales y holds vencidos.
        $condicionOcupacion = ReservacionConfig::condicionSqlOcupacionActiva(static::$tabla);
        $stmt = self::getDB()->prepare(
            "SELECT id, nombre, fecha, hora, estado
             FROM " . static::$tabla . "
             WHERE fecha = ?
               AND {$condicionOcupacion}
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
        $ahora = self::fechaHoraConsulta($fechaActual, $horaActual);
        $condicionVisible = ReservacionVigenciaService::condicionSqlVisibleCliente(
            'reservaciones',
            $ahora
        );
        $sql = "SELECT id, nombre, fecha, hora, comensales, nota, estado, contacto_tipo
                FROM reservaciones
                WHERE contacto_tipo = ?
                  AND contacto = ?
                  AND {$condicionVisible}
                ORDER BY fecha ASC, hora ASC, id ASC
                LIMIT ?";
        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta por contacto.');
        }

        $stmt->bind_param(
            'ssi',
            $tipo,
            $contactoNormalizado,
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
        string $horaActual,
        int $excluirReservacionId = 0
    ): int {
        $ahora = self::fechaHoraConsulta($fechaActual, $horaActual);
        $condicionLimite = ReservacionVigenciaService::condicionSqlCuentaLimite(
            'reservaciones',
            $ahora
        );
        $sql = "SELECT COUNT(*) AS total
                FROM reservaciones
                WHERE contacto_tipo = ?
                  AND contacto = ?
                  AND {$condicionLimite}";
        if ($excluirReservacionId > 0) {
            $sql .= ' AND id <> ?';
        }
        $stmt = self::getDB()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el conteo por contacto.');
        }

        if ($excluirReservacionId > 0) {
            $stmt->bind_param('ssi', $tipo, $contactoNormalizado, $excluirReservacionId);
        } else {
            $stmt->bind_param('ss', $tipo, $contactoNormalizado);
        }
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }

        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($fila['total'] ?? 0);
    }

    /**
     * Devuelve únicamente datos públicos de reemplazos aún pendientes para
     * que la interfaz pueda avisar que la reservación original sigue vigente.
     * No expone IDs internos ni request_token.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buscarReemplazosPendientesPorContacto(
        string $tipo,
        string $contactoNormalizado,
        string $fechaActual,
        string $horaActual
    ): array {
        $ahora = self::fechaHoraConsulta($fechaActual, $horaActual);
        $stmt = self::getDB()->prepare(
            "SELECT reemplaza_reservacion_id, fecha, hora, comensales, nota, hold_expires_at
             FROM reservaciones
             WHERE contacto_tipo = ?
               AND contacto = ?
               AND estado = 'pendiente_verificacion'
               AND reemplaza_reservacion_id IS NOT NULL
               AND hold_expires_at > ?
             ORDER BY fecha ASC, hora ASC, id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de cambios pendientes.');
        }

        $instante = $ahora->format('Y-m-d H:i:s');
        $stmt->bind_param('sss', $tipo, $contactoNormalizado, $instante);
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

    public static function buscarAdmin(array $filtros = []) {
        $condiciones = self::condicionesAdmin($filtros, true);
        $having = self::havingAsignacionAdmin($filtros);
        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.contacto_tipo,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.comentario_admin,
                    r.origen,
                    r.estado,
                    r.hold_expires_at,
                    r.reemplaza_reservacion_id,
                    r.request_token,
                    r.estado_changed_at,
                    r.created_at,
                    r.updated_at,
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
                        r.contacto_tipo,
                        r.contacto,
                        r.fecha,
                        r.hora,
                        r.comensales,
                        r.nota,
                        r.estado,
                        r.hold_expires_at,
                        r.reemplaza_reservacion_id,
                        r.request_token,
                        r.estado_changed_at,
                        r.created_at,
                        r.updated_at";

        if ($having) {
            $query .= " HAVING {$having}";
        }

        $query .= " ORDER BY r.fecha ASC, r.hora ASC, r.id DESC";

        return self::consultarSQL($query);
    }

    public static function buscarPorDiaOperacionAdmin($fecha) {
        $fecha = self::escaparString($fecha);
        $query = "SELECT
                    r.id,
                    r.nombre,
                    r.contacto_tipo,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.comentario_admin,
                    r.origen,
                    r.estado,
                    r.hold_expires_at,
                    r.reemplaza_reservacion_id,
                    r.request_token,
                    r.estado_changed_at,
                    r.created_at,
                    r.updated_at,
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
                    r.contacto_tipo,
                    r.contacto,
                    r.fecha,
                    r.hora,
                    r.comensales,
                    r.nota,
                    r.comentario_admin,
                    r.estado,
                    r.hold_expires_at,
                    r.reemplaza_reservacion_id,
                    r.request_token,
                    r.estado_changed_at,
                    r.created_at,
                    r.updated_at
                  ORDER BY r.hora ASC,
                    FIELD(r.estado, " . self::estadosSql(ReservacionConfig::ORDEN_ESTADOS) . "),
                    r.id ASC";

        return self::consultarSQL($query);
    }

    private static function fechaHoraConsulta(string $fecha, string $hora): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(
                trim($fecha) . ' ' . trim($hora),
                ReservacionConfig::timezone()
            );
        } catch (\Throwable $e) {
            return ReservacionConfig::ahora();
        }
    }

    public static function metricasAdmin(array $filtros = []) {
        $condiciones = self::condicionesAdmin($filtros, false);
        $having = self::havingAsignacionAdmin($filtros);

        $subquery = "SELECT r.id, r.estado, r.origen, COUNT(rm.id) AS mesas_count
                     FROM reservaciones r
                     LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id";

        if (!empty($condiciones)) {
            $subquery .= " WHERE " . implode(' AND ', $condiciones);
        }

        $subquery .= " GROUP BY r.id, r.estado, r.origen";

        if ($having) {
            $subquery .= " HAVING {$having}";
        }

        $query = "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(estado = 'pendiente_verificacion'), 0) AS pendientes,
                    COALESCE(SUM(estado = 'confirmada'), 0) AS confirmadas,
                    COALESCE(SUM(estado = 'completada'), 0) AS completadas,
                    COALESCE(SUM(estado = 'cancelada'), 0) AS canceladas,
                    COALESCE(SUM(estado = 'no_show'), 0) AS no_show,
                    COALESCE(SUM(origen = 'admin' AND estado = 'confirmada' AND mesas_count = 0), 0) AS sin_mesa
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
        $origen = (string)($filtros['origen'] ?? '');

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(r.nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR r.contacto LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
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

        if (in_array($origen, ['landing', 'admin'], true)) {
            $origen = self::escaparString($origen);
            $condiciones[] = "r.origen = '{$origen}'";
        }

        return $condiciones;
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
        if ($this->contacto_tipo === 'ninguno') {
            $this->contacto = null;
        } else {
            try {
                $this->contacto = ContactoService::normalizar(
                    (string)$this->contacto_tipo,
                    (string)$this->contacto
                );
            } catch (\InvalidArgumentException $e) {
                static::setAlerta('error', $e->getMessage());
            }
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

        if (
            $this->id !== null
            && $this->reemplaza_reservacion_id !== null
            && (int)$this->reemplaza_reservacion_id === (int)$this->id
        ) {
            static::setAlerta('error', 'Una reservacion no puede reemplazarse a si misma');
        }

        return static::$alertas;
    }
}
