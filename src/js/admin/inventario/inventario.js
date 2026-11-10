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
            let submitting = false;
            const all = workspace.querySelector('[data-warehouse-all]');
            const panel = workspace.querySelector('[data-alcance-panel]');
            const trigger = workspace.querySelector('[data-alcance-trigger]');
            const aplicar = workspace.querySelector('[data-alcance-aplicar]');
            const boxes = function () { return Array.from(selection.querySelectorAll('input[name="almacen_ids[]"]')); };
            const avisar = function (texto) { if (status) status.textContent = texto; };
            // Lo aplicado es lo que pintó el servidor (defaultChecked). Cerrar el panel sin
            // aplicar vuelve a ello: el resumen de la página siempre dice lo que la tabla
            // muestra, aunque el navegador restaure casillas al volver con el historial.
            const pendiente = function () { return boxes().some(function (box) { return box.checked !== box.defaultChecked; }); };
            const restablecer = function () { boxes().forEach(function (box) { box.checked = box.defaultChecked; }); };
            // Cada propietario lleva su recuento; «Aplicar» dice cuántos quedarán.
            function pintar() {
                const marcados = boxes().filter(function (box) { return box.checked; }).length;
                workspace.querySelectorAll('[data-alcance-grupo]').forEach(function (grupo) {
                    const propias = Array.from(grupo.querySelectorAll('input[name="almacen_ids[]"]'));
                    const cuenta = grupo.querySelector('[data-alcance-cuenta]');
                    if (cuenta) cuenta.textContent = propias.filter(function (box) { return box.checked; }).length + '/' + propias.length;
                });
                if (aplicar) aplicar.textContent = pendiente() ? 'Aplicar (' + marcados + ')' : 'Aplicar selección';
                return marcados;
            }
            function anunciarCambio() {
                const marcados = pintar();
                avisar(pendiente() ? marcados + ' seleccionados. Pulsa Aplicar para actualizar la consulta.' : marcados + ' almacenes en el alcance actual.');
            }
            // Ninguna acción deja la consulta vacía: si quitar dejaría cero, no se quita.
            function marcar(lista, valor) {
                const antes = lista.map(function (box) { return box.checked; });
                lista.forEach(function (box) { box.checked = valor; });
                if (!boxes().some(function (box) { return box.checked; })) {
                    lista.forEach(function (box, i) { box.checked = antes[i]; });
                    pintar(); avisar('Mantén al menos un almacén seleccionado.');
                    return;
                }
                anunciarCambio();
            }
            if (all) {
                all.hidden = false;
                all.addEventListener('click', function () { marcar(boxes(), true); });
            }
            selection.addEventListener('change', function (event) {
                if (!event.target.matches('input[name="almacen_ids[]"]')) return;
                if (!selection.querySelectorAll('input[name="almacen_ids[]"]:checked').length) {
                    event.target.checked = true;
                    avisar('Mantén al menos un almacén seleccionado.');
                } else {
                    anunciarCambio();
                }
            });
            workspace.querySelectorAll('[data-alcance-grupo]').forEach(function (grupo) {
                const atajos = grupo.querySelector('[data-alcance-atajos]');
                if (!atajos) return;
                atajos.hidden = false;
                const visibles = function () { return Array.from(grupo.querySelectorAll('.almacen-choice:not([hidden]) input[name="almacen_ids[]"]')); };
                grupo.querySelector('[data-alcance-todos]').addEventListener('click', function () { marcar(visibles(), true); });
                grupo.querySelector('[data-alcance-ninguno]').addEventListener('click', function () { marcar(visibles(), false); });
            });
            // Buscar acota lo visible, nunca lo marcado: una casilla oculta conserva su
            // estado y se sigue enviando. Comparación sin acentos ni mayúsculas.
            const buscar = workspace.querySelector('[data-alcance-buscar]');
            const q = workspace.querySelector('[data-alcance-q]');
            const plano = function (texto) { return String(texto).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
            function filtrar() {
                const termino = plano(q.value.trim());
                workspace.querySelectorAll('[data-alcance-grupo]').forEach(function (grupo) {
                    let alguno = false;
                    grupo.querySelectorAll('[data-alcance-nombre]').forEach(function (label) {
                        const coincide = termino === '' || plano(label.dataset.alcanceNombre).indexOf(termino) !== -1;
                        label.hidden = !coincide;
                        if (coincide) alguno = true;
                    });
                    const vacio = grupo.querySelector('[data-alcance-vacio]');
                    if (vacio) vacio.hidden = alguno;
                });
            }
            if (buscar && q) {
                buscar.hidden = false;
                q.addEventListener('input', filtrar);
                // Enter en el buscador no envía la consulta: aún se está eligiendo.
                q.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); });
            }
            // Panel: popover nativo (abre, cierra con Escape o al pulsar fuera y devuelve
            // el foco). Aquí se ancla bajo el resumen, se sincroniza aria-expanded y, si se
            // cierra sin aplicar, se restablece lo aplicado. En móvil es hoja inferior.
            const compactoAlcance = window.matchMedia ? window.matchMedia('(max-width: 760px)') : null;
            if (panel && trigger && panel.showPopover) {
                let frame = 0;
                function position() {
                    frame = 0;
                    if (compactoAlcance && compactoAlcance.matches) { panel.classList.remove('is-anclado'); panel.style.left = panel.style.top = ''; return; }
                    panel.classList.add('is-anclado');
                    const rect = trigger.getBoundingClientRect();
                    const ancho = panel.offsetWidth, alto = panel.offsetHeight;
                    panel.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - ancho - 12)) + 'px';
                    panel.style.top = Math.max(12, Math.min(rect.bottom + 6, window.innerHeight - alto - 12)) + 'px';
                }
                function seguir() { if (!frame) frame = window.requestAnimationFrame(position); }
                panel.addEventListener('toggle', function (event) {
                    const abierto = event.newState === 'open';
                    trigger.setAttribute('aria-expanded', String(abierto));
                    if (abierto) {
                        position();
                        window.addEventListener('scroll', seguir, {capture: true, passive: true});
                        window.addEventListener('resize', seguir, {passive: true});
                        // Con buscador, el foco va a él; si no, a la primera casilla.
                        const destino = (buscar && !buscar.hidden && q) || boxes()[0];
                        if (destino) destino.focus();
                        return;
                    }
                    window.removeEventListener('scroll', seguir, {capture: true});
                    window.removeEventListener('resize', seguir);
                    if (frame) { window.cancelAnimationFrame(frame); frame = 0; }
                    if (submitting) return;
                    restablecer();
                    if (q && q.value !== '') { q.value = ''; filtrar(); }
                    pintar(); avisar(boxes().filter(function (box) { return box.checked; }).length + ' almacenes en el alcance actual.');
                });
                // Salir con Tab no deja el panel flotando sobre la tabla.
                panel.addEventListener('focusout', function () {
                    window.setTimeout(function () {
                        const activo = document.activeElement;
                        if (panel.matches(':popover-open') && activo && activo !== document.body && activo !== trigger && !panel.contains(activo)) panel.hidePopover();
                    }, 0);
                });
            }
            selection.addEventListener('submit', function (event) {
                const lista = boxes();
                if (lista.length && !selection.querySelector('input[name="almacen_ids[]"]:checked')) {
                    event.preventDefault(); avisar('Selecciona al menos un almacén.'); lista[0].focus();
                    return;
                }
                if (submitting) { event.preventDefault(); return; }
                // Sin popover nativo el panel no se abre ni se cierra desde aquí.
                const abierto = !!(panel && panel.showPopover && panel.matches(':popover-open'));
                // Sin cambios no hay nada que pedir: se cierra el panel y ya.
                if (abierto && !pendiente()) {
                    event.preventDefault(); panel.hidePopover(); if (trigger) trigger.focus();
                    return;
                }
                submitting = true;
                avisar('Actualizando la consulta…');
                if (abierto) panel.hidePopover();
                esqueleto(true);
            });
            // Volver con el historial restaura la página tal cual se dejó (bfcache): se
            // retira el esqueleto y la selección vuelve a ser la que se ve.
            window.addEventListener('pageshow', function () {
                submitting = false;
                esqueleto(false);
                restablecer();
                pintar();
            });
        }
        // Carga de la consulta: la página se recarga con el GET, así que mientras llega la
        // respuesta la tabla vieja se cambia por filas fantasma. Dicen «esto se está
        // actualizando» y conservan la forma de la tabla, para que nada salte al llegar.
        function esqueleto(activo) {
            const lista = workspace.querySelector('[data-stock-list]');
            if (!lista || !lista.querySelectorAll) return;
            const contenido = lista.querySelectorAll(':scope > .almacen-legend, :scope > .almacen-table, :scope > .admin-empty, :scope > .admin-pagination');
            const previo = lista.querySelector('[data-stock-skeleton]');
            if (!activo) {
                if (previo) previo.remove();
                contenido.forEach(function (nodo) { if (nodo.dataset.stockOculto) { nodo.hidden = false; delete nodo.dataset.stockOculto; } });
                return;
            }
            if (previo) return;
            const filas = Math.min(6, Math.max(3, lista.querySelectorAll('.almacen-table tbody tr').length));
            const bloque = document.createElement('div');
            bloque.className = 'almacen-skeleton';
            bloque.dataset.stockSkeleton = '';
            const aviso = document.createElement('p');
            aviso.className = 'admin-visually-hidden';
            aviso.setAttribute('role', 'status');
            bloque.appendChild(aviso);
            const rejilla = document.createElement('div');
            rejilla.className = 'almacen-skeleton__filas';
            rejilla.setAttribute('aria-hidden', 'true');
            for (let i = 0; i < filas; i++) {
                const fila = document.createElement('div');
                fila.className = 'almacen-skeleton__fila';
                fila.style.setProperty('--fila', String(i));
                ['nombre', 'saldo', 'barra', 'acciones'].forEach(function (celda) {
                    const c = document.createElement('div');
                    c.className = 'almacen-skeleton__celda almacen-skeleton__celda--' + celda;
                    c.innerHTML = '<span></span><span></span>';
                    fila.appendChild(c);
                });
                rejilla.appendChild(fila);
            }
            bloque.appendChild(rejilla);
            const ancla = contenido[0];
            if (ancla) lista.insertBefore(bloque, ancla); else lista.appendChild(bloque);
            contenido.forEach(function (nodo) { if (!nodo.hidden) { nodo.hidden = true; nodo.dataset.stockOculto = '1'; } });
            // La región viva ya existe cuando cambia su texto: así se anuncia.
            (window.requestAnimationFrame || window.setTimeout)(function () { aviso.textContent = 'Actualizando existencias de la selección…'; });
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
            // La trampa de Tab es del gestor compartido (admin.js), que ya descarta los
            // paneles ocultos: aquí sólo se decide el foco inicial.
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
        // Entrada con proveedor: el costo pasa a obligatorio (el servidor también lo
        // exige) y, si está vacío, se propone el costo de referencia que ese proveedor
        // tiene para el artículo. Ese costo es por unidad base, así que se marca «por
        // unidad» cuando el artículo admite captura por pieza.
        workspace.querySelectorAll('[data-entrada-proveedor]').forEach(function (select) {
            const form = select.form;
            const costo = form && form.querySelector('[name="costo_unitario"]');
            if (!costo) return;
            // El rótulo deja de decir «(opcional)» cuando el costo se vuelve obligatorio.
            const opcional = form.querySelector('[data-costo-opcional]');
            // Lo que se propuso al elegir el proveedor anterior: si el operador no lo
            // tocó, cambiar de proveedor lo reemplaza en vez de arrastrar un costo ajeno.
            let propuesto = '';
            select.addEventListener('change', function () {
                const opcion = select.options[select.selectedIndex];
                costo.required = select.value !== '';
                if (opcional) opcional.hidden = costo.required;
                if (propuesto !== '' && costo.value === propuesto) costo.value = '';
                propuesto = '';
                if (select.value !== '' && costo.value === '' && opcion && opcion.dataset.costo) {
                    costo.value = opcion.dataset.costo;
                    propuesto = costo.value;
                    const porUnidad = form.querySelector('[name="costo_por"][value="unidad"]');
                    if (porUnidad) porUnidad.checked = true;
                    costo.dispatchEvent(new Event('input', {bubbles: true}));
                }
                // La confirmación repite de quién llega la entrada: se recalcula su texto.
                const cantidad = form.querySelector('[name="cantidad"]');
                if (cantidad) cantidad.dispatchEvent(new Event('input', {bubbles: true}));
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
                if (saldo === null || cantidad === null || cantidad.total <= 0n) {
                    output.textContent = 'Escribe una cantidad válida con hasta tres decimales.';
                    form.dataset.confirmDescription = originalDescription;
                    return;
                }
                const resultado = saldo + (form.dataset.stockOperation === 'merma' ? -cantidad.total : cantidad.total);
                const texto = decimal(resultado) + ' ' + unidad;
                // Ningún almacén admite saldo negativo: se avisa antes de enviar, sin
                // bloquear; el servidor es quien rechaza.
                output.textContent = resultado < 0n
                    ? 'Quedaría en ' + texto + '. El saldo no puede ser negativo: el servidor rechazará la operación.'
                    : 'Resultado estimado: ' + texto + '. Se confirma en el servidor.';
                const proveedor = form.querySelector('[data-entrada-proveedor]');
                const deQuien = proveedor && proveedor.value !== '' ? ' · Proveedor: ' + proveedor.options[proveedor.selectedIndex].text.split(' · ')[0] : '';
                form.dataset.confirmDescription = originalDescription + ' · Cantidad: ' + cantidad.texto + deQuien + ' · Resultado estimado: ' + texto;
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
        // Al desplegar una acción del documento, su historial o la solicitud, lo que se
        // abre suele quedar bajo el borde del cuerpo desplazable del modal: se acerca lo
        // justo para verlo entero o, si no cabe, desde arriba (el scroll-margin de
        // inventario.scss deja a la vista el botón que lo abrió). `toggle` no burbujea:
        // se escucha en captura. Los documentos que llegan abiertos no se tocan.
        workspace.querySelectorAll('.almacen-modal__body').forEach(function (body) {
            body.addEventListener('toggle', function (event) {
                const details = event.target;
                if (!details.open || !details.matches('.almacen-transfer-action, .almacen-transfer-history, .almacen-transfer-request')) return;
                // El details de una acción cede su caja a la barra (display: contents) y
                // no se puede desplazar hacia él: se usa lo que despliega.
                const target = details.querySelector(':scope > form, :scope > ol');
                if (!target || body.closest('[hidden]')) return;
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.requestAnimationFrame(function () { target.scrollIntoView({block: 'nearest', behavior: reduce ? 'auto' : 'smooth'}); });
            }, true);
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
            // Anticipa qué hará el servidor con la pareja elegida (él decide; esto sólo
            // informa): mismo negocio o superadministrador, un paso; si el origen es propio,
            // sale enviada; si sólo el destino es propio, queda solicitada al otro negocio.
            const hint = request.querySelector('[data-transfer-hint]');
            const submit = request.querySelector('[data-transfer-submit]');
            const hintBase = hint ? hint.textContent : '';
            const submitBase = submit ? submit.textContent : '';
            const negocio = function (field) {
                const option = field.options[field.selectedIndex];
                return option && option.value ? option.dataset.contexto : '';
            };
            // Nombre visible del negocio: cada opción se rotula «Almacén · Negocio» con
            // Contextos::NOMBRES, así que basta con su último tramo.
            const nombreNegocio = function (field) {
                const option = field.options[field.selectedIndex];
                const partes = option ? option.textContent.split(' · ') : [];
                return partes.length > 1 ? partes[partes.length - 1].trim() : 'el otro negocio';
            };
            const nombrePropio = function () {
                const propia = request.querySelector('[name="origen_id"] option[data-contexto="' + request.dataset.contexto + '"]');
                const partes = propia ? propia.textContent.split(' · ') : [];
                return partes.length > 1 ? partes[partes.length - 1].trim() : 'este negocio';
            };
            const anticipar = function () {
                const campoOrigen = request.querySelector('[name="origen_id"]');
                const campoDestino = request.querySelector('[name="destino_id"]');
                const origen = negocio(campoOrigen);
                const destino = negocio(campoDestino);
                let texto = hintBase;
                let boton = submitBase;
                let valida = false;
                if (origen && destino) {
                    if (campoOrigen.value === campoDestino.value) {
                        // Antes anunciaba «Transferir ahora» con el mismo almacén en los dos
                        // lados; el envío lo rechazaba después. Se dice antes.
                        texto = 'Elige un destino distinto al origen.';
                    } else if (request.dataset.super === '1' || origen === destino) {
                        texto = 'Se aplica en un solo paso: sale del origen y entra al destino al registrarla.';
                        boton = 'Transferir ahora';
                        valida = true;
                    } else if (origen === request.dataset.contexto) {
                        texto = 'Sale enviada: se descuenta del origen y ' + nombreNegocio(campoDestino) + ' registra la recepción. Mientras tanto no está disponible en ningún almacén.';
                        boton = 'Enviar';
                        valida = true;
                    } else if (destino === request.dataset.contexto) {
                        texto = 'Queda solicitada: ' + nombreNegocio(campoOrigen) + ', dueño del origen, la envía y aquí registras la recepción.';
                        boton = 'Solicitar';
                        valida = true;
                    } else {
                        texto = 'Uno de los dos almacenes debe pertenecer a ' + nombrePropio() + '.';
                    }
                }
                // La condición de asociación sólo acompaña a una pareja válida; junto a un
                // aviso de pareja inválida distraía del arreglo. Se compara antes de
                // escribir: reescribir el mismo texto haría que el aria-live lo repitiera.
                if (hint && hint.textContent !== texto) hint.textContent = texto;
                if (submit && submit.textContent !== boton) submit.textContent = boton;
                avisarAsociacion();
            };
            // Ya no hace falta asociar antes de transferir: el destino queda asociado al
            // registrar. Aquí sólo se avisa antes de enviar qué artículos estrenarán
            // almacén, y cuáles no están en el origen (el servidor rechazará esos).
            let asociaciones = {};
            try { asociaciones = JSON.parse(request.dataset.asociaciones || '{}') || {}; } catch (error) { asociaciones = {}; }
            const aviso = request.querySelector('[data-transfer-asociacion]');
            const nombreAlmacen = function (field) {
                const option = field.options[field.selectedIndex];
                return option && option.value ? option.textContent.split(' · ')[0].trim() : '';
            };
            function avisarAsociacion() {
                if (!aviso) return;
                const campoOrigen = request.querySelector('[name="origen_id"]');
                const campoDestino = request.querySelector('[name="destino_id"]');
                const origen = Number(campoOrigen.value), destino = Number(campoDestino.value);
                const nuevos = [], sinOrigen = [];
                request.querySelectorAll('[name$="[articulo_id]"]').forEach(function (select) {
                    const option = select.options ? select.options[select.selectedIndex] : null;
                    if (!option || !option.value) return;
                    const donde = asociaciones[option.value] || [];
                    const nombre = '«' + option.textContent.split(' · ')[0].trim() + '»';
                    if (origen && donde.indexOf(origen) === -1) { if (sinOrigen.indexOf(nombre) === -1) sinOrigen.push(nombre); return; }
                    if (destino && destino !== origen && donde.indexOf(destino) === -1 && nuevos.indexOf(nombre) === -1) nuevos.push(nombre);
                });
                let texto = '';
                // Lo que falta en el origen no es un aviso: el servidor rechazará el envío.
                // Se dice cómo arreglarlo y se marca con data-tono (rojo, no sólo color:
                // el texto cambia y pide una acción).
                if (sinOrigen.length) texto = sinOrigen.join(', ') + (sinOrigen.length === 1 ? ' no está asociado' : ' no están asociados') + ' a ' + nombreAlmacen(campoOrigen) + ': ahí no hay existencias que enviar. Elige otro origen o ' + (sinOrigen.length === 1 ? 'quita ese artículo.' : 'quita esos artículos.');
                if (nuevos.length) texto += (texto ? ' ' : '') + nuevos.join(', ') + (nuevos.length === 1 ? ' todavía no se maneja' : ' todavía no se manejan') + ' en ' + nombreAlmacen(campoDestino) + '. Al registrar, ' + (nuevos.length === 1 ? 'quedará asociado' : 'quedarán asociados') + ' a ese almacén con mínimo 0.';
                if (texto === '') { aviso.hidden = true; aviso.textContent = ''; aviso.removeAttribute('data-tono'); return; }
                // Primero se muestra y después se escribe: un role="status" que recibe el
                // texto mientras está oculto no siempre se anuncia al aparecer.
                aviso.dataset.tono = sinOrigen.length ? 'error' : 'aviso';
                aviso.hidden = false;
                if (aviso.textContent !== texto) aviso.textContent = texto;
            }
            request.addEventListener('change', function (event) {
                if (event.target.matches('[name$="[articulo_id]"]')) avisarAsociacion();
            });
            lines.addEventListener('click', function (event) {
                if (event.target.closest('[data-transfer-remove]')) window.setTimeout(avisarAsociacion, 0);
            });
            request.querySelectorAll('[name="origen_id"], [name="destino_id"]').forEach(function (field) {
                field.addEventListener('change', function () { request.querySelector('[name="destino_id"]').setCustomValidity(''); anticipar(); });
            });
        }
        // «Asociar artículo»: sin casillas el servidor responde «Elige al menos un almacén»
        // tras recargar. Se dice antes, junto a las casillas y con role="alert"; la casilla
        // nativa no tiene «al menos una» y un globo en la primera se leería como si esa
        // en particular fuera obligatoria.
        const grupoAsociar = document.querySelector('[data-asociar-almacenes]');
        const formAsociar = grupoAsociar ? grupoAsociar.closest('form') : null;
        const errorAsociar = grupoAsociar ? grupoAsociar.querySelector('[data-asociar-error]') : null;
        if (formAsociar && errorAsociar) {
            const casillas = function () { return Array.prototype.slice.call(grupoAsociar.querySelectorAll('input[type="checkbox"]')); };
            const limpiar = function () { errorAsociar.hidden = true; errorAsociar.textContent = ''; grupoAsociar.classList.remove('has-error'); };
            formAsociar.addEventListener('submit', function (event) {
                const todas = casillas();
                if (!todas.length || todas.some(function (casilla) { return casilla.checked; })) return;
                event.preventDefault();
                grupoAsociar.classList.add('has-error');
                errorAsociar.hidden = false;
                errorAsociar.textContent = 'Elige al menos un almacén.';
                todas[0].focus();
            });
            grupoAsociar.addEventListener('change', function () {
                if (!errorAsociar.hidden && casillas().some(function (casilla) { return casilla.checked; })) limpiar();
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
