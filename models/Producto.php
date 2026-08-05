<?php
namespace Model;

/**
 * Catalogo unico de platillos y bebidas (tabla 'productos'): fuente unica.
 *
 * Absorbe a la antigua tabla 'menu': ambas guardaban lo mismo con llaves
 * distintas (enlazadas solo por el nombre) y el CRUD de la carta solo tocaba
 * 'menu', asi que un platillo borrado de la carta seguia vendiendose en el POS.
 * Ahora hay una sola fila por producto y una sola bandera: si esta activo se
 * vende en el POS y se publica en la carta. El esquema resultante esta en
 * database/ddl.sql; 'menu' se conserva ahi solo como compatibilidad de lectura.
 *
 * El borrado del admin es suave (activo = 0) para no romper el JOIN por nombre
 * que hacen ticket_items, Services\Sugerencias y n8n sobre tickets historicos.
 *
 * Lo editan dos pantallas del admin: /admin/menu (la carta) y /admin/recetas
 * (catalogo completo con receta).
 */
class Producto extends ActiveRecord {
    protected static $tabla = 'productos';
    protected static $columnasDB = [
        'id', 'nombre', 'descripcion', 'categoria_id', 'precio',
        'area_id', 'activo'
    ];

    public $id;
    public $nombre;
    public $descripcion;
    public $categoria_id;
    public $precio;
    public $area_id;
    public $activo = 1;

    // Campos extra del JOIN con categorias / areas_produccion (no en
    // $columnasDB). Sin declararlos, ActiveRecord::crearObjeto descarta esos
    // alias del SELECT y llegan vacios a la carta, al PDF y al POS.
    public $categoria_nombre;
    public $categoria_img;
    public $area_slug;

    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla . " ORDER BY activo DESC, categoria_id ASC, nombre ASC"
        );
    }

    /**
     * Carta publica agrupada por categoria: lo activo de categorias activas.
     * La consumen /menu (JSON) y el PDF publico.
     *
     * @return array<int, array{id: int, nombre: string, img: ?string, platillos: Producto[]}>
     */
    public static function cartaPublica(): array
    {
        return self::agruparPorCategoria('WHERE c.activo = 1 AND p.activo = 1');
    }

    /**
     * Carta para el PDF del admin: incluye los productos retirados, para que el
     * operador vea todo el catalogo que tiene dado de alta.
     */
    public static function cartaCompleta(): array
    {
        return self::agruparPorCategoria('');
    }

    /**
     * Catalogo del POS agrupado por categoria, con el area de produccion de
     * cada producto.
     */
    public static function catalogoPos(): array
    {
        return self::consultarSQL(
            "SELECT p.id, p.nombre, p.precio, p.categoria_id, p.area_id,
                    c.nombre AS categoria_nombre, a.slug AS area_slug
               FROM productos p
               JOIN categorias c       ON c.id = p.categoria_id
               JOIN areas_produccion a ON a.id = p.area_id
              WHERE p.activo = 1 AND c.activo = 1
              ORDER BY p.categoria_id ASC, p.nombre ASC"
        );
    }

    /**
     * Agrupa productos por categoria respetando el orden de 'categorias'.
     * Las categorias sin productos que cumplan el filtro no se devuelven, para
     * que el PDF no imprima secciones vacias.
     */
    private static function agruparPorCategoria(string $condiciones): array
    {
        $filas = self::consultarSQL(
            "SELECT p.*, c.nombre AS categoria_nombre, c.img AS categoria_img
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
             {$condiciones}
              ORDER BY c.id ASC, p.id ASC"
        );

        $grupos = [];
        foreach ($filas as $fila) {
            $catId = (int) $fila->categoria_id;

            if (!isset($grupos[$catId])) {
                $grupos[$catId] = [
                    'id'        => $catId,
                    'nombre'    => $fila->categoria_nombre,
                    'img'       => $fila->categoria_img,
                    'platillos' => [],
                ];
            }

            $grupos[$catId]['platillos'][] = $fila;
        }

        return array_values($grupos);
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

    /**
     * Borrado suave: retira el producto sin borrar la fila. Un DELETE real
     * dejaria huerfanos los ticket_items historicos, que resuelven el producto
     * por nombre (igual que Services\Sugerencias y el flujo de n8n).
     */
    public function retirar(): bool
    {
        $id = (int) $this->id;

        if ($id < 1) {
            return false;
        }

        if (!self::ejecutarSQL("UPDATE productos SET activo = 0 WHERE id = {$id} LIMIT 1")) {
            return false;
        }

        $this->activo = 0;

        return true;
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
        $areaId = (int) ($filtros['area'] ?? 0);

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
        // El area decide a que comanda se imprime el producto.
        if (!$this->area_id) {
            static::setAlerta('error', 'El área de producción es obligatoria');
        }
        // La descripcion es opcional: las bebidas se imprimen en la carta solo
        // con nombre y precio.
        if (trim((string) $this->nombre) && self::nombreRepetido((string) $this->nombre, (int) $this->id)) {
            static::setAlerta('error', 'Ya existe un producto con ese nombre');
        }

        return static::$alertas;
    }

    /**
     * El nombre es UNIQUE en la BD porque ticket_items, Services\Sugerencias y
     * n8n resuelven el producto por nombre. Se comprueba antes de guardar para
     * dar un mensaje util en lugar del error 1062 de MySQL.
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
