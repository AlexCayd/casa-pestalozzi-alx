<?php
declare(strict_types=1);

/** Fixtures directos de autorización para pruebas del motor 3C. */
final class FixtureAlmacenes3C
{
    /** @return array{id:int,username:string} */
    public static function usuario(mysqli $db, string $alias, array $membresias = [], bool $activo = true): array
    {
        $username = 'qa3c_' . preg_replace('/[^a-z0-9_]/i', '_', $alias) . '_' . bin2hex(random_bytes(4));
        $nombre = 'Fixture 3C ' . $alias;
        $hash = password_hash('fixture-pass-3c', PASSWORD_DEFAULT);
        $activoInt = $activo ? 1 : 0;
        $stmt = $db->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?, ?,0)');
        $stmt->bind_param('sssi', $username, $nombre, $hash, $activoInt);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();

        foreach ($membresias as $slug => $config) {
            $stmt = $db->prepare('SELECT id FROM unidades WHERE slug=?');
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $unidad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$unidad) throw new RuntimeException("Unidad fixture desconocida: $slug.");
            $unidadId = (int)$unidad['id'];
            $membershipActive = !array_key_exists('activo', $config) || $config['activo'] ? 1 : 0;
            $stmt = $db->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,?)');
            $stmt->bind_param('iii', $id, $unidadId, $membershipActive);
            $stmt->execute();
            $stmt->close();

            foreach (($config['permisos'] ?? []) as $module => $level) {
                $stmt = $db->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)');
                $stmt->bind_param('iiss', $id, $unidadId, $module, $level);
                $stmt->execute();
                $stmt->close();
            }
            foreach (($config['facultades'] ?? []) as $faculty) {
                $stmt = $db->prepare('INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES (?,?,?)');
                $stmt->bind_param('iis', $id, $unidadId, $faculty);
                $stmt->execute();
                $stmt->close();
            }
        }
        return ['id' => $id, 'username' => $username];
    }
}
