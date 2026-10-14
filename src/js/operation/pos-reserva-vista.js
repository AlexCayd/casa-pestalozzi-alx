/**
 * Presentación compartida de una reservación en el POS: la usan el cajón, la
 * ficha y la barra de contexto, para que una misma reserva diga lo mismo en
 * los tres sitios. No consulta nada ni decide permisos: traduce lo que el
 * servidor ya resolvió (estado, ventana, minutos, banderas puede_*).
 */
(function (window) {
    'use strict';

    var ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

    function esc(valor) {
        return String(valor == null ? '' : valor).replace(/[&<>"']/g, function (c) { return ESCAPES[c]; });
    }

    function icono(nombre, tamano) {
        return window.AdminIcons && typeof window.AdminIcons.get === 'function'
            ? window.AdminIcons.get(nombre, tamano || 14)
            : '';
    }

    function hhmm(valor) {
        var m = String(valor || '').match(/(\d{2}):(\d{2})/);
        return m ? m[1] + ':' + m[2] : '';
    }

    function minutosDe(valor) {
        var m = String(valor || '').match(/^(\d{2}):(\d{2})/);
        return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
    }

    function ids(reserva) {
        return (Array.isArray(reserva && reserva.mesa_ids) ? reserva.mesa_ids : [])
            .map(function (id) { return parseInt(id, 10); })
            .filter(function (id) { return id > 0; });
    }

    /*
     * «Mesa 6», «Mesas 3 y 4» o «Mesas 8 a 11» si son tres o más seguidas por
     * número. `mesaPorId` lo pone quien llama (el POS tiene el plano cargado).
     */
    function nombresMesas(mesaIds, mesaPorId) {
        var mesas = (mesaIds || []).map(function (id) {
            var mesa = typeof mesaPorId === 'function' ? mesaPorId(id) : null;
            return { id: id, nombre: mesa && mesa.nombre ? String(mesa.nombre) : 'Mesa ' + id, numero: mesa ? parseInt(mesa.numero, 10) : NaN };
        });
        if (!mesas.length) return '';
        // Con todas llamadas «Mesa N», se nombran por tramos: «Mesas 2 a 6 y
        // 8 a 11» en vez de nueve «Mesa N» seguidos, que en la barra de un
        // grupo grande ocupaban una línea entera y había que leerlos uno a
        // uno. Un tramo de dos se enumera («Mesas 3 y 4»): un rango entre
        // vecinas no ahorra nada. Si alguna se llama de otra forma, van sus
        // nombres.
        //
        // «a» y no la raya «–»: la frase sale en avisos y en la barra, que son
        // role="status", y NVDA en español no pronuncia la raya con la
        // puntuación por defecto — «Mesas 3–11» sonaba «Mesas 3 11».
        var numerables = mesas.length > 1 && mesas.every(function (m) {
            return !isNaN(m.numero) && m.nombre === 'Mesa ' + m.numero;
        });
        if (!numerables) return mesas.map(function (m) { return m.nombre; }).join(' · ');
        var numeros = mesas.map(function (m) { return m.numero; })
            .sort(function (a, b) { return a - b; })
            .filter(function (n, i, todos) { return i === 0 || n !== todos[i - 1]; });
        var tramos = [];
        numeros.forEach(function (n) {
            var ultimo = tramos[tramos.length - 1];
            if (ultimo && n === ultimo[1] + 1) ultimo[1] = n;
            else tramos.push([n, n]);
        });
        var partes = [];
        tramos.forEach(function (t) {
            if (t[1] - t[0] >= 2) partes.push(t[0] + ' a ' + t[1]);
            else for (var n = t[0]; n <= t[1]; n++) partes.push(String(n));
        });
        var lista = partes.length > 1
            ? partes.slice(0, -1).join(', ') + ' y ' + partes[partes.length - 1]
            : partes[0];
        return 'Mesas ' + lista;
    }

    var TERMINALES = { cancelada: 'Cancelada', no_show: 'No llegó', completada: 'Atendida' };

    function estaResuelta(reserva) {
        return Object.prototype.hasOwnProperty.call(TERMINALES, String(reserva && reserva.estado || ''));
    }

    function enMesa(reserva) {
        return String(reserva && reserva.estado || '') === 'en_curso' || (reserva && reserva.ticket_abierto === true);
    }

    function minutos(valor) {
        var n = parseInt(valor, 10);
        return isNaN(n) || n < 0 ? 0 : n;
    }

    /*
     * Cuánto tiempo, para una señal de 24 px: «8 min», «1 h 05», y nada a
     * partir de un día. Con minutos a secas, una reserva de ayer sin registrar
     * decía «Sin llegar · 1566 min» — una cifra que nadie convierte de cabeza
     * y que no cabe en la tarjeta. Pasado un día el dato que sirve es «Sin
     * llegar», no cuánto.
     */
    function duracion(n) {
        if (!n || n >= 1440) return null;
        if (n < 60) return { html: '<b>' + n + '</b> min', texto: n + (n === 1 ? ' minuto' : ' minutos') };
        var h = Math.floor(n / 60);
        var m = n % 60;
        return {
            html: '<b>' + h + '</b> h' + (m ? ' <b>' + ('0' + m).slice(-2) + '</b>' : ''),
            texto: h + (h === 1 ? ' hora' : ' horas') + (m ? ' ' + m + ' minutos' : '')
        };
    }

    /*
     * La señal de TIEMPO de una reserva, del reloj real (la lista es del día,
     * no del instante proyectado). Devuelve {texto, html, variante, icono} o
     * null cuando no hay nada que decir (futura a más de una hora).
     *
     * La variante es lo único que pone color, y sale del pin: «reservada» es
     * el azul de la mesa reservada y «vencida» el azul hondo con que el modal
     * de mesa marca la tolerancia vencida. La tolerancia sigue en azul y no
     * pasa a ámbar: es justo cuando el anfitrión cruza la tarjeta con el
     * plano, y el pin sigue azul. La urgencia sube por intensidad, no por
     * familia. Sin variante, la señal es neutra (en mesa, apartada, resuelta).
     */
    function senalTiempo(reserva) {
        var estado = String(reserva && reserva.estado || '');
        if (TERMINALES[estado]) return { variante: '', icono: '', html: esc(TERMINALES[estado]), texto: TERMINALES[estado] };
        if (enMesa(reserva)) {
            var apertura = hhmm(reserva.ticket && reserva.ticket.hora_apertura);
            return { variante: '', icono: 'check', html: 'En mesa' + (apertura ? ' · <b>' + esc(apertura) + '</b>' : ''), texto: 'En mesa' + (apertura ? ' desde las ' + apertura : '') };
        }
        if (estado === 'pendiente_verificacion') return { variante: '', icono: 'reloj', html: 'Apartada', texto: 'Apartada' };
        if (estado !== 'confirmada') return null;

        var ventana = String(reserva.ventana_pos || reserva.ventana_operativa || '');
        // Tolerancia vencida: dura lo que tarda la siguiente lectura en
        // registrar la ausencia sola. No es un estado que haya que atender.
        if (reserva.ausencia_pendiente === true || ventana === 'ausencia_pendiente') {
            return { variante: 'vencida', icono: 'reloj', html: 'Se libera', texto: 'Tolerancia vencida: se registra la ausencia' };
        }
        if (ventana === 'tolerancia') {
            var espera = duracion(minutos(reserva.minutos_retraso));
            return { variante: 'reservada', icono: 'estado-reservada', html: 'Esperando' + (espera ? ' · ' + espera.html : ''), texto: 'Esperando' + (espera ? ', ' + espera.texto : '') };
        }
        if (ventana === 'inicio') return { variante: 'reservada', icono: 'estado-reservada', html: 'Llega ahora', texto: 'Llega ahora' };
        if (ventana === 'advertencia' || ventana === 'bloqueo') {
            var faltan = minutos(reserva.minutos_para_inicio);
            return { variante: 'reservada', icono: 'estado-reservada', html: 'en <b>' + faltan + '</b> min', texto: 'Llega en ' + faltan + ' minutos' };
        }
        return null;
    }

    function senalHtml(senal) {
        if (!senal) return '';
        return '<span class="pos-senal' + (senal.variante ? ' pos-senal--' + senal.variante : '') + '">'
            + (senal.icono ? icono(senal.icono, 14) : '') + '<span>' + senal.html + '</span></span>';
    }

    /*
     * Señales secundarias de la tarjeta: reubicada y tamaño. «Grupo grande»
     * (mayor al máximo de la página) sustituye a «Exterior completo».
     */
    function senalesExtra(reserva, opciones) {
        opciones = opciones || {};
        var extra = [];
        // Un cambio de horario la dejó fuera: el mismo indicador que el buzón,
        // para que el anfitrión no la siente sin revisar el seguimiento. Es
        // la única señal extra con color —ámbar en la tinta, sin relleno,
        // como «Sin mesa asignada»—, porque es la única que pide algo.
        if (reserva.fuera_horario_operacion === true) {
            extra.push({ variante: 'aviso', icono: 'alerta', html: 'Fuera de horario', texto: 'Fuera de horario de operación' });
        }
        if (reserva.reubicada === true) extra.push({ variante: '', icono: 'reubicar', html: 'Reubicada', texto: 'Reubicada' });
        var maximo = parseInt(opciones.maxComensales, 10) || 0;
        var comensales = parseInt(reserva.comensales, 10) || 0;
        // Sin icono: en la tarjeta van justo detrás de las personas, que ya
        // llevan el de grupo, y dos siluetas seguidas se leían como dos datos.
        if (maximo > 0 && comensales > maximo) {
            extra.push({ variante: '', icono: '', html: 'Grupo grande', texto: 'Grupo grande' });
        } else if (opciones.esBloque && opciones.esBloque(ids(reserva))) {
            extra.push({ variante: '', icono: '', html: 'Exterior completo', texto: 'Exterior completo' });
        }
        return extra;
    }

    var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    var DIAS_CORTOS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    // Tablas a mano y no toLocaleDateString: el idioma de una tablet nueva no
    // es una garantía (CLAUDE.md, «El cajón»).
    function partesFecha(iso) {
        var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        var fecha = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        return { dia: fecha.getUTCDay(), numero: +m[3], mes: +m[2] - 1 };
    }

    function fechaLarga(iso) {
        var p = partesFecha(iso);
        if (!p) return String(iso || '');
        var dia = DIAS[p.dia];
        return dia.charAt(0).toUpperCase() + dia.slice(1) + ' ' + p.numero + ' de ' + MESES[p.mes];
    }

    function fechaCorta(iso) {
        var p = partesFecha(iso);
        return p ? DIAS_CORTOS[p.dia] + ' ' + p.numero + ' ' + MESES_CORTOS[p.mes] : String(iso || '');
    }

    function plural(n, uno, varios) {
        return n + ' ' + (n === 1 ? uno : varios);
    }

    window.PosReservaVista = {
        esc: esc,
        icono: icono,
        hhmm: hhmm,
        minutosDe: minutosDe,
        ids: ids,
        nombresMesas: nombresMesas,
        estaResuelta: estaResuelta,
        enMesa: enMesa,
        senalTiempo: senalTiempo,
        senalesExtra: senalesExtra,
        senalHtml: senalHtml,
        fechaLarga: fechaLarga,
        fechaCorta: fechaCorta,
        plural: plural
    };
})(window);
