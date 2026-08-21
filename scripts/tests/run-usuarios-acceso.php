<?php

/** Contratos rápidos del acceso de usuarios, sin depender de una BD local. */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Services\NipService;

function comprobar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

$_ENV['NIP_LOOKUP_SECRET'] = 'secret-only-for-tests';
putenv('NIP_LOOKUP_SECRET=secret-only-for-tests');

$candidatos = ['1234', '1234', '5678'];
NipService::usarGenerador(static function () use (&$candidatos): string {
    return array_shift($candidatos) ?? '9999';
});

$ocupados = [NipService::lookup('1234') => true];
$credencial = NipService::generarCredencialDisponible(
    static fn (string $lookup): bool => isset($ocupados[$lookup])
);

comprobar($credencial['nip'] === '5678', 'La colisión controlada no reintentó el candidato.');
comprobar((bool) preg_match('/^\d{4}$/', $credencial['nip']), 'El NIP no conserva cuatro dígitos.');
comprobar(password_verify('5678', $credencial['hash']), 'El hash de prueba no verifica el NIP.');
comprobar(
    hash_equals(
        hash_hmac('sha256', '5678', 'secret-only-for-tests'),
        $credencial['lookup']
    ),
    'El lookup no es el HMAC esperado.'
);

$ddl = file_get_contents($root . '/database/ddl.sql');
$modelo = file_get_contents($root . '/models/Usuario.php');
$formulario = file_get_contents($root . '/views/admin/users/form.php');
$javascript = file_get_contents($root . '/src/js/admin/users/users-form.js');
$estilos = file_get_contents($root . '/src/scss/admin/modules/users.scss');
$confirmation = file_get_contents($root . '/src/js/components/confirmation-modal.js');
$configuracion = file_get_contents($root . '/services/UsuarioConfig.php');
$servicio = file_get_contents($root . '/services/UsuarioService.php');
$login = file_get_contents($root . '/models/Usuario.php');
$controlador = file_get_contents($root . '/controllers/AdminUsersController.php');
$authController = file_get_contents($root . '/controllers/AuthController.php');
$routes = file_get_contents($root . '/public/index.php');
$edicion = file_get_contents($root . '/views/admin/users/edit.php');
$alta = file_get_contents($root . '/views/admin/users/create.php');
$cambioPassword = file_get_contents($root . '/views/admin/users/change-password.php');
$listado = file_get_contents($root . '/views/admin/users/index.php');
$credenciales = file_get_contents($root . '/docs/usuarios/credenciales.md');
$usuariosDocs = file_get_contents($root . '/docs/usuarios/usuarios.md');

