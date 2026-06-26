<?php
/**
 * Controlador del modulo de gestion de menu dentro del shell admin.
 * Encapsula el CRUD legacy de categorias y platillos bajo /admin/menu.
 */

namespace Controllers;

use Classes\ImagenUploader;
use Model\CategoriasMenu;
use Model\Menu;
use MVC\Router;

class AdminMenuController
{
    private const CATEGORIES_PATH = '/admin/menu/categories';
    private const ITEMS_PATH = '/admin/menu/items';
    private const MENU_CSS = '/build/css/admin/menu.css';

    public static function index(Router $router): void
    {
        self::render('menu/index', [
            'title' => 'Gestión de menú',
            'topbarSection' => 'Gestión de menú',
            'totalCategorias' => count(CategoriasMenu::all()),
            'totalMenu' => (int) Menu::total(),
            'alertas' => array_merge_recursive(CategoriasMenu::getAlertas(), Menu::getAlertas()),
        ]);
    }

    public static function categories(Router $router): void
    {
        self::render('menu/categories', [
            'title' => 'Categorias del menu',
            'topbarSection' => 'Gestión de menú / Categorias',
            'categorias' => CategoriasMenu::all(),
            'alertas' => CategoriasMenu::getAlertas(),
        ]);
    }

    public static function items(Router $router): void
    {
        $categorias = CategoriasMenu::all();
        $categoriasMap = [];

        foreach ($categorias as $cat) {
            $categoriasMap[$cat->id] = $cat->nombre;
        }

        $porPagina = 10;
        $totalMenu = (int) Menu::total();
        $totalPaginas = max(1, (int) ceil($totalMenu / $porPagina));

        $paginaActual = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$paginaActual || $paginaActual > $totalPaginas) {
            $paginaActual = 1;
        }

        $offset = ($paginaActual - 1) * $porPagina;
        $platillos = Menu::paginar($porPagina, $offset);

