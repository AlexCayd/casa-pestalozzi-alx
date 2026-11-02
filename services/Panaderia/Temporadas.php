<?php

/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — sólo la landing /panaderia lee esto.             │
 * └───────────────────────────────────────────────────────────────┘
 *
 * El pan de temporada: lo que sólo hay unas semanas al año y la gente busca
 * por su nombre. Alimenta la sección «De temporada» de la landing
 * (views/panaderia/_temporada.php): la pieza de ahora (o la siguiente, si hoy
 * no toca ninguna) y el año entero en una regla de doce meses.
 *
 * El calendario vive en código y no en la base porque se repite cada año: las
 * fechas son mes-día. Una ventana que cruza el año (la rosca, del 26 de
 * diciembre al 6 de enero) se escribe tal cual y aquí se parte en dos tramos.
 *
 * ⚠️ PANES Y FECHAS DE EJEMPLO. Cambiar este arreglo con lo que hornee la
 * panadería; la vista no sabe cuántos son.
 */

namespace Services\Panaderia;

class Temporadas
{
    /** [nombre, desde 'MM-DD', hasta 'MM-DD', descripción, anticipación, frase de WhatsApp] */
    public const PANES = [
        ['nombre' => 'Rosca de Reyes',      'desde' => '12-26', 'hasta' => '01-06',
         'descripcion' => 'Con higo, ate y naranja cristalizada. El muñeco va dentro.',
         'anticipacion' => 'Apártala con dos días de anticipación.',
         'frase' => 'Hola! Quiero apartar una rosca de Reyes'],
        ['nombre' => 'Zeppole de San José', 'desde' => '03-12', 'hasta' => '03-19',
         'descripcion' => 'Masa choux frita, crema pastelera y una cereza amarena, como en Nápoles el día del padre.',
         'anticipacion' => 'Se hacen por la mañana; apártalas un día antes.',
         'frase' => 'Hola! Quiero apartar zeppole de San José'],
        ['nombre' => 'Pan de elote',        'desde' => '09-01', 'hasta' => '09-16',
         'descripcion' => 'Elote tierno, mantequilla y un poco de canela. Para las fiestas patrias.',
         'anticipacion' => 'Entero o por rebanada; el entero, con un día de anticipación.',
         'frase' => 'Hola! Quiero apartar un pan de elote'],
        ['nombre' => 'Pan de muerto',       'desde' => '10-15', 'hasta' => '11-02',
         'descripcion' => 'De naranja y azahar, con azúcar o con ajonjolí.',
         'anticipacion' => 'Apártalo con un día de anticipación.',
         'frase' => 'Hola! Quiero apartar pan de muerto'],
        ['nombre' => 'Panettone',           'desde' => '11-15', 'hasta' => '12-24',
         'descripcion' => 'Masa madre, naranja confitada y pasas. En caja, para regalar.',
         'anticipacion' => 'Por pedido, con tres días de anticipación.',
         'frase' => 'Hola! Quiero apartar un panettone'],
    ];

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Todo lo que pinta la sección, calculado para una fecha:
     * - `ahora`: la pieza en temporada (la que termina antes si hay dos), o null.
     * - `siguiente`: la próxima en empezar, para cuando no hay ninguna.
     * - `calendario`: cada pieza con sus tramos en porcentaje del año
     *   ([['inicio' => %, 'ancho' => %], …]), sus fechas en palabras y si es la
     *   de ahora; en el orden de PANES.
     * - `hoy`: dónde cae hoy en la regla, en porcentaje.
     */
    public static function paraFecha(\DateTimeImmutable $hoy): array
    {
        $calendario = [];
        $ahora = null;
        $siguiente = null;
        $diasParaSiguiente = PHP_INT_MAX;

        foreach (self::PANES as $pan) {
            [$inicio, $fin] = self::ventanaQueContiene($pan, $hoy);
            $enTemporada = $hoy >= $inicio && $hoy <= $fin;
            $fila = $pan + [
                'fechas' => self::fechasEnPalabras($pan['desde'], $pan['hasta']),
                'hasta_texto' => self::diaEnPalabras($pan['hasta']),
                'desde_texto' => self::diaEnPalabras($pan['desde']),
                'tramos' => self::tramos($pan['desde'], $pan['hasta']),
                'ahora' => false,
            ];

            if ($enTemporada && ($ahora === null || $fin < $ahora['_fin'])) {
                $ahora = $fila + ['_fin' => $fin];
            }

            $proximoInicio = self::proximoInicio($pan['desde'], $hoy);
            $dias = (int) $hoy->diff($proximoInicio)->days;
            if (!$enTemporada && $dias < $diasParaSiguiente) {
                $diasParaSiguiente = $dias;
                $siguiente = $fila;
            }

            $calendario[] = $fila;
        }

        if ($ahora !== null) {
            foreach ($calendario as &$fila) {
                $fila['ahora'] = $fila['nombre'] === $ahora['nombre'];
            }
            unset($fila, $ahora['_fin']);
        }

        return [
            'ahora' => $ahora,
            'siguiente' => $siguiente,
            'calendario' => $calendario,
            'hoy' => round(((int) $hoy->format('z') + 0.5) / self::diasDelAnio($hoy) * 100, 2),
        ];
    }

