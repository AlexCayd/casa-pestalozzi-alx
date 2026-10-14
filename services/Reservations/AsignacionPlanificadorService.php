<?php

/**
 * Motor de asignación automática de mesas. Es puro: no lee ni escribe la base,
 * recibe los hechos del día y devuelve un plan.
 *
 * Las reglas son las del diagrama del restaurante:
 *
 *   n > máximo   → no hay asignación automática: el grupo se gestiona a mano.
 *   9 ≤ n        → el BLOQUE exterior completo (Mesas 8–11). Las reservas que
 *                  ya estaban ahí se reubican solas en el interior; si alguna
 *                  no cabe, o hay un ticket en el bloque, el horario no se
 *                  ofrece.
 *   5–8          → una pareja de ReservacionConfig::GRUPOS_DOS_MESAS, en su
 *                  orden: el interior primero, el exterior como desborde.
 *   1–4          → una mesa: interior 1→7, luego exterior 8→11.
 *
 * Las barras nunca se asignan: no son reservables y aquí sólo entran mesas.
 *
 * Por qué el bloque es el exterior COMPLETO y no «las mesas que hagan falta»:
 * es una decisión del restaurante, no de capacidad. Un grupo grande se sienta
 * junto, afuera, y el salón de adentro sigue funcionando para los demás.
 */

namespace Services\Reservations;

use DateTimeImmutable;
use Services\Tables\OcupacionMesasService;

final class AsignacionPlanificadorService
{
    public const TIPO_INDIVIDUAL = 'mesa_individual';
    public const TIPO_PAREJA = 'par_predefinido';
    public const TIPO_BLOQUE = 'bloque_exterior';

    public const MOTIVO_REQUIERE_MANUAL = 'requiere_asignacion_manual';
    public const MOTIVO_SIN_MESA = 'sin_combinacion_fisica';
    public const MOTIVO_BLOQUE_INCOMPLETO = 'bloque_incompleto';
    public const MOTIVO_TICKET_EN_BLOQUE = 'ticket_en_bloque';
    public const MOTIVO_GRUPO_EN_BLOQUE = 'bloque_ocupado_por_grupo';
    public const MOTIVO_RESERVA_INICIADA = 'reserva_iniciada_en_bloque';
    public const MOTIVO_SIN_REUBICACION = 'sin_reubicacion';

    /**
     * @param array{comensales:int, fecha:string, hora:string, excluir?:array<int,int>, max_automatico?:int, ahora?:DateTimeImmutable} $solicitud
     * @param array<int, object> $mesas Todas las mesas del plano, con zona.
     * @param array<string, mixed> $evaluacion Ocupación del intervalo solicitado (OcupacionMesasService).
     * @param array<int, array<string, mixed>> $asignacionesDelDia Filas reservación-mesa vigentes del día.
     * @param callable(string, array<int, int>): array<string, mixed> $evaluar Ocupación de otro intervalo del mismo día.
     * @return array{ok: bool, motivo: ?string, tipo: ?string, mesas: array<int, object>, mesa_ids: array<int, int>, capacidad_total: int, reubicaciones: array<int, array<string, mixed>>}
     */
    public static function planificar(
        array $solicitud,
        array $mesas,
        array $evaluacion,
        array $asignacionesDelDia,
        callable $evaluar
    ): array {
        $comensales = (int)($solicitud['comensales'] ?? 0);
        $maximo = (int)($solicitud['max_automatico'] ?? ReservacionConfig::maxComensalesPublico());
        if ($comensales < 1 || $comensales > $maximo) {
            return self::ninguna(self::MOTIVO_REQUIERE_MANUAL);
        }

        $libre = static fn($mesa): bool => self::libre($evaluacion, $mesa);

        if ($comensales <= 4) {
            foreach (self::individualesOrdenadas($mesas, $comensales, false) as $mesa) {
                if ($libre($mesa)) {
                    return self::resultado(self::TIPO_INDIVIDUAL, [$mesa], []);
                }
            }
            return self::ninguna(self::MOTIVO_SIN_MESA);
        }

        if ($comensales < ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR) {
            foreach (self::parejasOrdenadas($mesas, $comensales, false) as $pareja) {
                if ($libre($pareja[0]) && $libre($pareja[1])) {
                    return self::resultado(self::TIPO_PAREJA, $pareja, []);
                }
            }
            return self::ninguna(self::MOTIVO_SIN_MESA);
        }

        return self::bloque($solicitud, $mesas, $evaluacion, $asignacionesDelDia, $evaluar);
    }

