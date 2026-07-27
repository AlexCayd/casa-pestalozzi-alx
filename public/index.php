<?php

/**
 * Registra las rutas publicas, administrativas y API del sitio.
 */

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AdminController;
use Controllers\AdminConfigurationController;
use Controllers\AdminAreaController;
use Controllers\AdminPuntoVentaController;
use Controllers\AdminMenuController;
use Controllers\AdminInventarioController;
use Controllers\AdminProductosController;
use Controllers\AdminFinanzasController;
use Controllers\AdminPrintersController;
use Controllers\AdminReservacionController;
use Controllers\ReservacionOperacionController;
use Controllers\AdminUsersController;
use Controllers\AuthController;
use Controllers\HomeController;
use Controllers\MenuController;
use Controllers\ReservacionController;
use Controllers\FeedbackController;
use Controllers\PuntoVentaController;
use Controllers\AreaController;

$router = new Router();

// Protección de rutas: /admin/* exige rol admin (login con contraseña en
// /admin/login); /punto-de-venta, /area/* y las APIs del personal exigen sesión
// iniciada (login por NIP en /login, solo personal de piso).
\Classes\Auth::proteger($_SERVER['PATH_INFO'] ?? '/');

// Home
$router->get('/', [HomeController::class, 'index']);

// Reservaciones
$router->get('/api/reservation-schedules', [ReservacionController::class, 'horarios']);
$router->get('/api/reservaciones/disponibilidad', [ReservacionController::class, 'disponibilidad']);
$router->get('/api/operacion/horario-efectivo', [ReservacionController::class, 'horarioEfectivo']);
$router->post('/api/reservaciones/retencion', [ReservacionController::class, 'retencion']);
$router->post('/api/reservaciones/crear', [ReservacionController::class, 'crearVerificada']);
$router->post('/api/reservaciones/modificar', [ReservacionController::class, 'modificarPublica']);
$router->post('/api/reservaciones/cancelar', [ReservacionController::class, 'cancelarPublica']);
$router->post('/api/reservaciones/contacto/codigo', [ReservacionController::class, 'solicitarCodigo']);
$router->post('/api/reservaciones/contacto/verificar', [ReservacionController::class, 'verificarContacto']);
$router->get('/api/reservaciones/mis-reservaciones', [ReservacionController::class, 'misReservaciones']);
$router->post('/api/reservaciones/contacto/logout', [ReservacionController::class, 'logoutContacto']);

