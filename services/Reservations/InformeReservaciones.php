<?php
declare(strict_types=1);

namespace Services\Reservations;

/** Lectura agregada del periodo de servicio; no modifica reservas. */
final class InformeReservaciones
{
    public static function documento(\mysqli $db, int $unidad, array $rango, \DateTimeImmutable $ahora): array
    {
        $estados = array_filter(ReservacionListadoPresenter::FILTROS, static fn ($clave): bool => $clave !== '', ARRAY_FILTER_USE_KEY);
        $claves = array_keys($estados);
        $marcadores = implode(',', array_fill(0, count($claves), '?'));
        $consulta = $db->prepare("SELECT fecha, estado, COUNT(*) total, COALESCE(SUM(comensales),0) personas
            FROM reservaciones WHERE unidad_id=? AND fecha>=? AND fecha<=? AND estado IN ({$marcadores})
            GROUP BY fecha, estado ORDER BY fecha, estado");
        if ($consulta === false || !$consulta->execute(array_merge([$unidad, $rango['start'], $rango['end']], $claves))) {
            throw new \RuntimeException('No se pudieron consultar las reservaciones.');
        }
        $resultado = $consulta->get_result();
        if ($resultado === false) throw new \RuntimeException('No se pudieron leer las reservaciones.');
        $conteos = array_fill_keys($claves, 0);
        $dias = [];
        $inicio = new \DateTimeImmutable($rango['start']);
        for ($i = 0; $i < $rango['dias']; $i++) {
            $dias[$inicio->modify('+' . $i . ' days')->format('Y-m-d')] = 0;
        }
        $personas = 0;
        foreach ($resultado->fetch_all(MYSQLI_ASSOC) as $fila) {
            $estado = $fila['estado'];
            $cantidad = (int) $fila['total'];
            $conteos[$estado] += $cantidad;
            $dias[$fila['fecha']] += $cantidad;
            $personas += (int) $fila['personas'];
        }
        $total = array_sum($conteos);
        $pendientes = $conteos['confirmada'] + $conteos['en_curso'];
        $provisional = $rango['end'] >= $ahora->format('Y-m-d');
        $resumen = $total === 0 ? 'No hubo reservaciones de negocio en el periodo.' : 'El informe reúne las reservaciones de negocio según su fecha programada.';
        $resumen .= $provisional
            ? ' El periodo incluye hoy o fechas futuras; sus resultados son provisionales.'
            : ' El periodo corresponde a fechas anteriores al día de generación.';
        if ($pendientes > 0) $resumen .= ' Hay reservaciones confirmadas o en curso pendientes de cierre.';
        $distribucion = [];
        foreach ($estados as $clave => $etiqueta) $distribucion[] = [$etiqueta, $conteos[$clave]];
        $serie = [];
        foreach ($dias as $fecha => $cantidad) $serie[] = [$fecha, $cantidad];
        $ordenados = $dias;
        arsort($ordenados);
        $demanda = [];
        foreach (array_slice($ordenados, 0, 7, true) as $fecha => $cantidad) {
            if ($cantidad > 0) $demanda[] = [$fecha, $cantidad];
        }
        $instante = $ahora->format('Y-m-d H:i:s');
        return [
            'titulo' => 'Informe de reservaciones' . ($provisional ? ' · Provisional' : ''), 'contexto' => 'Restaurante',
            'periodo' => ['inicio' => $rango['start'], 'fin' => $rango['end']],
            'generadoEn' => $instante, 'corteDatos' => $instante, 'resumen' => $resumen,
            'kpis' => [
                ['etiqueta' => 'Reservaciones de negocio', 'valor' => $total, 'formato' => 'entero'],
                ['etiqueta' => 'Comensales programados', 'valor' => $personas, 'formato' => 'entero'],
                ['etiqueta' => 'Atendidas', 'valor' => $conteos['completada'], 'formato' => 'entero'],
                ['etiqueta' => 'No llegaron registrados', 'valor' => $conteos['no_show'], 'formato' => 'entero'],
                ['etiqueta' => 'Canceladas', 'valor' => $conteos['cancelada'], 'formato' => 'entero'],
                ['etiqueta' => 'Pendientes de cierre', 'valor' => $pendientes, 'formato' => 'entero'],
            ],
            'secciones' => [
                ['titulo' => 'Distribución de estados', 'tipo' => 'tabla', 'columnas' => ['Estado', 'Reservaciones'], 'formatos' => ['texto', 'entero'], 'filas' => $distribucion],
                ['titulo' => 'Reservaciones por día', 'tipo' => 'serie', 'columnas' => ['Fecha', 'Reservaciones'], 'formatos' => ['texto', 'entero'], 'filas' => $serie],
                ['titulo' => 'Días de mayor demanda', 'tipo' => 'tabla', 'columnas' => ['Fecha', 'Reservaciones'], 'formatos' => ['texto', 'entero'], 'filas' => $demanda],
            ],
            'notas' => [
                'El periodo corresponde a la fecha programada de servicio, no a la fecha de registro ni de cancelación.',
                'Se incluyen confirmadas, en curso, completadas, no-show registrados y canceladas; se excluyen retenciones pendientes, expiradas y versiones reemplazadas.',
                'Los comensales son los programados, no una medición de asistencia real. No se infieren ausencias a partir de reservas confirmadas.',
                'Las confirmadas y en curso no han finalizado. Los estados son una instantánea al generar el documento y pueden cambiar.',
            ],
        ];
    }
}
