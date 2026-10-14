<?php

namespace Services\Pos;

/**
 * Presentación exclusiva del POS: tres estados y nada más.
 *
 *   Verde · libre                 se puede sentar a alguien.
 *   Rojo  · ocupada               no disponible: ticket abierto, barra llena o
 *                                 una regla operativa que impide abrir ticket.
 *   Azul  · reservacion-proxima   una reserva llega en menos de 60 min, está
 *                                 llegando o no ha llegado (sin_llegar) y la
 *                                 mesa sigue apartada.
 *
 * El color nunca va solo: cada mesa lleva una etiqueta corta («Libre»,
 * «Ocupada», «Reservada») y un subrótulo con el dato que hace falta para
 * decidir («en 45 min», «Sin llegar», «2/4»). Verde y rojo son justo el par que
 * se confunde con daltonismo.
 *
 * Caja y Llevar no son mesas: son fichas de acción, sin estado. Las barras no
 * se reservan; se llenan por tickets hasta su tope.
 *
 * Recibe hechos ya resueltos por MesaEstadoService. No calcula fechas,
 * minutos ni ventanas; sólo los traduce.
 */
final class PosMesaProjectionPresenter
{
    public const ETIQUETAS = [
        'libre' => 'Libre',
        'ocupada' => 'Ocupada',
        'reservacion-proxima' => 'Reservada',
        'no-utilizable' => 'No disponible',
    ];

    /** Ventanas de la política POS que apartan la mesa para la reserva. */
    private const VENTANAS_RESERVADA = ['advertencia', 'bloqueo', 'inicio', 'tolerancia'];

    /**
     * @return array{estado_visual:string,modificadores:array<int,string>,precedencia:string,aria_label:string,etiqueta:string,subtitulo:string,ficha:bool}
     */
    public static function presentar(array $hechos): array
    {
        $nombre = trim((string)($hechos['nombre'] ?? ''));
        $prefijo = $nombre !== '' ? $nombre . '. ' : '';

        if (self::booleano($hechos['ficha'] ?? false)) {
            return self::ficha($hechos, $prefijo);
        }

        if (!self::booleano($hechos['utilizable'] ?? false)) {
            if (self::booleano($hechos['multi_ticket'] ?? false)) {
                return self::barra($hechos, $prefijo);
            }
            if (self::booleano($hechos['mostrar_estado_ticket_pos'] ?? false)) {
                $ocupada = self::booleano($hechos['ticket_bloquea_consulta'] ?? false);
                $etiqueta = trim((string)($hechos['etiqueta_operacion_pos'] ?? 'Elemento')) ?: 'Elemento';
                return self::resultado(
                    $ocupada ? 'ocupada' : 'libre',
                    $ocupada ? ['ticket_abierto'] : [],
                    $ocupada ? 'ticket' : 'disponible',
                    $prefijo . ($ocupada
                        ? $etiqueta . ' ocupada por un ticket abierto.'
                        : $etiqueta . ' operativa. Disponible para abrir un ticket.')
                );
            }
            return self::resultado('no-utilizable', [], 'no-utilizable', $prefijo . 'No utilizable.');
        }

        $estado = 'libre';
        $modificadores = [];
        $precedencia = 'disponible';
        $ariaLabel = 'Libre. Disponible para sentar.';
        $subtitulo = '';
        $proyeccion = self::booleano($hechos['proyeccion'] ?? false);

        // En proyección sólo ocupa el ticket que SIGUE ocupando a la hora
        // consultada (su liberación estimada es posterior); el que se habrá
        // ido para entonces no pinta la mesa de rojo a las 20:00.
        $ticketPrioritario = $proyeccion
            ? self::booleano($hechos['ticket_bloquea_consulta'] ?? false)
            : self::booleano($hechos['ticket_abierto'] ?? false)
                || self::booleano($hechos['ocupada_fisicamente'] ?? false)
                || self::booleano($hechos['ticket_bloquea_consulta'] ?? false);
        if ($ticketPrioritario) {
            $estado = 'ocupada';
            $modificadores[] = 'ticket_abierto';
            $precedencia = 'ticket';
            $ariaLabel = 'Ocupada: ticket abierto.';
        }

        $reservacion = is_array($hechos['reservacion'] ?? null) ? $hechos['reservacion'] : [];
        if ($reservacion !== [] && $proyeccion) {
            [$estado, $modificadores, $precedencia, $ariaLabel, $subtitulo] = self::reservacionProyectada(
                $reservacion,
                $ticketPrioritario,
                [$estado, $modificadores, $precedencia, $ariaLabel, $subtitulo]
            );
        } elseif ($reservacion !== []) {
            $ventana = (string)($reservacion['ventana_visual_pos'] ?? $reservacion['ventana_pos'] ?? 'futura');
            $hora = substr((string)($reservacion['hora'] ?? ''), 0, 5);
            $minutos = isset($reservacion['minutos_para_inicio']) && $reservacion['minutos_para_inicio'] !== null
                ? (int)$reservacion['minutos_para_inicio']
                : null;
            $aLas = $hora !== '' ? ' para las ' . $hora : '';

            if (self::booleano($reservacion['ausencia_pendiente'] ?? false)) {
                // No llegó y venció la tolerancia. La ausencia se registra
                // sola en la siguiente lectura (ReservacionAusenciaAutomaticaService)
                // y la mesa queda libre; mientras tanto —segundos— sigue azul,
                // porque el walk-in todavía la rechaza.
                $modificadores[] = 'sin_llegar';
                $bloqueaWalkIns = array_key_exists('bloquea_walk_ins', $reservacion)
                    ? self::booleano($reservacion['bloquea_walk_ins'])
                    : !self::booleano($reservacion['disponible_para_ticket'] ?? false);
                if (!$ticketPrioritario && $bloqueaWalkIns) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'sin_llegar';
                    $subtitulo = 'Se libera';
                    $ariaLabel = 'Reservada' . $aLas . '. Venció la tolerancia: la ausencia se registra sola y la mesa queda libre en un momento.';
                } elseif ($ticketPrioritario) {
                    $ariaLabel .= ' Una reservación' . $aLas . ' venció la tolerancia; revísala.';
                }
            } elseif (in_array($ventana, self::VENTANAS_RESERVADA, true)) {
                $modificadores[] = 'ventana_' . $ventana;
                if (!$ticketPrioritario) {
                    $estado = 'reservacion-proxima';
                    $precedencia = 'reservacion_' . $ventana;
                    $subtitulo = match ($ventana) {
                        'advertencia', 'bloqueo' => $minutos !== null && $minutos > 0
                            ? 'en ' . $minutos . ' min'
                            : ($hora !== '' ? $hora : ''),
                        'inicio' => 'Llega ahora',
                        default => 'Esperando',
                    };
                    $ariaLabel = match ($ventana) {
                        'advertencia' => 'Reservada' . $aLas . ($minutos !== null ? ', llega en ' . $minutos . ' minutos' : '')
                            . '. Se puede sentar a alguien sólo si confirmas que se libera antes.',
                        'bloqueo' => 'Reservada' . $aLas . ($minutos !== null ? ', llega en ' . $minutos . ' minutos' : '')
                            . '. Ya no se pueden sentar walk-ins.',
                        'inicio' => 'Reservada' . $aLas . '. El cliente llega ahora.',
                        default => 'Reservada' . $aLas . '. Esperando al cliente dentro de la tolerancia.',
                    };
                }
                // Mesa ocupada con una reserva por llegar: no hay estado
                // compuesto. El rojo manda y la reserva se reubica sola a una
                // mesa libre (ReservacionReubicacionPorTicketService).
            }
        }

