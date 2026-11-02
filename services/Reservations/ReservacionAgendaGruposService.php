<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;
use Services\Scheduling\HorarioOperacionService;

/**
 * Agenda de grupos grandes: el exterior visto por días, hacia adelante.
 *
 * El bloque exterior (Mesas 8–11) es el único sitio donde cabe un grupo de 9 o
 * más, así que planear grupos es planear ese bloque. Por día devuelve:
 *
 *   - la franja del bloque cada 30 minutos dentro del horario: libre (las
 *     cuatro mesas sin reserva), parcial (alguna ocupada, que el motor podría
 *     reubicar) o tomada (las cuatro ocupadas);
 *   - las reservaciones que usan el exterior o que son de 9 o más, con sus
 *     enlaces al POS y al detalle.
 *
 * Mira las reservas, no los tickets: es planeación de días por venir. Sólo
 * lectura.
 */
final class ReservacionAgendaGruposService
{
    public const FRANJA_MINUTOS = 30;
    public const LIBRE = 'libre';
    public const PARCIAL = 'parcial';
    public const TOMADA = 'tomada';

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Rango máximo: más días dejan de ser una agenda y pasan a ser un listado. */
    public const MAX_DIAS = 62;

    /** @return array{dias: array<int, array<string, mixed>>, bloque: array<int, array<string, mixed>>, resumen: array<string, int>} */
    public static function rango(string $desde, string $hasta): array
    {
        $vacio = ['dias' => [], 'bloque' => [], 'resumen' => ['grupos' => 0, 'dias_con_grupo' => 0, 'personas' => 0]];
        if (!HorarioReservacionService::fechaValida($desde) || !HorarioReservacionService::fechaValida($hasta) || $desde > $hasta) {
            return $vacio;
        }
        $inicio = new DateTimeImmutable($desde, ReservacionConfig::timezone());
        $fin = new DateTimeImmutable($hasta, ReservacionConfig::timezone());
        if ((int)$inicio->diff($fin)->days >= self::MAX_DIAS) {
            $fin = $inicio->modify('+' . (self::MAX_DIAS - 1) . ' days');
        }

        $bloque = array_values(array_filter(
            Mesa::buscarTodasParaMapa(),
            static fn($mesa): bool => (string)$mesa->tipo === 'mesa'
                && (string)($mesa->zona ?? '') === ReservacionConfig::ZONA_EXTERIOR
                && (int)$mesa->activo === 1
        ));
        $idsBloque = array_map(static fn($mesa): int => (int)$mesa->id, $bloque);
        $porFecha = self::reservacionesPorFecha($inicio->format('Y-m-d'), $fin->format('Y-m-d'), $idsBloque);

        $dias = [];
        $resumen = ['grupos' => 0, 'dias_con_grupo' => 0, 'personas' => 0];
        for ($dia = $inicio; $dia <= $fin; $dia = $dia->modify('+1 day')) {
            $fecha = $dia->format('Y-m-d');
            $horario = HorarioOperacionService::obtenerHorarioEfectivo($fecha);
            $reservas = $porFecha[$fecha] ?? [];
            $grupos = array_values(array_filter(
                $reservas,
                static fn(array $reserva): bool => $reserva['grupo_grande'] || $reserva['usa_exterior']
            ));
            $deGrupo = array_filter($grupos, static fn(array $reserva): bool => $reserva['grupo_grande']);
            if ($deGrupo !== []) {
                $resumen['dias_con_grupo']++;
                $resumen['grupos'] += count($deGrupo);
                $resumen['personas'] += array_sum(array_column($deGrupo, 'comensales'));
            }
            $dias[] = [
                'fecha' => $fecha,
                'etiqueta' => self::DIAS[(int)$dia->format('w')] . ' ' . (int)$dia->format('j') . ' de ' . self::MESES[(int)$dia->format('n')],
                'abierto' => !empty($horario['abierto']),
                'hora_apertura' => $horario['hora_apertura'] ?? null,
                'hora_cierre' => $horario['hora_cierre'] ?? null,
                'franjas' => !empty($horario['abierto'])
                    ? self::franjas($fecha, (string)$horario['hora_apertura'], (string)$horario['hora_cierre'], $reservas, $idsBloque)
                    : [],
                'grupos' => $grupos,
            ];
        }

        return [
            'dias' => $dias,
            'bloque' => array_map(static fn($mesa): array => ['id' => (int)$mesa->id, 'nombre' => (string)$mesa->nombre], $bloque),
            'resumen' => $resumen,
        ];
    }

