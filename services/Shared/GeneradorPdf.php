<?php

namespace Services\Shared;

/** Presenta únicamente hechos ya preparados y autorizados por el módulo. */
final class GeneradorPdf
{
    public static function documento(array $documento): \Dompdf\Dompdf
    {
        self::validar($documento);
        $raiz = realpath(dirname(__DIR__, 2));
        $fuentes = $raiz === false ? false : realpath($raiz . '/public/build/fonts');
        if ($fuentes === false) {
            throw new \RuntimeException('No se encontraron las fuentes del documento.');
        }
        $fontsDir = str_replace('\\', '/', $fuentes);
        ob_start();
        try {
            require $raiz . '/views/admin/reportes/base.php';
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $opciones = new \Dompdf\Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isPhpEnabled', false);
        $opciones->set('isJavascriptEnabled', false);
        $opciones->set('defaultFont', 'Montserrat');
        $opciones->setChroot([$fuentes]);
        $pdf = new \Dompdf\Dompdf($opciones);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $fuente = $pdf->getFontMetrics()->getFont('Montserrat', 'normal');
        if ($fuente !== null) {
            $pdf->getCanvas()->page_text(500, 817, '{PAGE_NUM} / {PAGE_COUNT}', $fuente, 8, [0.25, 0.30, 0.25]);
        }
        return $pdf;
    }

    public static function emitir(array $documento, string $nombreArchivo): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]*\.pdf\z/', $nombreArchivo)) {
            throw new \InvalidArgumentException('Nombre de archivo inválido.');
        }
        $pdf = self::documento($documento);
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $pdf->stream($nombreArchivo, ['Attachment' => false]);
    }

    private static function validar(array $doc): void
    {
        foreach (['titulo', 'contexto', 'generadoEn', 'corteDatos', 'resumen'] as $clave) {
            if (!isset($doc[$clave]) || !is_string($doc[$clave])) {
                throw new \InvalidArgumentException('Documento incompleto: ' . $clave);
            }
        }
        if (!isset($doc['periodo']) || !is_array($doc['periodo'])) {
            throw new \InvalidArgumentException('Periodo documental inválido.');
        }
        RangoPeriodo::resolverInforme(['desde' => $doc['periodo']['inicio'] ?? null, 'hasta' => $doc['periodo']['fin'] ?? null]);
        foreach (['kpis', 'secciones', 'notas'] as $clave) {
            if (!isset($doc[$clave]) || !is_array($doc[$clave])) {
                throw new \InvalidArgumentException('Documento incompleto: ' . $clave);
            }
        }
        foreach ($doc['kpis'] as $kpi) {
            if (!is_array($kpi) || !isset($kpi['etiqueta'], $kpi['formato']) || !is_string($kpi['etiqueta'])
                || !in_array($kpi['formato'], ['moneda', 'numero', 'cantidad', 'entero', 'porcentaje'], true)
                || !array_key_exists('valor', $kpi) || !self::numeroONulo($kpi['valor'])
                || (array_key_exists('comparacion', $kpi) && !self::numeroONulo($kpi['comparacion']))) {
                throw new \InvalidArgumentException('Indicador inválido.');
            }
        }
        foreach ($doc['secciones'] as $seccion) {
            if (!is_array($seccion) || !isset($seccion['titulo'], $seccion['tipo']) || !is_string($seccion['titulo'])) {
                throw new \InvalidArgumentException('Sección inválida.');
            }
            if (isset($seccion['vacio']) && !is_string($seccion['vacio'])) throw new \InvalidArgumentException('Aviso de vacío inválido.');
            if ($seccion['tipo'] === 'texto') {
                if (!isset($seccion['contenido']) || !is_string($seccion['contenido'])) throw new \InvalidArgumentException('Texto inválido.');
            } elseif (in_array($seccion['tipo'], ['tabla', 'serie', 'indicadores'], true)) {
                if (!isset($seccion['columnas'], $seccion['filas']) || !is_array($seccion['columnas']) || !is_array($seccion['filas']) || !$seccion['columnas']) throw new \InvalidArgumentException('Tabla inválida.');
                foreach ($seccion['columnas'] as $columna) if (!is_string($columna)) throw new \InvalidArgumentException('Columna inválida.');
                if (isset($seccion['formatos'])) {
                    if (!is_array($seccion['formatos']) || count($seccion['formatos']) !== count($seccion['columnas'])) throw new \InvalidArgumentException('Formatos inválidos.');
                    foreach ($seccion['formatos'] as $formato) if (!in_array($formato, ['texto', 'moneda', 'numero', 'cantidad', 'entero', 'porcentaje'], true)) throw new \InvalidArgumentException('Formato inválido.');
                }
                foreach ($seccion['filas'] as $fila) {
                    if (!is_array($fila) || count($fila) !== count($seccion['columnas'])) throw new \InvalidArgumentException('Fila inválida.');
                    foreach (array_values($fila) as $indice => $celda) {
                        $tipo = array_values($seccion['formatos'] ?? array_fill(0, count($fila), 'texto'))[$indice];
                        if ($tipo === 'texto' ? (!is_string($celda) && !self::numeroONulo($celda)) : !self::numeroONulo($celda)) throw new \InvalidArgumentException('Celda inválida.');
                    }
                }
            } else {
                throw new \InvalidArgumentException('Tipo de sección inválido.');
            }
        }
        foreach ($doc['notas'] as $nota) if (!is_string($nota)) throw new \InvalidArgumentException('Nota inválida.');
    }

    private static function numeroONulo($valor): bool
    {
        return $valor === null || ((is_int($valor) || is_float($valor)) && is_finite((float) $valor));
    }
}
