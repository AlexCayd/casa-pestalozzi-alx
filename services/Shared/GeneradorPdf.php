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
        $opciones = new \Dompdf\Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isPhpEnabled', false);
        $opciones->set('isJavascriptEnabled', false);
        $opciones->set('defaultFont', 'Montserrat');
        $opciones->setChroot([$fuentes]);
        $pdf = new \Dompdf\Dompdf($opciones);
        // El adaptador SVG de Dompdf necesita el archivo de métricas, no el alias CSS.
        $fontUrl = 'file://' . $fontsDir . '/Montserrat-Regular.ttf';
        if (!$pdf->getFontMetrics()->registerFont(['family' => 'Montserrat', 'style' => 'normal', 'weight' => 'normal'], $fontUrl)) {
            throw new \RuntimeException('No se pudo cargar la fuente institucional de las gráficas.');
        }
        $fuenteGrafica = str_replace('\\', '/', (string) $pdf->getFontMetrics()->getFont('Montserrat', 'normal'));
        ob_start();
        try {
            require $raiz . '/views/admin/reportes/base.php';
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $fuente = $pdf->getFontMetrics()->getFont('Montserrat', 'normal');
        if ($fuente !== null) {
            $pdf->getCanvas()->page_text(500, 817, '{PAGE_NUM} / {PAGE_COUNT}', $fuente, 8, [0.38, 0.40, 0.43]);
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
        if (isset($doc['id']) && (!is_string($doc['id']) || !in_array($doc['id'], ['finanzas','analisis','reservaciones','inventario','tickets'], true))) throw new \InvalidArgumentException('Identificador documental inválido.');
        if (isset($doc['provisional']) && !is_bool($doc['provisional'])) throw new \InvalidArgumentException('Estado documental inválido.');
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
        $idsSeccion = [];
        foreach ($doc['secciones'] as $seccion) {
            if (!is_array($seccion) || !isset($seccion['titulo'], $seccion['tipo']) || !is_string($seccion['titulo'])) {
                throw new \InvalidArgumentException('Sección inválida.');
            }
            if (isset($seccion['id'])) {
                if (!is_string($seccion['id']) || !preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $seccion['id']) || in_array($seccion['id'], $idsSeccion, true)) throw new \InvalidArgumentException('Identificador de sección inválido.');
                $idsSeccion[] = $seccion['id'];
            }
            if (isset($seccion['orden']) && (!is_int($seccion['orden']) || $seccion['orden'] < 0 || $seccion['orden'] > 1000)) throw new \InvalidArgumentException('Orden de sección inválido.');
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
                if (isset($seccion['conSigno'])) {
                    if (!is_array($seccion['conSigno']) || !array_is_list($seccion['conSigno'])) throw new \InvalidArgumentException('Formato de signo inválido.');
                    foreach ($seccion['conSigno'] as $indice) if (!is_int($indice) || !isset($seccion['formatos'][$indice]) || $seccion['formatos'][$indice] === 'texto') throw new \InvalidArgumentException('Columna con signo inválida.');
                }
                if (isset($seccion['grafica'])) GraficaPdf::validar($seccion);
                if (isset($seccion['anchos'])) {
                    if (!is_array($seccion['anchos']) || count($seccion['anchos']) !== count($seccion['columnas'])) throw new \InvalidArgumentException('Anchos de tabla inválidos.');
                    foreach ($seccion['anchos'] as $ancho) if (!is_int($ancho) || $ancho < 1) throw new \InvalidArgumentException('Ancho de tabla inválido.');
                    if (array_sum($seccion['anchos']) !== 100) throw new \InvalidArgumentException('Anchos de tabla inválidos.');
                }
                if (isset($seccion['resaltadas']) && !is_array($seccion['resaltadas'])) throw new \InvalidArgumentException('Filas destacadas inválidas.');
                foreach ($seccion['resaltadas'] ?? [] as $fila) if (!is_int($fila) || $fila < 0 || $fila >= count($seccion['filas'])) throw new \InvalidArgumentException('Fila destacada inválida.');
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
