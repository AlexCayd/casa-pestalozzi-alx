<?php

namespace Services\Reservations;

use Model\Reservacion;
use Model\RegistroRestaurante;
use Model\TicketMesa;
use Services\Notifications\BuzonNotificacionesService;
use Services\Pos\ReservacionPoliticaPosService;
use Services\Scheduling\HorarioOperacionImpactoService;
use Services\Scheduling\HorarioOperacionService;

/**
 * La bandeja del buzón tal como la ve el administrador: los avisos abiertos
 * resueltos contra su fuente y agrupados en una tarjeta por reservación.
 *
 * Es la ÚNICA fuente de los números del buzón —el contador del topbar, el
 * «N por atender · M en espera» del cajón y las dos pestañas—, y no por
 * comodidad. Antes el contador contaba FILAS de `buzon_notificaciones` y la
 * lista pintaba lo que sobrevivía a la resolución contra la fuente, que
 * descarta un aviso cuando su reservación ya no existe, ya se resolvió o salió
 * de la ventana operativa. Las dos cuentas divergían en silencio: el topbar
 * marcaba «1», el cajón decía «1 por atender» y la pestaña enseñaba «Todo al
 * día». Además una cuenta era de avisos y la otra de reservaciones, así que
 * dos motivos sobre la misma reserva sumaban 2 contra una sola tarjeta.
 *
 * Aquí se cuenta lo que se pinta, con el mismo criterio que usan las pestañas
 * del cajón (src/js/admin/buzon.js, `itemRequiresAction`): una tarjeta está
 * «por atender» si alguno de sus avisos requiere acción, y «en espera» si
 * ninguno.
 */
final class ReservacionBuzonBandejaService
{
    private static function unidadSql(string $alias = ''): string
    {
        return \Services\Security\ContextoNegocio::nuevo() ? RegistroRestaurante::filtroUnidad($alias) : '';
    }
    /** El mismo tope que tenía la API: el cajón no pagina. */
    private const LIMITE = 100;

    /** @var array<int, string>|null Nombres de mesa, memorizados por petición. */
    private static ?array $nombresMesa = null;

