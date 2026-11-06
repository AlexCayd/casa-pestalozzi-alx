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
            $modulos['menu']['titulo'] = 'Productos de la casa';
            $modulos['menu']['detalle'] = 'Identidades maestras y uso comercial autorizado; Global no vende.';
        }
        $modulos['inventario']['ruta']=($contexto==='restaurante'?'/admin':'/admin/'.$contexto).'/almacenes';
        $modulos['ia']['ruta']=($contexto==='restaurante'?'/admin':'/admin/'.$contexto).'/ia';
        $modulos['analytics']['ruta']=($contexto==='restaurante'?'/admin':'/admin/'.$contexto).'/analytics';
        if ($contexto === 'global') $modulos['analytics']['detalle'] = 'Los dos negocios comparados y cómo se venden los productos de la casa en cada uno.';
        if ($contexto === 'panaderia') $modulos['analytics']['detalle'] = 'Ventas en caja, lo más vendido, mapa de calor de venta y qué se lleva junto.';
        $modulos['finanzas']['ruta']=($contexto==='restaurante'?'/admin':'/admin/'.$contexto).'/finanzas';
        if ($contexto === 'global') $modulos['finanzas']['detalle'] = 'Estado de los dos negocios: hoy, el periodo lado a lado y el total de la organización. Sólo lectura.';
        if ($contexto === 'panaderia') $modulos['finanzas']['detalle'] = 'Ventas en caja, costo de insumos, merma, gastos fijos y estado de resultados.';
        if ($contexto !== 'restaurante') $modulos['ia']['detalle'] = $contexto==='global'
            ? 'Preguntas sobre los almacenes de la organización, las transferencias y los productos de la casa.'
            : 'Preguntas sobre las ventas en caja, los almacenes y el menú.';
        $modulos['reservations_map'] = ['titulo' => 'Mapa operativo de reservaciones', 'detalle' => 'Operación de reservas en el mapa, sin administración.', 'grupo' => Permisos::GRUPO_PISO, 'ruta' => '/admin/reservaciones/operacion'];
        if ($contexto === 'panaderia') {
            // El piso de Panadería es su caja de mostrador: vender y cobrar,
            // registrar lo que sale del horno y anular un cobro. Sin mesas,
            // producción ni mapa. Mismas claves que guarda la base (ddl.sql).
            $modulos['pdv']['titulo'] = 'Caja';
            $modulos['pdv']['detalle'] = 'Vender en mostrador, cobrar, imprimir el ticket y consultar el corte.';
            $modulos['pdv']['ruta'] = Contextos::CAJA_PANADERIA;
            $modulos['vitrina'] = ['titulo' => 'Horneadas', 'detalle' => 'Registrar en la caja las piezas que salen del horno.', 'grupo' => Permisos::GRUPO_PISO, 'ruta' => Contextos::CAJA_PANADERIA];
            $modulos['anular'] = ['titulo' => 'Anular tickets', 'detalle' => 'Anular un cobro del día y devolver sus piezas a la vitrina; también autoriza con su NIP la anulación de otra persona.', 'grupo' => Permisos::GRUPO_PISO, 'ruta' => Contextos::CAJA_PANADERIA];
        }
        $pisoDelAmbito = match ($contexto) {
            'restaurante' => ['pdv', 'area', 'reservations_map'],
            'panaderia' => ['pdv', 'vitrina', 'anular'],
            default => [],
        };
        foreach ($modulos as $key => &$modulo) {
            $operativo = $modulo['grupo'] === Permisos::GRUPO_PISO;
            if ($operativo && !in_array($key, $pisoDelAmbito, true)) { unset($modulos[$key]); continue; }
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
