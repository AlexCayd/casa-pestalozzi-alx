<?php

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AdminController;
use Controllers\AdminAreaController;
use Controllers\AdminMapController;
use Controllers\AdminMenuController;
use Controllers\AuthController;
use Controllers\HomeController;
use Controllers\MenuController;
use Controllers\ReservacionController;
use Controllers\FeedbackController;
use Controllers\MapaController;
use Controllers\AreaController;

$router = new Router();

// Home
$router->get('/', [HomeController::class, 'index']);

// Reservaciones
$router->post('/reservar', [ReservacionController::class, 'crear']);

// Admin
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/menu', [AdminMenuController::class, 'index']);
$router->get('/admin/menu/categories', [AdminMenuController::class, 'categories']);
$router->get('/admin/menu/categories/create', [AdminMenuController::class, 'categoryCreate']);
$router->post('/admin/menu/categories/create', [AdminMenuController::class, 'categoryCreate']);
$router->get('/admin/menu/categories/edit', [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categories/edit', [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categories/delete', [AdminMenuController::class, 'categoryDelete']);
$router->get('/admin/menu/items', [AdminMenuController::class, 'items']);
$router->get('/admin/menu/items/create', [AdminMenuController::class, 'itemCreate']);
$router->post('/admin/menu/items/create', [AdminMenuController::class, 'itemCreate']);
$router->get('/admin/menu/items/edit', [AdminMenuController::class, 'itemEdit']);
$router->post('/api/cancelar-item',       [MapaController::class, 'cancelarItem']);
$router->post('/admin/menu/items/edit', [AdminMenuController::class, 'itemEdit']);
$router->post('/admin/menu/items/delete', [AdminMenuController::class, 'itemDelete']);
$router->get('/admin/map', [AdminMapController::class, 'index']);
$router->get('/admin/area', [AdminAreaController::class, 'index']);
$router->get('/admin/area/cafe', [AdminAreaController::class, 'cafe']);
$router->get('/admin/area/jugos', [AdminAreaController::class, 'jugos']);
$router->get('/admin/area/cocina', [AdminAreaController::class, 'cocina']);
$router->get('/admin/area/horno', [AdminAreaController::class, 'horno']);
$router->get('/admin/api/map', [AdminMapController::class, 'map']);
$router->post('/admin/api/open-ticket', [AdminMapController::class, 'openTicket']);
$router->post('/admin/api/release-reservation', [AdminMapController::class, 'releaseReservation']);
$router->post('/admin/api/close-ticket', [AdminMapController::class, 'closeTicket']);
$router->post('/admin/api/send-order', [AdminMapController::class, 'sendOrder']);
$router->get('/admin/api/ticket-items', [AdminMapController::class, 'ticketItems']);
$router->post('/admin/api/deliver-item', [AdminMapController::class, 'deliverItem']);
$router->post('/admin/api/update-ticket', [AdminMapController::class, 'updateTicket']);
$router->get('/admin/api/area-items', [AdminAreaController::class, 'areaItems']);
$router->post('/admin/api/advance-item', [AdminAreaController::class, 'advanceItem']);
$router->post('/admin/api/rollback-item', [AdminAreaController::class, 'rollbackItem']);
$router->get('/admin/reservations', [AdminController::class, 'reservations']);
$router->get('/admin/tables', [AdminController::class, 'tables']);
$router->get('/admin/products', [AdminController::class, 'products']);
$router->get('/admin/categories', [AdminController::class, 'categories']);
$router->get('/admin/tickets', [AdminController::class, 'tickets']);
$router->get('/admin/payments', [AdminController::class, 'payments']);
$router->get('/admin/printers', [AdminController::class, 'printers']);
$router->get('/admin/users', [AdminController::class, 'users']);



// Vistas standalone del personal (POS meseros y KDS cocina)
$router->get('/mapa',        [MapaController::class, 'index']);
$router->get('/api/mapa',    [MapaController::class, 'api']);
$router->post('/api/abrir-ticket',        [MapaController::class, 'abrirTicket']);
$router->post('/api/liberar-reservacion', [MapaController::class, 'liberarReservacion']);
$router->post('/api/cerrar-ticket',       [MapaController::class, 'cerrarTicket']);
$router->post('/api/enviar-comanda',      [MapaController::class, 'enviarComanda']);
$router->get('/api/ticket-items',         [MapaController::class, 'ticketItems']);
$router->post('/api/entregar-item',       [MapaController::class, 'entregarItem']);
$router->post('/api/actualizar-ticket',   [MapaController::class, 'actualizarTicket']);

$router->get('/area/cafe',   [AreaController::class, 'cafe']);
$router->get('/area/jugos',  [AreaController::class, 'jugos']);
$router->get('/area/cocina', [AreaController::class, 'cocina']);
$router->get('/area/horno',  [AreaController::class, 'horno']);
$router->get('/api/area-items',       [AreaController::class, 'areaItems']);
$router->post('/api/avanzar-item',    [AreaController::class, 'avanzarItem']);
$router->post('/api/retroceder-item', [AreaController::class, 'retrocederItem']);

// Feedback de clientes
$router->get('/feedback',      [FeedbackController::class, 'index']);
$router->post('/api/feedback', [FeedbackController::class, 'guardar']);

// Login
$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// Crear Cuenta
$router->get('/registro', [AuthController::class, 'registro']);
$router->post('/registro', [AuthController::class, 'registro']);

// Formulario de olvide mi password
$router->get('/olvide', [AuthController::class, 'olvide']);
$router->post('/olvide', [AuthController::class, 'olvide']);

// Colocar el nuevo password
$router->get('/reestablecer', [AuthController::class, 'reestablecer']);
$router->post('/reestablecer', [AuthController::class, 'reestablecer']);

// Confirmación de Cuenta
$router->get('/mensaje', [AuthController::class, 'mensaje']);
$router->get('/confirmar-cuenta', [AuthController::class, 'confirmar']);

// Leer menu de la base de datos
$router->get('/menu', [MenuController::class, 'index']);




$router->comprobarRutas();
