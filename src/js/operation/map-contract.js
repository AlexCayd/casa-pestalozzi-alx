/**
 * Contrato visual compartido por los mapas de POS y Reservaciones.
 *
 * Es puro: no accede al DOM ni decide disponibilidad o interacción.
 */
(function () {
    var FALLBACK_STATE = 'no-utilizable';

    function canonicalState(value) {
        var state = String(value == null ? '' : value).trim().toLowerCase();
        return state === 'libre'
            || state === 'ocupada'
            || state === 'reservacion-proxima'
            || state === FALLBACK_STATE
            ? state
            : null;
    }

    function identifyLegacySelection(value, previousState) {
        return String(value == null ? '' : value).trim().toLowerCase() === 'seleccionada'
            && canonicalState(previousState) !== null;
    }

    function validateState(value, previousState) {
        var state = String(value == null ? '' : value).trim().toLowerCase();
        if (state === 'seleccionada') {
            var previous = canonicalState(previousState);
            return previous === null
                ? {
                    valido: false,
                    estadoVisual: FALLBACK_STATE
                }
                : {
                    valido: true,
                    estadoVisual: previous
                };
        }

        var normalized = canonicalState(state);
        return {
            valido: normalized !== null,
            estadoVisual: normalized || FALLBACK_STATE
        };
    }

    function normalizeState(value, previousState) {
        return validateState(value, previousState).estadoVisual;
    }

    function validateModifiers(value) {
        return Array.isArray(value) && value.every(function (modifier) {
            return typeof modifier === 'string'
                && /^[a-z0-9_-]+$/i.test(modifier.trim());
        });
    }

    function safeFallback() {
        return {
            estadoVisual: FALLBACK_STATE,
            estadoNoVerificado: true,
            modificadores: [],
            seleccionada: false,
            seleccionValida: false,
            interactivo: false
        };
    }

    window.MapaContrato = {
        validarEstado: validateState,
        normalizarEstado: normalizeState,
        validarModificadores: validateModifiers,
        identificarSeleccionHeredada: identifyLegacySelection,
        fallbackSeguro: safeFallback
    };
})();
