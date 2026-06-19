<?php

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AdminController;
use Controllers\AuthController;
use Controllers\HomeController;
use Controllers\ReservacionController;

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


$router->comprobarRutas();
