<?php

/**
 * Registra las rutas publicas, administrativas y API del sitio.
 */

require_once __DIR__ . '/../includes/app.php';

use MVC\Router;
use Controllers\AdminController;
use Controllers\AdminConfigurationController;
use Controllers\AdminHorarioImpactoController;
use Controllers\AdminBuzonController;
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
use Controllers\ScheduleChangeAccessController;
use Controllers\ReservationManagementAccessController;
use Controllers\N8nReservationsController;
use Controllers\MenuController;
use Controllers\ReservacionController;
use Controllers\FeedbackController;
use Controllers\PuntoVentaController;
use Controllers\AreaController;

$router = new Router();

// Protección de rutas por rol: /admin/* exige admin, /punto-de-venta y las APIs
// del POS exigen mesero, /area* y las APIs de producción exigen cocinero. El
// admin pasa por todas. Todo lo demás queda público.
\Classes\Auth::proteger($_SERVER['PATH_INFO'] ?? '/');

// Home
$router->get('/', [HomeController::class, 'index']);
$router->get('/reservaciones', [HomeController::class, 'index']);
$router->get('/reservaciones/gestionar', [ReservationManagementAccessController::class, 'show']);
$router->get('/reservaciones/cambio-horario', [ScheduleChangeAccessController::class, 'show']);

// Reservaciones
$router->get('/api/reservaciones/horarios', [ReservacionController::class, 'horarios']);
$router->get('/api/reservaciones/disponibilidad', [ReservacionController::class, 'disponibilidad']);
$router->post('/api/reservaciones/retencion', [ReservacionController::class, 'retencion']);
$router->post('/api/reservaciones/crear', [ReservacionController::class, 'crearVerificada']);
$router->post('/api/reservaciones/modificar', [ReservacionController::class, 'modificarPublica']);
$router->post('/api/reservaciones/confirmar-modificacion', [ReservacionController::class, 'confirmarModificacion']);
$router->post('/api/reservaciones/cancelar', [ReservacionController::class, 'cancelarPublica']);
$router->post('/api/reservaciones/contacto/codigo', [ReservacionController::class, 'solicitarCodigo']);
$router->post('/api/reservaciones/contacto/verificar', [ReservacionController::class, 'verificarContacto']);
$router->get('/api/reservaciones/mis-reservaciones', [ReservacionController::class, 'misReservaciones']);
$router->post('/api/reservaciones/contacto/logout', [ReservacionController::class, 'logoutContacto']);
$router->post('/api/reservaciones/cambio-horario/disponibilidad', [ScheduleChangeAccessController::class, 'disponibilidad']);
$router->get('/api/reservaciones/cambio-horario/disponibilidad', [ScheduleChangeAccessController::class, 'disponibilidad']);
$router->post('/api/reservaciones/cambio-horario/modificar', [ScheduleChangeAccessController::class, 'modificar']);
$router->post('/api/reservaciones/cambio-horario/cancelar', [ScheduleChangeAccessController::class, 'cancelar']);
$router->get('/api/reservaciones/gestionar/disponibilidad', [ReservationManagementAccessController::class, 'disponibilidad']);
$router->post('/api/reservaciones/gestionar/disponibilidad', [ReservationManagementAccessController::class, 'disponibilidad']);
$router->post('/api/reservaciones/gestionar/modificar', [ReservationManagementAccessController::class, 'modificar']);
$router->post('/api/reservaciones/gestionar/cancelar', [ReservationManagementAccessController::class, 'cancelar']);
$router->post('/api/integraciones/n8n/reservaciones/recordatorios/preparar', [N8nReservationsController::class, 'prepararRecordatorios']);
$router->post('/api/integraciones/n8n/reservaciones/notificacion-resultado', [N8nReservationsController::class, 'notificacionResultado']);

