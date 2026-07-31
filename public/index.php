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
use Controllers\AdminRecetasController;
use Controllers\AdminFinanzasController;
use Controllers\AdminPrintersController;
use Controllers\AdminReservacionController;
use Controllers\ReservacionMantenimientoController;
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
$router->get('/reservaciones', [HomeController::class, 'index']);

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
// Gestión de menú: los platillos viven en `productos` desde la fusión con
// "Productos y recetas". La lista entra directo (ya no hay página de hub).
$router->get('/admin/menu',                     [AdminMenuController::class, 'index']);
$router->get('/admin/menu/pdf',                 [AdminMenuController::class, 'pdf']);
$router->get('/admin/menu/create',              [AdminMenuController::class, 'create']);
$router->post('/admin/menu/create',             [AdminMenuController::class, 'create']);
$router->get('/admin/menu/edit',                [AdminMenuController::class, 'edit']);
$router->post('/admin/menu/edit',               [AdminMenuController::class, 'edit']);
$router->post('/admin/menu/delete',             [AdminMenuController::class, 'delete']);
$router->post('/admin/menu/toggle',             [AdminMenuController::class, 'toggle']);
$router->get('/admin/menu/categorias',          [AdminMenuController::class, 'categories']);
$router->post('/admin/menu/categorias/create',  [AdminMenuController::class, 'categoryCreate']);
$router->get('/admin/menu/categorias/edit',     [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categorias/edit',    [AdminMenuController::class, 'categoryEdit']);
$router->post('/admin/menu/categorias/delete',  [AdminMenuController::class, 'categoryDelete']);
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
$router->get('/admin/api/reservations/disponibilidad', [AdminReservacionController::class, 'disponibilidad']);
$router->post('/admin/reservations/update', [AdminReservacionController::class, 'update']);
$router->post('/admin/reservations/status', [AdminReservacionController::class, 'status']);
$router->post('/admin/reservations/reassign', [AdminReservacionController::class, 'reasignarAutomaticamente']);
$router->get('/admin/reservations/development-tools', [ReservacionMantenimientoController::class, 'index']);
$router->post('/admin/reservations/development-tools/process-expired', [ReservacionMantenimientoController::class, 'procesarPendientes']);
$router->post('/admin/reservations/development-tools/cleanup-preview', [ReservacionMantenimientoController::class, 'vistaPreviaLimpieza']);
$router->post('/admin/reservations/development-tools/cleanup', [ReservacionMantenimientoController::class, 'limpiar']);
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

// Recetas (composición de platillos y subrecetas). Los datos del platillo se
// editan en Gestión de menú; aquí solo se arma lo que consume cada unidad.
$router->get('/admin/recetas',                       [AdminRecetasController::class, 'index']);
$router->get('/admin/recetas/editar',                [AdminRecetasController::class, 'receta']);
$router->post('/admin/recetas/editar',               [AdminRecetasController::class, 'receta']);
$router->get('/admin/recetas/subrecetas',            [AdminRecetasController::class, 'subrecetas']);
$router->get('/admin/recetas/subrecetas/create',     [AdminRecetasController::class, 'subrecetaCreate']);
$router->post('/admin/recetas/subrecetas/create',    [AdminRecetasController::class, 'subrecetaCreate']);
$router->get('/admin/recetas/subrecetas/edit',       [AdminRecetasController::class, 'subrecetaEdit']);
$router->post('/admin/recetas/subrecetas/edit',      [AdminRecetasController::class, 'subrecetaEdit']);
$router->post('/admin/recetas/subrecetas/delete',    [AdminRecetasController::class, 'subrecetaDelete']);

/*
 * Redirecciones 301 de las rutas previas a la fusión menu+productos (2026-07).
 * Se conserva el query string: mucha gente tiene abierto /admin/productos/edit?id=42.
 * Solo GET: un 301 sobre POST lo degrada a GET y pierde el cuerpo, y los POST
 * antiguos venían de vistas que este mismo cambio elimina.
 */
$redir301 = static function (string $destino): callable {
    return static function () use ($destino) {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $destino . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    };
};

$router->get('/admin/menu/items',                 $redir301('/admin/menu'));
$router->get('/admin/menu/items/create',          $redir301('/admin/menu/create'));
$router->get('/admin/menu/items/edit',            $redir301('/admin/menu/edit'));
$router->get('/admin/menu/items/pdf',             $redir301('/admin/menu/pdf'));
$router->get('/admin/menu/categories',            $redir301('/admin/menu/categorias'));
$router->get('/admin/menu/categories/create',     $redir301('/admin/menu/categorias'));
$router->get('/admin/menu/categories/edit',       $redir301('/admin/menu/categorias/edit'));
$router->get('/admin/productos',                  $redir301('/admin/recetas'));
$router->get('/admin/productos/create',           $redir301('/admin/menu/create'));
$router->get('/admin/productos/edit',             $redir301('/admin/recetas/editar'));
$router->get('/admin/productos/subrecetas',        $redir301('/admin/recetas/subrecetas'));
$router->get('/admin/productos/subrecetas/create', $redir301('/admin/recetas/subrecetas/create'));
$router->get('/admin/productos/subrecetas/edit',   $redir301('/admin/recetas/subrecetas/edit'));

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
$router->get('/api/productos',         [PuntoVentaController::class, 'productos']);
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
// Estaba incrustada por error entre las rutas de /admin/menu/items.
$router->post('/api/cancelar-item',       [PuntoVentaController::class, 'cancelarItem']);
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

// Carta pública: JSON para la landing y PDF para el comensal.
// /menu/pdf queda fuera de /admin/, así que Auth::proteger lo deja público.
$router->get('/menu',     [MenuController::class, 'index']);
$router->get('/menu/pdf', [MenuController::class, 'pdf']);




$router->comprobarRutas();
