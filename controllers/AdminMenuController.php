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
use Model\HistorialPrecio;
use Model\Producto;
use MVC\Router;
use Services\Menu\CategoriaMenuService;
use Services\Menu\FotoPlatillo;
use Services\Inventory\HistorialPrecios;
use Services\Menu\MenuPdf;
use Services\Menu\Variantes;
use Services\Security\AdminCsrfService;

class AdminMenuController
{
    private const CATEGORIES_PATH = '/admin/menu/categorias';
    private const ITEMS_PATH = '/admin/menu';
    private const MENU_CSS = '/build/css/admin/menu.css';

    /**
     * Listas de pendientes que el listado sabe pintar (?pendiente=). Hoy sólo
     * la receta: es lo único incompleto que tiene consecuencia —sin ella la
     * venta no descuenta inventario y el costo sale en cero—. La descripción ya
     * la exige el formulario y la foto propia es opcional por diseño (la carta
     * usa la de la categoría).
     */
    private const PENDIENTES = ['receta'];

    /** Listado de platillos con pestañas de categoría. */
    public static function index(Router $router): void
    {
        $todasLasCategorias = CategoriasMenu::ordenadas();
        $categoriasMap = [];
        $categoriasFoto = [];

        // El mapa lleva TODAS las categorías aunque haya una carta elegida: es
        // sólo para rotular filas, y la fila de una carta nunca trae otra, pero
        // un platillo suelto no debe quedarse sin nombre de categoría si cambia
        // el criterio.
        foreach ($todasLasCategorias as $cat) {
            $categoriasMap[(int) $cat->id] = $cat->nombre;
            // La miniatura de un platillo sin foto propia es la de su
            // categoría, que es lo que la carta enseñará en su lugar.
            $categoriasFoto[(int) $cat->id] = FotoPlatillo::url($cat->img);
        }

        $porPagina = 10;
        $filtros = self::leerFiltrosItems($todasLasCategorias);
        $cartaActiva = (string) $filtros['carta'];

        // Las pestañas son las categorías de la carta elegida. Sin carta, las
        // dos piezas juntas, que es lo que se veía antes de existir el filtro.
        $categorias = $cartaActiva === ''
            ? $todasLasCategorias
            : array_values(array_filter(
                $todasLasCategorias,
                static fn($cat): bool => $cat->carta === $cartaActiva
            ));


        // Se vuelve aquí desde el editor de recetas (?volver=) por redirección:
        // su acuse viaja en la sesión y se enseña como el resto de avisos.
        $recetaGuardada = AdminRecetasController::consumirAvisoGuardado();
        if ($recetaGuardada !== null) {
            Producto::setAlerta('exito', $recetaGuardada);
        }

        $resumen = self::resumenEstado($filtros);

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
            'categoriasFoto' => $categoriasFoto,
            'cartas' => CategoriasMenu::CARTAS,
            'areasMap' => self::areasMap(),
            'variantesPorProducto' => self::conteoVariantes(),
            'filtros' => $filtros,
            'categoriaActiva' => (int) ($filtros['categoria'] ?: 0),
            'cartaActiva' => $cartaActiva,
            'pendienteActivo' => (string) $filtros['pendiente'],
            'resumen' => $resumen,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            // array_unique: $alertas es UN estático de ActiveRecord compartido
            // por todos los modelos, así que Producto y CategoriasMenu
            // devuelven el mismo arreglo y la mezcla pintaba cada aviso dos
            // veces («Platillo creado correctamente» ×2).
            'alertas' => array_map(
                static fn ($mensajes) => array_values(array_unique((array) $mensajes)),
                array_merge_recursive(Producto::getAlertas(), CategoriasMenu::getAlertas())
            ),
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

    /** PDF de la carta de comida (la pública es MenuController::pdf). */
    public static function pdf(Router $router): void
    {
        MenuPdf::stream(CategoriasMenu::CARTA_COMIDA);
    }

    /** PDF de la carta de maridaje (la pública es MenuController::pdfMaridaje). */
    public static function pdfMaridaje(Router $router): void
    {
        MenuPdf::stream(CategoriasMenu::CARTA_MARIDAJE);
    }

    public static function create(Router $router): void
    {
        $platillo = new Producto();
        $alertas = [];
        $foto = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::sincronizarPlatillo($platillo);
            $alertas = $platillo->validar();
            // La foto se procesa aunque la validación ya haya fallado: así la
            // recién subida sobrevive como pendiente y no hay que volver a
            // elegirla al corregir el formulario.
            $foto = FotoPlatillo::desdeFormulario($platillo, null, $_POST, $_FILES);
            $alertas = self::sumarErrores($alertas, $foto->errores());

            if (empty($alertas)) {
                $resultado = self::guardarPlatillo($platillo);

                if ($resultado && $resultado['resultado']) {
                    $foto->confirmar();
                    HistorialPrecios::registrarAlta(
                        HistorialPrecio::ENTIDAD_PRODUCTO,
                        (int) $resultado['id'],
                        $platillo->precio
                    );
                    Producto::setAlerta('exito', 'Platillo creado correctamente');
                    self::index($router);
                    return;
                }

                Producto::setAlerta('error', 'No se pudo guardar el platillo. Revisa que el nombre no esté repetido.');
                $alertas = Producto::getAlertas();
            }

            $foto->revertir($platillo);
        }

        self::renderForm('Nuevo platillo', 'Crear platillo', $platillo, $alertas, $foto);
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
        $foto = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // El precio y la foto vigentes hay que leerlos antes de sincronizar:
            // después el objeto ya trae lo nuevo y no queda con qué comparar.
            $precioAnterior = $platillo->precio;
            $imagenActual = $platillo->img;

            self::sincronizarPlatillo($platillo);
            $alertas = $platillo->validar();
            $foto = FotoPlatillo::desdeFormulario($platillo, $imagenActual, $_POST, $_FILES);
            $alertas = self::sumarErrores($alertas, $foto->errores());

            if (empty($alertas)) {
                if (self::guardarPlatillo($platillo)) {
                    // Hasta aquí la foto anterior sigue en disco: si el UPDATE
                    // hubiera fallado, el platillo seguiría nombrándola.
                    $foto->confirmar();
                    // registrar() no escribe si el precio no cambió, así que
                    // guardar sólo la descripción no ensucia el histórico.
                    HistorialPrecios::registrar(
                        HistorialPrecio::ENTIDAD_PRODUCTO,
                        (int) $platillo->id,
                        $precioAnterior,
                        $platillo->precio
                    );
                    Producto::setAlerta('exito', 'Platillo actualizado correctamente');
                    self::index($router);
                    return;
                }

                Producto::setAlerta('error', 'No se pudo actualizar el platillo');
                $alertas = Producto::getAlertas();
            }

            $foto->revertir($platillo);
        }

