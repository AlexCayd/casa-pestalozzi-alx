<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Ticket de prueba: confirma visualmente que una impresora responde, está en red
 * y corta papel correctamente. Se dispara desde el CRUD admin (/admin/printers).
 *
 * $info: ['nombre'=>string, 'destino'=>string, 'rol'=>string, 'area'=>?string]
 */
class Prueba extends Documento {

    private array $info;

    public function __construct(array $info, int $ancho = 48) {
        parent::__construct($ancho);
        $this->info = $info;
    }

    public function imprimir(Printer $printer): void {
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("CASA PESTALOZZI\n");
        $printer->setTextSize(1, 1);
        $printer->text("TICKET DE PRUEBA\n");
        $printer->setEmphasis(false);
        $printer->feed();

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->separador());
        $printer->text($this->dosColumnas('Impresora:', $this->info['nombre'] ?? ''));
        $printer->text($this->dosColumnas('Rol:', $this->info['rol'] ?? ''));
        if (!empty($this->info['area'])) {
            $printer->text($this->dosColumnas('Área:', $this->info['area']));
        }
        $printer->text($this->dosColumnas('Destino:', $this->info['destino'] ?? ''));
        $printer->text($this->dosColumnas('Ancho:', $this->ancho . ' col'));
        $printer->text($this->dosColumnas('Fecha:', date('d/m/Y H:i')));
        $printer->text($this->separador());

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed();
        $printer->text("Si puede leer esto,\n");
        $printer->text("la impresora funciona.\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->feed(2);
        $printer->cut();
    }
}
