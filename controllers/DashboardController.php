<?php

namespace Controllers;

use Model\CategoriasMenu;
use Model\Menu;
use Classes\ImagenUploader;
use MVC\Router;

class DashboardController
{
    // Panel principal: muestra categorías y platillos
    public static function index(Router $router)
    {
        // Categorías ordenadas de forma ascendente por id
        // $categorias = CategoriasMenu::consultarSQL(
        //     "SELECT * FROM categorias ORDER BY id ASC"
        // );
         $categorias = CategoriasMenu::all();
        // Mapa id => nombre de categoría para mostrar en la tabla del menú
        $categoriasMap = [];
        foreach ($categorias as $cat) {
            $categoriasMap[$cat->id] = $cat->nombre;
        }

        // --- Paginación de platillos: 10 por página ---
        $porPagina   = 10;
        $totalMenu   = (int) Menu::total();
        $totalPaginas = max(1, (int) ceil($totalMenu / $porPagina));

        $paginaActual = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$paginaActual || $paginaActual > $totalPaginas) {
            $paginaActual = 1;
        }

        $offset    = ($paginaActual - 1) * $porPagina;
        $platillos = Menu::paginar($porPagina, $offset);

        self::render('dashboard/index', [
            'titulo'        => 'Panel de Administración',
            'categorias'    => $categorias,
            'platillos'     => $platillos,
            'categoriasMap' => $categoriasMap,
            'alertas'       => CategoriasMenu::getAlertas(),
            'paginaActual'  => $paginaActual,
            'totalPaginas'  => $totalPaginas,
            'totalMenu'     => $totalMenu,
            'porPagina'     => $porPagina,
        ]);
    }


    //  CATEGORÍAS
    public static function categoriaCrear(Router $router)
    {
        $categoria = new CategoriasMenu();
        $alertas   = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $categoria->sincronizar($_POST);
            $categoria->activo = isset($_POST['activo']) ? 1 : 0;

            $alertas = $categoria->validar();

            // Procesar la imagen subida (obligatoria al crear)
            $imagen = $_FILES['imagen'] ?? null;
            if (ImagenUploader::seEnvioArchivo($imagen)) {
                $uploader = new ImagenUploader();
                $ruta     = $uploader->procesar($imagen);
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
                $resultado = $categoria->guardar();
                if ($resultado && $resultado['resultado']) {
                    CategoriasMenu::setAlerta('exito', 'Categoría creada correctamente');
                    self::index($router);
                    return;
                }
                // Limpiar la imagen recién subida si el INSERT falló
                ImagenUploader::eliminar($categoria->img);
                CategoriasMenu::setAlerta('error', 'No se pudo guardar la categoría');
                $alertas = CategoriasMenu::getAlertas();
            }
        }

        self::render('dashboard/categoria-form', [
            'titulo'    => 'Nueva Categoría',
            'categoria' => $categoria,
            'alertas'   => $alertas,
            'accion'    => 'Crear',
        ]);
    }

    public static function categoriaEditar(Router $router)
    {
        $id        = self::validarId($_GET['id'] ?? null, $router);
        $categoria = CategoriasMenu::find($id);

        if (!$categoria) {
            CategoriasMenu::setAlerta('error', 'La categoría no existe');
            self::index($router);
            return;
        }

        $alertas = [];

        // Conservar la imagen actual por si no se sube una nueva
        $imagenActual = $categoria->img;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $categoria->sincronizar($_POST);
            $categoria->activo = isset($_POST['activo']) ? 1 : 0;
            // sincronizar() no toca `img` (no viene en $_POST); la mantenemos
            $categoria->img = $imagenActual;

            $alertas = $categoria->validar();

            // Si se subió una imagen nueva, procesarla y reemplazar la anterior
            $imagen = $_FILES['imagen'] ?? null;
            if (ImagenUploader::seEnvioArchivo($imagen)) {
                $uploader = new ImagenUploader();
                $ruta     = $uploader->procesar($imagen);
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
                    // Borrar la imagen anterior solo si fue sustituida
                    if ($categoria->img !== $imagenActual) {
                        ImagenUploader::eliminar($imagenActual);
                    }
                    CategoriasMenu::setAlerta('exito', 'Categoría actualizada correctamente');
                    self::index($router);
                    return;
                }
                CategoriasMenu::setAlerta('error', 'No se pudo actualizar la categoría');
                $alertas = CategoriasMenu::getAlertas();
            }
        }

        self::render('dashboard/categoria-form', [
            'titulo'    => 'Editar Categoría',
            'categoria' => $categoria,
            'alertas'   => $alertas,
            'accion'    => 'Actualizar',
        ]);
    }

    public static function categoriaEliminar(Router $router)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirigir('/dashboard');
        }

        $id        = self::validarId($_POST['id'] ?? null, $router);
        $categoria = CategoriasMenu::find($id);

        if (!$categoria) {
            CategoriasMenu::setAlerta('error', 'La categoría no existe');
            self::index($router);
            return;
        }

        // Evitar borrar categorías con platillos asociados
        $platillos = Menu::consultarSQL(
            "SELECT id FROM menu WHERE categoria_id = " . (int) $id
        );

        if (!empty($platillos)) {
            CategoriasMenu::setAlerta('error', 'No se puede eliminar: la categoría tiene platillos asociados');
            self::index($router);
            return;
        }

        if ($categoria->eliminar()) {
            // Borrar también el archivo de imagen asociado del disco
            ImagenUploader::eliminar($categoria->img);
            CategoriasMenu::setAlerta('exito', 'Categoría eliminada correctamente');
        } else {
            CategoriasMenu::setAlerta('error', 'No se pudo eliminar la categoría');
        }

        self::index($router);
    }

    //  PLATILLOS (MENÚ)
    public static function menuCrear(Router $router)
    {
        $platillo   = new Menu();
        $categorias = CategoriasMenu::all();
        $alertas    = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $platillo->sincronizar($_POST);
            $platillo->activo = isset($_POST['activo']) ? 1 : 0;
            $platillo->tag    = trim($_POST['tag'] ?? '') !== '' ? $_POST['tag'] : null;

            $alertas = $platillo->validar();

            if (empty($alertas)) {
                $resultado = $platillo->guardar();
                if ($resultado && $resultado['resultado']) {
                    Menu::setAlerta('exito', 'Platillo creado correctamente');
                    self::index($router);
                    return;
                }
                Menu::setAlerta('error', 'No se pudo guardar el platillo');
                $alertas = Menu::getAlertas();
            }
        }

        self::render('dashboard/menu-form', [
            'titulo'     => 'Nuevo Platillo',
            'platillo'   => $platillo,
            'categorias' => $categorias,
            'alertas'    => $alertas,
            'accion'     => 'Crear',
        ]);
    }

    public static function menuEditar(Router $router)
    {
        $id       = self::validarId($_GET['id'] ?? null, $router);
        $platillo = Menu::find($id);

        if (!$platillo) {
            Menu::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        $categorias = CategoriasMenu::all();
        $alertas    = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $platillo->sincronizar($_POST);
            $platillo->activo = isset($_POST['activo']) ? 1 : 0;
            $platillo->tag    = trim($_POST['tag'] ?? '') !== '' ? $_POST['tag'] : null;

            $alertas = $platillo->validar();

            if (empty($alertas)) {
                if ($platillo->guardar()) {
                    Menu::setAlerta('exito', 'Platillo actualizado correctamente');
                    self::index($router);
                    return;
                }
                Menu::setAlerta('error', 'No se pudo actualizar el platillo');
                $alertas = Menu::getAlertas();
            }
        }

        self::render('dashboard/menu-form', [
            'titulo'     => 'Editar Platillo',
            'platillo'   => $platillo,
            'categorias' => $categorias,
            'alertas'    => $alertas,
            'accion'     => 'Actualizar',
        ]);
    }

    public static function menuEliminar(Router $router)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirigir('/dashboard');
        }

        $id       = self::validarId($_POST['id'] ?? null, $router);
        $platillo = Menu::find($id);

        if (!$platillo) {
            Menu::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        if ($platillo->eliminar()) {
            Menu::setAlerta('exito', 'Platillo eliminado correctamente');
        } else {
            Menu::setAlerta('error', 'No se pudo eliminar el platillo');
        }

        self::index($router);
    }

    //  Helpers internos
    // Renderiza una vista del dashboard envuelta en su propio layout
    private static function render($view, $datos = [])
    {
        foreach ($datos as $key => $value) {
            $$key = $value;
        }

        ob_start();
        include_once __DIR__ . "/../views/$view.php";
        $contenido = ob_get_clean();

        include_once __DIR__ . '/../views/dashboard/layout.php';
    }

    // Valida que el id sea un entero positivo; si no, muestra el panel con la alerta
    private static function validarId($id, Router $router)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            CategoriasMenu::setAlerta('error', 'Identificador no válido');
            self::index($router);
            exit;
        }
        return $id;
    }

    private static function redirigir($url)
    {
        header('Location: ' . $url);
        exit;
    }
}
