<?php

namespace Services;

/**
 * Proyección visual exclusiva del mapa administrativo.
 *
 * Recibe hechos temporales y de ocupación ya calculados por los servicios de
 * dominio. No decide capacidad ni modifica la interpretación que consume POS.
 */
final class ReservacionMapaMesaPresenter
{
    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    public static function presentar(array $hechos): array
    {
        $utilizable = self::booleano($hechos['utilizable'] ?? false);
        $ticketAbierto = self::booleano($hechos['ticket_abierto'] ?? false);
        $reservaciones = (array)($hechos['reservaciones'] ?? []);

        if ($ticketAbierto) {
            return self::resultado('ocupada', [], 'ocupada', 'ticket');
        }

        $mejor = null;
        foreach ($reservaciones as $reservacion) {
            $reservacion = is_array($reservacion) ? $reservacion : [];
            $candidato = self::candidato($reservacion);
            if ($mejor === null || $candidato['rank'] > $mejor['rank']) {
                $mejor = $candidato;
            }
        }

        if ($mejor !== null && $utilizable) {
            return [
                'estado_visual' => $mejor['estado_visual'],
                'modificadores' => $mejor['modificadores'],
                'label' => $mejor['label'],
                'precedencia' => $mejor['precedencia'],
            ];
        }

        if (!$utilizable) {
            return self::resultado('no-utilizable', [], 'no utilizable', 'no-utilizable');
        }

        return self::resultado('libre', [], 'disponible', 'disponible');
    }

    /** @return array{rank:int,estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    private static function candidato(array $reservacion): array
    {
        $minutos = array_key_exists('minutos_para_inicio', $reservacion)
            ? self::enteroNulo($reservacion['minutos_para_inicio'])
            : self::enteroNulo($reservacion['minutos_restantes'] ?? null);
        // El servicio entrega `inicio_exacto` como hecho canónico. No se
        // infiere desde minutos negativos porque éstos también representan
        // una tolerancia vencida pendiente de ausencia.
        $inicioExacto = self::booleano($reservacion['inicio_exacto'] ?? false);
        $enTolerancia = self::booleano($reservacion['en_tolerancia'] ?? false);
        $toleranciaVencida = self::booleano($reservacion['tolerancia_vencida'] ?? false);
        $ausenciaPendiente = self::booleano($reservacion['ausencia_pendiente'] ?? false);
        $bloqueaExactamente = self::booleano($reservacion['bloquea_horario_exactamente'] ?? false);

        if ($inicioExacto || $bloqueaExactamente) {
            return [
                'rank' => 600,
                'estado_visual' => 'ocupada',
                'modificadores' => ['reservacion_bloqueante'],
                'label' => 'ocupada',
                'precedencia' => 'reservacion_exacta',
            ];
        }
        if ($enTolerancia) {
            return [
                'rank' => 500,
                'estado_visual' => 'reservacion-proxima',
                'modificadores' => ['reservacion_tolerancia'],
                'label' => 'reservación dentro de tolerancia',
                'precedencia' => 'tolerancia',
            ];
        }
        if ($minutos !== null && $minutos > 0 && $minutos <= 30) {
            return [
                'rank' => 400,
                'estado_visual' => 'reservacion-proxima',
                'modificadores' => ['reservacion_inminente'],
                'label' => 'reservación próxima; no asignar a un nuevo servicio',
                'precedencia' => 'reservacion_0_30',
            ];
        }
        if ($minutos !== null && $minutos > 30 && $minutos <= 60) {
            $detalle = 'disponible con reservación en ' . $minutos . ' minutos';
            return [
                'rank' => 300,
                'estado_visual' => 'libre',
                'modificadores' => ['reservacion_advertencia'],
                'label' => $detalle,
                'precedencia' => 'reservacion_30_60',
            ];
        }
        if ($toleranciaVencida && $ausenciaPendiente) {
            return [
                'rank' => 200,
                'estado_visual' => 'libre',
                'modificadores' => ['accion_pendiente', 'AUSENCIA_PENDIENTE'],
                'label' => 'disponible con ausencia pendiente',
                'precedencia' => 'tolerancia_vencida',
            ];
        }

        return [
            'rank' => 100,
            'estado_visual' => 'libre',
            'modificadores' => [],
            'label' => 'disponible',
            'precedencia' => 'disponible',
        ];
    }

    /** @return array{estado_visual:string,modificadores:array<int,string>,label:string,precedencia:string} */
    private static function resultado(string $estado, array $modificadores, string $label, string $precedencia): array
    {
        return [
            'estado_visual' => $estado,
            'modificadores' => $modificadores,
            'label' => $label,
            'precedencia' => $precedencia,
        ];
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }

    private static function enteroNulo($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return (int)$valor;
    }
}
