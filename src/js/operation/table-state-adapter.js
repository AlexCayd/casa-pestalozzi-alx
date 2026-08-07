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
        'seleccionada',
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
        var state = String(value || 'disponible').toLowerCase();
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

        return aliases[state] || 'disponible';
    }

    function hasModifier(modifiers, name) {
        return modifiers.indexOf(name) !== -1;
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
        return aliases[state] || 'libre';
    }

    function ticketBloqueaConsulta(raw, options, modifiers) {
        var ticket = raw.ticket_abierto;
        if (ticket && typeof ticket === 'object'
            && Object.prototype.hasOwnProperty.call(ticket, 'bloquea_en_consulta')) {
            return booleanValue(ticket.bloquea_en_consulta);
        }
        if (raw.bloquea_en_consulta != null) {
            return booleanValue(raw.bloquea_en_consulta);
        }
        if (options.ticketAbierto && typeof options.ticketAbierto === 'object'
            && Object.prototype.hasOwnProperty.call(options.ticketAbierto, 'bloquea_en_consulta')) {
            return booleanValue(options.ticketAbierto.bloquea_en_consulta);
        }
        return Boolean(raw.ticket_abierto || options.ticketAbierto)
            || hasModifier(modifiers, 'ticket_abierto');
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

    /**
     * Resuelve la apariencia sin mezclar estados ni crear etiquetas auxiliares:
     * seleccion valida, ticket/ocupacion, reservacion proxima, no utilizable
     * y disponible.
     */
    function resolverEstadoVisualMesa(raw, options) {
        raw = raw || {};
        options = options || {};
        var modifiers = uniqueStrings((raw.modificadores || []).concat(options.modificadores || []));
        var state = baseState(options.estadoBase || raw.estado_base || raw.estadoBase || raw.estado);
        var selected = options.seleccionActual != null
            ? booleanValue(options.seleccionActual)
            : booleanValue(raw.seleccion_actual);
        var selectionValid = options.seleccionValida !== false;
        var hasTicket = ticketBloqueaConsulta(raw, options, modifiers);
        var hasPendingAbsence = hasModifier(modifiers, 'accion_pendiente') ||
            String(raw.accion_pendiente || options.accionPendiente || '') === 'REGISTRAR_AUSENCIA';
        var hasUpcomingReservation = Boolean(raw.reservacion_proxima || options.reservacionProxima) ||
            hasModifier(modifiers, 'reservacion_proxima') || state === 'bloqueada' || state === 'proxima';
        // `estado_visual_mapa` pertenece a la proyección administrativa. El
        // adaptador también se comparte con POS, por lo que sólo se consume
        // cuando la pantalla objetivo lo entrega explícitamente.
        var explicitVisualState = normalizeVisualState(options.estadoVisual);
        var hasExplicitVisualState = Boolean(options.estadoVisual);
        var unusable = isUnusable(raw, options, state);

        // Una mesa no reservable nunca puede quedar seleccionada, aunque el
        // consumidor haya enviado una intención stale o una opción incompleta.
        if (unusable) return 'no-utilizable';

        // Un ticket abierto conserva la base física roja. La selección se
        // expresa como modificador/ring, no sustituyendo ese estado.
        if (hasTicket || state === 'ocupada') return 'ocupada';
        if (selected && selectionValid) return 'seleccionada';
        // La ausencia pendiente no reemplaza la disponibilidad física: el
        // fondo sigue siendo verde y el borde se expresa mediante el
        // modificador `accion_pendiente`.
        if (hasPendingAbsence) return 'libre';
        if (hasExplicitVisualState) return explicitVisualState;
        if (hasUpcomingReservation) return 'reservacion-proxima';
        return 'libre';
    }

    function toMapVisual(raw, options) {
        raw = raw || {};
        options = options || {};
        var stateBase = String(
            options.estadoBase || raw.estado_base || raw.estadoBase || 'disponible'
        );
        var modifiers = uniqueStrings(
            (raw.modificadores || []).concat(options.modificadores || [])
        );
        var selected = options.seleccionActual != null
            ? booleanValue(options.seleccionActual)
            : booleanValue(raw.seleccion_actual);
        var noUtilizable = isUnusable(raw, options, stateBase);
        var seleccionValida = options.seleccionValida !== false && !noUtilizable;
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
                ? booleanValue(options.interactivo)
                : booleanValue(raw.reservable),
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