// Admin
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/configuracion', [AdminConfigurationController::class, 'index']);
$router->get('/admin/configuracion/horarios', [AdminConfigurationController::class, 'hours']);
$router->post('/admin/configuracion/horarios', [AdminConfigurationController::class, 'guardarHorarios']);
$router->get('/admin/configuracion/reservaciones', [AdminConfigurationController::class, 'reservations']);
$router->post('/admin/configuracion/reservaciones', [AdminConfigurationController::class, 'guardarReservaciones']);
$router->post('/admin/configuracion/horarios/excepciones/guardar', [AdminConfigurationController::class, 'guardarExcepcion']);
$router->post('/admin/configuracion/horarios/excepciones/estado', [AdminConfigurationController::class, 'cambiarEstadoExcepcion']);
$router->post('/admin/configuracion/horarios/excepciones/eliminar', [AdminConfigurationController::class, 'eliminarExcepcion']);
$router->get('/api/configuracion/horarios/semanales', [AdminConfigurationController::class, 'apiObtenerHorarios']);
$router->post('/api/configuracion/horarios/semanales', [AdminConfigurationController::class, 'apiGuardarHorarios']);
$router->post('/api/configuracion/horarios/especiales', [AdminConfigurationController::class, 'apiGuardarEspecial']);
$router->post('/api/configuracion/horarios/excepciones', [AdminConfigurationController::class, 'apiGuardarExcepcion']);
$router->post('/api/configuracion/horarios/excepciones/estado', [AdminConfigurationController::class, 'apiCambiarEstadoExcepcion']);
$router->delete('/api/configuracion/horarios/excepciones', [AdminConfigurationController::class, 'apiEliminarExcepcion']);
$router->get('/admin/api/horarios-impactos', [AdminHorarioImpactoController::class, 'show']);
$router->post('/admin/api/horarios-impactos/preparar', [AdminHorarioImpactoController::class, 'notify']);
$router->post('/admin/api/horarios-impactos/contacto', [AdminHorarioImpactoController::class, 'addContact']);
$router->post('/admin/api/horarios-impactos/atender-manual', [AdminHorarioImpactoController::class, 'attendManual']);
$router->post('/admin/api/horarios-impactos/acceso-prueba', [AdminHorarioImpactoController::class, 'testLink']);
$router->get('/admin/api/buzon/resumen', [AdminBuzonController::class, 'resumen']);
$router->post('/admin/api/buzon/sincronizar', [AdminBuzonController::class, 'sincronizar']);
$router->get('/admin/api/buzon', [AdminBuzonController::class, 'listar']);
$router->post('/admin/api/buzon/leida', [AdminBuzonController::class, 'marcarLeida']);
$router->get('/admin/configuracion/anuncio', [AdminConfigurationController::class, 'announcement']);
$router->post('/admin/configuracion/anuncio', [AdminConfigurationController::class, 'guardarAnuncio']);
$router->get('/admin/configuracion/pos', [AdminConfigurationController::class, 'pos']);
$router->post('/admin/configuracion/pos', [AdminConfigurationController::class, 'guardarPos']);
$router->get('/admin/configuracion/reportes', [AdminConfigurationController::class, 'reports']);
$router->post('/admin/configuracion/reportes/estado', [AdminConfigurationController::class, 'reportStatus']);
// Envío del modal "Reportar un problema" del panel.
$router->post('/admin/api/reportes', [AdminConfigurationController::class, 'crearReporte']);
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
$router->get('/admin/reservaciones', [AdminReservacionController::class, 'index']);
$router->get('/admin/reservaciones/crear', [AdminReservacionController::class, 'create']);
$router->post('/admin/reservaciones/crear', [AdminReservacionController::class, 'store']);
$router->get('/admin/reservaciones/operacion', [ReservacionOperacionController::class, 'operation']);
$router->get('/admin/reservaciones/detalle', [AdminReservacionController::class, 'show']);
$router->get('/admin/api/reservaciones/disponibilidad', [AdminReservacionController::class, 'disponibilidad']);
$router->post('/admin/reservaciones/actualizar', [AdminReservacionController::class, 'update']);
$router->post('/admin/reservaciones/estado', [AdminReservacionController::class, 'status']);
$router->post('/admin/reservaciones/reasignar', [AdminReservacionController::class, 'reasignarAutomaticamente']);
$router->get('/admin/reservaciones/herramientas-desarrollo', [ReservacionMantenimientoController::class, 'index']);
$router->post('/admin/reservaciones/herramientas-desarrollo/procesar-vencidas', [ReservacionMantenimientoController::class, 'procesarPendientes']);
$router->get('/admin/api/reservaciones/operacion', [ReservacionOperacionController::class, 'operationData']);
$router->post('/admin/api/reservaciones/operacion/crear', [ReservacionOperacionController::class, 'createFromOperation']);
$router->get('/admin/api/reservaciones/operacion/disponibilidad', [ReservacionOperacionController::class, 'availability']);
$router->post('/admin/api/reservaciones/operacion/asignar-mesas', [ReservacionOperacionController::class, 'apiAssignTables']);
$router->post('/admin/api/reservaciones/operacion/liberar-mesas', [ReservacionOperacionController::class, 'apiClearTables']);
$router->post('/admin/api/reservaciones/operacion/reasignar', [ReservacionOperacionController::class, 'apiReasignarAutomaticamente']);
$router->post('/admin/api/reservaciones/operacion/comentario', [ReservacionOperacionController::class, 'apiUpdateComment']);
$router->post('/admin/api/reservaciones/operacion/estado', [ReservacionOperacionController::class, 'apiStatus']);
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
$router->post('/admin/inventario/merma',    [AdminInventarioController::class, 'merma']);
// Proveedores: submódulo de inventario. Cuelgan de los ingredientes, que es lo
// que se compra; un platillo se produce aquí.
$router->get('/admin/inventario/proveedores',         [AdminInventarioController::class, 'proveedores']);
$router->get('/admin/inventario/proveedores/create',  [AdminInventarioController::class, 'proveedorCreate']);
$router->post('/admin/inventario/proveedores/create', [AdminInventarioController::class, 'proveedorCreate']);
$router->get('/admin/inventario/proveedores/edit',    [AdminInventarioController::class, 'proveedorEdit']);
$router->post('/admin/inventario/proveedores/edit',   [AdminInventarioController::class, 'proveedorEdit']);
$router->post('/admin/inventario/proveedores/delete', [AdminInventarioController::class, 'proveedorDelete']);
// Histórico de precios: lo consultan las fichas de ingrediente y de platillo.
$router->get('/admin/api/historial-precios', [AdminInventarioController::class, 'historialPrecios']);

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

