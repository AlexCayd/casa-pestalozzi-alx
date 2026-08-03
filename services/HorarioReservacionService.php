<?php

/**
 * Valida fechas, horarios configurados y disponibilidad de reservacion.
 */

namespace Services;

use DateTimeImmutable;

class HorarioReservacionService
{
    public const FECHA_INVALIDA = 'FECHA_INVALIDA';
    public const FECHA_PASADA = 'FECHA_PASADA';
    public const HORARIO_INVALIDO = 'HORARIO_INVALIDO';
    public const HORARIO_PASADO = 'HORARIO_PASADO';
    public const DIA_INACTIVO = 'DIA_INACTIVO';
    public const HORARIO_DISPONIBLE = 'HORARIO_DISPONIBLE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';
    public const MOTIVO_FECHA_INVALIDA = 'fecha_invalida';
    public const MOTIVO_FECHA_PASADA = 'fecha_pasada';
    public const MOTIVO_FECHA_FUERA_DE_HORIZONTE = 'fecha_fuera_de_horizonte';
    public const MOTIVO_DIA_NO_OPERATIVO = 'dia_no_operativo';
    public const MOTIVO_HORARIO_SIN_CONFIGURACION = 'horario_sin_configuracion';
    public const MOTIVO_ANTICIPACION_INSUFICIENTE = 'anticipacion_insuficiente';
    public const MOTIVO_DESPUES_DE_ULTIMA_RESERVACION = 'despues_de_ultima_reservacion';
    public const MOTIVO_HORARIO_FUERA_DE_OPERACION = 'horario_fuera_de_operacion';

    /**
     * Genera la proyección reservable desde el horario operativo canónico.
     * La última reservación comienza, como máximo, una hora antes del cierre.
     */
    public static function generarIntervalos(string $horaApertura, string $horaCierre): array
    {
        $aperturaSql = self::normalizarHoraSql($horaApertura);
        $cierreSql = self::normalizarHoraSql($horaCierre);
        if ($aperturaSql === '' || $cierreSql === '') {
            throw new \InvalidArgumentException('Las horas de apertura y cierre deben tener un formato válido.');
        }

        $apertura = DateTimeImmutable::createFromFormat('!H:i:s', $aperturaSql);
        $cierre = DateTimeImmutable::createFromFormat('!H:i:s', $cierreSql);
        if (!$apertura instanceof DateTimeImmutable || !$cierre instanceof DateTimeImmutable || $apertura >= $cierre) {
            throw new \InvalidArgumentException('La hora de apertura debe ser anterior a la hora de cierre.');
        }

        $limiteUltimaReservacion = $cierre->modify(
            '-' . ReservacionConfig::MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION . ' minutes'
        );
        $intervalos = [];
        for ($actual = $apertura; $actual <= $limiteUltimaReservacion; $actual = $actual->modify('+' . ReservacionConfig::INTERVALO_RESERVACION_MINUTOS . ' minutes')) {
            $intervalos[$actual->format('H:i:s')] = true;
        }

        return array_keys($intervalos);
    }

