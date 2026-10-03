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

    function creationAvailability(options) {
        options = options || {};
        var unavailable = options.hasValidDate !== true
            || options.hasSchedules !== true
            || options.cargando === true
            || Boolean(options.loadFailure)
            || options.readonly === true;
        return {
            unavailable: unavailable,
            disabled: unavailable || options.guardando === true
        };
    }

    function tableModalState(table, options) {
        table = table || {};
        options = options || {};
        var selected = options.selected === true;
        var modifiers = Array.isArray(table.modificadores_visual_mapa)
            ? table.modificadores_visual_mapa
            : null;
        var visualState = String(table.estado_visual_mapa || '');
        var label = String(table.label_visual_mapa || '').trim();
        var title = String(table.titulo_mapa || table.aria_label_mapa || '').trim();
        var contractValid = ['libre', 'ocupada', 'reservacion-proxima', 'no-utilizable'].indexOf(visualState) !== -1
            && modifiers !== null
            && label !== ''
            && title !== '';
        if (!contractValid) {
            return {
                label: 'Estado no verificado',
                context: 'La información visual está incompleta; no se puede asignar esta mesa.',
                visualState: 'no-utilizable',
                selected: false,
                ariaLabel: title,
                assignable: false
            };
        }

        var context = modifiers.indexOf('ausencia_pendiente') !== -1
            ? 'Acción pendiente: registrar ausencia.'
            : '';

        return {
            label: label,
            context: context,
            visualState: visualState,
            selected: selected && visualState !== 'no-utilizable',
            ariaLabel: title,
            assignable: mesaPuedeSerCandidata(table)
        };
    }

    root.ReservationOperationPolicy = {
        mesaPuedeSerCandidata: mesaPuedeSerCandidata,
        currentAssignmentIsConflict: currentAssignmentIsConflict,
        creationAvailability: creationAvailability,
        tableModalState: tableModalState
    };
}(window));