    /**
     * Estado del bloque en cada franja de 30 minutos donde todavía cabe una
     * reserva entera (90 minutos) antes del cierre.
     *
     * @param array<int, array<string, mixed>> $reservas
     * @param array<int, int> $idsBloque
     * @return array<int, array<string, mixed>>
     */
    private static function franjas(string $fecha, string $apertura, string $cierre, array $reservas, array $idsBloque): array
    {
        $zona = ReservacionConfig::timezone();
        try {
            $desde = new DateTimeImmutable($fecha . ' ' . $apertura, $zona);
            $hasta = new DateTimeImmutable($fecha . ' ' . $cierre, $zona);
        } catch (\Throwable) {
            return [];
        }
        if ($hasta <= $desde) {
            $hasta = $hasta->modify('+1 day');
        }
        $duracion = ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        $ultimaEntrada = $hasta->modify('-' . $duracion . ' minutes');
        $total = count($idsBloque);
        $franjas = [];
        for ($t = $desde; $t <= $ultimaEntrada; $t = $t->modify('+' . self::FRANJA_MINUTOS . ' minutes')) {
            $finFranja = $t->modify('+' . $duracion . ' minutes');
            $ocupadas = [];
            $quienes = [];
            foreach ($reservas as $reserva) {
                if (!$reserva['usa_exterior']) {
                    continue;
                }
                $inicioReserva = new DateTimeImmutable($fecha . ' ' . $reserva['hora'] . ':00', $zona);
                $finReserva = $inicioReserva->modify('+' . $duracion . ' minutes');
                // Intervalos semiabiertos: tocarse en el borde no es solaparse.
                if ($inicioReserva < $finFranja && $t < $finReserva) {
                    foreach ($reserva['mesa_ids'] as $mesaId) {
                        if (in_array($mesaId, $idsBloque, true)) {
                            $ocupadas[$mesaId] = true;
                        }
                    }
                    $quienes[] = $reserva['id'];
                }
            }
            $n = count($ocupadas);
            $franjas[] = [
                'hora' => $t->format('H:i'),
                'estado' => $n === 0 ? self::LIBRE : ($total > 0 && $n >= $total ? self::TOMADA : self::PARCIAL),
                'mesas_ocupadas' => $n,
                'mesas_total' => $total,
                'reservacion_ids' => array_values(array_unique($quienes)),
            ];
        }

        return $franjas;
    }

    /**
     * Reservas vivas del rango, con sus mesas: confirmadas y holds vigentes,
     * que también apartan el bloque.
     *
     * @param array<int, int> $idsBloque
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function reservacionesPorFecha(string $desde, string $hasta, array $idsBloque): array
    {
        $db = ActiveRecord::getDB();
        $stmt = $db->prepare(
            "SELECT r.id, r.nombre, r.fecha, r.hora, r.comensales, r.estado,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas
               FROM reservaciones r
               LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
               LEFT JOIN mesas m ON m.id = rm.mesa_id
              WHERE r.fecha BETWEEN ? AND ?" . \Model\Reservacion::filtroUnidad('r') . "
                AND (r.estado = 'confirmada'
                     OR (r.estado = 'pendiente_verificacion' AND r.hold_expires_at > NOW()))
              GROUP BY r.id, r.nombre, r.fecha, r.hora, r.comensales, r.estado
              ORDER BY r.fecha ASC, r.hora ASC, r.id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }
        $stmt->bind_param('ss', $desde, $hasta);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $porFecha = [];
        foreach ($filas as $fila) {
            $mesaIds = array_values(array_filter(array_map('intval', explode(',', (string)$fila['mesa_ids']))));
            $id = (int)$fila['id'];
            $hora = substr((string)$fila['hora'], 0, 5);
            $comensales = (int)$fila['comensales'];
            $porFecha[(string)$fila['fecha']][] = [
                'id' => $id,
                'nombre' => (string)$fila['nombre'],
                'hora' => $hora,
                'comensales' => $comensales,
                'estado' => (string)$fila['estado'],
                'mesa_ids' => $mesaIds,
                'mesas' => (string)$fila['mesas'],
                'sin_mesa' => $mesaIds === [],
                'grupo_grande' => $comensales >= ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR,
                'mayor_al_maximo' => $comensales > ReservacionConfig::umbralGrupoGrande(),
                'usa_exterior' => array_intersect($mesaIds, $idsBloque) !== [],
                'url_detalle' => '/admin/reservaciones/detalle?id=' . $id,
                'url_pos' => '/punto-de-venta?' . http_build_query([
                    'fecha' => (string)$fila['fecha'],
                    'hora' => $hora,
                    'reservation_id' => $id,
                ]),
            ];
        }

        return $porFecha;
    }
}
