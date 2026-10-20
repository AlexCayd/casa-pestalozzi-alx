<?php

namespace Services\Reservations;

/**
 * Presentación de la Agenda del panel (/admin/reservaciones): las
 * reservaciones de un periodo, día por día y hora por hora.
 *
 * No consulta nada ni decide permisos: recibe las filas que ya trajo
 * `Reservacion::buscarAdmin()` —con la vigencia que el controlador les fundió
 * encima— y las traduce a lo que la vista pinta. Vive aquí y no en la vista
 * porque la vista tenía ochenta líneas de cálculo antes de la primera etiqueta
 * y porque el rótulo de las mesas y el de los estados tienen que decir lo
 * mismo que el POS (`src/js/operation/pos-reserva-vista.js`): si uno cambia,
 * cambia el otro.
 */
final class ReservacionListadoPresenter
{
    /**
     * Los filtros de estado que ofrece la Agenda, en el orden de la jornada:
     * lo que viene, lo que está en mesa, y después cómo terminó. `''` es «Todas».
     *
     * Son los estados del negocio y nada más. «Apartada» (la retención de la
     * landing mientras el cliente confirma), «Expirada» y «Reemplazada» son
     * existencia técnica: siguen consultables escribiendo `?estado=` en la
     * dirección, pero no ocupan un botón.
     */
    public const FILTROS = [
        '' => 'Todas',
        'confirmada' => 'Confirmadas',
        'en_curso' => 'En mesa',
        'completada' => 'Atendidas',
        'no_show' => 'No llegaron',
        'cancelada' => 'Canceladas',
    ];

    /** El rótulo de UNA reservación, en la voz del POS. */
    public const ETIQUETAS = [
        'confirmada' => 'Confirmada',
        'en_curso' => 'En mesa',
        'completada' => 'Atendida',
        'no_show' => 'No llegó',
        'cancelada' => 'Cancelada',
        'pendiente_verificacion' => 'Apartada',
        'expirada' => 'Expirada',
        'reemplazada' => 'Reemplazada',
    ];

    /**
     * Fuera de «Todas» salvo filtro explícito. El modelo ya quita apartadas y
     * expiradas; «Reemplazada» se quita aquí porque es la versión vieja de una
     * reservación que el cliente cambió: con ella en la lista, la misma visita
     * salía dos veces y sumaba sus personas dos veces.
     */
    public const ESTADOS_TECNICOS = ['pendiente_verificacion', 'expirada', 'reemplazada'];

    /**
     * Lo que ya no va a pasar. Se atenúa en la lista y no cuenta en las
     * personas del día. «Atendida» NO está: también terminó, pero es el
     * desenlace que se buscaba y se lee a plena tinta.
     */
    public const SIN_EFECTO = ['cancelada', 'no_show', 'expirada', 'reemplazada'];

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Quita los estados técnicos salvo que se hayan pedido explícitamente.
     *
     * @param iterable<int, mixed> $reservaciones
     * @return array<int, mixed>
     */
    public static function sinTecnicas(iterable $reservaciones, string $estadoFiltro = ''): array
    {
        $salida = [];
        foreach ($reservaciones as $reservacion) {
            $estado = (string)self::valor($reservacion, 'estado', '');
            if ($estadoFiltro === '' && in_array($estado, self::ESTADOS_TECNICOS, true)) {
                continue;
            }
            $salida[] = $reservacion;
        }

        return $salida;
    }

    /**
     * Cuántas hay de cada filtro en el universo del periodo y la búsqueda (sin
     * el filtro de estado: si se estrechara, pulsar «Canceladas» estando en
     * «No llegaron» daría cero).
     *
     * @param iterable<int, mixed> $universo
     * @return array<string, int>
     */
    public static function conteos(iterable $universo): array
    {
        $conteos = array_fill_keys(array_keys(self::FILTROS), 0);
        foreach ($universo as $reservacion) {
            $estado = (string)self::valor($reservacion, 'estado', '');
            $conteos['']++;
            if (array_key_exists($estado, $conteos) && $estado !== '') {
                $conteos[$estado]++;
            }
        }

        return $conteos;
    }

