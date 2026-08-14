<?php

namespace Services;

use Model\ActiveRecord;
use Model\IngredienteProveedor;
use Model\Proveedor;

/**
 * Alta, baja y asignación de proveedores a ingredientes.
 *
 * La regla que justifica que esto sea un servicio y no cuatro llamadas sueltas
 * al modelo es la del preferente: marcar uno tiene que desmarcar al anterior,
 * y eso son dos escrituras que no pueden quedarse a medias.
 */
final class Proveedores
{
    /**
     * Reemplaza la lista de proveedores de un ingrediente con la que llega del
     * formulario.
     *
     * Se sustituye en bloque en vez de ir fila a fila porque el formulario
     * manda el estado completo: quitar una fila allí tiene que borrarla aquí, y
     * comparar altas, bajas y ediciones por separado era más código para el
     * mismo resultado.
     *
     * $filas: [['proveedor_id' => int, 'costo' => float, 'codigo' => ?string,
     *           'preferente' => bool], …]
     */
    public static function asignarAIngrediente(int $ingredienteId, array $filas): bool
    {
        if ($ingredienteId < 1) {
            return false;
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $db->query(
                'DELETE FROM ingrediente_proveedores WHERE ingrediente_id = ' . $ingredienteId
            );

            $stmt = $db->prepare(
                'INSERT INTO ingrediente_proveedores
                    (ingrediente_id, proveedor_id, costo, codigo, preferente)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            // Un solo preferente: el primero que venga marcado. El resto se
            // guarda igual, pero sin la marca.
            $yaHayPreferente = false;
            $vistos = [];

            foreach ($filas as $fila) {
                $proveedorId = (int) ($fila['proveedor_id'] ?? 0);
                if ($proveedorId < 1 || isset($vistos[$proveedorId])) {
                    // El UNIQUE de la tabla rechazaría el duplicado con un error
                    // que no dice cuál de las filas del formulario sobra.
                    continue;
                }
                $vistos[$proveedorId] = true;

                $costo = (float) ($fila['costo'] ?? 0);
                if ($costo < 0) {
                    $costo = 0;
                }

                $codigo = trim((string) ($fila['codigo'] ?? ''));
                $codigo = $codigo === '' ? null : mb_substr($codigo, 0, 60);

                $preferente = 0;
                if (!empty($fila['preferente']) && !$yaHayPreferente) {
                    $preferente = 1;
                    $yaHayPreferente = true;
                }

                $stmt->bind_param('iidsi', $ingredienteId, $proveedorId, $costo, $codigo, $preferente);
                if (!$stmt->execute()) {
                    throw new \RuntimeException($stmt->error);
                }
            }

            $stmt->close();
            $db->commit();

            return true;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignorado) {
                // Sin transacción viva no hay nada que deshacer.
            }
            error_log('Proveedores::asignarAIngrediente - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lee del POST la tabla repetible de proveedores del formulario de
     * ingrediente. Los tres campos viajan como arrays paralelos, así que el
     * índice es lo que une cada proveedor con su costo.
     */
    public static function filasDesdePost(array $post): array
    {
        $ids = (array) ($post['proveedor_id'] ?? []);
        $costos = (array) ($post['proveedor_costo'] ?? []);
        $codigos = (array) ($post['proveedor_codigo'] ?? []);
        $preferente = (string) ($post['proveedor_preferente'] ?? '');

        $filas = [];
        foreach ($ids as $indice => $id) {
            if ((int) $id < 1) {
                continue;
            }
            $filas[] = [
                'proveedor_id' => (int) $id,
                'costo' => (float) ($costos[$indice] ?? 0),
                'codigo' => (string) ($codigos[$indice] ?? ''),
                // El radio manda el índice de la fila elegida, no un booleano
                // por fila: sólo puede haber uno.
                'preferente' => (string) $indice === $preferente,
            ];
        }

        return $filas;
    }

    /**
     * Elimina un proveedor. Sus precios se van con él por la llave foránea en
     * cascada, pero el histórico de precios lo conserva con proveedor_id en
     * NULL: la subida de precio ocurrió aunque ya no le compremos.
     */
    public static function eliminar(int $proveedorId): bool
    {
        $proveedor = Proveedor::find($proveedorId);
        if (!$proveedor) {
            return false;
        }

        try {
            $proveedor->eliminar();
            return true;
        } catch (\Throwable $e) {
            error_log('Proveedores::eliminar - ' . $e->getMessage());
            return false;
        }
    }

    /** Proveedores de un ingrediente, para pintar su ficha. */
    public static function deIngrediente(int $ingredienteId): array
    {
        return $ingredienteId > 0 ? IngredienteProveedor::porIngrediente($ingredienteId) : [];
    }
}