    /**
     * @return array{items: list<array<string, mixed>>, cantidad: int,
     *   cantidad_accionable: int, cantidad_seguimiento: int,
     *   prioridad_maxima: ?string, prioridad_maxima_accionable: ?string}
     */
    public static function construir(): array
    {
        $notificaciones = BuzonNotificacionesService::listar(['limit' => self::LIMITE]);
        if ($notificaciones === []) {
            return ['items' => []] + self::contar([]);
        }

        // Los tickets abiertos sólo los miran los avisos que apuntan a una
        // reservación (ausencia, sin asignar, grupo grande). Con un buzón de
        // sólo cambios de horario o reubicaciones, la consulta del mapa sobra:
        // esto corre en cada página del panel, porque el topbar lo pinta.
        $ticketsPorReservacion = [];
        $necesitaTickets = false;
        foreach ($notificaciones as $notificacion) {
            if ((string)($notificacion['entidad_tipo'] ?? '') === ReservacionBuzonService::ENTIDAD_RESERVACION) {
                $necesitaTickets = true;
                break;
            }
        }
        if ($necesitaTickets) {
            foreach (TicketMesa::abiertosParaMapa() as $ticket) {
                $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
                if ($reservacionId > 0) {
                    $ticketsPorReservacion[$reservacionId] = $ticket;
                }
            }
        }

        $grupos = [];
        foreach ($notificaciones as $notificacion) {
            $item = self::fuente($notificacion, $ticketsPorReservacion);
            if ($item === null) {
                // Lectura pura: la fuente desapareció o ya no pide nada. El
                // aviso no se pinta ni se cuenta; cerrarlo es trabajo de la
                // sincronización (POST /admin/api/buzon/sincronizar).
                continue;
            }
            $tipo = (string)$notificacion['tipo'];
            $reservacionId = (int)$item['reservacion_id'];
            if (!isset($grupos[$reservacionId])) {
                $grupos[$reservacionId] = [
                    'reservacion_id' => $reservacionId,
                    'nombre' => (string)$item['nombre'],
                    'fecha' => (string)$item['fecha'],
                    'hora' => (string)$item['hora'],
                    'comensales' => (int)$item['comensales'],
                    // El umbral es configurable: el JS no debe compararlo por
                    // su cuenta contra un número fijo.
                    'grupo_grande' => (int)$item['comensales'] > ReservacionConfig::umbralGrupoGrande(),
                    'prioridad' => 'normal',
                    'severidad' => (int)($item['severidad'] ?? 50),
                    'leida' => true,
                    'motivos' => [],
                    'notificaciones' => [],
                ];
            }
            $grupo =& $grupos[$reservacionId];
            $prioridad = (string)($notificacion['prioridad'] ?? 'normal');
            if ($prioridad === 'alta') {
                $grupo['prioridad'] = 'alta';
            }
            $grupo['severidad'] = min($grupo['severidad'], (int)($item['severidad'] ?? 50));
            if (($notificacion['leida_at'] ?? null) === null) {
                $grupo['leida'] = false;
            }
            $grupo['motivos'][] = [
                'tipo' => $tipo,
                'prioridad' => $prioridad,
                'etiqueta' => $item['etiqueta'],
                'descripcion' => $item['descripcion'] ?? null,
                'estado' => $item['estado'] ?? null,
                'severidad' => (int)($item['severidad'] ?? 50),
            ];
            $grupo['notificaciones'][] = [
                'id' => (int)$notificacion['id'],
                'tipo' => $tipo,
                'entidad_tipo' => (string)$notificacion['entidad_tipo'],
                'entidad_id' => (int)$notificacion['entidad_id'],
                'prioridad' => $prioridad,
                'leida_at' => $notificacion['leida_at'],
                'estado' => $item['estado'] ?? null,
                'etiqueta' => $item['etiqueta'],
                'descripcion' => $item['descripcion'] ?? null,
                'accion_primaria' => $item['accion_primaria'] ?? null,
                'puede_registrar_no_show' => (bool)($item['puede_registrar_no_show'] ?? false),
                'puede_asignar_mesas' => (bool)($item['puede_asignar_mesas'] ?? false),
                'ventana_operativa' => $item['ventana_operativa'] ?? null,
                'impacto_id' => $item['impacto_id'] ?? null,
                'impacto_reservacion_id' => $item['impacto_reservacion_id'] ?? null,
                'tiene_contacto' => $item['tiene_contacto'] ?? null,
                'test_link_disponible' => $item['test_link_disponible'] ?? false,
                'requiere_accion' => (bool)($item['requiere_accion'] ?? ((int)($notificacion['requiere_accion'] ?? 1) === 1)),
                'puede_mandar_aviso' => (bool)($item['puede_mandar_aviso'] ?? false),
                'mensaje_aviso' => $item['mensaje_aviso'] ?? null,
                'access_expires_at' => $item['access_expires_at'] ?? null,
                'notification_attempts' => (int)($item['notification_attempts'] ?? 0),
            ];
            unset($grupo);
        }

        foreach ($grupos as &$grupo) {
            // «No llegó» y «falta asignar mesas» sobre la misma reserva son el
            // mismo problema visto dos veces: manda la ausencia.
            $hayAusencia = array_filter(
                $grupo['notificaciones'],
                static fn(array $notificacion): bool => $notificacion['tipo'] === ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE
            );
            if ($hayAusencia) {
                $grupo['notificaciones'] = array_values(array_filter(
                    $grupo['notificaciones'],
                    static fn(array $notificacion): bool => $notificacion['tipo'] !== ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA
                ));
                $grupo['motivos'] = array_values(array_filter(
                    $grupo['motivos'],
                    static fn(array $motivo): bool => $motivo['tipo'] !== ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA
                ));
            }
            usort($grupo['motivos'], static function (array $a, array $b): int {
                return [(int)($a['severidad'] ?? 50), (string)($a['etiqueta'] ?? '')]
                    <=> [(int)($b['severidad'] ?? 50), (string)($b['etiqueta'] ?? '')];
            });
        }
        unset($grupo);

        $items = array_values(array_filter($grupos, static fn(array $grupo): bool => $grupo['notificaciones'] !== []));
        usort($items, static function (array $a, array $b): int {
            return [$a['severidad'], $a['prioridad'] === 'alta' ? 0 : 1]
                <=> [$b['severidad'], $b['prioridad'] === 'alta' ? 0 : 1];
        });

        return ['items' => $items] + self::contar($items);
    }

