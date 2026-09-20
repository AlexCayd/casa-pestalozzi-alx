<?php

namespace Classes;

use Model\Impresora;
use Model\ConfiguracionPos;
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

    /**
     * Segundos que se espera a que la impresora acepte la conexión TCP.
     *
     * Sin este límite, NetworkPrintConnector llama a fsockopen() sin timeout y
     * PHP cae en default_socket_timeout (60 s por omisión). Medido contra una
     * impresora apagada, cada intento tardaba 21 s en rendirse —el timeout de
     * TCP del sistema— y la impresión ocurre DENTRO de /api/enviar-comanda,
     * una vez por área y en serie: el mesero se quedaba 21 s por área mirando
     * un botón que no respondía, volvía a tocarlo y el pedido entraba
     * duplicado al tablero de producción.
     *
     * 2 s es holgado: en la LAN del restaurante una impresora encendida
     * contesta en milisegundos. Si no contestó en dos segundos, no va a
     * imprimir, y el pedido —que ya está guardado— no tiene por qué esperarla.
     */
    private const TIMEOUT_CONEXION = 2;

    /**
     * ¿Está encendido el servicio de impresión?
     *
     * Es un interruptor GLOBAL, distinto de `impresoras.activo`: apagarlo deja
     * las cinco estaciones dadas de alta y configuradas, pero ninguna comanda
     * ni ninguna cuenta llega a abrir un socket. Existe porque una instalación
     * puede tener las impresoras en el sistema sin el hardware conectado —el
     * caso de `deploy.sql`, que las siembra apuntando a 192.168.1.5x— y
     * entonces cada envío de comanda paga el timeout de conexión por área,
     * dentro de la petición y en serie.
     *
     * Se consulta una vez por proceso: el valor no cambia a media petición y
     * `imprimirComanda()` recorre varias áreas.
     */
    private static ?bool $servicioActivo = null;

    public static function servicioActivo(): bool {
        if (self::$servicioActivo === null) {
            self::$servicioActivo = ConfiguracionPos::impresionActiva();
        }
        return self::$servicioActivo;
    }

    /** Devuelve (y no consume) el detalle del último fallo, o null si no hubo. */
    public static function ultimoError(): ?string {
        return self::$ultimoError;
    }

    /**
     * Imprime las comandas de cocina/barra SEGMENTADAS por área de producción:
     * la orden se divide por el área de cada platillo y cada área recibe en su
     * impresora únicamente lo que le corresponde preparar (con mesero, mesa y
     * las notas de cada platillo). Las áreas sin platillos en este envío no
     * imprimen nada.
     *
     * @param array $items [ ['nombre','cantidad','comensal','nota','precio','area_id'], ... ]
     * @param array $meta  ['mesa'=>string|int, 'mesa_nombre'=>?string, 'cliente'=>?string,
     *                      'mesero'=>?string, 'hora'=>string, 'ticket_id'=>int]
     * @return array [ area_id => bool ]  true si la comanda de esa área se envió a la impresora.
     */
    public static function imprimirComanda(array $items, array $meta): array {
        // El corte va aquí arriba, no en enviar(): con el servicio apagado no
        // se consulta areas_produccion ni se busca impresora por área ni se
        // construye un solo Documento. El array vacío es el mismo que devuelve
        // una orden sin platillos, y quien lo recibe ya lo trata como «no hubo
        // nada que imprimir».
        if (!self::servicioActivo()) {
            return [];
        }

        // Encabezado común a todas las comandas del ticket.
        $ticket = [
            'mesa'        => $meta['mesa']        ?? null,
            'mesa_nombre' => $meta['mesa_nombre'] ?? null,
            'nombre'      => $meta['cliente']     ?? null,
            'mesero'      => $meta['mesero']      ?? null,
            'hora'        => $meta['hora']        ?? null,
        ];

        // Segmentar la orden: cada área sólo recibe sus platillos.
        $itemsPorArea = [];
        foreach ($items as $item) {
            $itemsPorArea[(int)($item['area_id'] ?? 0)][] = $item;
        }
        if (empty($itemsPorArea)) {
            return [];
        }

        // Nombres legibles de las áreas para el encabezado de cada comanda.
        $nombresArea = [];
        try {
            $areas = Impresora::consultarSQL(
                "SELECT id, nombre FROM areas_produccion ORDER BY id ASC"
            );
            foreach ($areas as $area) {
                $nombresArea[(int)$area->id] = $area->nombre;
            }
        } catch (\Throwable $e) {
            error_log('TicketPrinter::imprimirComanda — no se pudieron consultar las áreas: ' . $e->getMessage());
        }

        $resultados = [];
        foreach ($itemsPorArea as $areaId => $itemsArea) {
            try {
                $impresora = Impresora::comandaPorArea($areaId);
                if (!$impresora) {
                    error_log("TicketPrinter::imprimirComanda — sin impresora activa para el área {$areaId}");
                    $resultados[$areaId] = false;
                    continue;
                }

                $areaNombre = $nombresArea[$areaId] ?? ('Área ' . $areaId);
                $doc = new Comanda($ticket, $areaNombre, $itemsArea, (int)$impresora->ancho);
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
     * @param array  $ticket            Fila del ticket: ['id','cliente','comensales','hora_apertura','mesa','mesero']
     * @param array  $items             [ ['nombre','precio','cantidad','comensal'=>?], ... ] (ya sin cancelados)
     * @param string $metodoPago        'efectivo' | 'tarjeta'
     * @param bool   $separarComensales true = desglosar la cuenta por comensal (con subtotales)
     *                                  en un solo ticket físico.
     * @return bool true si la cuenta se envió a la impresora.
     */
    public static function imprimirCuenta(array $ticket, array $items, string $metodoPago, bool $separarComensales = false): bool {
        // Mismo corte que en la comanda, y por la misma razón: la cuenta se
        // imprime dentro del cierre de ticket, así que un host inalcanzable
        // retrasa el cobro y el token de feedback.
        if (!self::servicioActivo()) {
            return false;
        }

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
                'mesero'      => $ticket['mesero']     ?? null,
                'metodo_pago' => $metodoPago,
            ];

            $doc = new Cuenta($datos, $items, (int)$impresora->ancho, $separarComensales);
            return self::enviar($impresora, $doc);
        } catch (\Throwable $e) {
            error_log("TicketPrinter::imprimirCuenta — error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un ticket de prueba a UNA impresora concreta (sin importar su rol,
     * si está activa, ni si el servicio de impresión está encendido). Lo usa el
     * CRUD admin para verificar conectividad.
     *
     * Que ignore `servicioActivo()` es deliberado: la prueba es precisamente
     * con lo que se comprueba una estación ANTES de reanudar el servicio, y es
     * una acción manual del administrador —nadie espera detrás de ella, al
     * revés que un mesero en el envío de comanda.
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
                return new NetworkPrintConnector(
                    $impresora->host,
                    (int)$impresora->puerto,
                    self::TIMEOUT_CONEXION
                );
        }
    }
}
