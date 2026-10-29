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

use Model\CategoriasMenu;

use Model\Ingrediente;
use Model\Producto;
use Model\Subreceta;
use Services\Menu\RecetasOferta;
use Services\Security\ContextoNegocio;
use Services\Inventory\AlmacenesConsumo;
use Services\Inventory\DecimalInventario;
use Services\Security\AdminCsrfService;
use MVC\Router;

class AdminRecetasController
{
    private const PATH = '/admin/recetas';
    private const SUBPATH = '/admin/recetas/subrecetas';
    private const CSS = '/build/css/admin/recetas.css';
    // Diez por página, como el listado de Menú: son las mismas filas de la
    // misma tabla y alternar entre los dos módulos con dos ventanas distintas
    // sólo desorienta.
    private const POR_PAGINA = 10;

    // ── Listado de platillos con su receta ───────────────────
    public static function index(Router $router): void
    {
        ContextoNegocio::exigir('productos', 'ver', null, true);
        $filtros = self::leerFiltros();

        $productos = [];
        $conteos = [];
        $catalogo = 0;
        $total = 0;
        $totalPaginas = 1;
        $paginaActual = 1;

        // Acuse de la receta recién guardada: se llega aquí por redirección
        // desde el editor (?volver=), y la alerta normal no sobrevive al salto.
        $guardada = self::consumirAvisoGuardado();
        if ($guardada !== null) {
            Producto::setAlerta('exito', $guardada);
        }

        $ocultos = 0;

        try {
            $conteos = self::conteoComponentes();
            $ocultos = self::conteoOcultos();

            /*
             * Las dos cifras de la cabecera se consultan aparte, y no se
             * cuentan sobre $productos: con el listado paginado, contar las
             * filas traídas haría que «Platillos» dijera 10 y «Sin receta»
             * describiera la página en curso en vez del catálogo. Es la misma
             * trampa que ya se pagó en Tickets.
             */
            $catalogo = Producto::totalRecetas([]);

            $total = Producto::totalRecetas($filtros);
            $totalPaginas = max(1, (int) ceil($total / self::POR_PAGINA));

            $paginaActual = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$paginaActual || $paginaActual > $totalPaginas) {
                $paginaActual = 1;
            }

            $productos = Producto::buscarRecetas(
                $filtros,
                self::POR_PAGINA,
                ($paginaActual - 1) * self::POR_PAGINA
            );
        } catch (\Throwable $e) {
            Producto::setAlerta('error', 'No se pudo cargar el catálogo. ¿Ya corriste la migración de la BD?');
        }

        $data = [
            'title' => 'Recetas',
            'topbarSection' => 'Recetas',
            'productos' => $productos,
            'conteosReceta' => $conteos,
            'categoriasMap' => self::categoriasMap(),
            'totalProductos' => $catalogo,
            'conReceta' => count($conteos),
            'ocultos' => $ocultos,
            'pendienteActivo' => $filtros['estado'] === 'sin',
            'filtros' => $filtros,
            'filtrosActivos' => $filtros['q'] !== '' || $filtros['estado'] !== 'todas',
            'totalFiltrado' => $total,
            'paginaActual' => $paginaActual,
            'totalPaginas' => $totalPaginas,
            'porPagina' => self::POR_PAGINA,
            'alertas' => Producto::getAlertas(),
            'partialUrl' => AdminController::filterUrl(self::PATH, array_merge(
                $filtros,
                $paginaActual > 1 ? ['page' => $paginaActual] : []
            )),
        ];

        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('recetas/index', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('recetas/index', $data);
    }

    /**
     * Filtros del listado.
     *
     * `estado` vale «todas» y no cadena vacía cuando no filtra, y eso NO es
     * ruido en la URL: las pastillas son radios de verdad y el intercambio
     * reactivo las vuelve a marcar leyendo el parámetro de la URL canónica
     * (`syncControls` en reactive-filters.js). Un radio con `value=""` nunca
     * casaría en esa comparación, así que al pulsar «Todas» el grupo se
     * quedaría sin ninguna opción marcada.
     */
    private static function leerFiltros(): array
    {
        // 100 caracteres es el mismo tope que usa Menú. Se recorta aquí, antes
        // de que el modelo parta el texto en términos.
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

        $estado = (string) ($_GET['estado'] ?? '');
        if ($estado !== 'con' && $estado !== 'sin') {
            $estado = 'todas';
        }

        return ['q' => $q, 'estado' => $estado];
    }

