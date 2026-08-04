/**
 * Card visual unica para los drawers de Mapa y Operacion.
 * No selecciona ni modifica reservaciones; cada controlador conserva su flujo.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeClass(value) {
        return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '');
    }

    function safeClassList(value) {
        return String(value || '').split(/\s+/).map(safeClass).filter(Boolean).join(' ');
    }

    function defaultStateLabel(value) {
        var label = String(value || 'confirmada').replace(/_/g, ' ');
        return label.charAt(0).toUpperCase() + label.slice(1);
    }

    function pluralPeople(value) {
        var count = Math.max(0, parseInt(value || '0', 10) || 0);
        return count + (count === 1 ? ' persona' : ' personas');
    }

    function render(reservation, options) {
        reservation = reservation || {};
        options = options || {};

        var id = Math.max(0, parseInt(reservation.id || '0', 10) || 0);
        var state = safeClass(options.estado || reservation.estado || 'confirmada') || 'confirmada';
        var stateLabel = options.estadoLabel || defaultStateLabel(state);
        var hour = String(options.hora || reservation.hora || '').slice(0, 5) || '--:--';
        var customer = options.cliente || reservation.nombre || 'Sin nombre';
        var people = options.comensales == null ? reservation.comensales : options.comensales;
        var contact = options.contacto || reservation.contacto_visible || reservation.contacto || 'Sin contacto';
        var origin = options.origen || reservation.origen_visible || '';
        var note = options.nota || reservation.nota_breve || '';
        var tables = Array.isArray(options.mesas)
            ? options.mesas
            : (Array.isArray(reservation.mesas_asignadas) ? reservation.mesas_asignadas : []);
        var articleClasses = ['reservation-operation-card', 'reserva-card'];

        (options.clases || []).forEach(function (className) {
            className = safeClass(className);
            if (className) {
                articleClasses.push(className);
            }
        });

        var tableMarkup = tables.slice(0, 3).map(function (name) {
            return '<span>' + escapeHtml(name) + '</span>';
        }).join('');

        if (tables.length > 3) {
            tableMarkup += '<span>+' + (tables.length - 3) + '</span>';
        }
        if (!tableMarkup) {
            tableMarkup = '<span class="reservation-operation-card__muted">Sin mesas asignadas</span>';
        }

        var secondaryMeta = '';
        if (options.meta && options.meta.label) {
            secondaryMeta = '<span class="reservation-operation-card__secondary ' + safeClassList(options.meta.className) + '">' + escapeHtml(options.meta.label) + '</span>';
        } else if (options.mostrarSinMesas && !tables.length) {
            secondaryMeta = '<span class="reservation-operation-card__secondary reservation-operation-card__secondary--warning">Sin asignar</span>';
        }

        var accessibleLabel = 'Reservación ' + id + ', ' + customer + ', ' + hour + ', ' + pluralPeople(people) + ', estado ' + stateLabel;
        if (tables.length) {
            accessibleLabel += ', mesas ' + tables.join(', ');
        } else {
            accessibleLabel += ', sin mesas asignadas';
        }

        return '<article class="' + articleClasses.join(' ') + '" data-id="' + id + '">' +
            '<button type="button" class="reservation-operation-card__button" data-operation-reservation="' + id + '" aria-label="' + escapeHtml(accessibleLabel) + '" aria-pressed="' + (options.seleccionada ? 'true' : 'false') + '">' +
                '<div class="reservation-operation-card__head">' +
                    '<time class="reservation-operation-card__time">' + escapeHtml(hour) + '</time>' +
                    '<span class="reservations-table__status reservations-table__status--' + state + '">' + escapeHtml(stateLabel) + '</span>' +
                '</div>' +
                '<div class="reservation-operation-card__customer"><strong>' + escapeHtml(customer) + '</strong></div>' +
                '<div class="reservation-operation-card__meta"><span>' + escapeHtml(pluralPeople(people)) + '</span>' + secondaryMeta + '</div>' +
                (options.mostrarContextoAdmin ? '<div class="reservation-operation-card__admin-meta"><span>' + escapeHtml(contact) + '</span>' +
                    (origin ? '<span>' + escapeHtml(origin) + '</span>' : '') + '</div>' : '') +
                (options.mostrarContextoAdmin && note ? '<p class="reservation-operation-card__note">' + escapeHtml(note) + '</p>' : '') +
                '<div class="reservation-operation-card__tables">' + tableMarkup + '</div>' +
            '</button>' +
        '</article>';
    }

    function create(reservation, options) {
        var template = document.createElement('template');
        template.innerHTML = render(reservation, options).trim();
        return template.content.firstElementChild;
    }

    window.OperationalReservationCard = {
        render: render,
        create: create
    };
})();
