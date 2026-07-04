<?php
namespace Model;

class Menu extends ActiveRecord {
    protected static $tabla = 'menu';
    protected static $columnasDB = ['id', 'nombre', 'descripcion', 'precio', 'tag', 'activo', 'categoria_id'];

    public $id;
    public $nombre;
    public $descripcion;
    public $precio;
    public $tag;
    public $activo = 1;
    public $categoria_id;

    public static function buscarAdmin(array $filtros = [], ?int $limite = null, int $offset = 0): array
    {
        $condiciones = self::condicionesAdmin($filtros);
        $query = "SELECT * FROM " . static::$tabla;

        if (!empty($condiciones)) {
            $query .= " WHERE " . implode(' AND ', $condiciones);
        }

        $query .= " ORDER BY categoria_id ASC, nombre ASC, id DESC";

        if ($limite !== null) {
            $limite = max(1, (int) $limite);
            $offset = max(0, (int) $offset);
            $query .= " LIMIT {$limite} OFFSET {$offset}";
        }

        return self::consultarSQL($query);
    }

    public static function totalAdmin(array $filtros = []): int
    {
        $condiciones = self::condicionesAdmin($filtros);
        $query = "SELECT COUNT(*) FROM " . static::$tabla;

        if (!empty($condiciones)) {
            $query .= " WHERE " . implode(' AND ', $condiciones);
        }

        $resultado = self::$db->query($query);

        if (!$resultado) {
            return 0;
        }

        $total = $resultado->fetch_array();
        $resultado->free();

        return (int) array_shift($total);
    }

    private static function condicionesAdmin(array $filtros): array
    {
        $condiciones = [];
        $q = trim((string) ($filtros['q'] ?? ''));
        $categoriaId = (int) ($filtros['category_id'] ?? 0);
        $visible = (string) ($filtros['visible'] ?? '');

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR descripcion LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
        }

        if ($categoriaId > 0) {
            $condiciones[] = "categoria_id = {$categoriaId}";
        }

        if ($visible === '1' || $visible === '0') {
            $condiciones[] = "activo = {$visible}";
        }

        return $condiciones;
    }

    // Funcion para validar el menu
    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre del platillo es obligatorio');
        }
        if (!$this->descripcion) {
            static::setAlerta('error', 'La descripción es obligatoria');
        }
        if ($this->precio === '' || $this->precio === null) {
            static::setAlerta('error', 'El precio es obligatorio');
        } elseif (!is_numeric($this->precio) || (float)$this->precio < 0) {
            static::setAlerta('error', 'El precio debe ser un número válido');
        }
        if (!$this->categoria_id) {
            static::setAlerta('error', 'La categoría es obligatoria');
        }

        return static::$alertas;
    }
}