        self::renderForm('Editar platillo', 'Guardar cambios', $platillo, $alertas, $foto);
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

        // mysqli lanza excepción en vez de devolver false (PHP 8.1+): sin
        // capturarla, un DELETE fallido era un 500 en vez del aviso de abajo,
        // y aquí además es lo que decide si se borra el archivo de la foto.
        try {
            $eliminado = (bool) $platillo->eliminar();
        } catch (\Throwable $e) {
            error_log('AdminMenuController::delete - ' . $e->getMessage());
            $eliminado = false;
        }

        if ($eliminado) {
            if (!\Services\Security\ContextoNegocio::nuevo()) {
                ImagenUploader::eliminar($platillo->img);
                Producto::setAlerta('exito', 'Platillo eliminado correctamente');
            } else {
                Producto::setAlerta('exito', 'Platillo retirado correctamente');
            }
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
        $platillo = Producto::find($id);
        if (!$platillo || !$platillo->alternarVisibilidad()) {
            Producto::setAlerta('error', 'No fue posible actualizar la visibilidad del platillo');
            self::index($router);
            return;
        }
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
            'cartas' => CategoriasMenu::CARTAS,
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
        // sincronizar() copia lo que venga; normalizar aquí es lo que impide
        // que un POST a mano meta una categoría en una carta inexistente.
        $categoria->carta = CategoriasMenu::normalizarCarta($categoria->carta);

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
            $categoria->carta = CategoriasMenu::normalizarCarta($categoria->carta);
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
            'cartas' => CategoriasMenu::CARTAS,
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
        $img = $platillo->img;
        $platillo->sincronizar($_POST);
        // La foto no se escribe desde el formulario como texto: la decide
        // FotoPlatillo con lo que se sube. Sin esto, un campo `img` añadido a
        // mano al POST se copiaría tal cual a la columna.
        $platillo->img = $img;
        $platillo->activo = isset($_POST['activo']) ? 1 : 0;
    }

