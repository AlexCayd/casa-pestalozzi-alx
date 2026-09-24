/**
 * Adapta el contrato operativo del backend al contrato de dibujo de MapaVisual.
 *
 * No calcula disponibilidad: sólo traduce nombres, agrega clases de
 * modificadores e incorpora opciones propias de cada pantalla.
 */
(function () {
    // La precedencia decide el fondo principal; los modificadores conservan
    // las advertencias secundarias (por ejemplo, ticket + reservación).
    var VISUAL_PRECEDENCE = [
        'ocupada',
        'reservacion-proxima',
        'libre',
        'no-utilizable'
    ];

    function booleanValue(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function uniqueStrings(values) {
        var seen = {};
        return (Array.isArray(values) ? values : []).filter(function (value) {
            value = String(value || '').trim();
            if (!value || seen[value]) {
                return false;
            }
            seen[value] = true;
            return true;
        });
    }

    function modifierClass(modifier) {
        return 'mesa-pin--mod-' + String(modifier || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]/g, '-');
    }

    function merge(base, overlay) {
        base = base || {};
        overlay = overlay || {};
        var merged = Object.assign({}, base, overlay);
        merged.modificadores = uniqueStrings(
            (base.modificadores || []).concat(overlay.modificadores || [])
        );
        return merged;
    }

    function baseState(value) {
        var state = String(value || '').toLowerCase();
        var aliases = {
            libre: 'disponible',
            disponible: 'disponible',
            ocupada: 'ocupada',
            bloqueada: 'bloqueada',
            proxima: 'proxima',
            no_reservable: 'no_reservable',
            'no-reservable': 'no_reservable',
            'no-utilizable': 'no_reservable'
        };

        return aliases[state] || '';
    }

    function normalizeVisualState(value) {
        var state = String(value || '').toLowerCase();
        var aliases = {
            disponible: 'libre',
            libre: 'libre',
            ocupada: 'ocupada',
            'reservacion-proxima': 'reservacion-proxima',
            proxima: 'reservacion-proxima',
            bloqueada: 'reservacion-proxima',
            seleccionada: 'seleccionada',
            'no-utilizable': 'no-utilizable',
            no_utilizable: 'no-utilizable'
        };
        return aliases[state] || 'no-utilizable';
    }

    function validVisualContract(value, previousState) {
        var rawState = String(value || '').toLowerCase();
        var aliases = {
            disponible: true,
            libre: true,
            ocupada: true,
            'reservacion-proxima': true,
            proxima: true,
            bloqueada: true,
            seleccionada: true,
            'no-utilizable': true,
            no_utilizable: true
        };
        if (!Object.prototype.hasOwnProperty.call(aliases, rawState)) return false;
        if (rawState !== 'seleccionada') return true;

        var previous = String(previousState || '').toLowerCase();
        return Object.prototype.hasOwnProperty.call(aliases, previous) && previous !== 'seleccionada';
    }

    function isUnusable(raw, options, state) {
        if (options.noUtilizable != null) {
            return booleanValue(options.noUtilizable);
        }

        return state === 'no_reservable'
            || raw.activo === false
            || raw.activo === 0
            || raw.activo === '0'
            || (raw.reservable != null && !booleanValue(raw.reservable));
    }

    function selectionValidity(raw, options) {
        var valid = true;
        if (raw.seleccionValida != null) {
            valid = booleanValue(raw.seleccionValida);
        } else if (raw.seleccion_valida != null) {
            valid = booleanValue(raw.seleccion_valida);
        }
        if (options.seleccionValida != null) {
            valid = valid && booleanValue(options.seleccionValida);
        }
        return valid;
    }

    /**
     * El estado visual llega resuelto desde el backend. Aquí sólo se normaliza;
     * la selección permanece como capa secundaria de interacción.
     */
    function resolverEstadoVisualMesa(raw, options) {
        raw = raw || {};
        options = options || {};
        var previousState = options.estadoVisualAnterior
            || raw.estado_visual_previo
            || raw.estadoVisualAnterior;
        var visualValue = options.estadoVisual
            || raw.estado_visual_pos
            || raw.estadoVisual
            || raw.estado_visual;
        if (!validVisualContract(visualValue, previousState)) return 'no-utilizable';

        var explicitVisualState = normalizeVisualState(visualValue);
        if (explicitVisualState === 'seleccionada') {
            return normalizeVisualState(previousState);
        }
        return explicitVisualState;
    }

    function toMapVisual(raw, options) {
        raw = raw || {};
        options = options || {};
        var stateBase = String(options.estadoBase || raw.estado_base || raw.estadoBase || '');
        var modifiers = uniqueStrings(
            (raw.modificadores || []).concat(options.modificadores || [])
        );
        var selected = options.seleccionActual != null
            ? booleanValue(options.seleccionActual)
            : booleanValue(raw.seleccion_actual);
        var visualValue = options.estadoVisual
            || raw.estado_visual_pos
            || raw.estadoVisual
            || raw.estado_visual;
        var previousState = options.estadoVisualAnterior
            || raw.estado_visual_previo
            || raw.estadoVisualAnterior;
        var contractValid = validVisualContract(visualValue, previousState);
        var noUtilizable = isUnusable(raw, options, stateBase);
        var disponibleParaAsignacion = raw.disponible_para_asignacion == null
            ? null
            : booleanValue(raw.disponible_para_asignacion);
        var seleccionValida = selectionValidity(raw, options) && !noUtilizable && contractValid;
        selected = selected && seleccionValida;
        if (selected && modifiers.indexOf('seleccion_actual') === -1) {
            modifiers.push('seleccion_actual');
        }

        return {
            id: parseInt(raw.id || '0', 10),
            numero: raw.numero,
            nombre: String(raw.etiqueta || raw.nombre || ''),
            tipo: String(raw.tipo || 'mesa'),
            estadoBase: stateBase,
            x: options.x != null ? options.x : raw.pos_x,
            y: options.y != null ? options.y : raw.pos_y,
            ancho: options.ancho != null ? options.ancho : raw.ancho,
            alto: options.alto != null ? options.alto : raw.alto,
            reservable: booleanValue(raw.reservable),
            capacidad: parseInt(raw.capacidad || '0', 10) || 0,
            seleccionada: selected,
            seleccionValida: seleccionValida,
            interactivo: options.interactivo != null
                ? booleanValue(options.interactivo) && contractValid
                : contractValid && booleanValue(raw.reservable)
                    && (disponibleParaAsignacion === null || disponibleParaAsignacion),
            titulo: String(options.titulo || raw.titulo || raw.nombre || ''),
            ariaLabel: String(options.ariaLabel || raw.titulo_mapa || raw.aria_label || ''),
            modificadores: modifiers,
            estadoVisual: resolverEstadoVisualMesa(raw, Object.assign({}, options, {
                estadoBase: stateBase,
                modificadores: modifiers,
                seleccionActual: selected,
                seleccionValida: seleccionValida,
                noUtilizable: noUtilizable,
                estadoVisual: options.estadoVisual || ''
            })),
            clasesEstado: modifiers.map(modifierClass).concat(options.clasesEstado || []),
            atributos: Object.assign({
                'data-estado-base': stateBase,
                'data-modificadores': modifiers.join(' ')
            }, options.atributos || {})
        };
    }

    window.MesaEstadoAdapter = {
        fusionar: merge,
        paraMapaVisual: toMapVisual,
        resolverEstadoVisualMesa: resolverEstadoVisualMesa,
        precedenciaVisual: VISUAL_PRECEDENCE.slice()
    };
})();
