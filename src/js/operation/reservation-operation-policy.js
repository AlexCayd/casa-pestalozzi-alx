(function (root) {
    'use strict';

    function mesaPuedeSerCandidata(mesa) {
        return Boolean(mesa)
            && mesa.reservable === true
            && mesa.disponible_para_asignacion === true;
    }

    function currentAssignmentIsConflict(mesa) {
        return Boolean(mesa)
            && mesa.asignada_actualmente === true
            && mesa.disponible_para_asignacion !== true;
    }

    function tableModalState(table, options) {
        options = options || {};
        var selected = options.selected === true;
        var modifiers = Array.isArray(table.modificadores_visual_mapa)
            ? table.modificadores_visual_mapa
            : (Array.isArray(table.modificadores_mapa) ? table.modificadores_mapa : []);
        var reservation = table.reservacion || table.reservacion_asociada || null;
        var hourSource = reservation && (reservation.hora || reservation.hora_reservacion)
            ? reservation.hora || reservation.hora_reservacion
            : table.inicio_reservacion || '';
        var hourMatch = String(hourSource || '').match(/(?:^|T|\s)(\d{2}:\d{2})/);
        var hour = hourMatch ? hourMatch[1] : '';
        var visualState = String(table.estado_visual_mapa || table.estado_visual || 'libre');
        var ticketOpen = table.ticket_abierto === true;
        var ticketBlocksInterval = table.ticket_bloquea_consulta === true;
        var blockedInInterval = table.bloqueada_en_intervalo === true
            || ticketBlocksInterval
            || table.disponible_para_asignacion !== true;
        var label = 'Disponible';
        var context = '';

        if (selected) {
            label = 'Seleccionada';
            context = 'Asignación en curso';
            visualState = 'seleccionada';
        } else if (table.utilizable === false || table.reservable === false) {
            label = 'No reservable';
            context = 'Área operativa';
            visualState = 'no-utilizable';
        } else if (table.ausencia_pendiente === true || modifiers.indexOf('ausencia_pendiente') !== -1) {
            label = 'Ausencia pendiente';
            context = hour || 'Registrar ausencia';
        } else if (ticketBlocksInterval) {
            label = 'Ocupada';
            context = 'Ticket abierto';
            visualState = 'ocupada';
        } else if (visualState === 'ocupada') {
            label = 'Ocupada';
            context = 'Servicio activo';
        } else if (visualState === 'reservacion-proxima'
            || modifiers.indexOf('reservacion_advertencia') !== -1
            || modifiers.indexOf('reservacion_inminente') !== -1
            || table.reservacion_proxima) {
            label = 'Reserva próxima';
            context = hour || 'Reserva próxima';
            visualState = 'reservacion-proxima';
        } else if (blockedInInterval) {
            label = 'Bloqueada';
            context = hour || 'No disponible para este horario';
            visualState = visualState === 'libre' ? 'reservacion-proxima' : visualState;
        } else if (ticketOpen) {
            context = 'Disponible para este horario · ticket actualmente abierto';
        }

        return {
            label: label,
            context: context,
            visualState: visualState,
            assignable: mesaPuedeSerCandidata(table)
        };
    }

    root.ReservationOperationPolicy = {
        mesaPuedeSerCandidata: mesaPuedeSerCandidata,
        currentAssignmentIsConflict: currentAssignmentIsConflict,
        tableModalState: tableModalState
    };
}(window));
