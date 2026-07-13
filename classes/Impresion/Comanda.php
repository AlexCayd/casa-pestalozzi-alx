<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Comanda de cocina/barra: la lista de productos que un área de producción
 * debe preparar para un ticket. NO lleva precios; es una orden de trabajo.
 *
 * $ticket: ['nombre'=>?, 'mesa'=>?, 'mesa_nombre'=>?, 'mesero'=>?, 'comensales'=>?, 'folio'=>?]  (todas opcionales)
 * $items : cada uno ['nombre', 'cantidad', 'nota'=>?, 'comensal'=>?]
 */
class Comanda extends Documento {

    private array $ticket;
    private string $area;
    private array $items;

    public function __construct(array $ticket, string $area, array $items, int $ancho = 48) {
        parent::__construct($ancho);
        $this->ticket = $ticket;
        $this->area   = $area;
        $this->items  = $items;
    }

    public function imprimir(Printer $printer): void {
        // Encabezado: nombre del área en grande y centrado.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text(mb_strtoupper($this->area) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $mesa = $this->ticket['mesa'] ?? null;
        if ($mesa !== null && $mesa !== '') {
            $printer->setEmphasis(true);
            $printer->setTextSize(2, 1);
            $printer->text('MESA ' . $mesa . "\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
        }

        $mesaNombre = $this->ticket['mesa_nombre'] ?? null;
        if ($mesaNombre) {
            $printer->text($mesaNombre . "\n");
        }

        $nombre = $this->ticket['nombre'] ?? null;
        if ($nombre) {
            $printer->text($nombre . "\n");
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->separador());

        // Metadatos: folio, mesero, comensales, hora.
        $folio = $this->ticket['folio'] ?? null;
        if ($folio) {
            $printer->text($this->dosColumnas('Folio:', '#' . $folio));
        }
        $mesero = $this->ticket['mesero'] ?? null;
        if ($mesero) {
            $printer->text($this->dosColumnas('Mesero:', $mesero));
        }
        $comensales = $this->ticket['comensales'] ?? null;
        if ($comensales) {
            $printer->text($this->dosColumnas('Comensales:', (string)$comensales));
        }
        $hora = $this->ticket['hora'] ?? null;
        $printer->text($this->dosColumnas('Hora:', $hora ?: date('d/m/Y H:i')));
        $printer->text($this->separador());

        // Productos.
        $printer->setTextSize(1, 1);
        foreach ($this->items as $item) {
            $cantidad = (int)($item['cantidad'] ?? 1);
            $linea = $cantidad . ' x ' . ($item['nombre'] ?? '');

            $printer->setEmphasis(true);
            $printer->text($this->ajustar($linea));
            $printer->setEmphasis(false);

            $comensal = $item['comensal'] ?? null;
            if ($comensal !== null && $comensal !== '') {
                $printer->text($this->ajustar('Comensal ' . $comensal, 4));
            }

            $nota = trim((string)($item['nota'] ?? ''));
            if ($nota !== '') {
                $printer->text($this->ajustar('>> ' . $nota, 4));
            }
        }

        $printer->text($this->separador());
        $printer->feed(2);
        $printer->cut();
    }
}
