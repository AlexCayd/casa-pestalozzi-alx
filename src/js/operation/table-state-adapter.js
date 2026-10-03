/**
 * Traduce los hechos y la proyección del backend al objeto que dibuja el mapa.
 *
 * No calcula disponibilidad ni permisos. Valida el contrato con MapaContrato,
 * incorpora decisiones del consumidor y deja la geometría lista para pintar.
 */
(function () {
    function booleanValue(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function firstPresent(values) {
        for (var i = 0; i < values.length; i += 1) {
            if (values[i] !== null && values[i] !== undefined) {
                return values[i];
            }
        }
        return null;
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

    function numberOrNull(value) {
        var parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function clampPercent(value, fallback) {
        var parsed = numberOrNull(value);
        return parsed === null ? fallback : Math.max(0, Math.min(100, parsed));
    }

    function normalizeClasses(value) {
        if (!Array.isArray(value)) {
            return [];
        }
        return value.filter(function (className) {
            return typeof className === 'string' && /^[a-zA-Z0-9_-]+$/.test(className);
        });
    }

    function normalizeAttributes(value) {
        var attributes = {};
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return attributes;
        }
        Object.keys(value).forEach(function (name) {
            if (/^(data-[a-z0-9_-]+|aria-[a-z0-9_-]+)$/i.test(name) && value[name] != null) {
                attributes[name] = String(value[name]);
            }
        });
        return attributes;
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

    function selectionValidity(raw, options) {
        var sourceValidity = raw.seleccionValida;
        if (sourceValidity == null) {
            sourceValidity = raw.seleccion_valida;
        }
        var hasSource = sourceValidity != null;
        var valid = hasSource ? booleanValue(sourceValidity) : true;
        if (options.seleccionValida != null) {
            hasSource = true;
            valid = valid && booleanValue(options.seleccionValida);
        }
        return hasSource && valid;
    }

    function modifiersValid(raw, options) {
        var fields = ['modificadores_visual_pos', 'modificadores_visual_mapa', 'modificadores'];
        for (var i = 0; i < fields.length; i += 1) {
            var field = fields[i];
            if (Object.prototype.hasOwnProperty.call(raw, field)
                && !window.MapaContrato.validarModificadores(raw[field])) {
                return false;
            }
        }
        return !Object.prototype.hasOwnProperty.call(options, 'modificadores')
            || window.MapaContrato.validarModificadores(options.modificadores);
    }

    function visualValue(raw, options) {
        return firstPresent([
            options.estadoVisual,
            raw.estado_visual_pos,
            raw.estado_visual_mapa,
            raw.estadoVisual,
            raw.estado_visual
        ]);
    }

    function previousVisualState(raw, options) {
        return options.estadoVisualAnterior
            || raw.estado_visual_previo
            || raw.estadoVisualAnterior;
    }

    function visualLabel(state, modifiers, raw, options) {
        var supplied = options.etiquetaEstado || raw.etiqueta_estado_visual || raw.etiquetaEstado;
        if (typeof supplied === 'string' && supplied.trim()) {
            return supplied.trim();
        }
        if (modifiers.indexOf('ausencia_pendiente') !== -1) {
            return 'Ausencia pendiente';
        }
        if (modifiers.indexOf('reservacion_advertencia') !== -1
            || state === 'reservacion-proxima') {
            return 'Reserva próxima';
        }
        var labels = {
            libre: 'Disponible',
            ocupada: 'Ocupada',
            'reservacion-proxima': 'Reserva próxima',
            'no-utilizable': 'No utilizable'
        };
        return labels[state] || state;
    }

    function toMapVisual(raw, options) {
        raw = raw || {};
        options = options || {};

        var visualStateValue = visualValue(raw, options);
        var previousState = previousVisualState(raw, options);
        var visualContract = window.MapaContrato.validarEstado(visualStateValue, previousState);
        var inheritedSelection = window.MapaContrato.identificarSeleccionHeredada(
            visualStateValue,
            previousState
        );
        var contractValid = visualContract.valido
            && modifiersValid(raw, options);
        var stateBase = String(options.estadoBase || raw.estado_base || raw.estadoBase || '');
        var fallback = window.MapaContrato.fallbackSeguro();
        var estadoNoVerificado = booleanValue(options.estadoNoVerificado)
            || booleanValue(raw.estadoNoVerificado)
            || !contractValid;
        var estadoVisual = estadoNoVerificado ? fallback.estadoVisual : visualContract.estadoVisual;
        var seleccionValidaSolicitada = selectionValidity(raw, options)
            && !estadoNoVerificado
            && estadoVisual !== 'no-utilizable';
        var seleccionValida = seleccionValidaSolicitada;
        var selected = (options.seleccionActual != null
            ? booleanValue(options.seleccionActual)
            : booleanValue(raw.seleccion_actual)) || inheritedSelection;
        selected = selected && seleccionValida;

        var modifiers = estadoNoVerificado
            ? fallback.modificadores.slice()
            : uniqueStrings((raw.modificadores || []).concat(options.modificadores || []));
        if (selected && modifiers.indexOf('seleccion_actual') === -1) {
            modifiers.push('seleccion_actual');
        }
        if (estadoNoVerificado) {
            modifiers = modifiers.filter(function (modifier) {
                return modifier !== 'seleccion_actual' && modifier !== 'seleccionada';
            });
        }

        var stateClasses = normalizeClasses(options.clasesEstado || []);
        if (estadoNoVerificado) {
            stateClasses = stateClasses.filter(function (className) {
                return !/(^|[-_])(selected|seleccionada|highlight)([-_]|$)/i.test(className);
            });
        }

        var interactiveRequested = options.interactivo != null
            ? booleanValue(options.interactivo)
            : (raw.interactivo != null && booleanValue(raw.interactivo));
        var visualModifiers = modifiers.slice();
        var classes = stateClasses;
        var customAttributes = Object.assign({}, options.atributos || {});
        var attributes = normalizeAttributes(Object.assign({
            'data-estado-base': stateBase,
            'data-modificadores': visualModifiers.join(' ')
        }, customAttributes));
        var tipo = String(raw.tipo || 'mesa').toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'mesa';
        var name = String(raw.etiqueta || raw.nombre || '');

        return {
            id: parseInt(raw.id || '0', 10),
            numero: raw.numero == null ? '' : String(raw.numero),
            nombre: name,
            tipo: tipo,
            estadoBase: stateBase,
            estadoNoVerificado: estadoNoVerificado,
            x: clampPercent(options.x != null ? options.x : raw.pos_x, 50),
            y: clampPercent(options.y != null ? options.y : raw.pos_y, 50),
            ancho: numberOrNull(options.ancho != null ? options.ancho : raw.ancho),
            alto: numberOrNull(options.alto != null ? options.alto : raw.alto),
            reservable: booleanValue(raw.reservable),
            activo: raw.activo == null ? null : booleanValue(raw.activo),
            capacidad: Math.max(0, parseInt(raw.capacidad || '0', 10) || 0),
            motivoBloqueo: String(raw.motivo_bloqueo || raw.motivoBloqueo || ''),
            seleccionada: estadoNoVerificado ? fallback.seleccionada : selected,
            interactivoSolicitado: interactiveRequested,
            interactivo: estadoNoVerificado || estadoVisual === 'no-utilizable'
                ? false
                : interactiveRequested,
            independienteDeConsulta: booleanValue(options.independienteDeConsulta)
                || booleanValue(raw.independienteDeConsulta),
            titulo: String(options.titulo || raw.titulo || raw.nombre || ('Mesa ' + (raw.id || ''))),
            ariaLabel: String(options.ariaLabel || raw.titulo_mapa || raw.aria_label || ''),
            etiquetaEstado: estadoNoVerificado
                ? 'Estado no verificado'
                : visualLabel(estadoVisual, visualModifiers, raw, options),
            subtitulo: String(options.subtitulo || raw.subtitulo || (
                estadoVisual === 'no-utilizable' ? raw.motivo_bloqueo || raw.motivoBloqueo || '' : ''
            )),
            modificadores: visualModifiers,
            estadoVisual: estadoVisual,
            clasesEstado: classes,
            seleccionValida: seleccionValida && !estadoNoVerificado,
            seleccionValidaSolicitada: seleccionValidaSolicitada,
            atributos: attributes
        };
    }

    window.MesaEstadoAdapter = {
        fusionar: merge,
        paraMapaVisual: toMapVisual
    };
})();
