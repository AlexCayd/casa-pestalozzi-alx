<?php
// Sólo usado por el runner aislado; jamás cambia includes/.env.
$notificationTestDb = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($notificationTestDb) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $notificationTestDb)) {
    throw new RuntimeException('Base aislada de notificaciones requerida.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
$_ENV['DB_NAME'] = $notificationTestDb;
$_ENV['APP_ENV'] = 'test';
$_ENV['N8N_BASE_URL'] = 'http://n8n.invalid';
$_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$notificationFixtureDb = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    $notificationTestDb
);
$notificationUnitStmt = $notificationFixtureDb->prepare("SELECT id FROM unidades WHERE slug = 'restaurante' LIMIT 1");
$notificationUnitStmt->execute();
$notificationRestaurantUnitId = (int)($notificationUnitStmt->get_result()->fetch_row()[0] ?? 0);
$notificationUnitStmt->close();
if ($notificationRestaurantUnitId < 1) {
    throw new RuntimeException('La unidad Restaurante no está sembrada en la base aislada.');
}
if (!defined('NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID')) {
    define('NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID', $notificationRestaurantUnitId);
}

// Las filas históricas ticket_items de estas pruebas ahora requieren oferta,
// producto, categoría y área locales. Crear una fixture mínima por área.
$notificationAreaSlugs = ['cafe', 'jugos', 'cocina', 'horno'];
$notificationAreas = [];
foreach ($notificationAreaSlugs as $notificationAreaOrder => $notificationAreaSlug) {
    $notificationAreaStmt = $notificationFixtureDb->prepare(
        'SELECT id FROM areas_produccion WHERE unidad_id = ? AND slug = ? LIMIT 1'
    );
    $notificationAreaStmt->bind_param('is', $notificationRestaurantUnitId, $notificationAreaSlug);
    $notificationAreaStmt->execute();
    $notificationAreaId = (int)($notificationAreaStmt->get_result()->fetch_row()[0] ?? 0);
    $notificationAreaStmt->close();
    if ($notificationAreaId < 1) {
        $notificationAreaName = ucfirst($notificationAreaSlug) . ' fixture';
        $notificationAreaColor = '#777777';
        $notificationAreaInsert = $notificationFixtureDb->prepare(
            'INSERT INTO areas_produccion (unidad_id, unidad_tipo, nombre, slug, color, orden) VALUES (?, \'negocio\', ?, ?, ?, ?)'
        );
        $notificationAreaInsert->bind_param(
            'isssi',
            $notificationRestaurantUnitId,
            $notificationAreaName,
            $notificationAreaSlug,
            $notificationAreaColor,
            $notificationAreaOrder
        );
        $notificationAreaInsert->execute();
        $notificationAreaId = (int)$notificationFixtureDb->insert_id;
        $notificationAreaInsert->close();
    }
    $notificationAreas[$notificationAreaSlug] = $notificationAreaId;
}
if (!defined('NOTIFICATIONS_TEST_AREA_IDS')) {
    define('NOTIFICATIONS_TEST_AREA_IDS', $notificationAreas);
}

$notificationCategoryStmt = $notificationFixtureDb->prepare(
    "SELECT id FROM categorias WHERE unidad_id = ? AND carta = 'comida' AND nombre = 'Fixture notificaciones' LIMIT 1"
);
$notificationCategoryStmt->bind_param('i', $notificationRestaurantUnitId);
$notificationCategoryStmt->execute();
$notificationCategoryId = (int)($notificationCategoryStmt->get_result()->fetch_row()[0] ?? 0);
$notificationCategoryStmt->close();
if ($notificationCategoryId < 1) {
    $notificationCategoryStmt = $notificationFixtureDb->prepare(
        "INSERT INTO categorias (unidad_id, carta, nombre, activo, orden) VALUES (?, 'comida', 'Fixture notificaciones', 1, 999)"
    );
    $notificationCategoryStmt->bind_param('i', $notificationRestaurantUnitId);
    $notificationCategoryStmt->execute();
    $notificationCategoryId = (int)$notificationFixtureDb->insert_id;
    $notificationCategoryStmt->close();
}

$notificationOffersByArea = [];
foreach ($notificationAreas as $notificationAreaSlug => $notificationAreaId) {
    $notificationOfferLookup = $notificationFixtureDb->prepare(
        'SELECT id, producto_id FROM ofertas_producto WHERE unidad_id = ? AND area_id = ? AND categoria_id = ? ORDER BY id LIMIT 1'
    );
    $notificationOfferLookup->bind_param('iii', $notificationRestaurantUnitId, $notificationAreaId, $notificationCategoryId);
    $notificationOfferLookup->execute();
    $notificationOfferRow = $notificationOfferLookup->get_result()->fetch_assoc() ?: null;
    $notificationOfferLookup->close();
    if (!$notificationOfferRow) {
        $notificationProductName = 'Fixture ticket ' . $notificationAreaSlug;
        $notificationProductStmt = $notificationFixtureDb->prepare('INSERT INTO productos (nombre, descripcion) VALUES (?, ?)');
        $notificationProductDescription = 'Producto de prueba efímero';
        $notificationProductStmt->bind_param('ss', $notificationProductName, $notificationProductDescription);
        $notificationProductStmt->execute();
        $notificationProductId = (int)$notificationFixtureDb->insert_id;
        $notificationProductStmt->close();
        $notificationOfferStmt = $notificationFixtureDb->prepare(
            'INSERT INTO ofertas_producto (producto_id, unidad_id, unidad_tipo, categoria_id, precio, area_id, activo, orden)
             VALUES (?, ?, \'negocio\', ?, 10.00, ?, 1, 999)'
        );
        $notificationOfferStmt->bind_param(
            'iiii',
            $notificationProductId,
            $notificationRestaurantUnitId,
            $notificationCategoryId,
            $notificationAreaId
        );
        $notificationOfferStmt->execute();
        $notificationOfferRow = ['id' => (int)$notificationFixtureDb->insert_id, 'producto_id' => $notificationProductId];
        $notificationOfferStmt->close();
    }
    $notificationOffersByArea[$notificationAreaId] = [
        'oferta_id' => (int)$notificationOfferRow['id'],
        'producto_id' => (int)$notificationOfferRow['producto_id'],
        'categoria_id' => $notificationCategoryId,
    ];
}
if (!defined('NOTIFICATIONS_TEST_OFFERS_BY_AREA')) {
    define('NOTIFICATIONS_TEST_OFFERS_BY_AREA', $notificationOffersByArea);
}
function notificationsTestOfferForArea(int $areaId): array
{
    $offer = NOTIFICATIONS_TEST_OFFERS_BY_AREA[$areaId] ?? null;
    if (!is_array($offer)) {
        throw new RuntimeException('No existe oferta sintética para el área de la prueba.');
    }
    return $offer;
}

// DDL vigente no incluye el catálogo histórico de mesas que las pruebas de
// reservas presuponen. Se recrea sólo dentro de la base efímera del runner.
$notificationMesaCount = $notificationFixtureDb->prepare('SELECT COUNT(*) FROM mesas WHERE unidad_id = ?');
$notificationMesaCount->bind_param('i', $notificationRestaurantUnitId);
$notificationMesaCount->execute();
$notificationHasTables = (int)$notificationMesaCount->get_result()->fetch_row()[0] > 0;
$notificationMesaCount->close();
if (!$notificationHasTables) {
    $notificationMesaInsert = $notificationFixtureDb->prepare(
        "INSERT INTO mesas (unidad_id, unidad_tipo, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable)
         VALUES (?, 'negocio', ?, ?, 'mesa', ?, 4, ?, ?, 1, 1)"
    );
    for ($notificationMesaNumber = 1; $notificationMesaNumber <= 11; $notificationMesaNumber++) {
        $notificationMesaName = 'Fixture Mesa ' . $notificationMesaNumber;
        $notificationMesaZone = $notificationMesaNumber >= 8 ? 'exterior' : 'interior';
        $notificationMesaX = 10.0 + ($notificationMesaNumber % 6) * 12.0;
        $notificationMesaY = 10.0 + intdiv($notificationMesaNumber - 1, 6) * 18.0;
        $notificationMesaInsert->bind_param(
            'iissdd',
            $notificationRestaurantUnitId,
            $notificationMesaNumber,
            $notificationMesaName,
            $notificationMesaZone,
            $notificationMesaX,
            $notificationMesaY
        );
        $notificationMesaInsert->execute();
    }
    $notificationMesaInsert->close();
    $notificationServiceTableInsert = $notificationFixtureDb->prepare(
        'INSERT INTO mesas (unidad_id, unidad_tipo, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable)
         VALUES (?, \'negocio\', ?, ?, ?, NULL, ?, ?, ?, 1, 0)'
    );
    foreach ([
        [12, 'Fixture Barra', 'barra', 0, 82.0, 18.0],
        [13, 'Fixture Especial', 'especial', 0, 82.0, 40.0],
        [14, 'Fixture Llevar', 'especial', 0, 82.0, 62.0],
    ] as [$notificationMesaNumber, $notificationMesaName, $notificationMesaType, $notificationMesaCapacity, $notificationMesaX, $notificationMesaY]) {
        $notificationServiceTableInsert->bind_param(
            'iissidd',
            $notificationRestaurantUnitId,
            $notificationMesaNumber,
            $notificationMesaName,
            $notificationMesaType,
            $notificationMesaCapacity,
            $notificationMesaX,
            $notificationMesaY
        );
        $notificationServiceTableInsert->execute();
    }
    $notificationServiceTableInsert->close();
}

// Actor aislado para suites que ejercitan acciones administrativas protegidas.
$notificationStaffUsername = 'notification_fixture_' . bin2hex(random_bytes(6));
$notificationStaffName = 'Fixture staff notificaciones';
$notificationPasswordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
$notificationStaffStmt = $notificationFixtureDb->prepare(
    'INSERT INTO usuarios (username, nombre, password_hash, activo, es_superadmin) VALUES (?, ?, ?, 1, 0)'
);
$notificationStaffStmt->bind_param('sss', $notificationStaffUsername, $notificationStaffName, $notificationPasswordHash);
$notificationStaffStmt->execute();
$notificationStaffId = (int)$notificationFixtureDb->insert_id;
$notificationStaffStmt->close();
$notificationMembership = $notificationFixtureDb->prepare(
    'INSERT INTO usuario_unidades (usuario_id, unidad_id, rol_id, activo) VALUES (?, ?, NULL, 1)'
);
$notificationMembership->bind_param('ii', $notificationStaffId, $notificationRestaurantUnitId);
$notificationMembership->execute();
$notificationMembership->close();
$notificationPermission = $notificationFixtureDb->prepare(
    "INSERT INTO usuario_unidad_permisos (usuario_id, unidad_id, modulo, nivel) VALUES (?, ?, ?, ?)"
);
foreach ([
    ['reservations', 'editar'],
    ['configuration', 'editar'],
    ['pdv', 'operar'],
    ['reservations_map', 'operar'],
    ['analytics', 'ver'],
] as [$notificationModule, $notificationLevel]) {
    $notificationPermission->bind_param('iiss', $notificationStaffId, $notificationRestaurantUnitId, $notificationModule, $notificationLevel);
    $notificationPermission->execute();
}
$notificationPermission->close();
if (!defined('NOTIFICATIONS_TEST_STAFF_ID')) {
    define('NOTIFICATIONS_TEST_STAFF_ID', $notificationStaffId);
}
function notificationsTestAuthenticateStaff(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [
        'login' => true,
        'id' => NOTIFICATIONS_TEST_STAFF_ID,
        'nombre' => 'Fixture staff notificaciones',
        'username' => 'notification_fixture',
        'auth_nivel' => 'contrasena',
        '_auth3b' => true,
    ];
}
$notificationFixtureDb->close();
