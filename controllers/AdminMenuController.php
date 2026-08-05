<?php
/**
 * Gestión de menú: CRUD de platillos y de sus categorías.
 *
 * Tras la fusión con "Productos y recetas" este módulo escribe directamente en
 * `productos`, la fuente única de los platillos. El listado entra ya en los
 * platillos (antes había una página intermedia de "hub") y las categorías se
 * eligen en pestañas, con un tab "+" para crear una nueva sin salir.
 *
 * La composición de recetas vive aparte, en AdminRecetasController.
 */

namespace Controllers;

use Classes\ImagenUploader;
use Model\CategoriasMenu;
use Model\Producto;
use MVC\Router;
use Services\CategoriaMenuService;
use Services\MenuPdf;

class AdminMenuController
{
    private const CATEGORIES_PATH = '/admin/menu/categorias';
    private const ITEMS_PATH = '/admin/menu';
    private const MENU_CSS = '/build/css/admin/menu.css';

    /** Listado de platillos con pestañas de categoría. */
    public static function index(Router $router): void
    {
        $categorias = CategoriasMenu::ordenadas();
        $categoriasMap = [];

        foreach ($categorias as $cat) {
            $categoriasMap[(int) $cat->id] = $cat->nombre;
        }

        $porPagina = 10;
        $filtros = self::leerFiltrosItems();
        $totalMenu = Producto::totalAdmin($filtros);
        $totalPaginas = max(1, (int) ceil($totalMenu / $porPagina));

        $paginaActual = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$paginaActual || $paginaActual > $totalPaginas) {
            $paginaActual = 1;
        }

        $offset = ($paginaActual - 1) * $porPagina;
        $platillos = Producto::buscarAdmin($filtros, $porPagina, $offset);

        $data = [
            'title' => 'Menú',
            'topbarSection' => 'Menú',
            'platillos' => $platillos,
            'categorias' => $categorias,
            'categoriasMap' => $categoriasMap,
            'areasMap' => self::areasMap(),
            'filtros' => $filtros,
            'categoriaActiva' => (int) ($filtros['categoria'] ?: 0),
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'alertas' => array_merge_recursive(Producto::getAlertas(), CategoriasMenu::getAlertas()),
            'paginaActual' => $paginaActual,
            'totalPaginas' => $totalPaginas,
            'totalMenu' => $totalMenu,
            'porPagina' => $porPagina,
            'partialUrl' => AdminController::filterUrl('/admin/menu', array_merge(
                $filtros,
                $paginaActual > 1 ? ['page' => $paginaActual] : []
            )),
        ];

        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('menu/index', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('menu/index', $data);
    }

    /** PDF de la carta (versión admin; la pública es MenuController::pdf). */
    public static function pdf(Router $router): void
    {
        MenuPdf::stream();
    }

    public static function create(Router $router): void
    {
        $platillo = new Producto();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::sincronizarPlatillo($platillo);
            $alertas = $platillo->validar();

            if (empty($alertas)) {
                $resultado = $platillo->guardar();

                if ($resultado && $resultado['resultado']) {
                    Producto::setAlerta('exito', 'Platillo creado correctamente');
                    self::index($router);
                    return;
                }

                Producto::setAlerta('error', 'No se pudo guardar el platillo. Revisa que el nombre no esté repetido.');
                $alertas = Producto::getAlertas();
            }
        }