    /**
     * Los números del buzón sin la lista. Es lo que pinta el topbar en cada
     * página del panel y lo que devuelve /admin/api/buzon/resumen.
     *
     * @return array{cantidad: int, cantidad_accionable: int, cantidad_seguimiento: int,
     *   prioridad_maxima: ?string, prioridad_maxima_accionable: ?string}
     */
    public static function resumen(): array
    {
        try {
            $bandeja = self::construir();
            unset($bandeja['items']);
            return $bandeja;
        } catch (\Throwable $e) {
            // El topbar se pinta en todas las páginas del panel: un fallo del
            // buzón no puede tumbar la página, sólo dejar el contador en cero.
            error_log('ReservacionBuzonBandejaService::resumen - ' . $e->getMessage());
            return self::contar([]);
        }
    }

    /**
     * Cuenta TARJETAS, con el criterio de las pestañas del cajón.
     *
     * @param list<array<string, mixed>> $items
     * @return array{cantidad: int, cantidad_accionable: int, cantidad_seguimiento: int,
     *   prioridad_maxima: ?string, prioridad_maxima_accionable: ?string}
     */
    public static function contar(array $items): array
    {
        $accionables = 0;
        $seguimiento = 0;
        $altaAccionable = false;
        $alta = false;
        foreach ($items as $item) {
            $requiereAccion = false;
            foreach ($item['notificaciones'] ?? [] as $notificacion) {
                $esAlta = ($notificacion['prioridad'] ?? 'normal') === 'alta';
                $alta = $alta || $esAlta;
                if (($notificacion['requiere_accion'] ?? true) !== false) {
                    $requiereAccion = true;
                    $altaAccionable = $altaAccionable || $esAlta;
                }
            }
            if ($requiereAccion) {
                $accionables++;
            } else {
                $seguimiento++;
            }
        }
        $total = $accionables + $seguimiento;

        return [
            'cantidad' => $total,
            'cantidad_accionable' => $accionables,
            'cantidad_seguimiento' => $seguimiento,
            'prioridad_maxima' => $total === 0 ? null : ($alta ? 'alta' : 'normal'),
            'prioridad_maxima_accionable' => $accionables === 0 ? null : ($altaAccionable ? 'alta' : 'normal'),
        ];
    }

