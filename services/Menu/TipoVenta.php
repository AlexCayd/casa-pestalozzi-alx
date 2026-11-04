<?php
declare(strict_types=1);
namespace Services\Menu;

use Services\Inventory\Articulos as A;
use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio as C;

/**
 * Tipo de venta de una oferta (docs/arquitectura.md §16). Es una regla de
 * validación sobre composiciones que el motor ya entiende: no descuenta, no
 * resuelve recetas y no crea otro modo de consumo.
 */
final class TipoVenta
{
    public const TIPOS = ['preparado','reventa'];
    public const ETIQUETAS = ['preparado'=>'Preparado al momento','reventa'=>'Reventa'];

    /**
     * Sugeridor único. La migración m3-tipo-venta.sql y development.sql llevan
     * exactamente esta sentencia (lo verifica run-tipo-venta.php). Sólo toca
     * ofertas en «sugerido»: nunca reescribe una decisión humana.
     */
    public const SQL_SUGERIR = "UPDATE ofertas_producto o SET o.tipo_venta=IF(NOT EXISTS(SELECT 1 FROM variante_ajustes va WHERE va.oferta_id=o.id AND va.unidad_id=o.unidad_id) AND (CASE WHEN EXISTS(SELECT 1 FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 WHERE p.oferta_id=o.id AND p.unidad_id=o.unidad_id) THEN NOT EXISTS(SELECT 1 FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.oferta_id=o.id AND p.unidad_id=o.unidad_id AND ((SELECT COUNT(*) FROM presentacion_componentes c WHERE c.version_id=v.id)<>1 OR NOT EXISTS(SELECT 1 FROM presentacion_componentes c WHERE c.version_id=v.id AND c.modalidad='almacenado' AND c.etapa='servicio' AND c.unidad='pza' AND c.cantidad=1))) WHEN EXISTS(SELECT 1 FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1) THEN (SELECT COUNT(*) FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1)=1 AND EXISTS(SELECT 1 FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id JOIN ingredientes i ON i.id=rc.articulo_id LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 AND rc.subreceta_id IS NULL AND ca.componente_id IS NULL AND i.unidad='pza' AND rc.cantidad=1) ELSE 0 END),'reventa','preparado') WHERE o.tipo_venta_estado='sugerido'";

    public static function sugerir(\mysqli $db): int
    {
        $db->query(self::SQL_SUGERIR);
        return $db->affected_rows;
    }

    public static function normalizar(mixed $tipo): string
    {
        if (!is_string($tipo) || !in_array($tipo, self::TIPOS, true)) {
            throw new InventarioError('TIPO_VENTA_INVALIDO', 'Elige si el producto se prepara al momento o es de reventa.');
        }
        return $tipo;
    }

    /** @return array{tipo_venta:string,tipo_venta_estado:string} */
    public static function deOferta(int $oferta, int $u, bool $bloquear = false): array
    {
        $r = A::sql('SELECT tipo_venta,tipo_venta_estado FROM ofertas_producto WHERE id=? AND unidad_id=?'.($bloquear ? ' FOR UPDATE' : ''), [$oferta, $u])->get_result()->fetch_assoc();
        if (!$r) throw new InventarioError('OFERTA_DENEGADA', 'La oferta no pertenece al negocio activo.');
        return $r;
    }

    /** Sólo una reventa confirmada restringe composición: «sugerido» se vende como siempre. */
    public static function reventaConfirmada(int $oferta, int $u, bool $bloquear = false): bool
    {
        $t = self::deOferta($oferta, $u, $bloquear);
        return $t['tipo_venta'] === 'reventa' && $t['tipo_venta_estado'] === 'confirmado';
    }

    /** Componentes de una versión de presentación: [{modalidad,etapa,...}]. */
    public static function validarPresentacion(array $componentes): void
    {
        if (count($componentes) !== 1) {
            throw new InventarioError('REVENTA_COMPOSICION', 'Un producto de reventa descuenta un solo artículo almacenado por unidad vendida.');
        }
        $c = array_values($componentes)[0];
        if (($c['modalidad'] ?? '') !== 'almacenado' || ($c['etapa'] ?? 'servicio') !== 'servicio') {
            throw new InventarioError('REVENTA_COMPOSICION', 'En un producto de reventa el artículo se descuenta de existencias al servir, sin elaboración.');
        }
    }

    /** Componentes normalizados de RecetasOferta: [{articulo_id,subreceta_id,version_receta_id}]. */
    public static function validarReceta(array $componentes): void
    {
        if ($componentes === []) return; // Retirar la receta deja la oferta sin consumo; no viola reventa.
        if (count($componentes) !== 1) {
            throw new InventarioError('REVENTA_COMPOSICION', 'Un producto de reventa descuenta un solo artículo almacenado por unidad vendida.');
        }
        $c = array_values($componentes)[0];
        if (($c['articulo_id'] ?? null) === null || ($c['subreceta_id'] ?? null) !== null || ($c['version_receta_id'] ?? null) !== null) {
            throw new InventarioError('REVENTA_COMPOSICION', 'En un producto de reventa el componente es un artículo de existencias, no una preparación ni una elaboración.');
        }
    }