    /**
     * guardar() puede lanzar (mysqli en PHP 8.1+) y, con una foto recién
     * subida de por medio, un 500 la dejaría huérfana en disco. Aquí un fallo
     * es un `false` y el llamador revierte.
     */
    private static function guardarPlatillo(Producto $platillo)
    {
        try {
            return $platillo->guardar();
        } catch (\Throwable $e) {
            error_log('AdminMenuController::guardarPlatillo - ' . $e->getMessage());
            return false;
        }
    }

    private static function sumarErrores(array $alertas, array $errores): array
    {
        foreach ($errores as $error) {
            $alertas['error'][] = $error;
        }

        return $alertas;
    }

    private static function renderForm(
        string $titulo,
        string $accion,
        Producto $platillo,
        array $alertas,
        ?FotoPlatillo $foto = null
    ): void {
        self::render('menu/form', [
            'title' => $titulo,
            'topbarSection' => 'Menú / ' . $titulo,
            'platillo' => $platillo,
            'categorias' => CategoriasMenu::ordenadas(),
            'areas' => self::areas(),
            'alertas' => $alertas,
            'accion' => $accion,
            'foto' => $foto ? $foto->vista() : FotoPlatillo::vistaInicial($platillo->img),
        ]);
    }

    /** Áreas de producción, para las pestañas del formulario. */
    public static function areas(): array
    {
        $areas = [];
        $db = Producto::getDB();
        $where = \Services\Security\ContextoNegocio::nuevo()
            ? ' WHERE unidad_id = ' . \Services\Security\ContextoNegocio::unidad(\Services\Security\ContextoNegocio::slug())
            : '';
        $res = $db->query("SELECT id, nombre FROM areas_produccion{$where} ORDER BY id ASC");

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

    /**
     * GET /admin/menu/variantes?id= — editor de variantes del platillo.
     *
     * Página propia y no una sección del formulario del platillo: el editor
     * necesita ancho para la vista previa de receta y del POS, y guarda por
     * JSON (sin recargar), mientras que el formulario es un POST clásico.
     */
    public static function variantes(Router $router): void
    {
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $platillo = $id ? Producto::find($id) : null;
        if (!$platillo) {
            header('Location: ' . self::ITEMS_PATH, true, 302);
            exit;
        }

        try {
            $editor = Variantes::datosEditor((int) $platillo->id);
            $alertas = [];
        } catch (\Throwable $e) {
            error_log('AdminMenuController::variantes - ' . $e->getMessage());
            $editor = null;
            $alertas = ['error' => ['No fue posible cargar las variantes. Revisa que existan las tablas `producto_variantes` y `variante_ajustes` (database/ddl.sql).']];
        }

        self::render('menu/variantes', [
            'title' => 'Variantes · ' . $platillo->nombre,
            'topbarSection' => 'Menú',
            'platillo' => $platillo,
            'categoriasMap' => self::mapaCategorias(),
            'editorVariantes' => $editor,
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => $alertas,
            'styles' => [self::MENU_CSS, '/build/css/admin/variantes.css'],
            'scripts' => ['/build/js/admin/variantes.js'],
        ]);
    }

    /** POST /admin/api/menu/variantes — guarda todos los grupos de un platillo. */
    public static function guardarVariantes(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $datos = json_decode((string) file_get_contents('php://input'), true);
        $datos = is_array($datos) ? $datos : [];

        if (!AdminCsrfService::validar(isset($datos['admin_csrf']) ? (string) $datos['admin_csrf'] : null)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'errores' => ['La sesión del editor venció. Recarga la página; tus cambios siguen en pantalla hasta entonces.']], JSON_UNESCAPED_UNICODE);
            return;
        }