// Admin
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/configuracion', [AdminConfigurationController::class, 'index']);
$router->get('/admin/configuracion/horarios', [AdminConfigurationController::class, 'hours']);
$router->post('/admin/configuracion/horarios', [AdminConfigurationController::class, 'guardarHorarios']);
$router->post('/admin/configuracion/horarios/excepciones/guardar', [AdminConfigurationController::class, 'guardarExcepcion']);
$router->post('/admin/configuracion/horarios/excepciones/estado', [AdminConfigurationController::class, 'cambiarEstadoExcepcion']);
$router->post('/admin/configuracion/horarios/excepciones/eliminar', [AdminConfigurationController::class, 'eliminarExcepcion']);
$router->get('/api/configuracion/horarios/semanales', [AdminConfigurationController::class, 'apiObtenerHorarios']);
$router->post('/api/configuracion/horarios/semanales', [AdminConfigurationController::class, 'apiGuardarHorarios']);
$router->post('/api/configuracion/horarios/especiales', [AdminConfigurationController::class, 'apiGuardarEspecial']);
$router->post('/api/configuracion/horarios/excepciones', [AdminConfigurationController::class, 'apiGuardarExcepcion']);
$router->delete('/api/configuracion/horarios/excepciones', [AdminConfigurationController::class, 'apiEliminarExcepcion']);
$router->get('/admin/configuracion/anuncio', [AdminConfigurationController::class, 'announcement']);
$router->post('/admin/configuracion/anuncio', [AdminConfigurationController::class, 'guardarAnuncio']);
$router->get('/admin/configuracion/reportes', [AdminConfigurationController::class, 'reports']);
$router->get('/admin/menu', [AdminMenuController::class, 'index']);
$router->get('/admin/menu/categories', [AdminMenuController::class, 'categories']);
$router->get('/admin/menu/categories/create', [AdminMenuController::class, 'categoryCreate']);
$router->post('/admin/menu/categories/create', [AdminMenuController::class, 'categoryCreate']);
$router->get('/admin/menu/categories/edit', [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categories/edit', [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categories/delete', [AdminMenuController::class, 'categoryDelete']);
$router->get('/admin/menu/items', [AdminMenuController::class, 'items']);
$router->get('/admin/menu/items/pdf', [AdminMenuController::class, 'itemsPdf']);
$router->get('/admin/menu/items/create', [AdminMenuController::class, 'itemCreate']);
$router->post('/admin/menu/items/create', [AdminMenuController::class, 'itemCreate']);
$router->get('/admin/menu/items/edit', [AdminMenuController::class, 'itemEdit']);
$router->post('/api/cancelar-item',       [PuntoVentaController::class, 'cancelarItem']);
$router->post('/admin/menu/items/edit', [AdminMenuController::class, 'itemEdit']);
$router->post('/admin/menu/items/delete', [AdminMenuController::class, 'itemDelete']);
$router->get('/admin/punto-de-venta', [AdminPuntoVentaController::class, 'index']);
$router->get('/admin/area', [AdminAreaController::class, 'index']);
$router->get('/admin/area/cafe', [AdminAreaController::class, 'cafe']);
$router->get('/admin/area/jugos', [AdminAreaController::class, 'jugos']);
$router->get('/admin/area/cocina', [AdminAreaController::class, 'cocina']);
$router->get('/admin/area/horno', [AdminAreaController::class, 'horno']);
$router->get('/admin/api/area-items', [AdminAreaController::class, 'areaItems']);
$router->post('/admin/api/advance-item', [AdminAreaController::class, 'advanceItem']);
$router->post('/admin/api/rollback-item', [AdminAreaController::class, 'rollbackItem']);
$router->get('/admin/reservations', [AdminReservacionController::class, 'index']);
$router->get('/admin/reservations/create', [AdminReservacionController::class, 'create']);
$router->post('/admin/reservations/create', [AdminReservacionController::class, 'store']);
$router->get('/admin/reservations/operation', [ReservacionOperacionController::class, 'operation']);
$router->get('/admin/reservations/show', [AdminReservacionController::class, 'show']);
$router->post('/admin/reservations/update', [AdminReservacionController::class, 'update']);
$router->post('/admin/reservations/status', [AdminReservacionController::class, 'status']);
$router->post('/admin/reservations/reassign', [AdminReservacionController::class, 'reasignarAutomaticamente']);
$router->get('/admin/api/reservations/operation', [ReservacionOperacionController::class, 'operationData']);
$router->post('/admin/api/reservations/operation/assign-tables', [ReservacionOperacionController::class, 'apiAssignTables']);
$router->post('/admin/api/reservations/operation/reassign', [ReservacionOperacionController::class, 'apiReasignarAutomaticamente']);
$router->post('/admin/api/reservations/operation/update-comment', [ReservacionOperacionController::class, 'apiUpdateComment']);
$router->post('/admin/api/reservations/operation/status', [ReservacionOperacionController::class, 'apiStatus']);
$router->post('/admin/reservations/operation/assign-tables', [ReservacionOperacionController::class, 'assignTables']);
$router->post('/admin/reservations/operation/update-comment', [ReservacionOperacionController::class, 'updateComment']);
$router->get('/admin/feedback', [AdminController::class, 'feedback']);
$router->post('/admin/feedback/refresh', [AdminController::class, 'feedbackRefresh']);
$router->get('/admin/api/feedback-areas', [AdminController::class, 'feedbackAreas']);
$router->get('/admin/tickets', [AdminController::class, 'tickets']);

// Inventario (Ingredientes)
$router->get('/admin/inventario',           [AdminInventarioController::class, 'index']);
$router->get('/admin/inventario/create',    [AdminInventarioController::class, 'create']);
$router->post('/admin/inventario/create',   [AdminInventarioController::class, 'create']);
$router->get('/admin/inventario/edit',      [AdminInventarioController::class, 'edit']);
$router->post('/admin/inventario/edit',     [AdminInventarioController::class, 'edit']);
$router->post('/admin/inventario/delete',   [AdminInventarioController::class, 'delete']);
$router->post('/admin/inventario/ajustar',  [AdminInventarioController::class, 'ajustar']);
$router->post('/admin/inventario/entrada',  [AdminInventarioController::class, 'entrada']);

// Productos (recetas y subrecetas)
$router->get('/admin/productos',                     [AdminProductosController::class, 'index']);
$router->get('/admin/productos/create',              [AdminProductosController::class, 'create']);
$router->post('/admin/productos/create',             [AdminProductosController::class, 'create']);
$router->get('/admin/productos/edit',                [AdminProductosController::class, 'edit']);
$router->post('/admin/productos/edit',               [AdminProductosController::class, 'edit']);
$router->post('/admin/productos/delete',             [AdminProductosController::class, 'delete']);
$router->get('/admin/productos/subrecetas',          [AdminProductosController::class, 'subrecetas']);
$router->get('/admin/productos/subrecetas/create',   [AdminProductosController::class, 'subrecetaCreate']);
$router->post('/admin/productos/subrecetas/create',  [AdminProductosController::class, 'subrecetaCreate']);
$router->get('/admin/productos/subrecetas/edit',     [AdminProductosController::class, 'subrecetaEdit']);
$router->post('/admin/productos/subrecetas/edit',    [AdminProductosController::class, 'subrecetaEdit']);
$router->post('/admin/productos/subrecetas/delete',  [AdminProductosController::class, 'subrecetaDelete']);

// Finanzas
$router->get('/admin/finanzas',                  [AdminFinanzasController::class, 'index']);
$router->post('/admin/finanzas/gasto/guardar',   [AdminFinanzasController::class, 'guardarGasto']);
$router->post('/admin/finanzas/gasto/eliminar',  [AdminFinanzasController::class, 'eliminarGasto']);

$router->get('/admin/printers',          [AdminPrintersController::class, 'index']);
$router->get('/admin/printers/create',   [AdminPrintersController::class, 'create']);
$router->post('/admin/printers/create',  [AdminPrintersController::class, 'create']);
$router->get('/admin/printers/edit',     [AdminPrintersController::class, 'edit']);
$router->post('/admin/printers/edit',    [AdminPrintersController::class, 'edit']);
$router->post('/admin/printers/delete',  [AdminPrintersController::class, 'delete']);
$router->post('/admin/printers/test',    [AdminPrintersController::class, 'test']);

$router->get('/admin/usuarios', [AdminUsersController::class, 'index']);
$router->get('/admin/usuarios/create', [AdminUsersController::class, 'userCreate']);
$router->post('/admin/usuarios/create', [AdminUsersController::class, 'userCreate']);
$router->get('/admin/usuarios/edit', [AdminUsersController::class, 'userEdit']);
$router->post('/admin/usuarios/edit', [AdminUsersController::class, 'userEdit']);
$router->get('/admin/usuarios/change-password', [AdminUsersController::class, 'changePassword']);
$router->post('/admin/usuarios/change-password', [AdminUsersController::class, 'changePassword']);
$router->post('/admin/usuarios/deactivate', [AdminUsersController::class, 'deactivate']);
$router->post('/admin/usuarios/activate', [AdminUsersController::class, 'activate']);
$router->post('/admin/usuarios/delete', [AdminUsersController::class, 'delete']);



// Vistas standalone del personal (POS meseros y KDS cocina)
$router->get('/punto-de-venta',        [PuntoVentaController::class, 'index']);
$router->get('/api/punto-de-venta',    [PuntoVentaController::class, 'api']);
$router->get('/api/punto-de-venta/reservaciones', [PuntoVentaController::class, 'reservaciones']);
$router->get('/api/punto-de-venta/mesa-contexto', [PuntoVentaController::class, 'mesaContexto']);
$router->post('/api/punto-de-venta/reservaciones/llegada', [PuntoVentaController::class, 'llegada']);
$router->post('/api/punto-de-venta/reservaciones/comenzar', [PuntoVentaController::class, 'comenzarReservacion']);
$router->post('/api/punto-de-venta/reservaciones/cancelar', [PuntoVentaController::class, 'cancelarReservacion']);
$router->post('/api/punto-de-venta/reservaciones/no-show', [PuntoVentaController::class, 'noShowReservacion']);
$router->post('/api/abrir-ticket',        [PuntoVentaController::class, 'abrirTicket']);
$router->post('/api/liberar-reservacion', [PuntoVentaController::class, 'liberarReservacion']);
$router->post('/api/cerrar-ticket',       [PuntoVentaController::class, 'cerrarTicket']);
$router->post('/api/enviar-comanda',      [PuntoVentaController::class, 'enviarComanda']);
$router->get('/api/ticket-items',         [PuntoVentaController::class, 'ticketItems']);
$router->get('/api/corte-caja',           [PuntoVentaController::class, 'corteCaja']);
$router->post('/api/entregar-item',       [PuntoVentaController::class, 'entregarItem']);
$router->post('/api/actualizar-ticket',   [PuntoVentaController::class, 'actualizarTicket']);
$router->post('/api/sugerencias',         [PuntoVentaController::class, 'sugerencias']);

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
$router->post('/api/feedback-n8n', [FeedbackController::class, 'recibirN8n']);

// Login: NIP para personal de piso, usuario+contraseña para el admin
$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/admin/login', [AuthController::class, 'loginAdmin']);
$router->post('/admin/login', [AuthController::class, 'loginAdmin']);
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