    public static function validarVariantes(bool $hayAjustesDeArticulos): void
    {
        if ($hayAjustesDeArticulos) {
            throw new InventarioError('REVENTA_SIN_AJUSTES', 'Un producto de reventa sólo admite variantes de precio: no puede agregar, quitar ni sustituir artículos.');
        }
    }

    /** Composición vigente completa (presentaciones o receta) y variantes. */
    public static function exigirReventaValida(int $oferta, int $u): void
    {
        $versiones = A::sql('SELECT v.id FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.oferta_id=? AND p.unidad_id=?', [$oferta, $u])->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($versiones) {
            foreach ($versiones as $v) self::validarPresentacion(PresentacionesOferta::componentes((int)$v['id']));
        } else {
            $receta = A::sql('SELECT rc.articulo_id,rc.subreceta_id,ca.version_receta_id FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id WHERE r.oferta_id=? AND r.unidad_id=? AND r.activo=1 ORDER BY rc.id', [$oferta, $u])->get_result()->fetch_all(MYSQLI_ASSOC);
            self::validarReceta($receta);
        }
        self::validarVariantes((bool)A::sql('SELECT 1 FROM variante_ajustes WHERE oferta_id=? AND unidad_id=? LIMIT 1', [$oferta, $u])->get_result()->fetch_row());
    }

    /**
     * Fija el tipo dentro de la transacción del llamador (alta o edición de la
     * oferta) y lo deja confirmado: quien guardó el formulario lo revisó.
     */
    public static function fijar(int $oferta, int $u, string $tipo): void
    {
        $tipo = self::normalizar($tipo);
        self::deOferta($oferta, $u, true);
        if ($tipo === 'reventa') self::exigirReventaValida($oferta, $u);
        A::sql("UPDATE ofertas_producto SET tipo_venta=?,tipo_venta_estado='confirmado' WHERE id=? AND unidad_id=?", [$tipo, $oferta, $u]);
    }

    /**
     * Confirmación en lote desde el filtro «Tipo por confirmar». Confirma el
     * tipo sugerido de cada oferta válida; las que ya no cumplen quedan en
     * «sugerido» con su motivo, sin escrituras parciales en ellas.
     *
     * @param list<int|string> $ids
     */
    public static function confirmarLote(string $ctx, array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 200) {
            throw new InventarioError('DATOS_INVALIDOS', 'Selecciona de 1 a 200 productos.');
        }
        $limpios = [];
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id)) {
                throw new InventarioError('ID_INVALIDO', 'Un producto seleccionado no es válido.');
            }
            $limpios[(int)$id] = true;
        }
        $ids = array_keys($limpios);
        sort($ids);
        return C::en($ctx, static function () use ($ctx, $ids): array {
            return C::transaccion(static function () use ($ctx, $ids): array {
                C::exigir('menu', 'editar', $ctx, true);
                $u = C::vendedora($ctx);
                $confirmadas = [];
                $rechazadas = [];
                foreach ($ids as $id) {
                    $t = A::sql('SELECT o.tipo_venta,o.tipo_venta_estado,p.nombre FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id WHERE o.id=? AND o.unidad_id=? FOR UPDATE', [$id, $u])->get_result()->fetch_assoc();
                    // Un ID ajeno se rechaza igual que uno inexistente.
                    if (!$t) throw new InventarioError('OFERTA_DENEGADA', 'Un producto seleccionado no pertenece al negocio activo.');
                    if ($t['tipo_venta_estado'] === 'confirmado') { $confirmadas[] = $id; continue; }
                    try {
                        if ($t['tipo_venta'] === 'reventa') self::exigirReventaValida($id, $u);
                    } catch (InventarioError $e) {
                        $rechazadas[] = ['oferta_id' => $id, 'nombre' => $t['nombre'], 'mensaje' => $e->getMessage()];
                        continue;
                    }
                    A::sql("UPDATE ofertas_producto SET tipo_venta_estado='confirmado' WHERE id=? AND unidad_id=?", [$id, $u]);
                    $confirmadas[] = $id;
                }
                return ['codigo' => 'TIPO_VENTA_CONFIRMADO', 'confirmadas' => $confirmadas, 'rechazadas' => $rechazadas];
            });
        });
    }

    /** Conteo para el filtro «Tipo por confirmar» del Menú administrativo. */
    public static function pendientes(string $ctx): int
    {
        C::exigir('menu', 'ver', $ctx);
        return (int)A::sql("SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=? AND tipo_venta_estado='sugerido'", [C::vendedora($ctx)])->get_result()->fetch_row()[0];
    }
}