    /**
     * Resuelve un aviso contra su fuente. Devuelve null cuando ya no hay nada
     * que enseñar: la fuente no existe, ya se resolvió o cayó fuera de su
     * ventana.
     *
     * @param array<string, mixed> $notificacion
     * @param array<int, array<string, mixed>> $ticketsPorReservacion
     * @return array<string, mixed>|null
     */
    public static function fuente(array $notificacion, array $ticketsPorReservacion = []): ?array
    {
        $tipo = (string)$notificacion['tipo'];
        if ($tipo === ReservacionBuzonService::TIPO_HORARIO_AFECTADO) {
            $fuente = HorarioOperacionImpactoService::obtenerPorItem((int)$notificacion['entidad_id']);
            if (!$fuente || in_array((string)$fuente['estado'], [
                HorarioOperacionImpactoService::ESTADO_ITEM_MANUAL,
                HorarioOperacionImpactoService::ESTADO_ITEM_CLIENTE,
            ], true)) {
                return null;
            }
            $ahora = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
            $expirada = (string)($fuente['access_expires_at'] ?? '') !== ''
                && (string)$fuente['access_expires_at'] <= $ahora;
            $tieneContacto = (bool)($fuente['tiene_contacto'] ?? false);
            $esGrupoGrande = (int)($fuente['comensales'] ?? 0) > ReservacionConfig::umbralGrupoGrande();
            $estadoEntrega = (string)($fuente['notification_delivery_status'] ?? 'pending');
            $requiereAccion = !$tieneContacto
                || $esGrupoGrande
                || $estadoEntrega === 'failed'
                || $expirada;
            if ($esGrupoGrande) {
                $fuente['etiqueta'] = 'Requiere gestión manual';
                $fuente['descripcion'] = 'Los grupos de más de ' . ReservacionConfig::umbralGrupoGrande() . ' personas se coordinan desde la reservación.';
                $fuente['severidad'] = 30;
            } elseif (!$tieneContacto) {
                $fuente['etiqueta'] = 'Falta un contacto';
                $fuente['descripcion'] = 'Agrega un correo o teléfono para enviar el enlace.';
                $fuente['severidad'] = 20;
            } elseif ($estadoEntrega === 'failed') {
                $fuente['etiqueta'] = 'No pudimos enviar el aviso.';
                $fuente['descripcion'] = 'El acceso se invalidó. Revisa el contacto antes de reenviar el aviso.';
                $fuente['severidad'] = 20;
            } elseif ($estadoEntrega === 'pending') {
                $fuente['etiqueta'] = 'Aviso preparado';
                $fuente['descripcion'] = 'Esperando respuesta del servicio de envío.';
                $fuente['severidad'] = 40;
            } elseif ($expirada) {
                $fuente['etiqueta'] = 'Sin respuesta';
                $fuente['descripcion'] = 'El enlace para cambiar el horario venció.';
                $fuente['severidad'] = 20;
            } else {
                $fuente['etiqueta'] = 'Esperando respuesta';
                $horaExpiracion = '';
                try {
                    $horaExpiracion = (new \DateTimeImmutable((string)$fuente['access_expires_at'], ReservacionConfig::timezone()))->format('H:i');
                } catch (\Throwable) {
                    $horaExpiracion = substr((string)$fuente['access_expires_at'], 11, 5);
                }
                $fuente['descripcion'] = ($estadoEntrega === 'accepted' ? 'Proveedor aceptó el envío. ' : '')
                    . 'El cliente tiene un enlace activo hasta ' . $horaExpiracion . '.';
                $fuente['severidad'] = 50;
            }
            $fuente['accion_primaria'] = 'Abrir reservación';
            $fuente['requiere_accion'] = $requiereAccion;
            $fuente['puede_mandar_aviso'] = !$esGrupoGrande
                && $tieneContacto
                && (bool)($fuente['puede_mandar_aviso'] ?? false);
            $fuente['mensaje_aviso'] = $fuente['puede_mandar_aviso']
                ? 'Reenviar aviso'
                : null;
            return $fuente;
        }

        if ($tipo === ReservacionBuzonService::TIPO_REUBICADA) {
            return self::fuenteReubicacion((int)$notificacion['entidad_id']);
        }

        if (!in_array($tipo, [
            ReservacionBuzonService::TIPO_GRUPO_GRANDE,
            ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE,
            ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA,
        ], true)) {
            return null;
        }

        $reservacion = Reservacion::findWithMesas((int)$notificacion['entidad_id']);
        if (!$reservacion) {
            return null;
        }
        $reservacionId = (int)$reservacion->id;
        $ticket = $ticketsPorReservacion[$reservacionId] ?? null;
        $estado = (string)$reservacion->estado;
        $estadoFinal = in_array($estado, ReservacionConfig::ESTADOS_FINALES, true);
        $sinContacto = !in_array((string)$reservacion->contacto_tipo, ['email', 'telefono'], true)
            || trim((string)($reservacion->contacto ?? '')) === '';
        $vigencia = ReservacionVigenciaService::clasificar($reservacion, null, $ticket);
        $politica = ReservacionPoliticaPosService::evaluar(
            $reservacion,
            null,
            $ticket,
            null,
            ['sin_mesas' => (int)$reservacion->mesas_count === 0]
        );

        $base = [
            'reservacion_id' => $reservacionId,
            'nombre' => (string)$reservacion->nombre,
            'fecha' => (string)$reservacion->fecha,
            'hora' => substr((string)$reservacion->hora, 0, 5),
            'comensales' => (int)$reservacion->comensales,
            'estado' => $estado,
            'tiene_contacto' => !$sinContacto,
            'test_link_disponible' => false,
            'requiere_accion' => true,
        ];

        if ($tipo === ReservacionBuzonService::TIPO_GRUPO_GRANDE) {
            if (!ReservacionBuzonService::grupoGrandeVisibleParaBuzon($reservacion)) {
                return null;
            }
            return array_merge($base, [
                'etiqueta' => 'Requiere gestión manual',
                'descripcion' => 'Los grupos de más de ' . ReservacionConfig::umbralGrupoGrande() . ' personas se coordinan desde la reservación.',
                'accion_primaria' => 'Abrir reservación',
                'requiere_accion' => true,
                'severidad' => 30,
            ]);
        }

        if ($tipo === ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE) {
            if ($estado !== 'confirmada'
                || $ticket
                || !$vigencia['ausencia_pendiente']
                || !$vigencia['puede_marcar_no_show']) {
                return null;
            }
            return array_merge($base, [
                'etiqueta' => 'Tolerancia vencida',
                'descripcion' => 'El cliente no llegó dentro del tiempo de tolerancia.',
                'accion_primaria' => 'Registrar que no llegó',
                'puede_registrar_no_show' => true,
                'requiere_accion' => true,
                'severidad' => 10,
            ]);
        }

        $fueraHorario = !HorarioOperacionService::estaAbierto(
            (string)$reservacion->fecha,
            (string)$reservacion->hora
        );
        $ventana = (string)($vigencia['ventana_operativa']['estado'] ?? $politica['ventana_pos'] ?? 'futura');
        if ($estado !== 'confirmada'
            || $estadoFinal
            || $ticket
            || (int)$reservacion->mesas_count > 0
            || (int)$reservacion->comensales > ReservacionConfig::umbralGrupoGrande()
            || $vigencia['ausencia_pendiente']
            || $fueraHorario
            || !in_array($ventana, ['advertencia', 'bloqueo', 'tolerancia'], true)) {
            return null;
        }
        return array_merge($base, [
            'etiqueta' => 'Falta asignar mesas',
            'descripcion' => 'La reservación comienza a las ' . substr((string)$reservacion->hora, 0, 5) . ' y todavía no tiene mesas asignadas.',
            'accion_primaria' => 'Asignar mesas',
            'puede_asignar_mesas' => true,
            'requiere_accion' => true,
            'ventana_operativa' => $ventana,
            'severidad' => 40,
        ]);
    }