    /**
     * Composición de la receta de un platillo. El platillo se muestra en solo
     * lectura: sus datos se editan en Gestión de menú.
     */
    public static function receta(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        $id = self::validarId($_GET['id'] ?? null, $router);
        $producto = Producto::paraReceta($id);

        if (!$producto) {
            Producto::setAlerta('error', 'El platillo no existe');
            self::index($router);
            return;
        }

        $alertas = Producto::getAlertas();
        $aviso = $_SERVER['REQUEST_METHOD'] === 'GET' ? self::consumirAvisoGuardado() : null;
        if ($aviso !== null) $alertas['exito'][] = $aviso;
        $volver = self::volverSeguro($_GET['volver'] ?? null);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            ContextoNegocio::exigir('productos', 'editar', $ctx, true);
            $guardado = self::guardarComponentes($ctx, (int) $producto->id);
            $componentes = count($guardado['componentes'] ?? []);
            if (empty($guardado['ok'])) {
                Producto::setAlerta('error', $guardado['mensaje'] ?? 'No se pudo guardar la receta.');
                self::render('recetas/form', [
                    'title' => 'Receta de ' . $producto->nombre,
                    'topbarSection' => 'Recetas / ' . $producto->nombre,
                    'producto' => $producto,
                    'categoriasMap' => self::categoriasMap(),
                    'ingredientes' => Ingrediente::paraRecetas($ctx),
                    'subrecetas' => Subreceta::todas($ctx),
                    'componentes' => RecetasOferta::componentes($ctx, (int)$producto->id),
                    'alertas' => Producto::getAlertas(),
                    'accion' => 'Guardar receta',
                    'volverUrl' => $volver ?? self::PATH,
                ]);
                return;
            }

            // Sin almacén de consumo la venta se rechaza: se vuelve a la receta a elegirlo.
            if ($componentes > 0 && AlmacenesConsumo::pendientes($ctx, (int) $producto->id) > 0) {
                $_SESSION[self::AVISO_GUARDADO] = 'Receta guardada. Falta elegir de qué almacén sale cada ingrediente.';
                self::redirect(self::urlReceta((int) $producto->id, $volver) . '#almacenes');
            }

            /*
             * Se llegó desde una lista de pendientes (la de Menú o la de este
             * mismo módulo): se vuelve a ella con una redirección de verdad
             * —recargar no reenvía el formulario— y el platillo ya no sale en
             * la lista. El acuse viaja en la sesión porque la alerta del
             * modelo es de esta petición y no sobrevive al salto.
             */
            if ($volver !== null) {
                // Guardar vacía no resuelve el pendiente: el acuse lo dice para
                // que no parezca que el platillo «se perdió» de la lista.
                $_SESSION[self::AVISO_GUARDADO] = $componentes > 0
                    ? 'Receta de «' . $producto->nombre . '» guardada.'
                    : 'Se guardó «' . $producto->nombre . '» sin componentes: sigue sin receta.';
                self::redirect($volver);
            }

            Producto::setAlerta('exito', 'Receta actualizada correctamente');
            self::index($router);
            return;
        }

