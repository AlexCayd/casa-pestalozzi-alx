<?php
/**
 * Módulo de Recetas del panel admin.
 *
 * Reemplaza al antiguo "Productos y recetas". Los datos del platillo (nombre,
 * descripción, precio, categoría, área) se editan en Gestión de menú, que
 * ahora escribe en la misma tabla `productos`; aquí solo se compone la receta:
 * qué ingredientes y subrecetas consume una unidad, que es lo que descuenta
 * inventario al vender.
 */

namespace Controllers;

use Model\Ingrediente;
use Model\Producto;
use Model\Subreceta;
use MVC\Router;

class AdminRecetasController
{
    private const PATH = '/admin/recetas';
    private const SUBPATH = '/admin/recetas/subrecetas';
    private const CSS = '/build/css/admin/recetas.css';

    // ── Listado de platillos con su receta ───────────────────
    public static function index(Router $router): void
    {
        $productos = [];
        try {
            $productos = Producto::todos();
        } catch (\Throwable $e) {
            Producto::setAlerta('error', 'No se pudo cargar el catálogo. ¿Ya corriste la migración de la BD?');
        }

        $conteos = self::conteoComponentes();

        self::render('recetas/index', [
            'title' => 'Recetas',
            'topbarSection' => 'Recetas',
            'productos' => $productos,
            'conteosReceta' => $conteos,
            'categoriasMap' => self::categoriasMap(),
            'totalProductos' => count($productos),
            'conReceta' => count(array_filter($conteos)),
            'alertas' => Producto::getAlertas(),
        ]);
    }

    /**
     * Composición de la receta de un platillo. El platillo se muestra en solo
     * lectura: sus datos se editan en Gestión de menú.
     */
    public static function receta(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $producto = Producto::find($id);

        if (!$producto) {
            Producto::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::guardarComponentes((int) $producto->id);
            Producto::setAlerta('exito', 'Receta actualizada correctamente');
            self::index($router);
            return;
        }

        $ingredientes = [];
        $subrecetas = [];
        try {
            $ingredientes = Ingrediente::todos();
            $subrecetas = Subreceta::todas();
        } catch (\Throwable $e) {
            // Sin inventario aún: la receta se muestra vacía.
        }

        self::render('recetas/form', [
            'title' => 'Receta de ' . $producto->nombre,
            'topbarSection' => 'Recetas / ' . $producto->nombre,
            'producto' => $producto,
            'categoriasMap' => self::categoriasMap(),
            'ingredientes' => $ingredientes,
            'subrecetas' => $subrecetas,
            'componentes' => self::componentesDe((int) $producto->id),
            'alertas' => $alertas,
            'accion' => 'Guardar receta',
        ]);
    }

    // ── Subrecetas ───────────────────────────────────────────
    public static function subrecetas(Router $router): void
    {
        $subrecetas = [];
        try {
            $subrecetas = Subreceta::todas();
        } catch (\Throwable $e) {
            Subreceta::setAlerta('error', 'No se pudieron cargar las subrecetas.');
        }

        self::render('recetas/subrecetas', [
            'title' => 'Subrecetas',
            'topbarSection' => 'Recetas / Subrecetas',
            'subrecetas' => $subrecetas,
            'conteosSub' => self::conteoSubrecetaIngredientes(),
            'alertas' => Subreceta::getAlertas(),
        ]);
    }

    public static function subrecetaCreate(Router $router): void
    {
        $subreceta = new Subreceta();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $subreceta->sincronizar($_POST);
            $subreceta->activo = isset($_POST['activo']) ? 1 : 0;
            $alertas = $subreceta->validar();

            if (empty($alertas)) {
                $resultado = $subreceta->guardar();
                if ($resultado && ($resultado['resultado'] ?? false)) {
                    $nuevoId = (int) ($resultado['id'] ?? 0);
                    if ($nuevoId) {
                        self::guardarSubrecetaIngredientes($nuevoId);
                    }
                    Subreceta::setAlerta('exito', 'Subreceta creada correctamente');
                    self::subrecetas($router);
                    return;
                }
                Subreceta::setAlerta('error', 'No se pudo guardar la subreceta');
                $alertas = Subreceta::getAlertas();
            }
        }

