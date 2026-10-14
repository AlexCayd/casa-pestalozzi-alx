<?php

namespace Services\Reservations;

/**
 * Respuesta JSON del alta de una reservación hecha por el personal.
 *
 * La comparten el formulario del panel (AdminReservacionController::store) y
 * el «+ Reservación» del POS. Vivía escrita dentro del controlador del panel y
 * el POS la habría copiado: dos respuestas del mismo alta acaban diciendo cosas
 * distintas a la primera advertencia nueva.
 *
 * Recibe el resultado ya enriquecido por ReservacionErrorCatalog y los datos
 * que se enviaron; no decide nada.
 */
final class ReservacionAltaPresenter
{
    /**
     * @param array<string, mixed> $resultado
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    public static function json(array $resultado, array $entrada): array
    {
        $decisiones = ReservacionErrorCatalog::decisionesResultado($resultado);
        $contexto = array_merge(
            (array)($resultado['contexto'] ?? []),
            [
                'comensales' => (int)($resultado['capacidad_solicitada'] ?? 0),
                'capacidad_disponible' => (int)($resultado['capacidad_disponible'] ?? 0),
                'asignacion_automatica_solicitada' => array_key_exists('asignacion_automatica_solicitada', (array)($resultado['contexto'] ?? []))
                    ? (bool)$resultado['contexto']['asignacion_automatica_solicitada']
                    : filter_var($entrada['asignar_automaticamente'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]
        );
        $capacidades = [
            'requestedCapacity' => (int)($resultado['capacidad_solicitada'] ?? 0),
            'availableCapacity' => (int)($resultado['capacidad_disponible'] ?? 0),
            'physicalCapacityTotal' => (int)($resultado['capacidad_fisica_total'] ?? 0),
            'physicalCapacityCommitted' => (int)($resultado['capacidad_fisica_comprometida'] ?? 0),
            'physicalCapacityFree' => (int)($resultado['capacidad_fisica_libre'] ?? 0),
            'unassignedDemand' => (int)($resultado['demanda_no_asignada'] ?? 0),
            'realAvailableCapacity' => (int)($resultado['capacidad_real_disponible'] ?? 0),
            'resultingCapacity' => (int)($resultado['capacidad_resultante'] ?? 0),
            'excessCapacity' => (int)($resultado['exceso_capacidad'] ?? 0),
        ];
        $comun = [
            'mensaje' => (string)($resultado['mensaje'] ?? ''),
            'descripcion' => (string)($resultado['descripcion'] ?? ''),
            'consecuencia' => (string)($resultado['consecuencia'] ?? ''),
            'titulo' => (string)($resultado['titulo'] ?? ''),
            'tipo' => (string)($resultado['tipo'] ?? ''),
            'commit' => (bool)($resultado['commit'] ?? false),
            'contexto' => $contexto,
            'warnings' => $decisiones,
            'confirmaciones_requeridas' => $decisiones,
            'requiredConfirmations' => $decisiones,
        ];

        if (!($resultado['ok'] ?? false)) {
            return [
                'success' => false,
                'ok' => false,
                'reservationId' => null,
                'fieldErrors' => is_array($resultado['errors'] ?? null) ? $resultado['errors'] : [],
                'codigo' => (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO),
                'requiresContactConfirmation' => (bool)($resultado['requiere_confirmacion_sin_contacto'] ?? false),
                'requiresCapacityConfirmation' => (bool)($resultado['requiere_confirmacion_capacidad'] ?? false),
                'nextValidTime' => $resultado['siguiente_horario_valido'] ?? null,
                'mesa_ids' => array_values(array_map('intval', (array)($resultado['mesa_ids'] ?? []))),
                'conflictos_ticket' => $resultado['conflictos_ticket'] ?? [],
                // MESAS_REQUERIDAS de un grupo mayor al máximo: el cliente
                // pasa a elegirlas en el mapa en vez de mostrar un error.
                'requiere_seleccion_mesas' => (bool)($resultado['requiere_seleccion_mesas'] ?? false),
            ] + $comun + $capacidades;
        }

        $id = (int)($resultado['id'] ?? 0);
        $mesaIds = array_values(array_map('intval', (array)($resultado['mesa_ids'] ?? [])));

        return [
            'success' => (bool)($resultado['commit'] ?? false),
            'ok' => true,
            'reservationId' => $id > 0 ? $id : null,
            'reservacion_id' => $id > 0 ? $id : null,
            'fieldErrors' => [],
            'codigo' => (string)($resultado['codigo'] ?? ReservacionService::CREADA),
            'fecha' => (string)($entrada['fecha'] ?? ''),
            'hora' => HorarioReservacionService::normalizarHoraCorta((string)($entrada['hora'] ?? '')),
            'asignacion' => (string)($resultado['asignacion'] ?? 'PENDIENTE'),
            'mesaIds' => $mesaIds,
            'mesa_ids' => $mesaIds,
            'reubicaciones' => array_values((array)($resultado['reubicaciones'] ?? [])),
            'requiresConfirmation' => (bool)($resultado['requiere_confirmacion'] ?? false)
                || (string)($resultado['tipo'] ?? '') === ReservacionErrorCatalog::TIPO_DECISION,
            'requiresManualAssignment' => (bool)($resultado['requiere_asignacion_manual'] ?? false),
            'withoutContact' => (bool)($resultado['sin_contacto'] ?? false),
            'warning' => $resultado['warning'] ?? null,
            'dependsOnProjectedRelease' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
            'projectedTableIds' => array_values(array_map(
                'intval',
                (array)($resultado['mesas_proyectadas'] ?? [])
            )),
            'idempotent' => (bool)($resultado['idempotente'] ?? false),
        ] + $comun + $capacidades;
    }
}