    /** «Del 15 de octubre al 2 de noviembre»; «Del 12 al 19 de marzo» si es el mismo mes. */
    public static function fechasEnPalabras(string $desde, string $hasta): string
    {
        if (substr($desde, 0, 2) === substr($hasta, 0, 2)) {
            return 'Del ' . (int) substr($desde, 3) . ' al ' . self::diaEnPalabras($hasta);
        }

        return 'Del ' . self::diaEnPalabras($desde) . ' al ' . self::diaEnPalabras($hasta);
    }

    /** '11-02' → «2 de noviembre». */
    public static function diaEnPalabras(string $mmdd): string
    {
        [$mes, $dia] = array_map('intval', explode('-', $mmdd));

        return $dia . ' de ' . self::MESES[$mes];
    }

    /**
     * La ventana (inicio y fin, con año) que contiene la fecha o, si no la
     * contiene ninguna, la de este año. Para una que cruza el año, en enero
     * es la que empezó el diciembre anterior.
     */
    private static function ventanaQueContiene(array $pan, \DateTimeImmutable $hoy): array
    {
        $anio = (int) $hoy->format('Y');
        $cruza = $pan['desde'] > $pan['hasta'];
        $inicio = self::fecha($anio, $pan['desde'], $hoy);
        $fin = self::fecha($cruza ? $anio + 1 : $anio, $pan['hasta'], $hoy)->setTime(23, 59, 59);

        if ($cruza && $hoy < $inicio) {
            $inicio = self::fecha($anio - 1, $pan['desde'], $hoy);
            $fin = self::fecha($anio, $pan['hasta'], $hoy)->setTime(23, 59, 59);
        }

        return [$inicio, $fin];
    }

    private static function proximoInicio(string $desde, \DateTimeImmutable $hoy): \DateTimeImmutable
    {
        $inicio = self::fecha((int) $hoy->format('Y'), $desde, $hoy);

        return $inicio < $hoy->setTime(0, 0) ? self::fecha((int) $hoy->format('Y') + 1, $desde, $hoy) : $inicio;
    }

    /**
     * Los tramos de la ventana en la regla del año, sobre un año de 365 días:
     * la regla es un dibujo, y un año bisiesto no mueve nada que se vea.
     */
    private static function tramos(string $desde, string $hasta): array
    {
        $a = self::diaDelAnio($desde);
        $b = self::diaDelAnio($hasta) + 1; // el último día cuenta entero
        $pct = static fn (float $dias): float => round($dias / 365 * 100, 2);

        if ($a < $b) {
            return [['inicio' => $pct($a), 'ancho' => $pct($b - $a)]];
        }

        return [
            ['inicio' => 0.0, 'ancho' => $pct($b)],
            ['inicio' => $pct($a), 'ancho' => $pct(365 - $a)],
        ];
    }

    /** '03-12' → 70 (días transcurridos antes de esa fecha, en un año de 365). */
    private static function diaDelAnio(string $mmdd): int
    {
        return (int) \DateTimeImmutable::createFromFormat('!Y-m-d', '2027-' . $mmdd)->format('z');
    }

    private static function fecha(int $anio, string $mmdd, \DateTimeImmutable $referencia): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $anio . '-' . $mmdd, $referencia->getTimezone());
    }

    private static function diasDelAnio(\DateTimeImmutable $hoy): int
    {
        return (int) $hoy->format('L') === 1 ? 366 : 365;
    }
}