/*
 * Rutas de reservaciones en inglés (2026-08). El resto del panel estaba en
 * español y este módulo se había quedado atrás. Solo GET, por lo mismo que
 * arriba: un 301 sobre POST lo degrada a GET y pierde el cuerpo. Las APIs no
 * llevan redirección: sus únicos clientes son los bundles de este repo, que se
 * recompilan con las rutas nuevas.
 */
$router->get('/admin/reservations',                  $redir301('/admin/reservaciones'));
$router->get('/admin/reservations/create',           $redir301('/admin/reservaciones/crear'));
$router->get('/admin/reservations/operation',        $redir301('/admin/reservaciones/operacion'));
$router->get('/admin/reservations/show',             $redir301('/admin/reservaciones/detalle'));
$router->get('/admin/reservations/development-tools', $redir301('/admin/reservaciones/herramientas-desarrollo'));

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
// Los administradores cambian contraseña; el personal de piso sólo puede
// recibir un NIP nuevo generado por el sistema desde la ficha del usuario.
$router->get('/admin/usuarios/cambiar-password', [AdminUsersController::class, 'cambiarPassword']);
$router->post('/admin/usuarios/cambiar-password', [AdminUsersController::class, 'cambiarPassword']);
$router->post('/admin/usuarios/regenerar-nip', [AdminUsersController::class, 'regenerarNip']);
$router->post('/admin/usuarios/deactivate', [AdminUsersController::class, 'deactivate']);
$router->post('/admin/usuarios/activate', [AdminUsersController::class, 'activate']);
$router->post('/admin/usuarios/delete', [AdminUsersController::class, 'delete']);



// Vistas standalone del personal (POS meseros y KDS cocina)
$router->get('/punto-de-venta',        [PuntoVentaController::class, 'index']);
$router->get('/api/punto-de-venta',    [PuntoVentaController::class, 'api']);
$router->get('/api/productos',         [PuntoVentaController::class, 'productos']);
$router->get('/api/punto-de-venta/reservaciones', [PuntoVentaController::class, 'reservaciones']);
$router->get('/api/punto-de-venta/mesa-contexto', [PuntoVentaController::class, 'mesaContexto']);
$router->post('/api/punto-de-venta/reservaciones/comenzar', [PuntoVentaController::class, 'comenzarReservacion']);
$router->post('/api/punto-de-venta/reservaciones/cancelar', [PuntoVentaController::class, 'cancelarReservacion']);
$router->post('/api/punto-de-venta/reservaciones/no-show', [PuntoVentaController::class, 'noShowReservacion']);
$router->post('/api/abrir-ticket',        [PuntoVentaController::class, 'abrirTicket']);
$router->post('/api/cerrar-ticket',       [PuntoVentaController::class, 'cerrarTicket']);
$router->post('/api/enviar-comanda',      [PuntoVentaController::class, 'enviarComanda']);
$router->get('/api/ticket-items',         [PuntoVentaController::class, 'ticketItems']);
$router->get('/api/corte-caja',           [PuntoVentaController::class, 'corteCaja']);
$router->post('/api/entregar-item',       [PuntoVentaController::class, 'entregarItem']);
// Estaba incrustada por error entre las rutas de /admin/menu/items.
$router->post('/api/cancelar-item',       [PuntoVentaController::class, 'cancelarItem']);
$router->post('/api/actualizar-ticket',   [PuntoVentaController::class, 'actualizarTicket']);
$router->post('/api/sugerencias',         [PuntoVentaController::class, 'sugerencias']);

$router->get('/area',        [AreaController::class, 'index']);
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

// Carta pública: JSON para la landing y PDF para el comensal.
// /menu/pdf queda fuera de /admin/, así que Auth::proteger lo deja público.
$router->get('/menu',     [MenuController::class, 'index']);
$router->get('/menu/pdf', [MenuController::class, 'pdf']);




$router->comprobarRutas();