        self::render('menu/items', [
            'title' => 'Platillos',
            'topbarSection' => 'Gestión de menú / Platillos',
            'platillos' => $platillos,
            'categoriasMap' => $categoriasMap,
            'alertas' => Menu::getAlertas(),
            'paginaActual' => $paginaActual,
            'totalPaginas' => $totalPaginas,
            'totalMenu' => $totalMenu,
            'porPagina' => $porPagina,
        ]);
    }

    public static function categoryCreate(Router $router): void
    {
        $categoria = new CategoriasMenu();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $categoria->sincronizar($_POST);
            $categoria->activo = isset($_POST['activo']) ? 1 : 0;

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
            } else {
                $alertas['error'][] = 'Debes cargar una imagen para la categoria';
            }

            if (empty($alertas)) {
                $resultado = $categoria->guardar();

                if ($resultado && $resultado['resultado']) {
                    CategoriasMenu::setAlerta('exito', 'Categoria creada correctamente');
                    self::categories($router);
                    return;
                }

                ImagenUploader::eliminar($categoria->img);
                CategoriasMenu::setAlerta('error', 'No se pudo guardar la categoria');
                $alertas = CategoriasMenu::getAlertas();
            }
        }

        self::render('menu/category-form', [
            'title' => 'Nueva categoria',
            'topbarSection' => 'Gestión de menú / Nueva categoria',
            'categoria' => $categoria,
            'alertas' => $alertas,
            'accion' => 'Crear',
        ]);
    }

    public static function categoryEdit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $categoria = CategoriasMenu::find($id);

        if (!$categoria) {
            CategoriasMenu::setAlerta('error', 'La categoria no existe');
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
                if ($categoria->guardar()) {
                    if ($categoria->img !== $imagenActual) {
                        ImagenUploader::eliminar($imagenActual);
                    }

                    CategoriasMenu::setAlerta('exito', 'Categoria actualizada correctamente');
                    self::categories($router);
                    return;
                }

                CategoriasMenu::setAlerta('error', 'No se pudo actualizar la categoria');
                $alertas = CategoriasMenu::getAlertas();
            }
        }

        self::render('menu/category-form', [
            'title' => 'Editar categoria',
            'topbarSection' => 'Gestión de menú / Editar categoria',
            'categoria' => $categoria,
            'alertas' => $alertas,
            'accion' => 'Actualizar',
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
            CategoriasMenu::setAlerta('error', 'La categoria no existe');
            self::categories($router);
            return;
        }

        $platillos = Menu::consultarSQL(
            'SELECT id FROM menu WHERE categoria_id = ' . (int) $id
        );

        if (!empty($platillos)) {
            CategoriasMenu::setAlerta('error', 'No se puede eliminar: la categoria tiene platillos asociados');
            self::categories($router);
            return;
        }

        if ($categoria->eliminar()) {
            ImagenUploader::eliminar($categoria->img);
            CategoriasMenu::setAlerta('exito', 'Categoria eliminada correctamente');
        } else {
            CategoriasMenu::setAlerta('error', 'No se pudo eliminar la categoria');
        }

        self::categories($router);
    }

    public static function itemCreate(Router $router): void
    {
        $platillo = new Menu();
        $categorias = CategoriasMenu::all();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $platillo->sincronizar($_POST);
            $platillo->activo = isset($_POST['activo']) ? 1 : 0;
            $platillo->tag = trim($_POST['tag'] ?? '') !== '' ? $_POST['tag'] : null;

            $alertas = $platillo->validar();

            if (empty($alertas)) {
                $resultado = $platillo->guardar();

                if ($resultado && $resultado['resultado']) {
                    Menu::setAlerta('exito', 'Platillo creado correctamente');
                    self::items($router);
                    return;
                }

                Menu::setAlerta('error', 'No se pudo guardar el platillo');
                $alertas = Menu::getAlertas();
            }
        }

        self::render('menu/item-form', [
            'title' => 'Nuevo platillo',
            'topbarSection' => 'Gestión de menú / Nuevo platillo',
            'platillo' => $platillo,
            'categorias' => $categorias,
            'alertas' => $alertas,
            'accion' => 'Crear',
        ]);
    }

    public static function itemEdit(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null, $router);
        $platillo = Menu::find($id);

        if (!$platillo) {
            Menu::setAlerta('error', 'El platillo no existe');
            self::items($router);
            return;
        }

        $categorias = CategoriasMenu::all();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $platillo->sincronizar($_POST);
            $platillo->activo = isset($_POST['activo']) ? 1 : 0;
            $platillo->tag = trim($_POST['tag'] ?? '') !== '' ? $_POST['tag'] : null;

            $alertas = $platillo->validar();

            if (empty($alertas)) {
                if ($platillo->guardar()) {
                    Menu::setAlerta('exito', 'Platillo actualizado correctamente');
                    self::items($router);
                    return;
                }

                Menu::setAlerta('error', 'No se pudo actualizar el platillo');
                $alertas = Menu::getAlertas();
            }
        }

        self::render('menu/item-form', [
            'title' => 'Editar platillo',
            'topbarSection' => 'Gestión de menú / Editar platillo',
            'platillo' => $platillo,
            'categorias' => $categorias,
            'alertas' => $alertas,
            'accion' => 'Actualizar',
        ]);
    }

    public static function itemDelete(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::ITEMS_PATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $platillo = Menu::find($id);

        if (!$platillo) {
            Menu::setAlerta('error', 'El platillo no existe');
            self::items($router);
            return;
        }

        if ($platillo->eliminar()) {
            Menu::setAlerta('exito', 'Platillo eliminado correctamente');
        } else {
            Menu::setAlerta('error', 'No se pudo eliminar el platillo');
        }

        self::items($router);
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'menu',
            'styles' => [self::MENU_CSS],
            'scripts' => [],
        ], $data));
    }

    private static function validarId($id, Router $router): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$id) {
            CategoriasMenu::setAlerta('error', 'Identificador no valido');
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