    /**
     * Resuelve el calendario reservable para una fecha usando la prioridad
     * excepción activa -> horario semanal. La hora recibida permite congelar
     * el reloj en pruebas sin cambiar la hora del sistema operativo.
     *
     * @return array<string, mixed>
     */
    public static function resolverFecha(
        string $fecha,
        ?DateTimeImmutable $ahora = null,
        bool $permitirHistorica = false
    ): array {
        $fecha = trim($fecha);
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $base = [
            'fecha' => $fecha,
            'reservable' => false,
            'abierto' => false,
            'hora_apertura' => null,
            'hora_cierre' => null,
            'horarios_candidatos' => [],
            'horarios' => [],
            'motivo_no_disponible' => null,
            'codigo' => self::HORARIO_DISPONIBLE,
            'origen' => null,
            'tipo' => null,
            'detalle_horario' => null,
        ];

        if (!self::fechaValida($fecha)) {
            return array_merge($base, [
                'codigo' => self::FECHA_INVALIDA,
                'motivo_no_disponible' => self::MOTIVO_FECHA_INVALIDA,
            ]);
        }

        $hoy = $ahora->format('Y-m-d');
        if (!$permitirHistorica && $fecha < $hoy) {
            return array_merge($base, [
                'codigo' => self::FECHA_PASADA,
                'motivo_no_disponible' => self::MOTIVO_FECHA_PASADA,
            ]);
        }

        $horizonte = $ahora->modify('+' . ReservacionConfig::HORIZONTE_MAXIMO_DIAS . ' days')
            ->format('Y-m-d');
        if (!$permitirHistorica && $fecha > $horizonte) {
            return array_merge($base, [
                'codigo' => 'FECHA_FUERA_DE_HORIZONTE',
                'motivo_no_disponible' => self::MOTIVO_FECHA_FUERA_DE_HORIZONTE,
            ]);
        }

        try {
            $efectivo = HorarioOperacionService::obtenerHorarioEfectivo($fecha);
        } catch (\Throwable $e) {
            error_log('HorarioReservacionService::resolverFecha - ' . $e->getMessage());

            return array_merge($base, [
                'codigo' => self::ERROR_INTERNO,
                'motivo_no_disponible' => 'error_interno',
            ]);
        }

        $origen = (string)($efectivo['origen'] ?? 'semanal');
        $tipo = $efectivo['tipo'] ?? null;
        $base['origen'] = $origen;
        $base['tipo'] = $tipo;
        $base['detalle_horario'] = [
            'es_excepcion' => $origen === 'excepcion',
            'tipo' => $tipo,
            'motivo' => $efectivo['motivo'] ?? null,
        ];

        if (!($efectivo['abierto'] ?? false)) {
            $sinConfiguracion = $origen === 'semanal'
                && !($efectivo['configurado'] ?? true);

            return array_merge($base, [
                'codigo' => $sinConfiguracion ? 'HORARIO_SIN_CONFIGURACION' : self::DIA_INACTIVO,
                'motivo_no_disponible' => $sinConfiguracion
                    ? self::MOTIVO_HORARIO_SIN_CONFIGURACION
                    : self::MOTIVO_DIA_NO_OPERATIVO,
            ]);
        }

        $apertura = self::normalizarHoraSql((string)($efectivo['hora_apertura'] ?? ''));
        $cierre = self::normalizarHoraSql((string)($efectivo['hora_cierre'] ?? ''));
        if ($apertura === '' || $cierre === '') {
            return array_merge($base, [
                'codigo' => 'HORARIO_SIN_CONFIGURACION',
                'motivo_no_disponible' => self::MOTIVO_HORARIO_SIN_CONFIGURACION,
            ]);
        }

        try {
            $intervalos = self::generarIntervalos($apertura, $cierre);
        } catch (\InvalidArgumentException $e) {
            // TIME no puede representar el día siguiente de forma inequívoca
            // para reservaciones; una configuración invertida no es reservable.
            return array_merge($base, [
                'codigo' => 'HORARIO_SIN_CONFIGURACION',
                'motivo_no_disponible' => self::MOTIVO_HORARIO_SIN_CONFIGURACION,
            ]);
        }

        if (!$permitirHistorica && $fecha === $hoy) {
            $limite = $ahora->modify('+' . ReservacionConfig::ANTICIPACION_MINIMA_MINUTOS . ' minutes');
            $intervalos = array_values(array_filter(
                $intervalos,
                static function (string $hora) use ($fecha, $limite): bool {
                    $inicio = self::fechaHora($fecha, $hora);
                    return $inicio instanceof DateTimeImmutable && $inicio >= $limite;
                }
            ));
        }

        $candidatosCortos = array_map(
            static fn(string $hora): string => substr($hora, 0, 5),
            $intervalos
        );
        $base['abierto'] = true;
        $base['hora_apertura'] = substr($apertura, 0, 5);
        $base['hora_cierre'] = substr($cierre, 0, 5);
        $base['horarios_candidatos'] = $candidatosCortos;
        $base['horarios'] = $intervalos;
        $base['reservable'] = $intervalos !== [];

        if ($intervalos === []) {
            $base['codigo'] = $fecha === $hoy
                ? 'SIN_HORARIOS_FUTUROS'
                : self::DIA_INACTIVO;
            $base['motivo_no_disponible'] = $fecha === $hoy
                ? self::MOTIVO_ANTICIPACION_INSUFICIENTE
                : self::MOTIVO_DIA_NO_OPERATIVO;
        }

        return $base;
    }

    /** Alias explícito para consumidores del núcleo de dominio. */
    public static function calendarioParaFecha(
        string $fecha,
        ?DateTimeImmutable $ahora = null,
        bool $permitirHistorica = false
    ): array {
        return self::resolverFecha($fecha, $ahora, $permitirHistorica);
    }