    /**
     * Planifica con los hechos del día ya cargados por
     * OcupacionMesasService::prepararContextoFecha(). Es la puerta que usan la
     * disponibilidad, la asignación y el alta administrativa, para que las tres
     * apliquen exactamente la misma regla.
     *
     * @param array<int, int> $excluir Reservaciones que no cuentan (la propia al editar).
     */
    public static function planificarConContexto(
        array $contextoFecha,
        array $evaluacion,
        int $comensales,
        string $hora,
        array $excluir = [],
        ?int $maxAutomatico = null,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ($contextoFecha['ahora'] ?? null) ?? ReservacionConfig::ahora();
        $evaluar = static fn(string $otraHora, array $excluidas): array =>
            OcupacionMesasService::evaluarHorarioConContexto($contextoFecha, $otraHora, $excluidas, $ahora);

        return self::planificar(
            [
                'comensales' => $comensales,
                'fecha' => (string)($contextoFecha['fecha'] ?? ''),
                'hora' => HorarioReservacionService::normalizarHoraSql($hora),
                'excluir' => $excluir,
                'max_automatico' => $maxAutomatico ?? ReservacionConfig::maxComensalesPublico(),
                'ahora' => $ahora,
            ],
            (array)($contextoFecha['mesas'] ?? []),
            $evaluacion,
            (array)($contextoFecha['reservaciones'] ?? []),
            $evaluar
        );
    }

    /**
     * Sólo el bloque exterior, sin mirar el tamaño del grupo.
     *
     * Es lo que pide «Cambiar mesas» cuando el personal elige las cuatro mesas
     * del exterior a mano —un grupo mayor al máximo, o uno pequeño al que se
     * decide dar la terraza—: las mismas reglas del bloque (ticket o grupo
     * dentro lo impiden; las reservas pequeñas se reubican) que aplica el
     * alta automática, para que las dos vías no puedan contradecirse.
     *
     * @param array<int, int> $excluir
     */
    public static function planificarBloqueConContexto(
        array $contextoFecha,
        array $evaluacion,
        int $comensales,
        string $hora,
        array $excluir = [],
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ($contextoFecha['ahora'] ?? null) ?? ReservacionConfig::ahora();
        $evaluar = static fn(string $otraHora, array $excluidas): array =>
            OcupacionMesasService::evaluarHorarioConContexto($contextoFecha, $otraHora, $excluidas, $ahora);

        return self::bloque(
            [
                'comensales' => max(1, $comensales),
                'fecha' => (string)($contextoFecha['fecha'] ?? ''),
                'hora' => HorarioReservacionService::normalizarHoraSql($hora),
                'excluir' => $excluir,
                'ahora' => $ahora,
            ],
            (array)($contextoFecha['mesas'] ?? []),
            $evaluacion,
            (array)($contextoFecha['reservaciones'] ?? []),
            $evaluar
        );
    }