    /**
     * «Reubicada»: informativo. El sistema ya movió la reserva; lo que hace
     * falta es que alguien lo sepa antes de sentar al cliente en la mesa que
     * creía suya. Desaparece cuando se marca revisada (desde el POS) o cuando
     * la reserva deja de estar viva.
     *
     * @return array<string, mixed>|null
     */
    public static function fuenteReubicacion(int $reubicacionId): ?array
    {
        $reubicacion = \Model\ReservacionReubicacion::buscarConReservacion($reubicacionId);
        if (!$reubicacion || $reubicacion['revisada']) {
            return null;
        }
        $reservacion = Reservacion::findWithMesas($reubicacion['reservacion_id']);
        if (!$reservacion || in_array((string)$reservacion->estado, ReservacionConfig::ESTADOS_FINALES, true)) {
            return null;
        }
        $nombres = self::nombresMesa();
        $describir = static function (array $ids) use ($nombres): string {
            $lista = array_map(static fn(int $id): string => $nombres[$id] ?? ('Mesa ' . $id), $ids);
            return $lista === [] ? 'sin mesa' : implode(' y ', $lista);
        };

        return [
            'reservacion_id' => (int)$reservacion->id,
            'nombre' => (string)$reservacion->nombre,
            'fecha' => (string)$reservacion->fecha,
            'hora' => substr((string)$reservacion->hora, 0, 5),
            'comensales' => (int)$reservacion->comensales,
            'estado' => (string)$reservacion->estado,
            'tiene_contacto' => in_array((string)$reservacion->contacto_tipo, ['email', 'telefono'], true),
            'test_link_disponible' => false,
            'etiqueta' => 'Reubicada',
            'descripcion' => 'Pasó de ' . $describir($reubicacion['mesas_antes']) . ' a '
                . $describir($reubicacion['mesas_despues'])
                . ' para dar el exterior a un grupo grande. Al cliente no se le avisa.',
            'accion_primaria' => 'Abrir reservación',
            'requiere_accion' => false,
            'severidad' => 60,
        ];
    }

