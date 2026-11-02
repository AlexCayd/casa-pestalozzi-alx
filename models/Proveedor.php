<?php
namespace Model;

/**
 * Proveedor que surte insumos.
 *
 * El precio no vive aquí: un proveedor surte varios ingredientes y cada uno a
 * su precio, así que eso es ingrediente_proveedores (IngredienteProveedor).
 */
class Proveedor extends ActiveRecord {
    protected static $tabla = 'proveedores';
    protected static $columnasDB = ['id', 'nombre', 'contacto', 'telefono', 'correo', 'notas', 'activo'];

    public $id;
    public $nombre;
    public $contacto;
    public $telefono;
    public $correo;
    public $notas;
    public $activo = 1;
    public $created_at;
    public $updated_at;
    public $unidad_id;

    public static function todos(): array
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
            $u=\Services\Security\ContextoNegocio::unidad('restaurante');
            $s=self::getDB()->prepare('SELECT * FROM proveedores WHERE unidad_id=? ORDER BY activo DESC,nombre ASC'); $s->execute([$u]);
            return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
        }
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, nombre ASC"
        );
    }

    /** Sólo los que siguen surtiendo: es lo que se ofrece al asignar o recibir. */
    public static function activos(): array
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
            $u=\Services\Security\ContextoNegocio::unidad('restaurante');
            $s=self::getDB()->prepare('SELECT * FROM proveedores WHERE unidad_id=? AND activo=1 ORDER BY nombre ASC'); $s->execute([$u]);
            return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
        }
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " WHERE activo = 1 ORDER BY nombre ASC"
        );
    }

    public static function find($id) {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::find($id);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
        $s=self::getDB()->prepare('SELECT * FROM proveedores WHERE id=? AND unidad_id=?');
        $s->execute([(int)$id,\Services\Security\ContextoNegocio::unidad('restaurante')]);
        $r=$s->get_result()->fetch_assoc(); return $r?static::crearObjeto($r):null;
    }

    public static function all() { return \Services\Security\ContextoNegocio::nuevo() ? self::todos() : parent::all(); }
    public static function total($columna='', $valor='') {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::total($columna,$valor);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
        $s=self::getDB()->prepare('SELECT COUNT(*) FROM proveedores WHERE unidad_id=?'); $s->execute([$u]); return (int)$s->get_result()->fetch_row()[0];
    }
    public static function paginar($porPagina,$offset) {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::paginar($porPagina,$offset);
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
        $s=self::getDB()->prepare('SELECT * FROM proveedores WHERE unidad_id=? ORDER BY id LIMIT ? OFFSET ?'); $s->execute([$u,(int)$porPagina,(int)$offset]);
        return array_map(static fn($r)=>static::crearObjeto($r),$s->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    public static function get($limite) {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::get($limite);
        return self::paginar((int)$limite,0)[0]??null;
    }
    public static function where($columna,$valor) {
        if(!\Services\Security\ContextoNegocio::nuevo()) return parent::where($columna,$valor);
        $permitidas=['id','nombre','activo']; if(!in_array($columna,$permitidas,true)) throw new \Services\Inventory\InventarioError('CONSULTA_INVALIDA','La búsqueda de proveedor no es válida.');
        \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante'); $s=self::getDB()->prepare("SELECT * FROM proveedores WHERE {$columna}=? AND unidad_id=? LIMIT 1");
        $s->execute([$valor,\Services\Security\ContextoNegocio::unidad('restaurante')]); $r=$s->get_result()->fetch_assoc(); return $r?static::crearObjeto($r):null;
    }

    public function guardar() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::guardar();
        return $this->persistirNuevo();
    }

    public function crear() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::crear();
        return $this->persistirNuevo();
    }

    public function actualizar() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::actualizar();
        return $this->persistirNuevo();
    }

    private function persistirNuevo() {
        \Services\Security\ContextoNegocio::exigir('inventario','editar','restaurante');
        $db=self::getDB(); $u=\Services\Security\ContextoNegocio::unidad('restaurante');
        if ($this->id) {
            $s=$db->prepare('UPDATE proveedores SET nombre=?,contacto=?,telefono=?,correo=?,notas=?,activo=? WHERE id=? AND unidad_id=?');
            $s->execute([$this->nombre,$this->contacto,$this->telefono,$this->correo,$this->notas,(int)$this->activo,(int)$this->id,$u]);
            if($s->affected_rows===0 && !self::find($this->id)) throw new \Services\Inventory\InventarioError('RECURSO_NO_ENCONTRADO','No se encontró el proveedor de este Restaurante.');
            return $s->affected_rows>=0;
        }
        $s=$db->prepare('INSERT INTO proveedores(unidad_id,nombre,contacto,telefono,correo,notas,activo) VALUES (?,?,?,?,?,?,?)');
        $s->execute([$u,$this->nombre,$this->contacto,$this->telefono,$this->correo,$this->notas,(int)$this->activo]);
        $this->id=(int)$db->insert_id; $this->unidad_id=$u; return ['resultado'=>true,'id'=>$this->id];
    }

    public function eliminar() {
        if (!\Services\Security\ContextoNegocio::nuevo()) return parent::eliminar();
        \Services\Security\ContextoNegocio::exigir('inventario','editar','restaurante');
        $s=self::getDB()->prepare('DELETE FROM proveedores WHERE id=? AND unidad_id=?');
        $s->execute([(int)$this->id,\Services\Security\ContextoNegocio::unidad('restaurante')]);
        return $s->affected_rows===1;
    }

    public function validar()
    {
        static::$alertas = [];

        $this->nombre = trim((string) $this->nombre);
        $this->correo = trim((string) $this->correo);

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre del proveedor es obligatorio');
        } elseif (mb_strlen($this->nombre) > 120) {
            static::setAlerta('error', 'El nombre del proveedor no puede pasar de 120 caracteres');
        } elseif (!$this->nombreDisponible()) {
            // El UNIQUE de la tabla lo impediría igual, pero el error de mysqli
            // no dice cuál de las columnas chocó.
            static::setAlerta('error', 'Ya existe un proveedor con ese nombre');
        }

        if ($this->correo !== '' && !filter_var($this->correo, FILTER_VALIDATE_EMAIL)) {
            static::setAlerta('error', 'El correo del proveedor no es válido');
        }

        return static::$alertas;
    }

    private function nombreDisponible(): bool
    {
        if (\Services\Security\ContextoNegocio::nuevo()) {
            \Services\Security\ContextoNegocio::exigir('inventario','ver','restaurante');
            $sql='SELECT id FROM proveedores WHERE unidad_id=? AND nombre=?';
            if($this->id) $sql.=' AND id<>?';
            $s=self::getDB()->prepare($sql.' LIMIT 1');
            $args=[\Services\Security\ContextoNegocio::unidad('restaurante'),$this->nombre]; if($this->id)$args[]=(int)$this->id;
            $s->execute($args); return !$s->get_result()->fetch_row();
        }
        $nombre = self::escaparString($this->nombre);
        $sql = "SELECT id FROM " . static::$tabla . " WHERE nombre = '{$nombre}'";
        if ($this->id) {
            $sql .= " AND id <> " . (int) $this->id;
        }

        return count(self::consultarSQL($sql . " LIMIT 1")) === 0;
    }
}
