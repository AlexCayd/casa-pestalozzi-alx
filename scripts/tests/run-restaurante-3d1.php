<?php
declare(strict_types=1);

/** Integration harness 3D.1; all writes target a newly created owned database. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__ . '/soporte-3d.php';

use MenuPreservacion\Comun;
use MenuPreservacion\ImportadorMenu;
use MenuPreservacion\VerificadorMenu;
use Classes\ImagenUploader;
use Model\ActiveRecord;
use Model\HistorialPrecio;
use Multinegocio\SqlArchivo;
use Model\Producto;
use Model\Subreceta;
use Services\Inventory\HistorialPrecios;
use Services\Menu\Carta;
use Services\Menu\Ofertas;
use Services\Menu\RecetasOferta;
use Services\Menu\Variantes;
use Services\Security\ContextoNegocio;

$admin = null;
$bases = [];
$assetRoot = null;
$assetParent = null;
$imageFixturePath = null;
$imageFixtureName = null;
$imageFixtureRoot = null;
$phase = 'argumentos y configuración';
$error = null;
$count = 0;
$result = null;
try {
    $options = restaurante3dOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $_ENV['NIP_LOOKUP_SECRET'] = bin2hex(random_bytes(32));
    putenv('NIP_LOOKUP_SECRET=' . $_ENV['NIP_LOOKUP_SECRET']);
    $config = $_ENV;
    Comun::origen($config, $options, $root); // Compara identidad; no conecta al origen.
    $packageCheck = VerificadorMenu::verificar($options['paquete']);
    restaurante3dAssert(($packageCheck['ok'] ?? false) === true, 'El paquete comercial no superó la verificación.', $count);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión al servidor sin esquema';
    $admin = new mysqli((string)$config['DB_HOST'], (string)$config['DB_USER'], (string)$config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    if ($admin->thread_id === 0) throw new RuntimeException('No se abrió la conexión administrativa sin esquema.');

    $phase = 'DDL y deploy en destino aleatorio';
    [$database, $token] = restaurante3dCrearBase($admin, $root, $bases);
    ActiveRecord::setDB($admin);
    [$assetRoot, $assetParent] = restaurante3dCrearRaizImagenes();

    $phase = 'importación del menú preservado';
    $import = ImportadorMenu::importar($admin, $options['paquete'], $assetRoot, $token);
    restaurante3dAssert(($import['ok'] ?? false) === true, 'Importación del paquete de menú falló.', $count);
    restaurante3dAssert(($import['conteos']['productos'] ?? null) === 112
        && ($import['conteos']['categorias'] ?? null) === 15
        && ($import['conteos']['areas'] ?? null) === 4
        && ($import['conteos']['ofertas'] ?? null) === 112
        && ($import['conteos']['archivos'] ?? null) === 13, 'Los conteos preservados no son 112 productos, 15 categorías, 4 áreas, 112 ofertas y 13 archivos.', $count);
    $projection = ImportadorMenu::proyectar($admin, $options['paquete']);
    $sourceMenu = Comun::leerJson($options['paquete'] . DIRECTORY_SEPARATOR . 'menu.json');
    $differences = VerificadorMenu::comparar($sourceMenu, $projection);
    restaurante3dAssert($differences === [], 'La proyección reconstruida cambió datos del menú: ' . implode('; ', array_slice($differences, 0, 5)), $count);
    restaurante3dAssert((int)$admin->query("SELECT COUNT(*) FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='restaurante'")->fetch_row()[0] === 112
        && (int)$admin->query("SELECT COUNT(*) FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='panaderia'")->fetch_row()[0] === 0,
        'La importación preservada debe pertenecer solo a Restaurante.', $count);

    $phase = 'catálogo público y contratos de alcance';
    $restAdmin = FixtureRestaurante3D::usuario($admin, 'rest-edit', ['menu' => 'editar', 'productos' => 'editar', 'pdv' => 'operar']);
    $panAdmin = FixtureRestaurante3D::usuario($admin, 'pan-edit', ['menu' => 'editar', 'productos' => 'editar'], 'panaderia');
    $readOnly = FixtureRestaurante3D::usuario($admin, 'rest-read', ['menu' => 'ver', 'productos' => 'ver']);
    $recipeOnly = FixtureRestaurante3D::usuario($admin, 'recipe-only', ['productos' => 'editar']);
    $menuOnly = FixtureRestaurante3D::usuario($admin, 'menu-only', ['menu' => 'editar']);
    $noAccess = FixtureRestaurante3D::usuario($admin, 'rest-none', []);
    $phase = 'lecturas del menú público y DTO de restaurante';
    restaurante3dIniciarSesion($restAdmin);
    restaurante3dAssert(ContextoNegocio::nuevo(), 'El contexto debe activar autenticación multinegocio con fixture sintético.', $count);
    $publicMenu = Carta::publica(null);
    $publicCount = array_sum(array_map(static fn(array $category): int => count($category['dishes']), $publicMenu));
    $visibleCount = (int)$admin->query("SELECT COUNT(*) FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE u.slug='restaurante' AND o.activo=1 AND c.activo=1")->fetch_row()[0];
    restaurante3dAssert($publicCount === $visibleCount, 'La carta pública debe coincidir con las ofertas activas visibles de Restaurante.', $count);
    foreach (['comida', 'maridaje', 'temporada'] as $letter) {
        $letterMenu = Carta::publica($letter);
        $letterCount = array_sum(array_map(static fn(array $category): int => count($category['dishes']), $letterMenu));
        $stmt = $admin->prepare("SELECT COUNT(*) FROM ofertas_producto o JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='restaurante' AND c.carta=? AND o.activo=1 AND c.activo=1");
        $stmt->bind_param('s', $letter); $stmt->execute(); $expectedLetterCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
        restaurante3dAssert($letterCount === $expectedLetterCount, "Carta pública {$letter} conserva la proyección usada por el PDF.", $count);
    }
    $pos = Carta::paraPos();
    $posCount = array_sum(array_map(static fn(array $category): int => count($category['dishes']), $pos));
    restaurante3dAssert($posCount === $visibleCount, 'El catálogo POS debe coincidir con las ofertas activas visibles de Restaurante.', $count);
    $allDtos = ContextoNegocio::en('restaurante', static fn() => Producto::all());
    $allCount = is_array($allDtos) ? count($allDtos) : -1;
    $todosDtos = ContextoNegocio::en('restaurante', static fn() => Producto::todos());
    restaurante3dAssert($allCount === 112 && count($todosDtos) === 112
        && count(array_filter($allDtos, static fn($dto): bool => $dto instanceof Producto && (int)$dto->unidad_id === (int)$restAdmin['unidad_id'])) === 112,
        'Producto::all() y todos() listan las 112 ofertas locales sin recursión ni filas de Panadería.', $count);
    $restOffer = (int)$admin->query("SELECT o.id FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id WHERE u.slug='restaurante' ORDER BY o.id LIMIT 1")->fetch_row()[0];
    $restProductId = (int)$admin->query("SELECT producto_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0];
    $restPriceBefore = (string)$admin->query("SELECT precio FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0];
    $originalImage = $admin->query("SELECT img FROM productos WHERE id=$restProductId")->fetch_row()[0];
    $imageFixture = restaurante3dCrearImagenPropia($root);
    $imageFixturePath = $imageFixture['path']; $imageFixtureName = $imageFixture['filename']; $imageFixtureRoot = $imageFixture['images_root'];
    $newImageRoute = $imageFixture['public_path'];
    $stmt = $admin->prepare('UPDATE productos SET img=? WHERE id=?');
    $stmt->bind_param('si', $newImageRoute, $restProductId); $stmt->execute(); $stmt->close();
    ImagenUploader::eliminar($newImageRoute);
    restaurante3dAssert(is_file($imageFixturePath), 'La guarda conserva una imagen compartida por las ofertas de ambos negocios.', $count);
    $stmt = $admin->prepare('UPDATE productos SET img=? WHERE id=?');
    $stmt->bind_param('si', $originalImage, $restProductId); $stmt->execute(); $stmt->close();
    ImagenUploader::eliminar($newImageRoute);
    restaurante3dAssert(!file_exists($imageFixturePath), 'La imagen temporal se elimina cuando ya no tiene referencias.', $count);
    $panUnit = (int)$admin->query("SELECT id FROM unidades WHERE slug='panaderia'")->fetch_row()[0];
    $panCategory = 'Fixture 3D ' . bin2hex(random_bytes(5));
    $panArea = 'fixture3d_' . bin2hex(random_bytes(4));
    $stmt = $admin->prepare('INSERT INTO categorias(unidad_id,carta,nombre,activo,orden) VALUES (?,\'comida\',?,1,0)');
    $stmt->bind_param('is', $panUnit, $panCategory); $stmt->execute(); $panCategoryId = (int)$admin->insert_id; $stmt->close();
    $stmt = $admin->prepare('INSERT INTO areas_produccion(unidad_id,nombre,slug,color,orden) VALUES (?,?,?,\'#aabbcc\',0)');
    $panAreaName = 'Área fixture 3D'; $stmt->bind_param('iss', $panUnit, $panAreaName, $panArea); $stmt->execute(); $panAreaId = (int)$admin->insert_id; $stmt->close();

    $phase = 'alta y aislamiento de oferta de Panadería';
    restaurante3dIniciarSesion($panAdmin);
    $panOfferResult = Ofertas::crear($restProductId, 'panaderia', ['categoria_id' => $panCategoryId, 'precio' => '17.35', 'area_id' => $panAreaId, 'activo' => true, 'orden' => 0]);
    restaurante3dAssert(($panOfferResult['ok'] ?? false) === true, 'Panadería debe poder crear una oferta propia para una identidad existente.', $count);
    $panOffer = (int)($panOfferResult['oferta_id'] ?? $panOfferResult['id'] ?? 0);
    restaurante3dAssert($panOffer > 0, 'El alta debe devolver el ID de oferta comercial.', $count);
    restaurante3dAssert((string)$admin->query("SELECT precio FROM ofertas_producto WHERE id=$panOffer")->fetch_row()[0] === '17.35'
        && (string)$admin->query("SELECT precio FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0] === $restPriceBefore,
        'El precio de Panadería debe ser independiente del de Restaurante.', $count);
    $panDto = Ofertas::obtener($panOffer, 'panaderia');
    restaurante3dAssert(is_array($panDto) && (int)($panDto['producto_id'] ?? 0) === $restProductId
        && (int)($panDto['oferta_id'] ?? $panDto['id'] ?? 0) === $panOffer, 'El DTO identifica producto e instancia comercial por separado.', $count);
    $crossOffer = Ofertas::crear($restProductId, 'panaderia', ['categoria_id' => (int)$admin->query("SELECT o.categoria_id FROM ofertas_producto o WHERE o.id=$restOffer")->fetch_row()[0], 'precio' => '12.00', 'area_id' => (int)$admin->query("SELECT o.area_id FROM ofertas_producto o WHERE o.id=$restOffer")->fetch_row()[0]]);
    restaurante3dAssert(($crossOffer['ok'] ?? true) === false, 'Una oferta Panadería no puede reutilizar categoría/área de Restaurante.', $count);
    restaurante3dAssert(Ofertas::obtener($restOffer, 'panaderia') === null, 'Panadería no puede resolver el ID de una oferta de Restaurante.', $count);

    $ingredientName = 'Ingrediente fixture 3D ' . bin2hex(random_bytes(5));
    $stmt = $admin->prepare('INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,\'g\',1.2500,1)');
    $stmt->bind_param('s', $ingredientName); $stmt->execute(); $ingredientId = (int)$admin->insert_id; $stmt->close();
    $phase = 'receta de Restaurante y rechazo de subreceta ajena';
    restaurante3dIniciarSesion($restAdmin);
    $restRecipe = RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '10.000']]);
    restaurante3dAssert(($restRecipe['ok'] ?? false) === true, 'Restaurante puede guardar receta versionada para su oferta.', $count);
    restaurante3dAssert(count(RecetasOferta::componentes('restaurante', $restOffer)) === 1, 'La receta de Restaurante se consulta por oferta.', $count);
    $firstRecipeId = (int)$restRecipe['receta_id'];
    $secondRecipe = RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '0.001']]);
    restaurante3dAssert(($secondRecipe['ok'] ?? false) === true && (int)$secondRecipe['version'] === 2,
        'La edición de receta crea la siguiente versión.', $count);
    $versions = $admin->query("SELECT r.id,r.version,r.activo,rc.cantidad FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id WHERE r.oferta_id=$restOffer AND r.unidad_id={$restAdmin['unidad_id']} ORDER BY r.version")->fetch_all(MYSQLI_ASSOC);
    restaurante3dAssert(count($versions) === 2 && (int)$versions[0]['id'] === $firstRecipeId
        && (int)$versions[0]['version'] === 1 && (int)$versions[0]['activo'] === 0 && (string)$versions[0]['cantidad'] === '10.000'
        && (int)$versions[1]['version'] === 2 && (int)$versions[1]['activo'] === 1 && (string)$versions[1]['cantidad'] === '0.001',
        'La nueva versión conserva la composición histórica y almacena exactamente 0.001.', $count);
    $versionCountBeforeInvalid = count($versions);
    $invalidPrecision = RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '0.0001']]);
    $versionsAfterInvalid = (int)$admin->query("SELECT COUNT(*) FROM recetas WHERE oferta_id=$restOffer AND unidad_id={$restAdmin['unidad_id']}")->fetch_row()[0];
    restaurante3dAssert(($invalidPrecision['ok'] ?? true) === false && $versionsAfterInvalid === $versionCountBeforeInvalid
        && (string)$admin->query("SELECT rc.cantidad FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id WHERE r.oferta_id=$restOffer AND r.unidad_id={$restAdmin['unidad_id']} AND r.activo=1")->fetch_row()[0] === '0.001',
        'Más de tres decimales se rechazan sin crear versión ni alterar cantidades.', $count);
    $phase = 'permisos de recetas sin permiso de Menú';
    restaurante3dIniciarSesion($recipeOnly);
    $recipeDto = ContextoNegocio::en('restaurante', static fn() => Producto::paraReceta($restOffer));
    restaurante3dAssert($recipeDto instanceof Producto && (int)$recipeDto->id === $restOffer
        && (int)$recipeDto->oferta_id === $restOffer && (int)$recipeDto->producto_id === $restProductId,
        'Un usuario con productos/ver puede consultar DTO para receta sin menú/ver.', $count);
    restaurante3dAssert(count(RecetasOferta::componentes('restaurante', $restOffer)) === 1,
        'Un usuario de Recetas puede consultar componentes sin permiso de Menú.', $count);
    $recipeOnlySave = RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '0.002']]);
    restaurante3dAssert(($recipeOnlySave['ok'] ?? false) === true, 'Un usuario con productos/editar puede guardar recetas sin menú/editar.', $count);
    $phase = 'alta transaccional de subreceta con componentes';
    $guardarSubrecetaCompleta = new ReflectionMethod(\Controllers\AdminRecetasController::class, 'guardarSubrecetaCompleta');
    $guardarSubrecetaCompleta->setAccessible(true);
    $postAnterior = $_POST;
    try {
        $subrecetaOk = new Subreceta();
        $subrecetaOk->nombre = 'Subreceta fixture completa ' . bin2hex(random_bytes(4));
        $subrecetaOk->unidad = 'g'; $subrecetaOk->rendimiento = '250.000'; $subrecetaOk->activo = 1;
        $_POST = ['ing_ref' => [$ingredientId], 'ing_cant' => ['2.125']];
        $subrecetaGuardada = $guardarSubrecetaCompleta->invoke(null, 'restaurante', $subrecetaOk);
        $savedSubrecipeId = (int)($subrecetaGuardada['subreceta_id'] ?? 0);
        $savedSubrecipeRows = $savedSubrecipeId > 0
            ? $admin->query("SELECT articulo_id,cantidad FROM subreceta_ingredientes WHERE subreceta_id=$savedSubrecipeId AND unidad_id={$restAdmin['unidad_id']}")->fetch_all(MYSQLI_ASSOC)
            : [];
        restaurante3dAssert(($subrecetaGuardada['ok'] ?? false) === true && !empty($subrecetaGuardada['confirmado'])
            && count($savedSubrecipeRows) === 1 && (int)$savedSubrecipeRows[0]['articulo_id'] === $ingredientId
            && (string)$savedSubrecipeRows[0]['cantidad'] === '2.125',
            'El flujo privado guarda encabezado y composición exacta de subreceta dentro de una transacción.', $count);

        $subrecipesBeforeFailure = (int)$admin->query("SELECT COUNT(*) FROM subrecetas WHERE unidad_id={$restAdmin['unidad_id']}")->fetch_row()[0];
        $componentsBeforeFailure = (int)$admin->query("SELECT COUNT(*) FROM subreceta_ingredientes WHERE unidad_id={$restAdmin['unidad_id']}")->fetch_row()[0];
        $missingIngredientId = (int)$admin->query('SELECT COALESCE(MAX(id),0)+1000000 FROM ingredientes')->fetch_row()[0];
        $subrecetaFail = new Subreceta();
        $subrecetaFail->nombre = 'Subreceta rollback fixture ' . bin2hex(random_bytes(4));
        $subrecetaFail->unidad = 'g'; $subrecetaFail->rendimiento = '100.000'; $subrecetaFail->activo = 1;
        $_POST = ['ing_ref' => [$ingredientId, $missingIngredientId], 'ing_cant' => ['1.000', '3.000']];
        $subrecetaFallida = $guardarSubrecetaCompleta->invoke(null, 'restaurante', $subrecetaFail);
        $subrecipesAfterFailure = (int)$admin->query("SELECT COUNT(*) FROM subrecetas WHERE unidad_id={$restAdmin['unidad_id']}")->fetch_row()[0];
        $componentsAfterFailure = (int)$admin->query("SELECT COUNT(*) FROM subreceta_ingredientes WHERE unidad_id={$restAdmin['unidad_id']}")->fetch_row()[0];
        restaurante3dAssert(($subrecetaFallida['ok'] ?? true) === false
            && $subrecipesAfterFailure === $subrecipesBeforeFailure && $componentsAfterFailure === $componentsBeforeFailure,
            'Un ID de ingrediente inválido en la segunda línea revierte encabezado y todos los componentes.', $count);
    } finally {
        $_POST = $postAnterior;
        unset($_POST['ing_ref'], $_POST['ing_cant']);
    }
    restaurante3dIniciarSesion($menuOnly);
    restaurante3dDenegado(fn() => ContextoNegocio::en('restaurante', static fn() => Producto::paraReceta($restOffer)), 'Un usuario con Menú pero sin productos/ver no consulta la receta.', $count);
    restaurante3dDenegado(fn() => RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '0.003']]),
        'Un usuario con Menú pero sin productos/editar no guarda recetas.', $count);
    $variantEditor = ContextoNegocio::en('restaurante', static fn() => Variantes::datosEditor($restOffer, 'restaurante'));
    restaurante3dAssert(is_array($variantEditor) && (int)($variantEditor['oferta_id'] ?? 0) === $restOffer
        && count($variantEditor['base'] ?? []) === 1,
        'Un usuario con Menú/editar puede consultar la vista previa y base del editor de variantes de su oferta sin permiso productos.', $count);
    restaurante3dIniciarSesion($restAdmin);
    $panSubrecipeName = 'Subreceta fixture Pan ' . bin2hex(random_bytes(4));
    $stmt = $admin->prepare('INSERT INTO subrecetas(unidad_id,nombre,rendimiento,unidad) VALUES (?, ?, 100.000, \'g\')');
    $stmt->bind_param('is', $panUnit, $panSubrecipeName); $stmt->execute(); $panSubrecipeId = (int)$admin->insert_id; $stmt->close();
    $crossRecipe = RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'subreceta', 'ref_id' => $panSubrecipeId, 'cantidad' => '1.000']]);
    restaurante3dAssert(($crossRecipe['ok'] ?? true) === false, 'Restaurante debe rechazar subrecetas de Panadería manipuladas por ID.', $count);
    $phase = 'receta propia de Panadería y aislamiento de recetas';
    restaurante3dIniciarSesion($panAdmin);
    $panRecipe = RecetasOferta::guardar('panaderia', $panOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '12.000']]);
    restaurante3dAssert(($panRecipe['ok'] ?? false) === true && count(RecetasOferta::componentes('panaderia', $panOffer)) === 1,
        'Panadería debe guardar y consultar receta propia sin alterar la de Restaurante.', $count);

    $variantTemplate = static fn(?int $subrecipe): array => [[
        'nombre' => 'Tamaño', 'opciones' => [
            ['nombre' => 'Regular', 'precio_delta' => '0.00', 'predeterminada' => true, 'activo' => true, 'ajustes' => []],
            ['nombre' => 'Grande', 'precio_delta' => '2.00', 'predeterminada' => false, 'activo' => true,
                'ajustes' => $subrecipe ? [['accion' => 'agregar', 'tipo' => 'subreceta', 'ref_id' => $subrecipe, 'cantidad' => '5.000']] : []],
        ],
    ]];
    $phase = 'variantes propias de Panadería y rechazo de selección cruzada';
    $panVariants = Variantes::guardar($panOffer, $variantTemplate($panSubrecipeId), 'panaderia');
    restaurante3dAssert(($panVariants['ok'] ?? false) === true, 'Panadería puede guardar variantes con subreceta propia.', $count);
    $panVariantId = (int)($panVariants['grupos'][0]['opciones'][1]['id'] ?? 0);
    restaurante3dAssert($panVariantId > 0, 'La variante de Panadería debe recibir ID propio.', $count);
    $phase = 'variantes de Restaurante y manipulación de referencias';
    restaurante3dIniciarSesion($restAdmin);
    $restVariants = Variantes::guardar($restOffer, $variantTemplate(null), 'restaurante');
    restaurante3dAssert(($restVariants['ok'] ?? false) === true, 'Restaurante puede guardar variantes para su oferta.', $count);
    $crossVariantSelection = Variantes::resolver($restOffer, [$panVariantId], 'restaurante');
    restaurante3dAssert(($crossVariantSelection['ok'] ?? true) === false, 'Restaurante debe rechazar selección con variante de Panadería.', $count);
    $crossVariantSubrecipe = Variantes::guardar($restOffer, $variantTemplate($panSubrecipeId), 'restaurante');
    restaurante3dAssert(($crossVariantSubrecipe['ok'] ?? true) === false, 'Restaurante debe rechazar subreceta ajena dentro del payload de variante.', $count);

    $phase = 'denegación de permisos de solo lectura';
    restaurante3dIniciarSesion($readOnly);
    restaurante3dAssert(Ofertas::obtener($panOffer, 'restaurante') === null, 'Restaurante no puede resolver el ID de una oferta de Panadería.', $count);
    restaurante3dDenegado(fn() => Ofertas::crear($restProductId, 'restaurante', ['categoria_id' => (int)$admin->query("SELECT categoria_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0], 'precio' => '9.99', 'area_id' => (int)$admin->query("SELECT area_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0]]), 'Permiso de lectura no permite editar ofertas.', $count);
    $phase = 'denegación por ausencia de permiso directo';
    restaurante3dIniciarSesion($noAccess);
    restaurante3dDenegado(fn() => RecetasOferta::guardar('restaurante', $restOffer, [['tipo' => 'ingrediente', 'ref_id' => $ingredientId, 'cantidad' => '1.000']]), 'La ausencia de permiso directo deniega edición de recetas.', $count);
    restaurante3dDenegado(fn() => Producto::find($restOffer), 'La ausencia de permiso directo deniega lectura del DTO de producto.', $count);
    $phase = 'rechazo de retiro de producto sin permiso de edición';
    $unauthorizedProduct = new Producto();
    $unauthorizedProduct->id = $unauthorizedProduct->oferta_id = $restOffer;
    restaurante3dDenegado(fn() => $unauthorizedProduct->retirar(), 'La ausencia de permiso directo deniega el retiro de una oferta.', $count);
    $phase = 'DTO de Producto con identidad y oferta separadas';
    restaurante3dIniciarSesion($restAdmin);
    $productDto = Producto::find($restOffer);
    restaurante3dAssert($productDto instanceof Producto && (int)$productDto->id === $restOffer
        && (int)$productDto->oferta_id === $restOffer && (int)$productDto->producto_id === $restProductId,
        'El DTO de producto conserva alias de oferta y la identidad del producto.', $count);
    $identityBefore = $admin->query("SELECT nombre,descripcion,img FROM productos WHERE id=$restProductId")->fetch_assoc();
    $panOfferStateBefore = $admin->query("SELECT precio,activo,orden FROM ofertas_producto WHERE id=$panOffer")->fetch_assoc();
    $productDto->precio = '31.27';
    $productDto->orden = 987;
    $productDto->activo = 0;
    restaurante3dAssert(ContextoNegocio::en('restaurante', static fn() => $productDto->actualizar()) === true,
        'Editar una oferta solo actualiza el DTO comercial de Restaurante.', $count);
    $restOfferUpdated = $admin->query("SELECT precio,activo,orden FROM ofertas_producto WHERE id=$restOffer")->fetch_assoc();
    $identityAfter = $admin->query("SELECT nombre,descripcion,img FROM productos WHERE id=$restProductId")->fetch_assoc();
    $panOfferStateAfter = $admin->query("SELECT precio,activo,orden FROM ofertas_producto WHERE id=$panOffer")->fetch_assoc();
    restaurante3dAssert((string)$restOfferUpdated['precio'] === '31.27' && (int)$restOfferUpdated['activo'] === 0 && (int)$restOfferUpdated['orden'] === 987
        && $identityAfter === $identityBefore && $panOfferStateAfter === $panOfferStateBefore,
        'Precio, estado y orden se persisten localmente sin cambiar identidad ni oferta Panadería.', $count);
    $phase = 'historial de precio limitado a la oferta local';
    restaurante3dAssert(HistorialPrecios::registrar(HistorialPrecio::ENTIDAD_PRODUCTO, $restOffer, $restPriceBefore, '32.50', 'edicion', null, (int)$restAdmin['unidad_id']),
        'Registrar historial de precio para la oferta de Restaurante.', $count);
    $offerHistory = ContextoNegocio::en('restaurante', static fn() => HistorialPrecio::historial(HistorialPrecio::ENTIDAD_PRODUCTO, $restOffer));
    restaurante3dAssert(count($offerHistory) === 1 && (int)$offerHistory[0]->unidad_id === (int)$restAdmin['unidad_id']
        && (int)$offerHistory[0]->oferta_id === $restOffer && abs((float)$offerHistory[0]->precio_anterior - (float)$restPriceBefore) < 0.0001
        && (string)$offerHistory[0]->precio_nuevo === '32.5000',
        'La consulta de historial confirma propietario, oferta y precios en el ámbito Restaurante.', $count);
    $categoryBeforeCrossEdit = (int)$admin->query("SELECT categoria_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0];
    $productDto->categoria_id = $panCategoryId;
    restaurante3dDenegado(fn() => ContextoNegocio::en('restaurante', static fn() => $productDto->actualizar()),
        'La edición rechaza categoría Panadería manipulada en Restaurante.', $count);
    restaurante3dAssert((int)$admin->query("SELECT categoria_id FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0] === $categoryBeforeCrossEdit,
        'El rechazo de categoría cruzada no muta la oferta.', $count);
    $productDto->categoria_id = $categoryBeforeCrossEdit;
    $productDto->activo = 1;
    restaurante3dAssert(ContextoNegocio::en('restaurante', static fn() => $productDto->actualizar()) === true
        && (int)$admin->query("SELECT activo FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0] === 1,
        'La oferta local puede reactivarse antes de probar su retiro explícito.', $count);
    restaurante3dAssert(ContextoNegocio::en('restaurante', static fn() => $productDto->retirar()) === true
        && (int)$admin->query("SELECT activo FROM ofertas_producto WHERE id=$restOffer")->fetch_row()[0] === 0
        && (int)$admin->query("SELECT activo FROM ofertas_producto WHERE id=$panOffer")->fetch_row()[0] === (int)$panOfferStateBefore['activo'],
        'Retirar la oferta de Restaurante es local y no desactiva la oferta de Panadería.', $count);

    $result = ['ok' => true, 'fase' => '3D.1', 'comprobaciones' => $count, 'base_temporal' => $database,
        'menu' => ['productos' => 112, 'categorias' => 15, 'areas' => 4, 'ofertas' => 112, 'imagenes' => 13],
        'oferta_panaderia' => $panOffer, 'nota' => 'Generación HTTP/PDF y navegador se cubre en 3D.4; no es una prueba de impresora física.'];
} catch (Throwable $exception) {
    $error = $exception instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $exception->getCode() . '.' : $exception->getMessage();
} finally {
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$name, $token]) {
            try {
                if (restaurante3dBasePropia($admin, $name, $token, (string)($_ENV['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . restaurante3dIdent($name));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $name\n");
                } else { $cleanupFailed = true; }
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($imageFixturePath !== null) {
        try { restaurante3dLimpiarImagenPropia($imageFixturePath, $imageFixtureName, $imageFixtureRoot, $root); }
        catch (Throwable) { $cleanupFailed = true; }
    }
    if ($assetRoot !== null && $assetParent !== null) {
        try { restaurante3dLimpiarRaizImagenes($assetRoot, $assetParent); }
        catch (Throwable) { $cleanupFailed = true; }
    }
    if ($cleanupFailed && $error === null) { $phase = 'limpieza protegida'; $error = 'No se pudo comprobar o limpiar algún recurso temporal.'; }
}
if ($error === null) echo Comun::json($result ?? ['ok' => true, 'comprobaciones' => $count]);
else fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $phase, 'comprobaciones' => $count, 'error' => $error]));
exit($error === null ? 0 : 1);
