<?php
declare(strict_types=1);
namespace Services\Menu;

use Model\ActiveRecord;
use Services\Security\ContextoNegocio;
use Services\Security\PermisosUsuario;

/** Identidades maestras: no escribe condiciones locales ni existencias. */
final class CatalogoComercial
{
    public const FACULTAD = 'comercial.identidad.editar';

    public static function puedeEditar(): bool
    {
        try { ContextoNegocio::facultad(self::FACULTAD, 'global', 'menu'); return true; }
        catch (\Throwable $e) { return false; }
    }

    /** Selección de identidades sin revelar ofertas de otros negocios. */
    public static function identidades(string $busqueda = '', int $pagina = 1): array
    {
        ContextoNegocio::exigir('menu', 'ver');
        $q = '%' . mb_substr(trim($busqueda), 0, 100) . '%';
        $offset = (max(1, $pagina) - 1) * 30;
        $stmt = ActiveRecord::getDB()->prepare('SELECT id,nombre,descripcion,img FROM productos WHERE nombre LIKE ? ORDER BY nombre,id LIMIT 30 OFFSET ?');
        $stmt->execute([$q, $offset]);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function identidad(int $id): ?array
    {
        ContextoNegocio::exigir('menu', 'ver');
        $stmt = ActiveRecord::getDB()->prepare('SELECT id,nombre,descripcion,img FROM productos WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** Global solo ve el uso en negocios donde su perfil permite consultar menú. */
    public static function usos(int $productoId): array
    {
        $perfil = ContextoNegocio::exigir('menu', 'ver', 'global');
        $stmt = ActiveRecord::getDB()->prepare('SELECT u.slug,o.id,o.precio,o.activo,c.nombre AS categoria FROM ofertas_producto o JOIN unidades u ON u.id=o.unidad_id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE o.producto_id=? ORDER BY u.slug');
        $stmt->execute([$productoId]);
        return array_values(array_filter($stmt->get_result()->fetch_all(MYSQLI_ASSOC),
            static fn(array $row): bool => PermisosUsuario::permitido($perfil, $row['slug'], 'menu', 'ver')));
    }

    public static function compartida(int $id): bool
    {
        ContextoNegocio::exigir('menu', 'ver');
        $stmt = ActiveRecord::getDB()->prepare('SELECT COUNT(*) FROM ofertas_producto WHERE producto_id=?');
        $stmt->execute([$id]);
        return (int)$stmt->get_result()->fetch_row()[0] > 1;
    }

    public static function huella(array $identidad): string
    {
        return hash('sha256', json_encode([$identidad['nombre'], $identidad['descripcion'], $identidad['img']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function actualizar(int $id, array $datos, string $huella, bool $confirmado): array
    {
        ContextoNegocio::facultad(self::FACULTAD, 'global', 'menu');
        return ContextoNegocio::transaccion(static function () use ($id, $datos, $huella, $confirmado): array {
            ContextoNegocio::facultad(self::FACULTAD, 'global', 'menu', true);
            $db = ActiveRecord::getDB();
            $s = $db->prepare('SELECT id,nombre,descripcion,img FROM productos WHERE id=? FOR UPDATE');
            $s->execute([$id]); $actual = $s->get_result()->fetch_assoc();
            if (!$actual || !hash_equals(self::huella($actual), $huella)) throw new \RuntimeException('La identidad cambió. Recarga la ficha antes de guardar.');
            if (!$confirmado) throw new \RuntimeException('Confirma el cambio de datos maestros para todos los negocios.');
            $nombre = trim((string)($datos['nombre'] ?? ''));
            $descripcion = trim((string)($datos['descripcion'] ?? ''));
            if ($nombre === '' || mb_strlen($nombre) > 120 || $descripcion === '') throw new \RuntimeException('Nombre y descripción son obligatorios; máximo 120 caracteres para nombre.');
            $s = $db->prepare('UPDATE productos SET nombre=?,descripcion=?,img=? WHERE id=?');
            $s->execute([$nombre, $descripcion, $datos['img'] ?? null, $id]);
            return ['id' => $id];
        });
    }
}
