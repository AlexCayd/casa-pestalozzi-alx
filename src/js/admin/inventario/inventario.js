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
    // Cantidades DECIMAL(12,3) como enteros de milésimas: sumar en coma flotante
    // daría 0.30000000000000004 donde el servidor exige la cifra exacta.
    function mil(valor) {
        if (!/^-?\d{1,9}(?:\.\d{1,3})?$/.test(valor)) return null;
        const negativo = valor[0] === '-';
        const partes = (negativo ? valor.slice(1) : valor).split('.');
        const n = BigInt(partes[0]) * 1000n + BigInt((partes[1] || '').padEnd(3, '0'));
        return negativo ? -n : n;
    }
    function decimal(n) {
        const abs = n < 0n ? -n : n;
        return (n < 0n ? '-' : '') + (abs / 1000n) + '.' + (abs % 1000n).toString().padStart(3, '0');
    }
    function bootAlmacenes() {
        const workspace = document.querySelector('[data-almacenes-workspace]');
        if (!workspace) return;
        const selection = workspace.querySelector('[data-warehouse-selection]');
        const status = workspace.querySelector('[data-selection-status]');
        if (selection) {
            let timer, submitting = false;
            const all = selection.querySelector('[data-warehouse-all]');
            function schedule() {
                window.clearTimeout(timer);
                const checked = selection.querySelectorAll('input[name="almacen_ids[]"]:checked');
                status.textContent = checked.length + ' seleccionados. La consulta se actualizará automáticamente.';
                timer = window.setTimeout(function () { selection.requestSubmit(); }, 600);
            }
            if (all) {
                all.hidden = false;
                all.addEventListener('click', function () {
                    selection.querySelectorAll('input[name="almacen_ids[]"]').forEach(function (box) { box.checked = true; });
                    schedule();
                });
            }
            selection.addEventListener('change', function (event) {
                if (!event.target.matches('input[name="almacen_ids[]"]')) return;
                const checked = selection.querySelectorAll('input[name="almacen_ids[]"]:checked');
                if (!checked.length) {
                    event.target.checked = true;
                    status.textContent = 'Mantén al menos un almacén seleccionado.';
                } else {
                    schedule();
                }
            });
            selection.addEventListener('submit', function (event) {
                window.clearTimeout(timer);
                const boxes = selection.querySelectorAll('input[name="almacen_ids[]"]');
                if (boxes.length && !selection.querySelector('input[name="almacen_ids[]"]:checked')) {
                    event.preventDefault(); status.textContent = 'Selecciona al menos un almacén.'; boxes[0].focus();
                    return;
                }
                if (submitting) { event.preventDefault(); return; }
                submitting = true;
                status.textContent = 'Actualizando la consulta…';
            });
            window.addEventListener('pageshow', function () { submitting = false; });
            window.addEventListener('pagehide', function () { window.clearTimeout(timer); });
        }
        // Diálogos de operación de un artículo (articulo-{id}-{operación}): cada uno trae
        // un formulario por almacén. Con varios almacenes, el selector muestra sólo el
        // del elegido; con uno, su panel ya está visible.
        workspace.querySelectorAll('[data-admin-modal]').forEach(function (modal) {
            const panels = modal.querySelectorAll('[data-warehouse-panel]');
            if (!panels.length) return;
            const picker = modal.querySelector('[data-warehouse-picker]');
            const empty = modal.querySelector('[data-warehouse-empty]');
            function update() {
                panels.forEach(function (panel) { panel.hidden = panel.dataset.warehousePanel !== picker.value; });
                if (empty) empty.hidden = picker.value !== '';
            }
            if (picker) picker.addEventListener('change', update);
            // admin.js enfoca el primer elemento (cerrar). Aquí la persona viene a
            // capturar: el foco va al selector si falta elegir almacén o, si no, al primer
            // campo del formulario. Un select realzado por AdminSelect se enfoca por su
            // combobox: el nativo queda oculto para el lector.
            function focusable(field) {
                const wrapper = field.tagName === 'SELECT' ? field.closest('.admin-select') : null;
                return (wrapper && wrapper.querySelector('[role="combobox"]')) || field;
            }
            // admin.js atrapa Tab con los enfocables del diálogo, pero sólo descarta los que
            // llevan `hidden` ellos mismos: los campos de un panel oculto contaban como el
            // último y Tab salía del diálogo (Mayús+Tab desde el primero no iba a ningún
            // sitio). En los bordes se recorre aquí sólo lo visible y no se le pasa el evento.
            if (picker) modal.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab') return;
                const scope = modal.querySelector('[data-admin-modal-dialog]') || modal;
                const visibles = Array.from(scope.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
                    .filter(function (el) { return !el.closest('[hidden]') && el.getAttribute('aria-hidden') !== 'true'; });
                if (!visibles.length) return;
                const borde = event.shiftKey ? visibles[0] : visibles[visibles.length - 1];
                if (document.activeElement !== borde) return;
                event.preventDefault(); event.stopPropagation();
                visibles[event.shiftKey ? visibles.length - 1 : 0].focus();
            });
            modal.addEventListener('admin:modal-opened', function () {
                if (picker) update();
                let target = picker && picker.value === '' ? picker : null;
                if (!target) {
                    const panel = Array.from(panels).find(function (p) { return !p.hidden; });
                    target = panel && panel.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled])');
                }
                if (target) focusable(target).focus();
            });
        });
        workspace.querySelectorAll('form[data-stock-operation]').forEach(function (form) {
            const input = form.querySelector('[name="cantidad"]');
            // Sólo existe si el artículo declara contenido por pieza (750 ml por botella).
            const piezas = form.querySelector('[name="piezas"]');
            const output = form.querySelector('[data-stock-preview]');
            const originalDescription = form.dataset.confirmDescription || '';
            const unidad = form.dataset.stockUnidad;
            // Misma conversión que el servidor: piezas × contenido + suelto, exacta a
            // milésimas. Sin piezas, lo suelto es el total.
            function capturado() {
                if (!piezas || piezas.value === '') {
                    const total = mil(input.value);
                    return total === null ? null : {total: total, texto: input.value + ' ' + unidad};
                }
                const p = mil(piezas.value), c = mil(form.dataset.stockContenido || '');
                const suelto = input.value === '' ? 0n : mil(input.value);
                if (p === null || c === null || suelto === null || p < 0n || suelto < 0n) return null;
                const bruto = p * c;
                if (bruto % 1000n !== 0n) return null;
                const total = bruto / 1000n + suelto;
                return {total: total, texto: piezas.value + (piezas.value === '1' ? ' pieza' : ' piezas') + ' + ' + decimal(suelto) + ' ' + unidad + ' (' + decimal(total) + ' ' + unidad + ')'};
            }
            function pintar() {
                const saldo = mil(form.dataset.stockSaldo || '');
                const cantidad = capturado();
                const conteo = form.dataset.stockOperation === 'ajuste';
                if (saldo === null || cantidad === null || (conteo ? cantidad.total < 0n : cantidad.total <= 0n)) {
                    output.textContent = 'Escribe una cantidad válida con hasta tres decimales.';
                    form.dataset.confirmDescription = originalDescription;
                    return;
                }
                const resultado = conteo ? cantidad.total : saldo + (form.dataset.stockOperation === 'merma' ? -cantidad.total : cantidad.total);
                const texto = decimal(resultado) + ' ' + unidad;
                // Ningún almacén admite saldo negativo: se avisa antes de enviar, sin
                // bloquear; el servidor es quien rechaza.
                output.textContent = resultado < 0n
                    ? 'Quedaría en ' + texto + '. El saldo no puede ser negativo: el servidor rechazará la operación.'
                    : 'Resultado estimado: ' + texto + '. Se confirma en el servidor.';
                form.dataset.confirmDescription = originalDescription + ' · Cantidad: ' + cantidad.texto + ' · Resultado estimado: ' + texto;
            }
            if (input && output) {
                input.addEventListener('input', pintar);
                if (piezas) piezas.addEventListener('input', pintar);
                pintar();
            }
        });
        // Reparto de un faltante: la suma se anuncia mientras se escribe. No bloquea el
        // envío; el servidor exige la cifra exacta y responde con su propio mensaje.
        workspace.querySelectorAll('form[data-faltante-form]').forEach(function (form) {
            const restante = mil(form.dataset.faltanteRestante || '');
            const unidad = form.dataset.faltanteUnidad || '';
            const output = form.querySelector('[data-faltante-suma]');
            const originalDescription = form.dataset.confirmDescription || '';
            function pintar() {
                let suma = 0n, invalido = false;
                const partes = [];
                form.querySelectorAll('[data-faltante-cantidad]').forEach(function (campo) {
                    if (campo.disabled || campo.value === '') return;
                    const q = mil(campo.value);
                    if (q === null || q < 0n) { invalido = true; return; }
                    if (q === 0n) return;
                    suma += q;
                    partes.push(campo.dataset.origenNombre + ' ' + decimal(q) + ' ' + unidad + (campo.dataset.origenPropio === '1' ? '' : ' (en confirmación)'));
                });
                form.dataset.confirmDescription = partes.length ? originalDescription + ' · ' + partes.join(' · ') : originalDescription;
                if (!output || restante === null) return;
                let texto;
                if (invalido) texto = 'Revisa las cantidades: usa números positivos con hasta tres decimales.';
                else if (suma === restante) texto = 'El reparto suma exactamente ' + decimal(restante) + ' ' + unidad + '.';
                else if (suma === 0n) texto = 'Por repartir: ' + decimal(restante) + ' ' + unidad + '.';
                else if (suma < restante) texto = 'Asignado ' + decimal(suma) + ' de ' + decimal(restante) + ' ' + unidad + '. Faltan ' + decimal(restante - suma) + ' ' + unidad + '.';
                else texto = 'Asignado ' + decimal(suma) + ' de ' + decimal(restante) + ' ' + unidad + '. Sobran ' + decimal(suma - restante) + ' ' + unidad + '.';
                output.textContent = texto;
                output.dataset.state = invalido || (suma !== 0n && suma !== restante) ? 'pendiente' : (suma === restante ? 'exacto' : '');
            }
            form.addEventListener('input', pintar);
            pintar();
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
        // Menú «Más» de cada fila. El popover nativo ya abre, cierra al pulsar fuera o con
        // Escape y devuelve el foco; aquí sólo se ancla bajo su botón, se sincroniza
        // aria-expanded y se recorre con flechas, como el selector de contexto. En móvil
        // es hoja inferior por CSS y no se ancla.
        const compacto = window.matchMedia ? window.matchMedia('(max-width: 760px)') : null;
        workspace.querySelectorAll('[data-row-menu]').forEach(function (menu) {
            const trigger = workspace.querySelector('[popovertarget="' + menu.id + '"]');
            if (!trigger || !menu.showPopover) return;
            const items = function () { return Array.from(menu.querySelectorAll('a[href], button:not([disabled])')); };
            let frame = 0;
            function position() {
                frame = 0;
                if (compacto && compacto.matches) { menu.classList.remove('is-anclado'); menu.style.left = menu.style.top = ''; return; }
                menu.classList.add('is-anclado');
                const rect = trigger.getBoundingClientRect();
                const ancho = menu.offsetWidth, alto = menu.offsetHeight;
                // Alineado al borde derecho del botón (la columna de acciones es la última);
                // si no cabe debajo, se abre hacia arriba.
                menu.style.left = Math.max(12, Math.min(rect.right - ancho, window.innerWidth - ancho - 12)) + 'px';
                const abajo = rect.bottom + 6, arriba = rect.top - alto - 6;
                menu.style.top = (abajo + alto > window.innerHeight - 12 && arriba >= 12 ? arriba : Math.max(12, Math.min(abajo, window.innerHeight - alto - 12))) + 'px';
            }
            // Sólo escucha desplazamiento mientras está abierto y a lo sumo un cálculo por cuadro.
            function seguir() { if (!frame) frame = window.requestAnimationFrame(position); }
            menu.addEventListener('toggle', function (event) {
                const abierto = event.newState === 'open';
                trigger.setAttribute('aria-expanded', String(abierto));
                if (abierto) {
                    position();
                    window.addEventListener('scroll', seguir, {capture: true, passive: true});
                    window.addEventListener('resize', seguir, {passive: true});
                } else {
                    window.removeEventListener('scroll', seguir, {capture: true});
                    window.removeEventListener('resize', seguir);
                    if (frame) { window.cancelAnimationFrame(frame); frame = 0; }
                }
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                event.preventDefault();
                if (!menu.matches(':popover-open')) menu.showPopover();
                const lista = items();
                if (lista.length) lista[event.key === 'ArrowUp' ? lista.length - 1 : 0].focus();
            });
            menu.addEventListener('keydown', function (event) {
                const lista = items(), indice = lista.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (lista.length) lista[(indice + (event.key === 'ArrowDown' ? 1 : lista.length - 1)) % lista.length].focus();
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    if (lista.length) lista[event.key === 'Home' ? 0 : lista.length - 1].focus();
                } else if (event.key === 'Escape') {
                    event.stopPropagation(); menu.hidePopover(); trigger.focus();
                }
            });
            // Salir con Tab no deja el menú flotando sobre otra fila. Se comprueba cuando el
            // foco ya llegó a su destino, para no devolverlo al botón.
            menu.addEventListener('focusout', function () {
                window.setTimeout(function () {
                    const activo = document.activeElement;
                    if (menu.matches(':popover-open') && activo && activo !== document.body && activo !== trigger && !menu.contains(activo)) menu.hidePopover();
                }, 0);
            });
            // «Configurar asociación» abre su diálogo (admin.js ya escucha el clic).
            // El menú se cierra para no quedar encima del diálogo, y al cerrarlo el foco
            // vuelve a «Más»: el elemento que lo abrió ya no es visible.
            menu.addEventListener('click', function (event) {
                const abre = event.target.closest('[data-admin-modal-open]');
                if (!abre) return;
                menu.hidePopover();
                const modal = document.getElementById(abre.dataset.adminModalOpen);
                if (modal) modal.addEventListener('admin:modal-closed', function () { window.requestAnimationFrame(function () { trigger.focus(); }); }, {once: true});
            });
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
        const contextual = document.querySelector('[data-admin-modal][data-contextual-open="1"]');
        if (contextual && contextual.id === 'almacen-asociar') {
            // ?asociar= sólo sirve para abrir el diálogo. Cancelarlo no debe dejar la URL
            // personalizada: recargar la reabriría y articulo_id seguiría filtrando la tabla.
            // Enviar el formulario navega por POST y no emite admin:modal-closed.
            // Ojo: el evento tampoco distingue un cierre por apilamiento (abrir otro
            // modal encima); si «Asociar» llega a tener un disparador de otro modal,
            // abrirlo recargaría la página. Hoy el diálogo no contiene ninguno.
            contextual.addEventListener('admin:modal-closed', function () {
                const url = new URL(window.location.href);
                if (!url.searchParams.has('asociar')) return;
                url.searchParams.delete('asociar');
                // Desde otro módulo (volver=) se conserva el artículo y el regreso.
                const recargar = !url.searchParams.has('volver');
                if (recargar) {
                    url.searchParams.delete('articulo_id');
                    if (url.searchParams.get('vista') === 'existencias') url.searchParams.delete('vista');
                }
                window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
                if (recargar) window.location.reload();
            }, {once: true});
        }
        if (contextual) document.dispatchEvent(new CustomEvent('admin:open-modal', {detail:{id:contextual.id}}));
        const initial = {movimientos:'almacen-movimientos', almacenes:'almacen-config', transferencias:'almacen-transferencias', faltantes:'almacen-faltantes'}[workspace.dataset.initialView];
        if (initial && !contextual) document.dispatchEvent(new CustomEvent('admin:open-modal', {detail:{id:initial}}));
    }
    if (document.readyState !== 'complete') document.addEventListener('DOMContentLoaded', bootAlmacenes);
    else bootAlmacenes();
})();
