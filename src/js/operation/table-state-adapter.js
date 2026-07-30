/**
 * Adapta el contrato operativo del backend al contrato de dibujo de MapaVisual.
 *
 * No calcula disponibilidad: sólo traduce nombres, agrega clases de
 * modificadores e incorpora opciones propias de cada pantalla.
 */
(function () {
    var BASE_STATE_MAP = {
        disponible: 'libre',
        ocupada: 'ocupada',
        bloqueada: 'bloqueada',
        no_reservable: 'no-reservable'
    };

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
        merged.indicadores = (base.indicadores || []).concat(overlay.indicadores || []);
        return merged;
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
        if (selected && modifiers.indexOf('seleccion_actual') === -1) {
            modifiers.push('seleccion_actual');
        }

        return {
            id: parseInt(raw.id || '0', 10),
            numero: raw.numero,
            nombre: String(raw.etiqueta || raw.nombre || ''),
            tipo: String(raw.tipo || 'mesa'),
            estadoBase: stateBase,
            estadoVisual: BASE_STATE_MAP[stateBase] || 'libre',
            x: options.x != null ? options.x : raw.pos_x,
            y: options.y != null ? options.y : raw.pos_y,
            ancho: options.ancho != null ? options.ancho : raw.ancho,
            alto: options.alto != null ? options.alto : raw.alto,
            reservable: booleanValue(raw.reservable),
            capacidad: parseInt(raw.capacidad || '0', 10) || 0,
            seleccionada: selected,
            interactivo: options.interactivo != null
                ? booleanValue(options.interactivo)
                : booleanValue(raw.reservable),
            titulo: String(options.titulo || raw.titulo || raw.nombre || ''),
            modificadores: modifiers,
            indicadores: (raw.indicadores || []).concat(options.indicadores || []),
            clasesEstado: modifiers.map(modifierClass).concat(options.clasesEstado || []),
            atributos: Object.assign({
                'data-estado-base': stateBase,
                'data-modificadores': modifiers.join(' ')
            }, options.atributos || {})
        };
    }

    window.MesaEstadoAdapter = {
        fusionar: merge,
        paraMapaVisual: toMapVisual
    };
})();
