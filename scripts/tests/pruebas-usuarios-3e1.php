<?php
declare(strict_types=1);

/** Aceptación de Usuarios 3E sobre la base desechable marcada por el runner. */
function probarUsuarios3E(mysqli $db, int $superId, int &$count): void
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('El fixture de Usuarios solo se ejecuta por CLI.');
    \Model\ActiveRecord::setDB($db);
    $assert = static function (bool $ok, string $mensaje) use (&$count): void {
        if (!$ok) throw new RuntimeException($mensaje);
        $count++;
    };
    $super = \Services\Security\PermisosUsuario::cargar($superId, $db);
    $assert(!empty($super['super']) && !empty($super['activo']), 'El fixture de Usuarios requiere el superadministrador activo provisionado.');
    $password = 'Prueba3E_' . bin2hex(random_bytes(12));
    $suffix = bin2hex(random_bytes(4));
    $serial = 0;
    $crear = static function (int $actor, string $contexto, array $ambitos, string $nombre = 'Usuario fixture 3E') use (&$serial, $suffix, $password): array {
        return \Services\Users\UsuarioService::guardarDirecto($actor, $contexto, null, [
            'username' => 'u3e_' . $suffix . '_' . ++$serial,
            'nombre' => $nombre,
            'activo' => true,
            'password' => $password,
            'password_confirm' => $password,
            'ambitos' => $ambitos,
        ]);
    };
    $scope = static fn(array $permisos, array $facultades = []): array => ['activo' => true, 'permisos' => $permisos, 'facultades' => $facultades];
    $ctxs = ['global', 'restaurante', 'panaderia'];
    $created = [];
    foreach ($ctxs as $ctx) {
        $catalog = \Services\Users\AccesosUsuario::catalogoFacultades($ctx);
        $assert(isset($catalog['usuarios.gestionar'], $catalog['permisos.delegar']), 'El catálogo de facultades conserva gestión y delegación en ' . $ctx . '.');
        $assert(($ctx === 'global') === isset($catalog['almacenes.crear']), 'Crear almacenes solo aparece como facultad de Global.');
        $assert(($ctx === 'global') === isset($catalog['catalogo.editar']), 'Editar maestro solo aparece como facultad de Global.');
        $result = $crear($superId, 'global', [$ctx => $scope(['users' => 'editar', 'inventario' => 'editar'], ['usuarios.gestionar', 'permisos.delegar'])]);
        $assert(($result['ok'] ?? false) === true, 'Global crea un administrador delegado de ' . $ctx . '.');
        $created[$ctx] = (int)($result['usuario']['id'] ?? 0);
        $profile = \Services\Security\PermisosUsuario::cargar($created[$ctx], $db);
        $assert(array_keys($profile['ambitos']) === [$ctx] && \Services\Security\PermisosUsuario::tipoAcceso($profile) === 'contrasena', 'El administrador creado tiene solo su ámbito y exige contraseña.');
        $detail = \Services\Users\UsuarioService::detalleDirecto($created[$ctx], $ctx, $created[$ctx]);
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        $assert(($detail['ok'] ?? false) === true && !str_contains($json, 'password_hash') && !str_contains($json, 'nip_hash') && !str_contains($json, 'nip_lookup'), 'Detalle autorizado no revela credenciales en ' . $ctx . '.');
    }
    foreach (['restaurante', 'panaderia'] as $ctx) {
        $local = $created[$ctx];
        $foreign = $ctx === 'restaurante' ? 'panaderia' : 'restaurante';
        $own = $crear($local, $ctx, [$ctx => $scope(['inventario' => 'ver'])]);
        $assert(($own['ok'] ?? false) === true, 'El administrador de ' . $ctx . ' crea una cuenta exclusivamente local.');
        $id = (int)$own['usuario']['id'];
        $foreignWrite = $crear($local, $ctx, [$foreign => $scope(['inventario' => 'ver'])]);
        $assert(($foreignWrite['ok'] ?? true) === false, 'El administrador local no crea accesos del negocio ajeno.');
        $foreignRead = \Services\Users\UsuarioService::detalleDirecto($local, $ctx, $created[$foreign]);
        $assert(($foreignRead['ok'] ?? true) === false, 'El detalle local no permite IDs de usuarios del negocio ajeno.');
        $disable = \Services\Users\UsuarioService::estadoDirecto($local, $ctx, $id, false);
        $assert(($disable['ok'] ?? false) === true && !\Services\Security\PermisosUsuario::cargar($id, $db)['activo'], 'La desactivación local revoca el acceso efectivo.');
        $enable = \Services\Users\UsuarioService::estadoDirecto($local, $ctx, $id, true);
        $assert(($enable['ok'] ?? false) === true && \Services\Security\PermisosUsuario::cargar($id, $db)['activo'], 'La activación explícita restituye la cuenta local.');
        $delete = \Services\Users\UsuarioService::eliminarDirecto($local, $ctx, $id);
        $assert(($delete['ok'] ?? false) === true, 'La cuenta exclusivamente local puede eliminarse desde su ámbito.');
        $escalar = $crear($local, $ctx, [$ctx => $scope(['inventario' => 'ver'], ['almacenes.crear'])]);
        $assert(($escalar['ok'] ?? true) === false, 'Un negocio no puede introducir una facultad exclusiva de Global.');
    }
    $multi = $crear($superId, 'global', ['restaurante' => $scope(['inventario' => 'ver']), 'panaderia' => $scope(['inventario' => 'ver'])]);
    $assert(($multi['ok'] ?? false) === true, 'Una identidad puede tener membresías de ambos negocios.');
    $multiId = (int)$multi['usuario']['id'];
    $before = $db->query('SELECT username,nombre,password_hash,activo FROM usuarios WHERE id=' . $multiId)->fetch_assoc();
    $identity = \Services\Users\UsuarioService::guardarDirecto($created['restaurante'], 'restaurante', $multiId, [
        'nombre' => 'Cambio de identidad prohibido', 'ambitos' => ['restaurante' => $scope(['inventario' => 'ver'])],
    ]);
    $assert(($identity['ok'] ?? true) === false && $before === $db->query('SELECT username,nombre,password_hash,activo FROM usuarios WHERE id=' . $multiId)->fetch_assoc(), 'Un administrador local no modifica la identidad compartida.');
    $revoke = \Services\Users\UsuarioService::eliminarDirecto($created['restaurante'], 'restaurante', $multiId);
    $after = \Services\Security\PermisosUsuario::cargar($multiId, $db);
    $assert(($revoke['ok'] ?? false) === true && ($revoke['codigo'] ?? '') === 'MEMBRESIA_REVOCADA' && !isset($after['ambitos']['restaurante']) && isset($after['ambitos']['panaderia']), 'Revocar la membresía local conserva el acceso ajeno de la identidad compartida.');
    $protect = \Services\Users\UsuarioService::estadoDirecto($created['restaurante'], 'restaurante', $superId, false);
    $assert(($protect['ok'] ?? true) === false && !empty(\Services\Security\PermisosUsuario::cargar($superId, $db)['activo']), 'Un administrador delegado no puede desactivar al superadministrador.');

    // La UI debe presentar el mismo techo que el servicio, aunque el actor tenga
    // permisos diferentes en una membresía destino que también le pertenezca.
    $uiActor = ['super' => false, 'ambitos' => [
        'global' => ['permisos' => ['users' => 'editar', 'inventario' => 'ver'], 'facultades' => ['usuarios.gestionar', 'permisos.delegar', 'almacenes.crear', 'catalogo.editar']],
        'panaderia' => ['permisos' => ['inventario' => 'editar'], 'facultades' => []],
    ]];
    $techos = new ReflectionMethod(\Controllers\AdminUsuariosAmbitosController::class, 'techosPorAmbito');
    $available = new ReflectionMethod(\Controllers\AdminUsuariosAmbitosController::class, 'facultadesPorAmbito');
    $uiCeilings = $techos->invoke(null, $uiActor, 'global', $ctxs);
    $uiFaculties = $available->invoke(null, $uiActor, 'global', $ctxs);
    $assert(($uiCeilings['panaderia']['inventario'] ?? '') === 'ver', 'El formulario Global no amplía el techo usando la membresía destino.');
    $assert(isset($uiFaculties['global']['almacenes.crear'], $uiFaculties['global']['catalogo.editar']) && !isset($uiFaculties['panaderia']['almacenes.crear'], $uiFaculties['panaderia']['catalogo.editar']), 'El formulario expone las facultades de Almacenes y maestro solamente donde son válidas.');
    $superFaculties = $available->invoke(null, $super, 'global', $ctxs);
    $assert(isset($superFaculties['global']['almacenes.crear']) && !isset($superFaculties['restaurante']['almacenes.crear']), 'La selección del superadministrador también respeta facultades exclusivas de Global.');
}
