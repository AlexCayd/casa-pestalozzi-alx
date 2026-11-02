<?php
namespace Model;

use Services\Security\ContextoNegocio;

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
 * que hacen ticket_items, Services\Analytics\Sugerencias y n8n sobre tickets historicos.
 *
 * Lo editan dos pantallas del admin: /admin/menu (la carta) y /admin/recetas
 * (catalogo completo con receta).
 */
class Producto extends ActiveRecord {
    protected static $tabla = 'productos';
    protected static $columnasDB = [
        'id', 'nombre', 'descripcion', 'img', 'categoria_id', 'precio',
        'area_id', 'activo'
    ];

    public bool $confirmar_identidad = false;
    public ?string $identidad_huella = null;
    public array $restaurar_casa = [];
    public $orden = 0;
    public $id;
    public $nombre;
    public $descripcion;
    // Ruta relativa de ImagenUploader o null. Null quiere decir «la carta usa
    // la foto de la categoría»; la escribe Services\Menu\FotoPlatillo, nunca
    // sincronizar($_POST) (ver AdminMenuController::sincronizarPlatillo).
    public $img;
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

    /**
     * Alta y edición con sentencia preparada en lugar de las de ActiveRecord.
     *
     * ActiveRecord entrecomilla cada valor, así que un platillo sin foto se
     * guardaría con `img = ''` y no con NULL (y en PHP 8.1+ escape_string(null)
     * además avisa). Aquí NULL no es un vacío cualquiera: significa «usa la
     * foto de la categoría», y quien consulte `img IS NULL` tiene que poder
     * fiarse. Las columnas siguen saliendo de $columnasDB; sólo cambia cómo
     * viajan los valores. Mismo contrato de retorno que el padre.
     */
    public function crear()
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'crear');
        $atributos = $this->atributos();
        $columnas = implode(', ', array_keys($atributos));
        $marcas = implode(', ', array_fill(0, count($atributos), '?'));

        $ok = self::ejecutarPreparada(
            "INSERT INTO " . static::$tabla . " ({$columnas}) VALUES ({$marcas})",
            array_values($atributos)
        );

        return ['resultado' => $ok, 'id' => $ok ? (int) self::$db->insert_id : 0];
    }

    public function actualizar()
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'actualizar');
        $atributos = $this->atributos();
        $asignaciones = implode(', ', array_map(
            static fn(string $columna): string => "{$columna} = ?",
            array_keys($atributos)
        ));
        $valores = array_values($atributos);
        $valores[] = (int) $this->id;

        return self::ejecutarPreparada(
            "UPDATE " . static::$tabla . " SET {$asignaciones} WHERE id = ? LIMIT 1",
            $valores
        );
    }

    /**
     * execute($valores) liga todo como cadena —MySQL convierte precio, ids y
     * activo igual que con el SQL concatenado de antes— y un null de PHP llega
     * como NULL, que es justo lo que hacía falta.
     */
    private static function ejecutarPreparada(string $sql, array $valores): bool
    {
        $stmt = self::$db->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $ok = $stmt->execute($valores);
        $stmt->close();

        return $ok;
    }

    public static function todos(): array
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::todos();
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
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::cartaPublica();
        return self::agruparPorCategoria('WHERE c.activo = 1 AND p.activo = 1');
    }

    /**
     * Carta para el PDF del admin: incluye los productos retirados, para que el
     * operador vea todo el catalogo que tiene dado de alta.
     */
    public static function cartaCompleta(): array
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::cartaCompleta();
        return self::agruparPorCategoria('');
    }

    /**
     * Catalogo del POS agrupado por categoria, con el area de produccion de
     * cada producto.
     */
    public static function catalogoPos(): array
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::catalogoPos();
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
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::buscarAdmin($filtros, $limite, $offset);
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
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::totalAdmin($filtros);
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
     * por nombre (igual que Services\Analytics\Sugerencias y el flujo de n8n).
     */
    public function retirar(): bool
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'retirar');
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

    /**
     * Listado paginado de /admin/recetas.
     *
     * No reutiliza buscarAdmin() porque la pregunta no es la misma. En Menú se
     * busca un platillo por su nombre; en Recetas la pregunta que nadie más
     * puede responder es «¿qué platillos llevan cilantro?», y para eso hay que
     * mirar dentro de la receta. De ahí el JOIN con categorias y el EXISTS
     * sobre producto_componentes.
     *
     * El orden es el de todos(): lo activo primero, luego por categoría y
     * nombre. Paginar no cambia el criterio, sólo recorta la ventana.
     */
    public static function buscarRecetas(array $filtros = [], ?int $limite = null, int $offset = 0): array
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::buscarRecetas($filtros, $limite, $offset);
        $condiciones = self::condicionesRecetas($filtros);
        $query = "SELECT p.* FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id";

        if (!empty($condiciones)) {
            $query .= " WHERE " . implode(' AND ', $condiciones);
        }

        $query .= " ORDER BY p.activo DESC, p.categoria_id ASC, p.nombre ASC";

        if ($limite !== null) {
            $limite = max(1, (int) $limite);
            $offset = max(0, (int) $offset);
            $query .= " LIMIT {$limite} OFFSET {$offset}";
        }

        return self::consultarSQL($query);
    }

    /** Total de platillos que casan con el filtro; alimenta la paginación. */
    public static function totalRecetas(array $filtros = []): int
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::totalRecetas($filtros);
        $condiciones = self::condicionesRecetas($filtros);
        $query = "SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id";

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
     * Búsqueda por términos sueltos, no por cadena literal.
     *
     * El texto se parte en palabras y cada una tiene que aparecer en ALGÚN
     * sitio del platillo —su nombre, su categoría o el nombre de un
     * ingrediente o subreceta de su receta—, sin importar el orden. Es lo que
     * hace que «pollo ensalada» encuentre «Ensalada de pollo» y que «cilantro»
     * devuelva los cuatro platillos que lo consumen aunque ninguno lo lleve en
     * el nombre.
     *
     * Las palabras se cruzan con AND y los campos con OR: dos términos afinan
     * el resultado en vez de ampliarlo, que es lo que la gente espera al
     * seguir escribiendo.
     *
     * Mayúsculas y acentos los resuelve la colación de la columna (utf8mb4
     * *_ci, insensible a caja y a acentos), igual que el resto de los LIKE del
     * proyecto: «jamon» encuentra «Jamón» sin normalizar nada aquí.
     */
    private static function condicionesRecetas(array $filtros): array
    {
        $condiciones = [];
        $q = trim((string) ($filtros['q'] ?? ''));
        $estado = (string) ($filtros['estado'] ?? '');

        if ($q !== '') {
            // Tope de términos: cada uno añade un EXISTS al plan, y a partir de
            // cinco palabras el filtro ya no afina nada que el usuario pueda
            // leer. El recorte de longitud lo hace el controlador.
            $terminos = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);

            foreach ($terminos as $termino) {
                $t = self::escaparLike($termino);
                $condiciones[] = "(
                    p.nombre LIKE '%{$t}%' ESCAPE '\\\\'
                    OR c.nombre LIKE '%{$t}%' ESCAPE '\\\\'
                    OR EXISTS (
                        SELECT 1 FROM producto_componentes pc
                          LEFT JOIN ingredientes i ON i.id = pc.ref_id AND pc.tipo = 'ingrediente'
                          LEFT JOIN subrecetas   s ON s.id = pc.ref_id AND pc.tipo = 'subreceta'
                         WHERE pc.producto_id = p.id
                           AND (i.nombre LIKE '%{$t}%' ESCAPE '\\\\'
                                OR s.nombre LIKE '%{$t}%' ESCAPE '\\\\')
                    )
                )";
            }
        }

        // El estado de la receta es el filtro propio de este módulo: la tarjeta
        // «Sin receta» de arriba dice cuántos faltan y esto es lo que lleva
        // directo a ellos.
        if ($estado === 'sin') {
            $condiciones[] = "NOT EXISTS (SELECT 1 FROM producto_componentes pc WHERE pc.producto_id = p.id)";
        } elseif ($estado === 'con') {
            $condiciones[] = "EXISTS (SELECT 1 FROM producto_componentes pc WHERE pc.producto_id = p.id)";
        }

        return $condiciones;
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
        $carta = (string) ($filtros['carta'] ?? '');

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

        // La carta vive en la categoría, no en el producto: el platillo
        // pertenece a la pieza impresa que le toca a su sección. Subconsulta y
        // no JOIN para que el resto de la consulta —columnas sin calificar,
        // `SELECT *`— siga igual.
        if ($carta !== '' && isset(CategoriasMenu::CARTAS[$carta])) {
            $cartaEscapada = self::escaparString($carta);
            $condiciones[] = "categoria_id IN (SELECT id FROM categorias WHERE carta = '{$cartaEscapada}')";
        }

        if ($areaId > 0) {
            $condiciones[] = "area_id = {$areaId}";
        }

        if ($visible === '1' || $visible === '0') {
            $condiciones[] = "activo = {$visible}";
        }

        // Lista de pendientes de Menú (?pendiente=receta). Misma definición que
        // el «Falta receta» de Recetas —ninguna fila en producto_componentes—
        // para que los dos módulos den la misma cifra del mismo catálogo.
        if ((string) ($filtros['pendiente'] ?? '') === 'receta') {
            $condiciones[] = "NOT EXISTS (SELECT 1 FROM producto_componentes pc WHERE pc.producto_id = productos.id)";
        }

        return $condiciones;
    }

    public function validar()
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'validar');
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
     * El nombre es UNIQUE en la BD porque ticket_items, Services\Analytics\Sugerencias y
     * n8n resuelven el producto por nombre. Se comprueba antes de guardar para
     * dar un mensaje util en lugar del error 1062 de MySQL.
     */
    public static function all()
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::todos();
        return parent::all();
    }
    public static function paraReceta($id)
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::paraReceta($id);
        return self::find($id);
    }

    public static function where($columna,$valor) { if (ContextoNegocio::nuevo()) throw new \LogicException('Use búsquedas de oferta con alcance de negocio.'); return parent::where($columna,$valor); }
    public static function get($limite) { if (ContextoNegocio::nuevo()) throw new \LogicException('Use búsquedas de oferta con alcance de negocio.'); return parent::get($limite); }
    public static function paginar($por_pagina,$offset) { if (ContextoNegocio::nuevo()) throw new \LogicException('Use búsquedas de oferta con alcance de negocio.'); return parent::paginar($por_pagina,$offset); }

    public static function find($id)
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::find($id);
        return parent::find($id);
    }

    public function alternarVisibilidad(): bool
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'alternarVisibilidad');
        $id=(int)$this->id;
        return (bool)self::ejecutarSQL("UPDATE productos SET activo=1-activo WHERE id={$id} LIMIT 1");
    }

    public function eliminar()
    {
        if (ContextoNegocio::nuevo()) return ProductoMultinegocio::delegar($this, 'eliminar');
        return parent::eliminar();
    }
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


