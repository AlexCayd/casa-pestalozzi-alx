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

    function inspectVisualContract(value, previousState) {
        if (window.MapaVisual && typeof window.MapaVisual.validarEstadoVisual === 'function') {
            return window.MapaVisual.validarEstadoVisual(value, previousState);
        }
        // La validación sin el renderer disponible falla de forma cerrada.
        return {
            valido: false,
            estadoVisual: 'no-utilizable',
            seleccionHeredada: false
        };
    }

    function validModifierContract(value) {
        return window.MapaVisual
            && typeof window.MapaVisual.validarModificadoresVisuales === 'function'
            ? window.MapaVisual.validarModificadoresVisuales(value)
            : false;
    }

    function contractModifiersValid(raw) {
        var fields = ['modificadores_visual_pos', 'modificadores_visual_mapa'];
        for (var i = 0; i < fields.length; i += 1) {
            if (Object.prototype.hasOwnProperty.call(raw, fields[i])
                && !validModifierContract(raw[fields[i]])) {
                return false;
            }
        }
        return true;
    }

    function firstPresent(values) {
        for (var i = 0; i < values.length; i += 1) {
            if (values[i] !== null && values[i] !== undefined) {
                return values[i];
            }
        }
        return null;
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
        var visualValue = firstPresent([
            options.estadoVisual,
            raw.estado_visual_pos,
            raw.estado_visual_mapa,
            raw.estadoVisual,
            raw.estado_visual
        ]);
        var visualContract = inspectVisualContract(visualValue, previousState);
        return visualContract.valido ? visualContract.estadoVisual : 'no-utilizable';
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
        var visualValue = firstPresent([
            options.estadoVisual,
            raw.estado_visual_pos,
            raw.estado_visual_mapa,
            raw.estadoVisual,
            raw.estado_visual
        ]);
        var previousState = options.estadoVisualAnterior
            || raw.estado_visual_previo
            || raw.estadoVisualAnterior;
        var visualContract = inspectVisualContract(visualValue, previousState);
        var contractValid = visualContract.valido && contractModifiersValid(raw);
        var estadoNoVerificado = booleanValue(options.estadoNoVerificado)
            || booleanValue(raw.estadoNoVerificado)
            || !contractValid;
        var noUtilizable = isUnusable(raw, options, stateBase);
        var disponibleParaAsignacion = raw.disponible_para_asignacion == null
            ? null
            : booleanValue(raw.disponible_para_asignacion);
        var seleccionValidaSolicitada = selectionValidity(raw, options)
            && !noUtilizable
            && !estadoNoVerificado
            && visualContract.estadoVisual !== 'no-utilizable';
        var seleccionValida = seleccionValidaSolicitada && !estadoNoVerificado;
        var interactivoSolicitado = options.interactivo != null
            ? booleanValue(options.interactivo)
            : booleanValue(raw.reservable)
                && (disponibleParaAsignacion === null || disponibleParaAsignacion);
        var estadoVisual = estadoNoVerificado
            ? 'no-utilizable'
            : visualContract.estadoVisual;
        selected = (selected || visualContract.seleccionHeredada) && seleccionValida;
        if (selected && modifiers.indexOf('seleccion_actual') === -1) {
            modifiers.push('seleccion_actual');
        }
        if (estadoNoVerificado) {
            modifiers = modifiers.filter(function (modifier) {
                return modifier !== 'seleccion_actual' && modifier !== 'seleccionada';
            });
        }

        var stateClasses = (options.clasesEstado || []).filter(function (className) {
            return typeof className === 'string';
        });
        if (estadoNoVerificado) {
            stateClasses = stateClasses.filter(function (className) {
                return !/(^|[-_])(selected|seleccionada|highlight)([-_]|$)/i.test(className);
            });
        }

        return {
            id: parseInt(raw.id || '0', 10),
            numero: raw.numero,
            nombre: String(raw.etiqueta || raw.nombre || ''),
            tipo: String(raw.tipo || 'mesa'),
            estadoBase: stateBase,
            estadoNoVerificado: estadoNoVerificado,
            x: options.x != null ? options.x : raw.pos_x,
            y: options.y != null ? options.y : raw.pos_y,
            ancho: options.ancho != null ? options.ancho : raw.ancho,
            alto: options.alto != null ? options.alto : raw.alto,
            reservable: booleanValue(raw.reservable),
            activo: raw.activo,
            motivo_bloqueo: raw.motivo_bloqueo,
            capacidad: parseInt(raw.capacidad || '0', 10) || 0,
            seleccionada: selected,
            seleccionValida: seleccionValida,
            seleccionValidaSolicitada: seleccionValidaSolicitada,
            interactivoSolicitado: interactivoSolicitado,
            interactivo: !estadoNoVerificado
                && estadoVisual !== 'no-utilizable'
                && interactivoSolicitado,
            independienteDeConsulta: booleanValue(options.independienteDeConsulta)
                || booleanValue(raw.independienteDeConsulta),
            titulo: String(options.titulo || raw.titulo || raw.nombre || ''),
            ariaLabel: String(options.ariaLabel || raw.titulo_mapa || raw.aria_label || ''),
            modificadores: modifiers,
            estadoVisual: estadoVisual,
            clasesEstado: modifiers.map(modifierClass).concat(stateClasses),
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
