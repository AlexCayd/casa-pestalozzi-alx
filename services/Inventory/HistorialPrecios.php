<?php

namespace Services\Inventory;

use Services\Security\ContextoNegocio;

use Model\HistorialPrecio;

/**
 * Registro de cambios de precio.
 *
 * Se llama a mano desde los cuatro sitios que editan un precio, y no desde
 * ActiveRecord ni desde el guardar() de los modelos, por una razón concreta:
 * ActiveRecord::actualizar() reescribe TODAS las columnas de la fila, así que
 * el ajuste rápido de stock y la entrada de mercancía emiten un UPDATE que
 * incluye `costo` aunque no lo toquen. Un hook genérico llenaría el histórico
 * de cambios que nunca ocurrieron.
 *
 * Aun así, registrar() compara antes de escribir: es la última defensa contra
 * una fila de ruido, y hace que llamarlo de más sea inofensivo.
 */
final class HistorialPrecios
{
    /**
     * Tolerancia de comparación. Los precios llegan como string desde el POST
     * y como float desde la BD; sin un margen, 12.30 y 12.3000 se registrarían
     * como un cambio. Es medio diezmilésimo: por debajo de la escala de
     * ingredientes.costo, que es DECIMAL(10,4).
     */
    private const EPSILON = 0.00005;

    /**
     * Deja constancia de un cambio de precio. Devuelve true sólo si escribió.
     *
     * $anterior en null significa alta: no había precio del que venir.
     */
    public static function registrar(
        string $entidad,
        int $refId,
        $anterior,
        $nuevo,
        string $motivo = 'edicion',
        ?int $proveedorId = null,
        ?int $unidadId = null
    ): bool {
        if (ContextoNegocio::nuevo()) return self::registrarMultinegocio($entidad,$refId,$anterior,$nuevo,$motivo,$proveedorId,$unidadId);
        if (!in_array($entidad, HistorialPrecio::ENTIDADES, true)) {
            return false;
        }
        if ($refId < 1 || !is_numeric($nuevo)) {
            return false;
        }
        if (!in_array($motivo, HistorialPrecio::MOTIVOS, true)) {
            $motivo = 'edicion';
        }

        $precioNuevo = (float) $nuevo;
        $precioAnterior = ($anterior === null || $anterior === '') ? null : (float) $anterior;

        // Guardar sin tocar el precio no es un cambio de precio.
        if ($precioAnterior !== null && abs($precioNuevo - $precioAnterior) < self::EPSILON) {
            return false;
        }

        try {
            return HistorialPrecio::registrar([
                'entidad' => $entidad,
                'ref_id' => $refId,
                'precio_anterior' => $precioAnterior,
                'precio_nuevo' => $precioNuevo,
                'motivo' => $motivo,
                'proveedor_id' => $proveedorId,
                'usuario_id' => self::usuarioActual(),
            ]);
        } catch (\Throwable $e) {
            /*
             * El histórico no puede tumbar la edición que lo provocó: el
             * administrador vino a cambiar un precio, y perder la bitácora es
             * peor que nada pero mucho menos grave que perder el cambio.
             */
            error_log('HistorialPrecios::registrar - ' . $e->getMessage());
            return false;
        }
    }

    /** Atajo para el alta, donde no hay precio anterior. */
    public static function registrarAlta(string $entidad, int $refId, $precio, ?int $unidadId = null): bool
    {
        return self::registrar($entidad, $refId, null, $precio, 'alta');
    }

    private static function registrarMultinegocio(string $entidad,int $refId,$anterior,$nuevo,string $motivo,?int $proveedorId,?int $unidadId): bool
    {
        if (!in_array($entidad,HistorialPrecio::ENTIDADES,true) || $refId<1 || !is_numeric($nuevo)) return false;
        if (!in_array($motivo,HistorialPrecio::MOTIVOS,true)) $motivo='edicion';
        $nuevo=(float)$nuevo; $anterior=($anterior===null || $anterior==='')?null:(float)$anterior;
        if ($anterior!==null && abs($nuevo-$anterior)<self::EPSILON) return false;
        try {
            $unidadId??=ContextoNegocio::unidad(ContextoNegocio::slug());
            return HistorialPrecio::registrar(['unidad_id'=>$unidadId,'oferta_id'=>$entidad===HistorialPrecio::ENTIDAD_PRODUCTO?$refId:null,'articulo_id'=>$entidad===HistorialPrecio::ENTIDAD_INGREDIENTE?$refId:null,'precio_anterior'=>$anterior,'precio_nuevo'=>$nuevo,'motivo'=>$motivo,'proveedor_id'=>$proveedorId,'usuario_id'=>ContextoNegocio::actorId() ?: null]);
        } catch (\Throwable $e) { error_log('HistorialPrecios::registrar - '.$e->getMessage()); return false; }
    }
    private static function usuarioActual(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            return null;
        }

        $id = (int) ($_SESSION['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