class ProductoMultinegocio extends Producto
{
    public static function delegar(Producto $origen,string $metodo,array $argumentos=[])
    {
        $nuevo=new self();
        foreach (get_object_vars($origen) as $prop=>$valor) if (property_exists($nuevo,$prop)) $nuevo->$prop=$valor;
        $ret=$nuevo->$metodo(...$argumentos);
        foreach (get_object_vars($nuevo) as $prop=>$valor) if (property_exists($origen,$prop)) $origen->$prop=$valor;
        return $ret;
    }
    protected static $tabla = 'ofertas_producto';
    protected static $columnasDB = ['id', 'nombre', 'descripcion', 'img', 'categoria_id', 'precio', 'area_id', 'activo', 'orden'];

    /** `id` permanece como alias de oferta_id para las interfaces existentes. */
    public bool $confirmar_identidad = false;
    public ?string $identidad_huella = null;
    public $id;
    public $oferta_id;
    public $producto_id;
    public $unidad_id;
    public $nombre;
    public $descripcion;
    public $img;
    public $categoria_id;
    public $precio;
    public $area_id;
    public $activo = 1;
    public $orden = 0;
    public $categoria_nombre;
    public $categoria_img;
    public $area_slug;

    /** Alta atómica de una identidad nueva y su oferta para el contexto actual. */
    public function crear()
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'editar', $ctx);
        if ($ctx === 'global') {
            return ['resultado' => false, 'id' => 0];
        }
        if ($this->validar()) return ['resultado' => false, 'id' => 0];
        $unidad = ContextoNegocio::unidad($ctx);
        $db = self::getDB();
        $resultado = ContextoNegocio::transaccion(function () use ($db, $unidad, $ctx) {

            ContextoNegocio::exigir('menu', 'editar', $ctx, true);
            self::validarReferenciasLocales($db, (int)$this->categoria_id, (int)$this->area_id, $unidad);
            $nombre = trim((string)$this->nombre);
            $descripcion = $this->descripcion;
            $img = $this->img;
            $stmt = $db->prepare('INSERT INTO productos (nombre, descripcion, img) VALUES (?, ?, ?)');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('sss', $nombre, $descripcion, $img);
            self::ejecutar($stmt);
            $productoId = (int)$db->insert_id;
            $stmt->close();
            $categoria = (int)$this->categoria_id;
            $area = (int)$this->area_id;
            $precio = \Services\Menu\ImporteOferta::normalizar($this->precio);
            $activo = (int)$this->activo;
            $orden = (int)$this->orden;
            $stmt = $db->prepare("INSERT INTO ofertas_producto (producto_id, unidad_id, unidad_tipo, categoria_id, precio, area_id, activo, orden) VALUES (?, ?, 'negocio', ?, ?, ?, ?, ?)");
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('iiisiii', $productoId, $unidad, $categoria, $precio, $area, $activo, $orden);
            self::ejecutar($stmt);
            $ofertaId = (int)$db->insert_id;
            $stmt->close();
            self::registrarPrecio($ofertaId, $unidad, null, $precio);
            return ['resultado' => true, 'id' => $ofertaId, 'producto_id' => $productoId];
        });
        $payload = self::transaccionResultado($resultado);
        if (empty($payload['resultado'])) return ['resultado' => false, 'id' => 0];
        $this->oferta_id = $this->id = (int)$payload['id'];
        $this->producto_id = (int)$payload['producto_id'];
        $this->unidad_id = $unidad;
        return ['resultado' => true, 'id' => $this->id];
    }

    /** Actualiza solo la oferta local; identidad compartida requiere facultad global. */
    public function actualizar()
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'editar', $ctx);
        if (\Services\Menu\ProductosCasa::publicada((int)$this->producto_id)) {
            $r=\Services\Menu\ProductosCasa::guardarLocal($ctx,(int)($this->oferta_id?:$this->id),['nombre'=>$this->nombre,'descripcion'=>$this->descripcion,'img'=>$this->img,'precio'=>$this->precio,'activo'=>$this->activo,'categoria_id'=>$this->categoria_id,'area_id'=>$this->area_id,'orden'=>$this->orden],$this->restaurar_casa);
            if(empty($r['ok']))self::setAlerta('error',$r['mensaje']??'No se pudo guardar la personalización.');
            return !empty($r['ok']) && !empty($r['confirmado']);
        }
        if ($this->validar()) return false;
        $unidad = ContextoNegocio::unidad($ctx);
        $oferta = (int)($this->oferta_id ?: $this->id);
        if ($oferta < 1 || $ctx === 'global') return false;
        $db = self::getDB();
        $resultado = ContextoNegocio::transaccion(function () use ($db, $unidad, $oferta, $ctx) {
            ContextoNegocio::exigir('menu', 'editar', $ctx, true);
            $lock = $db->prepare('SELECT p.id FROM productos p JOIN ofertas_producto o ON o.producto_id=p.id WHERE o.id=? AND o.unidad_id=? FOR UPDATE');
            $lock->execute([$oferta, $unidad]); $lock->get_result()->fetch_all(); $lock->close();
            $stmt = $db->prepare('SELECT o.producto_id, o.precio AS precio_anterior, p.nombre, p.descripcion, p.img, (SELECT COUNT(*) FROM ofertas_producto ox WHERE ox.producto_id=o.producto_id) AS ofertas FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id WHERE o.id=? AND o.unidad_id=? FOR UPDATE');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii', $oferta, $unidad);
            self::ejecutar($stmt);
            $actual = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$actual) return ['resultado' => false];
            self::validarReferenciasLocales($db, (int)$this->categoria_id, (int)$this->area_id, $unidad);
            $productoId = (int)$actual['producto_id'];
            $nombre = trim((string)$this->nombre);
            $descripcion = $this->descripcion;
            $img = $this->img;
            $cambioIdentidad = $nombre !== (string)$actual['nombre'] || $descripcion !== $actual['descripcion'] || $img !== $actual['img'];
            if ($cambioIdentidad && (int)$actual['ofertas'] > 1) {
                ContextoNegocio::facultad(\Services\Menu\CatalogoComercial::FACULTAD, 'global', 'menu', true);
                if (!$this->confirmar_identidad) throw new \RuntimeException('Confirma el cambio de identidad compartida.');
            }
            if ($cambioIdentidad && $this->identidad_huella !== null && !hash_equals(\Services\Menu\CatalogoComercial::huella($actual), $this->identidad_huella)) throw new \RuntimeException('La identidad cambió. Recarga el formulario.');
            if ($cambioIdentidad) {
                $stmt = $db->prepare('UPDATE productos SET nombre=?, descripcion=?, img=? WHERE id=? LIMIT 1');
                if (!$stmt) throw new \RuntimeException($db->error);
                $stmt->bind_param('sssi', $nombre, $descripcion, $img, $productoId);
                self::ejecutar($stmt);
                $stmt->close();
            }
            $categoria = (int)$this->categoria_id;
            $precio = \Services\Menu\ImporteOferta::normalizar($this->precio);
            $area = (int)$this->area_id;
            $activo = (int)$this->activo;
            $orden = (int)$this->orden;
            $stmt = $db->prepare('UPDATE ofertas_producto SET categoria_id=?, precio=?, area_id=?, activo=?, orden=? WHERE id=? AND unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('isiiiii', $categoria, $precio, $area, $activo, $orden, $oferta, $unidad);
            self::ejecutar($stmt);
            $stmt->close();
            if ((string)$actual['precio_anterior'] !== $precio) self::registrarPrecio($oferta, $unidad, $actual['precio_anterior'], $precio);
            return ['resultado' => true, 'id' => $oferta, 'producto_id' => $productoId];
        });
        $payload = self::transaccionResultado($resultado);
        if (empty($payload['resultado'])) return false;
        $this->id = $this->oferta_id = $oferta;
        $this->producto_id = (int)$payload['producto_id'];
        $this->unidad_id = $unidad;
        return true;
    }

    public static function paraReceta($id)
    {
        ContextoNegocio::exigir('productos','ver',ContextoNegocio::slug());
        $id=(int)$id; if ($id<1) return null;
        $rows=self::consultarSQL(self::baseSelect().' AND o.id='.$id.' LIMIT 1');
        return $rows[0]??null;
    }