        if (self::booleano($hechos['asignada_actualmente'] ?? false)) {
            $modificadores[] = 'asignada_actualmente';
        }

        // Un hold es una reserva a medio confirmar (el cliente está metiendo
        // su código): la mesa ya está comprometida para esa hora, así que se
        // pinta azul como cualquier reserva y no roja como un ticket.
        if (!$ticketPrioritario
            && $estado === 'libre'
            && self::booleano($hechos['hold_vigente'] ?? false)) {
            $estado = 'reservacion-proxima';
            $modificadores[] = 'hold_vigente';
            $precedencia = 'hold';
            $subtitulo = 'Apartada';
            $ariaLabel = 'Apartada: una reservación se está confirmando para esta mesa.';
        }

        // Las reglas para abrir un ticket son de AHORA: en una proyección no
        // se opera, así que no tienen nada que decir sobre el color.
        if (!$proyeccion
            && !$ticketPrioritario
            && $estado === 'libre'
            && array_key_exists('puede_abrir_ticket', $hechos)
            && !self::booleano($hechos['puede_abrir_ticket'])) {
            $estado = 'ocupada';
            $modificadores[] = 'restriccion_operativa';
            $precedencia = 'restriccion_operativa';
            $ariaLabel = 'No disponible para abrir un ticket según las reglas operativas.';
        }

