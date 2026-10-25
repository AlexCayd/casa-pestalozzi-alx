<?php

declare(strict_types=1);

/*
 * Contrato de Roles y permisos (Services\Security\Permisos y RolService).
 *
 * Lo que protege: que toda ruta del panel tenga módulo (una ruta nueva sin
 * mapear sólo la abren los roles de sistema, y eso se tiene que ver aquí, no
 * en una queja), que «ver» sea GET y «editar» todo lo demás, que la vía de
 * acceso salga de los permisos, que nadie dé lo que no tiene y que la base
 * nazca con los cuatro roles.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\Rol;
use Services\Security\Permisos;
use Services\Security\RolService;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$leer = static fn (string $ruta): string => (string) file_get_contents($root . '/' . $ruta);

// 1 · Cada módulo del menú del panel tiene su permiso, con la misma clave.
$adminController = $leer('controllers/AdminController.php');
preg_match("/public const MODULES = \\[(.*?)\\n    \\];/s", $adminController, $bloque);
preg_match_all("/^        '([a-z_]+)' => \\[/m", $bloque[1] ?? '', $claves);
$assert(($claves[1] ?? []) !== [], 'AdminController::MODULES se puede leer.');
foreach ($claves[1] as $clave) {
    $assert(isset(Permisos::MODULOS[$clave]), "El módulo del menú «{$clave}» tiene permiso en Permisos::MODULOS.");
}
$assert(count($claves[1]) === count(Permisos::MODULOS), 'Permisos::MODULOS y el menú tienen los mismos módulos.');

// 2 · Toda ruta /admin registrada tiene módulo (o es compartida/pública).
$index = $leer('public/index.php');
preg_match_all("/\\\$router->(?:get|post|delete)\\('(\\/admin[^']*)'/", $index, $rutas);
$sinMapear = [];
foreach (array_unique($rutas[1]) as $ruta) {
    if (Permisos::clasificar($ruta)['tipo'] === 'solo_sistema') {
        $sinMapear[] = $ruta;
    }
}
$assert($sinMapear === [], 'Rutas del panel sin módulo (sólo las abriría un rol de sistema): ' . implode(', ', $sinMapear));

// 3 · Las APIs del personal: todas con módulo; las públicas, públicas.
foreach (['/api/punto-de-venta', '/api/enviar-comanda', '/api/area-items', '/api/productos', '/api/configuracion/horarios/semanales'] as $api) {
    $assert(Permisos::clasificar($api)['tipo'] === 'modulos', "{$api} exige módulo.");
}
foreach (['/', '/menu', '/api/reservaciones/crear', '/login', '/admin/login'] as $publica) {
    $assert(Permisos::clasificar($publica)['tipo'] === 'publica', "{$publica} es pública.");
}

// 4 · La decisión.
$sistema = ['sistema' => true, 'permisos' => []];
$mesero = ['sistema' => false, 'permisos' => Rol::PERMISOS_POR_DEFECTO['waiter']];
$consultaMenu = ['sistema' => false, 'permisos' => ['menu' => 'ver', 'inventario' => 'editar']];
$decide = static fn (string $url, string $metodo, ?array $rol): string =>
    implode(':', array_filter(Permisos::decidir($url, $metodo, $rol)));

$assert($decide('/admin/menu', 'GET', null) === 'login', 'Sin sesión, al login.');
$assert($decide('/admin/cualquier-cosa', 'POST', $sistema) === 'permitir', 'Un rol de sistema entra a todo, también a lo no mapeado.');
$assert($decide('/admin/cualquier-cosa', 'GET', $consultaMenu) === 'negar:sin_modulo', 'Lo no mapeado queda cerrado para un rol personalizado.');
$assert($decide('/punto-de-venta', 'GET', $mesero) === 'permitir', 'El mesero entra al POS.');
$assert($decide('/api/enviar-comanda', 'POST', $mesero) === 'permitir', 'El mesero escribe en el POS.');
$assert($decide('/admin/menu', 'GET', $mesero) === 'negar:sin_modulo', 'El mesero no entra al panel.');
$assert($decide('/admin', 'GET', $mesero) === 'negar:sin_modulo', 'El inicio del panel es sólo para roles con panel.');
$assert($decide('/admin/menu', 'GET', $consultaMenu) === 'permitir', '«ver» abre las pantallas.');
$assert($decide('/admin/menu/create', 'POST', $consultaMenu) === 'negar:solo_lectura', '«ver» no guarda.');
$assert($decide('/admin/inventario/create', 'POST', $consultaMenu) === 'permitir', '«editar» guarda.');
$assert($decide('/admin/api/buzon/leida', 'POST', $consultaMenu) === 'permitir', 'El buzón es de cualquiera con panel.');
$assert($decide('/admin/api/historial-precios', 'GET', $consultaMenu) === 'permitir', 'El histórico de precios lo abre Menú o Inventario.');
$assert($decide('/admin/reservaciones/operacion', 'GET', $mesero) === 'permitir', 'La dirección vieja de Operación sigue abriéndose con el permiso del POS.');
$assert($decide('/admin/roles', 'GET', $consultaMenu) === 'negar:sin_modulo', 'Roles y permisos es un módulo como los demás.');

// 5 · Vía de acceso y destino.
$assert(Permisos::acceso(true, []) === Permisos::ACCESO_CONTRASENA, 'Los de sistema entran con contraseña.');
$assert(Permisos::acceso(false, ['pdv' => 'editar']) === Permisos::ACCESO_NIP, 'Sólo piso: NIP.');
$assert(Permisos::acceso(false, ['pdv' => 'editar', 'reservations' => 'ver']) === Permisos::ACCESO_CONTRASENA, 'Con un módulo del panel: contraseña.');
$assert(Permisos::acceso(false, []) === Permisos::ACCESO_NINGUNO, 'Sin módulos no entra.');
$assert(Permisos::acceso(false, ['inexistente' => 'editar']) === Permisos::ACCESO_NINGUNO, 'Un módulo fuera del catálogo no cuenta.');
$assert(Permisos::destino(true, []) === '/admin', 'El de sistema aterriza en el panel.');
$assert(Permisos::destino(false, ['area' => 'editar']) === '/area', 'El cocinero, en producción.');
$assert(Permisos::destino(false, ['pdv' => 'ver', 'finanzas' => 'ver']) === '/admin/finanzas', 'Con panel, en su primer módulo del panel.');

// 6 · Nadie da lo que no tiene.
$assert(Permisos::puedeOtorgar(true, [], ['finanzas' => 'editar']), 'El de sistema da cualquier cosa.');
$assert(Permisos::puedeOtorgar(false, ['menu' => 'editar'], ['menu' => 'ver']), 'Se puede dar menos de lo que se tiene.');
$assert(!Permisos::puedeOtorgar(false, ['menu' => 'ver'], ['menu' => 'editar']), 'No se da edición teniendo sólo consulta.');
$assert(!Permisos::puedeOtorgar(false, ['menu' => 'editar'], ['finanzas' => 'ver']), 'No se da un módulo que no se tiene.');

$super = ['slug' => 'super_admin', 'sistema' => true, 'permisos' => Permisos::todos()];
$admin = ['slug' => 'admin', 'sistema' => true, 'permisos' => Permisos::todos()];
$gerente = ['slug' => 'gerente', 'sistema' => false, 'permisos' => ['users' => 'editar', 'roles' => 'editar', 'pdv' => 'editar', 'menu' => 'ver']];
$assert(RolService::puedeAsignar($super, 'admin', Permisos::todos()), 'El super admin asigna Administrador.');
$assert(RolService::puedeAsignar($super, 'super_admin', Permisos::todos()), 'El super admin asigna Super admin.');
$assert(!RolService::puedeAsignar($admin, 'admin', Permisos::todos()), 'El admin no asigna ni edita cuentas de administrador.');
$assert(!RolService::puedeAsignar($admin, 'super_admin', Permisos::todos()), 'El admin no asigna super admin.');
$assert(RolService::puedeAsignar($admin, 'waiter', ['pdv' => 'editar']), 'El admin asigna Mesero.');
$assert(RolService::puedeAsignar($gerente, 'waiter', ['pdv' => 'editar']), 'Un rol con lo necesario asigna Mesero.');
$assert(!RolService::puedeAsignar($gerente, 'contador', ['finanzas' => 'ver']), 'Pero no un rol con módulos que él no tiene.');
$assert(!RolService::puedeGestionar($super, Rol::TIPO_SISTEMA, Permisos::todos()), 'Los roles de sistema no los edita nadie, ni el super admin.');
$assert(RolService::puedeGestionar($admin, Rol::TIPO_PREDEFINIDO, ['pdv' => 'editar']), 'Mesero y Cocinero se editan.');

// 7 · Base 3A: roles existentes, membresías por ámbito y seeds sin identidades de prueba.
$ddl = $leer('database/ddl.sql');
$assert(str_contains($ddl, 'FOREIGN KEY(rol_id) REFERENCES roles(id)'), 'La membresía apunta al rol por identidad.');
$assert(strpos($ddl, 'CREATE TABLE roles') < strpos($ddl, 'CREATE TABLE usuario_unidades'), 'El rol se crea antes que sus membresías.');
$assert(str_contains($ddl, 'PRIMARY KEY(usuario_id,unidad_id)') && str_contains($ddl, 'trg_membresia_rol_insert'), 'La membresía es única por unidad y rechaza roles locales ajenos.');
$deploy = $leer('database/deploy.sql');
foreach (['super_admin', 'admin', 'waiter', 'cook'] as $slug) {
    $assert(preg_match("/\\(\\d+,\\s*'{$slug}',/", $deploy) === 1, "deploy.sql siembra el rol {$slug}.");
}
$assert(strpos($deploy, 'INSERT INTO roles') < strpos($deploy, 'INSERT INTO cartas'), 'Los roles se siembran antes del catálogo por negocio.');
$development=$leer('database/development.sql');
$cuentasDevelopment=preg_match('/INSERT\s+INTO\s+usuarios\(id,username,nombre,password_hash,activo,es_superadmin\)\s+VALUES\s*(.*?);/si',$development,$referencias)===1
    ? preg_match_all("/\('\\d+','(dev_[^']+)','\\[DEV\\][^']+','[^']+','(\\d+)','(\\d+)'\)/i",$referencias[1],$filas,PREG_SET_ORDER)
    : 0;
$nombresCuentas=array_column($filas??[],1);
$cuentasEsperadas=['dev_global_inactivo','dev_restaurante_inactivo','dev_panaderia_inactivo','dev_mesero_1','dev_mesero_2','dev_mesero_3','dev_mesero_inactivo'];
$soloReferencias=$cuentasDevelopment===7 && $nombresCuentas===$cuentasEsperadas
    && !array_filter($filas,static fn(array $fila): bool=>$fila[2]!=='0'||$fila[3]!=='0');
$assert($soloReferencias,'Development conserva las tres identidades de referencia y añade solo cuatro meseros históricos inactivos sin privilegios.');
$bloqueMembresias=preg_match('/INSERT\s+INTO\s+usuario_unidades\([^;]+?VALUES\s*(.*?);/si',$development,$membresias)===1?$membresias[1]:'';
$assert(substr_count($bloqueMembresias,'(')===3 && !preg_match('/\(\s*100[4-7]\s*,/',$bloqueMembresias),'Los meseros históricos no reciben membresías ni acceso a panel o POS.');
$assert(!preg_match("/INSERT\\s+INTO\\s+usuarios[^;]*'admin_demo'/si",$development),'La cuenta conocida se aprovisiona mediante bootstrap y no mediante DML automático.');

// 8 · Nadie decide permisos con el rol escrito a mano.
foreach (['controllers/PuntoVentaController.php', 'controllers/PosReservacionController.php', 'views/punto-de-venta/partials/pos-alta.php', 'views/area/index.php'] as $archivo) {
    $assert(!preg_match("/rol'\\] \\?\\? ''\\) === 'admin'/", $leer($archivo)), "{$archivo} no compara el rol con 'admin' a mano.");
}

fwrite(STDOUT, "Roles y permisos: {$assertions} comprobaciones OK\n");
