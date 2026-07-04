<?php

namespace Classes;

use Model\Impresora;
use Classes\Impresion\Comanda;
use Classes\Impresion\Cuenta;
use Classes\Impresion\Prueba;
use Classes\Impresion\Documento;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

/**
 * Fachada pública de la capa de impresión térmica (ESC/POS por red).
 *
 * La impresión es un EFECTO SECUNDARIO del flujo del POS: estos métodos NUNCA
 * lanzan excepciones. Cualquier fallo (impresora apagada, host inalcanzable,
 * área sin impresora configurada) se registra con error_log y se refleja en el
 * valor de retorno, pero jamás interrumpe al endpoint que los invoca.
 *
 * Reutiliza los documentos de Classes\Impresion y el modelo Model\Impresora.
 */
class TicketPrinter {

    /**
     * Detalle del último fallo de impresión (mensaje de la excepción subyacente).
     * Útil para el CRUD admin: la prueba de conexión lo muestra al operador para
     * que sepa POR QUÉ falló (host inalcanzable, recurso compartido inexistente,
     * permisos del recurso, puerto COM ocupado, etc.).
     */
    private static ?string $ultimoError = null;

    /** Devuelve (y no consume) el detalle del último fallo, o null si no hubo. */
    public static function ultimoError(): ?string {
        return self::$ultimoError;
    }

    /**
     * Imprime las comandas de cocina/barra, una por cada área de producción,
     * en la impresora configurada para esa área.
     *
     * @param array $itemsPorArea [ area_id => [ ['nombre','cantidad','comensal','nota','precio'], ... ] ]
     * @param array $meta         ['mesa'=>string, 'cliente'=>?string, 'hora'=>string, 'ticket_id'=>int]
     * @return array [ area_id => bool ]  true si esa comanda se envió a la impresora.
     */
    public static function imprimirComanda(array $itemsPorArea, array $meta): array {
        // Encabezado común a todas las comandas del ticket.
        $ticket = [
            'mesa'   => $meta['mesa']      ?? null,
            'nombre' => $meta['cliente']   ?? null,
            'folio'  => $meta['ticket_id'] ?? null,
            'hora'   => $meta['hora']      ?? null,
        ];

        $resultados = [];
        foreach ($itemsPorArea as $areaId => $items) {
            $areaId = (int)$areaId;
            try {
                $impresora = Impresora::comandaPorArea($areaId);
                if (!$impresora) {
                    error_log("TicketPrinter::imprimirComanda — sin impresora activa para el área {$areaId}");
                    $resultados[$areaId] = false;
                    continue;
                }

                $areaNombre = $items[0]['area_nombre'] ?? ('Área ' . $areaId);
                $doc = new Comanda($ticket, $areaNombre, $items, (int)$impresora->ancho);
                $resultados[$areaId] = self::enviar($impresora, $doc);
            } catch (\Throwable $e) {
                error_log("TicketPrinter::imprimirComanda — error en el área {$areaId}: " . $e->getMessage());
                $resultados[$areaId] = false;
            }
        }

        return $resultados;
    }

    /**
     * Imprime la cuenta de cobro del cliente en la impresora de rol 'cuenta'.
     *
     * @param array  $ticket     Fila del ticket: ['id','cliente','comensales','hora_apertura','mesa']
     * @param array  $items      [ ['nombre','precio','cantidad'], ... ] (ya sin cancelados)
     * @param string $metodoPago 'efectivo' | 'tarjeta'
     * @return bool true si la cuenta se envió a la impresora.
     */
    public static function imprimirCuenta(array $ticket, array $items, string $metodoPago): bool {
        try {
            $impresora = Impresora::cuenta();
            if (!$impresora) {
                error_log("TicketPrinter::imprimirCuenta — no hay impresora de cuenta activa configurada");
                return false;
            }

            $datos = [
                'mesa'        => $ticket['mesa']       ?? null,
                'nombre'      => $ticket['cliente']    ?? ($ticket['nombre'] ?? null),
                'folio'       => $ticket['id']         ?? ($ticket['folio']  ?? null),
                'comensales'  => $ticket['comensales'] ?? null,
                'metodo_pago' => $metodoPago,
            ];

            $doc = new Cuenta($datos, $items, (int)$impresora->ancho);
            return self::enviar($impresora, $doc);
        } catch (\Throwable $e) {
            error_log("TicketPrinter::imprimirCuenta — error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un ticket de prueba a UNA impresora concreta (sin importar su rol ni
     * si está activa). Lo usa el CRUD admin para verificar conectividad.
     *
     * @param Impresora    $impresora  Impresora destino.
     * @param string|null  $areaNombre Nombre legible del área (opcional, informativo).
     * @return bool true si la prueba se envió a la impresora.
     */
    public static function imprimirPrueba(Impresora $impresora, ?string $areaNombre = null): bool {
        try {
            $doc = new Prueba([
                'nombre'  => $impresora->nombre,
                'destino' => $impresora->destino(),
                'rol'     => $impresora->rol,
                'area'    => $areaNombre,
            ], (int)$impresora->ancho);

            return self::enviar($impresora, $doc);
        } catch (\Throwable $e) {
            error_log("TicketPrinter::imprimirPrueba — error: " . $e->getMessage());
            return false;
        }
    }

    // Abre la conexión (red o windows), escribe el documento y cierra.
    // Aísla por completo los errores de conexión.
    private static function enviar(Impresora $impresora, Documento $documento): bool {
        $conector = null;
        self::$ultimoError = null;
        try {
            $conector = self::conectar($impresora);
            $printer  = new Printer($conector);
            try {
                $documento->imprimir($printer);
            } finally {
                // close() finaliza también el conector subyacente.
                $printer->close();
                $conector = null;
            }
            return true;
        } catch (\Throwable $e) {
            if ($conector !== null) {
                // El Printer no llegó a construirse: cerramos el conector a mano.
                try { $conector->finalize(); } catch (\Throwable $ignored) {}
            }
            self::$ultimoError = $e->getMessage();
            error_log(
                "TicketPrinter — fallo al imprimir en {$impresora->nombre} " .
                "({$impresora->destino()}): " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Construye el conector ESC/POS según el tipo de conexión de la impresora:
     *  - 'red':     socket TCP (por defecto puerto 9100).
     *  - 'windows': nombre de impresora instalada/compartida en Windows (spooler
     *               RAW) o recurso smb://host/impresora.
     */
    private static function conectar(Impresora $impresora): PrintConnector {
        switch ($impresora->conexion) {
            case 'windows':
                return new WindowsPrintConnector((string)$impresora->dispositivo);
            default:
                return new NetworkPrintConnector($impresora->host, (int)$impresora->puerto);
        }
    }
}
