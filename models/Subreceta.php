<?php
namespace Model;

use Services\Security\ContextoNegocio;
use Services\Inventory\DecimalInventario;

class Subreceta extends ActiveRecord
{
    protected static $tabla = 'subrecetas';
    protected static $columnasDB = ['id', 'nombre', 'unidad', 'rendimiento', 'activo'];
    public $id;
    public $nombre;
    public $unidad = 'g';
    public $rendimiento = 1;
    public $activo = 1;
    public $unidad_id;
    public $version = 1;
    public $created_at;
    public $updated_at;

    public static function todas(?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return self::consultarSQL('SELECT * FROM subrecetas ORDER BY activo DESC, nombre ASC');
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $stmt = self::getDB()->prepare('SELECT * FROM subrecetas WHERE unidad_id = ? ORDER BY activo DESC, nombre ASC');
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(static fn(array $row): self => self::crearObjeto($row), $rows);
    }

    public static function all()
    {
        if (!ContextoNegocio::nuevo()) return parent::all();
        return self::todas();
    }

    public static function where($columna, $valor)
    {
        if (!ContextoNegocio::nuevo()) return parent::where($columna, $valor);
        throw new \LogicException('Use Subreceta::find() para consultar una subreceta por ID.');
    }

    public static function total($columna = '', $valor = '')
    {
        if (!ContextoNegocio::nuevo()) return parent::total($columna, $valor);
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $stmt = self::getDB()->prepare('SELECT COUNT(*) FROM subrecetas WHERE unidad_id=?');
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $total;
    }

    public static function paginar($porPagina, $offset)
    {
        if (!ContextoNegocio::nuevo()) return parent::paginar($porPagina, $offset);
        return array_slice(self::todas(), max(0, (int)$offset), max(1, (int)$porPagina));
    }

    public static function get($limite)
    {
        if (!ContextoNegocio::nuevo()) return parent::get($limite);
        return array_slice(self::todas(), 0, max(1, (int)$limite));
    }

    public static function find($id, ?string $ctx = null)
    {
        if (!ContextoNegocio::nuevo()) return parent::find((int)$id);
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $stmt = self::getDB()->prepare('SELECT * FROM subrecetas WHERE id = ? AND unidad_id = ? LIMIT 1');
        $id = (int)$id;
        $stmt->bind_param('ii', $id, $unidadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? self::crearObjeto($row) : null;
    }

    public function guardar()
    {
        if (!ContextoNegocio::nuevo()) return parent::guardar();
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $nombre = trim((string)$this->nombre);
        $unidad = (string)$this->unidad;
        $rendimientoEntrada = is_int($this->rendimiento) ? (string)$this->rendimiento : $this->rendimiento;
        if (!is_string($rendimientoEntrada)) throw new \InvalidArgumentException('El rendimiento debe recibirse como decimal exacto.');
        $rendimientoEscalado = DecimalInventario::leer($rendimientoEntrada);
        if ($rendimientoEscalado <= 0) throw new \InvalidArgumentException('El rendimiento debe ser mayor a cero.');
        $rendimiento = DecimalInventario::cantidad($rendimientoEscalado);
        $activo = (int)$this->activo;
        if ($this->id) {
            $id = (int)$this->id;
            $result = ContextoNegocio::transaccion(static function () use ($nombre, $unidad, $rendimiento, $activo, $id, $unidadId, $ctx): array {
                ContextoNegocio::exigir('productos', 'editar', $ctx, true);
                $check = self::getDB()->prepare('SELECT id FROM subrecetas WHERE id=? AND unidad_id=? LIMIT 1 FOR UPDATE');
                if (!$check) throw new \RuntimeException(self::getDB()->error);
                $check->bind_param('ii', $id, $unidadId);
                if (!$check->execute() || !$check->get_result()->fetch_assoc()) {
                    $check->close();
                    throw new \DomainException('La subreceta no pertenece al negocio activo.');
                }
                $check->close();
                $stmt = self::getDB()->prepare('UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=?, activo=? WHERE id=? AND unidad_id=?');
                $stmt->bind_param('sssiii', $nombre, $unidad, $rendimiento, $activo, $id, $unidadId);
                $ok = $stmt->execute() && $stmt->affected_rows >= 0;
                $stmt->close();
                if (!$ok) throw new \RuntimeException('No se pudo actualizar la subreceta.');
                return ['resultado' => true];
            });
            return !empty($result['ok']) || ($result['ok'] ?? null) === null;
        }
        $result = ContextoNegocio::transaccion(static function () use ($unidadId, $nombre, $unidad, $rendimiento, $activo, $ctx): array {
            ContextoNegocio::exigir('productos', 'editar', $ctx, true);
            $stmt = self::getDB()->prepare('INSERT INTO subrecetas (unidad_id,nombre,unidad,rendimiento,activo) VALUES (?,?,?,?,?)');
            $stmt->bind_param('isssi', $unidadId, $nombre, $unidad, $rendimiento, $activo);
            if (!$stmt->execute()) throw new \RuntimeException($stmt->error);
            $id = (int)self::getDB()->insert_id;
            $stmt->close();
            return ['resultado' => true, 'id' => $id];
        });
        $id = (int)($result['id'] ?? 0);
        if ($id) $this->id = $id;
        return ['resultado' => $id > 0 && (!empty($result['ok']) || ($result['ok'] ?? null) === null), 'id' => $id];
    }

    public function crear()
    {
        if (!ContextoNegocio::nuevo()) return parent::crear();
        return $this->guardar();
    }

    public function actualizar()
    {
        if (!ContextoNegocio::nuevo()) return parent::actualizar();
        return $this->guardar();
    }

    public function eliminar()
    {
        if (!ContextoNegocio::nuevo()) return parent::eliminar();
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $id = (int)$this->id;
        $result = ContextoNegocio::transaccion(static function () use ($id, $unidadId, $ctx): array {
            ContextoNegocio::exigir('productos', 'editar', $ctx, true);
            $stmt = self::getDB()->prepare('DELETE FROM subrecetas WHERE id=? AND unidad_id=?');
            $stmt->bind_param('ii', $id, $unidadId);
            $ok = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$ok) throw new \RuntimeException('No se pudo eliminar la subreceta.');
            return ['resultado' => true];
        });
        return !empty($result['ok']) || ($result['ok'] ?? null) === null;
    }

    public function validar()
    {
        static::$alertas = [];
        if (!ContextoNegocio::nuevo()) {
            if (!trim((string)$this->nombre)) static::setAlerta('error', 'El nombre de la subreceta es obligatorio');
            if (!in_array($this->unidad, Ingrediente::UNIDADES, true)) static::setAlerta('error', 'La unidad no es válida');
            if (!is_numeric($this->rendimiento) || (float)$this->rendimiento <= 0) static::setAlerta('error', 'El rendimiento debe ser mayor a cero');
            return static::$alertas;
        }
        if (!trim((string)$this->nombre)) static::setAlerta('error', 'El nombre de la subreceta es obligatorio');
        if (!in_array($this->unidad, Ingrediente::UNIDADES, true)) static::setAlerta('error', 'La unidad no es válida');
        try {
            $rendimiento = is_int($this->rendimiento) ? (string)$this->rendimiento : $this->rendimiento;
            if (!is_string($rendimiento) || DecimalInventario::leer($rendimiento) <= 0) static::setAlerta('error', 'El rendimiento debe ser mayor a cero');
        } catch (\Throwable) {
            static::setAlerta('error', 'El rendimiento debe ser un decimal positivo con hasta tres decimales');
        }
        return static::$alertas;
    }
}
