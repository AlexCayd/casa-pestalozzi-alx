<?php
declare(strict_types=1);

/**
 * Fixtures temporales de 3B. Solo se cargan desde run-multinegocio-3b.php,
 * después de crear y marcar la base desechable.
 */
final class FixtureAutorizacion3B
{
    /** @return array{id:int,username:string,slug:string} */
    public static function usuario(mysqli $db, string $alias, array $membresias = [], bool $super = false, bool $activo = true): array
    {
        $username = 'qa3b_' . preg_replace('/[^a-z0-9_]/i', '_', $alias) . '_' . bin2hex(random_bytes(4));
        $stmt = $db->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?, ?, ?)');
        $nombre = 'Fixture 3B ' . $alias;
        $passwordHash = password_hash('fixture-pass-3b', PASSWORD_DEFAULT);
        $activoInt = $activo ? 1 : 0;
        $superInt = $super ? 1 : 0;
        $stmt->bind_param('sssii', $username, $nombre, $passwordHash, $activoInt, $superInt);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();

        foreach ($membresias as $slug => $config) {
            $unidad = $db->prepare('SELECT id FROM unidades WHERE slug=?');
            $unidad->bind_param('s', $slug);
            $unidad->execute();
            $row = $unidad->get_result()->fetch_assoc();
            $unidad->close();
            if (!$row) throw new RuntimeException("Unidad de fixture desconocida: $slug.");
            $unidadId = (int)$row['id'];
            $membershipActive = !array_key_exists('activo', $config) || $config['activo'] ? 1 : 0;
            $membership = $db->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,?)');
            $membership->bind_param('iii', $id, $unidadId, $membershipActive);
            $membership->execute();
            $membership->close();

            foreach (($config['permisos'] ?? []) as $module => $level) {
                $permission = $db->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)');
                $permission->bind_param('iiss', $id, $unidadId, $module, $level);
                $permission->execute();
                $permission->close();
            }
            foreach (($config['facultades'] ?? []) as $faculty) {
                $facultyStmt = $db->prepare('INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES (?,?,?)');
                $facultyStmt->bind_param('iis', $id, $unidadId, $faculty);
                $facultyStmt->execute();
                $facultyStmt->close();
            }
        }

        return ['id' => $id, 'username' => $username, 'slug' => $alias];
    }

    public static function permiso(mysqli $db, int $usuarioId, string $unidadSlug, string $modulo, ?string $nivel): void
    {
        $unidad = $db->prepare('SELECT id FROM unidades WHERE slug=?');
        $unidad->bind_param('s', $unidadSlug);
        $unidad->execute();
        $row = $unidad->get_result()->fetch_assoc();
        $unidad->close();
        if (!$row) throw new RuntimeException("Unidad de fixture desconocida: $unidadSlug.");
        $unidadId = (int)$row['id'];
        if ($nivel === null) {
            $stmt = $db->prepare('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?');
            $stmt->bind_param('iis', $usuarioId, $unidadId, $modulo);
        } else {
            $stmt = $db->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)');
            $stmt->bind_param('iiss', $usuarioId, $unidadId, $modulo, $nivel);
        }
        $stmt->execute();
        $stmt->close();
    }

    public static function membresiaActiva(mysqli $db, int $usuarioId, string $unidadSlug, bool $activa): void
    {
        $stmt = $db->prepare('UPDATE usuario_unidades uu JOIN unidades u ON u.id=uu.unidad_id SET uu.activo=? WHERE uu.usuario_id=? AND u.slug=?');
        $activo = $activa ? 1 : 0;
        $stmt->bind_param('iis', $activo, $usuarioId, $unidadSlug);
        $stmt->execute();
        $stmt->close();
    }

    public static function estadoUsuario(mysqli $db, int $usuarioId, bool $activo): void
    {
        $stmt = $db->prepare('UPDATE usuarios SET activo=? WHERE id=?');
        $value = $activo ? 1 : 0;
        $stmt->bind_param('ii', $value, $usuarioId);
        $stmt->execute();
        $stmt->close();
    }

    /** NIP de prueba sin secretos persistentes; el secreto HMAC lo fija el runner por proceso. */
    public static function asignarNip(mysqli $db, int $usuarioId): string
    {
        $nip = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $credential = \Services\Users\NipService::credencial($nip);
        $hash = $credential['hash'];
        $lookup = $credential['lookup'];
        $stmt = $db->prepare('UPDATE usuarios SET nip_hash=?,nip_lookup=? WHERE id=?');
        $stmt->bind_param('ssi', $hash, $lookup, $usuarioId);
        $stmt->execute();
        $stmt->close();
        return $nip;
    }

    public static function mapa(mysqli $db, int $usuarioId, string $slug): array
    {
        $stmt = $db->prepare('SELECT id FROM unidades WHERE slug=?');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException("Unidad de fixture desconocida: $slug.");
        return ['id' => (int)$row['id'], 'usuario_id' => $usuarioId];
    }
}
