/*
 * KDS admin de areas de produccion.
 * Mantiene polling y transiciones de estado del modulo legacy con endpoints admin.
 */
(function () {
    function initAdminArea() {
        if (!window.CP_ADMIN_AREA || initAdminArea.done) {
            return;
        }

        initAdminArea.done = true;

        var areaId = window.CP_ADMIN_AREA.id;
        var pollTimer = null;
        var endpoints = {
            items: '/admin/api/area-items?area_id=' + encodeURIComponent(areaId),
            advance: '/admin/api/advance-item',
            rollback: '/admin/api/rollback-item'
        };

        var listEnv = document.getElementById('list-enviados');
        var listPrep = document.getElementById('list-prep');
        var listListo = document.getElementById('list-listo');
        var countEnv = document.getElementById('count-enviados');
        var countPrep = document.getElementById('count-prep');
        var countListo = document.getElementById('count-listo');
        var refreshInfo = document.getElementById('area-refresh-info');

        if (!listEnv || !listPrep || !listListo) {
            return;
        }

        function escHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function minutesSince(timestamp) {
            var date = new Date(String(timestamp).replace(' ', 'T'));
            var diff = Math.floor((Date.now() - date.getTime()) / 60000);
            return diff < 0 || Number.isNaN(diff) ? 0 : diff;
        }

        function setRefresh(text, mode) {
            if (!refreshInfo) {
                return;
            }

            refreshInfo.classList.toggle('is-error', mode === 'error');
            refreshInfo.innerHTML = '<span class="admin-area__live-dot" aria-hidden="true"></span>' + escHtml(text);
        }

        function emptyState(text) {
            return '<div class="admin-area-empty"><span>' + escHtml(text) + '</span></div>';
        }

        function loadItems() {
            fetch(endpoints.items)
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data.ok) {
                        setRefresh('Error al cargar', 'error');
                        return;
                    }

                    renderBoard(data.items || []);

                    var now = new Date();
                    setRefresh('Actualizado ' + now.toLocaleTimeString('es-MX', {
                        hour: '2-digit',
                        minute: '2-digit'
                    }));
                })
                .catch(function () {
                    setRefresh('Error al cargar', 'error');
                });
        }

        function renderBoard(items) {
            var byTicket = {};
            var ticketOrder = [];

            items.forEach(function (item) {
                if (!byTicket[item.ticket_id]) {
                    byTicket[item.ticket_id] = {
                        ticket_id: item.ticket_id,
                        mesa_nombre: item.mesa_nombre,
                        ticket_nombre: item.ticket_nombre,
                        enviados: [],
                        prep: [],
                        listos: []
                    };
                    ticketOrder.push(item.ticket_id);
                }

                if (item.estado === 'enviado') {
                    byTicket[item.ticket_id].enviados.push(item);
                } else if (item.estado === 'en_preparacion') {
                    byTicket[item.ticket_id].prep.push(item);
                } else if (item.estado === 'listo' || item.estado === 'entregado') {
                    // Lo entregado por el mesero se queda en Listos: el
                    // registro no debe desaparecer del tablero.
                    byTicket[item.ticket_id].listos.push(item);
                }
            });

            var envCards = [];
            var prepCards = [];
            var listoCards = [];
            var envCount = 0;
            var prepCount = 0;
            var listoCount = 0;

            ticketOrder.forEach(function (ticketId) {
                var group = byTicket[ticketId];

                if (group.enviados.length) {
                    envCards.push(buildCard(group, group.enviados, 'enviado'));
                    envCount += group.enviados.length;
                }

                if (group.prep.length) {
                    prepCards.push(buildCard(group, group.prep, 'prep'));
                    prepCount += group.prep.length;
                }

                if (group.listos.length) {
                    listoCards.push(buildCard(group, group.listos, 'listo'));
                    // Mide trabajo por recoger, no filas visibles: lo ya
                    // entregado no cuenta.
                    group.listos.forEach(function (item) {
                        if (item.estado === 'listo') {
                            listoCount += 1;
                        }
                    });
                }
            });

            listEnv.innerHTML = envCards.length ? envCards.join('') : emptyState('Sin pedidos');
            listPrep.innerHTML = prepCards.length ? prepCards.join('') : emptyState('Sin pedidos');
            listListo.innerHTML = listoCards.length ? listoCards.join('') : emptyState('Sin pedidos');

            if (countEnv) {
                countEnv.textContent = envCount;
            }
            if (countPrep) {
                countPrep.textContent = prepCount;
            }
            if (countListo) {
                countListo.textContent = listoCount;
            }

            bindActions();
        }

        function buildCard(group, itemList, colType) {
            // La antigüedad se mide sobre lo pendiente. Con ORDER BY created_at
            // ASC el más viejo suele ser el ya entregado, y tomarlo pintaría la
            // tarjeta en rojo por trabajo que ya está hecho.
            var pendiente = null;
            itemList.forEach(function (item) {
                if (!pendiente && item.estado !== 'entregado') {
                    pendiente = item;
                }
            });

            var minutes = minutesSince((pendiente || itemList[0]).created_at);
            var urgencyClass = !pendiente
                ? ''
                : (minutes >= 10
                    ? ' admin-area-card-kds--urgente'
                    : (minutes >= 5 ? ' admin-area-card-kds--alerta' : ''));
            var mesaText = escHtml(group.mesa_nombre);
            var clientText = group.ticket_nombre
                ? '<span class="admin-area-card-kds__client"> - ' + escHtml(group.ticket_nombre) + '</span>'
                : '';
            var timeText = minutes === 0 ? 'ahora' : 'hace ' + minutes + ' min';
            var html = '';

            html += '<article class="admin-area-card-kds' + urgencyClass + '">';
            html += '<header class="admin-area-card-kds__head">';
            html += '<span class="admin-area-card-kds__mesa">' + mesaText + clientText + '</span>';
            html += '<span class="admin-area-card-kds__time">' + escHtml(timeText) + '</span>';
            html += '</header>';
            html += '<div class="admin-area-card-kds__items">';

            itemList.forEach(function (item) {
                var comensalLabel = item.comensal !== null ? 'C.' + item.comensal : 'GL';
                // La columna de Listos es mixta: el estado se decide por ítem,
                // no por columna. Lo entregado ya no admite ninguna acción.
                var entregado = item.estado === 'entregado';
                var hasBack = !entregado && (colType === 'prep' || colType === 'listo');
                var hasForward = !entregado && (colType === 'enviado' || colType === 'prep');

                html += '<div class="admin-area-card-kds__item' +
                    (entregado ? ' admin-area-card-kds__item--entregado' : '') + '">';
                html += '<div class="admin-area-card-kds__item-info">';
                html += '<span class="admin-area-card-kds__qty">x' + escHtml(item.cantidad) + '</span>';
                html += '<span class="admin-area-card-kds__name">' + escHtml(item.nombre) + '</span>';
                html += '<span class="admin-area-card-kds__com">' + escHtml(comensalLabel) + '</span>';
                html += '</div>';

                if (entregado) {
                    html += '<div class="admin-area-card-kds__actions">';
                    html += '<span class="admin-area-card-kds__badge admin-area-card-kds__badge--entregado">Entregado</span>';
                    html += '</div>';
                } else if (hasBack || hasForward) {
                    html += '<div class="admin-area-card-kds__actions">';

                    if (hasBack) {
                        html += '<button class="admin-area-card-kds__btn admin-area-card-kds__btn--back" data-id="' +
                            escHtml(item.id) + '" data-dir="back">Devolver</button>';
                    }

                    if (colType === 'enviado') {
                        html += '<button class="admin-area-card-kds__btn admin-area-card-kds__btn--prep" data-id="' +
                            escHtml(item.id) + '" data-dir="fwd">Prep</button>';
                    } else if (colType === 'prep') {
                        html += '<button class="admin-area-card-kds__btn admin-area-card-kds__btn--listo" data-id="' +
                            escHtml(item.id) + '" data-dir="fwd">Listo</button>';
                    }

                    html += '</div>';
                }

                html += '</div>';
            });

            html += '</div>';
            html += '</article>';

            return html;
        }

        function bindActions() {
            var buttons = document.querySelectorAll('.admin-area-card-kds__btn[data-id]');

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var endpoint = button.dataset.dir === 'back' ? endpoints.rollback : endpoints.advance;

                    button.disabled = true;
                    button.classList.add('is-loading');

                    fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            item_id: parseInt(button.dataset.id, 10)
                        })
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (result) {
                            if (result.ok) {
                                loadItems();
                                return;
                            }

                            button.disabled = false;
                            button.classList.remove('is-loading');
                        })
                        .catch(function () {
                            button.disabled = false;
                            button.classList.remove('is-loading');
                        });
                });
            });
        }

        loadItems();
        pollTimer = window.setInterval(loadItems, 1000);

        window.addEventListener('beforeunload', function () {
            if (pollTimer) {
                window.clearInterval(pollTimer);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAdminArea);
    } else {
        initAdminArea();
    }
})();
