<?php
declare(strict_types=1);

/**
 * Pruebas de integración para autorización multinegocio 3B.
 * Valida --host/--base/--entorno contra la configuración, y solo crea/borra
 * bases aleatorias con marca propia. Nunca selecciona DB_NAME configurada.
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__ . '/fixture-3b.php';

use MenuPreservacion\Comun;
use Multinegocio\SqlArchivo;
use Model\ActiveRecord;
use Model\Usuario;
use Services\Security\PermisosUsuario;
use Services\Users\NipService;
use Services\Users\UsuarioService;
use Classes\Auth;

function test3bOpciones(array $args): array
{
    $opciones = [];
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--solo-nucleo') {
            if (isset($opciones['solo_nucleo'])) throw new RuntimeException('Opción duplicada: --solo-nucleo.');
            $opciones['solo_nucleo'] = true;
            continue;
        }
        if (!preg_match('/^--(host|base|entorno)$/D', $args[$i], $m)
            || !isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--') || isset($opciones[$m[1]])) {
            throw new RuntimeException('Uso: --host HOST --base BASE --entorno development|test [--solo-nucleo]');
        }
        $opciones[$m[1]] = $args[++$i];
    }
    foreach (['host','base','entorno'] as $key) {
        if (!isset($opciones[$key]) || $opciones[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    return $opciones;
}

function test3bAssert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function test3bSqlFail(mysqli $db, callable $action, array $expectedCodes, string $message, int &$count): void
{
    $db->begin_transaction();
    try { $action(); }
    catch (mysqli_sql_exception $e) {
        $db->rollback();
        test3bAssert(in_array($e->getCode(), $expectedCodes, true), "$message: código SQL inesperado {$e->getCode()}.", $count);
        return;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $db->rollback();
    throw new RuntimeException("$message: la escritura fue aceptada.");
}

function test3bIdent(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Identificador SQL inesperado.');
    return '`' . $name . '`';
}

function test3bCrearBase(mysqli $admin, string $root, array &$bases): array
{
    $name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) throw new RuntimeException('Nombre de base temporal inválido.');
    $admin->query('CREATE DATABASE ' . test3bIdent($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bases[] = [$name, $token];
    $admin->select_db($name);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
    SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');
    return [$name, $token];
}

function test3bPuedeBorrar(mysqli $admin, string $name, string $token, string $baseOrigen): bool
{
    if ($name === $baseOrigen || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) return false;
    $found = $admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($name) . "'")->fetch_row();
    if (!$found) return false;
    $admin->select_db($name);
    try {
        $rows = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) { return false; }
}

function test3bUnidadId(mysqli $db, string $slug): int
{
    $stmt = $db->prepare('SELECT id FROM unidades WHERE slug=?');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException("No existe unidad fixture $slug.");
    return (int)$row['id'];
}

function test3bCore(mysqli $db, int &$count): array
{
    ActiveRecord::setDB($db);
    $version = $db->query("SELECT version FROM esquema_versiones WHERE version LIKE 'multinegocio-3b-%' LIMIT 1")->fetch_row();
    test3bAssert($version !== null, 'Falta versión de esquema 3B.', $count);
    $cols = $db->query("SHOW COLUMNS FROM usuario_unidades LIKE 'rol_id'")->fetch_assoc();
    test3bAssert(is_array($cols) && strtoupper((string)$cols['Null']) === 'YES', 'rol_id debe ser metadata nullable en las membresías.', $count);
    test3bAssert((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='usuario_unidad_permisos'")->fetch_row()[0] === 1, 'Falta usuario_unidad_permisos.', $count);

    $rest = FixtureAutorizacion3B::usuario($db, 'rest', [
        'restaurante' => ['permisos' => ['menu' => 'editar', 'users' => 'ver', 'tickets' => 'ver', 'pdv' => 'operar', 'reservations_map' => 'operar']],
    ]);
    $pan = FixtureAutorizacion3B::usuario($db, 'pan', [
        'panaderia' => ['permisos' => ['menu' => 'ver', 'inventario' => 'editar']],
    ]);
    $mix = FixtureAutorizacion3B::usuario($db, 'mix', [
        'restaurante' => ['permisos' => ['menu' => 'ver', 'pdv' => 'operar']],
        'panaderia' => ['permisos' => ['finanzas' => 'editar']],
    ]);
    $global = FixtureAutorizacion3B::usuario($db, 'global', [
        'global' => ['permisos' => ['users' => 'editar', 'menu' => 'editar'], 'facultades' => ['usuarios.gestionar','permisos.delegar']],
    ]);
    $roleTrap = FixtureAutorizacion3B::usuario($db, 'role_trap', [
        'restaurante' => ['permisos' => []],
    ]);
    $inactive = FixtureAutorizacion3B::usuario($db, 'inactive', [
        'restaurante' => ['activo' => false, 'permisos' => ['menu' => 'editar']],
    ]);
    $super = FixtureAutorizacion3B::usuario($db, 'super', [], true);
    $floorOnlyKds = FixtureAutorizacion3B::usuario($db, 'kds', [
        'restaurante' => ['permisos' => ['pdv' => 'operar', 'area' => 'operar', 'reservations_map' => 'operar']],
    ]);
    $floorOnlyKds['nip'] = FixtureAutorizacion3B::asignarNip($db, $floorOnlyKds['id']);
    $kdsOnly = FixtureAutorizacion3B::usuario($db, 'kds_only', [
        'restaurante' => ['permisos' => ['area' => 'operar']],
    ]);
    $mapOnly = FixtureAutorizacion3B::usuario($db, 'map_only', [
        'restaurante' => ['permisos' => ['reservations_map' => 'operar']],
    ]);
    $mapOnly['nip'] = FixtureAutorizacion3B::asignarNip($db, $mapOnly['id']);

    $ticketRest = $db->query("INSERT INTO tickets(unidad_id,unidad_tipo) VALUES (2,'negocio')");
    $ticketRestId = (int)$db->insert_id;
    $db->query("INSERT INTO tickets(unidad_id,unidad_tipo) VALUES (3,'negocio')");
    $ticketPanId = (int)$db->insert_id;

    // Roles heredados pueden coexistir como metadata, pero no ampliar permisos directos.
    $systemRole = $db->query("SELECT id FROM roles WHERE tipo='sistema' ORDER BY id LIMIT 1")->fetch_row();
    if (!$systemRole) throw new RuntimeException('DDL 3B debe conservar roles de sistema como metadata existente.');
    $roleId = (int)$systemRole[0];
    $stmt = $db->prepare('UPDATE usuario_unidades SET rol_id=? WHERE usuario_id=?');
    $stmt->bind_param('ii', $roleId, $roleTrap['id']);
    $stmt->execute();
    $stmt->close();
    $db->query("INSERT INTO rol_permisos(rol_id,modulo,nivel) VALUES ($roleId,'qa_role_only','editar') ON DUPLICATE KEY UPDATE nivel='editar'");

    $profileRest = PermisosUsuario::cargar($rest['id'], $db);
    test3bAssert(is_array($profileRest) && ($profileRest['activo'] ?? false) && isset($profileRest['ambitos']['restaurante']), 'El perfil debe resolver la membresía activa de Restaurante.', $count);
    test3bAssert(PermisosUsuario::tipoAcceso($profileRest) === 'contrasena', 'Un permiso de panel requiere contraseña aunque exista un módulo de piso.', $count);
    test3bAssert(PermisosUsuario::permitido($profileRest, 'restaurante', 'menu', 'ver'), 'Edición incluye lectura.', $count);
    test3bAssert(PermisosUsuario::permitido($profileRest, 'restaurante', 'menu', 'editar'), 'Permiso directo de edición concedido.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileRest, 'restaurante', 'menu', 'operar'), 'Nivel panel no se convierte a operativo.', $count);
    test3bAssert(PermisosUsuario::permitido($profileRest, 'restaurante', 'pdv', 'operar'), 'Permiso operativo de POS concedido.', $count);
    test3bAssert(PermisosUsuario::permitido($profileRest, 'restaurante', 'reservations_map', 'operar'), 'Mapa operativo tiene permiso propio.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileRest, 'restaurante', 'reservations', 'ver'), 'Mapa operativo no concede administración de Reservaciones.', $count);
    $pinBypass = PermisosUsuario::decidir($profileRest, 'restaurante', 'pdv', 'operar', 'nip');
    test3bAssert(($pinBypass['ok'] ?? false) === false && ($pinBypass['codigo'] ?? '') === 'NIVEL_AUTH_REQUERIDO', 'PIN no habilita sesión si la unión de permisos requiere contraseña.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileRest, 'panaderia', 'menu', 'ver'), 'No hay acceso a una unidad sin membresía.', $count);

    $restUnit = test3bUnidadId($db, 'restaurante');
    $panUnit = test3bUnidadId($db, 'panaderia');
    $restUserId = (int)$rest['id'];
    $globalUserId = (int)$global['id'];
    test3bSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($restUserId,$restUnit,'menu','ver')"), [1062], 'Permiso directo duplicado', $count);
    test3bSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($globalUserId,$restUnit,'menu','ver')"), [1452], 'Permiso sin membresía correspondiente', $count);
    test3bSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($restUserId,$restUnit,'pdv','ver')"), [3819], 'El nivel de módulo operativo debe ser operar', $count);
    $panUserId = (int)$pan['id'];
    test3bSqlFail($db, static fn() => $db->query("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES ($panUserId,$panUnit,'pdv','operar')"), [1644], 'Módulo operativo no habilitado en Panadería', $count);

    $profilePan = PermisosUsuario::cargar($pan['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profilePan) === 'contrasena', 'Lectura administrativa en Panadería requiere contraseña.', $count);
    test3bAssert(PermisosUsuario::permitido($profilePan, 'panaderia', 'menu', 'ver'), 'Lectura autorizada en Panadería.', $count);
    test3bAssert(!PermisosUsuario::permitido($profilePan, 'panaderia', 'menu', 'editar'), 'Lectura no autoriza edición.', $count);
    test3bAssert(!PermisosUsuario::permitido($profilePan, 'restaurante', 'inventario', 'editar'), 'Permisos de Panadería no se cruzan a Restaurante.', $count);

    $profileMix = PermisosUsuario::cargar($mix['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileMix) === 'contrasena', 'Permiso administrativo de cualquier ámbito requiere contraseña.', $count);
    test3bAssert(PermisosUsuario::permitido($profileMix, 'restaurante', 'pdv', 'operar'), 'Acceso combinado conserva POS.', $count);
    test3bAssert(PermisosUsuario::permitido($profileMix, 'panaderia', 'finanzas', 'editar'), 'Acceso combinado conserva Finanzas de Panadería.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileMix, 'global', 'users', 'ver'), 'Membresía en negocios no implica Global.', $count);

    $profileGlobal = PermisosUsuario::cargar($global['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileGlobal) === 'contrasena', 'Permiso Global de panel requiere contraseña.', $count);
    test3bAssert(PermisosUsuario::permitido($profileGlobal, 'global', 'users', 'editar'), 'Permiso Global directo concedido.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileGlobal, 'restaurante', 'users', 'editar'), 'Membresía Global no concede acceso al ámbito Restaurante.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileGlobal, 'global', 'inventario', 'ver'), 'Global solo concede los módulos asignados.', $count);

    $profileRoleTrap = PermisosUsuario::cargar($roleTrap['id'], $db);
    test3bAssert(!PermisosUsuario::permitido($profileRoleTrap, 'restaurante', 'qa_role_only', 'editar'), 'rol_permisos no es fuente implícita de acceso.', $count);
    test3bAssert(PermisosUsuario::tipoAcceso($profileRoleTrap) === 'ninguno', 'Rol con permisos ocultos no impone tipo de autenticación.', $count);

    $profileKds = PermisosUsuario::cargar($floorOnlyKds['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileKds) === 'nip', 'Solo KDS puede autenticarse con NIP.', $count);
    test3bAssert(PermisosUsuario::permitido($profileKds, 'restaurante', 'pdv', 'operar') && PermisosUsuario::permitido($profileKds, 'restaurante', 'reservations_map', 'operar'), 'Una cuenta puede reunir varios módulos operativos bajo NIP.', $count);
    test3bAssert(PermisosUsuario::permitido($profileKds, 'restaurante', 'area', 'operar'), 'El permiso interno de Producción habilita KDS.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileKds, 'restaurante', 'users', 'ver'), 'KDS no concede acceso administrativo.', $count);
    $kdsDecision = PermisosUsuario::decidir($profileKds, 'restaurante', 'area', 'operar', 'nip');
    test3bAssert(($kdsDecision['ok'] ?? false) === true, 'NIP permite operación KDS cuando no hay permisos administrativos.', $count);
    $profileKdsOnly = PermisosUsuario::cargar($kdsOnly['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileKdsOnly) === 'nip' && PermisosUsuario::permitido($profileKdsOnly, 'restaurante', 'area', 'operar'), 'La cuenta exclusivamente KDS utiliza NIP.', $count);
    $profileMapOnly = PermisosUsuario::cargar($mapOnly['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileMapOnly) === 'nip' && PermisosUsuario::permitido($profileMapOnly, 'restaurante', 'reservations_map', 'operar'), 'La cuenta exclusivamente de mapa operativo utiliza NIP.', $count);
    test3bAssert(!PermisosUsuario::permitido($profileMapOnly, 'restaurante', 'reservations', 'ver'), 'La cuenta del mapa no entra en administración de Reservaciones.', $count);
    $profileSuper = PermisosUsuario::cargar($super['id'], $db);
    test3bAssert(PermisosUsuario::tipoAcceso($profileSuper) === 'contrasena', 'Superadministrador requiere contraseña.', $count);
    $profileInactive = PermisosUsuario::cargar($inactive['id'], $db);
    test3bAssert(!PermisosUsuario::permitido($profileInactive, 'restaurante', 'menu', 'ver'), 'Membresía inactiva no se incluye en permisos.', $count);

    test3bAssert(PermisosUsuario::recurso($profileRest, 'restaurante', 'tickets', $ticketRestId, 'ver'), 'El ticket propio del ámbito es visible.', $count);
    test3bAssert(!PermisosUsuario::recurso($profileRest, 'restaurante', 'tickets', $ticketPanId, 'ver'), 'El ticket de otro negocio se deniega aunque su ID sea conocido.', $count);
    $decision = PermisosUsuario::decidir($profilePan, 'restaurante', 'menu', 'ver', 'contrasena');
    test3bAssert(($decision['ok'] ?? true) === false, 'La decisión de otro ámbito debe denegar acceso.', $count);

    return compact('rest','pan','mix','global','roleTrap','inactive','super','floorOnlyKds','kdsOnly','mapOnly','ticketRestId','ticketPanId');
}

function test3bGestion(mysqli $db, array $fixtures, int &$count): void
{
    $actorId = (int)$fixtures['global']['id'];
    ActiveRecord::setDB($db);
    $onlyRest = FixtureAutorizacion3B::usuario($db, 'local_manager', [
        'restaurante' => ['permisos' => ['users' => 'editar', 'menu' => 'editar', 'pdv' => 'operar'], 'facultades' => ['usuarios.gestionar','permisos.delegar']],
    ]);
    $onlyPan = FixtureAutorizacion3B::usuario($db, 'pan_manager', [
        'panaderia' => ['permisos' => ['users' => 'editar', 'menu' => 'ver'], 'facultades' => ['usuarios.gestionar','permisos.delegar']],
    ]);

    $list = UsuarioService::listarDirectos($actorId, 'global');
    test3bAssert(is_array($list) && ($list['ok'] ?? false) === true, 'Global autorizado puede listar usuarios de sus ámbitos.', $count);
    $own = UsuarioService::detalleDirecto($actorId, 'global', (int)$fixtures['rest']['id']);
    test3bAssert(is_array($own) && ($own['ok'] ?? false) === true, 'Global autorizado puede consultar usuarios de Restaurante.', $count);
    test3bAssert(!array_key_exists('password_hash', $own['usuario'] ?? []) && !array_key_exists('nip_hash', $own['usuario'] ?? []), 'Detalle nunca expone hashes de credenciales.', $count);

    $newUsername = 'qa3b_shared_' . bin2hex(random_bytes(3));
    $created = UsuarioService::guardarDirecto($actorId, 'global', null, [
        'username' => $newUsername, 'nombre' => 'Cuenta compartida de prueba', 'activo' => true,
        'password' => 'fixture-password-3b', 'password_confirm' => 'fixture-password-3b',
        'ambitos' => [
            'global' => ['activo' => true, 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []],
        ],
    ]);
    test3bAssert(($created['ok'] ?? false) === true && isset($created['usuario']['id']), 'Global puede crear una cuenta con varios ámbitos delegados.', $count);
    if (empty($created['usuario']['id'])) throw new RuntimeException('La creación Global no devolvió id de usuario.');
    $createdId = (int)$created['usuario']['id'];
    $createdProfile = PermisosUsuario::cargar($createdId, $db);
    test3bAssert(isset($createdProfile['ambitos']['global'], $createdProfile['ambitos']['restaurante'], $createdProfile['ambitos']['panaderia']), 'Global puede crear una identidad con acceso de Global y ambos negocios.', $count);
    test3bAssert(($createdProfile['ambitos']['global']['permisos']['users'] ?? '') === 'ver'
        && ($createdProfile['ambitos']['global']['permisos']['menu'] ?? '') === 'ver', 'Los permisos Global se mantienen limitados a los módulos concedidos.', $count);
    test3bAssert(($createdProfile['ambitos']['restaurante']['permisos']['menu'] ?? '') === 'editar'
        && ($createdProfile['ambitos']['panaderia']['permisos']['menu'] ?? '') === 'ver', 'Los permisos se guardan separados por negocio.', $count);
    test3bAssert(Usuario::porCredenciales($newUsername, 'fixture-password-3b') !== null, 'La credencial administrativa creada permite inicio de sesión.', $count);
    $beforeCredential = $db->query("SELECT password_hash,nip_hash,nip_lookup FROM usuarios WHERE id=$createdId")->fetch_assoc();

    $localUpdate = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $createdId, [
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($localUpdate['ok'] ?? false) === true, 'El administrador local puede editar su permiso en una cuenta multiunidad.', $count);
    $afterLocal = PermisosUsuario::cargar($createdId, $db);
    test3bAssert(($afterLocal['ambitos']['restaurante']['permisos']['menu'] ?? '') === 'ver'
        && ($afterLocal['ambitos']['panaderia']['permisos']['menu'] ?? '') === 'ver', 'La edición local no altera permisos del otro negocio.', $count);
    $afterCredential = $db->query("SELECT password_hash,nip_hash,nip_lookup FROM usuarios WHERE id=$createdId")->fetch_assoc();
    test3bAssert($beforeCredential === $afterCredential, 'Editar permisos conserva credenciales cuando no se solicitó cambio.', $count);

    $identityBefore = $db->query("SELECT username,nombre,activo FROM usuarios WHERE id=$createdId")->fetch_assoc();
    $localIdentityEscape = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $createdId, [
        'username' => $newUsername . '_x', 'nombre' => 'Nombre alterado desde negocio',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($localIdentityEscape['ok'] ?? true) === false, 'Un administrador local no puede cambiar identidad compartida.', $count);
    test3bAssert($identityBefore === $db->query("SELECT username,nombre,activo FROM usuarios WHERE id=$createdId")->fetch_assoc(), 'El rechazo conserva username, nombre y estado global.', $count);
    $foreignFields = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $createdId, [
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => [], 'rol_id' => 1]],
    ]);
    test3bAssert(($foreignFields['ok'] ?? true) === false, 'El CRUD rechaza metadata de rol oculta enviada por formulario.', $count);

    $localGlobalEscape = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $createdId, [
        'ambitos' => [
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => false, 'permisos' => [], 'facultades' => []],
        ],
    ]);
    test3bAssert(($localGlobalEscape['ok'] ?? true) === false, 'Un administrador local no puede revocar membresías ajenas.', $count);
    $globalUpdate = UsuarioService::guardarDirecto($actorId, 'global', $createdId, [
        'ambitos' => [
            'global' => ['activo' => true, 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
        ],
    ]);
    test3bAssert(($globalUpdate['ok'] ?? false) === true, 'Global puede editar los permisos de ambos negocios.', $count);

    $forbidden = UsuarioService::guardarDirecto($onlyRest['id'], 'restaurante', null, [
        'username' => 'qa3b_local_escape', 'nombre' => 'Escape local', 'activo' => true,
        'password' => 'fixture-password', 'password_confirm' => 'fixture-password',
        'ambitos' => ['panaderia' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($forbidden['ok'] ?? true) === false, 'Administrador local no puede crear acceso Panadería.', $count);
    test3bAssert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE username='qa3b_local_escape'")->fetch_row()[0] === 0, 'Rechazo de escalamiento no inserta identidad.', $count);

    $panUserName = 'qa3b_pan_read_' . bin2hex(random_bytes(3));
    $panCreated = UsuarioService::guardarDirecto((int)$onlyPan['id'], 'panaderia', null, [
        'username' => $panUserName, 'nombre' => 'Solo lectura Panadería', 'activo' => true,
        'password' => 'fixture-password-pan', 'password_confirm' => 'fixture-password-pan',
        'ambitos' => ['panaderia' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($panCreated['ok'] ?? false) === true, 'Panadería puede crear usuarios con permisos de su propio ámbito.', $count);
    $panUpgrade = UsuarioService::guardarDirecto((int)$onlyPan['id'], 'panaderia', (int)($panCreated['usuario']['id'] ?? 0), [
        'ambitos' => ['panaderia' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []]],
    ]);
    test3bAssert(($panUpgrade['ok'] ?? true) === false, 'Un administrador no puede delegar por encima de su nivel de lectura.', $count);
    test3bAssert((PermisosUsuario::cargar((int)$panCreated['usuario']['id'], $db)['ambitos']['panaderia']['permisos']['menu'] ?? '') === 'ver', 'El rechazo de escalamiento deja el permiso de lectura sin cambios.', $count);

    $floorName = 'qa3b_floor_' . bin2hex(random_bytes(3));
    $floorCreated = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', null, [
        'username' => $floorName, 'nombre' => 'Piso restaurante', 'activo' => true,
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['pdv' => 'operar'], 'facultades' => []]],
    ]);
    test3bAssert(($floorCreated['ok'] ?? false) === true && isset($floorCreated['nip']), 'Cuenta solo POS se crea con NIP de un solo uso.', $count);
    test3bAssert(($floorCreated['no_store'] ?? false) === true, 'La respuesta de creación NIP indica no-store.', $count);
    $floorId = (int)($floorCreated['usuario']['id'] ?? 0);
    $nipBefore = $db->query("SELECT nip_hash,nip_lookup FROM usuarios WHERE id=$floorId")->fetch_assoc();
    $floorNoChange = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $floorId, [
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['pdv' => 'operar'], 'facultades' => []]],
    ]);
    test3bAssert(($floorNoChange['ok'] ?? false) === true && !isset($floorNoChange['nip']), 'Guardar sin cambios no vuelve a revelar ni regenerar el NIP.', $count);
    test3bAssert($nipBefore === $db->query("SELECT nip_hash,nip_lookup FROM usuarios WHERE id=$floorId")->fetch_assoc(), 'La edición sin cambios conserva el NIP existente.', $count);

    $floorPromotion = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $floorId, [
        'password' => 'promoted-password', 'password_confirm' => 'promoted-password', 'admin_password' => 'fixture-pass-3b',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($floorPromotion['ok'] ?? false) === true, 'Promover una cuenta NIP a panel exige y acepta contraseña nueva con reautenticación.', $count);
    test3bAssert($db->query("SELECT nip_hash FROM usuarios WHERE id=$floorId")->fetch_row()[0] === null, 'La promoción elimina la credencial PIN anterior.', $count);
    test3bAssert(Usuario::porCredenciales($floorName, 'promoted-password') !== null && Usuario::porNip((string)$floorCreated['nip']) === null, 'La cuenta promovida solo conserva login administrativo.', $count);

    $localAdminName = 'qa3b_admin_' . bin2hex(random_bytes(3));
    $localAdmin = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', null, [
        'username' => $localAdminName, 'nombre' => 'Admin de prueba local', 'activo' => true,
        'password' => 'initial-local-password', 'password_confirm' => 'initial-local-password',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($localAdmin['ok'] ?? false) === true, 'Restaurante puede crear una cuenta administrativa de su propio ámbito.', $count);
    $localAdminId = (int)($localAdmin['usuario']['id'] ?? 0);
    $passwordNoReauth = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $localAdminId, [
        'password' => 'changed-local-password', 'password_confirm' => 'changed-local-password',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($passwordNoReauth['ok'] ?? true) === false, 'Cambiar contraseña local requiere reautenticación.', $count);
    $passwordWrongReauth = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $localAdminId, [
        'password' => 'changed-local-password', 'password_confirm' => 'changed-local-password', 'admin_password' => 'incorrect-password',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($passwordWrongReauth['ok'] ?? true) === false, 'Una contraseña de actor incorrecta no autoriza el cambio de credencial.', $count);
    $passwordWithReauth = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $localAdminId, [
        'password' => 'changed-local-password', 'password_confirm' => 'changed-local-password', 'admin_password' => 'fixture-pass-3b',
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($passwordWithReauth['ok'] ?? false) === true, 'La contraseña se cambia con reautenticación válida del actor.', $count);
    test3bAssert(Usuario::porCredenciales($localAdminName, 'changed-local-password') !== null, 'La credencial actualizada permite login.', $count);
    $localDegrade = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', $localAdminId, [
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['pdv' => 'operar'], 'facultades' => []]],
    ]);
    test3bAssert(($localDegrade['ok'] ?? false) === true && isset($localDegrade['nip']), 'Degradar de panel a POS genera un NIP nuevo.', $count);
    test3bAssert($db->query("SELECT nip_hash FROM usuarios WHERE id=$localAdminId")->fetch_row()[0] !== null
        && Usuario::porCredenciales($localAdminName, 'changed-local-password') === null
        && Usuario::porNip((string)$localDegrade['nip']) !== null, 'La degradación retira el login por contraseña y habilita solo el PIN.', $count);

    $passwordChangeNoReauth = UsuarioService::guardarDirecto($actorId, 'global', $createdId, [
        'username' => $newUsername, 'nombre' => 'Cuenta compartida de prueba', 'activo' => true,
        'password' => 'another-password', 'password_confirm' => 'another-password',
        'ambitos' => [
            'global' => ['activo' => true, 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
        ],
    ]);
    test3bAssert(($passwordChangeNoReauth['ok'] ?? true) === false, 'Cambiar contraseña requiere reautenticación del actor.', $count);
    $passwordWrongReauthGlobal = UsuarioService::guardarDirecto($actorId, 'global', $createdId, [
        'username' => $newUsername, 'nombre' => 'Cuenta compartida de prueba', 'activo' => true,
        'password' => 'another-password', 'password_confirm' => 'another-password', 'admin_password' => 'incorrect-password',
        'ambitos' => [
            'global' => ['activo' => true, 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
        ],
    ]);
    test3bAssert(($passwordWrongReauthGlobal['ok'] ?? true) === false, 'La reautenticación Global exige contraseña válida del actor.', $count);
    $passwordChange = UsuarioService::guardarDirecto($actorId, 'global', $createdId, [
        'username' => $newUsername, 'nombre' => 'Cuenta compartida de prueba', 'activo' => true,
        'password' => 'another-password', 'password_confirm' => 'another-password', 'admin_password' => 'fixture-pass-3b',
        'ambitos' => [
            'global' => ['activo' => true, 'permisos' => ['users' => 'ver', 'menu' => 'ver'], 'facultades' => []],
            'restaurante' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
            'panaderia' => ['activo' => true, 'permisos' => ['menu' => 'editar'], 'facultades' => []],
        ],
    ]);
    test3bAssert(($passwordChange['ok'] ?? false) === true, 'Global puede cambiar credenciales con reautenticación.', $count);
    test3bAssert(Usuario::porCredenciales($newUsername, 'another-password') !== null, 'La contraseña actualizada queda activa.', $count);

    $escalationFields = UsuarioService::guardarDirecto((int)$onlyRest['id'], 'restaurante', null, [
        'username' => 'qa3b_super_forgery', 'nombre' => 'Forged super flag', 'activo' => true,
        'password' => 'fixture-password', 'password_confirm' => 'fixture-password', 'es_superadmin' => true,
        'ambitos' => ['restaurante' => ['activo' => true, 'permisos' => ['menu' => 'ver'], 'facultades' => []]],
    ]);
    test3bAssert(($escalationFields['ok'] ?? true) === false, 'El CRUD rechaza campos de superadministrador enviados por cliente.', $count);
    test3bAssert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE username='qa3b_super_forgery'")->fetch_row()[0] === 0, 'Un intento de fijar es_superadmin no crea cuenta.', $count);

    $lastSuper = UsuarioService::estadoDirecto($actorId, 'global', (int)$fixtures['super']['id'], false);
    test3bAssert(($lastSuper['ok'] ?? true) === false && ($lastSuper['codigo'] ?? '') === 'ADMIN_ACTIVO_REQUERIDO', 'No se puede desactivar el último superadministrador activo.', $count);
    test3bAssert((int)$db->query("SELECT activo FROM usuarios WHERE id=" . (int)$fixtures['super']['id'])->fetch_row()[0] === 1, 'El último superadministrador sigue activo después del rechazo.', $count);
    $deleteLastSuper = UsuarioService::eliminarDirecto($actorId, 'global', (int)$fixtures['super']['id']);
    test3bAssert(($deleteLastSuper['ok'] ?? true) === false, 'No se puede eliminar el último superadministrador activo.', $count);
    test3bAssert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE id=" . (int)$fixtures['super']['id'])->fetch_row()[0] === 1, 'El último superadministrador sigue presente después del rechazo.', $count);

    if (class_exists(NipService::class)) {
        test3bAssert(NipService::secretoConfigurado(), 'Runner configura HMAC aleatorio solo para fixtures temporales.', $count);
    }
}

function test3bAuth(mysqli $db, array $fixtures, int &$count): void
{
    test3bAssert(Auth::multinegocio(), 'Auth selecciona el flujo directo por versión del esquema 3B.', $count);
    $kds = $fixtures['floorOnlyKds'];
    $nipUser = Usuario::porNip((string)$kds['nip']);
    test3bAssert($nipUser !== null && (int)$nipUser->id === (int)$kds['id'], 'El login por NIP encuentra una cuenta operativa.', $count);
    if (!$nipUser) throw new RuntimeException('No se pudo cargar fixture NIP para probar Auth.');
    Auth::login($nipUser, 'nip');
    test3bAssert(Auth::check(), 'Una cuenta operativa puede iniciar sesión con NIP.', $count);
    test3bAssert(Auth::contextos() === ['restaurante'], 'La cuenta operativa solo recibe el contexto Restaurante autorizado.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'pdv', 'operar')['ok'] === true, 'Auth autoriza operación POS por sesión NIP.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'area', 'operar')['ok'] === true, 'Auth autoriza KDS por sesión NIP.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'reservations_map', 'operar')['ok'] === true, 'Auth autoriza el mapa operativo por sesión NIP.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'reservations', 'ver')['ok'] === false, 'Auth no confunde el mapa con administración de Reservaciones.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'users', 'ver')['ok'] === false, 'Una sesión NIP no entra a páginas administrativas.', $count);

    // Cambio de permisos mientras la sesión permanece autenticada con PIN.
    $cred = $db->prepare('SELECT nip_hash,nip_lookup FROM usuarios WHERE id=?');
    $kdsId = (int)$kds['id'];
    $cred->bind_param('i', $kdsId);
    $cred->execute();
    $pinRow = $cred->get_result()->fetch_assoc();
    $cred->close();
    $db->query("UPDATE usuarios SET nip_hash=NULL,nip_lookup=NULL WHERE id=$kdsId");
    FixtureAutorizacion3B::permiso($db, $kdsId, 'restaurante', 'menu', 'ver');
    $refreshed = Auth::refrescar();
    test3bAssert(is_array($refreshed) && PermisosUsuario::tipoAcceso($refreshed) === 'contrasena', 'El cambio del permiso se refleja al refrescar perfil.', $count);
    $promotion = Auth::evaluar('restaurante', 'pdv', 'operar');
    test3bAssert(($promotion['ok'] ?? true) === false && ($promotion['codigo'] ?? '') === 'NIVEL_AUTH_REQUERIDO', 'Una sesión NIP no amplía su nivel al promoverse a panel.', $count);
    FixtureAutorizacion3B::permiso($db, $kdsId, 'restaurante', 'menu', null);
    $stmt = $db->prepare('UPDATE usuarios SET nip_hash=?,nip_lookup=? WHERE id=?');
    $stmt->bind_param('ssi', $pinRow['nip_hash'], $pinRow['nip_lookup'], $kdsId);
    $stmt->execute();
    $stmt->close();
    test3bAssert(Auth::evaluar('restaurante', 'pdv', 'operar')['ok'] === true, 'Al retirar la promoción, los permisos de piso vuelven a evaluarse dentro de la misma sesión.', $count);

    FixtureAutorizacion3B::permiso($db, $kdsId, 'restaurante', 'area', null);
    $revoked = Auth::evaluar('restaurante', 'area', 'operar');
    test3bAssert(($revoked['ok'] ?? true) === false && ($revoked['codigo'] ?? '') === 'PERMISO_DENEGADO', 'La revocación entra en vigor al siguiente chequeo protegido.', $count);
    FixtureAutorizacion3B::membresiaActiva($db, $kdsId, 'restaurante', false);
    test3bAssert(Auth::evaluar('restaurante', 'pdv', 'operar')['ok'] === false, 'Revocar membresía deniega el contexto en la siguiente petición.', $count);
    Auth::logout();

    $restUser = Usuario::porCredenciales((string)$fixtures['rest']['username'], 'fixture-pass-3b');
    test3bAssert($restUser !== null, 'El login administrativo conserva validación de contraseña.', $count);
    if (!$restUser) throw new RuntimeException('No se pudo cargar fixture de contraseña para probar Auth.');
    Auth::login($restUser, 'contrasena');
    test3bAssert(Auth::evaluar('restaurante', 'menu', 'ver')['ok'] === true, 'Auth permite lectura de menú con contraseña.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'menu', 'editar')['ok'] === true, 'Auth permite edición de menú con contraseña.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'tickets', 'ver', 'tickets', (int)$fixtures['ticketRestId'])['ok'] === true, 'Auth permite leer un recurso propio del contexto.', $count);
    test3bAssert(Auth::evaluar('restaurante', 'tickets', 'ver', 'tickets', (int)$fixtures['ticketPanId'])['ok'] === false, 'Auth deniega una URL/API que apunta a un ticket de otro negocio.', $count);
    test3bAssert(Auth::evaluar('panaderia', 'menu', 'ver')['ok'] === false, 'Auth deniega URL de contexto no asignado.', $count);
    test3bAssert(Auth::puede('menu', 'editar', 'restaurante'), 'Auth::puede resuelve el contexto directo.', $count);
    Auth::logout();

    $inactive = Usuario::find((int)$fixtures['inactive']['id']);
    $denied = false;
    try { Auth::login($inactive, 'contrasena'); } catch (RuntimeException) { $denied = true; }
    test3bAssert($denied, 'Una cuenta inactiva no inicia sesión.', $count);
    $superUser = Usuario::find((int)$fixtures['super']['id']);
    $superPinDenied = false;
    try { Auth::login($superUser, 'nip'); } catch (RuntimeException) { $superPinDenied = true; }
    test3bAssert($superPinDenied, 'El superadministrador no puede iniciar sesión con PIN.', $count);
    Auth::login($superUser, 'contrasena');
    test3bAssert(Auth::esSuperAdmin() && Auth::evaluar('panaderia', 'finanzas', 'editar')['ok'], 'La excepción de superadministrador es explícita y requiere contraseña.', $count);
    Auth::logout();
}

$admin = null;
$bases = [];
$options = [];
$phase = 'argumentos/configuración';
$error = null;
$total = 0;
$output = null;
try {
    $options = test3bOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    Comun::origen($config, $options, $root); // Confirma identidad; no abre conexión al esquema origen.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión administrativa sin esquema';
    $admin = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    if ($admin->thread_id === 0) throw new RuntimeException('No se pudo abrir conexión administrativa sin esquema.');

    $phase = 'DDL/deploy 3B sobre base temporal';
    [$base, $token] = test3bCrearBase($admin, $root, $bases);
    $nipSecret = bin2hex(random_bytes(32));
    putenv('NIP_LOOKUP_SECRET=' . $nipSecret);
    $_ENV['NIP_LOOKUP_SECRET'] = $nipSecret;
    $phase = 'fixtures y núcleo de autorización';
    $fixtures = test3bCore($admin, $total);
    $phase = 'sesión, autenticación y guardia';
    test3bAuth($admin, $fixtures, $total);
    if (empty($options['solo_nucleo'])) {
        $phase = 'servicios directos de usuarios';
        test3bGestion($admin, $fixtures, $total);
    }
    $output = ['ok' => true, 'comprobaciones' => $total, 'fases' => empty($options['solo_nucleo']) ? ['núcleo','auth','gestión'] : ['núcleo','auth'],
        'base_temporal' => $base, 'esquema' => (string)$admin->query("SELECT version FROM esquema_versiones WHERE version LIKE 'multinegocio-3b-%' LIMIT 1")->fetch_row()[0]];
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$name, $token]) {
            try {
                if (test3bPuedeBorrar($admin, $name, $token, (string)($config['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . test3bIdent($name));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $name\n");
                } else { $cleanupFailed = true; }
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($cleanupFailed && $error === null) { $phase = 'limpieza'; $error = 'No se pudo verificar o limpiar una base temporal.'; }
}
if ($error === null) echo Comun::json($output ?? ['ok' => true, 'comprobaciones' => $total]);
else fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $phase, 'comprobaciones' => $total, 'error' => $error]));
exit($error === null ? 0 : 1);
