<?php
namespace Model;

/**
 * Catalogo unico de platillos y bebidas (tabla 'productos'): fuente unica.
 *
 * Absorbe a la antigua tabla 'menu': ambas guardaban lo mismo con llaves
 * distintas (enlazadas solo por el nombre) y el CRUD de la carta solo tocaba
 * 'menu'. Ahora hay una sola fila por producto y una sola bandera: si esta
 * activo se publica en la carta.
 *
 * Lo edita /admin/menu.
 */
class Producto extends ActiveRecord {
    protected static $tabla = 'productos';
    protected static $columnasDB = [
        'id', 'nombre', 'descripcion', 'categoria_id', 'precio', 'activo'
    ];

    public $id;
    public $nombre;
    public $descripcion;
    public $categoria_id;
    public $precio;
    public $activo = 1;

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
        // Se acepta también 'category_id', la clave que usaba la pantalla de la
        // carta antes de la fusión, para no romper enlaces ya publicados.
        $categoriaId = (int) ($filtros['categoria'] ?? $filtros['category_id'] ?? 0);
        $visible = (string) ($filtros['visible'] ?? '');

        // Solo por nombre. Buscar también en la descripción devolvía platillos
        // que no contienen el término buscado en ningún sitio visible de la
        // tabla, y el resultado parecía un error.
        if ($q !== '') {
            $qEscapado = self::escaparLike($q);
            $condiciones[] = "nombre LIKE '%{$qEscapado}%' ESCAPE '\\\\'";
        }

        if ($categoriaId > 0) {
            $condiciones[] = "categoria_id = {$categoriaId}";
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
        // La descripcion es opcional: las bebidas se imprimen en la carta solo
        // con nombre y precio.
        if (trim((string) $this->nombre) && self::nombreRepetido((string) $this->nombre, (int) $this->id)) {
            static::setAlerta('error', 'Ya existe un producto con ese nombre');
        }

        return static::$alertas;
    }

    /**
     * El nombre es UNIQUE en la BD: dos platillos homonimos en la carta no se
     * distinguirian. Se comprueba antes de guardar para dar un mensaje util en
     * lugar del error 1062 de MySQL.
     */
    private static function nombreRepetido(string $nombre, int $idActual): bool
    {
        $nombreEscapado = self::escaparString(trim($nombre));
        $query = "SELECT id FROM productos WHERE nombre = '{$nombreEscapado}'";

        if ($idActual > 0) {
            $query .= " AND id <> {$idActual}";
        }

        $res = self::ejecutarSQL($query . ' LIMIT 1');

        return $res && $res->num_rows > 0;
    }
}
