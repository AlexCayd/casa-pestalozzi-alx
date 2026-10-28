<?php
declare(strict_types=1);
namespace Services\Security;

use Model\ActiveRecord;

/** Asignaciones directas; los roles de membresía nunca participan en esta decisión. */
final class PermisosUsuario
{
    public static function catalogo(string $contexto): array
    {
        if (!isset(Contextos::NOMBRES[$contexto])) return [];
        $modulos = Permisos::MODULOS;
        unset($modulos['roles']);
        $modulos['menu']['ruta'] = match ($contexto) {
            'global' => '/admin/global/productos',
            'panaderia' => '/admin/panaderia/menu',
            default => '/admin/menu',
        };
        if ($contexto === 'global') {
            $modulos['menu']['titulo'] = 'Productos comerciales';
            $modulos['menu']['detalle'] = 'Identidades maestras y uso comercial autorizado; Global no vende.';
        }
        $modulos['inventario']['ruta']=($contexto==='restaurante'?'/admin':'/admin/'.$contexto).'/almacenes';
        $modulos['reservations_map'] = ['titulo' => 'Mapa operativo de reservaciones', 'detalle' => 'Operación de reservas en el mapa, sin administración.', 'grupo' => Permisos::GRUPO_PISO, 'ruta' => '/admin/reservaciones/operacion'];
        foreach ($modulos as $key => &$modulo) {
            $operativo = $modulo['grupo'] === Permisos::GRUPO_PISO;
            if ($operativo && $contexto !== 'restaurante') { unset($modulos[$key]); continue; }
            $modulo['operativo'] = $operativo;
            $modulo['niveles'] = $operativo ? ['operar'] : ['ver', 'editar'];
        }
        unset($modulo);
        return $modulos;
    }

