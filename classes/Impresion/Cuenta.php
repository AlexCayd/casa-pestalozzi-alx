<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Cuenta / ticket de cobro: el detalle con precios y total que recibe el cliente.
 *
 * $ticket: ['nombre'=>?, 'mesa'=>?, 'mesero'=>?, 'comensales'=>?, 'folio'=>?, 'metodo_pago'=>?]
 * $items : cada uno ['nombre', 'cantidad', 'precio', 'comensal'=>?]
 *
 * Con $porComensal = true la cuenta se desglosa en secciones por comensal
 * (los items sin comensal van a "General"), cada una con su subtotal, pero
 * todo sale en UN solo ticket físico con el total al final.
 */
class Cuenta extends Documento {

    private array $ticket;
    private array $items;
    private bool $porComensal;

    public function __construct(array $ticket, array $items, int $ancho = 48, bool $porComensal = false) {
        parent::__construct($ancho);
        $this->ticket      = $ticket;
        $this->items       = $items;
        $this->porComensal = $porComensal;
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
        $mesero = $this->ticket['mesero'] ?? null;
        if ($mesero) {
            $printer->text($this->dosColumnas('Le atendió:', $mesero));
        }
        $comensales = $this->ticket['comensales'] ?? null;
        if ($comensales) {
            $printer->text($this->dosColumnas('Comensales:', (string)$comensales));
        }
        $printer->text($this->dosColumnas('Fecha:', date('d/m/Y H:i')));
        $printer->text($this->separador('='));

        // Detalle de productos: corrido, o desglosado por comensal.
        $total = $this->porComensal
            ? $this->imprimirDetallePorComensal($printer)
            : $this->imprimirDetalle($printer, $this->items);

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

    // Imprime una lista de items (cantidad, precio unitario, importe) y
    // devuelve la suma de sus importes.
    private function imprimirDetalle(Printer $printer, array $items): float {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $cantidad  = (int)($item['cantidad'] ?? 1);
            $precio    = (float)($item['precio'] ?? 0);
            $importe   = $precio * $cantidad;
            $subtotal += $importe;

            $printer->text($this->ajustar($cantidad . ' x ' . ($item['nombre'] ?? '')));
            // Precio unitario a la izquierda, importe de la línea a la derecha.
            $printer->text($this->dosColumnas(
                '    ' . $this->dinero($precio) . ' c/u',
                $this->dinero($importe)
            ));
        }
        return $subtotal;
    }

    // Desglosa la cuenta en secciones por comensal (los items sin comensal al
    // final como "General"), cada una con su subtotal. Devuelve el total.
    private function imprimirDetallePorComensal(Printer $printer): float {
        $grupos   = [];   // comensal (int) => items
        $generales = [];  // items sin comensal asignado
        foreach ($this->items as $item) {
            $comensal = $item['comensal'] ?? null;
            if ($comensal !== null && $comensal !== '') {
                $grupos[(int)$comensal][] = $item;
            } else {
                $generales[] = $item;
            }
        }
        ksort($grupos);
        if (!empty($generales)) {
            $grupos['General'] = $generales;
        }

        $total   = 0.0;
        $primero = true;
        foreach ($grupos as $clave => $items) {
            if (!$primero) {
                $printer->text($this->separador());
            }
            $primero = false;

            $titulo = is_int($clave) ? 'COMENSAL ' . $clave : mb_strtoupper($clave);
            $printer->setEmphasis(true);
            $printer->text($titulo . "\n");
            $printer->setEmphasis(false);

            $subtotal = $this->imprimirDetalle($printer, $items);
            $total   += $subtotal;

            $printer->text($this->dosColumnas('Subtotal:', $this->dinero($subtotal)));
        }
        return $total;
    }
}
