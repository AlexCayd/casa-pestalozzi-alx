<?php

namespace Model;

class ExcepcionOperacion extends RegistroRestaurante
{
    protected static $tabla = 'excepciones_operacion';
    protected static $columnasDB = [
        'id',
        'fecha',
        'tipo',
        'motivo',
        'hora_apertura',
        'hora_cierre',
        'activo',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    public $id = null;
    public $fecha = '';
    public $tipo = 'cerrado';
    public $motivo = null;
    public $hora_apertura = null;
    public $hora_cierre = null;
    public $activo = 1;
    public $updated_by = null;
    public $created_at = null;
    public $updated_at = null;

    public function __construct(array $args = [])
    {
        foreach ($args as $propiedad => $valor) {
            if (property_exists($this, $propiedad)) {
                $this->$propiedad = $valor;
            }
        }
    }

    public function validar(): array
    {
        static::$alertas = [];

        if ($this->fecha === '') {
            static::setAlerta('error', 'La fecha es obligatoria.');
        }
        if (!in_array($this->tipo, ['cerrado', 'horario_especial'], true)) {
            static::setAlerta('error', 'El tipo de excepción no es válido.');
        }
        if ($this->tipo === 'horario_especial') {
            if (!$this->hora_apertura) {
                static::setAlerta('error', 'La hora de apertura es obligatoria.');
            }
            if (!$this->hora_cierre) {
                static::setAlerta('error', 'La hora de cierre es obligatoria.');
            }
        }

        return static::$alertas;
    }

    public static function buscarPorId(int $id): ?self
    {
        return static::buscarUno('SELECT * FROM ' . static::$tabla . ' WHERE id = ?' . static::filtroUnidad() . ' LIMIT 1', 'i', [$id]);
    }

    public static function buscarPorIdParaActualizar(int $id): ?self
    {
        return static::buscarUno(
            'SELECT * FROM ' . static::$tabla . ' WHERE id = ?' . static::filtroUnidad() . ' LIMIT 1 FOR UPDATE',
            'i',
            [$id]
        );
    }

    public static function buscarActivaPorFecha(string $fecha): ?self
    {
        return static::buscarUno(
            'SELECT * FROM ' . static::$tabla . ' WHERE fecha = ? AND activo = 1' . static::filtroUnidad() . ' LIMIT 1',
            's',
            [$fecha]
        );
    }

    public static function listarOrdenadas(array $filtros = []): array
    {
        $condiciones = [];
        if (\Services\Security\ContextoNegocio::nuevo()) $condiciones[]='unidad_id='.static::unidadRestaurante();
        $tipos = '';
        $valores = [];

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $condiciones[] = 'activo = ?';
            $tipos .= 'i';
            $valores[] = (int) ((bool) $filtros['activo']);
        }
        if (in_array($filtros['tipo'] ?? '', ['cerrado', 'horario_especial'], true)) {
            $condiciones[] = 'tipo = ?';
            $tipos .= 's';
            $valores[] = $filtros['tipo'];
        }
        if (!empty($filtros['fecha_desde'])) {
            $condiciones[] = 'fecha >= ?';
            $tipos .= 's';
            $valores[] = $filtros['fecha_desde'];
        }
        if (!empty($filtros['fecha_hasta'])) {
            $condiciones[] = 'fecha <= ?';
            $tipos .= 's';
            $valores[] = $filtros['fecha_hasta'];
        }

        $query = 'SELECT * FROM ' . static::$tabla;
        if ($condiciones) {
            $query .= ' WHERE ' . implode(' AND ', $condiciones);
        }
        $query .= ' ORDER BY fecha ASC';

        $stmt = self::getDB()->prepare($query);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de excepciones.');
        }
        if ($tipos !== '') {
            $stmt->bind_param($tipos, ...$valores);
        }
        $stmt->execute();
        $resultado = $stmt->get_result();
        $excepciones = [];
        while ($fila = $resultado->fetch_assoc()) {
            $excepciones[] = static::crearObjeto($fila);
        }
        $stmt->close();