    public static function cargar(int $id, ?\mysqli $db = null, bool $bloquear = false): array
    {
        $db ??= ActiveRecord::getDB();
        if (!$db || $id < 1) throw new \RuntimeException('No se pudo verificar la identidad.');
        $lock = $bloquear ? ' FOR SHARE' : '';
        $stmt = $db->prepare('SELECT id,nombre,username,activo,es_superadmin FROM usuarios WHERE id=?' . $lock);
        $stmt->execute([$id]);
        $fila = $stmt->get_result()->fetch_assoc();
        if (!$fila) return ['id' => $id, 'nombre' => '', 'username' => '', 'activo' => false, 'super' => false, 'ambitos' => []];
        $perfil = ['id' => (int)$fila['id'], 'nombre' => $fila['nombre'], 'username' => $fila['username'], 'activo' => (int)$fila['activo'] === 1, 'super' => (int)$fila['es_superadmin'] === 1, 'ambitos' => []];
        $stmt = $db->prepare('SELECT u.id,u.slug FROM usuario_unidades m JOIN unidades u ON u.id=m.unidad_id WHERE m.usuario_id=? AND m.activo=1 ORDER BY u.id' . $lock);
        $stmt->execute([$id]);
        $unidades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($perfil['super']) $unidades = $db->query('SELECT id,slug FROM unidades ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        foreach ($unidades as $unidad) {
            $slug = $unidad['slug'];
            if (!isset(Contextos::NOMBRES[$slug])) continue;
            $perfil['ambitos'][$slug] = ['id' => (int)$unidad['id'], 'permisos' => [], 'facultades' => []];
        }
        $stmt = $db->prepare('SELECT u.slug,p.modulo,p.nivel FROM usuario_unidad_permisos p JOIN unidades u ON u.id=p.unidad_id JOIN usuario_unidades m ON m.usuario_id=p.usuario_id AND m.unidad_id=p.unidad_id WHERE p.usuario_id=? AND m.activo=1' . $lock);
        $stmt->execute([$id]);
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
            $catalogo = self::catalogo($p['slug']);
            if (isset($perfil['ambitos'][$p['slug']], $catalogo[$p['modulo']]) && in_array($p['nivel'], $catalogo[$p['modulo']]['niveles'], true)) $perfil['ambitos'][$p['slug']]['permisos'][$p['modulo']] = $p['nivel'];
        }
        $stmt = $db->prepare('SELECT u.slug,f.facultad FROM usuario_unidad_facultades f JOIN unidades u ON u.id=f.unidad_id WHERE f.usuario_id=? AND EXISTS(SELECT 1 FROM usuario_unidades m WHERE m.usuario_id=f.usuario_id AND m.unidad_id=f.unidad_id AND m.activo=1)' . $lock);
        $stmt->execute([$id]);
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
            if (isset($perfil['ambitos'][$f['slug']])) $perfil['ambitos'][$f['slug']]['facultades'][] = $f['facultad'];
        }
        if ($perfil['super']) {
            $facultades = $db->query('SELECT clave,solo_global FROM facultades')->fetch_all(MYSQLI_ASSOC);
            foreach ($perfil['ambitos'] as $slug => &$ambito) {
                foreach (self::catalogo($slug) as $mod => $def) $ambito['permisos'][$mod] = $def['operativo'] ? 'operar' : 'editar';
                foreach ($facultades as $f) if (!$f['solo_global'] || $slug === 'global') $ambito['facultades'][] = $f['clave'];
                $ambito['facultades'] = array_values(array_unique($ambito['facultades']));
            }
            unset($ambito);
        }
        return $perfil;
    }

    public static function tipoAcceso(array $perfil): string
    {
        if (!empty($perfil['super'])) return Permisos::ACCESO_CONTRASENA;
        $piso = false;
        foreach ($perfil['ambitos'] ?? [] as $ctx => $a) foreach ($a['permisos'] ?? [] as $mod => $nivel) {
            $def = self::catalogo($ctx)[$mod] ?? null;
            if (!$def || !in_array($nivel, $def['niveles'], true)) continue;
            if (!$def['operativo']) return Permisos::ACCESO_CONTRASENA;
            $piso = true;
        }
        return $piso ? Permisos::ACCESO_NIP : Permisos::ACCESO_NINGUNO;
    }

    public static function permitido(array $perfil, string $ctx, string $modulo, string $nivel = 'ver'): bool
    {
        if (empty($perfil['activo']) || !isset($perfil['ambitos'][$ctx])) return false;
        $def = self::catalogo($ctx)[$modulo] ?? null;
        if (!$def) return false;
        $tiene = $perfil['ambitos'][$ctx]['permisos'][$modulo] ?? null;
        if ($def['operativo']) return $tiene === 'operar' && in_array($nivel, ['ver', 'editar', 'operar'], true);
        return in_array($nivel, ['ver', 'editar'], true) && ($tiene === 'editar' || ($tiene === 'ver' && $nivel === 'ver'));
    }

    public static function decidir(array $perfil, string $ctx, string $modulo, string $nivel, string $autenticacion): array
    {
        $codigo = 'PERMITIDO';
        if (empty($perfil['activo']) || !in_array($autenticacion, ['nip', 'contrasena'], true)) $codigo = 'NO_AUTORIZADO';
        elseif (self::tipoAcceso($perfil) === 'contrasena' && $autenticacion !== 'contrasena') $codigo = 'NIVEL_AUTH_REQUERIDO';
        elseif (!isset($perfil['ambitos'][$ctx])) $codigo = 'AMBITO_DENEGADO';
        elseif (!self::permitido($perfil, $ctx, $modulo, $nivel)) $codigo = 'PERMISO_DENEGADO';
        return ['ok' => $codigo === 'PERMITIDO', 'codigo' => $codigo];
    }

    public static function recurso(array $perfil, string $ctx, string $tabla, int $id, string $nivel = 'ver', ?string $modulo = null): bool
    {
        $tablas = ['usuarios' => 'users', 'tickets' => 'tickets', 'ticket_items' => 'pdv', 'ticket_pagos' => 'pdv', 'mesas' => 'pdv', 'reservaciones' => 'reservations', 'almacenes' => 'inventario', 'ofertas_producto' => 'menu', 'recetas' => 'productos', 'operaciones_financieras' => 'finanzas'];
        if (!isset($tablas[$tabla]) || $id < 1 || !self::permitido($perfil, $ctx, $modulo ?? $tablas[$tabla], $nivel)) return false;
        $compatibles=['usuarios'=>['users'],'tickets'=>['tickets','pdv','area','reservations_map'],'ticket_items'=>['pdv','area'],'ticket_pagos'=>['pdv','tickets'],'mesas'=>['pdv','reservations_map'],'reservaciones'=>['reservations','pdv','reservations_map'],'almacenes'=>['inventario'],'ofertas_producto'=>['menu','productos','pdv'],'recetas'=>['productos'],'operaciones_financieras'=>['finanzas']];
        if ($modulo !== null && !in_array($modulo,$compatibles[$tabla],true)) return false;
        $db = ActiveRecord::getDB();
        if (!$db) return false;
        if ($tabla === 'usuarios') {
            $stmt = $db->prepare('SELECT id FROM usuarios WHERE id=?'); $stmt->execute([$id]);
            if (!$stmt->get_result()->fetch_row()) return false;
            if ($ctx === 'global') return true;
            $stmt = $db->prepare('SELECT 1 FROM usuario_unidades WHERE usuario_id=? AND unidad_id=?');
            $stmt->execute([$id,$perfil['ambitos'][$ctx]['id']]);
            return (bool)$stmt->get_result()->fetch_row();
        }
        $stmt = $db->prepare('SELECT unidad_id FROM ' . $tabla . ' WHERE id=?'); $stmt->execute([$id]);
        $row = $stmt->get_result()->fetch_row();
        return $row && (($ctx === 'global' && $nivel === 'ver') || (int)$row[0] === (int)$perfil['ambitos'][$ctx]['id']);
    }
}