    /**
     * Mesas que se PROPONEN —no se asignan— a un grupo mayor al máximo.
     *
     * Para esos grupos no hay asignación automática y el personal elige en el
     * mapa; la propuesta sólo le ahorra empezar con el plano en blanco. Llena
     * por cercanía: primero el exterior, que es el bloque pensado para grupos,
     * y después el interior por filas desde la más próxima a él (`pos_y`), para
     * que el grupo quede junto y no salpicado por el salón. Sólo cuenta mesas
     * libres sin ticket proyectado —el criterio estricto del bloque—, y al final
     * suelta las que sobren por si la última mesa ya cubría a una anterior.
     *
     * @param array<int, object> $mesas Las mesas reservables del día.
     * @return array<int, int> Ids ordenados, o [] si lo libre no alcanza.
     */
    public static function sugerirGrupoGrande(array $mesas, array $evaluacion, int $comensales): array
    {
        if ($comensales < 1) {
            return [];
        }
        $libres = array_values(array_filter($mesas, static fn($mesa): bool =>
            self::elegible($mesa) && self::libreEstricto($evaluacion, $mesa)));
        $rango = static fn($mesa): int => self::zona($mesa) === ReservacionConfig::ZONA_EXTERIOR ? 0 : 1;
        usort($libres, static fn($a, $b): int =>
            ($rango($a) <=> $rango($b))
            ?: ((float)($a->pos_y ?? 0) <=> (float)($b->pos_y ?? 0))
            ?: ((float)($a->pos_x ?? 0) <=> (float)($b->pos_x ?? 0))
            ?: ((int)$a->numero <=> (int)$b->numero));

        $elegidas = [];
        $suma = 0;
        foreach ($libres as $mesa) {
            if ($suma >= $comensales) {
                break;
            }
            $elegidas[] = $mesa;
            $suma += (int)$mesa->capacidad;
        }
        if ($suma < $comensales) {
            return [];
        }
        for ($i = count($elegidas) - 1; $i >= 0; $i--) {
            $capacidad = (int)$elegidas[$i]->capacidad;
            if ($suma - $capacidad >= $comensales) {
                $suma -= $capacidad;
                array_splice($elegidas, $i, 1);
            }
        }

        $ids = self::idsMesas($elegidas);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** ¿Estas mesas son exactamente el bloque exterior del plano? @param array<int, int> $mesaIds */
    public static function esBloqueExterior(array $mesaIds, array $plano): bool
    {
        $ids = self::ids($mesaIds);
        $bloque = self::idsMesas(self::mesasDelBloque($plano));
        sort($ids, SORT_NUMERIC);
        sort($bloque, SORT_NUMERIC);

        return $bloque !== [] && $ids === $bloque;
    }

    /**
     * ¿Es una agrupación que el motor habría podido proponer? Lo usa la
     * confirmación de un reemplazo público, que revalida sin replanificar.
     *
     * @param array<int, object> $mesas Las mesas de la agrupación.
     * @param array<int, object> $plano Todas las mesas, para saber cuál es el bloque.
     */
    public static function esAgrupacionCanonica(array $mesas, int $comensales, array $plano): bool
    {
        if ($mesas === [] || $comensales < 1) {
            return false;
        }
        foreach ($mesas as $mesa) {
            if (!self::elegible($mesa)) {
                return false;
            }
        }
        if (self::capacidad($mesas) < $comensales) {
            return false;
        }

        $numeros = self::numeros($mesas);
        if ($comensales <= 4) {
            return count($mesas) === 1;
        }
        if ($comensales < ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR) {
            foreach (ReservacionConfig::GRUPOS_DOS_MESAS as $grupo) {
                $grupo = array_map('intval', $grupo);
                sort($grupo, SORT_NUMERIC);
                if ($grupo === $numeros) {
                    return true;
                }
            }
            return false;
        }

        return $numeros === self::numeros(self::mesasDelBloque($plano));
    }

    // ── Bloque exterior ───────────────────────────────────────────────

    private static function bloque(
        array $solicitud,
        array $mesas,
        array $evaluacion,
        array $asignacionesDelDia,
        callable $evaluar
    ): array {
        $comensales = (int)$solicitud['comensales'];
        $bloque = self::mesasDelBloque($mesas);
        if ($bloque === [] || self::capacidad($bloque) < $comensales) {
            return self::ninguna(self::MOTIVO_BLOQUE_INCOMPLETO);
        }
        // Una mesa del exterior fuera de servicio rompe el bloque: el grupo no
        // se reparte entre lo que quede.
        foreach ($bloque as $mesa) {
            if (!self::elegible($mesa)) {
                return self::ninguna(self::MOTIVO_BLOQUE_INCOMPLETO);
            }
        }
        // Un ticket no se puede mover: la gente ya está sentada. Criterio
        // estricto: también uno cuya liberación estimada ya pasó, porque
        // sigue abierto y nadie ha levantado la mesa.
        foreach ($bloque as $mesa) {
            $fuente = (string)($evaluacion['mesas'][(int)$mesa->id]['fuente'] ?? '');
            if ($fuente === 'ticket_abierto' || $fuente === 'ticket_proyectado') {
                return self::ninguna(self::MOTIVO_TICKET_EN_BLOQUE);
            }
        }

        $inicio = self::fechaHora((string)$solicitud['fecha'], (string)$solicitud['hora']);
        if ($inicio === null) {
            return self::ninguna(self::MOTIVO_SIN_MESA);
        }
        $ahora = $solicitud['ahora'] ?? ReservacionConfig::ahora();
        $desplazadas = self::reservacionesEnBloque(
            $bloque,
            $asignacionesDelDia,
            $inicio,
            self::ids((array)($solicitud['excluir'] ?? []))
        );

        $ocupadasPorDesplazadas = [];
        foreach ($desplazadas as $reserva) {
            if ($reserva['comensales'] >= ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR) {
                return self::ninguna(self::MOTIVO_GRUPO_EN_BLOQUE);
            }
            // Ya empezó: el cliente puede estar llegando a esa mesa. Mover la
            // reserva justo ahora deja al anfitrión sin saber dónde sentarlo.
            if ($reserva['inicio'] <= $ahora) {
                return self::ninguna(self::MOTIVO_RESERVA_INICIADA);
            }
            foreach ($reserva['mesas'] as $mesaId) {
                $ocupadasPorDesplazadas[$mesaId] = true;
            }
        }
        foreach ($bloque as $mesa) {
            $mesaId = (int)$mesa->id;
            if (!isset($ocupadasPorDesplazadas[$mesaId]) && !self::libreEstricto($evaluacion, $mesa)) {
                return self::ninguna(self::MOTIVO_SIN_MESA);
            }
        }

        if ($desplazadas === []) {
            return self::resultado(self::TIPO_BLOQUE, $bloque, []);
        }

        $reubicaciones = self::reubicar($desplazadas, $bloque, $mesas, $solicitud, $evaluar);

        return $reubicaciones === null
            ? self::ninguna(self::MOTIVO_SIN_REUBICACION)
            : self::resultado(self::TIPO_BLOQUE, $bloque, $reubicaciones);
    }

    /**
     * Reservas vigentes que tocan el bloque durante el intervalo solicitado,
     * agrupadas por reservación y con TODAS sus mesas (una pareja [7,8] se
     * mueve entera aunque sólo la 8 sea del bloque).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function reservacionesEnBloque(
        array $bloque,
        array $asignacionesDelDia,
        DateTimeImmutable $inicioSolicitud,
        array $excluir
    ): array {
        $excluidas = array_fill_keys($excluir, true);
        $bloqueIds = array_fill_keys(self::idsMesas($bloque), true);
        $porReservacion = [];
        foreach ($asignacionesDelDia as $fila) {
            $reservacionId = (int)($fila['reservacion_id'] ?? 0);
            if ($reservacionId < 1 || isset($excluidas[$reservacionId]) || !self::influye($fila)) {
                continue;
            }
            if (!isset($porReservacion[$reservacionId])) {
                $porReservacion[$reservacionId] = [
                    'reservacion_id' => $reservacionId,
                    'fecha' => (string)($fila['fecha'] ?? ''),
                    'hora' => HorarioReservacionService::normalizarHoraSql((string)($fila['hora'] ?? '')),
                    'comensales' => (int)($fila['comensales'] ?? 0),
                    'mesas' => [],
                ];
            }
            $porReservacion[$reservacionId]['mesas'][] = (int)($fila['mesa_id'] ?? 0);
        }

        $desplazadas = [];
        foreach ($porReservacion as $reserva) {
            $tocaBloque = false;
            foreach ($reserva['mesas'] as $mesaId) {
                if (isset($bloqueIds[$mesaId])) {
                    $tocaBloque = true;
                    break;
                }
            }
            if (!$tocaBloque) {
                continue;
            }
            $inicio = self::fechaHora($reserva['fecha'], $reserva['hora']);
            if ($inicio === null || !OcupacionMesasService::intervalosSeTraslapan($inicio, $inicioSolicitud)) {
                continue;
            }
            $mesasReserva = array_values(array_unique($reserva['mesas']));
            sort($mesasReserva, SORT_NUMERIC);
            $desplazadas[] = ['inicio' => $inicio, 'mesas' => $mesasReserva] + $reserva;
        }

        usort($desplazadas, static fn(array $a, array $b): int =>
            ($a['inicio'] <=> $b['inicio']) ?: ($a['reservacion_id'] <=> $b['reservacion_id']));

        return $desplazadas;
    }

    /**
     * Busca destino para cada reserva desplazada, sin tocar el bloque y sin
     * que dos desplazadas que se solapan compartan mesa. Backtracking en orden
     * fijo (hora, id; mesas por número), así que el mismo día da siempre el
     * mismo plan.
     *
     * @return array<int, array<string, mixed>>|null null si alguna no cabe.
     */
    private static function reubicar(
        array $desplazadas,
        array $bloque,
        array $mesas,
        array $solicitud,
        callable $evaluar
    ): ?array {
        $bloqueIds = array_fill_keys(self::idsMesas($bloque), true);
        $excluirBase = self::ids((array)($solicitud['excluir'] ?? []));

        foreach ($desplazadas as $indice => $reserva) {
            // La ocupación de SU intervalo, sin ella misma: así su mesa actual
            // no le estorba y las demás reservas del día sí cuentan.
            $evaluacion = $evaluar(
                (string)$reserva['hora'],
                array_merge($excluirBase, [(int)$reserva['reservacion_id']])
            );
            $disponible = static fn($mesa): bool =>
                !isset($bloqueIds[(int)$mesa->id]) && self::libreEstricto($evaluacion, $mesa);

            $candidatas = [];
            if ($reserva['comensales'] <= 4) {
                foreach (self::individualesOrdenadas($mesas, $reserva['comensales'], true) as $mesa) {
                    if ($disponible($mesa)) {
                        $candidatas[] = [$mesa];
                    }
                }
            } else {
                foreach (self::parejasOrdenadas($mesas, $reserva['comensales'], true) as $pareja) {
                    if ($disponible($pareja[0]) && $disponible($pareja[1])) {
                        $candidatas[] = $pareja;
                    }
                }
            }
            if ($candidatas === []) {
                return null;
            }
            $desplazadas[$indice]['candidatas'] = $candidatas;
        }

        $nodos = 0;
        return self::buscar($desplazadas, 0, [], $nodos);
    }

    /** @return array<int, array<string, mixed>>|null */
    private static function buscar(array $desplazadas, int $indice, array $tomadas, int &$nodos): ?array
    {
        if ($indice >= count($desplazadas)) {
            return [];
        }
        if (++$nodos > ReservacionConfig::REUBICACION_MAX_NODOS) {
            return null;
        }

        $reserva = $desplazadas[$indice];
        foreach ($reserva['candidatas'] as $candidata) {
            $ids = self::idsMesas($candidata);
            foreach ($tomadas as [$inicioTomada, $idsTomados]) {
                if (array_intersect($ids, $idsTomados) !== []
                    && OcupacionMesasService::intervalosSeTraslapan($inicioTomada, $reserva['inicio'])) {
                    continue 2;
                }
            }
            $resto = self::buscar(
                $desplazadas,
                $indice + 1,
                array_merge($tomadas, [[$reserva['inicio'], $ids]]),
                $nodos
            );
            if ($resto !== null) {
                return array_merge([[
                    'reservacion_id' => (int)$reserva['reservacion_id'],
                    'hora' => (string)$reserva['hora'],
                    'comensales' => (int)$reserva['comensales'],
                    'mesas_antes' => $reserva['mesas'],
                    'mesas_despues' => $ids,
                ]], $resto);
            }
        }

        return null;
    }

    // ── Catálogo de candidatas ────────────────────────────────────────

    /**
     * Mesas sueltas que caben, en el orden de preferencia de la casa: interior
     * antes que exterior, y una mesa sin zona sólo como último recurso. Con
     * $soloInterior (destinos de una reubicación) el exterior no participa.
     *
     * @return array<int, object>
     */
    private static function individualesOrdenadas(array $mesas, int $comensales, bool $soloInterior): array
    {
        $candidatas = array_values(array_filter($mesas, static fn($mesa): bool =>
            self::elegible($mesa)
            && (int)$mesa->capacidad >= $comensales
            && (!$soloInterior || self::zona($mesa) === ReservacionConfig::ZONA_INTERIOR)));

        usort($candidatas, static fn($a, $b): int =>
            (self::rangoZona($a) <=> self::rangoZona($b))
            ?: (((int)$a->capacidad - $comensales) <=> ((int)$b->capacidad - $comensales))
            ?: ((int)$a->numero <=> (int)$b->numero)
            ?: ((int)$a->id <=> (int)$b->id));

        return $candidatas;
    }

    /** @return array<int, array{0: object, 1: object}> */
    private static function parejasOrdenadas(array $mesas, int $comensales, bool $soloInterior): array
    {
        $porNumero = [];
        foreach ($mesas as $mesa) {
            if (self::elegible($mesa)) {
                $porNumero[(int)$mesa->numero] = $mesa;
            }
        }

        $parejas = [];
        foreach (ReservacionConfig::GRUPOS_DOS_MESAS as $grupo) {
            $a = $porNumero[(int)($grupo[0] ?? 0)] ?? null;
            $b = $porNumero[(int)($grupo[1] ?? 0)] ?? null;
            if ($a === null || $b === null || self::capacidad([$a, $b]) < $comensales) {
                continue;
            }
            if ($soloInterior
                && (self::zona($a) !== ReservacionConfig::ZONA_INTERIOR || self::zona($b) !== ReservacionConfig::ZONA_INTERIOR)) {
                continue;
            }
            $parejas[] = [$a, $b];
        }

        return $parejas;
    }

    /** @return array<int, object> */
    private static function mesasDelBloque(array $mesas): array
    {
        $bloque = array_values(array_filter($mesas, static fn($mesa): bool =>
            (string)($mesa->tipo ?? '') === 'mesa' && self::zona($mesa) === ReservacionConfig::ZONA_EXTERIOR));
        usort($bloque, static fn($a, $b): int => ((int)$a->numero <=> (int)$b->numero) ?: ((int)$a->id <=> (int)$b->id));

        return $bloque;
    }

    // ── Hechos de ocupación ───────────────────────────────────────────

    private static function libre(array $evaluacion, $mesa): bool
    {
        return !empty($evaluacion['mesas'][(int)$mesa->id]['disponible']);
    }

    /**
     * Libre y sin ticket proyectado. Para el bloque y para los destinos de una
     * reubicación no basta con que el ticket «debería» haberse ido: si sigue
     * abierto, la mesa no está libre.
     */
    private static function libreEstricto(array $evaluacion, $mesa): bool
    {
        $estado = $evaluacion['mesas'][(int)$mesa->id] ?? null;

        return is_array($estado)
            && !empty($estado['disponible'])
            && (string)($estado['fuente'] ?? '') !== 'ticket_proyectado';
    }

    /** El mismo filtro que OcupacionMesasService aplica a las filas del día. */
    private static function influye(array $fila): bool
    {
        return !array_key_exists('reservacion_influye_en_disponibilidad', $fila)
            || (bool)$fila['reservacion_influye_en_disponibilidad']
            || !empty($fila['bloquea_intervalo_mapa_admin']);
    }

    private static function elegible($mesa): bool
    {
        return (int)($mesa->activo ?? 0) === 1
            && (int)($mesa->reservable ?? 0) === 1
            && (string)($mesa->tipo ?? '') === 'mesa'
            && (int)($mesa->capacidad ?? 0) > 0;
    }

    private static function zona($mesa): string
    {
        return (string)($mesa->zona ?? '');
    }

    private static function rangoZona($mesa): int
    {
        return match (self::zona($mesa)) {
            ReservacionConfig::ZONA_INTERIOR => 0,
            ReservacionConfig::ZONA_EXTERIOR => 1,
            default => 2,
        };
    }

    private static function capacidad(array $mesas): int
    {
        return array_sum(array_map(static fn($mesa): int => (int)($mesa->capacidad ?? 0), $mesas));
    }

    /** @return array<int, int> */
    private static function numeros(array $mesas): array
    {
        $numeros = array_map(static fn($mesa): int => (int)($mesa->numero ?? 0), $mesas);
        sort($numeros, SORT_NUMERIC);

        return $numeros;
    }

    /** @return array<int, int> */
    private static function idsMesas(array $mesas): array
    {
        $ids = array_map(static fn($mesa): int => (int)$mesa->id, $mesas);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<int, int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = HorarioReservacionService::normalizarHoraSql($hora);
        if ($fecha === '' || $hora === '') {
            return null;
        }
        $resultado = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha . ' ' . $hora, ReservacionConfig::timezone());

        return $resultado instanceof DateTimeImmutable ? $resultado : null;
    }

    // ── Resultados ────────────────────────────────────────────────────

    private static function resultado(string $tipo, array $mesas, array $reubicaciones): array
    {
        return [
            'ok' => true,
            'motivo' => null,
            'tipo' => $tipo,
            'mesas' => array_values($mesas),
            'mesa_ids' => array_values(array_map(static fn($mesa): int => (int)$mesa->id, $mesas)),
            'capacidad_total' => self::capacidad($mesas),
            'reubicaciones' => $reubicaciones,
        ];
    }

    private static function ninguna(string $motivo): array
    {
        return [
            'ok' => false,
            'motivo' => $motivo,
            'tipo' => null,
            'mesas' => [],
            'mesa_ids' => [],
            'capacidad_total' => 0,
            'reubicaciones' => [],
        ];
    }
}