    /**
     * Valida que una hora sea uno de los candidatos de la fecha.
     *
     * @return array<string, mixed>
     */
    public static function validarHora(
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null,
        bool $permitirHistorica = false
    ): array {
        $horaSql = self::normalizarHoraSql($hora);
        $calendario = self::resolverFecha($fecha, $ahora, $permitirHistorica);
        $resultado = $calendario + [
            'hora' => $horaSql,
            'hora_corta' => $horaSql !== '' ? substr($horaSql, 0, 5) : '',
        ];

        if (!($calendario['reservable'] ?? false)) {
            return ['ok' => false] + $resultado;
        }
        if ($horaSql === '') {
            return [
                'ok' => false,
                'codigo' => self::HORARIO_INVALIDO,
                'motivo_no_disponible' => self::MOTIVO_HORARIO_FUERA_DE_OPERACION,
            ] + $resultado;
        }
        if (!in_array($horaSql, $calendario['horarios'], true)) {
            $inicio = self::fechaHora($fecha, $horaSql);
            $apertura = self::fechaHora($fecha, (string)$calendario['hora_apertura']);
            $cierre = self::fechaHora($fecha, (string)$calendario['hora_cierre']);
            $reloj = $ahora ?? ReservacionConfig::ahora();
            $motivo = self::MOTIVO_HORARIO_FUERA_DE_OPERACION;
            $codigo = self::HORARIO_INVALIDO;
            if ($fecha === $reloj->format('Y-m-d')
                && $inicio instanceof DateTimeImmutable
                && $inicio < $reloj->modify('+' . ReservacionConfig::ANTICIPACION_MINIMA_MINUTOS . ' minutes')
            ) {
                $motivo = self::MOTIVO_ANTICIPACION_INSUFICIENTE;
                $codigo = self::HORARIO_PASADO;
            } elseif ($inicio instanceof DateTimeImmutable && $cierre instanceof DateTimeImmutable
                && $inicio >= $cierre->modify('-' . ReservacionConfig::MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION . ' minutes')
                && $inicio < $cierre
            ) {
                $motivo = self::MOTIVO_DESPUES_DE_ULTIMA_RESERVACION;
                $codigo = 'DESPUES_DE_ULTIMA_RESERVACION';
            }

            $siguiente = null;
            foreach ($calendario['horarios'] as $candidato) {
                if ($candidato > $horaSql) {
                    $siguiente = substr($candidato, 0, 5);
                    break;
                }
            }

            return [
                'ok' => false,
                'codigo' => $codigo,
                'motivo_no_disponible' => $motivo,
                'siguiente_horario_valido' => $siguiente,
            ] + $resultado;
        }

        return ['ok' => true, 'codigo' => self::HORARIO_DISPONIBLE] + $resultado;
    }

    /** Devuelve el intervalo canónico [inicio, fin). */
    public static function intervalo(
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): ?array {
        $validacion = self::validarHora($fecha, $hora, $ahora);
        if (!($validacion['ok'] ?? false)) {
            return null;
        }

        $inicio = self::fechaHora($fecha, (string)$validacion['hora']);
        if (!$inicio) {
            return null;
        }

        $fin = $inicio->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes');

        return [
            'inicio' => $inicio,
            'fin' => $fin,
            'inicio_sql' => $inicio->format('Y-m-d H:i:s'),
            'fin_sql' => $fin->format('Y-m-d H:i:s'),
        ];
    }

    public static function filtrarHorariosPasados(
        string $fecha,
        array $horarios,
        ?DateTimeImmutable $ahora = null
    ): array
    {
        $normalizados = [];

        foreach ($horarios as $horario) {
            $valor = is_object($horario) ? (string) ($horario->hora ?? '') : (string) $horario;
            $horaSql = self::normalizarHoraSql($valor);

            if ($horaSql === '' || self::horarioPasadoHoy($fecha, $horaSql, $ahora)) {
                continue;
            }

            $normalizados[$horaSql] = true;
        }

        $resultado = array_keys($normalizados);
        sort($resultado, SORT_STRING);

        return $resultado;
    }

    public static function fechaSeguraGet(string $fecha): string
    {
        return self::fechaValida($fecha) ? $fecha : self::hoy();
    }

