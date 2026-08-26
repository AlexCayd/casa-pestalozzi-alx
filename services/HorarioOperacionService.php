<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\ExcepcionOperacion;
use Model\HorarioOperacion;
use Model\Reservacion;

class HorarioOperacionService
{
    public const TIPOS_EXCEPCION = ['cerrado', 'horario_especial'];
    public const CIERRE_EXCLUSIVO = true;

    private const DIAS = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ];

    public static function obtenerHorarioSemanal(): array
    {
        $existentes = [];
        foreach (HorarioOperacion::todosOrdenados() as $horario) {
            $existentes[(int) $horario->dia_semana] = $horario;
        }

        $semana = [];
        foreach (self::DIAS as $dia => $nombre) {
            $horario = $existentes[$dia] ?? null;
            // Ausencia de configuración válida significa cerrado; nunca se
            // inventa un horario abierto como fallback operativo.
            $abierto = $horario ? (bool) $horario->abierto : false;
            $semana[] = [
                'id' => $horario ? (int) $horario->id : null,
                'dia_semana' => $dia,
                'nombre' => $nombre,
                'abierto' => $abierto,
                'hora_apertura' => $abierto
                    ? self::horaCorta((string) ($horario->hora_apertura ?? '08:00:00'))
                    : '',
                'hora_cierre' => $abierto
                    ? self::horaCorta((string) ($horario->hora_cierre ?? '22:00:00'))
                    : '',
                'configurado' => $horario !== null,
            ];
        }

        return $semana;
    }

    /** Devuelve el horario informativo público en orden lunes a domingo. */
    public static function obtenerHorarioSemanalPublico(): array
    {
        $semana = self::obtenerHorarioSemanal();

        return array_merge(array_slice($semana, 1), array_slice($semana, 0, 1));
    }

    /**
     * Indexa por día de la semana las excepciones que caen dentro de los
     * próximos siete días.
     *
     * La landing muestra las excepciones SOBRE la fila del día que les toca —el
     * visitante conecta "el martes abren tarde" con la fila del martes— y para
     * eso hace falta traducir una fecha a su día de la semana. La cuenta vive
     * aquí y no en la vista porque la piden dos: la tabla de la sección de
     * reservación y la columna de horario del pie, que antes ignoraba las
     * excepciones y contradecía a la de arriba.
     *
     * Sólo entran siete días porque sólo hay siete filas que marcar; lo que cae
     * más allá no se anuncia. La ventana se calcula en la zona horaria del
     * restaurante: el reloj del visitante puede ser otro.
     *
     * @param array<int, array<string, mixed>> $excepciones
     * @return array<int, array<string, mixed>> día de la semana (0-6) => excepción
     */
    public static function mapearExcepcionesDeLaSemana(
        array $excepciones,
        DateTimeImmutable $hoy
    ): array {
        $ventana = [];
        for ($i = 0; $i < 7; $i++) {
            $ventana[$hoy->modify('+' . $i . ' days')->format('Y-m-d')] = $i;
        }

        $porDia = [];
        foreach ($excepciones as $excepcion) {
            $fecha = (string) ($excepcion['fecha'] ?? '');
            if (!isset($ventana[$fecha])) {
                continue;
            }
            // Una sola excepción por fecha (uq_excepciones_operacion_fecha) y
            // siete fechas distintas: no hay colisión posible en la ventana.
            $dia = (int) $hoy->modify('+' . $ventana[$fecha] . ' days')->format('w');
            $porDia[$dia] = $excepcion;
        }

        return $porDia;
    }

    /** Devuelve solo las próximas excepciones activas que pueden mostrarse públicamente. */
    public static function obtenerProximasExcepciones(?int $limite = 5): array
    {
        $excepciones = self::listarExcepciones([
            'activo' => true,
            'fecha_desde' => self::fechaActual(),
        ]);

        return $limite === null
            ? $excepciones
            : array_slice($excepciones, 0, max(1, $limite));
    }

    public static function guardarHorarioSemanal(
        array $horarios,
        ?int $usuarioId = null,
        bool $confirmarConflictos = false
    ): array
    {
        $validacion = self::validarHorarioSemanal($horarios);
        if (!$validacion['ok']) {
            return array_merge($validacion, [
                'codigo' => 'HORARIO_INVALIDO',
            ]);
        }

        $db = ActiveRecord::getDB();
        $transaccionIniciada = false;
        $lockHorario = false;

        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transacción.');
            }
            $transaccionIniciada = true;

            $lockHorario = HorarioConfigLock::adquirir($db);
            if (!$lockHorario) {
                throw new \RuntimeException('No fue posible bloquear la configuración de horarios.');
            }

            $diasModificados = self::prepararDiasModificados($validacion['datos'], $usuarioId);
            $conflictos = self::detectarConflictosReservaciones($diasModificados);
            if ($conflictos !== [] && !$confirmarConflictos) {
                $db->rollback();
                $transaccionIniciada = false;
                return [
                    'ok' => false,
                    'codigo' => 'RESERVACIONES_AFECTADAS',
                    'reservaciones_afectadas' => count($conflictos),
                    'requiere_confirmacion' => true,
                    'datos' => $validacion['datos'],
                    'horarios' => $validacion['horarios'],
                    'conflictos' => $conflictos,
                ];
            }

            foreach ($validacion['datos'] as $datos) {
                $horario = new HorarioOperacion($datos);
                $horario->updated_by = $usuarioId;

                if (!$horario->guardarPorDia()) {
                    throw new \RuntimeException('No fue posible guardar uno de los días.');
                }

            }

            self::verificarHorarioCanonico($validacion['datos']);

            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transacción.');
            }
            $transaccionIniciada = false;

            return [
                'ok' => true,
                'codigo' => 'HORARIOS_ACTUALIZADOS',
                'datos' => $validacion['datos'],
                'horarios' => self::obtenerHorarioSemanal(),
            ];
        } catch (\Throwable $e) {
            if ($transaccionIniciada) {
                $db->rollback();
            }
            error_log('HorarioOperacionService::guardarHorarioSemanal - ' . $e->getMessage());

            return [
                'ok' => false,
                'codigo' => 'ERROR_ACTUALIZACION_HORARIOS',
                'datos' => $validacion['datos'],
                'horarios' => $validacion['horarios'],
            ];
        } finally {
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    public static function listarExcepciones(array $filtros = []): array
    {
        return array_map(static function (ExcepcionOperacion $excepcion): array {
            $especial = $excepcion->tipo === 'horario_especial';

            return [
                'id' => (int) $excepcion->id,
                'fecha' => (string) $excepcion->fecha,
                'tipo' => (string) $excepcion->tipo,
                'tipo_nombre' => $especial ? 'Horario especial' : 'Cerrado',
                'motivo' => (string) ($excepcion->motivo ?? ''),
                'hora_apertura' => $especial ? self::horaCorta((string) $excepcion->hora_apertura) : '',
                'hora_cierre' => $especial ? self::horaCorta((string) $excepcion->hora_cierre) : '',
                'horario' => $especial
                    ? self::horaCorta((string) $excepcion->hora_apertura) . ' – ' . self::horaCorta((string) $excepcion->hora_cierre)
                    : 'Cerrado todo el día',
                'activo' => (bool) $excepcion->activo,
            ];
        }, ExcepcionOperacion::listarOrdenadas($filtros));
    }

    public static function fechaActual(): string
    {
        return ReservacionConfig::fechaActual();
    }

    public static function guardarExcepcion(
        array $datos,
        ?int $usuarioId = null,
        bool $confirmarConflictos = false
    ): array
    {
        $validacion = self::validarExcepcion($datos);
        if (!$validacion['ok']) {
            return array_merge($validacion, ['codigo' => 'HORARIO_INVALIDO']);
        }

        $limpios = $validacion['datos'];
        $id = $limpios['id'];
        $db = ActiveRecord::getDB();
        $transaccionIniciada = false;
        $locksFecha = [];
        $conflictos = [];

        try {
            $fechasLock = [$limpios['fecha']];
            if ($id !== null) {
                $anterior = ExcepcionOperacion::buscarPorId($id);
                if ($anterior) {
                    $fechasLock[] = (string)$anterior->fecha;
                }
            }
            $locksFecha = self::adquirirLocksFecha($db, $fechasLock);
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transaccion de la excepcion.');
            }
            $transaccionIniciada = true;

            $excepcion = null;
            if ($id !== null) {
                $excepcion = ExcepcionOperacion::buscarPorIdParaActualizar($id);
                if (!$excepcion) {
                    return self::errorExcepcion('EXCEPCION_NO_ENCONTRADA', $limpios);
                }
            }

            if (ExcepcionOperacion::existeFecha($limpios['fecha'], $id)) {
                return self::errorExcepcion('EXCEPCION_DUPLICADA', $limpios);
            }

            if ($limpios['activo'] === 1) {
                $conflictos = self::detectarConflictosExcepcion(
                    $limpios['fecha'],
                    $limpios['tipo'],
                    $limpios['hora_apertura'],
                    $limpios['hora_cierre']
                );
                if ($conflictos !== [] && !$confirmarConflictos) {
                    return self::resultadoConflictosExcepcion(
                        $conflictos,
                        $limpios
                    );
                }
            }

            $excepcion = $excepcion ?? new ExcepcionOperacion();
            $excepcion->fecha = $limpios['fecha'];
            $excepcion->tipo = $limpios['tipo'];
            $excepcion->motivo = $limpios['motivo'] !== '' ? $limpios['motivo'] : null;
            $excepcion->hora_apertura = $limpios['hora_apertura'];
            $excepcion->hora_cierre = $limpios['hora_cierre'];
            $excepcion->activo = $limpios['activo'];
            $excepcion->updated_by = $usuarioId;

            if (!$excepcion->guardarExcepcion()) {
                throw new \RuntimeException('El guardado de la excepción no fue confirmado.');
            }

            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transaccion de la excepcion.');
            }
            $transaccionIniciada = false;

            return [
                'ok' => true,
                'id' => (int) $excepcion->id,
                'editada' => $id !== null,
                'codigo' => $id !== null ? 'EXCEPCION_ACTUALIZADA' : 'EXCEPCION_CREADA',
            ];
        } catch (\mysqli_sql_exception $e) {
            error_log('HorarioOperacionService::guardarExcepcion - ' . $e->getMessage());
            if ((int) $e->getCode() === 1062) {
                return self::errorExcepcion('EXCEPCION_DUPLICADA', $limpios);
            }

            return self::errorExcepcion('ERROR_ACTUALIZACION_HORARIOS', $limpios);
        } catch (\Throwable $e) {
            error_log('HorarioOperacionService::guardarExcepcion - ' . $e->getMessage());
            return self::errorExcepcion('ERROR_ACTUALIZACION_HORARIOS', $limpios);
        } finally {
            if ($transaccionIniciada) {
                $db->rollback();
            }
            self::liberarLocksFecha($db, $locksFecha);
        }
    }

    public static function cambiarEstadoExcepcion(int $id, bool $activo, ?int $usuarioId = null): array
    {
        $db = ActiveRecord::getDB();
        $transaccionIniciada = false;
        $locksFecha = [];

        if ($id < 1) {
            return ['ok' => false, 'codigo' => 'EXCEPCION_ID_INVALIDO'];
        }

        try {
            $excepcion = ExcepcionOperacion::buscarPorId($id);
            if (!$excepcion) {
                return ['ok' => false, 'codigo' => 'EXCEPCION_NO_ENCONTRADA'];
            }

            $locksFecha = self::adquirirLocksFecha($db, [(string)$excepcion->fecha]);
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transaccion de estado.');
            }
            $transaccionIniciada = true;
            $excepcion = ExcepcionOperacion::buscarPorIdParaActualizar($id);
            if (!$excepcion) {
                return ['ok' => false, 'codigo' => 'EXCEPCION_NO_ENCONTRADA'];
            }

            if ($activo) {
                $errorTemporal = self::validarAplicacionHoy($excepcion);
                if ($errorTemporal !== null) {
                    return ['ok' => false, 'codigo' => $errorTemporal];
                }

                $conflictos = self::detectarConflictosExcepcion(
                    (string) $excepcion->fecha,
                    (string) $excepcion->tipo,
                    self::horaComparable($excepcion->hora_apertura),
                    self::horaComparable($excepcion->hora_cierre)
                );
                if ($conflictos !== []) {
                    return self::resultadoConflictosExcepcion(
                        $conflictos
                    );
                }
            }

            if (!ExcepcionOperacion::cambiarEstado($id, $activo, $usuarioId)) {
                throw new \RuntimeException('No fue posible actualizar el estado.');
            }

            $persistida = ExcepcionOperacion::buscarPorIdParaActualizar($id);
            if (!$persistida || (int)$persistida->activo !== ($activo ? 1 : 0)) {
                throw new \RuntimeException('No fue posible verificar el estado de la excepcion.');
            }
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transaccion de estado.');
            }
            $transaccionIniciada = false;

            return [
                'ok' => true,
                'codigo' => 'EXCEPCION_ESTADO_ACTUALIZADO',
            ];
        } catch (\Throwable $e) {
            error_log('HorarioOperacionService::cambiarEstadoExcepcion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => 'ERROR_ACTUALIZACION_HORARIOS'];
        } finally {
            if ($transaccionIniciada) {
                $db->rollback();
            }
            self::liberarLocksFecha($db, $locksFecha);
        }
    }

    public static function eliminarExcepcion(int $id): array
    {
        $locksFecha = [];

        if ($id < 1) {
            return ['ok' => false, 'codigo' => 'EXCEPCION_ID_INVALIDO'];
        }

        $db = ActiveRecord::getDB();
        $transaccionIniciada = false;

        try {
            $excepcionPrevia = ExcepcionOperacion::buscarPorId($id);
            if (!$excepcionPrevia) {
                return ['ok' => false, 'codigo' => 'EXCEPCION_NO_ENCONTRADA'];
            }
            $locksFecha = self::adquirirLocksFecha($db, [(string)$excepcionPrevia->fecha]);
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transacción.');
            }
            $transaccionIniciada = true;

            $excepcion = ExcepcionOperacion::buscarPorIdParaActualizar($id);
            if (!$excepcion) {
                throw new \DomainException('La excepción seleccionada no existe.');
            }

            $conflictos = [];
            if ((string) $excepcion->fecha >= ReservacionConfig::fechaActual()) {
                $conflictos = self::detectarConflictosHorarioSemanal((string) $excepcion->fecha);
            }
            if ($conflictos !== []) {
                $db->rollback();
                $transaccionIniciada = false;

                return self::resultadoConflictosExcepcion(
                    $conflictos
                );
            }

            if (!$excepcion->eliminarExcepcion()) {
                throw new \RuntimeException('La eliminación de la excepción no fue confirmada.');
            }
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transacción.');
            }
            $transaccionIniciada = false;

            return ['ok' => true, 'codigo' => 'EXCEPCION_ELIMINADA'];
        } catch (\Throwable $e) {
            if ($transaccionIniciada) {
                $db->rollback();
            }
            error_log('HorarioOperacionService::eliminarExcepcion - ' . $e->getMessage());

            return [
                'ok' => false,
                'codigo' => 'ERROR_ACTUALIZACION_HORARIOS',
            ];
        } finally {
            self::liberarLocksFecha($db, $locksFecha);
        }
    }

    public static function obtenerHorarioEfectivo(string $fecha): array
    {
        if (!self::fechaValida($fecha)) {
            return [
                'fecha' => $fecha,
                'abierto' => false,
                'hora_apertura' => null,
                'hora_cierre' => null,
                'origen' => 'invalido',
                'tipo' => null,
                'motivo' => null,
                'valido' => false,
            ];
        }

        $excepcion = ExcepcionOperacion::buscarActivaPorFecha($fecha);
        if ($excepcion) {
            $especial = $excepcion->tipo === 'horario_especial';

            return [
                'fecha' => $fecha,
                'abierto' => $especial,
                'hora_apertura' => $especial ? self::horaSql((string) $excepcion->hora_apertura) : null,
                'hora_cierre' => $especial ? self::horaSql((string) $excepcion->hora_cierre) : null,
                'origen' => 'excepcion',
                'tipo' => (string) $excepcion->tipo,
                'motivo' => $excepcion->motivo !== null ? (string) $excepcion->motivo : null,
                'configurado' => true,
                'valido' => true,
            ];
        }

        $diaSemana = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)->format('w');
        $horario = HorarioOperacion::buscarPorDia($diaSemana);
        $abierto = $horario !== null
            && (int) $horario->abierto === 1
            && $horario->hora_apertura
            && $horario->hora_cierre;

        return [
            'fecha' => $fecha,
            'abierto' => (bool) $abierto,
            'hora_apertura' => $abierto ? self::horaSql((string) $horario->hora_apertura) : null,
            'hora_cierre' => $abierto ? self::horaSql((string) $horario->hora_cierre) : null,
            'origen' => 'semanal',
            'tipo' => null,
            'motivo' => null,
            'configurado' => $horario !== null,
            'valido' => true,
        ];
    }

    public static function obtenerHorarioHabitualParaFecha(string $fecha): array
    {
        if (!self::fechaValida($fecha)) {
            return [
                'abierto' => false,
                'hora_apertura' => null,
                'hora_cierre' => null,
            ];
        }

        $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        $horario = $fechaObjeto instanceof DateTimeImmutable
            ? HorarioOperacion::buscarPorDia((int) $fechaObjeto->format('w'))
            : null;
        $abierto = $horario !== null
            && (int) $horario->abierto === 1
            && $horario->hora_apertura
            && $horario->hora_cierre;

        return [
            'abierto' => (bool) $abierto,
            'hora_apertura' => $abierto ? self::horaCorta((string) $horario->hora_apertura) : null,
            'hora_cierre' => $abierto ? self::horaCorta((string) $horario->hora_cierre) : null,
        ];
    }

    /** El cierre es exclusivo: hora_apertura <= hora < hora_cierre. */
    public static function estaAbierto(string $fecha, string $hora): bool
    {
        $horaNormalizada = self::normalizarHora($hora);
        if (!self::fechaValida($fecha) || $horaNormalizada === null) {
            return false;
        }

        $efectivo = self::obtenerHorarioEfectivo($fecha);
        if (!$efectivo['abierto']) {
            return false;
        }

        return $horaNormalizada >= $efectivo['hora_apertura']
            && $horaNormalizada < $efectivo['hora_cierre'];
    }

    private static function adquirirLocksFecha(\mysqli $db, array $fechas): array
    {
        $fechas = array_values(array_unique(array_filter(array_map(
            static fn($fecha): string => trim((string)$fecha),
            $fechas
        ))));
        sort($fechas, SORT_STRING);
        $adquiridos = [];

        try {
            foreach ($fechas as $fecha) {
                if (!FechaOperacionLock::adquirir($db, $fecha, 10)) {
                    throw new \RuntimeException('No fue posible bloquear la fecha de operacion.');
                }
                $adquiridos[] = $fecha;
            }
            return $adquiridos;
        } catch (\Throwable $e) {
            self::liberarLocksFecha($db, $adquiridos);
            throw $e;
        }
    }

    private static function liberarLocksFecha(\mysqli $db, array $fechas): void
    {
        foreach (array_reverse($fechas) as $fecha) {
            FechaOperacionLock::liberar($db, (string)$fecha);
        }
    }

    /**
     * Comprueba dentro de la transacción que los siete upserts canónicos
     * quedaron exactamente como el payload validado. No se responde éxito con
     * una fila omitida o con valores anteriores.
     */
    private static function verificarHorarioCanonico(array $esperados): void
    {
        $persistidos = [];
        foreach (HorarioOperacion::todosOrdenados() as $horario) {
            $persistidos[(int)$horario->dia_semana] = $horario;
        }
        if (count($persistidos) !== 7) {
            throw new \RuntimeException('La semana canónica no contiene exactamente siete días.');
        }

        foreach ($esperados as $esperado) {
            $dia = (int)$esperado['dia_semana'];
            $persistido = $persistidos[$dia] ?? null;
            if (
                !$persistido
                || (int)$persistido->abierto !== (int)$esperado['abierto']
                || self::horaComparable($persistido->hora_apertura)
                    !== self::horaComparable($esperado['hora_apertura'])
                || self::horaComparable($persistido->hora_cierre)
                    !== self::horaComparable($esperado['hora_cierre'])
            ) {
                throw new \RuntimeException("El día {$dia} no coincide con el horario solicitado.");
            }
        }
    }

    private static function validarHorarioSemanal(array $horarios): array
    {
        $fieldCodes = [];
        $limpios = [];
        $diasRecibidos = [];

        if (count($horarios) !== 7) {
            $fieldCodes['horarios'][] = 'HORARIO_INVALIDO';
        }

        foreach ($horarios as $posicion => $datos) {
            if (!is_array($datos)) {
                $fieldCodes['horarios'][] = 'HORARIO_INVALIDO';
                continue;
            }

            $dia = filter_var($datos['dia_semana'] ?? null, FILTER_VALIDATE_INT);
            if ($dia === false || $dia < 0 || $dia > 6) {
                $fieldCodes['horarios'][] = 'HORARIO_INVALIDO';
                continue;
            }
            if (isset($diasRecibidos[$dia])) {
                $fieldCodes['horarios'][] = 'HORARIO_INVALIDO';
                continue;
            }
            $diasRecibidos[$dia] = true;

            $abierto = self::normalizarBooleano($datos['abierto'] ?? 0);
            $apertura = $abierto ? self::normalizarHora((string) ($datos['hora_apertura'] ?? '')) : null;
            $cierre = $abierto ? self::normalizarHora((string) ($datos['hora_cierre'] ?? '')) : null;
            if ($abierto && $apertura === null) {
                $fieldCodes["dia_{$dia}"][] = 'HORA_NO_VALIDA';
            }
            if ($abierto && $cierre === null) {
                $fieldCodes["dia_{$dia}"][] = 'HORA_NO_VALIDA';
            }
            if ($abierto && $apertura !== null && $cierre !== null && $apertura >= $cierre) {
                $fieldCodes["dia_{$dia}"][] = 'HORARIO_INVALIDO';
            }

            $limpios[$dia] = [
                'dia_semana' => $dia,
                'abierto' => $abierto ? 1 : 0,
                'hora_apertura' => $abierto ? $apertura : null,
                'hora_cierre' => $abierto ? $cierre : null,
            ];
        }

        if (count($diasRecibidos) !== 7) {
            $fieldCodes['horarios'][] = 'HORARIO_INVALIDO';
        }

        ksort($limpios);

        return [
            'ok' => $fieldCodes === [],
            'field_codes' => $fieldCodes,
            'datos' => array_values($limpios),
            'horarios' => self::construirSemanaFormulario($horarios),
        ];
    }

    private static function prepararDiasModificados(array $horarios, ?int $usuarioId): array
    {
        $persistidos = [];
        foreach (HorarioOperacion::todosOrdenados() as $horario) {
            $persistidos[(int) $horario->dia_semana] = $horario;
        }

        $modificados = [];
        foreach ($horarios as $datos) {
            $diaSemana = (int) $datos['dia_semana'];
            if (!self::horarioFueModificado($persistidos[$diaSemana] ?? null, $datos)) {
                continue;
            }

            $horario = new HorarioOperacion($datos);
            $horario->updated_by = $usuarioId;
            $intervalos = (int) $horario->abierto === 1
                ? HorarioReservacionService::generarIntervalos(
                    (string) $horario->hora_apertura,
                    (string) $horario->hora_cierre
                )
                : [];

            $modificados[$diaSemana] = [
                'horario' => $horario,
                'intervalos' => $intervalos,
            ];
        }

        return $modificados;
    }

    private static function horarioFueModificado(?HorarioOperacion $persistido, array $nuevo): bool
    {
        if (!$persistido) {
            return true;
        }

        if ((int) $persistido->abierto !== (int) $nuevo['abierto']) {
            return true;
        }

        return self::horaComparable($persistido->hora_apertura) !== self::horaComparable($nuevo['hora_apertura'])
            || self::horaComparable($persistido->hora_cierre) !== self::horaComparable($nuevo['hora_cierre']);
    }

    private static function horaComparable($hora): ?string
    {
        if ($hora === null || trim((string) $hora) === '') {
            return null;
        }

        $normalizada = HorarioReservacionService::normalizarHoraSql((string) $hora);
        return $normalizada !== '' ? $normalizada : null;
    }

    private static function detectarConflictosReservaciones(array $diasModificados): array
    {
        $reservaciones = Reservacion::buscarFuturasActivas(
            ReservacionConfig::fechaActual(),
            ReservacionConfig::horaActual()
        );
        $conflictos = [];

        foreach ($reservaciones as $reservacion) {
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $reservacion->fecha);
            if (!$fecha instanceof DateTimeImmutable) {
                continue;
            }

            $diaSemana = (int) $fecha->format('w');
            if (!isset($diasModificados[$diaSemana])) {
                continue;
            }

            $hora = HorarioReservacionService::normalizarHoraSql((string) $reservacion->hora);
            $intervalos = $diasModificados[$diaSemana]['intervalos'];
            if ($hora !== '' && in_array($hora, $intervalos, true)) {
                continue;
            }

            $conflictos[] = [
                'id' => (int) $reservacion->id,
                'nombre' => (string) $reservacion->nombre,
                'fecha' => (string) $reservacion->fecha,
                'hora' => $hora !== '' ? substr($hora, 0, 5) : (string) $reservacion->hora,
                'estado' => (string) $reservacion->estado,
            ];
        }

        return $conflictos;
    }

    private static function detectarConflictosExcepcion(
        string $fecha,
        string $tipo,
        ?string $horaApertura,
        ?string $horaCierre
    ): array {
        $conflictos = [];
        foreach (Reservacion::buscarActivasPorFecha($fecha) as $reservacion) {
            $hora = self::horaComparable($reservacion->hora);
            $compatible = $tipo === 'horario_especial'
                && $hora !== null
                && $horaApertura !== null
                && $horaCierre !== null
                && $hora >= $horaApertura
                && $hora < $horaCierre;

            if (!$compatible) {
                $conflictos[] = self::datosConflicto($reservacion, $hora);
            }
        }

        return $conflictos;
    }

    private static function detectarConflictosHorarioSemanal(string $fecha): array
    {
        $fechaObjeto = self::crearFecha($fecha);
        if (!$fechaObjeto) {
            throw new \DomainException('La fecha de la excepción no es válida.');
        }

        $horario = HorarioOperacion::buscarPorDia((int) $fechaObjeto->format('w'));
        $apertura = $horario && (int) $horario->abierto === 1
            ? self::horaComparable($horario->hora_apertura)
            : null;
        $cierre = $horario && (int) $horario->abierto === 1
            ? self::horaComparable($horario->hora_cierre)
            : null;
        $conflictos = [];

        foreach (Reservacion::buscarActivasPorFecha($fecha) as $reservacion) {
            $hora = self::horaComparable($reservacion->hora);
            $compatible = $hora !== null
                && $apertura !== null
                && $cierre !== null
                && $hora >= $apertura
                && $hora < $cierre;
            if (!$compatible) {
                $conflictos[] = self::datosConflicto($reservacion, $hora);
            }
        }

        return $conflictos;
    }

    private static function datosConflicto(Reservacion $reservacion, ?string $hora): array
    {
        return [
            'id' => (int) $reservacion->id,
            'nombre' => (string) $reservacion->nombre,
            'fecha' => (string) $reservacion->fecha,
            'hora' => $hora !== null ? substr($hora, 0, 5) : (string) $reservacion->hora,
            'estado' => (string) $reservacion->estado,
        ];
    }

    private static function resultadoConflictosExcepcion(
        array $conflictos,
        ?array $datos = null
    ): array {
        $resultado = [
            'ok' => false,
            'codigo' => 'RESERVACIONES_AFECTADAS',
            'conflictos' => $conflictos,
            'reservaciones_afectadas' => count($conflictos),
            'requiere_confirmacion' => true,
        ];
        if ($datos !== null) {
            $resultado['datos'] = $datos;
        }

        return $resultado;
    }

    private static function validarAplicacionHoy(ExcepcionOperacion $excepcion): ?string
    {
        if (
            (string) $excepcion->fecha === ReservacionConfig::fechaActual()
            && (string) $excepcion->tipo === 'horario_especial'
        ) {
            $cierre = self::horaComparable($excepcion->hora_cierre);
            if ($cierre === null || $cierre <= ReservacionConfig::horaActual()) {
                return 'EXCEPCION_HORARIO_PASADO';
            }
        }

        return null;
    }

    private static function construirSemanaFormulario(array $horarios): array
    {
        $recibidos = [];
        foreach ($horarios as $datos) {
            if (!is_array($datos)) {
                continue;
            }
            $dia = filter_var($datos['dia_semana'] ?? null, FILTER_VALIDATE_INT);
            if ($dia !== false && $dia >= 0 && $dia <= 6 && !isset($recibidos[$dia])) {
                $recibidos[$dia] = $datos;
            }
        }

        $semana = [];
        foreach (self::DIAS as $dia => $nombre) {
            $datos = $recibidos[$dia] ?? [];
            $abierto = self::normalizarBooleano($datos['abierto'] ?? 0);
            $semana[] = [
                'id' => null,
                'dia_semana' => $dia,
                'nombre' => $nombre,
                'abierto' => $abierto,
                'hora_apertura' => $abierto ? trim((string) ($datos['hora_apertura'] ?? '')) : '',
                'hora_cierre' => $abierto ? trim((string) ($datos['hora_cierre'] ?? '')) : '',
                'configurado' => false,
            ];
        }

        return $semana;
    }

    private static function validarExcepcion(array $datos): array
    {
        $fieldCodes = [];
        $id = null;

        if (isset($datos['id']) && $datos['id'] !== '') {
            $idValidado = filter_var($datos['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$idValidado) {
                $fieldCodes['id'][] = 'EXCEPCION_ID_INVALIDO';
            } else {
                $id = (int) $idValidado;
            }
        }

        $fecha = trim((string) ($datos['fecha'] ?? ''));
        $tipo = trim((string) ($datos['tipo'] ?? ''));
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        $activo = self::normalizarBooleano($datos['activo'] ?? 0) ? 1 : 0;

        $fechaObjeto = null;
        if ($fecha === '') {
            $fieldCodes['fecha'][] = 'EXCEPCION_FECHA_REQUERIDA';
        } elseif (!self::fechaValida($fecha)) {
            $fieldCodes['fecha'][] = 'EXCEPCION_FECHA_INVALIDA';
        } else {
            $fechaObjeto = self::crearFecha($fecha);
            $hoy = ReservacionConfig::ahora()->setTime(0, 0);
            if ($fechaObjeto < $hoy) {
                $fieldCodes['fecha'][] = 'EXCEPCION_FECHA_PASADA';
            }
        }
        if (!in_array($tipo, self::TIPOS_EXCEPCION, true)) {
            $fieldCodes['tipo'][] = 'EXCEPCION_TIPO_INVALIDO';
        }
        if (self::longitud($motivo) > 160) {
            $fieldCodes['motivo'][] = 'EXCEPCION_MOTIVO_DEMASIADO_LARGO';
        }

        $apertura = null;
        $cierre = null;
        if ($tipo === 'horario_especial') {
            $aperturaRecibida = trim((string) ($datos['hora_apertura'] ?? ''));
            $cierreRecibido = trim((string) ($datos['hora_cierre'] ?? ''));
            $apertura = self::normalizarHora($aperturaRecibida);
            $cierre = self::normalizarHora($cierreRecibido);

            if ($aperturaRecibida === '') {
                $fieldCodes['hora_apertura'][] = 'EXCEPCION_HORA_APERTURA_REQUERIDA';
            } elseif ($apertura === null) {
                $fieldCodes['hora_apertura'][] = 'EXCEPCION_HORA_APERTURA_INVALIDA';
            }
            if ($cierreRecibido === '') {
                $fieldCodes['hora_cierre'][] = 'EXCEPCION_HORA_CIERRE_REQUERIDA';
            } elseif ($cierre === null) {
                $fieldCodes['hora_cierre'][] = 'EXCEPCION_HORA_CIERRE_INVALIDA';
            }
            if ($apertura !== null && $cierre !== null && $apertura >= $cierre) {
                $fieldCodes['hora_cierre'][] = 'EXCEPCION_HORAS_INVALIDAS';
            }
            if (
                $fechaObjeto instanceof DateTimeImmutable
                && $fecha === ReservacionConfig::fechaActual()
                && $cierre !== null
                && $cierre <= ReservacionConfig::horaActual()
            ) {
                $fieldCodes['hora_cierre'][] = 'EXCEPCION_HORARIO_PASADO';
            }
        }

        $limpios = [
            'id' => $id,
            'fecha' => $fecha,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'hora_apertura' => $tipo === 'horario_especial' ? $apertura : null,
            'hora_cierre' => $tipo === 'horario_especial' ? $cierre : null,
            'activo' => $activo,
        ];

        return ['ok' => $fieldCodes === [], 'field_codes' => $fieldCodes, 'datos' => $limpios];
    }

    private static function errorExcepcion(string $codigo, array $datos): array
    {
        return ['ok' => false, 'codigo' => $codigo, 'datos' => $datos];
    }

    private static function fechaValida(string $fecha): bool
    {
        $objeto = self::crearFecha($fecha);
        $errores = DateTimeImmutable::getLastErrors();

        return $objeto instanceof DateTimeImmutable
            && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))
            && $objeto->format('Y-m-d') === $fecha;
    }

    private static function crearFecha(string $fecha): ?DateTimeImmutable
    {
        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());

        return $objeto instanceof DateTimeImmutable ? $objeto : null;
    }

    private static function normalizarHora(string $hora): ?string
    {
        $hora = trim($hora);
        foreach (['!H:i', '!H:i:s'] as $formato) {
            $objeto = DateTimeImmutable::createFromFormat($formato, $hora);
            $errores = DateTimeImmutable::getLastErrors();
            if (
                $objeto instanceof DateTimeImmutable
                && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))
                && $objeto->format(strlen($hora) === 5 ? 'H:i' : 'H:i:s') === $hora
            ) {
                return $objeto->format('H:i:s');
            }
        }

        return null;
    }

    private static function normalizarBooleano($valor): bool
    {
        return in_array($valor, [1, '1', true, 'true', 'on'], true);
    }

    private static function horaCorta(string $hora): string
    {
        $normalizada = self::normalizarHora($hora);
        return $normalizada !== null ? substr($normalizada, 0, 5) : '';
    }

    private static function horaSql(string $hora): ?string
    {
        return self::normalizarHora($hora);
    }

    private static function longitud(string $valor): int
    {
        return function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
    }
}
