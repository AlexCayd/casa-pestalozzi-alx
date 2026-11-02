<?php
namespace Model;

use Services\Security\ContextoNegocio;

class GastoFijo extends ActiveRecord {
    protected static $tabla = 'gastos_fijos';
    protected static $columnasDB = ['id', 'nombre', 'categoria', 'monto', 'activo'];

    public const CATEGORIAS = ['renta', 'servicios', 'nomina', 'insumos', 'otros'];

    public $id;
    public $nombre;
    public $categoria = 'otros';
    public $monto = 0;
    public $activo = 1;
    public $created_at;
    public $updated_at;

    public static function todos(): array
    {
        if (ContextoNegocio::nuevo()) {
            $ctx = self::ambito();
            ContextoNegocio::exigir('finanzas', 'ver', $ctx);
            $db = ContextoNegocio::db();
            $s = $db->prepare('SELECT id,nombre,categoria,monto,activo,created_at,updated_at FROM gastos_fijos WHERE unidad_id=? ORDER BY activo DESC,monto DESC');
            $s->execute([ContextoNegocio::unidad($ctx)]);
            $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            return array_map(static fn(array $fila) => self::crearObjeto($fila), $filas);
        }
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, monto DESC"
        );
    }

    public static function all()
    {
        if (!ContextoNegocio::nuevo()) return parent::all();
        return self::todos();
    }

    public static function where($columna, $valor)
    {
        if (!ContextoNegocio::nuevo()) return parent::where($columna, $valor);
        $ctx = self::ambito();
        if ($columna !== 'id') throw new \InvalidArgumentException('Consulta no disponible para este modelo.');
        return self::find((int)$valor);
    }

    public static function total($columna = '', $valor = '')
    {
        if (!ContextoNegocio::nuevo()) return parent::total($columna, $valor);
        $ctx = self::ambito();
        ContextoNegocio::exigir('finanzas','ver',$ctx);
        if ($columna !== '' && !in_array($columna,['activo','categoria'],true)) throw new \InvalidArgumentException('Filtro no disponible.');
        $sql='SELECT COUNT(*) n FROM gastos_fijos WHERE unidad_id=?';$args=[ContextoNegocio::unidad($ctx)];
        if($columna!==''){$sql.=" AND {$columna}=?";$args[]=$valor;}$s=ContextoNegocio::db()->prepare($sql);$s->execute($args);return (int)($s->get_result()->fetch_assoc()['n']??0);
    }

    public static function paginar($por_pagina,$offset)
    {
        if (!ContextoNegocio::nuevo()) return parent::paginar($por_pagina,$offset);
        $ctx = self::ambito();
        ContextoNegocio::exigir('finanzas','ver',$ctx);$s=ContextoNegocio::db()->prepare('SELECT id,nombre,categoria,monto,activo,created_at,updated_at FROM gastos_fijos WHERE unidad_id=? ORDER BY id DESC LIMIT ? OFFSET ?');$s->execute([ContextoNegocio::unidad($ctx),max(1,(int)$por_pagina),max(0,(int)$offset)]);return array_map(static fn(array $r)=>self::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public static function find($id)
    {
        if (!ContextoNegocio::nuevo()) return parent::find($id);
        $ctx = self::ambito();
        ContextoNegocio::exigir('finanzas', 'ver', $ctx);
        $s = ContextoNegocio::db()->prepare('SELECT id,nombre,categoria,monto,activo,created_at,updated_at FROM gastos_fijos WHERE id=? AND unidad_id=? LIMIT 1');
        $s->execute([(int)$id, ContextoNegocio::unidad($ctx)]);
        $fila = $s->get_result()->fetch_assoc();
        return $fila ? self::crearObjeto($fila) : null;
    }

    public function guardar()
    {
        if (!ContextoNegocio::nuevo()) return parent::guardar();
        $ctx = self::ambito();
        ContextoNegocio::exigir('finanzas', 'editar', $ctx);
        $unidad = ContextoNegocio::unidad($ctx);
        $resultado = ContextoNegocio::transaccion(function () use ($unidad, $ctx): array {
            ContextoNegocio::exigir('finanzas', 'editar', $ctx, true);
            $db = ContextoNegocio::db();
            if ($this->id) {
                $buscar = $db->prepare('SELECT id FROM gastos_fijos WHERE id=? AND unidad_id=? FOR UPDATE');
                $buscar->execute([(int)$this->id,$unidad]);
                if (!$buscar->get_result()->fetch_row()) return ['ok'=>false,'codigo'=>'GASTO_NO_EXISTE'];
                $s = $db->prepare('UPDATE gastos_fijos SET nombre=?,categoria=?,monto=?,activo=? WHERE id=? AND unidad_id=?');
                $s->execute([(string)$this->nombre,(string)$this->categoria,(float)$this->monto,(int)$this->activo,(int)$this->id,$unidad]);
                if ($s->affected_rows < 0) return ['ok'=>false,'codigo'=>'GASTO_NO_GUARDADO'];
            } else {
                $s = $db->prepare('INSERT INTO gastos_fijos (unidad_id,nombre,categoria,monto,activo) VALUES (?,?,?,?,?)');
                $s->execute([$unidad,(string)$this->nombre,(string)$this->categoria,(float)$this->monto,(int)$this->activo]);
                $this->id = (int)$db->insert_id;
            }
            return ['id'=>(int)$this->id];
        });
        return ($resultado['ok'] ?? null) === true && ($resultado['confirmado'] ?? false);
    }

    public function crear()
    {
        if (!ContextoNegocio::nuevo()) return parent::crear();
        $this->id = null;
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
        $ctx = self::ambito();
        ContextoNegocio::exigir('finanzas', 'editar', $ctx);
        $r = ContextoNegocio::transaccion(function () use ($ctx): array {
            ContextoNegocio::exigir('finanzas', 'editar', $ctx, true);
            $s = ContextoNegocio::db()->prepare('DELETE FROM gastos_fijos WHERE id=? AND unidad_id=?');
            $s->execute([(int)$this->id, ContextoNegocio::unidad($ctx)]);
            if ($s->affected_rows !== 1) return ['ok'=>false,'codigo'=>'GASTO_NO_EXISTE'];
            return ['eliminado'=>true];
        });
        return ($r['ok'] ?? null) === true && ($r['confirmado'] ?? false);
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre del gasto es obligatorio');
        }
        if (!in_array($this->categoria, self::CATEGORIAS, true)) {
            static::setAlerta('error', 'La categoría no es válida');
        }
        if (!is_numeric($this->monto) || (float) $this->monto < 0) {
            static::setAlerta('error', 'El monto debe ser un número válido');
        }

        return static::$alertas;
    }

    /**
     * El negocio dueño de los gastos: el de la ruta, si vende. Global no
     * tiene gastos propios (lee el consolidado de los dos negocios).
     */
    private static function ambito(): string
    {
        $ctx = ContextoNegocio::slug();
        if (!in_array($ctx, ['restaurante', 'panaderia'], true)) {
            throw new \Services\Inventory\InventarioError('AMBITO_NO_DISPONIBLE', 'Los gastos fijos son de cada negocio: Restaurante o Panadería.');
        }
        return $ctx;
    }
}
