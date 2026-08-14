/**
 * Histórico de precios en la ficha de un platillo o de un ingrediente.
 *
 * Vive en core y no en el módulo de inventario porque lo usan dos pantallas de
 * módulos distintos (Menú e Inventario), y el panel sólo carga admin.js.
 *
 * El modal lo abre y lo cierra admin.js: aquí sólo se pide el dato y se pinta.
 */
(function () {
    'use strict';

    var disparadores = document.querySelectorAll('[data-historial-precios]');
    if (!disparadores.length) {
        return;
    }

    var modal = document.getElementById('historial-precios-modal');
    if (!modal) {
        return;
    }

    var cuerpo = modal.querySelector('[data-historial-cuerpo]');
    var subtitulo = modal.querySelector('[data-historial-subtitulo]');

    var MOTIVOS = {
        alta: 'Alta',
        edicion: 'Edición',
        proveedor: 'Cambio de proveedor'
    };

    function escapar(valor) {
        var div = document.createElement('div');
        div.textContent = String(valor == null ? '' : valor);
        return div.innerHTML;
    }

    function dinero(valor) {
        // Cuatro decimales sólo cuando los hay: el costo de un insumo se mide en
        // diezmilésimas por gramo, pero el precio de un platillo son pesos y
        // "$120.0000" se lee peor que "$120.00".
        var decimales = Math.abs(valor * 100 - Math.round(valor * 100)) > 0.0001 ? 4 : 2;
        return '$' + valor.toFixed(decimales);
    }

    function fecha(iso) {
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) {
            return escapar(iso);
        }
        return d.toLocaleDateString('es-MX', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function pintar(cambios) {
        if (!cambios.length) {
            cuerpo.innerHTML = '<p class="admin-field__hint">Todavía no hay cambios registrados. ' +
                'El histórico empieza a llenarse a partir del próximo cambio de precio.</p>';
            return;
        }

        var h = '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>' +
            '<th>Fecha</th><th class="admin-table__num">Antes</th>' +
            '<th class="admin-table__num">Después</th><th>Motivo</th><th>Quién</th>' +
            '</tr></thead><tbody>';

        for (var i = 0; i < cambios.length; i++) {
            var c = cambios[i];
            var sube = c.anterior !== null && c.nuevo > c.anterior;
            var baja = c.anterior !== null && c.nuevo < c.anterior;
            var flecha = sube ? ' ▲' : (baja ? ' ▼' : '');
            var tono = sube ? 'danger' : (baja ? 'success' : 'neutral');

            h += '<tr>';
            h += '<td><span class="admin-table__cell-sub">' + fecha(c.fecha) + '</span></td>';
            h += '<td class="admin-table__num">' + (c.anterior === null ? '—' : dinero(c.anterior)) + '</td>';
            h += '<td class="admin-table__num"><strong>' + dinero(c.nuevo) + '</strong>' +
                 '<span class="admin-badge admin-badge--' + tono + '">' +
                 escapar(porcentaje(c)) + flecha + '</span></td>';
            h += '<td>' + escapar(MOTIVOS[c.motivo] || c.motivo) +
                 (c.proveedor ? ' · ' + escapar(c.proveedor) : '') + '</td>';
            h += '<td><span class="admin-table__cell-sub">' + escapar(c.usuario || 'Sistema') + '</span></td>';
            h += '</tr>';
        }

        cuerpo.innerHTML = h + '</tbody></table></div>';
    }

    function porcentaje(cambio) {
        if (cambio.anterior === null || cambio.anterior === 0) {
            return 'Alta';
        }
        var variacion = ((cambio.nuevo - cambio.anterior) / cambio.anterior) * 100;
        return (variacion > 0 ? '+' : '') + variacion.toFixed(1) + '%';
    }

    function abrir(disparador) {
        var entidad = disparador.getAttribute('data-historial-entidad');
        var id = disparador.getAttribute('data-historial-id');
        var titulo = disparador.getAttribute('data-historial-titulo') || '';

        subtitulo.textContent = titulo;
        cuerpo.innerHTML = '<p class="admin-field__hint">Cargando…</p>';

        document.dispatchEvent(new CustomEvent('admin:open-modal', {
            detail: { id: 'historial-precios-modal', trigger: disparador }
        }));

        fetch('/admin/api/historial-precios?entidad=' + encodeURIComponent(entidad) +
              '&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (respuesta) {
                return respuesta.ok ? respuesta.json() : null;
            })
            .then(function (datos) {
                if (!datos || !datos.ok) {
                    cuerpo.innerHTML = '<p class="admin-field__hint">No se pudo cargar el histórico.</p>';
                    return;
                }
                pintar(datos.cambios || []);
                // La tabla acaba de cambiar el alto del diálogo; sin remedir,
                // Lenis conserva el límite de antes.
                if (window.AdminScrollLock) {
                    window.AdminScrollLock.remedir();
                }
            })
            .catch(function () {
                cuerpo.innerHTML = '<p class="admin-field__hint">No se pudo cargar el histórico.</p>';
            });
    }

    for (var i = 0; i < disparadores.length; i++) {
        (function (disparador) {
            disparador.addEventListener('click', function () {
                abrir(disparador);
            });
        })(disparadores[i]);
    }
})();
