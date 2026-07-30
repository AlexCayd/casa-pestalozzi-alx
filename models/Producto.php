<?php
namespace Model;

/**
 * Platillo del menú — fuente única.
 *
 * Absorbió al modelo `Menu`: antes los mismos platillos vivían en dos tablas
 * (`menu` para la carta pública y el PDF, `productos` para inventario y ruteo
 * por área) enlazadas solo por el nombre. Ver la migración
 * database/migrations/2026_07_26_002_fusion_menu_productos.sql
 */
class Producto extends ActiveRecord {
    protected static $tabla = 'productos';
    protected static $columnasDB = ['id', 'nombre', 'descripcion', 'categoria_id', 'precio', 'tag', 'area_id', 'activo'];

    public $id;
    public $nombre;
    public $descripcion;
    public $categoria_id;
    public $precio;
    public $tag;
    public $area_id;
    public $activo = 1;

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, categoria_id ASC, nombre ASC"
        );
    }

    /** Listado paginado del admin, con los filtros de la barra superior. */
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
        // La clave coincide con el nombre del parámetro de la URL:
        // AdminController::filterUrl reconstruye el query string con estas claves.
        $categoriaId = (int) ($filtros['categoria'] ?? 0);
        $visible = (string) ($filtros['visible'] ?? '');
        $areaId = (int) ($filtros['area'] ?? 0);

        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "(nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\' OR descripcion LIKE '%{$qEscapado}%' ESCAPE '\\\\')";
        }

        if ($categoriaId > 0) {
            $condiciones[] = "categoria_id = {$categoriaId}";
        }

        if ($areaId > 0) {
            $condiciones[] = "area_id = {$areaId}";
        }

        if ($visible === '1' || $visible === '0') {
            $condiciones[] = "activo = {$visible}";
        }

        return $condiciones;
    }

    public function validar()
    {
        static::$alertas = [];

        if (!trim((string) $this->nombre)) {
            static::setAlerta('error', 'El nombre del platillo es obligatorio');
        }
        // La columna es NULL en el esquema (para no bloquear la migración),
        // pero la carta pública y el PDF necesitan texto: se exige aquí.
        if (!trim((string) $this->descripcion)) {
            static::setAlerta('error', 'La descripción es obligatoria');
        }
        if (!$this->categoria_id || (int) $this->categoria_id < 1) {
            static::setAlerta('error', 'La categoría es obligatoria');
        }
        if ($this->precio === '' || $this->precio === null || !is_numeric($this->precio) || (float) $this->precio < 0) {
            static::setAlerta('error', 'El precio debe ser un número válido');
        }
        if (!$this->area_id) {
            static::setAlerta('error', 'El área de producción es obligatoria');
        }

        return static::$alertas;
    }
}
