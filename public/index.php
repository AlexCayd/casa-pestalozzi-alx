<?php

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AuthController;
use Controllers\HomeController;
use Controllers\MapaController;
use Controllers\ReservacionController;
use Controllers\AreaController;

$router = new Router();

// Home
$router->get('/', [HomeController::class, 'index']);

// Reservaciones
$router->post('/reservar', [ReservacionController::class, 'crear']);

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