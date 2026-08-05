<?php

/**
 * Coordina la asignacion manual y automatica de mesas.
 * Protege las operaciones con transacciones y bloqueos de registros.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;
use Model\TicketMesa;

class AsignacionMesasService
{
    public const ASIGNACION_GUARDADA = 'ASIGNACION_GUARDADA';
    public const SIN_CAPACIDAD = 'SIN_CAPACIDAD';
    public const MESA_OCUPADA = 'MESA_OCUPADA';
    public const ESTADO_INVALIDO = 'ESTADO_INVALIDO';
    public const RESERVACION_NO_EXISTE = 'RESERVACION_NO_EXISTE';
    public const ASIGNACION_VACIA = 'ASIGNACION_VACIA';
    public const MESAS_INVALIDAS = 'MESAS_INVALIDAS';
    public const CAPACIDAD_INSUFICIENTE = 'CAPACIDAD_INSUFICIENTE';
    public const CONFLICTO_TICKETS_ABIERTOS = 'CONFLICTO_TICKETS_ABIERTOS';
    public const CONFLICTO_CONCURRENTE = 'CONFLICTO_CONCURRENTE';
    public const VERSION_DESACTUALIZADA = 'VERSION_DESACTUALIZADA';
    public const DATOS_INCOMPLETOS = 'DATOS_INCOMPLETOS';
    public const RESERVACION_NO_EDITABLE = 'RESERVACION_NO_EDITABLE';
    public const SUPERPOSICION_NO_AUTORIZADA = 'SUPERPOSICION_NO_AUTORIZADA';
    public const AGRUPACION_NO_AUTORIZADA = 'AGRUPACION_NO_AUTORIZADA';
    public const CONFLICTO_TICKET_ABIERTO = 'CONFLICTO_TICKET_ABIERTO';
    public const DEPENDE_LIBERACION_PROYECTADA = 'DEPENDE_LIBERACION_PROYECTADA';
    public const SIN_CONTACTO = 'SIN_CONTACTO';
    public const LIBERAR_ASIGNACION_ACTUAL = 'LIBERAR_ASIGNACION_ACTUAL';
    public const LIBERACION_NO_AUTORIZADA = 'LIBERACION_NO_AUTORIZADA';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    private const TIPO_AUTOMATICA_GENERAL = 'general';
    private const TIPO_AUTOMATICA_PUBLICA = 'publica';

    public static function asignarManual(
        int $reservacionId,
        array $mesaIds,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true,
        array $opciones = []
    ): array
    {
        $mesaIds = self::normalizarMesaIds($mesaIds);

        if (empty($mesaIds)) {
            return ['ok' => false, 'codigo' => self::ASIGNACION_VACIA];
        }

        return self::asignar(
            $reservacionId,
            $mesaIds,
            'manual',
            $permitirCapacidadInsuficiente,
            $gestionarTransaccion,
            $opciones
        );
    }

    public static function asignarAutomaticamente(int $reservacionId, bool $gestionarTransaccion = true): array
    {
        return self::asignar($reservacionId, [], self::TIPO_AUTOMATICA_GENERAL, false, $gestionarTransaccion);
    }

    public static function asignarAutomaticamentePublica(int $reservacionId, bool $gestionarTransaccion = true): array
    {
        return self::asignar($reservacionId, [], self::TIPO_AUTOMATICA_PUBLICA, false, $gestionarTransaccion);
    }

    public static function obtenerOcupacionParaHorario(
        string $fecha,
        string $hora,
        int $excluirReservacionId = 0,
        bool $bloquear = false,
        bool $forzarOcupacionFisica = false
    ): array {
        if ($forzarOcupacionFisica) {
            $asignaciones = ReservacionMesa::obtenerOcupacionDelDia(
                $fecha,
                $excluirReservacionId,
                $bloquear
            );

            return self::combinarOcupacion(
                OcupacionMesasService::ocupacionReservacionesEnVentana(
                    $asignaciones,
                    $hora,
                    $excluirReservacionId
                ),
                TicketMesa::ocupacionAbierta($bloquear)
            );
        }

        return (array)(OcupacionMesasService::evaluarHorario(
            $fecha,
            $hora,
            $excluirReservacionId,
            $bloquear
        )['ocupacion_bloqueante'] ?? []);
    }

    public static function obtenerOcupacionPorReservacionDelDia(
        string $fecha,
        array $reservaciones,
        ?array $ticketsAbiertos = null
    ): array
    {
        $tickets = $ticketsAbiertos ?? TicketMesa::abiertosParaMapa();
        $ocupacion = [];

        foreach ($reservaciones as $reservacion) {
            $reservacionId = (int)($reservacion->id ?? 0);
            $hora = (string)($reservacion->hora ?? '');
            $ocupacion[$reservacionId] = (array)(OcupacionMesasService::evaluarHorario(
                $fecha,
                $hora,
                $reservacionId,
                false,
                $tickets
            )['ocupacion_bloqueante'] ?? []);
        }

        return $ocupacion;
    }

    public static function seleccionarMesasGeneral(
        array $mesasDisponibles,
        int $comensales,
        array $mesaIdsProyectadas = []
    ): array
    {
        return self::seleccionarCanonica($mesasDisponibles, $comensales);
    }

    public static function seleccionarMesasPublicas(
        array $mesasDisponibles,
        int $comensales,
        array $mesaIdsProyectadas = []
    ): array
    {
        return self::seleccionarCanonica($mesasDisponibles, $comensales);
    }

    public static function validarCapacidad(array $mesas, array $mesaIds, int $comensales): bool
    {
        return self::capacidadTotal($mesas, $mesaIds) >= $comensales;
    }

    /** Valida la agrupacion publica sin seleccionar ni mutar mesas. */
    public static function agrupacionPublicaValida(array $mesas, int $comensales): bool
    {
        if (!OcupacionMesasService::agrupacionValida($mesas, $comensales)) {
            return false;
        }

        if ($comensales <= 4) {
            return count($mesas) === 1;
        }

        $numeros = array_map(static fn($mesa): int => (int)($mesa->numero ?? 0), $mesas);
        sort($numeros, SORT_NUMERIC);
        foreach (array_merge(ReservacionConfig::GRUPOS_DOS_MESAS, ReservacionConfig::GRUPOS_TRES_MESAS) as $grupo) {
            $grupo = array_map('intval', (array)$grupo);
            sort($grupo, SORT_NUMERIC);
            if ($grupo === $numeros) {
                return true;
            }
        }

        return false;
    }

    public static function hayConflictoHorario(array $ocupacion, array $mesaIds): bool
    {
        foreach (self::normalizarMesaIds($mesaIds) as $mesaId) {
            if (!empty($ocupacion[$mesaId])) {
                return true;
            }
        }

        return false;
    }

    public static function capacidadTotal(array $mesas, array $mesaIds = []): int
    {
        $mesaIds = self::normalizarMesaIds($mesaIds);
        $filtrar = !empty($mesaIds);
        $porId = array_fill_keys($mesaIds, true);

        return array_reduce($mesas, static function (int $total, $mesa) use ($filtrar, $porId): int {
            $mesaId = (int)($mesa->id ?? 0);

            if ($filtrar && !isset($porId[$mesaId])) {
                return $total;
            }

            return $total + (int)($mesa->capacidad ?? 0);
        }, 0);
    }

    /**
     * Selección pura y determinista. Las combinaciones se resuelven por
     * mesas.numero, nunca por IDs ni por una suma arbitraria de capacidad.
     *
     * @return array<int, object>
     */
    private static function seleccionarCanonica(array $mesasDisponibles, int $comensales): array
    {
        $comensales = (int)$comensales;
        if ($comensales < 1 || $comensales > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return [];
        }

        $elegibles = [];
        foreach ($mesasDisponibles as $mesa) {
            if (
                (int)($mesa->activo ?? 0) === 1
                && (int)($mesa->reservable ?? 0) === 1
                && (string)($mesa->tipo ?? '') === 'mesa'
                && (int)($mesa->capacidad ?? 0) > 0
            ) {
                $elegibles[(int)($mesa->numero ?? 0)] = $mesa;
            }
        }
        ksort($elegibles, SORT_NUMERIC);

        $candidatas = [];
        if ($comensales <= 4) {
            foreach ($elegibles as $mesa) {
                if ((int)$mesa->capacidad >= $comensales) {
                    $candidatas[] = [[$mesa], 0];
                }
            }
        } else {
            foreach (ReservacionConfig::GRUPOS_DOS_MESAS as $indice => $grupo) {
                $candidata = self::resolverGrupo($grupo, $elegibles);
                if ($candidata !== [] && self::capacidadSeleccion($candidata) >= $comensales) {
                    $candidatas[] = [$candidata, $indice];
                }
            }
            foreach (ReservacionConfig::GRUPOS_TRES_MESAS as $indice => $grupo) {
                $candidata = self::resolverGrupo($grupo, $elegibles);
                if ($candidata !== [] && self::capacidadSeleccion($candidata) >= $comensales) {
                    $candidatas[] = [$candidata, count(ReservacionConfig::GRUPOS_DOS_MESAS) + $indice];
                }
            }
        }

        if ($candidatas === []) {
            return [];
        }

        usort($candidatas, static function (array $a, array $b) use ($comensales): int {
            $mesasA = $a[0];
            $mesasB = $b[0];
            return (count($mesasA) <=> count($mesasB))
                ?: ((self::capacidadSeleccion($mesasA) - $comensales)
                    <=> (self::capacidadSeleccion($mesasB) - $comensales))
                ?: (($a[1] ?? 0) <=> ($b[1] ?? 0))
                ?: (self::numerosSeleccion($mesasA) <=> self::numerosSeleccion($mesasB));
        });

        return $candidatas[0][0];
    }

    /** @return array<int, object> */
    private static function resolverGrupo(array $numeros, array $porNumero): array
    {
        $resultado = [];
        foreach ($numeros as $numero) {
            if (!isset($porNumero[(int)$numero])) {
                return [];
            }
            $resultado[] = $porNumero[(int)$numero];
        }
        return $resultado;
    }

    private static function asignar(
        int $reservacionId,
        array $mesaIds,
        string $tipoAsignacion,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true,
        array $opciones = []
    ): array {
        $automatico = in_array($tipoAsignacion, [self::TIPO_AUTOMATICA_GENERAL, self::TIPO_AUTOMATICA_PUBLICA], true);

        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();

        try {
            if ($gestionarTransaccion && !$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transaccion de asignacion.');
            }

            $reservacion = self::fila(
                "SELECT id, fecha, hora, comensales, estado, origen,
                        contacto_tipo, contacto,
                        created_at, updated_at
                 FROM reservaciones
                 WHERE id = {$reservacionId}
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$reservacion) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
            }

            $modoMapaAdministrativo = !empty($opciones['modo_administrativo_mapa']);
            if ($modoMapaAdministrativo) {
                $ticketVinculado = self::fila(
                    "SELECT id FROM tickets WHERE reservacion_id = {$reservacionId} AND "
                    . TicketMesa::condicionSqlAbierto('tickets') . " LIMIT 1 FOR UPDATE"
                );
                if ($ticketVinculado) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::RESERVACION_NO_EDITABLE];
                }
            }
            $codigoNoEditable = ReservacionService::codigoNoEditable($reservacion);
            if ($codigoNoEditable !== '') {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => in_array($codigoNoEditable, [
                        ReservacionService::RESERVACION_PASADA,
                        ReservacionService::RESERVACION_HORARIO_PASADO,
                    ], true) ? $codigoNoEditable : self::RESERVACION_NO_EDITABLE,
                ];
            }
            if ($modoMapaAdministrativo && (string)$reservacion['estado'] !== 'confirmada') {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }

            $mesas = $automatico
                ? Mesa::reservablesParaActualizar()
                : Mesa::reservablesParaActualizar($mesaIds);

            if (!$automatico && count($mesas) !== count($mesaIds)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::MESAS_INVALIDAS];
            }

            $versionEsperada = trim((string)($opciones['version_esperada'] ?? ''));
            $asignacionActualIds = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
            $validarContexto = !empty($opciones['validar_contexto']);
            if ($validarContexto && empty($opciones['contexto_completo'])) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::DATOS_INCOMPLETOS];
            }

            $versionActual = hash(
                'sha256',
                (string)($reservacion['updated_at'] ?: $reservacion['created_at'])
                    . '|' . implode(',', $asignacionActualIds)
            );
            if ($validarContexto) {
                $fechaEsperada = trim((string)($opciones['fecha_esperada'] ?? ''));
                $horaEsperada = HorarioReservacionService::normalizarHoraSql(
                    (string)($opciones['hora_esperada'] ?? '')
                );
                $mesasActualesEsperadas = self::normalizarMesaIds(
                    (array)($opciones['mesa_ids_actuales'] ?? [])
                );
                $mesasActualesComparables = $asignacionActualIds;
                sort($mesasActualesEsperadas, SORT_NUMERIC);
                sort($mesasActualesComparables, SORT_NUMERIC);
                if (
                    $fechaEsperada !== (string)$reservacion['fecha']
                    || $horaEsperada !== HorarioReservacionService::normalizarHoraSql(
                        (string)$reservacion['hora']
                    )
                    || $mesasActualesEsperadas !== $mesasActualesComparables
                ) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return [
                        'ok' => false,
                        'codigo' => self::VERSION_DESACTUALIZADA,
                        'version_actual' => $versionActual,
                    ];
                }
            }
            if (!$automatico && $versionEsperada !== '' && !hash_equals($versionActual, $versionEsperada)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::VERSION_DESACTUALIZADA,
                    'version_actual' => $versionActual,
                ];
            }

            $ticketsAbiertos = TicketMesa::abiertosParaMapa(true);
            $evaluacionOcupacion = OcupacionMesasService::evaluarHorario(
                (string)$reservacion['fecha'],
                (string)$reservacion['hora'],
                $reservacionId,
                true,
                $ticketsAbiertos
            );
            $ocupacionReservaciones = (array)(
                $evaluacionOcupacion['ocupacion_reservaciones'] ?? []
            );
            $ocupacion = (array)($evaluacionOcupacion['ocupacion_bloqueante'] ?? []);
            $ticketsBloqueantes = (array)($evaluacionOcupacion['tickets_bloqueantes'] ?? []);
            $mesaIdsProyectadas = self::normalizarMesaIds(
                (array)($evaluacionOcupacion['mesas_proyectadas'] ?? [])
            );
            $conflictosTicket = [];
            $ticketConfirmacionPendiente = false;
            $confirmaciones = self::normalizarConfirmaciones($opciones['confirmaciones'] ?? []);

            if ($automatico) {
                $disponibles = array_values(array_filter($mesas, static function ($mesa) use ($ocupacion): bool {
                    return empty($ocupacion[(int)$mesa->id]);
                }));

                usort($disponibles, static function ($a, $b): int {
                    return ((int)$a->numero <=> (int)$b->numero) ?: ((int)$a->id <=> (int)$b->id);
                });

                $seleccionadas = $tipoAsignacion === self::TIPO_AUTOMATICA_PUBLICA
                    ? self::seleccionarMesasPublicas(
                        $disponibles,
                        (int)$reservacion['comensales'],
                        $mesaIdsProyectadas
                    )
                    : self::seleccionarMesasGeneral(
                        $disponibles,
                        (int)$reservacion['comensales'],
                        $mesaIdsProyectadas
                    );

                if (empty($seleccionadas)) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::SIN_CAPACIDAD];
                }

                $mesaIds = array_map(static function ($mesa): int {
                    return (int)$mesa->id;
                }, $seleccionadas);
                $mesas = $seleccionadas;
            } else {
                if (self::hayConflictoHorario($ocupacionReservaciones, $mesaIds)) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::MESA_OCUPADA];
                }

                $conflictosTicket = self::conflictosTicketsSeleccionados(
                    $ticketsBloqueantes,
                    $mesaIds
                );
                if ($conflictosTicket !== []) {
                    if (empty($opciones['permitir_superposicion_ticket_abierto'])) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return [
                            'ok' => false,
                            'codigo' => self::SUPERPOSICION_NO_AUTORIZADA,
                            'conflictos_ticket' => $conflictosTicket,
                        ];
                    }
                    $ticketIdsActuales = array_column($conflictosTicket, 'ticket_id');
                    $ticketIdsAceptados = self::normalizarMesaIds(
                        (array)($opciones['ticket_ids_aceptados'] ?? [])
                    );
                    $tokenActual = self::tokenConflictosTicket($conflictosTicket);
                    $tokenAceptado = trim((string)($opciones['conflicto_token'] ?? ''));

                    if ($ticketIdsAceptados === []) {
                        $ticketConfirmacionPendiente = true;
                    }

                    sort($ticketIdsActuales, SORT_NUMERIC);
                    sort($ticketIdsAceptados, SORT_NUMERIC);
                    if (
                        !$ticketConfirmacionPendiente
                        && ($ticketIdsActuales !== $ticketIdsAceptados
                        || $tokenAceptado === ''
                        || !hash_equals($tokenActual, $tokenAceptado))
                    ) {
                        self::rollbackSiPropia($db, $gestionarTransaccion);
                        return [
                            'ok' => false,
                            'codigo' => self::CONFLICTO_CONCURRENTE,
                            'conflictos_ticket' => $conflictosTicket,
                            'conflicto_token' => $tokenActual,
                        ];
                    }
                } elseif ((array)($opciones['ticket_ids_aceptados'] ?? []) !== []) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return [
                        'ok' => false,
                        'codigo' => self::CONFLICTO_CONCURRENTE,
                        'conflictos_ticket' => [],
                    ];
                }
            }

            if (
                !$modoMapaAdministrativo
                && (int)$reservacion['comensales'] <= ReservacionConfig::MAX_PUBLIC_GUESTS
                && !OcupacionMesasService::agrupacionValida(
                    $mesas,
                    (int)$reservacion['comensales']
                )
            ) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::AGRUPACION_NO_AUTORIZADA];
            }

            $capacidadInsuficiente = !self::validarCapacidad(
                $mesas,
                $mesaIds,
                (int)$reservacion['comensales']
            );
            $seleccionProyectada = array_values(array_intersect(
                self::normalizarMesaIds($mesaIds),
                $mesaIdsProyectadas
            ));
            $dependeLiberacionProyectada = $seleccionProyectada !== [];
            $advertencias = [];
            $confirmacionesRequeridas = [];
            if ($capacidadInsuficiente) {
                $advertencias[] = self::CAPACIDAD_INSUFICIENTE;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::CAPACIDAD_INSUFICIENTE;
                }
            }
            if ($dependeLiberacionProyectada) {
                $advertencias[] = self::DEPENDE_LIBERACION_PROYECTADA;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::DEPENDE_LIBERACION_PROYECTADA;
                }
            }
            if ($conflictosTicket !== []) {
                $advertencias[] = self::CONFLICTO_TICKET_ABIERTO;
                if ($modoMapaAdministrativo) {
                    $confirmacionesRequeridas[] = self::CONFLICTO_TICKET_ABIERTO;
                }
            }
            if (
                trim((string)($reservacion['contacto'] ?? '')) === ''
                || (string)($reservacion['contacto_tipo'] ?? 'ninguno') === 'ninguno'
            ) {
                $advertencias[] = self::SIN_CONTACTO;
            }
            $advertencias = array_values(array_unique($advertencias));
            $confirmacionesRequeridas = array_values(array_unique($confirmacionesRequeridas));

            if ($ticketConfirmacionPendiente || (
                $modoMapaAdministrativo
                && $conflictosTicket !== []
                && !in_array(self::CONFLICTO_TICKET_ABIERTO, $confirmaciones, true)
            )) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::CONFLICTO_TICKETS_ABIERTOS,
                    'requiere_confirmacion' => true,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                    'conflictos_ticket' => $conflictosTicket,
                    'conflicto_token' => self::tokenConflictosTicket($conflictosTicket),
                ];
            }

            $capacidadConfirmada = $permitirCapacidadInsuficiente
                || in_array(self::CAPACIDAD_INSUFICIENTE, $confirmaciones, true);
            if ($capacidadInsuficiente && !$capacidadConfirmada) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::CAPACIDAD_INSUFICIENTE,
                    'requiere_confirmacion' => $modoMapaAdministrativo,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                ];
            }
            if (
                $modoMapaAdministrativo
                && $dependeLiberacionProyectada
                && !in_array(self::DEPENDE_LIBERACION_PROYECTADA, $confirmaciones, true)
            ) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => self::DEPENDE_LIBERACION_PROYECTADA,
                    'requiere_confirmacion' => true,
                    'advertencias' => $advertencias,
                    'confirmaciones_requeridas' => $confirmacionesRequeridas,
                ];
            }

            ReservacionMesa::reemplazarAsignacion($reservacionId, $mesaIds);
            // La versión administrativa incluye updated_at y la asignación
            // actual. La tabla pivote no tiene timestamp propio, por lo que
            // una reasignación debe avanzar explícitamente la versión para
            // que un snapshot concurrente quede obsoleto aunque ambas
            // escrituras ocurran dentro del mismo segundo.
            if (!$db->query(
                "UPDATE reservaciones
                 SET updated_at = CASE
                     WHEN updated_at IS NULL THEN CURRENT_TIMESTAMP
                     ELSE GREATEST(CURRENT_TIMESTAMP, updated_at + INTERVAL 1 SECOND)
                 END
                 WHERE id = {$reservacionId}"
            )) {
                throw new \RuntimeException('No fue posible actualizar la version de asignacion.');
            }
            $motivo = !empty($conflictosTicket)
                ? 'Asignación manual aceptó tickets abiertos #' . implode(', #', array_column($conflictosTicket, 'ticket_id'))
                : ($automatico ? 'Asignación automática de mesas' : 'Asignación manual de mesas');
            if ($dependeLiberacionProyectada) {
                $motivo .= ' con liberación proyectada de mesas #'
                    . implode(', #', $seleccionProyectada);
            }
            $motivo = ActiveRecord::escaparString($motivo);
            if ($gestionarTransaccion && !$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transaccion de asignacion.');
            }

            return [
                'ok' => true,
                'codigo' => self::ASIGNACION_GUARDADA,
                'mesa_ids' => $mesaIds,
                'tickets_aceptados' => array_column($conflictosTicket ?? [], 'ticket_id'),
                'depende_liberacion_proyectada' => $dependeLiberacionProyectada,
                'mesas_proyectadas' => $seleccionProyectada,
                'advertencias' => $advertencias,
                'confirmaciones_requeridas' => [],
                'advertencia' => $dependeLiberacionProyectada
                    ? 'La asignación depende de mesas con servicio activo y liberación proyectada. Verifica su estado durante la operación.'
                    : null,
            ];
        } catch (\Throwable $e) {
            try {
                if ($gestionarTransaccion) {
                    $db->rollback();
                }
            } catch (\Throwable $rollbackError) {
                error_log('AsignacionMesasService rollback - ' . $rollbackError->getMessage());
            }

            error_log('AsignacionMesasService::asignar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    private static function rollbackSiPropia(\mysqli $db, bool $gestionarTransaccion): void
    {
        if ($gestionarTransaccion) {
            $db->rollback();
        }
    }

    private static function ocupacionEnVentana(array $asignaciones, string $hora, int $excluirReservacionId = 0): array
    {
        $ocupadas = [];

        foreach ($asignaciones as $asignacion) {
            if ($excluirReservacionId > 0 && (int)$asignacion['reservacion_id'] === $excluirReservacionId) {
                continue;
            }

            if (!self::hayTraslapeHorario($hora, (string)$asignacion['hora']) || empty($asignacion['mesa_id'])) {
                continue;
            }

            $ocupadas[(int)$asignacion['mesa_id']] = [
                'reservacion_id' => (int)$asignacion['reservacion_id'],
                'nombre' => (string)$asignacion['nombre'],
                'contacto' => (string)$asignacion['contacto'],
                'hora' => (string)$asignacion['hora'],
                'comensales' => (int)$asignacion['comensales'],
                'estado' => (string)$asignacion['estado'],
            ];
        }

        return $ocupadas;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function conflictosTicketsSeleccionados(array $tickets, array $mesaIds): array
    {
        $seleccion = array_fill_keys(self::normalizarMesaIds($mesaIds), true);
        $conflictos = [];

        foreach ($tickets as $ticket) {
            $todas = self::normalizarMesaIds((array)($ticket['mesa_ids'] ?? []));
            $enConflicto = array_values(array_filter(
                $todas,
                static fn(int $mesaId): bool => isset($seleccion[$mesaId])
            ));
            if ($enConflicto === []) {
                continue;
            }

            $conflictos[] = [
                'ticket_id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
                'reservacion_id' => $ticket['reservacion_id'] ?? null,
                'origen' => (string)($ticket['origen'] ?? 'walk_in'),
                'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
                'mesa_ids' => $todas,
                'mesas_conflicto' => $enConflicto,
            ];
        }

        usort(
            $conflictos,
            static fn(array $a, array $b): int => $a['ticket_id'] <=> $b['ticket_id']
        );

        return $conflictos;
    }

    private static function tokenConflictosTicket(array $conflictos): string
    {
        return hash('sha256', json_encode($conflictos, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    /**
     * La ocupación física prevalece sobre la agenda: un estado final erróneo
     * no libera una mesa mientras su ticket continúe abierto.
     */
    private static function combinarOcupacion(array $reservaciones, array $tickets): array
    {
        $ocupacion = $reservaciones;
        foreach ($tickets as $ticket) {
            $mesaId = (int)($ticket['mesa_id'] ?? 0);
            if ($mesaId < 1) {
                continue;
            }

            $ocupacion[$mesaId] = [
                'tipo' => 'ticket_abierto',
                'ticket_id' => (int)($ticket['ticket_id'] ?? 0),
                'reservacion_id' => $ticket['reservacion_id'] ?? null,
                'nombre' => 'Servicio activo',
                'contacto' => '',
                'hora' => '',
                'comensales' => 0,
                'estado' => 'ticket_abierto',
                'walk_in' => !empty($ticket['walk_in']),
                'mesa_ids' => $ticket['mesa_ids'] ?? [$mesaId],
                'liberacion_estimada' => $ticket['liberacion_estimada'] ?? null,
            ];
        }

        return $ocupacion;
    }

    /**
     * La ventana es simetrica: dos reservaciones chocan si sus rangos
     * [hora - bloqueo previo, hora + duracion) se traslapan.
     */
    private static function hayTraslapeHorario(string $horaA, string $horaB): bool
    {
        $a = self::minutosDesdeHora($horaA);
        $b = self::minutosDesdeHora($horaB);
        $inicioA = $a - ReservacionConfig::BLOQUEO_PREVIO_MESA_MINUTOS;
        $finA = $a + ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        $inicioB = $b - ReservacionConfig::BLOQUEO_PREVIO_MESA_MINUTOS;
        $finB = $b + ReservacionConfig::DURACION_RESERVACION_MINUTOS;

        return $inicioA < $finB && $inicioB < $finA;
    }

    private static function normalizarMesaIds(array $mesaIds): array
    {
        $ids = [];

        foreach ($mesaIds as $mesaId) {
            $mesaId = (int)$mesaId;

            if ($mesaId > 0 && !in_array($mesaId, $ids, true)) {
                $ids[] = $mesaId;
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    private static function normalizarConfirmaciones($confirmaciones): array
    {
        if (!is_array($confirmaciones)) {
            $confirmaciones = [$confirmaciones];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn($codigo): string => trim((string)$codigo),
            $confirmaciones
        ))));
    }

    private static function capacidadSeleccion(array $mesas): int
    {
        return array_reduce($mesas, static function (int $total, $mesa): int {
            return $total + (int)($mesa->capacidad ?? 0);
        }, 0);
    }

    private static function numerosSeleccion(array $mesas): string
    {
        $numeros = array_map(static function ($mesa): int {
            return (int)($mesa->numero ?? 0);
        }, $mesas);

        sort($numeros, SORT_NUMERIC);

        return implode('-', array_map(static function (int $numero): string {
            return str_pad((string)$numero, 3, '0', STR_PAD_LEFT);
        }, $numeros));
    }

    private static function fila(string $query): ?array
    {
        $resultado = ActiveRecord::getDB()->query($query);

        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();

        return $fila;
    }

    private static function minutosDesdeHora(string $hora): int
    {
        $partes = explode(':', $hora);
        $horas = isset($partes[0]) ? (int)$partes[0] : 0;
        $min = isset($partes[1]) ? (int)$partes[1] : 0;

        return ($horas * 60) + $min;
    }
}
