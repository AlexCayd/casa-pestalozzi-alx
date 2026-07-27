<?php

/**
 * Coordina la asignacion manual y automatica de mesas.
 * Protege las operaciones con transacciones y bloqueos de registros.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Mesa;
use Model\ReservacionMesa;

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
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    private const TIPO_AUTOMATICA_GENERAL = 'general';
    private const TIPO_AUTOMATICA_PUBLICA = 'publica';

    public static function asignarManual(
        int $reservacionId,
        array $mesaIds,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true
    ): array
    {
        $mesaIds = self::normalizarMesaIds($mesaIds);

        if (empty($mesaIds)) {
            return ['ok' => false, 'codigo' => self::ASIGNACION_VACIA];
        }

        return self::asignar($reservacionId, $mesaIds, 'manual', $permitirCapacidadInsuficiente, $gestionarTransaccion);
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
        bool $bloquear = false
    ): array {
        $asignaciones = ReservacionMesa::obtenerOcupacionDelDia($fecha, $excluirReservacionId, $bloquear);

        return self::ocupacionEnVentana($asignaciones, $hora, $excluirReservacionId);
    }

    public static function obtenerOcupacionPorReservacionDelDia(string $fecha, array $reservaciones): array
    {
        $asignaciones = ReservacionMesa::obtenerOcupacionDelDia($fecha);
        $ocupacion = [];

        foreach ($reservaciones as $reservacion) {
            $reservacionId = (int)($reservacion->id ?? 0);
            $ocupacion[$reservacionId] = self::ocupacionEnVentana(
                $asignaciones,
                (string)($reservacion->hora ?? ''),
                $reservacionId
            );
        }

        return $ocupacion;
    }

    public static function seleccionarMesasGeneral(array $mesasDisponibles, int $comensales): array
    {
        $comensales = max(1, $comensales);

        if (empty($mesasDisponibles)) {
            return [];
        }

        $candidatas = [];

        foreach ($mesasDisponibles as $mesa) {
            if ((int)$mesa->capacidad >= $comensales) {
                $candidatas[] = [$mesa];
            }
        }

        $seleccion = [];
        $capacidad = 0;

        foreach ($mesasDisponibles as $mesa) {
            $seleccion[] = $mesa;
            $capacidad += (int)$mesa->capacidad;

            if ($capacidad >= $comensales) {
                $candidatas[] = $seleccion;
                break;
            }
        }

        if (empty($candidatas)) {
            return [];
        }

        usort($candidatas, static function (array $a, array $b) use ($comensales): int {
            $capacidadA = self::capacidadSeleccion($a);
            $capacidadB = self::capacidadSeleccion($b);

            return (count($a) <=> count($b))
                ?: (($capacidadA - $comensales) <=> ($capacidadB - $comensales))
                ?: ($capacidadA <=> $capacidadB)
                ?: (self::numerosSeleccion($a) <=> self::numerosSeleccion($b));
        });

        return $candidatas[0];
    }

    public static function seleccionarMesasPublicas(array $mesasDisponibles, int $comensales): array
    {
        $comensales = max(1, min(ReservacionConfig::MAX_COMENSALES_PUBLICO, $comensales));
        $candidatas = [];

        foreach ($mesasDisponibles as $mesa) {
            if ((int)$mesa->capacidad >= $comensales) {
                $candidatas[] = [$mesa];
            }
        }

        $porNumero = [];
        foreach ($mesasDisponibles as $mesa) {
            $porNumero[(int)$mesa->numero] = $mesa;
        }

        foreach (ReservacionConfig::COMBINACIONES_PUBLICAS_AUTORIZADAS as $numeros) {
            $seleccion = [];

            foreach ($numeros as $numero) {
                if (!isset($porNumero[$numero])) {
                    $seleccion = [];
                    break;
                }

                $seleccion[] = $porNumero[$numero];
            }

            if (!empty($seleccion) && self::capacidadSeleccion($seleccion) >= $comensales) {
                $candidatas[] = $seleccion;
            }
        }

        if (empty($candidatas)) {
            return [];
        }

        usort($candidatas, static function (array $a, array $b) use ($comensales): int {
            $capacidadA = self::capacidadSeleccion($a);
            $capacidadB = self::capacidadSeleccion($b);

            return (count($a) <=> count($b))
                ?: (($capacidadA - $comensales) <=> ($capacidadB - $comensales))
                ?: ($capacidadA <=> $capacidadB)
                ?: (self::numerosSeleccion($a) <=> self::numerosSeleccion($b));
        });

        return $candidatas[0];
    }

    public static function validarCapacidad(array $mesas, array $mesaIds, int $comensales): bool
    {
        return self::capacidadTotal($mesas, $mesaIds) >= $comensales;
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

    private static function asignar(
        int $reservacionId,
        array $mesaIds,
        string $tipoAsignacion,
        bool $permitirCapacidadInsuficiente = false,
        bool $gestionarTransaccion = true
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
                "SELECT id, fecha, hora, comensales, estado
                 FROM reservaciones
                 WHERE id = {$reservacionId}
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$reservacion) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
            }

            $codigoNoEditable = ReservacionService::codigoNoEditable($reservacion);
            if ($codigoNoEditable !== '') {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return [
                    'ok' => false,
                    'codigo' => in_array($codigoNoEditable, [
                        ReservacionService::RESERVACION_PASADA,
                        ReservacionService::RESERVACION_HORARIO_PASADO,
                    ], true) ? $codigoNoEditable : self::ESTADO_INVALIDO,
                ];
            }

            $mesas = $automatico
                ? Mesa::reservablesParaActualizar()
                : Mesa::reservablesParaActualizar($mesaIds);

            if (!$automatico && count($mesas) !== count($mesaIds)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::MESAS_INVALIDAS];
            }

            $ocupacion = self::obtenerOcupacionParaHorario(
                (string)$reservacion['fecha'],
                (string)$reservacion['hora'],
                $reservacionId,
                true
            );

            if ($automatico) {
                $disponibles = array_values(array_filter($mesas, static function ($mesa) use ($ocupacion): bool {
                    return empty($ocupacion[(int)$mesa->id]);
                }));

                usort($disponibles, static function ($a, $b): int {
                    return ((int)$a->numero <=> (int)$b->numero) ?: ((int)$a->id <=> (int)$b->id);
                });

                $seleccionadas = $tipoAsignacion === self::TIPO_AUTOMATICA_PUBLICA
                    ? self::seleccionarMesasPublicas($disponibles, (int)$reservacion['comensales'])
                    : self::seleccionarMesasGeneral($disponibles, (int)$reservacion['comensales']);

                if (empty($seleccionadas)) {
                    self::rollbackSiPropia($db, $gestionarTransaccion);
                    return ['ok' => false, 'codigo' => self::SIN_CAPACIDAD];
                }

                $mesaIds = array_map(static function ($mesa): int {
                    return (int)$mesa->id;
                }, $seleccionadas);
                $mesas = $seleccionadas;
            } elseif (self::hayConflictoHorario($ocupacion, $mesaIds)) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::MESA_OCUPADA];
            }

            if (!self::validarCapacidad($mesas, $mesaIds, (int)$reservacion['comensales']) && !$permitirCapacidadInsuficiente) {
                self::rollbackSiPropia($db, $gestionarTransaccion);
                return ['ok' => false, 'codigo' => self::CAPACIDAD_INSUFICIENTE];
            }

            ReservacionMesa::reemplazarAsignacion($reservacionId, $mesaIds);
            if ($gestionarTransaccion && !$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la transaccion de asignacion.');
            }

            return ['ok' => true, 'codigo' => self::ASIGNACION_GUARDADA, 'mesa_ids' => $mesaIds];
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
     * La ventana es simetrica: dos reservaciones chocan si sus rangos
     * [hora - bloqueo previo, hora + duracion) se traslapan.
     */
    private static function hayTraslapeHorario(string $horaA, string $horaB): bool
    {
        $a = self::minutosDesdeHora($horaA);
        $b = self::minutosDesdeHora($horaB);
        $inicioA = $a - ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO;
        $finA = $a + ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        $inicioB = $b - ReservacionConfig::MINUTOS_PREVIOS_BLOQUEO;
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