        self::renderForm('Nuevo platillo', 'Crear platillo', $platillo, $alertas);
    }

    public static function edit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $platillo = Producto::find($id);

        if (!$platillo) {
            Producto::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::sincronizarPlatillo($platillo);
            $alertas = $platillo->validar();

            if (empty($alertas)) {
                if ($platillo->guardar()) {
                    Producto::setAlerta('exito', 'Platillo actualizado correctamente');
                    self::index($router);
                    return;
                }

                Producto::setAlerta('error', 'No se pudo actualizar el platillo');
                $alertas = Producto::getAlertas();
            }
        }

        self::renderForm('Editar platillo', 'Guardar cambios', $platillo, $alertas);
    }

    public static function delete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::ITEMS_PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $platillo = Producto::find($id);

        if (!$platillo) {
            Producto::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        if ($platillo->eliminar()) {
            Producto::setAlerta('exito', 'Platillo eliminado correctamente');
        } else {
            Producto::setAlerta('error', 'No se pudo eliminar: el platillo tiene una receta o ventas asociadas.');
        }

        self::index($router);
    }

    /**
     * Invierte la visibilidad de un platillo.
     *
     * Endpoint propio en vez de reenviar la fila entera por campos ocultos:
     * sobre la tabla fusionada ese formulario tendría que arrastrar `area_id`
     * y `descripcion`, y sin el primero la validación rechazaba cada clic.
     */
    public static function toggle(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::ITEMS_PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        Producto::ejecutarSQL("UPDATE productos SET activo = 1 - activo WHERE id = {$id} LIMIT 1");
        Producto::setAlerta('exito', 'Visibilidad actualizada');

        self::index($router);
    }

    // ── Categorías ─────────────────────────────────────────────────────────

    public static function categories(Router $router): void
    {
        self::render('menu/categories', [
            'title' => 'Categorías del menú',
            'topbarSection' => 'Menú / Categorías',
            'categorias' => CategoriasMenu::ordenadas(),
            'alertas' => CategoriasMenu::getAlertas(),
        ]);
    }

    /**
     * Alta rápida desde el tab "+" del listado. La imagen dejó de ser
     * obligatoria: se puede subir después desde Categorías. La lista marca las
     * que aún no tienen, porque sin imagen la carta pública no muestra
     * previsualización.
     */
    public static function categoryCreate(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::CATEGORIES_PATH);
        }

        $categoria = new CategoriasMenu();
        $categoria->sincronizar($_POST);
        $categoria->activo = 1;

        $alertas = $categoria->validar();
        $imagen = $_FILES['imagen'] ?? null;

        if (ImagenUploader::seEnvioArchivo($imagen)) {
            $uploader = new ImagenUploader();
            $ruta = $uploader->procesar($imagen);

            if ($ruta) {
                $categoria->img = $ruta;
            } else {
                foreach ($uploader->getErrores() as $error) {
                    $alertas['error'][] = $error;
                }
            }
        }

        if (!empty($alertas)) {
            foreach ($alertas['error'] ?? [] as $error) {
                CategoriasMenu::setAlerta('error', $error);
            }
            self::index($router);
            return;
        }

        $resultado = CategoriaMenuService::crear($categoria);

        if ($resultado['ok'] ?? false) {
            CategoriasMenu::setAlerta('exito', 'Categoría creada correctamente');
        } else {
            CategoriasMenu::setAlerta('error', 'No se pudo guardar la categoría');
        }

        self::index($router);
    }

    public static function categoryEdit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $categoria = CategoriasMenu::find($id);

        if (!$categoria) {
            CategoriasMenu::setAlerta('error', 'La categoría no existe');
            self::categories($router);
            return;
        }

        $alertas = [];
        $imagenActual = $categoria->img;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $categoria->sincronizar($_POST);
            $categoria->activo = isset($_POST['activo']) ? 1 : 0;
            $categoria->img = $imagenActual;

            $alertas = $categoria->validar();
            $imagen = $_FILES['imagen'] ?? null;

            if (ImagenUploader::seEnvioArchivo($imagen)) {
                $uploader = new ImagenUploader();
                $ruta = $uploader->procesar($imagen);

                if ($ruta) {
                    $categoria->img = $ruta;
                } else {
                    foreach ($uploader->getErrores() as $error) {
                        $alertas['error'][] = $error;
                    }
                }
            }

            if (empty($alertas)) {
                $resultado = CategoriaMenuService::actualizar($categoria);
                if ($resultado['ok'] ?? false) {
                    CategoriasMenu::setAlerta('exito', 'Categoría actualizada correctamente');
                    self::categories($router);
                    return;
                }

                CategoriasMenu::setAlerta('error', 'No se pudo actualizar la categoría');
                $alertas = CategoriasMenu::getAlertas();
            } elseif ($categoria->img !== $imagenActual) {
                ImagenUploader::eliminar($categoria->img);
                $categoria->img = $imagenActual;
            }
        }

        self::render('menu/category-form', [
            'title' => 'Editar categoría',
            'topbarSection' => 'Menú / Editar categoría',
            'categoria' => $categoria,
            'alertas' => $alertas,
            'accion' => 'Guardar cambios',
        ]);
    }

    public static function categoryDelete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::CATEGORIES_PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $categoria = CategoriasMenu::find($id);

        if (!$categoria) {
            CategoriasMenu::setAlerta('error', 'La categoría no existe');
            self::categories($router);
            return;
        }

        $resultado = CategoriaMenuService::eliminar($id);

        if (($resultado['codigo'] ?? '') === CategoriaMenuService::TIENE_PLATILLOS) {
            CategoriasMenu::setAlerta('error', 'No se puede eliminar: la categoría tiene platillos asociados');
            self::categories($router);
            return;
        }

        if ($resultado['ok'] ?? false) {
            CategoriasMenu::setAlerta('exito', 'Categoría eliminada correctamente');
        } else {
            CategoriasMenu::setAlerta('error', 'No se pudo eliminar la categoría');
        }

        self::categories($router);
    }

    // ── Internos ───────────────────────────────────────────────────────────

    private static function sincronizarPlatillo(Producto $platillo): void
    {
        $platillo->sincronizar($_POST);
        $platillo->activo = isset($_POST['activo']) ? 1 : 0;
    }

    private static function renderForm(string $titulo, string $accion, Producto $platillo, array $alertas): void
    {
        self::render('menu/form', [
            'title' => $titulo,
            'topbarSection' => 'Menú / ' . $titulo,
            'platillo' => $platillo,
            'categorias' => CategoriasMenu::ordenadas(),
            'areas' => self::areas(),
            'alertas' => $alertas,
            'accion' => $accion,
        ]);
    }

    /** Áreas de producción, para las pestañas del formulario. */
    public static function areas(): array
    {
        $areas = [];
        $db = Producto::getDB();
        $res = $db->query("SELECT id, nombre FROM areas_produccion ORDER BY id ASC");

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $areas[] = ['id' => (int) $row['id'], 'nombre' => $row['nombre']];
            }
            $res->free();
        }

        return $areas;
    }

    public static function areasMap(): array
    {
        $map = [];
        foreach (self::areas() as $area) {
            $map[$area['id']] = $area['nombre'];
        }

        return $map;
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'menu',
            'styles' => [self::MENU_CSS],
            'scripts' => [],
        ], $data));
    }

    private static function leerFiltrosItems(): array
    {
        // Solo nombre y categoría: el filtro de visibilidad se retiró del
        // formulario porque la columna "Visible" de la tabla ya lo resuelve.
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $categoryId = filter_var($_GET['categoria'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        return [
            'q' => $q,
            'categoria' => $categoryId ?: '',
        ];
    }

    private static function hayFiltrosActivos(array $filtros): bool
    {
        foreach ($filtros as $valor) {
            if ((string) $valor !== '') {
                return true;
            }
        }

        return false;
    }

    private static function validarId($id, Router $router): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$id) {
            CategoriasMenu::setAlerta('error', 'Identificador no válido');
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
