<?php

namespace Services\Users;

use Model\ActiveRecord;
use Services\Security\Permisos;
use Services\Security\PermisosUsuario;

/** CRUD de identidad y membresías para el esquema de permisos directos 3B. */
final class AccesosUsuario
{
    private const AMBITOS = ['global', 'restaurante', 'panaderia'];
    private const ERROR = 'ERROR_GUARDADO';
    private static array $transaccionesPropias = [];

    public static function listarDirectos(int $actorId, $contexto, array $filtros = []): array
    {
        try {
            $slug = self::slugContexto($contexto);
            $actor = self::perfil($actorId);
            if (!$actor || !self::puedeGestionar($actor, $slug, false)) {
                return self::fallo('SIN_PERMISO');
            }
            if ($slug !== 'global' && isset($filtros['ambito']) && (string) $filtros['ambito'] !== '' && (string) $filtros['ambito'] !== $slug) {
                return self::fallo('AMBITO_INVALIDO');
            }
            $sql = 'SELECT u.id, u.username, u.nombre, u.activo, u.es_superadmin FROM usuarios u WHERE 1=1';
            $tipos = '';
            $valores = [];
            if ($slug !== 'global') {
                $sql .= ' AND EXISTS (SELECT 1 FROM usuario_unidades uu WHERE uu.usuario_id=u.id AND uu.unidad_id=? )';
                $tipos .= 'i';
                $valores[] = self::unidadId($slug);
            } elseif (($filtros['ambito'] ?? '') !== '') {
                $filterSlug = (string) $filtros['ambito'];
                if (!in_array($filterSlug, self::ambitosGestionables($actor, 'global'), true)) {
                    return self::fallo('AMBITO_INVALIDO');
                }
                $sql .= ' AND EXISTS (SELECT 1 FROM usuario_unidades uu WHERE uu.usuario_id=u.id AND uu.unidad_id=? )';
                $tipos .= 'i';
                $valores[] = self::unidadId($filterSlug);
            } elseif (isset($filtros['ambito']) && $slug !== (string) $filtros['ambito']) {
                return self::fallo('AMBITO_INVALIDO');
            }
            $q = trim((string) ($filtros['q'] ?? ''));
            if ($q !== '') {
                $sql .= ' AND (u.username LIKE ? OR u.nombre LIKE ?)';
                $like = '%' . $q . '%';
                $tipos .= 'ss';
                $valores[] = $like;
                $valores[] = $like;
            }
            if (($filtros['status'] ?? '') === 'active' || ($filtros['status'] ?? '') === 'inactive') {
                $sql .= ' AND u.activo = ?';
                $tipos .= 'i';
                $valores[] = ($filtros['status'] === 'active') ? 1 : 0;
            }
            $sql .= ' ORDER BY u.nombre, u.id DESC';
            $stmt = self::db()->prepare($sql);
            self::execute($stmt, $tipos, $valores);
            $result = $stmt->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $profile = self::perfil((int) $row['id']);
                $rows[] = self::dto($row, $profile, $slug, false, self::conteoMembresias((int) $row['id'], self::db()) > 1);
            }
            $stmt->close();
            return ['ok' => true, 'codigo' => 'USUARIOS_LISTADOS', 'usuarios' => $rows];
        } catch (\Throwable $e) {
            return self::fallo(self::ERROR);
        }
    }

    public static function detalleDirecto(int $actorId, $contexto, int $id): array
    {
        try {
            $slug = self::slugContexto($contexto);
            $actor = self::perfil($actorId);
            if (!$actor || !self::puedeGestionar($actor, $slug, false)) {
                return self::fallo('SIN_PERMISO');
            }
            $row = self::usuario($id);
            if (!$row) {
                return self::fallo('USUARIO_NO_EXISTE');
            }
            $profile = self::perfil($id);
            if (!$profile || ($slug !== 'global' && !self::tieneMembresia($id, $slug))) {
                return self::fallo('USUARIO_NO_EXISTE');
            }
            return ['ok' => true, 'codigo' => 'USUARIO_ENCONTRADO', 'usuario' => self::dto(
                $row,
                $profile,
                $slug,
                true,
                self::conteoMembresias($id, self::db()) > 1,
                self::ambitosPersistidos($id, $slug)
            )];
        } catch (\Throwable $e) {
            return self::fallo(self::ERROR);
        }
    }

    public static function guardarDirecto(int $actorId, $contexto, ?int $id, array $datos): array
    {
        $db = self::db();
        try {
            $slug = self::slugContexto($contexto);
            $actor = self::perfil($actorId, $db);
            if (!$actor || !self::puedeGestionar($actor, $slug, true)) {
                return self::fallo('SIN_PERMISO');
            }
            $errores = self::validarClaves($datos);
            if ($errores) {
                return ['ok' => false, 'codigo' => 'DATOS_INVALIDOS', 'errores' => $errores];
            }
            $old = $id ? self::usuario($id, $db) : null;
            if ($id && !$old) {
                return self::fallo('USUARIO_NO_EXISTE');
            }
            if ($id && (int) ($old['es_superadmin'] ?? 0) === 1 && empty($actor['super'])) {
                return self::fallo('SIN_PERMISO');
            }
            if ($id && $slug !== 'global' && !self::tieneMembresia($id, $slug, $db)) {
                return self::fallo('SIN_PERMISO');
            }
            $username = trim((string) ($datos['username'] ?? $old['username'] ?? ''));
            $nombre = trim((string) ($datos['nombre'] ?? $old['nombre'] ?? ''));
            $activo = array_key_exists('activo', $datos)
                ? (self::boolValue($datos['activo']) ? 1 : 0)
                : 1;
            if (array_key_exists('activo', $datos) && self::boolValue($datos['activo']) === null) {
                $errores['activo'][] = 'El estado no es válido.';
            }
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                $errores['username'][] = 'El usuario debe tener de 3 a 20 caracteres y solo usar letras, números o guion bajo.';
            }
            if ($nombre === '' || mb_strlen($nombre) > 120) {
                $errores['nombre'][] = 'El nombre es obligatorio y debe tener hasta 120 caracteres.';
            }
            $submittedAmbitos = $datos['ambitos'] ?? [];
            if (!is_array($submittedAmbitos)) {
                $errores['ambitos'][] = 'Los ámbitos no son válidos.';
                $submittedAmbitos = [];
            }
            foreach (array_keys($submittedAmbitos) as $ambito) {
                if (!in_array($ambito, self::AMBITOS, true)) {
                    $errores['ambitos'][] = 'Se envió un ámbito desconocido.';
                }
            }
            if ($id && !array_key_exists('activo', $datos)) {
                $activo = (int) $old['activo'];
            }
            $oldProfile = $id ? self::perfil($id, $db) : null;
            $sharedTotal = $id ? self::conteoMembresias($id, $db) > 1 : false;
            $shared = $slug !== 'global' && $sharedTotal;
            $snapshotRow = $old;
            $snapshotProfile = $oldProfile;
            $snapshotShared = $sharedTotal;
            $manageable = self::ambitosGestionables($actor, $slug);
            if (!in_array($slug, $manageable, true)) {
                return self::fallo('SIN_PERMISO');
            }
            foreach ($submittedAmbitos as $targetSlug => $config) {
                if (!is_array($config)) {
                    $errores['ambitos'][] = 'La configuración de un ámbito no es válida.';
                    continue;
                }
                if (!in_array($targetSlug, $manageable, true) || ($slug !== 'global' && $targetSlug !== $slug)) {
                    $errores['ambitos'][] = 'No puedes administrar el ámbito ' . $targetSlug . '.';
                    continue;
                }
                if (array_diff(array_keys($config), ['activo', 'permisos', 'facultades'])) {
                    $errores['ambitos'][] = 'La configuración contiene campos no permitidos.';
                }
                if (array_key_exists('activo', $config) && self::boolValue($config['activo']) === null) {
                    $errores['ambitos'][] = 'El estado de un ámbito no es válido.';
                }
                $permissions = $config['permisos'] ?? [];
                $facultades = $config['facultades'] ?? [];
                if (!is_array($permissions) || !is_array($facultades) || array_diff(array_keys($permissions), array_keys(PermisosUsuario::catalogo($targetSlug)))) {
                    $errores['ambitos'][] = 'Los permisos enviados no son válidos.';
                    continue;
                }
                $catalog = PermisosUsuario::catalogo($targetSlug);
                foreach ($permissions as $module => $level) {
                    if (!self::nivelValido($module, (string) $level, $catalog)) {
                        $errores['ambitos'][] = 'El nivel para ' . $module . ' no es válido.';
                    }
                    if (!self::techoPermiso($actor, $slug, $targetSlug, $module, (string) $level)) {
                        $errores['ambitos'][] = 'No puedes delegar el nivel solicitado para ' . $module . '.';
                    }
                }
                $allowedFaculties = self::facultadesDelegables($actor, $slug, $targetSlug);
                foreach ($facultades as $faculty) {
                    if (!is_string($faculty) || !in_array($faculty, $allowedFaculties, true)) {
                        $errores['ambitos'][] = 'No puedes delegar una de las facultades solicitadas.';
                    }
                }
            }
            if (!$id) {
                $activePostedScopes = [];
                foreach ($submittedAmbitos as $targetSlug => $config) {
                    if (is_array($config) && self::boolValue($config['activo'] ?? false) === true
                        && in_array($targetSlug, $manageable, true)) {
                        $activePostedScopes[] = $targetSlug;
                    }
                }
                if ($activePostedScopes === []) {
                    $errores['ambitos'][] = 'Selecciona al menos un ámbito gestionable.';
                }
                if ($slug !== 'global' && !in_array($slug, $activePostedScopes, true)) {
                    $errores['ambitos'][] = 'El nuevo usuario debe pertenecer al ámbito actual.';
                }
            }
            if ($id && $slug === 'global' && $actorId === $id && isset($submittedAmbitos['global'])
                && self::boolValue($submittedAmbitos['global']['activo'] ?? false) === true) {
                $oldGlobal = $oldProfile['ambitos']['global'] ?? null;
                $newGlobal = $submittedAmbitos['global'];
                $oldPermissions = $oldGlobal['permisos'] ?? [];
                $newPermissions = $newGlobal['permisos'] ?? [];
                $newPermissions = array_filter($newPermissions, static fn ($level) => !in_array($level, ['', 'ninguno', null], true));
                ksort($oldPermissions);
                ksort($newPermissions);
                $oldFaculties = array_values($oldGlobal['facultades'] ?? []);
                $newFaculties = array_values($newGlobal['facultades'] ?? []);
                sort($oldFaculties);
                sort($newFaculties);
                if (!$oldGlobal || $oldPermissions !== $newPermissions || $oldFaculties !== $newFaculties) {
                    $errores['ambitos'][] = 'No puedes asignarte o ampliar permisos Global.';
                }
            }
            $password = (string) ($datos['password'] ?? '');
            $confirm = (string) ($datos['password_confirm'] ?? '');
            $currentMode = $oldProfile ? PermisosUsuario::tipoAcceso($oldProfile) : null;
            $resultProfile = self::perfilSimulado($submittedAmbitos, $slug, $oldProfile, $id, $manageable);
            $newMode = PermisosUsuario::tipoAcceso($resultProfile);
            if ($shared && $currentMode !== $newMode && $newMode !== Permisos::ACCESO_NINGUNO) {
                $errores['ambitos'][] = 'Una cuenta compartida no puede cambiar de tipo de credencial desde un negocio.';
            }
            $needsPassword = $newMode === Permisos::ACCESO_CONTRASENA && (!$id || $currentMode !== Permisos::ACCESO_CONTRASENA);
            $credentialChanged = $password !== '' || ($needsPassword && !$shared);
            if ($needsPassword && $password === '') {
                $errores['password'][] = 'Define una contraseña antes de conceder acceso al panel.';
            } elseif ($password !== '' && (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))) {
                $errores['password'][] = 'La contraseña debe tener al menos 8 caracteres, una mayúscula y un número.';
            }
            if ($password !== '' && $password !== $confirm) {
                $errores['password_confirm'][] = 'Las contraseñas no coinciden.';
            }
            if ($password !== '' && $newMode !== Permisos::ACCESO_CONTRASENA) {
                $errores['password'][] = 'La contraseña solo aplica cuando el usuario tiene acceso al panel administrativo.';
            }
            if ($credentialChanged && $id && !self::reauthValida($actorId, (string) ($datos['admin_password'] ?? ''), $db)) {
                $errores['admin_password'][] = 'Confirma tu contraseña para cambiar credenciales.';
            }
            if ($shared && $credentialChanged) {
                $errores['password'][] = 'La identidad compartida solo puede cambiar credenciales desde Global.';
            }
            if ($shared && ($username !== (string) $old['username'] || $nombre !== (string) $old['nombre'])) {
                $errores['nombre'][] = 'La identidad compartida solo puede editarse desde Global.';
            }
            if ($shared && array_key_exists('activo', $datos) && $activo !== (int) $old['activo']) {
                $errores['activo'][] = 'El estado global solo puede cambiarse desde Global.';
            }
            if ($id && $slug !== 'global') {
                $targetCurrent = $oldProfile['ambitos'][$slug] ?? null;
                if (isset($submittedAmbitos['global']) || isset($submittedAmbitos['restaurante']) && $slug !== 'restaurante' || isset($submittedAmbitos['panaderia']) && $slug !== 'panaderia') {
                    $errores['ambitos'][] = 'Un administrador local solo puede guardar su ámbito.';
                }
                if (!$targetCurrent && !isset($submittedAmbitos[$slug])) {
                    $errores['ambitos'][] = 'El usuario no pertenece al ámbito actual.';
                }
            }
            if ($errores) {
                return ['ok' => false, 'codigo' => 'DATOS_INVALIDOS', 'errores' => $errores];
            }
            if (!NipService::secretoConfigurado() && $newMode === Permisos::ACCESO_NIP) {
                return self::fallo('NIP_CONFIGURACION_INVALIDA');
            }

            self::beginTransaction($db);
            self::bloquearSuperadmins($db);
            self::bloquearEstadoUsuarios($db, $actorId, $id ?? 0);
            $actor = self::perfil($actorId, $db);
            if (!$actor || !self::puedeGestionar($actor, $slug, true) || !self::delegacionValida($actor, $slug, $submittedAmbitos)
                || ($credentialChanged && $id && !self::reauthValida($actorId, (string) ($datos['admin_password'] ?? ''), $db))) {
                self::rollback($db);
                return self::fallo('SIN_PERMISO');
            }
            $old = $id ? self::usuario($id, $db, true) : null;
            if ($id && !$old) {
                self::rollback($db);
                return self::fallo('USUARIO_NO_EXISTE');
            }
            if ($id) {
                if (!array_key_exists('username', $datos)) {
                    $username = (string) $old['username'];
                }
                if (!array_key_exists('nombre', $datos)) {
                    $nombre = (string) $old['nombre'];
                }
                $currentProfile = self::perfil($id, $db);
                $currentShared = self::conteoMembresias($id, $db) > 1;
                if (
                    (string) $old['username'] !== (string) $snapshotRow['username']
                    || (string) $old['nombre'] !== (string) $snapshotRow['nombre']
                    || (int) $old['activo'] !== (int) $snapshotRow['activo']
                    || $currentShared !== $snapshotShared
                    || serialize($currentProfile) !== serialize($snapshotProfile)
                ) {
                    self::rollback($db);
                    return self::fallo('CONFLICTO_DATOS');
                }
            }
            if ($id && (int) ($old['es_superadmin'] ?? 0) === 1 && (int) $old['activo'] === 1 && $activo === 0) {
                if (self::conteoSuperadmins($db) <= 1) {
                    self::rollback($db);
                    return self::fallo('ADMIN_ACTIVO_REQUERIDO');
                }
            }
            $oldNip = $id ? (string) ($old['nip_lookup'] ?? '') : '';
            $credential = null;
            $nip = null;
            for ($attempt = 1; $attempt <= NipService::MAX_INTENTOS; $attempt++) {
                try {
                if ($newMode === Permisos::ACCESO_NIP && (!$id || $currentMode !== Permisos::ACCESO_NIP || $oldNip === '')) {
                    $nip = NipService::generar();
                    $credential = NipService::credencial($nip);
                }
                $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : (string) ($old['password_hash'] ?? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
                $conservarNipCompartido = $shared && $newMode === Permisos::ACCESO_NINGUNO;
                $nipHash = $newMode === Permisos::ACCESO_NIP || $conservarNipCompartido ? ($credential['hash'] ?? $old['nip_hash'] ?? null) : null;
                $nipLookup = $newMode === Permisos::ACCESO_NIP || $conservarNipCompartido ? ($credential['lookup'] ?? $old['nip_lookup'] ?? null) : null;
                $guardarPermisosAntesDeCredencial = $id !== null && $newMode === Permisos::ACCESO_NIP;
                if ($guardarPermisosAntesDeCredencial) {
                    // El trigger de usuarios rechaza NIP mientras existan permisos de panel.
                    self::guardarAmbitos($db, $id, $slug, $submittedAmbitos, $manageable);
                }
                if ($id) {
                    $stmt = $db->prepare('UPDATE usuarios SET username = ?, nombre = ?, password_hash = ?, nip_hash = ?, nip_lookup = ?, activo = ? WHERE id = ?');
                    self::execute($stmt, 'sssssii', [$username, $nombre, $passwordHash, $nipHash, $nipLookup, $activo, $id]);
                    $uid = $id;
                } else {
                    $stmt = $db->prepare('INSERT INTO usuarios (username,nombre,password_hash,nip_hash,nip_lookup,activo,es_superadmin) VALUES (?,?,?,?,?,?,0)');
                    self::execute($stmt, 'sssssi', [$username, $nombre, $passwordHash, $nipHash, $nipLookup, $activo]);
                    $uid = (int) $db->insert_id;
                }
                $stmt->close();
                if (!$guardarPermisosAntesDeCredencial) {
                    self::guardarAmbitos($db, $uid, $slug, $submittedAmbitos, $manageable);
                }
                break;
                } catch (\Throwable $e) {
                    if ($nip !== null && NipService::esColision($e) && $attempt < NipService::MAX_INTENTOS) {
                        self::rollback($db);
                        self::beginTransaction($db);
                        self::bloquearSuperadmins($db);
                        self::bloquearEstadoUsuarios($db, $actorId, $id ?? 0);
                        $actor = self::perfil($actorId, $db);
                        if (!$actor || !self::puedeGestionar($actor, $slug, true) || !self::delegacionValida($actor, $slug, $submittedAmbitos)) {
                            self::rollback($db);
                            return self::fallo('SIN_PERMISO');
                        }
                        $old = $id ? self::usuario($id, $db, true) : null;
                        if ($id) {
                            $currentProfile = self::perfil($id, $db);
                            $currentShared = self::conteoMembresias($id, $db) > 1;
                            if (!$old || (string) $old['username'] !== (string) $snapshotRow['username']
                                || (string) $old['nombre'] !== (string) $snapshotRow['nombre']
                                || (int) $old['activo'] !== (int) $snapshotRow['activo']
                                || $currentShared !== $snapshotShared
                                || serialize($currentProfile) !== serialize($snapshotProfile)) {
                                self::rollback($db);
                                return self::fallo('CONFLICTO_DATOS');
                            }
                        }
                        if ($credentialChanged && $id && !self::reauthValida($actorId, (string) ($datos['admin_password'] ?? ''), $db)) {
                            self::rollback($db);
                            return self::fallo('CREDENCIAL_ACTUAL_INCORRECTA');
                        }
                        continue;
                    }
                    throw $e;
                }
            }
            self::commit($db);
            $row = self::usuario($uid);
            $profile = self::perfil($uid);
            $response = ['ok' => true, 'codigo' => $id ? 'USUARIO_ACTUALIZADO' : 'USUARIO_CREADO', 'usuario' => self::dto(
                $row,
                $profile,
                $slug,
                true,
                self::conteoMembresias($uid, $db) > 1,
                self::ambitosPersistidos($uid, $slug)
            )];
            if ($nip !== null) {
                $response['nip'] = $nip;
                $response['no_store'] = true;
            }
            return $response;
        } catch (\Throwable $e) {
            self::rollback($db);
            if ((int) $e->getCode() === 1568) {
                return self::fallo('TRANSACCION_ACTIVA');
            }
            if ((int) $e->getCode() === 1062 && !NipService::esColision($e)) {
                return ['ok' => false, 'codigo' => 'DATOS_INVALIDOS', 'errores' => ['username' => ['El nombre de usuario ya está registrado.']]];
            }
            return self::fallo(self::ERROR);
        }
    }

    public static function estadoDirecto(int $actorId, $contexto, int $id, bool $activo): array
    {
        return self::mutarDirecto($actorId, $contexto, $id, 'estado', $activo);
    }

    public static function eliminarDirecto(int $actorId, $contexto, int $id): array
    {
        return self::mutarDirecto($actorId, $contexto, $id, 'eliminar');
    }

    public static function regenerarNipDirecto(int $actorId, $contexto, int $id): array
    {
        return self::mutarDirecto($actorId, $contexto, $id, 'nip');
    }

    private static function mutarDirecto(int $actorId, $contexto, int $id, string $accion, bool $activo = false): array
    {
        $db = self::db();
        try {
            $slug = self::slugContexto($contexto);
            $actor = self::perfil($actorId, $db);
            if (!$actor || !self::puedeGestionar($actor, $slug, true)) {
                return self::fallo('SIN_PERMISO');
            }
            self::beginTransaction($db);
            self::bloquearSuperadmins($db);
            self::bloquearEstadoUsuarios($db, $actorId, $id);
            $actor = self::perfil($actorId, $db);
            if (!$actor || !self::puedeGestionar($actor, $slug, true)) {
                self::rollback($db);
                return self::fallo('SIN_PERMISO');
            }
            $row = self::usuario($id, $db, true);
            if (!$row) {
                self::rollback($db);
                return self::fallo('USUARIO_NO_EXISTE');
            }
            if ((int) ($row['es_superadmin'] ?? 0) === 1 && empty($actor['super'])) {
                self::rollback($db);
                return self::fallo('SIN_PERMISO');
            }
            $profile = self::perfil($id, $db);
            if ($slug !== 'global' && !self::tieneMembresia($id, $slug, $db)) {
                self::rollback($db);
                return self::fallo('USUARIO_NO_EXISTE');
            }
            if ((int) ($row['es_superadmin'] ?? 0) === 1 && empty($actor['super'])) {
                self::rollback($db);
                return self::fallo('SIN_PERMISO');
            }
            $shared = self::conteoMembresias($id, $db) > 1;
            if ($slug !== 'global' && $shared && $accion !== 'eliminar') {
                self::rollback($db);
                return self::fallo('SIN_PERMISO');
            }
            if ($accion === 'estado') {
                if ($slug !== 'global' && $shared) {
                    self::rollback($db);
                    return self::fallo('SIN_PERMISO');
                }
                if ((int) $row['es_superadmin'] === 1 && (int) $row['activo'] === 1 && !$activo && self::conteoSuperadmins($db) <= 1) {
                    self::rollback($db);
                    return self::fallo('ADMIN_ACTIVO_REQUERIDO');
                }
                $stmt = $db->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
                self::execute($stmt, 'ii', [$activo ? 1 : 0, $id]);
                $stmt->close();
            } elseif ($accion === 'eliminar') {
                if ($slug !== 'global' && $shared) {
                    $unitId = self::unidadId($slug, $db);
                    $stmt = $db->prepare('DELETE FROM usuario_unidades WHERE usuario_id = ? AND unidad_id = ?');
                    self::execute($stmt, 'ii', [$id, $unitId]);
                    $stmt->close();
                    self::commit($db);
                    return ['ok' => true, 'codigo' => 'MEMBRESIA_REVOCADA'];
                }
                if ((int) $row['es_superadmin'] === 1 && (int) $row['activo'] === 1 && self::conteoSuperadmins($db) <= 1) {
                    self::rollback($db);
                    return self::fallo('ADMIN_ACTIVO_REQUERIDO');
                }
                if ($slug === 'global') {
                    $stmt = $db->prepare('DELETE FROM usuario_unidades WHERE usuario_id = ?');
                    self::execute($stmt, 'i', [$id]);
                    $stmt->close();
                } else {
                    $unitId = self::unidadId($slug, $db);
                    $stmt = $db->prepare('DELETE FROM usuario_unidades WHERE usuario_id = ? AND unidad_id = ?');
                    self::execute($stmt, 'ii', [$id, $unitId]);
                    $stmt->close();
                }
                $stmt = $db->prepare('DELETE FROM usuarios WHERE id = ?');
                self::execute($stmt, 'i', [$id]);
                $stmt->close();
            } else {
                if (PermisosUsuario::tipoAcceso($profile) !== Permisos::ACCESO_NIP) {
                    self::rollback($db);
                    return self::fallo('TIPO_ACCESO_INVALIDO');
                }
                if (!NipService::secretoConfigurado()) {
                    self::rollback($db);
                    return self::fallo('NIP_CONFIGURACION_INVALIDA');
                }
                for ($attempt = 1; $attempt <= NipService::MAX_INTENTOS; $attempt++) {
                    $nip = NipService::generar();
                    $credential = NipService::credencial($nip);
                    $stmt = $db->prepare('UPDATE usuarios SET nip_hash = ?, nip_lookup = ? WHERE id = ?');
                    try {
                        self::execute($stmt, 'ssi', [$credential['hash'], $credential['lookup'], $id]);
                        $stmt->close();
                        self::commit($db);
                        return ['ok' => true, 'codigo' => 'NIP_REGENERADO', 'nip' => $nip, 'no_store' => true];
                    } catch (\Throwable $e) {
                        $stmt->close();
                        if (NipService::esColision($e) && $attempt < NipService::MAX_INTENTOS) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }
            self::commit($db);
            return ['ok' => true, 'codigo' => $accion === 'eliminar' ? 'USUARIO_ELIMINADO' : ($activo ? 'USUARIO_ACTIVADO' : 'USUARIO_DESACTIVADO')];
        } catch (\Throwable $e) {
            self::rollback($db);
            if ((int) $e->getCode() === 1568) {
                return self::fallo('TRANSACCION_ACTIVA');
            }
            return self::fallo(self::ERROR);
        }
    }

    private static function guardarAmbitos(\mysqli $db, int $uid, string $context, array $posted, array $manageable): void
    {
        $targets = $context === 'global' ? $manageable : [$context];
        foreach ($targets as $slug) {
            $unit = self::unidadId($slug, $db);
            $config = $posted[$slug] ?? null;
            if ($config === null || !filter_var($config['activo'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $stmt = $db->prepare('DELETE FROM usuario_unidades WHERE usuario_id = ? AND unidad_id = ?');
                self::execute($stmt, 'ii', [$uid, $unit]);
                $stmt->close();
                continue;
            }
            $stmt = $db->prepare('INSERT INTO usuario_unidades (usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1) ON DUPLICATE KEY UPDATE activo=1, rol_id=NULL');
            self::execute($stmt, 'ii', [$uid, $unit]);
            $stmt->close();
            $stmt = $db->prepare('DELETE FROM usuario_unidad_permisos WHERE usuario_id = ? AND unidad_id = ?');
            self::execute($stmt, 'ii', [$uid, $unit]);
            $stmt->close();
            $catalog = PermisosUsuario::catalogo($slug);
            foreach (($config['permisos'] ?? []) as $module => $level) {
                if ($level === '' || $level === 'ninguno' || $level === null) {
                    continue;
                }
                $stmt = $db->prepare('INSERT INTO usuario_unidad_permisos (usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)');
                self::execute($stmt, 'iiss', [$uid, $unit, $module, $level]);
                $stmt->close();
            }
            $stmt = $db->prepare('DELETE FROM usuario_unidad_facultades WHERE usuario_id = ? AND unidad_id = ?');
            self::execute($stmt, 'ii', [$uid, $unit]);
            $stmt->close();
            foreach (($config['facultades'] ?? []) as $faculty) {
                $stmt = $db->prepare('INSERT INTO usuario_unidad_facultades (usuario_id,unidad_id,facultad) VALUES (?,?,?)');
                self::execute($stmt, 'iis', [$uid, $unit, $faculty]);
                $stmt->close();
            }
        }
    }

    private static function puedeGestionar(array $actor, string $context, bool $editar): bool
    {
        if (empty($actor['activo'])) {
            return false;
        }
        $ambitos = self::ambitosGestionables($actor, $context);
        if (!in_array($context, $ambitos, true)) {
            return false;
        }
        $profileContext = $actor['ambitos'][$context] ?? null;
        $global = $actor['ambitos']['global'] ?? null;
        $manage = static function (?array $scope, bool $mustEdit): bool {
            $level = (string) ($scope['permisos']['users'] ?? '');
            return $scope !== null
                && ($mustEdit ? $level === 'editar' : in_array($level, ['ver', 'editar'], true))
                && in_array('usuarios.gestionar', $scope['facultades'] ?? [], true);
        };
        if ($context === 'global') {
            return self::super($actor) || ($manage($global, $editar)
                && (!$editar || in_array('permisos.delegar', $global['facultades'] ?? [], true)));
        }
        if (self::super($actor)) {
            return true;
        }
        if ($manage($profileContext, $editar)) {
            return !$editar || in_array('permisos.delegar', $profileContext['facultades'] ?? [], true);
        }
        return false;
    }

    private static function ambitosGestionables(array $actor, string $context): array
    {
        if (self::super($actor)) {
            return $context === 'global' ? self::AMBITOS : [$context];
        }
        if ($context !== 'global') {
            return [$context];
        }
        $scope = $actor['ambitos']['global'] ?? null;
        if ($scope && ($scope['permisos']['users'] ?? '') !== '' && in_array('usuarios.gestionar', $scope['facultades'] ?? [], true)) {
            return self::AMBITOS;
        }
        return [];
    }

    private static function techoPermiso(array $actor, string $actorContext, string $target, string $module, string $requested): bool
    {
        if (self::super($actor)) {
            return true;
        }
        $source = $actor['ambitos'][$actorContext] ?? null;
        $ceiling = (string) ($source['permisos'][$module] ?? '');
        $rank = ['' => 0, 'ver' => 1, 'operar' => 1, 'editar' => 2];
        return in_array($requested, ['', 'ninguno'], true) || ($rank[$requested] ?? 99) <= ($rank[$ceiling] ?? 0);
    }

    private static function facultadesDelegables(array $actor, string $actorContext, string $target): array
    {
        if (self::super($actor)) {
            return self::facultadesCatalogo($target);
        }
        $source = $actor['ambitos'][$actorContext] ?? null;
        return array_values(array_intersect($source['facultades'] ?? [], self::facultadesCatalogo($target)));
    }

    private static function delegacionValida(array $actor, string $context, array $posted): bool
    {
        $manageable = self::ambitosGestionables($actor, $context);
        foreach ($posted as $target => $config) {
            if (!is_array($config) || !in_array($target, $manageable, true) || ($context !== 'global' && $target !== $context)) {
                return false;
            }
            $catalog = PermisosUsuario::catalogo($target);
            foreach (($config['permisos'] ?? []) as $module => $level) {
                if (!is_string($level) || !self::nivelValido($module, $level, $catalog)
                    || !self::techoPermiso($actor, $context, $target, $module, $level)) {
                    return false;
                }
            }
            $facultades = $config['facultades'] ?? [];
            if (!is_array($facultades)
                || array_diff($facultades, self::facultadesDelegables($actor, $context, $target))) {
                return false;
            }
        }
        return true;
    }

    private static function facultadesCatalogo(string $slug): array
    {
        $result = self::db()->query('SELECT f.clave FROM facultades f WHERE f.solo_global = 0' . ($slug === 'global' ? ' OR f.solo_global = 1' : ''));
        if (!$result) {
            return [];
        }
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = (string) $row['clave'];
        }
        $result->free();
        return $values;
    }

    private static function perfilSimulado(array $posted, string $context, ?array $old, ?int $id, array $manageable): array
    {
        $result = $old ?? ['id' => $id ?? 0, 'super' => false, 'ambitos' => []];
        foreach ($manageable as $slug) {
            if ($context !== 'global' && $slug !== $context) {
                continue;
            }
            $config = $posted[$slug] ?? null;
            if (!$config || !filter_var($config['activo'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                unset($result['ambitos'][$slug]);
                continue;
            }
            $result['ambitos'][$slug] = ['permisos' => $config['permisos'] ?? [], 'facultades' => $config['facultades'] ?? []];
        }
        return $result;
    }

    private static function nivelValido(string $module, string $level, array $catalog): bool
    {
        if (!isset($catalog[$module])) {
            return false;
        }
        $group = $catalog[$module]['grupo'] ?? 'panel';
        return $group === 'piso' ? in_array($level, ['', 'operar', 'ninguno'], true) : in_array($level, ['', 'ver', 'editar', 'ninguno'], true);
    }

    private static function boolValue($value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return null;
    }

    private static function dto(array $row, ?array $profile, string $context, bool $detail, bool $shared, ?array $persistedScopes = null): array
    {
        $scope = $persistedScopes[$context] ?? ($profile['ambitos'][$context] ?? ['permisos' => [], 'facultades' => []]);
        $ambitos = [];
        foreach (($detail && $persistedScopes !== null ? $persistedScopes : ($profile['ambitos'] ?? [])) as $slug => $membership) {
            if ($context !== 'global' && $slug !== $context) {
                continue;
            }
            $ambitos[$slug] = ['activo' => $membership['activo'] ?? true];
            if ($detail) {
                $ambitos[$slug]['permisos'] = $membership['permisos'] ?? [];
                $ambitos[$slug]['facultades'] = $membership['facultades'] ?? [];
            }
        }
        return [
            'id' => (int) $row['id'], 'username' => (string) $row['username'], 'nombre' => (string) $row['nombre'],
            'activo' => (bool) $row['activo'], 'es_superadmin' => (bool) ($row['es_superadmin'] ?? false),
            'compartida' => $shared,
            'tipo_acceso' => $profile ? PermisosUsuario::tipoAcceso($profile) : Permisos::ACCESO_NINGUNO,
            'ambitos' => $ambitos,
            'permisos' => $detail ? ($scope['permisos'] ?? []) : [],
            'facultades' => $detail ? ($scope['facultades'] ?? []) : [],
        ];
    }

    private static function ambitosPersistidos(int $id, string $context): array
    {
        $db = self::db();
        $allowed = $context === 'global' ? self::AMBITOS : [$context];
        $stmt = $db->prepare('SELECT u.id,u.slug,m.activo FROM usuario_unidades m JOIN unidades u ON u.id=m.unidad_id WHERE m.usuario_id=? ORDER BY u.id');
        self::execute($stmt, 'i', [$id]);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $scopes = [];
        foreach ($rows as $membership) {
            $slug = (string) $membership['slug'];
            if (!in_array($slug, $allowed, true)) {
                continue;
            }
            $unitId = (int) $membership['id'];
            $permisos = [];
            $permissions = $db->prepare('SELECT modulo,nivel FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=?');
            self::execute($permissions, 'ii', [$id, $unitId]);
            foreach ($permissions->get_result()->fetch_all(MYSQLI_ASSOC) as $permission) {
                if (isset(PermisosUsuario::catalogo($slug)[$permission['modulo']])) {
                    $permisos[$permission['modulo']] = $permission['nivel'];
                }
            }
            $permissions->close();
            $facultades = $db->prepare('SELECT facultad FROM usuario_unidad_facultades WHERE usuario_id=? AND unidad_id=?');
            self::execute($facultades, 'ii', [$id, $unitId]);
            $facultyRows = $facultades->get_result()->fetch_all(MYSQLI_ASSOC);
            $facultades->close();
            $scopes[$slug] = [
                'activo' => (bool) $membership['activo'],
                'permisos' => $permisos,
                'facultades' => array_values(array_map(static fn ($row) => (string) $row['facultad'], $facultyRows)),
            ];
        }
        return $scopes;
    }

    private static function validarClaves(array $datos): array
    {
        $allowed = ['username', 'nombre', 'activo', 'password', 'password_confirm', 'ambitos', 'admin_password'];
        $unknown = array_diff(array_keys($datos), $allowed);
        return $unknown ? ['datos' => ['Se enviaron campos no permitidos.']] : [];
    }

    private static function reauthValida(int $actorId, string $password, \mysqli $db): bool
    {
        if ($password === '') {
            return false;
        }
        $stmt = $db->prepare('SELECT password_hash, activo FROM usuarios WHERE id = ?');
        self::execute($stmt, 'i', [$actorId]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row && (int) $row['activo'] === 1 && password_verify($password, (string) $row['password_hash']);
    }

    private static function slugContexto($contexto): string
    {
        $slug = is_array($contexto) ? ($contexto['slug'] ?? '') : (string) $contexto;
        if (!in_array($slug, self::AMBITOS, true)) {
            throw new \InvalidArgumentException('Ámbito inválido.');
        }
        return $slug;
    }

    private static function unidadId(string $slug, ?\mysqli $db = null): int
    {
        $stmt = ($db ?? self::db())->prepare('SELECT id FROM unidades WHERE slug = ? LIMIT 1');
        self::execute($stmt, 's', [$slug]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            throw new \RuntimeException('Ámbito no disponible.');
        }
        return (int) $row['id'];
    }

    private static function usuario(int $id, ?\mysqli $db = null, bool $lock = false): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = ($db ?? self::db())->prepare('SELECT id,username,nombre,password_hash,nip_hash,nip_lookup,activo,es_superadmin FROM usuarios WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        self::execute($stmt, 'i', [$id]);
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private static function perfil(int $id, ?\mysqli $db = null): ?array
    {
        return $id > 0 ? PermisosUsuario::cargar($id, $db ?? self::db()) : null;
    }

    private static function conteoMembresias(int $id, \mysqli $db): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM usuario_unidades WHERE usuario_id = ?');
        self::execute($stmt, 'i', [$id]);
        $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $count;
    }

    private static function tieneMembresia(int $id, string $slug, ?\mysqli $db = null): bool
    {
        $db ??= self::db();
        $stmt = $db->prepare('SELECT 1 FROM usuario_unidades WHERE usuario_id = ? AND unidad_id = ? LIMIT 1');
        self::execute($stmt, 'ii', [$id, self::unidadId($slug, $db)]);
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private static function bloquearSuperadmins(\mysqli $db): void
    {
        $result = $db->query('SELECT id FROM usuarios WHERE es_superadmin = 1 AND activo = 1 ORDER BY id FOR UPDATE');
        if (!$result) {
            throw new \RuntimeException('No se pudo proteger la cuenta superadministradora.');
        }
        $result->free();
    }

    private static function bloquearEstadoUsuarios(\mysqli $db, int $actorId, int $targetId): void
    {
        $ids = array_values(array_unique(array_filter([$actorId, $targetId], static fn ($id) => $id > 0)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $stmt = $db->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
            self::execute($stmt, 'i', [$id]);
            $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $db->prepare('SELECT unidad_id FROM usuario_unidades WHERE usuario_id = ? ORDER BY unidad_id FOR UPDATE');
            self::execute($stmt, 'i', [$id]);
            $result = $stmt->get_result();
            while ($result->fetch_assoc()) {
            }
            $stmt->close();
        }
    }

    private static function conteoSuperadmins(\mysqli $db): int
    {
        $result = $db->query('SELECT COUNT(*) AS n FROM usuarios WHERE es_superadmin = 1 AND activo = 1');
        if (!$result) {
            throw new \RuntimeException('No se pudo verificar la cuenta superadministradora.');
        }
        $count = (int) ($result->fetch_assoc()['n'] ?? 0);
        $result->free();
        return $count;
    }

    private static function super(array $perfil): bool { return !empty($perfil['super']); }
    private static function db(): \mysqli { return ActiveRecord::getDB(); }
    private static function execute(\mysqli_stmt $stmt, string $types = '', array $values = []): void
    {
        if (!$stmt) { throw new \RuntimeException('No se pudo preparar la consulta.'); }
        if ($types !== '') { $stmt->bind_param($types, ...$values); }
        if (!$stmt->execute()) { throw new \RuntimeException($stmt->error, (int) $stmt->errno); }
    }
    private static function beginTransaction(\mysqli $database): void
    {
        // SET TRANSACTION fails with MySQL 1568 when a caller already owns a transaction.
        if (!$database->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) {
            throw new \RuntimeException($database->error, (int) $database->errno);
        }
        if (!$database->begin_transaction()) {
            throw new \RuntimeException($database->error, (int) $database->errno);
        }
        self::$transaccionesPropias[spl_object_id($database)] = true;
    }

    private static function commit(\mysqli $database): void
    {
        $key = spl_object_id($database);
        if (!isset(self::$transaccionesPropias[$key])) {
            throw new \RuntimeException('La operación no es dueña de la transacción.');
        }
        if (!$database->commit()) {
            throw new \RuntimeException($database->error, (int) $database->errno);
        }
        unset(self::$transaccionesPropias[$key]);
    }

    private static function rollback(\mysqli $database): void
    {
        $key = spl_object_id($database);
        if (!isset(self::$transaccionesPropias[$key])) {
            return;
        }
        try {
            $database->rollback();
        } catch (\Throwable $e) {
            // No exponer datos ni modificar una transacción que no pertenezca al servicio.
        } finally {
            unset(self::$transaccionesPropias[$key]);
        }
    }
    private static function fallo(string $codigo): array { return ['ok' => false, 'codigo' => $codigo, 'errores' => []]; }
}
