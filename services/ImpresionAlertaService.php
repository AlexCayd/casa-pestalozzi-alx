<?php

namespace Services;

use Classes\TicketPrinter;
use Model\ActiveRecord;

/**
 * Alertas de impresión para el piso.
 *
 * Cada vez que una comanda o una cuenta no llega a su impresora con el
 * servicio de impresión ENCENDIDO, queda una fila en `impresion_alertas`. El
 * POS las recibe en su refresco y las enseña en todas las tablets hasta que
 * alguien las marca como atendidas: el mesero que envió puede no estar mirando
 * cuando falla, y quien pasa junto a la impresora sí.
 *
 * Todo aquí es tan efecto secundario como la impresión misma: ningún método
 * lanza. Un fallo al guardar una alerta se queda en el log y el envío de la
 * comanda o el cobro siguen su curso.
 */
class ImpresionAlertaService
{
    /**
     * Ventana de lo que se muestra. Una alerta sin atender de ayer ya no pide
     * nada al turno de hoy, y el POS no debe arrastrarla indefinidamente.
     */
    private const HORAS_VIGENCIA = 16;

    /** Tope de lo que viaja al POS en cada refresco. */
    private const LIMITE = 30;

    /**
     * Persiste los fallos que TicketPrinter recogió en esta petición.
     *
     * @param array<int, array<string, mixed>> $fallos   TicketPrinter::fallos()
     * @param array<string, mixed>             $contexto ticket_id, mesa_nombre, usuario_id
     * @return array<int, array<string, mixed>> Las alertas creadas, ya serializadas.
     */
    public static function registrar(array $fallos, array $contexto): array
    {
        if ($fallos === [] || !TicketPrinter::servicioActivo()) {
            return [];
        }

        $creadas = [];
        try {
            $db = ActiveRecord::getDB();
            $stmt = $db->prepare(
                'INSERT INTO impresion_alertas
                    (documento, motivo, ticket_id, mesa_nombre, area_id, area_nombre,
                     impresora_nombre, detalle, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }

            $ticketId = !empty($contexto['ticket_id']) ? (int)$contexto['ticket_id'] : null;
            $mesa = self::texto($contexto['mesa_nombre'] ?? null, 60);
            $usuarioId = !empty($contexto['usuario_id']) ? (int)$contexto['usuario_id'] : null;

            foreach ($fallos as $fallo) {
                $documento = ($fallo['documento'] ?? '') === 'cuenta' ? 'cuenta' : 'comanda';
                $motivo = in_array($fallo['motivo'] ?? '', ['sin_impresora', 'sin_conexion', 'fallo_envio'], true)
                    ? (string)$fallo['motivo']
                    : 'fallo_envio';
                $areaId = isset($fallo['area_id']) && (int)$fallo['area_id'] > 0 ? (int)$fallo['area_id'] : null;
                $areaNombre = self::texto($fallo['area_nombre'] ?? null, 60);
                $impresora = self::texto($fallo['impresora'] ?? null, 60);
                $detalle = self::texto($fallo['detalle'] ?? null, 255);

                $stmt->bind_param(
                    'ssisisssi',
                    $documento,
                    $motivo,
                    $ticketId,
                    $mesa,
                    $areaId,
                    $areaNombre,
                    $impresora,
                    $detalle,
                    $usuarioId
                );
                if (!$stmt->execute()) {
                    error_log('ImpresionAlertaService::registrar - ' . $stmt->error);
                    continue;
                }
                $creadas[] = (int)$db->insert_id;
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('ImpresionAlertaService::registrar - ' . $e->getMessage());
            return [];
        }

        return $creadas === [] ? [] : self::porIds($creadas);
    }

    /**
     * Alertas sin atender de la ventana vigente, la más reciente primero.
     * Con el servicio apagado devuelve vacío aunque haya filas viejas: apagar
     * la impresión es decir «no esperamos papel», y avisar de que no salió
     * sería ruido.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendientes(): array
    {
        if (!TicketPrinter::servicioActivo()) {
            return [];
        }

        try {
            $horas = self::HORAS_VIGENCIA;
            $limite = self::LIMITE;
            $resultado = ActiveRecord::getDB()->query(
                "SELECT * FROM impresion_alertas
                 WHERE atendida_at IS NULL
                   AND created_at >= NOW() - INTERVAL {$horas} HOUR
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$limite}"
            );
            if (!$resultado) {
                throw new \RuntimeException(ActiveRecord::getDB()->error);
            }
            $filas = $resultado->fetch_all(MYSQLI_ASSOC);
            $resultado->free();
        } catch (\Throwable $e) {
            // Una base sin la tabla todavía no debe tumbar el mapa de mesas.
            error_log('ImpresionAlertaService::pendientes - ' . $e->getMessage());
            return [];
        }

        return array_map([self::class, 'serializar'], $filas);
    }

    /**
     * Marca como atendidas las alertas indicadas, o todas las pendientes si
     * `$ids` es null. Devuelve cuántas cambiaron.
     *
     * @param array<int, int>|null $ids
     */
    public static function atender(?array $ids, int $usuarioId): int
    {
        try {
            $db = ActiveRecord::getDB();
            $usuario = $usuarioId > 0 ? $usuarioId : 'NULL';
            if ($ids === null) {
                $filtro = '';
            } else {
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                if ($ids === []) {
                    return 0;
                }
                $filtro = ' AND id IN (' . implode(',', $ids) . ')';
            }
            if (!$db->query(
                "UPDATE impresion_alertas
                 SET atendida_at = NOW(), atendida_por = {$usuario}
                 WHERE atendida_at IS NULL{$filtro}"
            )) {
                throw new \RuntimeException($db->error);
            }
            return (int)$db->affected_rows;
        } catch (\Throwable $e) {
            error_log('ImpresionAlertaService::atender - ' . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function porIds(array $ids): array
    {
        $ids = implode(',', array_map('intval', $ids));
        $resultado = ActiveRecord::getDB()->query(
            "SELECT * FROM impresion_alertas WHERE id IN ({$ids}) ORDER BY id DESC"
        );
        if (!$resultado) {
            return [];
        }
        $filas = $resultado->fetch_all(MYSQLI_ASSOC);
        $resultado->free();

        return array_map([self::class, 'serializar'], $filas);
    }

    /**
     * Forma que consume el POS. El texto se redacta aquí, una sola vez, para
     * que el aviso inmediato y la bandeja digan exactamente lo mismo.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function serializar(array $fila): array
    {
        $documento = (string)$fila['documento'];
        $area = trim((string)($fila['area_nombre'] ?? ''));
        $mesa = trim((string)($fila['mesa_nombre'] ?? ''));
        $impresora = trim((string)($fila['impresora_nombre'] ?? ''));
        $motivo = (string)$fila['motivo'];

        $queNo = $documento === 'cuenta'
            ? 'La cuenta no se imprimió'
            : 'La comanda' . ($area !== '' ? ' de ' . $area : '') . ' no se imprimió';

        $porque = match ($motivo) {
            'sin_impresora' => $documento === 'cuenta'
                ? 'No hay impresora de caja activa.'
                : 'No hay impresora activa para ' . ($area !== '' ? $area : 'esa área') . '.',
            'sin_conexion' => ($impresora !== '' ? $impresora : 'La impresora') . ' no responde.',
            default => ($impresora !== '' ? $impresora : 'La impresora') . ' no terminó de imprimir.',
        };

        // Qué hacer. La comanda sí entró al tablero del área —el papel es una
        // copia—, así que lo urgente es avisar; la cuenta, en cambio, no tiene
        // otra vía hacia el cliente.
        $accion = $documento === 'cuenta'
            ? 'Revisa la impresora de caja y entrega la cuenta al cliente.'
            : 'El pedido sí está en el tablero' . ($area !== '' ? ' de ' . $area : '') .
              '. Avisa al área y revisa la impresora.';

        $alerta = [
            'id' => (int)$fila['id'],
            'documento' => $documento,
            'motivo' => $motivo,
            'ticket_id' => $fila['ticket_id'] !== null ? (int)$fila['ticket_id'] : null,
            'mesa' => $mesa !== '' ? $mesa : null,
            'area' => $area !== '' ? $area : null,
            'impresora' => $impresora !== '' ? $impresora : null,
            'titulo' => $queNo . ($mesa !== '' ? ' · ' . $mesa : ''),
            'mensaje' => $porque,
            'accion' => $accion,
            'creada_en' => (string)$fila['created_at'],
        ];
        // El detalle técnico lleva IPs y rutas de red: al piso le basta el
        // motivo; el administrador sí lo necesita para diagnosticar. Se lee
        // $_SESSION y no Auth::esAdmin() porque el refresco del mapa ya liberó
        // la sesión, y esAdmin() la reabriría con su bloqueo.
        if (($_SESSION['rol'] ?? '') === 'admin' && !empty($fila['detalle'])) {
            $alerta['detalle'] = (string)$fila['detalle'];
        }

        return $alerta;
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        $texto = trim((string)($valor ?? ''));
        if ($texto === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($texto, 0, $max) : substr($texto, 0, $max);
    }
}