    public static function fechaValida(string $fecha): bool
    {
        if ($fecha === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        $errors = DateTimeImmutable::getLastErrors();
        $sinErrores = $errors === false
            || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0);

        return $date instanceof DateTimeImmutable
            && $sinErrores
            && $date->format('Y-m-d') === $fecha;
    }

    public static function normalizarHoraSql(string $hora): string
    {
        $hora = trim($hora);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $hora, $matches) !== 1) {
            return '';
        }

        return $matches[1] . ':' . $matches[2] . ':' . ($matches[3] ?? '00');
    }

    public static function normalizarHoraCorta(string $hora): string
    {
        $horaSql = self::normalizarHoraSql($hora);

        return $horaSql !== '' ? substr($horaSql, 0, 5) : '';
    }

    public static function horaPorDefecto(array $horarios, string $fecha): string
    {
        $horasDisponibles = array_values(array_filter(array_map(static function ($horario): string {
            $valor = is_object($horario) ? (string)($horario->hora ?? '') : (string)$horario;
            return self::normalizarHoraCorta($valor);
        }, $horarios)));

        if (empty($horasDisponibles)) {
            return '';
        }

        if ($fecha === self::hoy()) {
            $horaActual = ReservacionConfig::ahora()->format('H:i');

            foreach ($horasDisponibles as $hora) {
                if ($hora >= $horaActual) {
                    return $hora;
                }
            }
        }

        return $horasDisponibles[0];
    }

    /**
     * Resuelve el horario que puede mostrarse como contexto operativo.
     * Los horarios recibidos ya deben provenir de la disponibilidad canónica
     * del servidor; nunca se completa la lista con valores del navegador.
     *
     * @return array{
     *   hora_solicitada: string,
     *   hora_resuelta: string,
     *   ajustada: bool,
     *   solicitada_vencida: bool,
     *   sin_horarios_futuros: bool
     * }
     */
    public static function resolverHorarioOperativo(
        string $fecha,
        string $horaSolicitada,
        array $horarios,
        bool $modoHistorico = false
    ): array {
        $horariosNormalizados = [];
        foreach ($horarios as $horario) {
            $valor = is_object($horario) ? (string)($horario->hora ?? '') : (string)$horario;
            $hora = self::normalizarHoraCorta($valor);
            if ($hora !== '') {
                $horariosNormalizados[$hora] = true;
            }
        }
        $horariosNormalizados = array_keys($horariosNormalizados);
        sort($horariosNormalizados, SORT_STRING);

        $solicitada = self::normalizarHoraCorta($horaSolicitada);
        $solicitadaVencida = !$modoHistorico
            && $solicitada !== ''
            && self::horarioPasadoHoy($fecha, self::normalizarHoraSql($solicitada));
        $resuelta = $solicitada !== '' && in_array($solicitada, $horariosNormalizados, true)
            ? $solicitada
            : self::horaPorDefecto($horariosNormalizados, $fecha);

        return [
            'hora_solicitada' => $solicitada,
            'hora_resuelta' => $resuelta,
            'ajustada' => $solicitada !== '' && $resuelta !== $solicitada,
            'solicitada_vencida' => $solicitadaVencida,
            'sin_horarios_futuros' => !$modoHistorico
                && $fecha === self::hoy()
                && $horariosNormalizados === [],
        ];
    }

    public static function hoy(): string
    {
        return ReservacionConfig::fechaActual();
    }

    public static function fechaPasada(string $fecha): bool
    {
        return self::fechaValida($fecha) && $fecha < self::hoy();
    }

    public static function horarioPasadoHoy(
        string $fecha,
        string $horaSql,
        ?DateTimeImmutable $ahora = null
    ): bool
    {
        if ($fecha !== self::hoy() || $horaSql === '') {
            return false;
        }

        $horario = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $horaSql,
            ReservacionConfig::timezone()
        );

        if (!$horario instanceof DateTimeImmutable) {
            return true;
        }

        return $horario <= ($ahora ?? ReservacionConfig::ahora());
    }

    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = self::normalizarHoraSql($hora);
        if (!self::fechaValida($fecha) || $hora === '') {
            return null;
        }

        $resultado = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $hora,
            ReservacionConfig::timezone()
        );
        $errores = DateTimeImmutable::getLastErrors();

        return $resultado instanceof DateTimeImmutable
            && ($errores === false || (($errores['warning_count'] ?? 0) === 0 && ($errores['error_count'] ?? 0) === 0))
            ? $resultado
            : null;
    }

}
