<?php

namespace Classes\Impresion;

use Mike42\Escpos\Printer;

/**
 * Base de un documento imprimible ESC/POS.
 *
 * Cada documento concreto (Comanda, Cuenta) sabe escribirse a sí mismo en un
 * Printer ya conectado. Aquí viven sólo los helpers de formato de texto que
 * dependen del ancho del papel (en columnas de caracteres).
 */
abstract class Documento {

    protected int $ancho;

    public function __construct(int $ancho = 48) {
        // Un ancho razonable evita divisiones/restas negativas en el formato.
        $this->ancho = $ancho > 0 ? $ancho : 48;
    }

    // Escribe el documento en una impresora ya conectada.
    abstract public function imprimir(Printer $printer): void;

    // Línea con texto a la izquierda y a la derecha, rellenando el centro.
    // $ancho permite ajustar a líneas de texto ampliado (p.ej. doble ancho => $ancho/2).
    protected function dosColumnas(string $izquierda, string $derecha, ?int $ancho = null): string {
        $ancho   = $ancho ?? $this->ancho;
        $derecha = (string)$derecha;
        $maxIzq  = $ancho - mb_strlen($derecha) - 1;

        if ($maxIzq < 1) {
            // El valor derecho no cabe junto a nada: imprímelo solo.
            return $derecha . "\n";
        }

        if (mb_strlen($izquierda) > $maxIzq) {
            $izquierda = mb_substr($izquierda, 0, $maxIzq);
        }

        $relleno = $ancho - mb_strlen($izquierda) - mb_strlen($derecha);
        return $izquierda . str_repeat(' ', max(1, $relleno)) . $derecha . "\n";
    }

    // Línea separadora del ancho completo del papel.
    protected function separador(string $caracter = '-'): string {
        return str_repeat($caracter, $this->ancho) . "\n";
    }

    // Envuelve texto largo respetando el ancho del papel.
    protected function ajustar(string $texto, int $sangria = 0): string {
        $ancho = max(1, $this->ancho - $sangria);
        $prefijo = str_repeat(' ', $sangria);
        $lineas = explode("\n", wordwrap($texto, $ancho, "\n", true));
        return $prefijo . implode("\n" . $prefijo, $lineas) . "\n";
    }

    // Formatea un importe monetario, p.ej. 120.5 -> "$120.50".
    protected function dinero($monto): string {
        return '$' . number_format((float)$monto, 2, '.', ',');
    }
}