public static function find($id)
    {
        ContextoNegocio::exigir('menu','ver',ContextoNegocio::slug());
        $id=(int)$id; if ($id<1) return null;
        $rows=self::consultarSQL(self::baseSelect().' AND o.id='.$id.' LIMIT 1');
        return $rows[0]??null;
    }
    public static function todos(): array
    {
        ContextoNegocio::exigir('menu','ver',ContextoNegocio::slug());
        return self::consultarSQL(self::baseSelect() . ' ORDER BY o.activo DESC, o.categoria_id, o.orden, o.id');
    }

    public static function cartaPublica(): array
    {
        return self::agrupar(' AND c.activo=1 AND o.activo=1');
    }

    public static function cartaCompleta(): array
    {
        ContextoNegocio::exigir('menu','ver',ContextoNegocio::slug());
        return self::agrupar('');
    }

    public static function catalogoPos(): array
    {
        ContextoNegocio::exigir('pdv','operar',ContextoNegocio::slug());
        return self::consultarSQL('SELECT o.id AS id, o.id AS oferta_id, o.producto_id, o.unidad_id, p.nombre, o.precio, o.categoria_id, o.area_id, o.activo, o.orden, c.nombre AS categoria_nombre, ap.slug AS area_slug FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN areas_produccion ap ON ap.id=o.area_id AND ap.unidad_id=o.unidad_id WHERE o.unidad_id=' . ContextoNegocio::unidad(ContextoNegocio::slug()) . ' AND o.activo=1 AND c.activo=1 ORDER BY c.orden, c.id, o.orden, o.id');
    }

    private static function baseSelect(): string
    {
        $unidad = ContextoNegocio::unidad(ContextoNegocio::slug());
        return 'SELECT o.id AS id, o.id AS oferta_id, o.producto_id, o.unidad_id, p.nombre, p.descripcion, p.img, o.categoria_id, o.precio, o.area_id, o.activo, o.orden, c.nombre AS categoria_nombre, c.img AS categoria_img, ap.slug AS area_slug FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id LEFT JOIN areas_produccion ap ON ap.id=o.area_id AND ap.unidad_id=o.unidad_id WHERE o.unidad_id=' . $unidad;
    }

    private static function agrupar(string $filtro): array
    {
        $unidad = ContextoNegocio::unidad(ContextoNegocio::slug());
        $res = self::getDB()->query('SELECT o.id AS id, o.id AS oferta_id, o.producto_id, o.unidad_id, p.nombre, p.descripcion, p.img, o.categoria_id, o.precio, o.area_id, o.activo, o.orden, c.nombre AS categoria_nombre, c.img AS categoria_img FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE o.unidad_id=' . $unidad . $filtro . ' ORDER BY c.orden, c.id, o.orden, o.id');
        $grupos = [];
        if (!$res) return [];
        while ($fila = $res->fetch_object()) {
            $cid = (int)$fila->categoria_id;
            if (!isset($grupos[$cid])) $grupos[$cid] = ['id'=>$cid, 'nombre'=>$fila->categoria_nombre, 'img'=>$fila->categoria_img, 'platillos'=>[]];
            $grupos[$cid]['platillos'][] = $fila;
        }
        $res->free();
        return array_values($grupos);
    }

    public static function buscarAdmin(array $filtros = [], ?int $limite = null, int $offset = 0): array
    {
        ContextoNegocio::exigir('menu','ver',ContextoNegocio::slug());
        $where = self::condicionesAdmin($filtros);
        $sql = self::baseSelect() . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY o.categoria_id, o.orden, p.nombre, o.id DESC';
        if ($limite !== null) $sql .= ' LIMIT ' . max(1, (int)$limite) . ' OFFSET ' . max(0, (int)$offset);
        return self::consultarSQL($sql);
    }

    public static function totalAdmin(array $filtros = []): int
    {
        ContextoNegocio::exigir('menu','ver',ContextoNegocio::slug());
        $where = self::condicionesAdmin($filtros);
        $sql = 'SELECT COUNT(*) FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE o.unidad_id=' . ContextoNegocio::unidad(ContextoNegocio::slug());
        if ($where) $sql .= ' AND ' . implode(' AND ', $where);
        $res = self::getDB()->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row(); $res->free();
        return (int)$row[0];
    }

    public static function buscarRecetas(array $filtros = [], ?int $limite = null, int $offset = 0): array
    {
        ContextoNegocio::exigir('productos','ver',ContextoNegocio::slug());
        $where = self::condicionesRecetas($filtros);
        $sql = self::baseSelect() . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY o.activo DESC, o.categoria_id, o.orden, p.nombre';
        if ($limite !== null) $sql .= ' LIMIT ' . max(1,(int)$limite) . ' OFFSET ' . max(0,(int)$offset);
        return self::consultarSQL($sql);
    }

    public static function totalRecetas(array $filtros = []): int
    {
        ContextoNegocio::exigir('productos','ver',ContextoNegocio::slug());
        $where = self::condicionesRecetas($filtros);
        $sql = 'SELECT COUNT(*) FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id LEFT JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE o.unidad_id=' . ContextoNegocio::unidad(ContextoNegocio::slug());
        if ($where) $sql .= ' AND ' . implode(' AND ', $where);
        $res = self::getDB()->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row(); $res->free();
        return (int)$row[0];
    }

    private static function condicionesAdmin(array $f): array
    {
        $w = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') $w[] = "p.nombre LIKE '%" . self::escaparLike($q) . "%' ESCAPE '\\\\'";
        $cat = (int)($f['categoria'] ?? $f['category_id'] ?? 0);
        if ($cat > 0) $w[] = 'o.categoria_id=' . $cat;
        $carta = (string)($f['carta'] ?? '');
        if ($carta !== '' && isset(CategoriasMenu::CARTAS[$carta])) $w[] = "c.carta='" . self::escaparString($carta) . "'";
        $area = (int)($f['area'] ?? 0);
        if ($area > 0) $w[] = 'o.area_id=' . $area;
        $visible = (string)($f['visible'] ?? '');
        if ($visible === '0' || $visible === '1') $w[] = 'o.activo=' . $visible;
        if (($f['pendiente'] ?? '') === 'receta') $w[] = self::sinRecetaSql();
        return $w;
    }

    private static function condicionesRecetas(array $f): array
    {
        $w = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $term) {
            $t = self::escaparLike($term);
            $w[] = "(p.nombre LIKE '%{$t}%' ESCAPE '\\\\' OR c.nombre LIKE '%{$t}%' ESCAPE '\\\\' OR EXISTS (SELECT 1 FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id LEFT JOIN ingredientes i ON i.id=rc.articulo_id LEFT JOIN subrecetas s ON s.id=rc.subreceta_id AND s.unidad_id=r.unidad_id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 AND (i.nombre LIKE '%{$t}%' ESCAPE '\\\\' OR s.nombre LIKE '%{$t}%' ESCAPE '\\\\')))";
        }
        $estado = (string)($f['estado'] ?? '');
        if ($estado === 'sin') $w[] = self::sinRecetaSql();
        elseif ($estado === 'con') $w[] = self::tieneRecetaSql();
        return $w;
    }

    private static function tieneRecetaSql(): string
    {
        return 'EXISTS (SELECT 1 FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1)';
    }

    private static function sinRecetaSql(): string { return 'NOT ' . self::tieneRecetaSql(); }

    public function alternarVisibilidad(): bool
    {
        $ctx=ContextoNegocio::slug(); $id=(int)($this->oferta_id?:$this->id); $unidad=ContextoNegocio::unidad($ctx); $db=self::getDB();
        if (\Services\Menu\ProductosCasa::publicada((int)$this->producto_id)) { $r=\Services\Menu\ProductosCasa::guardarLocal($ctx,$id,['activo'=>1-(int)$this->activo]);return !empty($r['ok'])&&!empty($r['confirmado']); }
        $resultado=ContextoNegocio::transaccion(function () use($ctx,$id,$unidad,$db): array {
            ContextoNegocio::exigir('menu','editar',$ctx,true);
            $stmt=$db->prepare('UPDATE ofertas_producto SET activo=1-activo WHERE id=? AND unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$id,$unidad); self::ejecutar($stmt); $ok=$stmt->affected_rows===1; $stmt->close();
            return ['actualizado'=>$ok];
        });
        $ok=!empty($resultado['ok']) && !empty($resultado['confirmado']) && !empty($resultado['actualizado']);
        if ($ok) $this->activo=1-(int)$this->activo;
        return $ok;
    }

    public function retirar(): bool
    {
        $ctx=ContextoNegocio::slug(); $id=(int)($this->oferta_id?:$this->id); $unidad=ContextoNegocio::unidad($ctx); $db=self::getDB();
        if (\Services\Menu\ProductosCasa::publicada((int)$this->producto_id)) { $r=\Services\Menu\ProductosCasa::guardarLocal($ctx,$id,['activo'=>0]);return !empty($r['ok'])&&!empty($r['confirmado']); }
        $resultado=ContextoNegocio::transaccion(function () use($ctx,$id,$unidad,$db): array {
            ContextoNegocio::exigir('menu','editar',$ctx,true);
            $stmt=$db->prepare('SELECT id FROM ofertas_producto WHERE id=? AND unidad_id=? LIMIT 1 FOR UPDATE');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$id,$unidad); self::ejecutar($stmt); $existe=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
            if (!$existe) return ['retirado'=>false];
            $stmt=$db->prepare('UPDATE ofertas_producto SET activo=0 WHERE id=? AND unidad_id=? LIMIT 1');
            if (!$stmt) throw new \RuntimeException($db->error);
            $stmt->bind_param('ii',$id,$unidad); self::ejecutar($stmt); $stmt->close();
            return ['retirado'=>true];
        });
        $ok=!empty($resultado['ok']) && !empty($resultado['confirmado']) && !empty($resultado['retirado']);
        if ($ok) $this->activo=0;
        return $ok;
    }

    /** El retiro es la eliminación segura de la oferta; ventas/recetas conservan sus referencias. */
    public function eliminar() { return $this->retirar(); }

    public function validar(): array
    {
        static::$alertas = [];
        if (!trim((string)$this->nombre)) static::setAlerta('error', 'El nombre del platillo es obligatorio');
        if (!trim((string)$this->descripcion)) static::setAlerta('error', 'La descripción es obligatoria');
        if ((int)$this->categoria_id < 1) static::setAlerta('error', 'La categoría es obligatoria');
        try { \Services\Menu\ImporteOferta::normalizar($this->precio); } catch (\InvalidArgumentException $e) { static::setAlerta('error', $e->getMessage()); }
                if ((int)$this->area_id < 1) static::setAlerta('error', 'El área de producción es obligatoria');
        if (mb_strlen((string)$this->nombre) > 120) static::setAlerta('error', 'El nombre admite hasta 120 caracteres');
        return static::$alertas;
    }

    private static function registrarPrecio(int $oferta, int $unidad, ?string $anterior, string $nuevo): void
    {
        if (!HistorialPrecio::registrar(['unidad_id'=>$unidad,'oferta_id'=>$oferta,'articulo_id'=>null,
            'precio_anterior'=>$anterior,'precio_nuevo'=>$nuevo,'motivo'=>$anterior===null?'alta':'edicion'], true)) {
            throw new \RuntimeException('No se pudo preparar el histórico de la oferta.');
        }
    }

    private static function validarReferenciasLocales(\mysqli $db, int $categoria, int $area, int $unidad): void
    {
        $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM categorias WHERE id=? AND unidad_id=?), (SELECT COUNT(*) FROM areas_produccion WHERE id=? AND unidad_id=?)');
        if (!$stmt) throw new \RuntimeException($db->error);
        $stmt->bind_param('iiii', $categoria, $unidad, $area, $unidad);
        self::ejecutar($stmt);
        $row = $stmt->get_result()->fetch_row(); $stmt->close();
        if (!$row || (int)$row[0] !== 1 || (int)$row[1] !== 1) throw new \RuntimeException('La categoría o el área no pertenecen al negocio activo.');
    }

    private static function transaccionResultado(array $resultado): array
    {
        if (empty($resultado['ok']) || empty($resultado['confirmado'])) return [];
        return $resultado;
    }

    private static function ejecutar(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) throw new \RuntimeException($stmt->error);
    }
}
