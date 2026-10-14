<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\TicketMesa;

/**
 * «Casos»: las reservaciones de un periodo que piden que alguien haga algo.
 *
 * No es un listado más de reservaciones: sólo aparece la que tiene un caso, y
 * el caso dice qué hacer. Una tarjeta por reservación —aunque tenga varios
 * casos— porque quien la abre la resuelve entera, no por partes; los casos
 * van ordenados del más urgente al menos.
 *
 *   ausencia_pendiente  hoy, venció la tolerancia y nadie registró si llegó.
 *   sin_mesa            confirmada sin mesas (siempre, en un grupo grande).
 *   horario_afectado    su horario cambió y el seguimiento sigue abierto.
 *   reubicada           la movieron sola para dar el exterior a un grupo.
 *   sin_contacto        la dio de alta el personal sin contacto: no recibe
 *                       recordatorio ni puede autogestionarse.
 *
 * Sólo lectura. Las acciones se resuelven con los endpoints de siempre.
 */
final class ReservacionCasosAccionablesService
{
    public const TIPO_AUSENCIA = 'ausencia_pendiente';
    public const TIPO_SIN_MESA = 'sin_mesa';
    public const TIPO_HORARIO = 'horario_afectado';
    public const TIPO_REUBICADA = 'reubicada';
    public const TIPO_SIN_CONTACTO = 'sin_contacto';

    public const TIPOS = [
        self::TIPO_AUSENCIA,
        self::TIPO_SIN_MESA,
        self::TIPO_HORARIO,
        self::TIPO_REUBICADA,
        self::TIPO_SIN_CONTACTO,
    ];

    /** Menor es más urgente. */
    private const SEVERIDAD = [
        self::TIPO_AUSENCIA => 10,
        self::TIPO_SIN_MESA => 20,
        self::TIPO_HORARIO => 30,
        self::TIPO_REUBICADA => 50,
        self::TIPO_SIN_CONTACTO => 60,
    ];

    /**
     * @return array{casos: array<int, array<string, mixed>>, total: int, por_tipo: array<string, int>, pagina: int, paginas: int}
     */
    public static function listar(
        string $desde,
        string $hasta,
        ?string $tipo = null,
        int $pagina = 1,
        int $porPagina = 20,
        ?DateTimeImmutable $ahora = null
    ): array {
        $vacio = ['casos' => [], 'total' => 0, 'por_tipo' => array_fill_keys(self::TIPOS, 0), 'pagina' => 1, 'paginas' => 1];
        if (!HorarioReservacionService::fechaValida($desde) || !HorarioReservacionService::fechaValida($hasta) || $desde > $hasta) {
            return $vacio;
        }
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $tipo = in_array($tipo, self::TIPOS, true) ? $tipo : null;

        $tarjetas = [];
        foreach (self::filas($desde, $hasta) as $fila) {
            $casos = self::casosDe($fila, $ahora);
            if ($casos === []) {
                continue;
            }
            usort($casos, static fn(array $a, array $b): int => $a['severidad'] <=> $b['severidad']);
            $tarjetas[] = [
                'reservacion' => self::reservacion($fila),
                'casos' => $casos,
                'severidad' => $casos[0]['severidad'],
            ];
        }

        $porTipo = array_fill_keys(self::TIPOS, 0);
        foreach ($tarjetas as $tarjeta) {
            foreach ($tarjeta['casos'] as $caso) {
                $porTipo[$caso['tipo']]++;
            }
        }
        if ($tipo !== null) {
            $tarjetas = array_values(array_filter(
                $tarjetas,
                static fn(array $tarjeta): bool => in_array($tipo, array_column($tarjeta['casos'], 'tipo'), true)
            ));
        }
        // Lo urgente primero; a igual urgencia, lo que ocurre antes.
        usort($tarjetas, static fn(array $a, array $b): int =>
            [$a['severidad'], $a['reservacion']['fecha'], $a['reservacion']['hora'], $a['reservacion']['id']]
                <=> [$b['severidad'], $b['reservacion']['fecha'], $b['reservacion']['hora'], $b['reservacion']['id']]);

        $total = count($tarjetas);
        $porPagina = max(1, $porPagina);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);