    /**
     * Cierra los avisos cuya fuente ya no existe.
     *
     * `buzon_notificaciones` apunta a su caso por (entidad_tipo, entidad_id),
     * que es polimórfico y no admite llave foránea: borrar una reservación
     * —lo hacen los fixtures de development.sql al resembrar «Reservaciones de
     * hoy» y varias pruebas de base— deja el aviso abierto para siempre. La
     * sincronización no lo alcanzaba porque recorre RESERVACIONES, y la de un
     * aviso huérfano ya no está.
     *
     * Va dentro de la transacción del llamador.
     */
    public static function cerrarHuerfanasEnTransaccion(\mysqli $db): int
    {
        $fuentes = [
            ReservacionBuzonService::ENTIDAD_RESERVACION => 'reservaciones',
            ReservacionBuzonService::ENTIDAD_IMPACTO_RESERVACION => 'horario_impacto_reservaciones',
            ReservacionBuzonService::ENTIDAD_REUBICACION => 'reservacion_reubicaciones',
        ];
        $cerradas = 0;
        foreach ($fuentes as $entidadTipo => $tabla) {
            // La tabla sale de la lista de arriba, nunca de la petición; el
            // tipo de entidad viaja como parámetro.
            $stmt = $db->prepare(
                "UPDATE buzon_notificaciones bn
                 LEFT JOIN {$tabla} f ON f.id = bn.entidad_id" . (\Services\Security\ContextoNegocio::nuevo() ? ' AND f.unidad_id=bn.unidad_id' : '') . "
                 SET bn.cerrada_at = NOW(), bn.cerrada_por = NULL,
                     bn.cierre_motivo = 'fuente_inexistente', bn.updated_at = NOW()
                 WHERE bn.cerrada_at IS NULL
                   AND bn.entidad_tipo = ?
                   AND f.id IS NULL" . self::unidadSql('bn')
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar el cierre de avisos huérfanos.');
            }
            $stmt->bind_param('s', $entidadTipo);
            if (!$stmt->execute()) {
                $mensaje = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($mensaje);
            }
            $cerradas += max(0, (int)$stmt->affected_rows);
            $stmt->close();
        }
        return $cerradas;
    }

    /** @return array<int, string> */
    private static function nombresMesa(): array
    {
        if (self::$nombresMesa === null) {
            self::$nombresMesa = [];
            foreach (\Model\Mesa::buscarTodasParaMapa() as $mesa) {
                self::$nombresMesa[(int)$mesa->id] = (string)$mesa->nombre;
            }
        }
        return self::$nombresMesa;
    }
}
