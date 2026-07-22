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
use Services\CategoriaMenuService;

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
            'title' => 'Categorías del menú',
            'topbarSection' => 'Gestión de menú / Categorías',
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
        $filtros = self::leerFiltrosItems();
        $totalMenu = Menu::totalAdmin($filtros);
        $totalPaginas = max(1, (int) ceil($totalMenu / $porPagina));

        $paginaActual = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$paginaActual || $paginaActual > $totalPaginas) {
            $paginaActual = 1;
        }

        $offset = ($paginaActual - 1) * $porPagina;
        $platillos = Menu::buscarAdmin($filtros, $porPagina, $offset);

        $data = [
            'title' => 'Platillos',
            'topbarSection' => 'Gestión de menú / Platillos',
            'platillos' => $platillos,
            'categorias' => $categorias,
            'categoriasMap' => $categoriasMap,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'alertas' => Menu::getAlertas(),
            'paginaActual' => $paginaActual,
            'totalPaginas' => $totalPaginas,
            'totalMenu' => $totalMenu,
            'porPagina' => $porPagina,
            'partialUrl' => AdminController::filterUrl('/admin/menu/items', array_merge(
                $filtros,
                $paginaActual > 1 ? ['page' => $paginaActual] : []
            )),
        ];

        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('menu/items', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('menu/items', $data);
    }

    /**
     * Genera y envia al navegador un PDF con todo el menu registrado.
     * Incluye nombre, descripcion, precio y tag de cada platillo (tabla menu).
     */
    public static function itemsPdf(Router $router): void
    {
        $platillos = Menu::all();

        // Ruta absoluta (con / ) a las fuentes del proyecto para los @font-face.
        $projectRoot = realpath(__DIR__ . '/..');
        $fontsDir = str_replace('\\', '/', $projectRoot . '/public/build/fonts');

        // Renderiza la plantilla del PDF a un string (sin el layout admin).
        ob_start();
        $generado = date('d/m/Y H:i');
        include __DIR__ . '/../views/admin/menu/items-pdf.php';
        $html = ob_get_clean();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Montserrat');
        // Permite a Dompdf leer los .ttf locales referenciados en los @font-face.
        $options->setChroot([$projectRoot]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // 'Attachment' => false => se abre en el navegador; true => fuerza descarga.
        $dompdf->stream('menu-casa-pestalozzi.pdf', ['Attachment' => false]);
        exit;
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
                $alertas['error'][] = 'Debes cargar una imagen para la categoría';
            }

            if (empty($alertas)) {
                $resultado = CategoriaMenuService::crear($categoria);

                if ($resultado['ok'] ?? false) {
                    CategoriasMenu::setAlerta('exito', 'Categoría creada correctamente');
                    self::categories($router);
                    return;
                }

                CategoriasMenu::setAlerta('error', 'No se pudo guardar la categoría');
                $alertas = CategoriasMenu::getAlertas();
            } elseif (!empty($categoria->img)) {
                ImagenUploader::eliminar($categoria->img);
                $categoria->img = null;
            }
        }

        self::render('menu/category-form', [
            'title' => 'Nueva categoría',
            'topbarSection' => 'Gestión de menú / Nueva categoría',
            'categoria' => $categoria,
            'alertas' => $alertas,
            'accion' => 'Crear categoría',
        ]);
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
            'topbarSection' => 'Gestión de menú / Editar categoría',
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
            'accion' => 'Crear platillo',
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
            'accion' => 'Guardar cambios',
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

    private static function leerFiltrosItems(): array
    {
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $categoryId = filter_var($_GET['category_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        $visible = (string) ($_GET['visible'] ?? '');

        if ($visible !== '1' && $visible !== '0') {
            $visible = '';
        }

        return [
            'q' => $q,
            'category_id' => $categoryId ?: '',
            'visible' => $visible,
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
