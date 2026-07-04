<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Cuenta / ticket de cobro: el detalle con precios y total que recibe el cliente.
 *
 * $ticket: ['nombre'=>?, 'mesa'=>?, 'comensales'=>?, 'folio'=>?, 'metodo_pago'=>?]
 * $items : cada uno ['nombre', 'cantidad', 'precio']
 */
class Cuenta extends Documento {

    private array $ticket;
    private array $items;

    public function __construct(array $ticket, array $items, int $ancho = 48) {
        parent::__construct($ancho);
        $this->ticket = $ticket;
        $this->items  = $items;
    }

    public function imprimir(Printer $printer): void {
        // Encabezado del negocio.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("CASA PESTALOZZI\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->feed();

        // Datos del ticket.
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $folio = $this->ticket['folio'] ?? null;
        if ($folio) {
            $printer->text($this->dosColumnas('Folio:', '#' . $folio));
        }
        $mesa = $this->ticket['mesa'] ?? null;
        if ($mesa) {
            $printer->text($this->dosColumnas('Mesa:', (string)$mesa));
        }
        $nombre = $this->ticket['nombre'] ?? null;
        if ($nombre) {
            $printer->text($this->dosColumnas('Cuenta:', $nombre));
        }
        $comensales = $this->ticket['comensales'] ?? null;
        if ($comensales) {
            $printer->text($this->dosColumnas('Comensales:', (string)$comensales));
        }
        $printer->text($this->dosColumnas('Fecha:', date('d/m/Y H:i')));
        $printer->text($this->separador('='));

        // Detalle de productos.
        $total = 0.0;
        foreach ($this->items as $item) {
            $cantidad = (int)($item['cantidad'] ?? 1);
            $precio   = (float)($item['precio'] ?? 0);
            $importe  = $precio * $cantidad;
            $total   += $importe;

            $printer->text($this->ajustar($cantidad . ' x ' . ($item['nombre'] ?? '')));
            // Precio unitario a la izquierda, importe de la línea a la derecha.
            $printer->text($this->dosColumnas(
                '    ' . $this->dinero($precio) . ' c/u',
                $this->dinero($importe)
            ));
        }

        $printer->text($this->separador('='));

        // Total.
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 1);
        // Texto a doble ancho: sólo caben ~la mitad de columnas por línea.
        $printer->text($this->dosColumnas('TOTAL', $this->dinero($total), intdiv($this->ancho, 2)));
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $metodo = $this->ticket['metodo_pago'] ?? null;
        if ($metodo) {
            $printer->text($this->dosColumnas('Pago:', mb_strtoupper($metodo)));
        }

        // Pie.
        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("Gracias por su visita\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->feed(2);
        $printer->cut();
    }
}