        return self::resultado(
            $estado,
            array_values(array_unique($modificadores)),
            $precedencia,
            $prefijo . $ariaLabel,
            $subtitulo
        );
    }

    /**
     * Mesa vista a una hora consultada desde el cajón, no ahora.
     *
     * Azul de 60 minutos antes al final del intervalo de 90 —la misma regla
     * que en vivo, contada desde la hora consultada—, y el subrótulo dice la
     * HORA de la reserva y no «en N min»: proyectando a las 20:00, «en 30 min»
     * se leería como contado desde el reloj de la pared. No hay «sin llegar»:
     * la ausencia es un hecho de ahora, no de una hora imaginada.
     *
     * @param array{0:string,1:array<int,string>,2:string,3:string,4:string} $actual
     * @return array{0:string,1:array<int,string>,2:string,3:string,4:string}
     */
    private static function reservacionProyectada(array $reservacion, bool $ticketPrioritario, array $actual): array
    {
        [$estado, $modificadores, $precedencia, $ariaLabel, $subtitulo] = $actual;
        $ventana = (string)($reservacion['ventana_mapa'] ?? 'futura');
        if (!in_array($ventana, ['advertencia', 'bloqueo', 'inicio', 'activa'], true)) {
            return $actual;
        }
        $hora = substr((string)($reservacion['hora'] ?? ''), 0, 5);
        $aLas = $hora !== '' ? ' para las ' . $hora : '';
        $enServicio = in_array($ventana, ['inicio', 'activa'], true);
        $modificadores[] = 'ventana_' . $ventana;

        // Un ticket que sigue ahí manda: rojo, sin señal de la reserva. Sólo
        // hay tres estados; la reserva se reubica sola si llega a su ventana.
        if ($ticketPrioritario) {
            return [$estado, $modificadores, $precedencia, $ariaLabel, $subtitulo];
        }

        return [
            'reservacion-proxima',
            $modificadores,
            'reservacion_' . $ventana,
            'Reservada' . $aLas . ($enServicio
                ? '. A esta hora la reservación está en curso.'
                : '. A esta hora la mesa ya se está apartando.'),
            $hora,
        ];
    }

    /** Caja y Llevar: fichas de acción, sin estado. Llevar dice cuántos pedidos lleva. */
    private static function ficha(array $hechos, string $prefijo): array
    {
        $abiertos = max(0, (int)($hechos['tickets_elemento'] ?? 0));
        if (self::booleano($hechos['multi_ticket'] ?? false)) {
            $conteo = $abiertos > 0 ? self::conteo($abiertos, 'pedido', 'pedidos') : '';
            return self::resultado(
                'libre',
                [],
                'ficha',
                $prefijo . 'Pedidos para llevar. '
                    . ($abiertos > 0 ? $conteo . ($abiertos === 1 ? ' abierto. ' : ' abiertos. ') : 'Sin pedidos abiertos. ')
                    . 'Disponible para un nuevo pedido.',
                $conteo,
                true
            );
        }

        return self::resultado('libre', [], 'ficha', $prefijo . 'Abre el corte de caja.', '', true);
    }

    /** Una barra se llena por tickets hasta su tope; nunca se reserva. */
    private static function barra(array $hechos, string $prefijo): array
    {
        $abiertos = max(0, (int)($hechos['tickets_elemento'] ?? 0));
        $tope = isset($hechos['tope_tickets']) && $hechos['tope_tickets'] !== null
            ? max(1, (int)$hechos['tope_tickets'])
            : null;
        $llena = $tope !== null && $abiertos >= $tope;
        $etiqueta = trim((string)($hechos['etiqueta_operacion_pos'] ?? 'Barra')) ?: 'Barra';
        $deTope = $tope !== null ? ' de ' . $tope : '';

        if ($llena) {
            $aria = $etiqueta . ' llena: ' . $abiertos . $deTope . ' tickets abiertos.';
        } elseif ($abiertos > 0) {
            $aria = $etiqueta . ' con ' . $abiertos . $deTope . ' tickets abiertos. Disponible para abrir otro.';
        } else {
            $aria = $etiqueta . ' libre. Disponible para abrir un ticket.';
        }

        return self::resultado(
            $llena ? 'ocupada' : 'libre',
            $abiertos > 0 ? ['ticket_abierto'] : [],
            $llena ? 'barra_llena' : 'disponible',
            $prefijo . $aria,
            $abiertos > 0 && $tope !== null ? $abiertos . '/' . $tope : '',
            false,
            $llena ? 'Llena' : null
        );
    }

    private static function conteo(int $total, string $singular, string $plural): string
    {
        return $total . ' ' . ($total === 1 ? $singular : $plural);
    }

    /**
     * @return array{estado_visual:string,modificadores:array<int,string>,precedencia:string,aria_label:string,etiqueta:string,subtitulo:string,ficha:bool}
     */
    private static function resultado(
        string $estado,
        array $modificadores,
        string $precedencia,
        string $ariaLabel,
        string $subtitulo = '',
        bool $ficha = false,
        ?string $etiqueta = null
    ): array {
        return [
            'estado_visual' => $estado,
            'modificadores' => $modificadores,
            'precedencia' => $precedencia,
            'aria_label' => $ariaLabel,
            'etiqueta' => $ficha ? '' : ($etiqueta ?? (self::ETIQUETAS[$estado] ?? $estado)),
            'subtitulo' => $subtitulo,
            'ficha' => $ficha,
        ];
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOL);
    }
}