    /**
     * Una reservación lista para pintar.
     *
     * @param mixed $reservacion fila de buscarAdmin() con la vigencia fundida
     * @return array<string, mixed>
     */
    public static function fila($reservacion, string $hoy, string $urlListado): array
    {
        $id = (int)self::valor($reservacion, 'id', 0);
        $estado = (string)self::valor($reservacion, 'estado', 'confirmada');
        $fecha = (string)self::valor($reservacion, 'fecha', '');
        $hora = substr((string)self::valor($reservacion, 'hora', ''), 0, 5);
        $comensales = (int)self::valor($reservacion, 'comensales', 0);
        $mesas = self::mesas((string)self::valor($reservacion, 'mesas_asignadas', ''));
        // `elegible_no_show` lo resuelve ReservacionVigenciaService con el
        // reloj canónico: confirmada, tolerancia vencida y sin ticket abierto.
        // Vale también para días pasados, que es justo donde hace falta cerrar
        // una ausencia que la lectura automática no pudo registrar.
        $tarde = $estado === 'confirmada' && !empty(self::valor($reservacion, 'elegible_no_show', false));
        $sinEfecto = in_array($estado, self::SIN_EFECTO, true);

        // El POS sólo sirve para lo que todavía se puede operar: lo de hoy en
        // adelante que no ha terminado. Una reserva de ayer abriría el plano
        // de ayer en sólo lectura.
        $operable = in_array($estado, ['confirmada', 'en_curso'], true) && $fecha >= $hoy && !$tarde;

        return [
            'id' => $id,
            'nombre' => trim((string)self::valor($reservacion, 'nombre', '')),
            'fecha' => $fecha,
            'hora' => $hora,
            'comensales' => $comensales,
            'mesas' => $mesas,
            'nota' => trim((string)self::valor($reservacion, 'nota', '')),
            'estado' => $estado,
            'etiqueta' => $tarde ? 'Tolerancia vencida' : (self::ETIQUETAS[$estado] ?? ucfirst(str_replace('_', ' ', $estado))),
            // El tono decide la FORMA de la marca en la línea del día y el
            // acabado de la etiqueta; ver reservations.scss.
            'tono' => $tarde ? 'tarde' : self::tono($estado),
            'tarde' => $tarde,
            'sin_efecto' => $sinEfecto,
            'puede_no_show' => $tarde,
            'url_detalle' => '/admin/reservaciones/detalle?' . http_build_query([
                'id' => $id,
                'return_url' => $urlListado,
            ]),
            'url_pos' => $operable
                ? '/punto-de-venta?' . http_build_query([
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'reservation_id' => $id,
                ])
                : '',
        ];
    }

    /**
     * Agrupa las filas por día y marca dónde empieza cada franja horaria:
     * la hora se escribe una vez por franja y las reservas que comparten hora
     * cuelgan de ella, como en una libreta de reservaciones.
     *
     * @param array<int, array<string, mixed>> $filas
     * @return array<int, array<string, mixed>>
     */
    public static function porDia(array $filas, string $hoy): array
    {
        $dias = [];
        foreach ($filas as $fila) {
            $dias[(string)$fila['fecha']][] = $fila;
        }
        ksort($dias);

        $salida = [];
        foreach ($dias as $fecha => $delDia) {
            // Dentro de una misma hora, lo vivo primero y lo que quedó sin
            // efecto al final: una cancelada no debe separar a dos mesas que
            // llegan juntas.
            usort($delDia, static fn(array $a, array $b): int =>
                [$a['hora'], (int)$a['sin_efecto'], $a['id']] <=> [$b['hora'], (int)$b['sin_efecto'], $b['id']]);

            $horaPrevia = null;
            $reservas = 0;
            $personas = 0;
            $canceladas = 0;
            $noLlegaron = 0;
            $tarde = 0;
            foreach ($delDia as $i => $fila) {
                $delDia[$i]['inicio_franja'] = $fila['hora'] !== $horaPrevia;
                $horaPrevia = $fila['hora'];
                if (!$fila['sin_efecto']) {
                    $reservas++;
                    $personas += (int)$fila['comensales'];
                }
                $canceladas += $fila['estado'] === 'cancelada' ? 1 : 0;
                $noLlegaron += $fila['estado'] === 'no_show' ? 1 : 0;
                $tarde += $fila['tarde'] ? 1 : 0;
            }

            $ts = strtotime((string)$fecha) ?: time();
            $relativo = self::rotuloDia((string)$fecha, $hoy);
            $salida[] = [
                'fecha' => (string)$fecha,
                // La cabecera es una hoja de agenda: el número del día en el
                // margen de las horas, y a la derecha «Hoy» y el día de la
                // semana. El título completo va para el lector de pantalla.
                'numero' => (int)date('j', $ts),
                'mes' => substr(self::MESES[(int)date('n', $ts)], 0, 3),
                'relativo' => in_array($relativo, ['Hoy', 'Mañana', 'Ayer'], true) ? $relativo : '',
                'dia_semana' => ucfirst(self::DIAS[(int)date('w', $ts)]),
                'anio' => substr((string)$fecha, 0, 4) !== substr($hoy, 0, 4) ? date('Y', $ts) : '',
                'titulo' => self::fechaLarga((string)$fecha, $hoy),
                'es_hoy' => $fecha === $hoy,
                'pasado' => $fecha < $hoy,
                'reservas' => $reservas,
                'personas' => $personas,
                'canceladas' => $canceladas,
                'no_llegaron' => $noLlegaron,
                'tarde' => $tarde,
                'filas' => $delDia,
            ];
        }

        return $salida;
    }

