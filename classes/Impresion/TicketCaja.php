<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Comprobante de la caja de mostrador de Panadería: lo que el cliente se
 * lleva junto con su pan. Sin mesa ni comensales; con lo recibido y el cambio
 * cuando pagó en efectivo.
 *
 * $ticket: ['id', 'fecha' => 'Y-m-d H:i:s', 'cajero', 'metodo', 'total',
 *           'recibido', 'cambio', 'lineas' => [['nombre','variante','cantidad','precio','importe']]]
 */
class TicketCaja extends Documento {

    public function __construct(private array $ticket, int $ancho = 48) {
        parent::__construct($ancho);
    }

    public function imprimir(Printer $printer): void {
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("CASA PESTALOZZI\n");
        $printer->setTextSize(1, 1);
        $printer->text("PANADERIA\n");
        $printer->setEmphasis(false);
        $printer->feed();

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->dosColumnas('Ticket:', '#' . (int)$this->ticket['id']));
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)($this->ticket['fecha'] ?? ''));
        $printer->text($this->dosColumnas('Fecha:', $fecha ? $fecha->format('d/m/Y H:i') : date('d/m/Y H:i')));
        $cajero = trim((string)($this->ticket['cajero'] ?? ''));
        if ($cajero !== '') $printer->text($this->dosColumnas('Le atendió:', $cajero));
        $printer->text($this->separador('='));

        $piezas = 0;
        foreach ($this->ticket['lineas'] ?? [] as $l) {
            $cantidad = (int)($l['cantidad'] ?? 1);
            $piezas += $cantidad;
            $variante = trim((string)($l['variante'] ?? ''));
            $nombre = (string)($l['nombre'] ?? '') . ($variante !== '' ? ' (' . $variante . ')' : '');
            $printer->text($this->ajustar($cantidad . ' x ' . $nombre));
            $printer->text($this->dosColumnas('    ' . $this->dinero($l['precio'] ?? 0) . ' c/u', $this->dinero($l['importe'] ?? 0)));
        }
        $printer->text($this->separador('='));

        $printer->setEmphasis(true);
        $printer->setTextSize(2, 1);
        $printer->text($this->dosColumnas('TOTAL', $this->dinero($this->ticket['total'] ?? 0), intdiv($this->ancho, 2)));
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->text($this->dosColumnas('Piezas:', (string)$piezas));
        if (($this->ticket['metodo'] ?? '') === 'efectivo') {
            $printer->text($this->dosColumnas('Efectivo:', $this->dinero($this->ticket['recibido'] ?? 0)));
            $printer->text($this->dosColumnas('Cambio:', $this->dinero($this->ticket['cambio'] ?? 0)));
        } else {
            $printer->text($this->dosColumnas('Pago:', 'TARJETA'));
        }

        $printer->feed();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("Gracias por llevarse algo de la casa\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->feed(2);
        $printer->cut();
    }
}