        $productoId = filter_var($datos['producto_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$productoId || !Producto::find($productoId)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'errores' => ['El platillo ya no existe.']], JSON_UNESCAPED_UNICODE);
            return;
        }

        $grupos = isset($datos['grupos']) && is_array($datos['grupos']) ? $datos['grupos'] : [];
        $resultado = Variantes::guardar((int) $productoId, $grupos);
        if (!$resultado['ok']) {
            http_response_code(422);
        }
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /** Opciones de variante por producto, para el contador del listado. */
    private static function conteoVariantes(): array
    {
        try {
            return Variantes::conteoPorProducto();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** [id => nombre] de las categorías, para la ficha del editor. */
    private static function mapaCategorias(): array
    {
        $map = [];
        foreach (CategoriasMenu::ordenadas() as $categoria) {
            $map[(int) $categoria->id] = (string) $categoria->nombre;
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

    /**
     * @param CategoriasMenu[] $categorias Todas las categorías, para comprobar
     *                                     que la elegida cae en la carta elegida.
     */
    private static function leerFiltrosItems(array $categorias): array
    {
        // Nombre, carta y categoría: el filtro de visibilidad se retiró del
        // formulario porque la columna "Visible" de la tabla ya lo resuelve.
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $categoryId = filter_var($_GET['categoria'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        // Un valor fuera de la lista cerrada se trata como «sin carta»: es lo
        // que pasa con un enlace viejo o escrito a mano, y mostrar las dos
        // piezas es mejor que una tabla vacía sin explicación.
        $carta = $_GET['carta'] ?? '';
        $carta = is_string($carta) && isset(CategoriasMenu::CARTAS[$carta]) ? $carta : '';

        // Una categoría de OTRA carta no se combina con ésta: la tabla saldría
        // vacía con ninguna pestaña marcada, porque la fila de pestañas ya sólo
        // trae las de la carta elegida. Se descarta la categoría y manda la carta.
        if ($categoryId && $carta !== '') {
            foreach ($categorias as $categoria) {
                if ((int) $categoria->id === $categoryId && $categoria->carta !== $carta) {
                    $categoryId = false;
                    break;
                }
            }
        }

        // Lista cerrada: un valor desconocido vuelve a «todos» en vez de a una
        // lista de pendientes vacía que celebraría algo que no se ha hecho.
        $pendiente = $_GET['pendiente'] ?? '';
        $pendiente = is_string($pendiente) && in_array($pendiente, self::PENDIENTES, true) ? $pendiente : '';

        return [
            'q' => $q,
            'carta' => $carta,
            'categoria' => $categoryId ?: '',
            'pendiente' => $pendiente,
        ];
    }

    /**
     * Cifras de las tarjetas-filtro de la cabecera del listado.
     *
     * Consulta propia y no un recuento de $platillos: el listado viene de diez
     * en diez y contar las filas traídas describiría la página, no el menú (la
     * trampa que ya se pagó en Tickets y en Recetas).
     *
     * El alcance es la carta y la categoría elegidas, y NO la búsqueda ni el
     * propio pendiente: las tarjetas viven junto a las pestañas y dicen cómo va
     * lo que esas pestañas acotan. Si siguieran al texto del buscador, la cifra
     * de avance bailaría con cada tecla.
     *
     * @return array{total:int, sinReceta:int, ocultos:int, sinRecetaMenu:int}
     */
    private static function resumenEstado(array $filtros): array
    {
        $alcance = [
            'carta' => $filtros['carta'] ?? '',
            'categoria' => $filtros['categoria'] ?? '',
        ];

        try {
            $total = Producto::totalAdmin($alcance);
            $sinReceta = Producto::totalAdmin($alcance + ['pendiente' => 'receta']);
            $ocultos = Producto::totalAdmin($alcance + ['visible' => '0']);

            // Sólo hace falta cuando el alcance está acotado y ya no le quedan
            // pendientes: es lo que permite decir «aquí ya está, pero quedan N
            // en el resto del menú» en vez de celebrar el menú entero.
            $acotado = (string) $alcance['carta'] !== '' || (string) $alcance['categoria'] !== '';
            $sinRecetaMenu = $acotado && $sinReceta === 0
                ? Producto::totalAdmin(['pendiente' => 'receta'])
                : $sinReceta;
        } catch (\Throwable $e) {
            error_log('AdminMenuController::resumenEstado - ' . $e->getMessage());
            $total = $sinReceta = $ocultos = $sinRecetaMenu = 0;
        }

        return [
            'total' => $total,
            'sinReceta' => $sinReceta,
            'ocultos' => $ocultos,
            'sinRecetaMenu' => $sinRecetaMenu,
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
