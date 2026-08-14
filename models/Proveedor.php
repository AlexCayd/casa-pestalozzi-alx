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

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, nombre ASC"
        );
    }

    /** Sólo los que siguen surtiendo: es lo que se ofrece al asignar o recibir. */
    public static function activos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " WHERE activo = 1 ORDER BY nombre ASC"
        );
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
        $nombre = self::escaparString($this->nombre);
        $sql = "SELECT id FROM " . static::$tabla . " WHERE nombre = '{$nombre}'";
        if ($this->id) {
            $sql .= " AND id <> " . (int) $this->id;
        }

        return count(self::consultarSQL($sql . " LIMIT 1")) === 0;
    }
}
