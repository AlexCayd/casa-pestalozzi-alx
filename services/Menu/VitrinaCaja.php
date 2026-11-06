<?php
declare(strict_types=1);

namespace Services\Menu;

use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio;
use Services\Security\PermisosUsuario;

/**
 * Cómo se vende cada pan en la caja de Panadería (arquitectura §7): sus
 * opciones —las mismas variantes de Services\Menu\Variantes— y la pieza
 * terminada de la vitrina que descuenta cada venta.
 *
 * Una oferta sin pieza se vende sin descontar vitrina (venta sin consumo, o
 * su receta si la tuviera con origen). Una opción sólo cambia la pieza: la
 * concha con nata sale de su propio artículo. Todas las opciones que cambian
 * pieza pertenecen a un mismo grupo, para que una venta resuelva una sola.
 *
 * La decisión es de la oferta (Menú), no del inventario: exige `menu/editar`
 * de Panadería y sólo admite artículos ya asociados a un almacén activo del
 * negocio. No crea artículos, asociaciones ni existencias.
 */
final class VitrinaCaja
{
    public const CTX = 'panaderia';

    public static function vista(): array
    {
        return ContextoNegocio::en(self::CTX, static function (): array {
            $perfil = ContextoNegocio::exigir('menu', 'ver', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $db = ContextoNegocio::db();
            $ofertas = self::sql($db,
                "SELECT o.id,o.producto_id,o.precio,o.activo,c.nombre categoria,c.orden categoria_orden,p.nombre,
                        vp.almacen_id,vp.articulo_id,vp.cantidad
                   FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id
                   JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
                   LEFT JOIN vitrina_piezas vp ON vp.oferta_id=o.id AND vp.unidad_id=o.unidad_id
                  WHERE o.unidad_id=? ORDER BY c.orden,c.nombre,o.orden,p.nombre",
                [$unidad]
            )->get_result()->fetch_all(MYSQLI_ASSOC);
            $porVariante = [];
            foreach (self::sql($db, 'SELECT variante_id,almacen_id,articulo_id,cantidad FROM vitrina_piezas_variante WHERE unidad_id=?', [$unidad])->get_result()->fetch_all(MYSQLI_ASSOC) as $v) {
                $porVariante[(int)$v['variante_id']] = self::pieza($v);
            }
            $productos = [];
            foreach ($ofertas as $o) {
                $grupos = Variantes::deProducto((int)$o['id'], self::CTX);
                foreach ($grupos as &$g) foreach ($g['opciones'] as &$op) $op['pieza'] = $porVariante[(int)$op['id']] ?? null;
                unset($g, $op);
                $productos[] = [
                    'id' => (int)$o['id'],
                    'nombre' => (string)$o['nombre'],
                    'categoria' => (string)$o['categoria'],
                    'precio' => (string)$o['precio'],
                    'activo' => (int)$o['activo'] === 1,
                    // Global puede proteger precio o receta: entonces las
                    // opciones no se editan aquí, pero sus piezas sí.
                    'opcionesEditables' => ProductosCasa::permite((int)$o['producto_id'], 'precio') && ProductosCasa::permite((int)$o['producto_id'], 'receta'),
                    'pieza' => $o['articulo_id'] !== null ? self::pieza($o) : null,
                    'grupos' => $grupos,
                ];
            }
            $piezas = self::sql($db,
                "SELECT aa.almacen_id,a.nombre almacen,aa.articulo_id,i.nombre,i.unidad,aa.saldo
                   FROM almacen_articulos aa
                   JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id
                   JOIN ingredientes i ON i.id=aa.articulo_id
                  WHERE aa.unidad_id=? AND aa.activo=1 AND a.activo=1 AND i.activo=1
                  ORDER BY a.nombre,i.nombre",
                [$unidad]
            )->get_result()->fetch_all(MYSQLI_ASSOC);
            return [
                'puedeEditar' => PermisosUsuario::permitido($perfil, self::CTX, 'menu', 'editar'),
                'productos' => $productos,
                'piezas' => array_map(static fn(array $p): array => [
                    'clave' => (int)$p['almacen_id'] . ':' . (int)$p['articulo_id'],
                    'almacen_id' => (int)$p['almacen_id'], 'almacen' => (string)$p['almacen'],
                    'articulo_id' => (int)$p['articulo_id'], 'nombre' => (string)$p['nombre'],
                    'unidad' => (string)$p['unidad'], 'saldo' => (string)$p['saldo'],
                ], $piezas),
            ];
        });
    }

    /**
     * Guarda en UNA transacción las opciones de la oferta y sus piezas.
     *
     * @param array|null $pieza  {almacen_id, articulo_id, cantidad} o null (no se vende de vitrina)
     * @param array|null $grupos Grupos con el formato de Variantes::guardar, más
     *                           `pieza` por opción; null deja las opciones como están.
     */
    public static function guardar(int $ofertaId, ?array $pieza, ?array $grupos): array
    {
        try {
            return ContextoNegocio::en(self::CTX, static function () use ($ofertaId, $pieza, $grupos): array {
                ContextoNegocio::exigir('menu', 'editar', self::CTX);
                $unidad = ContextoNegocio::vendedora(self::CTX);
                $actor = ContextoNegocio::actorId();
                $db = ContextoNegocio::db();
                $r = ContextoNegocio::transaccion(static function () use ($db, $unidad, $actor, $ofertaId, $pieza, $grupos): array {
                    ContextoNegocio::exigir('menu', 'editar', self::CTX, true);
                    $oferta = self::sql($db, 'SELECT id,producto_id FROM ofertas_producto WHERE id=? AND unidad_id=? FOR UPDATE', [$ofertaId, $unidad])->get_result()->fetch_assoc();
                    if (!$oferta) throw new InventarioError('OFERTA_NO_DISPONIBLE', 'El pan no pertenece a Panadería.');

                    // Piezas pedidas por opción, por grupo y nombre: las opciones
                    // nuevas aún no tienen id hasta que Variantes las guarda.
                    $porOpcion = [];
                    if ($grupos !== null) {
                        $editables = ProductosCasa::permite((int)$oferta['producto_id'], 'precio') && ProductosCasa::permite((int)$oferta['producto_id'], 'receta');
                        $limpios = [];
                        foreach (array_values($grupos) as $g) {
                            if (!is_array($g)) throw new InventarioError('DATOS_INVALIDOS', 'Un grupo de opciones no es válido.');
                            $ops = [];
                            foreach (array_values(is_array($g['opciones'] ?? null) ? $g['opciones'] : []) as $op) {
                                if (!is_array($op)) throw new InventarioError('DATOS_INVALIDOS', 'Una opción no es válida.');
                                if (array_key_exists('pieza', $op) && $op['pieza'] !== null) {
                                    $porOpcion[mb_strtolower(trim((string)($g['nombre'] ?? ''))) . "\0" . mb_strtolower(trim((string)($op['nombre'] ?? '')))] = $op['pieza'];
                                }
                                unset($op['pieza']);
                                $ops[] = $op;
                            }
                            $limpios[] = ['nombre' => $g['nombre'] ?? '', 'opciones' => $ops];
                        }
                        if ($editables) {
                            $v = Variantes::guardar($ofertaId, $limpios, self::CTX);
                            if (($v['ok'] ?? null) === false) {
                                throw new InventarioError('VARIANTES_INVALIDAS', implode(' ', (array)($v['errores'] ?? ['No fue posible guardar las opciones.'])));
                            }
                        }
                    }

                    if ($pieza === null) {
                        if ($porOpcion !== []) throw new InventarioError('PIEZA_BASE_REQUERIDA', 'Elige primero la pieza del pan; las opciones sólo la cambian.');
                        self::sql($db, 'DELETE FROM vitrina_piezas WHERE oferta_id=? AND unidad_id=?', [$ofertaId, $unidad]);
                        return ['ok' => true, 'oferta_id' => $ofertaId];
                    }

                    $base = self::validarPieza($db, $unidad, $pieza);
                    self::sql($db,
                        'INSERT INTO vitrina_piezas(oferta_id,unidad_id,almacen_id,articulo_id,cantidad,updated_by) VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE almacen_id=VALUES(almacen_id),articulo_id=VALUES(articulo_id),cantidad=VALUES(cantidad),updated_by=VALUES(updated_by)',
                        [$ofertaId, $unidad, $base['almacen_id'], $base['articulo_id'], $base['cantidad'], $actor]
                    );
                    self::sql($db, 'DELETE FROM vitrina_piezas_variante WHERE oferta_id=? AND unidad_id=?', [$ofertaId, $unidad]);
                    if ($porOpcion !== []) {
                        $actuales = self::sql($db, 'SELECT id,grupo,nombre FROM producto_variantes WHERE oferta_id=? AND unidad_id=?', [$ofertaId, $unidad])->get_result()->fetch_all(MYSQLI_ASSOC);
                        $ids = [];
                        foreach ($actuales as $a) $ids[mb_strtolower((string)$a['grupo']) . "\0" . mb_strtolower((string)$a['nombre'])] = ['id' => (int)$a['id'], 'grupo' => (string)$a['grupo']];
                        $gruposConPieza = [];
                        foreach ($porOpcion as $clave => $p) {
                            if (!isset($ids[$clave])) throw new InventarioError('VARIANTE_INVALIDA', 'Una opción con pieza propia ya no existe. Recarga la página.');
                            $gruposConPieza[$ids[$clave]['grupo']] = true;
                            $v = self::validarPieza($db, $unidad, is_array($p) ? $p : []);
                            self::sql($db, 'INSERT INTO vitrina_piezas_variante(variante_id,oferta_id,unidad_id,almacen_id,articulo_id,cantidad) VALUES (?,?,?,?,?,?)',
                                [$ids[$clave]['id'], $ofertaId, $unidad, $v['almacen_id'], $v['articulo_id'], $v['cantidad']]);
                        }
                        if (count($gruposConPieza) > 1) throw new InventarioError('VITRINA_AMBIGUA', 'Sólo las opciones de un mismo grupo pueden cambiar la pieza.');
                    }
                    return ['ok' => true, 'oferta_id' => $ofertaId];
                });
                if (($r['ok'] ?? false) !== true) return ['ok' => false, 'codigo' => $r['codigo'] ?? 'VITRINA_NO_GUARDADA', 'mensaje' => $r['mensaje'] ?? 'No se pudo guardar.'];
                return ['ok' => true, 'producto' => self::producto($ofertaId)];
            });
        } catch (\Throwable $e) {
            if (!$e instanceof InventarioError) error_log('VitrinaCaja::guardar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => $e instanceof InventarioError ? $e->codigo : 'VITRINA_NO_GUARDADA',
                'mensaje' => $e instanceof InventarioError ? $e->getMessage() : 'No se pudo guardar. Intenta de nuevo.'];
        }
    }

    /** Un solo producto, ya guardado, con el formato de vista(). */
    private static function producto(int $ofertaId): ?array
    {
        foreach (self::vista()['productos'] as $p) if ($p['id'] === $ofertaId) return $p;
        return null;
    }

    private static function validarPieza(\mysqli $db, int $unidad, array $p): array
    {
        $almacen = filter_var($p['almacen_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $articulo = filter_var($p['articulo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cantidad = trim((string)($p['cantidad'] ?? '1'));
        if ($almacen === false || $articulo === false) throw new InventarioError('PIEZA_INVALIDA', 'Elige la pieza de la vitrina.');
        if (!preg_match('/^\d{1,4}(?:\.\d{1,3})?$/D', $cantidad) || (float)$cantidad <= 0) throw new InventarioError('CANTIDAD_INVALIDA', 'Las piezas por venta van de 0.001 a 9999.');
        $ok = self::sql($db,
            'SELECT 1 FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN ingredientes i ON i.id=aa.articulo_id
              WHERE aa.almacen_id=? AND aa.articulo_id=? AND aa.unidad_id=? AND aa.activo=1 AND a.activo=1 AND i.activo=1 FOR SHARE',
            [(int)$almacen, (int)$articulo, $unidad]
        )->get_result()->fetch_row();
        if (!$ok) throw new InventarioError('PIEZA_NO_DISPONIBLE', 'Esa pieza no está activa en un almacén de Panadería.');
        return ['almacen_id' => (int)$almacen, 'articulo_id' => (int)$articulo, 'cantidad' => number_format((float)$cantidad, 3, '.', '')];
    }

    private static function pieza(array $f): array
    {
        return ['clave' => (int)$f['almacen_id'] . ':' . (int)$f['articulo_id'], 'almacen_id' => (int)$f['almacen_id'], 'articulo_id' => (int)$f['articulo_id'], 'cantidad' => (string)$f['cantidad']];
    }

    private static function sql(\mysqli $db, string $sql, array $args = []): \mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new \RuntimeException($db->error);
        if ($args !== []) {
            $tipos = '';
            foreach ($args as $a) $tipos .= is_int($a) ? 'i' : 's';
            $stmt->bind_param($tipos, ...$args);
        }
        if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); throw new \RuntimeException($e); }
        return $stmt;
    }
}