        $ingredientes = [];
        $subrecetas = [];
        try {
            $ingredientes = Ingrediente::paraRecetas($ctx);
            $subrecetas = Subreceta::todas($ctx);
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
            'componentes' => RecetasOferta::componentes($ctx, (int) $producto->id),
            'almacenesConsumo' => AlmacenesConsumo::paraFormulario($ctx, (int) $producto->id),
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => $alertas,
            'accion' => 'Guardar receta',
            // «Volver» y «Cancelar» regresan a la lista de la que se vino.
            'volverUrl' => $volver ?? self::PATH,
        ]);
    }

    // ── Subrecetas ───────────────────────────────────────────
    public static function subrecetas(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        $subrecetas = [];
        try {
            $subrecetas = Subreceta::todas($ctx);
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
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $subreceta = new Subreceta();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $subreceta->sincronizar($_POST);
            $subreceta->activo = isset($_POST['activo']) ? 1 : 0;
            $alertas = $subreceta->validar();

            if (empty($alertas)) {
                $resultado = self::guardarSubrecetaCompleta($ctx, $subreceta);
                if (!empty($resultado['ok']) && !empty($resultado['confirmado'])) {
                    Subreceta::setAlerta('exito', 'Subreceta creada correctamente');
                    self::subrecetas($router);
                    return;
                }
                if (!empty($resultado['pendiente'])) {
                    Subreceta::setAlerta('error', 'El guardado quedó pendiente de confirmación; no se registró como exitoso.');
                } else {
                    $subreceta->id = null;
                    Subreceta::setAlerta('error', 'No se pudo guardar la subreceta y sus componentes. Intenta de nuevo.');
                }
                $alertas = Subreceta::getAlertas();
            }
        }

        self::renderSubrecetaForm($subreceta, $alertas, 'Crear subreceta', 'Nueva subreceta', []);
    }

    public static function subrecetaEdit(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $id = self::validarId($_GET['id'] ?? null, $router);
        $subreceta = Subreceta::find($id, $ctx);

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
                $resultado = self::guardarSubrecetaCompleta($ctx, $subreceta);
                if (!empty($resultado['ok']) && !empty($resultado['confirmado'])) {
                    Subreceta::setAlerta('exito', 'Subreceta actualizada correctamente');
                    self::subrecetas($router);
                    return;
                }
                if (!empty($resultado['pendiente'])) {
                    Subreceta::setAlerta('error', 'El guardado quedó pendiente de confirmación; no se registró como exitoso.');
                } else {
                    Subreceta::setAlerta('error', 'No se pudo guardar la subreceta y sus componentes. Intenta de nuevo.');
                }
                $alertas = Subreceta::getAlertas();
            }
        }

        self::renderSubrecetaForm($subreceta, $alertas, 'Guardar cambios', 'Editar subreceta', self::ingredientesDeSubreceta($ctx, (int) $subreceta->id));
    }

    public static function subrecetaDelete(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::SUBPATH);
        }

        $id = self::validarId($_POST['id'] ?? null, $router);
        $subreceta = Subreceta::find($id, $ctx);

        if ($subreceta && $subreceta->eliminar()) {
            Subreceta::setAlerta('exito', 'Subreceta eliminada correctamente');
        } else {
            Subreceta::setAlerta('error', 'No se pudo eliminar la subreceta');
        }

        self::subrecetas($router);
    }

    // ── Persistencia de recetas ──────────────────────────────
    /** Convierte el formulario actual a DTO y delega la versión a RecetasOferta. */
    /** POST: almacén de consumo de cada artículo que la receta o sus variantes descuentan. */
    public static function almacenes(Router $router): void
    {
        $ctx = ContextoNegocio::slug();
        $id = self::validarId($_GET['id'] ?? null, $router);
        $volver = self::volverSeguro($_GET['volver'] ?? null);
        $resultado = AdminCsrfService::validar(isset($_POST['admin_csrf']) ? (string) $_POST['admin_csrf'] : null)
            ? AlmacenesConsumo::guardar($ctx, $id, is_array($_POST['almacen'] ?? null) ? $_POST['almacen'] : [])
            : ['ok' => false, 'mensaje' => 'La sesión del formulario venció. Vuelve a elegir los almacenes.'];
        if (empty($resultado['ok'])) {
            Producto::setAlerta('error', $resultado['mensaje'] ?? 'No se pudieron guardar los almacenes.');
            $_SERVER['REQUEST_METHOD'] = 'GET';
            self::receta($router);
            return;
        }
        $_SESSION[self::AVISO_GUARDADO] = 'Almacenes de consumo guardados.';
        self::redirect($volver ?? self::urlReceta($id, null));
    }

    private static function urlReceta(int $id, ?string $volver): string
    {
        return self::PATH . '/editar?id=' . $id . ($volver !== null ? '&volver=' . rawurlencode($volver) : '');
    }

    private static function guardarComponentes(string $ctx, int $ofertaId): array
    {
        // Cada componente llega como "tipo:ref" (p. ej. "ingrediente:5") en comp[]
        // con su cantidad en comp_cant[]. Así no depende de JS para el tipo.
        $comps = $_POST['comp'] ?? [];
        $cants = $_POST['comp_cant'] ?? [];

        if (!is_array($comps)) {
            return ['ok' => false, 'mensaje' => 'La receta enviada no es válida.'];
        }
        $componentes = [];
        foreach ($comps as $i => $valor) {
            $partes = explode(':', (string) $valor, 2);
            if (count($partes) !== 2) {
                return ['ok' => false, 'mensaje' => 'Un componente de receta no es válido.'];
            }
            $tipo = $partes[0] === 'subreceta' ? 'subreceta' : 'ingrediente';
            $ref = (int) $partes[1];
            $cant = $cants[$i] ?? null;
            if ($ref <= 0 || !is_string($cant) || $cant === '') {
                return ['ok' => false, 'mensaje' => 'Cada componente requiere una cantidad positiva.'];
            }
            $componentes[] = ['tipo' => $tipo, 'ref_id' => $ref, 'cantidad' => $cant];
        }
        return RecetasOferta::guardar($ctx, $ofertaId, $componentes);
    }

    private static function guardarSubrecetaIngredientes(string $ctx, int $subrecetaId): bool
    {
        $refs  = $_POST['ing_ref'] ?? [];
        $cants = $_POST['ing_cant'] ?? [];
        if (!is_array($refs) || !is_array($cants)) return false;
        $unidadId = ContextoNegocio::unidad($ctx);
        try {
            $result = ContextoNegocio::en($ctx, static function () use ($subrecetaId, $refs, $cants, $unidadId, $ctx): array {
                return ContextoNegocio::transaccion(static function () use ($subrecetaId, $refs, $cants, $unidadId, $ctx): array {
                    ContextoNegocio::exigir('productos', 'editar', $ctx, true);
                    $db = Subreceta::getDB();
                    $check = $db->prepare('SELECT id FROM subrecetas WHERE id=? AND unidad_id=? FOR UPDATE');
                    $check->bind_param('ii', $subrecetaId, $unidadId); $check->execute();
                    $owned = (bool)$check->get_result()->fetch_assoc(); $check->close();
                    if (!$owned) throw new \DomainException('Subreceta fuera del ámbito.');
                    $limpios=[];
                    foreach ($refs as $i=>$ref) {
                        $ref=(int)$ref;
                        $cant=DecimalInventario::cantidad(DecimalInventario::leer($cants[$i]??null));
                        if ($ref<=0 || DecimalInventario::leer($cant)<=0) throw new \DomainException('Componente inválido.');
                        $limpios[]=[$ref,$cant];
                    }
                    $del=$db->prepare('DELETE FROM subreceta_ingredientes WHERE subreceta_id=? AND unidad_id=?');
                    $del->bind_param('ii',$subrecetaId,$unidadId); if(!$del->execute()) throw new \RuntimeException($del->error); $del->close();
                    $ins=$db->prepare('INSERT INTO subreceta_ingredientes (subreceta_id,unidad_id,articulo_id,cantidad) VALUES (?,?,?,?)');
                    foreach($limpios as [$articuloId,$cant]) { $ins->bind_param('iiis',$subrecetaId,$unidadId,$articuloId,$cant); if(!$ins->execute()) throw new \RuntimeException($ins->error); }
                    $ins->close();
                    return ['guardado'=>true];
                });
            });
            return !empty($result['ok']) || ($result['ok'] ?? null) === null;
        } catch (\Throwable $e) {
            error_log('AdminRecetasController::guardarSubrecetaIngredientes - '.$e->getMessage());
            return false;
        }
    }

    /** Guarda encabezado y composición bajo una sola confirmación externa. */
    private static function guardarSubrecetaCompleta(string $ctx, Subreceta $subreceta): array
    {
        try {
            return ContextoNegocio::en($ctx, static function () use ($ctx, $subreceta): array {
                return ContextoNegocio::transaccion(static function () use ($ctx, $subreceta): array {
                    ContextoNegocio::exigir('productos', 'editar', $ctx, true);
                    $resultado = $subreceta->guardar();
                    if ($resultado === false || (is_array($resultado) && empty($resultado['resultado']))) {
                        throw new \RuntimeException('No se pudo guardar el encabezado de la subreceta.');
                    }
                    $id = (int)($subreceta->id ?? (is_array($resultado) ? ($resultado['id'] ?? 0) : 0));
                    if ($id < 1 || !self::guardarSubrecetaIngredientes($ctx, $id)) {
                        throw new \RuntimeException('No se pudieron guardar los componentes de la subreceta.');
                    }
                    return ['subreceta_id' => $id, 'guardado' => true];
                });
            });
        } catch (\Throwable $e) {
            error_log('AdminRecetasController::guardarSubrecetaCompleta - ' . $e->getMessage());
            return ['ok' => false, 'confirmado' => false, 'pendiente' => false, 'mensaje' => 'No se pudo guardar la subreceta y sus componentes.'];
        }
    }

    // ── Lectura de recetas ───────────────────────────────────
    private static function componentesDe(int $productoId): array
    {
        return RecetasOferta::componentes(ContextoNegocio::slug(), $productoId);
    }

    private static function ingredientesDeSubreceta(string $ctx, int $subrecetaId): array
    {
        $db = Subreceta::getDB();
        $rows = [];
        $unidadId = ContextoNegocio::unidad($ctx);
        $stmt = $db->prepare(
            "SELECT si.articulo_id AS ref_id, si.cantidad, i.nombre, i.unidad
               FROM subreceta_ingredientes si
               JOIN ingredientes i ON i.id = si.articulo_id
              WHERE si.subreceta_id = ? AND si.unidad_id = ?
              ORDER BY i.nombre ASC"
        );
        $stmt->bind_param('ii', $subrecetaId, $unidadId); $stmt->execute(); $res=$stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Componentes de receta por platillo, separados por tipo: el listado dice
     * «4 ingredientes · 1 subreceta» en vez de llamar ingredientes a todo.
     * Sólo trae los platillos que TIENEN receta, así que su tamaño es el
     * «con receta» de la tarjeta.
     *
     * @return array<int, array{ingredientes:int, subrecetas:int}>
     */
    private static function conteoComponentes(): array
    {
        $db = Producto::getDB();
        $map = [];
        $unidadId = ContextoNegocio::unidad();
        $stmt = $db->prepare("SELECT r.oferta_id, SUM(rc.articulo_id IS NOT NULL) AS ingredientes, SUM(rc.subreceta_id IS NOT NULL) AS subrecetas FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id WHERE r.unidad_id=? AND r.activo=1 GROUP BY r.oferta_id");
        $stmt->bind_param('i', $unidadId); $stmt->execute(); $res=$stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $map[(int) $row['oferta_id']] = [
                    'ingredientes' => (int) $row['ingredientes'],
                    'subrecetas' => (int) $row['subrecetas'],
                ];
            }
        }
        $stmt->close();
        return $map;
    }

    /** Platillos ocultos de la carta, para la nota de la tarjeta «Platillos». */
    private static function conteoOcultos(): int
    {
        $unidadId = ContextoNegocio::unidad();
        $stmt = Producto::getDB()->prepare('SELECT COUNT(*) AS n FROM ofertas_producto WHERE unidad_id = ? AND activo = 0');
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row['n'] ?? 0);
    }

    /** Clave de sesión del acuse que sobrevive a la redirección tras guardar. */
    private const AVISO_GUARDADO = '_admin_receta_guardada';

    /**
     * El acuse pendiente, si lo hay, y lo borra: se enseña una sola vez. Lo
     * consume también Menú (AdminMenuController::index), que es la otra lista
     * a la que se vuelve.
     */
    public static function consumirAvisoGuardado(): ?string
    {
        $aviso = $_SESSION[self::AVISO_GUARDADO] ?? null;
        unset($_SESSION[self::AVISO_GUARDADO]);

        return is_string($aviso) && $aviso !== '' ? $aviso : null;
    }

    /**
     * El destino de «volver», sólo si es una ruta propia de las dos listas de
     * pendientes. Lista cerrada por prefijo, y nada que el navegador pudiera
     * leer como otro origen: `//host`, barras invertidas o saltos de línea
     * (cabecera inyectada). Cualquier otra cosa se ignora y el editor se
     * comporta como siempre.
     */
    private static function volverSeguro($volver): ?string
    {
        if (!is_string($volver) || $volver === '' || strlen($volver) > 300) {
            return null;
        }
        if (strpbrk($volver, "\\\r\n") !== false) {
            return null;
        }
        if (!preg_match('~^/admin/(menu|recetas)(?:[/?#]|$)~', $volver)) {
            return null;
        }

        return $volver;
    }

    private static function conteoSubrecetaIngredientes(): array
    {
        $db = Subreceta::getDB();
        $map = [];
        $unidadId = ContextoNegocio::unidad();
        $stmt = $db->prepare('SELECT subreceta_id, COUNT(*) AS n FROM subreceta_ingredientes WHERE unidad_id = ? GROUP BY subreceta_id');
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $map[(int) $row['subreceta_id']] = (int) $row['n'];
            }
        }
        $stmt->close();
        return $map;
    }

    /** Mapa id => nombre para pintar la categoría en el listado. */
    private static function categoriasMap(): array
    {
        $map = [];
        foreach (CategoriasMenu::paraRecetas() as $categoria) $map[(int)$categoria->id] = $categoria->nombre;
        return $map;
    }

    // ── Render ───────────────────────────────────────────────
    private static function renderSubrecetaForm(Subreceta $subreceta, array $alertas, string $accion, string $titulo, array $ingredientesReceta): void
    {
        $ingredientes = [];
        try {
            $ingredientes = Ingrediente::paraRecetas();
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
