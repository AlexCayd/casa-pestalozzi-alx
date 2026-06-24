<?php

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AdminController;
use Controllers\AuthController;
use Controllers\HomeController;
use Controllers\MenuController;
use Controllers\ReservacionController;
use Controllers\DashboardController;
use Controllers\MapaController;
use Controllers\AreaController;
use Controllers\FeedbackController;

$router = new Router();

// Home
$router->get('/', [HomeController::class, 'index']);

// Reservaciones
$router->post('/reservar', [ReservacionController::class, 'crear']);

// Admin
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/reservations', [AdminController::class, 'reservations']);
$router->get('/admin/tables', [AdminController::class, 'tables']);
$router->get('/admin/products', [AdminController::class, 'products']);
$router->get('/admin/categories', [AdminController::class, 'categories']);
$router->get('/admin/tickets', [AdminController::class, 'tickets']);
$router->get('/admin/payments', [AdminController::class, 'payments']);
$router->get('/admin/printers', [AdminController::class, 'printers']);
$router->get('/admin/users', [AdminController::class, 'users']);
// Mapa de mesas (herramienta interna)
$router->get('/mapa',     [MapaController::class, 'index']);
$router->get('/api/mapa', [MapaController::class, 'api']);
$router->post('/api/abrir-ticket',        [MapaController::class, 'abrirTicket']);
$router->post('/api/liberar-reservacion', [MapaController::class, 'liberarReservacion']);
$router->post('/api/cerrar-ticket',       [MapaController::class, 'cerrarTicket']);
$router->post('/api/enviar-comanda',      [MapaController::class, 'enviarComanda']);
$router->get('/api/ticket-items',         [MapaController::class, 'ticketItems']);
$router->post('/api/entregar-item',       [MapaController::class, 'entregarItem']);
$router->post('/api/actualizar-ticket',   [MapaController::class, 'actualizarTicket']);

// Áreas de producción (KDS)
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

// Rutas del dashboard
$router->get('/dashboard', [DashboardController::class, 'index']);

// CRUD Categorías
$router->get('/dashboard/categorias/crear', [DashboardController::class, 'categoriaCrear']);
$router->post('/dashboard/categorias/crear', [DashboardController::class, 'categoriaCrear']);
$router->get('/dashboard/categorias/editar', [DashboardController::class, 'categoriaEditar']);
$router->post('/dashboard/categorias/editar', [DashboardController::class, 'categoriaEditar']);
$router->post('/dashboard/categorias/eliminar', [DashboardController::class, 'categoriaEliminar']);

// CRUD Platillos (Menú)
$router->get('/dashboard/menu/crear', [DashboardController::class, 'menuCrear']);
$router->post('/dashboard/menu/crear', [DashboardController::class, 'menuCrear']);
$router->get('/dashboard/menu/editar', [DashboardController::class, 'menuEditar']);
$router->post('/dashboard/menu/editar', [DashboardController::class, 'menuEditar']);
$router->post('/dashboard/menu/eliminar', [DashboardController::class, 'menuEliminar']);

$router->comprobarRutas();
