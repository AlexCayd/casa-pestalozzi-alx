<?php

declare(strict_types=1);

/*
 * Consultas rápidas por hora: ConsultasRapidas::horario() lee la hora, la
 * franja y el día de la semana de la pregunta ya normalizada.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Analytics\ConsultasRapidas;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$h = static fn (string $texto): ?array => ConsultasRapidas::horario(ConsultasRapidas::normalizar($texto));

$assert($h('Lo más vendido esta semana') === null, 'Sin hora ni día no hay horario.');
$assert($h('Ventas de pan mañana') === null, '«mañana» sola es el día siguiente, no la franja.');

$nueve = $h('Lo más vendido a las 9');
$assert($nueve['desde'] === 9 && $nueve['hasta'] === 9 && $nueve['dow'] === null, '«a las 9» es de 9:00 a 9:59.');
$assert($nueve['frase'] === 'a las 9:00' && $nueve['sql'] === 'HOUR(ti.created_at) BETWEEN 9 AND 9', 'Frase y filtro de la hora.');

$assert($h('capuchinos a las 3')['desde'] === 15, 'De 1 a 7 sin am/pm se lee de la tarde: la casa abre a las 8.');
$assert($h('a las 9 de la noche')['desde'] === 21 && $h('a las 9 pm')['desde'] === 21, '«de la noche» y «pm» suman 12.');
$assert($h('a las 21:00')['desde'] === 21, '«21:00» se lee completo.');
$assert($h('a la 1 de la tarde')['frase'] === 'a las 13:00', 'La 1 de la tarde son las 13:00.');

$manana = $h('Lo más vendido en la mañana');
$assert([$manana['desde'], $manana['hasta']] === [0, 12] && $manana['frase'] === 'en la mañana', 'La mañana llega a las 12:59.');
$assert([$h('por la tarde')['desde'], $h('por la tarde')['hasta']] === [13, 18], 'La tarde va de 13:00 a 18:59.');
$assert($h('las noches')['desde'] === 19, 'La noche empieza a las 19:00.');

$sab = $h('Capuchinos los sábados a las 10');
$assert($sab['dow'] === 6 && $sab['desde'] === 10, 'Día de la semana y hora juntos.');
$assert($sab['frase'] === 'los sábados a las 10:00', 'La frase dice día y hora.');
$assert(str_contains($sab['sql'], 'DAYOFWEEK(ti.created_at) = 7'), 'MySQL numera el sábado como 7.');
$assert($sab['resto'] === 'capuchinos', 'Lo que queda para buscar no arrastra la hora ni el día.');
$assert($h('lo mas vendido el domingo')['dow'] === 0 && $h('lo mas vendido el domingo')['desde'] === null, 'Sólo el día, sin hora.');

$assert(ConsultasRapidas::periodo('8s')['desde'] === (new DateTimeImmutable('today'))->modify('-55 day')->format('Y-m-d'),
    'Las 8 semanas son 56 días contando hoy.');
$assert(ConsultasRapidas::periodo('hoy')['hora_sql'] === '', 'Un periodo sin hora no filtra nada.');

fwrite(STDOUT, "Consultas por hora: {$assertions} comprobaciones OK\n");
