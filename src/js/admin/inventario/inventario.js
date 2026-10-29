/**
 * Inventario: validación del alta de merma y borrado seguro de ingredientes.
 *
 * El modal de merma por fila se retiró junto con el botón que lo abría; queda
 * el formulario del panel, que además deja elegir el ingrediente. Aquí sólo se
 * valida su cantidad.
 */
(function () {
    function initMerma() {
        var form = document.querySelector('.admin-merma__form');
        if (!form) {
            return;
        }

        var cantidad = form.querySelector('#merma-cantidad');

        form.addEventListener('submit', function (event) {
            var valor = parseFloat(cantidad ? cantidad.value : '');
            if (!(valor > 0)) {
                event.preventDefault();
                if (window.AppNotice) {
                    window.AppNotice.show({
                        text: 'Escribe cuánto se mermó: debe ser mayor a cero.',
                        variant: 'error'
                    });
                }
                if (cantidad) {
                    cantidad.focus();
                }
            }
        });
    }

    /*
     * Tabla repetible de proveedores en la ficha del ingrediente.
     *
     * El <select> del proveedor se clona de un <template> que PHP ya pintó con
     * el catálogo completo: así no hay que serializar la lista a JavaScript ni
     * mantenerla en dos sitios.
     *
     * El "preferente" es un radio con el índice de fila como valor, y por eso
     * hay que renumerarlo cada vez que se agrega o se quita una: un radio con
     * valores repetidos manda el primero que encuentra, no el que se marcó.
     */
    function initProveedores() {
        var raiz = document.querySelector('[data-proveedores]');
        if (!raiz) {
            return;
        }

        var filas = raiz.querySelector('[data-proveedores-filas]');
        var plantilla = raiz.querySelector('[data-proveedor-plantilla]');
        var agregar = raiz.querySelector('[data-proveedor-agregar]');
        var vacio = raiz.querySelector('[data-proveedores-vacio]');

        if (!filas || !plantilla || !agregar) {
            return;
        }

        function renumerar() {
            var todas = filas.querySelectorAll('.admin-proveedores__fila');
            for (var i = 0; i < todas.length; i++) {
                var radio = todas[i].querySelector('input[name="proveedor_preferente"]');
                if (radio) {
                    radio.value = String(i);
                }
            }
            if (vacio) {
                vacio.hidden = todas.length > 0;
            }
            // Sin preferente marcado, el reabastecimiento no tendría a quién
            // proponer: se marca la primera, que es la que el orden de la ficha
            // ya presenta como principal.
            if (todas.length && !filas.querySelector('input[name="proveedor_preferente"]:checked')) {
                var primero = todas[0].querySelector('input[name="proveedor_preferente"]');
                if (primero) {
                    primero.checked = true;
                }
            }
        }

        /*
         * La fila nueva llega de un <template>, cuyo contenido no está en el
         * documento cuando select.js recorre la página al cargar: su <select>
         * se quedaba NATIVO —la lista blanca con el resaltado azul del sistema,
         * fuera del panel— mientras las filas pintadas por PHP sí salían con
         * el selector de la casa. Se realza al insertarla.
         *
         * Devuelve el disparador del selector (o el select si no hubo realce)
         * para poder enfocarlo.
         */
        function agregarFila() {
            var fragmento = plantilla.content.cloneNode(true);
            var fila = fragmento.querySelector('.admin-proveedores__fila');
            filas.appendChild(fragmento);
            renumerar();
            if (fila && window.AdminSelect && typeof window.AdminSelect.init === 'function') {
                window.AdminSelect.init(fila);
            }
            if (window.AdminScrollLock) {
                window.AdminScrollLock.remedir();
            }
            return fila
                ? (fila.querySelector('.admin-select__trigger') || fila.querySelector('select'))
                : null;
        }

        agregar.addEventListener('click', function () {
            var control = agregarFila();
            // El foco va al proveedor recién añadido: es lo siguiente que hay
            // que decidir, y sin moverlo se quedaba en el botón de abajo.
            if (control) {
                control.focus();
            }
        });

        /*
         * Se llega con #proveedores desde la lista de pendientes «Sin
         * proveedor»: si el ingrediente no tiene ninguno, la fila ya está
         * abierta y enfocada, que es la tarea a la que se venía.
         */
        if (window.location.hash === '#proveedores'
            && !filas.querySelector('.admin-proveedores__fila')) {
            var primero = agregarFila();
            if (primero) {
                primero.focus({ preventScroll: true });
                raiz.scrollIntoView({ block: 'center' });
            }
        }

        raiz.addEventListener('click', function (event) {
            if (!event.target.closest('[data-proveedor-quitar]')) {
                return;
            }
            var fila = event.target.closest('.admin-proveedores__fila');
            if (fila) {
                fila.remove();
                renumerar();
                // El botón pulsado ya no existe: sin esto el foco caía al
                // <body> y el teclado volvía al principio de la página.
                agregar.focus();
            }
        });

        renumerar();
    }

    /**
     * Borrado de un ingrediente: enseña a qué recetas afecta y exige escribir
     * el nombre.
     *
     * Las FK de producto_componentes y subreceta_ingredientes son ON DELETE
     * CASCADE, así que borrar un ingrediente vacía en silencio la receta de
     * todos los platillos que lo llevan. El diálogo genérico lo decía de
     * palabra ("y de las recetas que lo usan") sin decir CUÁLES, que es lo
     * único que permite decidir.
     *
     * Se engancha en CAPTURA sobre el document, no sobre el formulario. Es
     * deliberado: admin.js ya tiene un listener de submit en cada
     * [data-confirm-delete], y en el elemento destino los listeners corren en
     * orden de registro, así que desde el formulario no habría forma de
     * adelantarse. Capturando en document se corre antes y se detiene la
     * propagación. La ventaja de hacerlo así, y no quitándole el atributo al
     * formulario, es que si este archivo no llega a cargar el diálogo genérico
     * de admin.js sigue pidiendo confirmación: nunca se borra sin preguntar.
     */
    function initBorradoIngrediente() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches || !form.matches('[data-ingrediente-delete]')) {
                return;
            }
            if (form.dataset.deleteConfirmed === '1') {
                return;
            }
            if (!window.ConfirmationModal || !window.fetch) {
                return; // Que lo atienda el diálogo genérico de admin.js.
            }

            event.preventDefault();
            event.stopPropagation();

            var id = form.getAttribute('data-ingrediente-id') || '';
            var nombreIng = form.getAttribute('data-ingrediente-nombre') || 'este ingrediente';
            var modal = window.ConfirmationModal.get();

            fetch('/admin/api/inventario/uso?id=' + encodeURIComponent(id), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (datos) {
                    abrirDialogo(form, nombreIng, modal, datos);
                });
        }, true);
    }

    /** Lista de platillos y subrecetas afectados, para la ranura custom. */
    function resumenDeUso(datos) {
        if (!datos || !datos.ok) {
            // La consulta falló: no se calla el diálogo, se avisa de que no se
            // pudo comprobar. Callar sugeriría que no afecta a nada.
            var aviso = document.createElement('p');
            aviso.className = 'admin-inventario__uso-aviso';
            aviso.textContent = 'No se pudo comprobar en qué recetas se usa. Revisa antes de continuar.';
            return aviso;
        }

        var platillos = datos.platillos || [];
        var subrecetas = datos.subrecetas || [];

        if (!platillos.length && !subrecetas.length) {
            var libre = document.createElement('p');
            libre.className = 'admin-inventario__uso-aviso';
            libre.textContent = 'No aparece en ninguna receta ni subreceta.';
            return libre;
        }

        var caja = document.createElement('div');
        caja.className = 'admin-inventario__uso';

        if (platillos.length) {
            var titulo = document.createElement('p');
            titulo.className = 'admin-inventario__uso-titulo';
            titulo.textContent = platillos.length === 1
                ? 'Se quedará sin este ingrediente 1 platillo:'
                : 'Se quedarán sin este ingrediente ' + platillos.length + ' platillos:';
            caja.appendChild(titulo);

            var lista = document.createElement('ul');
            lista.className = 'admin-inventario__uso-lista';
            platillos.forEach(function (p) {
                var li = document.createElement('li');
                li.textContent = p.nombre;
                if (p.subrecetas && p.subrecetas.length) {
                    var via = document.createElement('span');
                    via.textContent = ' vía ' + p.subrecetas.join(', ');
                    li.appendChild(via);
                }
                lista.appendChild(li);
            });
            caja.appendChild(lista);
        }

        if (subrecetas.length) {
            var tituloSub = document.createElement('p');
            tituloSub.className = 'admin-inventario__uso-titulo';
            tituloSub.textContent = subrecetas.length === 1
                ? 'Y 1 subreceta:'
                : 'Y ' + subrecetas.length + ' subrecetas:';
            caja.appendChild(tituloSub);

            var listaSub = document.createElement('ul');
            listaSub.className = 'admin-inventario__uso-lista';
            subrecetas.forEach(function (nombre) {
                var li = document.createElement('li');
                li.textContent = nombre;
                listaSub.appendChild(li);
            });
            caja.appendChild(listaSub);
        }

        return caja;
    }

    function abrirDialogo(form, nombreIng, modal, datos) {
        modal.open({
            variant: 'danger',
            eyebrow: 'Eliminar ingrediente',
            title: '¿Eliminar «' + nombreIng + '»?',
            description: 'Se borrará del inventario y de las recetas que lo usan.',
            consequence: 'También se pierde su bitácora de movimientos. Esta acción no se puede deshacer.',
            customContent: resumenDeUso(datos),
            requireText: nombreIng,
            secondaryLabel: 'Cancelar',
            primaryLabel: 'Eliminar',
            onPrimary: function () {
                form.dataset.deleteConfirmed = '1';
                form.submit();
            }
        });
    }

    function boot() {
        initMerma();
        initProveedores();
        initBorradoIngrediente();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

// Almacenes comparte modales del panel; el servidor mantiene alcance y saldos.
(function () {
    function bootAlmacenes() {
        const workspace = document.querySelector('[data-almacenes-workspace]');
        if (!workspace) return;
        const selection = workspace.querySelector('[data-warehouse-selection]');
        const status = workspace.querySelector('[data-selection-status]');
        if (selection) {
            selection.addEventListener('change', function (event) {
                if (!event.target.matches('input[name="almacen_ids[]"]')) return;
                const checked = selection.querySelectorAll('input[name="almacen_ids[]"]:checked');
                if (!checked.length) {
                    event.target.checked = true;
                    status.textContent = 'Mantén al menos un almacén seleccionado.';
                } else {
                    status.textContent = checked.length + ' seleccionados. Aplica la selección para actualizar los resultados.';
                }
            });
            selection.addEventListener('submit', function (event) {
                const boxes = selection.querySelectorAll('input[name="almacen_ids[]"]');
                if (boxes.length && !selection.querySelector('input[name="almacen_ids[]"]:checked')) {
                    event.preventDefault(); status.textContent = 'Selecciona al menos un almacén.'; boxes[0].focus();
                }
            });
        }
        workspace.querySelectorAll('[data-warehouse-picker]').forEach(function (picker) {
            const modal = picker.closest('[data-admin-modal]');
            let operation = 'entrada';
            function update() {
                modal.querySelectorAll('[data-warehouse-panel]').forEach(function (panel) {
                    panel.hidden = panel.dataset.warehousePanel !== picker.value;
                    panel.querySelectorAll('details[data-operation]').forEach(function (details) {
                        details.open = details.dataset.operation === operation;
                    });
                });
            }
            picker.addEventListener('change', update);
            modal.addEventListener('admin:modal-opened', function (event) {
                operation = (event.detail.trigger && event.detail.trigger.dataset.operation) || 'entrada'; update();
            });
        });
        workspace.querySelectorAll('form[data-stock-operation]').forEach(function (form) {
            const input = form.querySelector('[name="cantidad"]');
            const output = form.querySelector('[data-stock-preview]');
            const originalDescription = form.dataset.confirmDescription || '';
            function mil(valor) {
                if (!/^-?\d{1,9}(?:\.\d{1,3})?$/.test(valor)) return null;
                const negativo = valor[0] === '-';
                const partes = (negativo ? valor.slice(1) : valor).split('.');
                const n = BigInt(partes[0]) * 1000n + BigInt((partes[1] || '').padEnd(3, '0'));
                return negativo ? -n : n;
            }
            function pintar() {
                const saldo = mil(form.dataset.stockSaldo || '');
                const cantidad = mil(input.value);
                if (saldo === null || cantidad === null || (form.dataset.stockOperation !== 'ajuste' && cantidad <= 0n)) {
                    output.textContent = 'Escribe una cantidad válida con hasta tres decimales.';
                    form.dataset.confirmDescription = originalDescription;
                    return;
                }
                const resultado = form.dataset.stockOperation === 'ajuste' ? cantidad : saldo + (form.dataset.stockOperation === 'merma' ? -cantidad : cantidad);
                const abs = resultado < 0n ? -resultado : resultado;
                const texto = (resultado < 0n ? '-' : '') + (abs / 1000n) + '.' + (abs % 1000n).toString().padStart(3, '0') + ' ' + form.dataset.stockUnidad;
                output.textContent = 'Resultado estimado: ' + texto + '. Se confirma en el servidor.';
                form.dataset.confirmDescription = originalDescription + ' · Cantidad: ' + input.value + ' ' + form.dataset.stockUnidad + ' · Resultado estimado: ' + texto;
            }
            if (input && output) { input.addEventListener('input', pintar); pintar(); }
        });
        workspace.querySelectorAll('[data-stock-tip]').forEach(function (button) {
            const tip = document.getElementById(button.getAttribute('popovertarget'));
            let hideTimer;
            function keepOpen() { window.clearTimeout(hideTimer); }
            function hideLater() {
                keepOpen();
                hideTimer = window.setTimeout(function () {
                    const focused = document.activeElement;
                    const relatedFocus = focused && focused.getAttribute('popovertarget') === (tip && tip.id);
                    if (!relatedFocus && tip && !tip.matches(':hover') && tip.matches(':popover-open')) tip.hidePopover();
                }, 180);
            }
            function show() {
                if (!tip || !tip.showPopover) return;
                keepOpen();
                tip.showPopover();
                const rect = button.getBoundingClientRect();
                tip.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - tip.offsetWidth - 12)) + 'px';
                tip.style.top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - tip.offsetHeight - 12)) + 'px';
            }
            button.addEventListener('pointerenter', show);
            button.addEventListener('focus', show);
            button.addEventListener('click', function (event) { event.preventDefault(); show(); });
            button.addEventListener('pointerleave', hideLater);
            button.addEventListener('blur', hideLater);
            if (tip) { tip.addEventListener('pointerenter', keepOpen); tip.addEventListener('pointerleave', hideLater); }
        });
        const request = workspace.querySelector('[data-transfer-request]');
        if (request) {
            const lines = request.querySelector('[data-transfer-lines]');
            const original = lines.querySelector('[data-transfer-line]').cloneNode(true);
            original.querySelectorAll('select').forEach(function (select) {
                const wrapper = select.closest('.admin-select');
                if (wrapper) { wrapper.parentNode.insertBefore(select, wrapper); wrapper.remove(); }
                delete select.dataset.enhanced;
                select.classList.remove('admin-select__native');
                select.removeAttribute('tabindex');
                select.removeAttribute('aria-hidden');
            });
            original.querySelectorAll('label').forEach(function (label) { label.removeAttribute('id'); });
            let nextLine = 1;
            request.querySelector('[data-transfer-add]').addEventListener('click', function () {
                if (lines.children.length >= 100) return;
                const row = original.cloneNode(true);
                row.querySelectorAll('input, select').forEach(function (field) {
                    const previous = field.id;
                    field.name = field.name.replace('[0]', '[' + nextLine + ']');
                    field.id = field.id.replace('-0', '-' + nextLine);
                    row.querySelector('label[for="' + previous + '"]').htmlFor = field.id;
                    field.value = '';
                });
                nextLine += 1;
                row.querySelector('[data-transfer-remove]').hidden = false;
                lines.appendChild(row);
                if (window.AdminSelect) window.AdminSelect.init(row);
                (row.querySelector('[role="combobox"]') || row.querySelector('select')).focus();
            });
            lines.addEventListener('click', function (event) {
                const remove = event.target.closest('[data-transfer-remove]');
                if (remove) {
                    remove.closest('[data-transfer-line]').remove();
                    request.querySelector('[data-transfer-add]').focus();
                }
            });
            request.addEventListener('submit', function (event) {
                const origin = request.querySelector('[name="origen_id"]');
                const destination = request.querySelector('[name="destino_id"]');
                destination.setCustomValidity(origin.value === destination.value ? 'Elige un destino distinto al origen.' : '');
                if (!destination.checkValidity()) { event.preventDefault(); destination.reportValidity(); }
            });
            request.querySelectorAll('[name="origen_id"], [name="destino_id"]').forEach(function (field) {
                field.addEventListener('change', function () { request.querySelector('[name="destino_id"]').setCustomValidity(''); });
            });
        }
        const initial = {movimientos:'almacen-movimientos', almacenes:'almacen-config', catalogo:'almacen-catalogo', transferencias:'almacen-transferencias'}[workspace.dataset.initialView];
        if (initial) document.dispatchEvent(new CustomEvent('admin:open-modal', {detail:{id:initial}}));
    }
    if (document.readyState !== 'complete') document.addEventListener('DOMContentLoaded', bootAlmacenes);
    else bootAlmacenes();
})();