        return $excepciones;
    }

    public static function existeFecha(string $fecha, ?int $excluirId = null): bool
    {
        $query = 'SELECT id FROM ' . static::$tabla . ' WHERE fecha = ?' . static::filtroUnidad();
        $tipos = 's';
        $valores = [$fecha];

        if ($excluirId !== null) {
            $query .= ' AND id <> ?';
            $tipos .= 'i';
            $valores[] = $excluirId;
        }
        $query .= ' LIMIT 1';

        $stmt = self::getDB()->prepare($query);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible comprobar la fecha de la excepción.');
        }
        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $existe = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $existe;
    }

    public function guardarExcepcion(): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            $r=\Services\Security\ContextoNegocio::transaccion(function (): array {
                \Services\Security\ContextoNegocio::exigir('configuration','editar','restaurante',true);
                if ($this->validar()!==[]) throw new \DomainException('Excepción inválida.');
                $datos=[$this->fecha,$this->tipo,$this->motivo,$this->hora_apertura,$this->hora_cierre,(int)$this->activo,\Services\Security\ContextoNegocio::actorId()];
                if ($this->id===null) {
                    $stmt=static::getDB()->prepare('INSERT INTO excepciones_operacion(unidad_id,fecha,tipo,motivo,hora_apertura,hora_cierre,activo,updated_by) VALUES (?,?,?,?,?,?,?,?)');
                    $stmt->execute(array_merge([static::unidadRestaurante()],$datos));
                    $this->id=(int)static::getDB()->insert_id;
                } else {
                    if (!static::buscarPorIdParaActualizar((int)$this->id)) throw new \DomainException('Excepción fuera del ámbito.');
                    $stmt=static::getDB()->prepare('UPDATE excepciones_operacion SET fecha=?,tipo=?,motivo=?,hora_apertura=?,hora_cierre=?,activo=?,updated_by=? WHERE id=? AND unidad_id=?');
                    $stmt->execute(array_merge($datos,[(int)$this->id,static::unidadRestaurante()]));
                }
                $stmt->close(); return ['guardado'=>true];
            });
            return ($r['ok']??null)!==false && !empty($r['guardado']);
        }
        $fecha = $this->fecha;
        $tipo = $this->tipo;
        $motivo = $this->motivo;
        $horaApertura = $this->hora_apertura;
        $horaCierre = $this->hora_cierre;
        $activo = (int) $this->activo;
        $usuarioId = $this->updated_by;

        if ($this->id === null) {
            $stmt = self::getDB()->prepare(
                'INSERT INTO ' . static::$tabla . '
                    (fecha, tipo, motivo, hora_apertura, hora_cierre, activo, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar el guardado de la excepción.');
            }
            $stmt->bind_param('sssssii', $fecha, $tipo, $motivo, $horaApertura, $horaCierre, $activo, $usuarioId);
        } else {
            $id = (int) $this->id;
            $stmt = self::getDB()->prepare(
                'UPDATE ' . static::$tabla . '
                 SET fecha = ?, tipo = ?, motivo = ?, hora_apertura = ?, hora_cierre = ?, activo = ?, updated_by = ?
                 WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar la actualización de la excepción.');
            }
            $stmt->bind_param('sssssiii', $fecha, $tipo, $motivo, $horaApertura, $horaCierre, $activo, $usuarioId, $id);
        }

        $resultado = $stmt->execute();
        if ($this->id === null && $resultado) {
            $this->id = self::getDB()->insert_id;
        }
        $stmt->close();

        return $resultado;
    }

    public static function cambiarEstado(int $id, bool $activo, ?int $usuarioId): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            $r=\Services\Security\ContextoNegocio::transaccion(static function () use($id,$activo): array {
                \Services\Security\ContextoNegocio::exigir('configuration','editar','restaurante',true);
                if (!static::buscarPorIdParaActualizar($id)) throw new \DomainException('Excepción fuera del ámbito.');
                $stmt=static::getDB()->prepare('UPDATE excepciones_operacion SET activo=?,updated_by=? WHERE id=? AND unidad_id=?');
                $stmt->execute([(int)$activo,\Services\Security\ContextoNegocio::actorId(),$id,static::unidadRestaurante()]); $stmt->close();
                return ['guardado'=>true];
            });
            return ($r['ok']??null)!==false && !empty($r['guardado']);
        }
        $estado = $activo ? 1 : 0;
        $stmt = self::getDB()->prepare(
            'UPDATE ' . static::$tabla . ' SET activo = ?, updated_by = ? WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el cambio de estado.');
        }
        $stmt->bind_param('iii', $estado, $usuarioId, $id);
        $stmt->execute();
        $actualizadas = $stmt->affected_rows;
        $stmt->close();

        return $actualizadas >= 0;
    }

    public function eliminarExcepcion(): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            $r=\Services\Security\ContextoNegocio::transaccion(function (): array {
                \Services\Security\ContextoNegocio::exigir('configuration','editar','restaurante',true);
                if (!static::buscarPorIdParaActualizar((int)$this->id)) throw new \DomainException('Excepción fuera del ámbito.');
                if (!$this->eliminar()) throw new \RuntimeException('No se eliminó la excepción.');
                return ['guardado'=>true];
            });
            return ($r['ok']??null)!==false && !empty($r['guardado']);
        }
        $id = filter_var($this->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new \InvalidArgumentException('El identificador de la excepción no es válido.');
        }

        $stmt = self::getDB()->prepare('DELETE FROM ' . static::$tabla . ' WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la eliminación de la excepción.');
        }

        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible eliminar la excepción.');
        }
        $eliminadas = $stmt->affected_rows;
        $stmt->close();

        return $eliminadas === 1;
    }

    private static function buscarUno(string $query, string $tipos, array $valores): ?self
    {
        $stmt = self::getDB()->prepare($query);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta de excepción.');
        }
        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }
}