comprobar(str_contains($ddl, 'nip_lookup'), 'El DDL no define nip_lookup.');
comprobar(str_contains($ddl, 'uq_usuarios_nip_lookup'), 'Falta la restricción UNIQUE de nip_lookup.');
comprobar(str_contains($ddl, 'chk_usuarios_admin_sin_nip'), 'Falta la regla de BD para admins sin NIP.');
comprobar(!str_contains($modelo, 'fecha_nacimiento'), 'El modelo aún contiene el campo retirado.');
comprobar(!str_contains($formulario, 'name="nip"'), 'El formulario administrativo acepta NIP manual.');
comprobar(!str_contains($formulario, 'nip_confirm'), 'El formulario administrativo conserva confirmación manual.');
comprobar(!str_contains($javascript, 'nip-disponible'), 'El frontend aún consulta disponibilidad manual.');
comprobar(str_contains($servicio, 'NipService::MAX_INTENTOS'), 'Faltan reintentos acotados en mutaciones.');
comprobar(str_contains($login, 'WHERE nip_lookup = ?'), 'El login no hace lookup directo.');
comprobar(str_contains($javascript, 'title: "Regenerar NIP"'), 'La regeneración no exige confirmación visible.');
comprobar(str_contains($javascript, 'form.submit()'), 'La regeneración no tiene envío confirmado.');
comprobar(str_contains($javascript, 'navigator.clipboard.writeText'), 'La entrega no usa Clipboard API.');
comprobar(str_contains($javascript, 'copyWithFallback'), 'La entrega no tiene fallback de portapapeles.');
comprobar(str_contains($javascript, 'textContent = isError ? "Copiar NIP" : "Copiado"'), 'La copia no muestra feedback temporal en el botón.');
comprobar(str_contains($javascript, 'closeBehavior: "non_cancelable"'), 'La entrega permite cerrar accidentalmente el modal.');
comprobar(str_contains($javascript, 'secondaryCloses: false'), 'Copiar NIP no está configurado como acción no cerrable.');
comprobar(str_contains($configuracion, 'NIP_MODAL_VISIBILIDAD_SEGUNDOS'), 'Falta la configuración canónica de visibilidad del NIP.');
comprobar(str_contains($alta, 'data-nip-visibility-seconds'), 'El alta no expone la ventana de visibilidad del NIP.');
comprobar(str_contains($edicion, 'data-nip-visibility-seconds'), 'La edición no expone la ventana de visibilidad del NIP.');
comprobar(str_contains($javascript, 'data-nip-visibility-seconds'), 'El frontend no consume la configuración de visibilidad.');
comprobar(str_contains($javascript, 'visibilitySeconds * 1000'), 'La entrega no programa el cierre automático.');
comprobar(str_contains($javascript, 'state.nip = null'), 'El cierre no limpia el NIP del estado JavaScript.');
comprobar(str_contains($javascript, 'progress.classList.add("is-running")'), 'La entrega no anima la barra de progreso discreta.');
comprobar(!str_contains($javascript, 'aria-live'), 'La entrega no debe anunciar un conteo regresivo cambiante.');
comprobar(!str_contains($javascript, '10000'), 'La duración del modal está duplicada como literal en JavaScript.');
comprobar(str_contains($javascript, 'data-role-nip-section'), 'Falta la visibilidad dinámica de la credencial por rol.');
comprobar(str_contains($controlador, 'Cache-Control: no-store'), 'La respuesta que entrega NIP no desactiva cache.');
comprobar(str_contains($controlador, '/admin/usuarios/create?resultado=creado'), 'El alta no usa PRG sobre la pantalla de entrega.');
comprobar(str_contains($controlador, '/admin/usuarios/edit?id='), 'La regeneración no vuelve a la edición.');
comprobar(str_contains($confirmation, 'secondaryCloses === false'), 'El modal global no admite una acción secundaria no cerrable.');
comprobar(str_contains($controlador, 'AdminCsrfService::validar'), 'La regeneración no valida CSRF.');
comprobar(str_contains($edicion, 'name="admin_csrf"'), 'La edición no entrega token CSRF para regenerar.');
comprobar(substr_count($controlador, 'if (!self::adminCsrfValido())') >= 7, 'Todas las mutaciones de usuarios exigen CSRF y sesión admin.');
comprobar(str_contains($formulario, 'name="admin_csrf"'), 'Alta y edición no entregan token CSRF.');
comprobar(str_contains($listado, 'name="admin_csrf"'), 'Activación, desactivación y eliminación no entregan token CSRF.');
comprobar(str_contains($cambioPassword, 'name="admin_csrf"'), 'Cambio de contraseña no entrega token CSRF.');
foreach (['/registro', '/olvide', '/reestablecer', '/mensaje', '/confirmar-cuenta'] as $rutaLegacy) {
    comprobar(!str_contains($routes, $rutaLegacy), "La ruta legacy {$rutaLegacy} sigue registrada.");
}
foreach (['registro', 'olvide', 'reestablecer', 'mensaje', 'confirmar'] as $metodoLegacy) {
    comprobar(!str_contains($authController, 'function ' . $metodoLegacy), "El método legacy {$metodoLegacy} sigue en AuthController.");
}
comprobar(!str_contains($listado, 'admin-users-nip-once'), 'El listado aún conserva la tarjeta de NIP.');
comprobar(!str_contains($listado, 'data-nip-once-value'), 'El listado aún contiene un valor NIP plano.');
comprobar(str_contains($formulario, 'data-role-nip-section'), 'La edición no expone el bloque de acceso de piso.');
comprobar(str_contains($formulario, 'data-has-persisted-nip'), 'La edición no entrega el estado persistido al frontend.');
comprobar(str_contains($formulario, 'data-user-access-status-grid'), 'Falta la subrejilla compartida de acceso y estado.');
comprobar(str_contains($formulario, 'Acceso de piso'), 'Falta el título del bloque de acceso de piso.');
comprobar(str_contains($formulario, 'Regenerar NIP'), 'Falta la acción explícita de regeneración.');
comprobar(str_contains($formulario, 'data-role-nip-hint'), 'Falta la descripción del acceso de piso.');
comprobar(str_contains($formulario, 'data-role-nip-pending-description'), 'Falta el aviso de generación futura del NIP.');
comprobar(str_contains($javascript, 'admin-users-access-status-grid--single'), 'El frontend no adapta la rejilla cuando se oculta el acceso.');
comprobar(str_contains($javascript, 'admin-user-nip-modal__progress'), 'El modal no expone el track de expiración.');
comprobar(str_contains($estilos, 'transform: scaleX(0)'), 'La barra activa no tiene una transformación visible.');
comprobar(str_contains($estilos, 'admin-users-access-status-card'), 'Faltan estilos compartidos para acceso y estado.');
comprobar(str_contains($estilos, 'admin-user-nip-modal__progress-bar'), 'Falta la capa activa de la barra de expiración.');
comprobar(str_contains($estilos, 'height: 6px'), 'El track de expiración conserva una altura imperceptible.');
comprobar(!str_contains($formulario, 'admin-users-nip-line'), 'La interfaz conserva la disposición lineal anterior.');
comprobar(file_exists($root . '/docs/usuarios/usuarios.md'), 'Falta la documentación canónica de usuarios.');
comprobar(file_exists($root . '/docs/usuarios/credenciales.md'), 'Falta la documentación de credenciales de desarrollo.');
comprobar(!file_exists($root . '/database/CREDENCIALES.md'), 'La copia antigua de credenciales no fue retirada.');
comprobar(!file_exists($root . '/docs/usuarios_acceso.md'), 'La documentación duplicada de acceso no fue retirada.');
comprobar(str_contains($usuariosDocs, 'NIP_LOOKUP_SECRET'), 'La documentación canónica no explica el secreto de lookup.');
comprobar(str_contains($credenciales, 'seed-usuarios-prueba.php'), 'Las credenciales de desarrollo no explican el seed.');
comprobar(!preg_match('/INSERT\s+INTO\s+usuarios/i', $ddl), 'El DDL contiene registros de usuarios.');

NipService::usarGenerador(null);
echo "OK: contratos de acceso de usuarios\n";