        return [
            'casos' => array_slice($tarjetas, ($pagina - 1) * $porPagina, $porPagina),
            'total' => $total,
            'por_tipo' => $porTipo,
            'pagina' => $pagina,
            'paginas' => $paginas,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function casosDe(array $fila, DateTimeImmutable $ahora): array
    {
        // Con la mesa ya servida no hay nada que resolver desde aquí.
        if (!empty($fila['ticket_abierto'])) {
            return [];
        }
        $casos = [];
        $comensales = (int)$fila['comensales'];
        $grupoGrande = $comensales > ReservacionConfig::umbralGrupoGrande();

        if ($fila['fecha'] === $ahora->format('Y-m-d')) {
            $vigencia = ReservacionVigenciaService::clasificar($fila, $ahora, null);
            if (!empty($vigencia['ausencia_pendiente']) && !empty($vigencia['puede_marcar_no_show'])) {
                // «La mesa sigue apartada» sólo si tiene mesa: en la misma
                // tarjeta salía junto a «Sin mesas» y se contradecían.
                $casos[] = self::caso(self::TIPO_AUSENCIA, 'No llegó', 'Venció la tolerancia y nadie registró si el cliente llegó.'
                    . ((int)$fila['mesas_count'] > 0 ? ' La mesa sigue apartada.' : ''));
            }
        }
        if ((int)$fila['mesas_count'] === 0) {
            $casos[] = self::caso(self::TIPO_SIN_MESA, 'Sin mesas', $grupoGrande
                ? 'Grupo grande: sus mesas se eligen a mano.'
                : 'Confirmada y sin mesas asignadas.');
        }
        if (!empty($fila['horario_pendiente'])) {
            $casos[] = self::caso(self::TIPO_HORARIO, 'Cambio de horario', 'Su horario cambió y el seguimiento sigue abierto.');
        }
        if (!empty($fila['reubicacion_id'])) {
            $casos[] = self::caso(self::TIPO_REUBICADA, 'Reubicada', 'Se movió sola para dar el exterior a un grupo grande. Revísala antes de sentar al cliente.')
                + ['reubicacion_id' => (int)$fila['reubicacion_id']];
        }
        $sinContacto = !in_array((string)$fila['contacto_tipo'], ['email', 'telefono'], true)
            || trim((string)($fila['contacto'] ?? '')) === '';
        if ($sinContacto) {
            $casos[] = self::caso(self::TIPO_SIN_CONTACTO, 'Sin contacto', 'No recibe recordatorio ni puede cambiar o cancelar su reservación por su cuenta.');
        }

        return $casos;
    }

    /** @return array<string, mixed> */
    private static function caso(string $tipo, string $etiqueta, string $descripcion): array
    {
        return [
            'tipo' => $tipo,
            'etiqueta' => $etiqueta,
            'descripcion' => $descripcion,
            'severidad' => self::SEVERIDAD[$tipo],
        ];
    }

    /** @return array<string, mixed> */
    private static function reservacion(array $fila): array
    {
        $id = (int)$fila['id'];
        $fecha = (string)$fila['fecha'];
        $hora = substr((string)$fila['hora'], 0, 5);

        return [
            'id' => $id,
            'nombre' => (string)$fila['nombre'],
            'fecha' => $fecha,
            'hora' => $hora,
            'comensales' => (int)$fila['comensales'],
            'mesas' => (string)($fila['mesas'] ?? ''),
            'grupo_grande' => (int)$fila['comensales'] > ReservacionConfig::umbralGrupoGrande(),
            'url_detalle' => '/admin/reservaciones/detalle?id=' . $id,
            // El POS abre la proyección de esa hora con la ficha de la reserva.
            'url_pos' => '/punto-de-venta?' . http_build_query([
                'fecha' => $fecha,
                'hora' => $hora,
                'reservation_id' => $id,
            ]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function filas(string $desde, string $hasta): array
    {
        $db = ActiveRecord::getDB();
        $condicionTicket = TicketMesa::condicionSqlAbierto('t');
        $stmt = $db->prepare(
            "SELECT r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora, r.comensales,
                    r.estado, r.hold_expires_at, r.origen, r.created_at, r.updated_at,
                    COUNT(rm.id) AS mesas_count,
                    COALESCE(GROUP_CONCAT(m.nombre ORDER BY rm.orden SEPARATOR ', '), '') AS mesas,
                    EXISTS (SELECT 1 FROM tickets t WHERE t.reservacion_id = r.id AND {$condicionTicket}) AS ticket_abierto,
                    EXISTS (SELECT 1 FROM horario_impacto_reservaciones hir
                             WHERE hir.reservacion_id = r.id
                               AND hir.estado IN ('pendiente_notificacion', 'notificacion_preparada', 'sin_contacto')) AS horario_pendiente,
                    (SELECT rr.id FROM reservacion_reubicaciones rr
                      WHERE rr.reservacion_id = r.id AND rr.revisada_at IS NULL
                      ORDER BY rr.id DESC LIMIT 1) AS reubicacion_id
               FROM reservaciones r
               LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
               LEFT JOIN mesas m ON m.id = rm.mesa_id
              WHERE r.estado = 'confirmada'
                AND r.fecha BETWEEN ? AND ?
              GROUP BY r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora, r.comensales,
                       r.estado, r.hold_expires_at, r.origen, r.created_at, r.updated_at
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

        return $filas;
    }
}