        self::renderSubrecetaForm($subreceta, $alertas, 'Crear subreceta', 'Nueva subreceta', []);
    }

    public static function subrecetaEdit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $subreceta = Subreceta::find($id);

        if (!$subreceta) {
            Subreceta::setAlerta('error', 'La subreceta no existe');
            self::subrecetas($router);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $subreceta->sincronizar($_POST);
            $subreceta->activo = isset($_POST['activo']) ? 1 : 0;
            $alertas = $subreceta->validar();

            if (empty($alertas)) {
                if ($subreceta->guardar() !== false) {
                    self::guardarSubrecetaIngredientes((int) $subreceta->id);
                    Subreceta::setAlerta('exito', 'Subreceta actualizada correctamente');
                    self::subrecetas($router);
                    return;
                }
                Subreceta::setAlerta('error', 'No se pudo actualizar la subreceta');
                $alertas = Subreceta::getAlertas();
            }
        }

        self::renderSubrecetaForm($subreceta, $alertas, 'Guardar cambios', 'Editar subreceta', self::ingredientesDeSubreceta((int) $subreceta->id));
    }

    public static function subrecetaDelete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::SUBPATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $subreceta = Subreceta::find($id);

        if ($subreceta && $subreceta->eliminar()) {
            Subreceta::setAlerta('exito', 'Subreceta eliminada correctamente');
        } else {
            Subreceta::setAlerta('error', 'No se pudo eliminar la subreceta');
        }

        self::subrecetas($router);
    }

    // ── Persistencia de recetas ──────────────────────────────
    private static function guardarComponentes(int $productoId): void
    {
        // Cada componente llega como "tipo:ref" (p. ej. "ingrediente:5") en comp[]
        // con su cantidad en comp_cant[]. Así no depende de JS para el tipo.
        $comps = $_POST['comp'] ?? [];
        $cants = $_POST['comp_cant'] ?? [];

        Producto::ejecutarSQL("DELETE FROM producto_componentes WHERE producto_id = {$productoId}");

        if (!is_array($comps)) {
            return;
        }

        foreach ($comps as $i => $valor) {
            $partes = explode(':', (string) $valor, 2);
            if (count($partes) !== 2) {
                continue;
            }
            $tipo = $partes[0] === 'subreceta' ? 'subreceta' : 'ingrediente';
            $ref = (int) $partes[1];
            $cant = (float) ($cants[$i] ?? 0);
            if ($ref <= 0 || $cant <= 0) {
                continue;
            }
            $cantSql = number_format($cant, 3, '.', '');
            Producto::ejecutarSQL(
                "INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
                 VALUES ({$productoId}, '{$tipo}', {$ref}, {$cantSql})"
            );
        }
    }

    private static function guardarSubrecetaIngredientes(int $subrecetaId): void
    {
        $refs  = $_POST['ing_ref'] ?? [];
        $cants = $_POST['ing_cant'] ?? [];

        Subreceta::ejecutarSQL("DELETE FROM subreceta_ingredientes WHERE subreceta_id = {$subrecetaId}");

        if (!is_array($refs)) {
            return;
        }

        foreach ($refs as $i => $ref) {
            $ref = (int) $ref;
            $cant = (float) ($cants[$i] ?? 0);
            if ($ref <= 0 || $cant <= 0) {
                continue;
            }
            $cantSql = number_format($cant, 3, '.', '');
            Subreceta::ejecutarSQL(
                "INSERT INTO subreceta_ingredientes (subreceta_id, ingrediente_id, cantidad)
                 VALUES ({$subrecetaId}, {$ref}, {$cantSql})"
            );
        }
    }

    // ── Lectura de recetas ───────────────────────────────────
    private static function componentesDe(int $productoId): array
    {
        $db = Producto::getDB();
        $rows = [];
        $res = $db->query(
            "SELECT pc.tipo, pc.ref_id, pc.cantidad,
                    CASE WHEN pc.tipo = 'ingrediente' THEN i.nombre ELSE s.nombre END AS nombre,
                    CASE WHEN pc.tipo = 'ingrediente' THEN i.unidad ELSE s.unidad END AS unidad
               FROM producto_componentes pc
               LEFT JOIN ingredientes i ON pc.tipo = 'ingrediente' AND i.id = pc.ref_id
               LEFT JOIN subrecetas   s ON pc.tipo = 'subreceta'   AND s.id = pc.ref_id
              WHERE pc.producto_id = {$productoId}
              ORDER BY pc.tipo DESC, nombre ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function ingredientesDeSubreceta(int $subrecetaId): array
    {
        $db = Subreceta::getDB();
        $rows = [];
        $res = $db->query(
            "SELECT si.ingrediente_id AS ref_id, si.cantidad, i.nombre, i.unidad
               FROM subreceta_ingredientes si
               JOIN ingredientes i ON i.id = si.ingrediente_id
              WHERE si.subreceta_id = {$subrecetaId}
              ORDER BY i.nombre ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function conteoComponentes(): array
    {
        $db = Producto::getDB();
        $map = [];
        $res = $db->query(
            "SELECT producto_id, COUNT(*) AS n FROM producto_componentes GROUP BY producto_id"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $map[(int) $row['producto_id']] = (int) $row['n'];
            }
        }
        return $map;
    }

    private static function conteoSubrecetaIngredientes(): array
    {
        $db = Subreceta::getDB();
        $map = [];
        $res = $db->query(
            "SELECT subreceta_id, COUNT(*) AS n FROM subreceta_ingredientes GROUP BY subreceta_id"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $map[(int) $row['subreceta_id']] = (int) $row['n'];
            }
        }
        return $map;
    }

    /** Mapa id => nombre para pintar la categoría en el listado. */
    private static function categoriasMap(): array
    {
        $map = [];
        $db = Producto::getDB();
        $res = $db->query("SELECT id, nombre FROM categorias ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $map[(int) $row['id']] = $row['nombre'];
            }
        }
        return $map;
    }

    // ── Render ───────────────────────────────────────────────
    private static function renderSubrecetaForm(Subreceta $subreceta, array $alertas, string $accion, string $titulo, array $ingredientesReceta): void
    {
        $ingredientes = [];
        try {
            $ingredientes = Ingrediente::todos();
        } catch (\Throwable $e) {
            // Sin inventario aún.
        }

        self::render('recetas/subreceta-form', [
            'title' => $titulo,
            'topbarSection' => 'Recetas / ' . $titulo,
            'subreceta' => $subreceta,
            'unidades' => Ingrediente::UNIDADES,
            'ingredientes' => $ingredientes,
            'ingredientesReceta' => $ingredientesReceta,
            'alertas' => $alertas,
            'accion' => $accion,
        ]);
    }

    private static function render(string $view, array $data = []): void
    {
        // La clave del módulo sigue siendo 'productos': el sidebar ya mapea ese
        // icono y renombrarla obligaría a añadir una entrada nueva sin ganancia.
        AdminController::render($view, array_merge([
            'activeModule' => 'productos',
            'styles' => [self::CSS],
            'scripts' => ['/build/js/admin/recipe-builder.js'],
        ], $data));
    }

    private static function validarId($id, Router $router): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            Producto::setAlerta('error', 'Identificador no válido');
            self::index($router);
            exit;
        }
        return $id;
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