    /**
     * «Mesa 6», «Mesas 3 y 4» o «Mesas 8 a 11» si son tres o más seguidas.
     * La misma regla que `nombresMesas()` del POS; si alguna se llama de otra
     * forma («Barra Blanca»), van sus nombres.
     */
    public static function mesas(string $nombres): string
    {
        $lista = array_values(array_filter(array_map('trim', explode(',', $nombres)), static fn(string $n): bool => $n !== ''));
        if ($lista === []) {
            return '';
        }
        $numeros = [];
        foreach ($lista as $nombre) {
            if (preg_match('/^Mesa (\d+)$/u', $nombre, $m) !== 1) {
                return self::enumerar($lista);
            }
            $numeros[] = (int)$m[1];
        }
        if (count($numeros) === 1) {
            return $lista[0];
        }
        $numeros = array_values(array_unique($numeros));
        sort($numeros);

        $tramos = [];
        foreach ($numeros as $n) {
            $ultimo = count($tramos) - 1;
            if ($ultimo >= 0 && $n === $tramos[$ultimo][1] + 1) {
                $tramos[$ultimo][1] = $n;
            } else {
                $tramos[] = [$n, $n];
            }
        }
        $partes = [];
        foreach ($tramos as [$desde, $hasta]) {
            // «a» y no la raya: un lector de pantalla en español no pronuncia
            // «–» con la puntuación por defecto («Mesas 8 11»).
            if ($hasta - $desde >= 2) {
                $partes[] = $desde . ' a ' . $hasta;
            } else {
                for ($n = $desde; $n <= $hasta; $n++) {
                    $partes[] = (string)$n;
                }
            }
        }

        return 'Mesas ' . self::enumerar($partes);
    }

    /** «hoy», «mañana», «ayer» o el día de la semana. */
    public static function rotuloDia(string $fecha, string $hoy): string
    {
        $ts = strtotime($fecha);
        $tsHoy = strtotime($hoy);
        if (!$ts || !$tsHoy) {
            return '';
        }
        $diferencia = (int)round(($ts - $tsHoy) / 86400);

        return match ($diferencia) {
            0 => 'Hoy',
            1 => 'Mañana',
            -1 => 'Ayer',
            default => ucfirst(self::DIAS[(int)date('w', $ts)]),
        };
    }

    /**
     * «lunes 19 de octubre», con el año sólo cuando no es el de hoy. En
     * palabras y no en d/m/Y: el día de la semana es con lo que el anfitrión
     * se ubica, y ni intl ni setlocale están garantizados (ver CLAUDE.md).
     */
    public static function fechaLarga(string $fecha, string $hoy): string
    {
        $ts = strtotime($fecha);
        if (!$ts) {
            return $fecha;
        }
        $texto = self::DIAS[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . self::MESES[(int)date('n', $ts)];
        if (substr($fecha, 0, 4) !== substr($hoy, 0, 4)) {
            $texto .= ' de ' . date('Y', $ts);
        }

        return $texto;
    }

    /** «19 oct», para frases cortas (avisos, confirmaciones). */
    public static function fechaCorta(string $fecha): string
    {
        $ts = strtotime($fecha);

        return $ts ? (int)date('j', $ts) . ' ' . substr(self::MESES[(int)date('n', $ts)], 0, 3) : $fecha;
    }

    /**
     * El tono de un estado: la FORMA de su marca en la línea del día y en la
     * tira de estados (que así hace de leyenda). Público para que la vista use
     * el mismo en los dos sitios.
     */
    public static function tono(string $estado): string
    {
        return match ($estado) {
            'confirmada' => 'esperada',
            'en_curso' => 'en-mesa',
            'completada' => 'atendida',
            'no_show' => 'no-llego',
            'cancelada' => 'cancelada',
            'pendiente_verificacion' => 'apartada',
            default => 'sin-efecto',
        };
    }

    /** @param array<int, string> $partes */
    private static function enumerar(array $partes): string
    {
        if (count($partes) < 2) {
            return (string)($partes[0] ?? '');
        }

        return implode(', ', array_slice($partes, 0, -1)) . ' y ' . end($partes);
    }

    /** @param mixed $item */
    private static function valor($item, string $campo, $defecto = '')
    {
        if (is_array($item)) {
            return $item[$campo] ?? $defecto;
        }
        if (is_object($item)) {
            return $item->$campo ?? $defecto;
        }

        return $defecto;
    }
}
