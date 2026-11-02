/**
 * Constructor de recetas del admin (Recetas y Subrecetas).
 *
 * Agrega y quita filas de ingredientes clonando el <template> del formulario, y
 * abre un modal con pestañas alfabéticas para elegir el ingrediente o subreceta
 * de cada fila.
 *
 * El modal sustituye al combobox anterior, que desplegaba su lista en
 * position:absolute dentro de la fila: la recortaba el overflow:hidden de
 * .admin-card, así que con el catálogo completo no se podía hacer scroll y el
 * listado se salía de la pantalla. Aquí el listado vive en su propio diálogo,
 * con scroll interno y espacio para varias columnas.
 */
(function () {
    'use strict';

    /**
     * La etiqueta del catálogo viene como "Nombre (unidad)" o "Nombre (subreceta)".
     * Se extrae el paréntesis final para poder mostrar junto a la cantidad si son
     * gramos, mililitros o piezas: sin eso se captura a ciegas.
     */
    function unidadDe(label) {
        var m = String(label || '').match(/\(([^()]+)\)\s*$/);
        if (!m) return '';
        return m[1] === 'subreceta' ? 'u' : m[1];
    }

    /** Marca la fila como completa y refleja la unidad junto a la cantidad. */
    function marcarFila(picker, label) {
        var row = picker.closest('[data-recipe-row]');
        if (!row) return;
        var slot = row.querySelector('[data-recipe-unit]');
        if (slot) slot.textContent = unidadDe(label);
        row.classList.toggle('is-complete', !!label);
    }

    function readOptions(form) {
        var node = form.querySelector('[data-recipe-options]');
        if (!node) return [];
        try {
            var parsed = JSON.parse(node.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Inicial con la que se agrupa una etiqueta. Se normalizan los acentos para
     * que "Ñoquis" y "Ajo" caigan donde el cocinero los busca, y todo lo que no
     * empiece por letra va a "#".
     */
    function inicialDe(label) {
        var texto = String(label || '').trim();
        if (!texto) return '#';
        var letra = texto.charAt(0).toUpperCase();
        if (letra.normalize) {
            // NFD separa la letra de su tilde; el rango son las marcas
            // combinantes, que se descartan. Así "Ñ" cae en N y "Á" en A.
            letra = letra.normalize('NFD').replace(/[̀-ͯ]/g, '');
        }
        return /^[A-Z]$/.test(letra) ? letra : '#';
    }

    // ── Modal compartido ──────────────────────────────────────────
    // Uno por página: lo abre la fila que se esté editando y devuelve el valor
    // a esa misma fila.
    function crearPicker() {
        var modal = document.querySelector('[data-ingredient-picker]');
        if (!modal) return null;

        var tabsEl   = modal.querySelector('[data-picker-tabs]');
        var gridEl   = modal.querySelector('[data-picker-grid]');
        var searchEl = modal.querySelector('[data-picker-search]');
        if (!tabsEl || !gridEl) return null;

        var opciones = [];
        var destino  = null;   // el .admin-picker que abrió el modal
        var ultimoFoco = null;
        var inicialActiva = 'todos';

        function visibles() {
            var q = (searchEl && searchEl.value || '').trim().toLowerCase();
            return opciones.filter(function (o) {
                if (q) return String(o.label).toLowerCase().indexOf(q) !== -1;
                if (inicialActiva === 'todos') return true;
                return inicialDe(o.label) === inicialActiva;
            });
        }

        function pintarTabs() {
            // Solo se ofrecen las iniciales que tienen algo detrás: una pestaña
            // vacía es una promesa incumplida.
            var iniciales = [];
            opciones.forEach(function (o) {
                var i = inicialDe(o.label);
                if (iniciales.indexOf(i) === -1) iniciales.push(i);
            });
            iniciales.sort(function (a, b) { return a.localeCompare(b, 'es'); });

            tabsEl.innerHTML = '';
            ['todos'].concat(iniciales).forEach(function (clave) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'admin-ingredient-picker__tab' +
                    (clave === inicialActiva ? ' is-active' : '');
                btn.setAttribute('role', 'tab');
                btn.setAttribute('aria-selected', clave === inicialActiva ? 'true' : 'false');
                btn.dataset.inicial = clave;
                btn.textContent = clave === 'todos' ? 'Todos' : clave;
                tabsEl.appendChild(btn);
            });
        }

        function pintarGrid() {
            var lista = visibles();
            gridEl.innerHTML = '';

            if (!lista.length) {
                var vacio = document.createElement('p');
                vacio.className = 'admin-ingredient-picker__empty';
                vacio.textContent = 'Sin coincidencias';
                gridEl.appendChild(vacio);
                return;
            }

            lista.forEach(function (o) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'admin-ingredient-picker__option';
                btn.dataset.value = o.value;
                btn.innerHTML = '<span class="admin-ingredient-picker__option-name"></span>' +
                    (o.group ? '<span class="admin-ingredient-picker__option-group"></span>' : '');
                btn.querySelector('.admin-ingredient-picker__option-name').textContent = o.label;
                if (o.group) {
                    btn.querySelector('.admin-ingredient-picker__option-group').textContent = o.group;
                }
                gridEl.appendChild(btn);
            });
        }

        function abrir(picker, opcionesFila) {
            destino = picker;
            opciones = opcionesFila;
            ultimoFoco = picker.querySelector('[data-picker-open]');
            inicialActiva = 'todos';
            if (searchEl) searchEl.value = '';

            pintarTabs();
            pintarGrid();

            modal.hidden = false;
            // AdminScrollLock para además del overflow. Sin pararlo, Lenis
            // seguía desplazando el fondo con la rueda estando el modal abierto.
            if (window.AdminScrollLock) {
                window.AdminScrollLock.bloquear();
            } else {
                document.body.style.overflow = 'hidden';
            }
            window.requestAnimationFrame(function () {
                modal.classList.add('is-open');
                if (searchEl) searchEl.focus();
            });
        }

        function cerrar() {
            if (modal.hidden) {
                return;
            }

            modal.classList.remove('is-open');
            if (window.AdminScrollLock) {
                window.AdminScrollLock.desbloquear();
            } else {
                document.body.style.overflow = '';
            }
            window.setTimeout(function () { modal.hidden = true; }, 220);
            destino = null;
            if (ultimoFoco) ultimoFoco.focus();
        }

        function elegir(valor) {
            var opcion = opciones.filter(function (o) {
                return String(o.value) === String(valor);
            })[0];
            if (!opcion || !destino) return;

            var picker = destino;
            picker.querySelector('[data-picker-value]').value = opcion.value;
            picker.querySelector('[data-picker-label]').textContent = opcion.label;
            marcarFila(picker, opcion.label);

            var row = picker.closest('[data-recipe-row]');
            cerrar();

            // Elegido el ingrediente, lo siguiente es la cantidad: se lleva el
            // foco solo, para no obligar a un clic extra por fila.
            var cant = row && row.querySelector('input[type="number"]');
            if (cant) {
                ultimoFoco = null;
                cant.focus();
            }
        }

        tabsEl.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-inicial]');
            if (!tab) return;
            inicialActiva = tab.dataset.inicial;
            // Filtrar por letra y por texto a la vez confunde: al tocar una
            // pestaña se limpia la búsqueda.
            if (searchEl) searchEl.value = '';
            pintarTabs();
            pintarGrid();
        });

        gridEl.addEventListener('click', function (event) {
            var opt = event.target.closest('[data-value]');
            if (opt) elegir(opt.dataset.value);
        });

        if (searchEl) {
            searchEl.addEventListener('input', function () {
                // Buscar recorre todo el catálogo, no solo la letra abierta.
                if (searchEl.value.trim() && inicialActiva !== 'todos') {
                    inicialActiva = 'todos';
                    pintarTabs();
                }
                pintarGrid();
            });
        }

        Array.prototype.forEach.call(modal.querySelectorAll('[data-picker-close]'), function (el) {
            el.addEventListener('click', cerrar);
        });

        document.addEventListener('keydown', function (event) {
            if (modal.hidden) return;
            if (event.key === 'Escape') cerrar();
        });

        return { abrir: abrir };
    }

    function initBuilder(form, picker) {
        var rows = form.querySelector('[data-recipe-rows]');
        var template = form.querySelector('[data-recipe-template]');
        var addBtn = form.querySelector('[data-recipe-add]');
        var options = readOptions(form);

        // Filas guardadas: reflejan de entrada su unidad y su estado.
        Array.prototype.forEach.call(form.querySelectorAll('[data-picker]'), function (p) {
            var valor = p.querySelector('[data-picker-value]');
            var label = p.querySelector('[data-picker-label]');
            if (valor && valor.value && label) marcarFila(p, label.textContent);
        });

        form.addEventListener('click', function (event) {
            var abrir = event.target.closest('[data-picker-open]');
            if (!abrir || !picker) return;
            picker.abrir(abrir.closest('[data-picker]'), options);
        });

        if (!rows || !template || !addBtn) return;

        addBtn.addEventListener('click', function () {
            var clone = template.content
                ? template.content.firstElementChild.cloneNode(true)
                : template.firstElementChild.cloneNode(true);
            rows.appendChild(clone);

            // Se abre el selector de una vez: agregar una fila vacía y tener
            // que tocarla otra vez era un paso de más.
            var nuevo = clone.querySelector('[data-picker]');
            if (nuevo && picker) picker.abrir(nuevo, options);
        });

        rows.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-recipe-remove]');
            if (remove) {
                var row = remove.closest('[data-recipe-row]');
                if (row) row.remove();
            }
        });
    }

    /**
     * 1234.5 → «1,234.5»: el mismo formato que los number_format de la vista,
     * para que la cifra no cambie de forma al escribir. A mano y no con
     * toLocaleString, que depende del idioma del navegador.
     */
    function formatoCantidad(valor, decimales) {
        var negativo = valor < 0;
        var partes = Math.abs(valor).toFixed(decimales).split('.');
        var entero = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var fraccion = decimales === 2 ? '.' + partes[1] : (partes[1] || '').replace(/0+$/, '');
        if (decimales !== 2 && fraccion) fraccion = '.' + fraccion;
        return (negativo ? '−' : '') + entero + fraccion;
    }

    /** A milésimas, como guarda la base las cantidades. */
    function milesimas(valor) {
        return Math.round(valor * 1000) / 1000;
    }

    /**
     * Ficha «Producir» de una subreceta por lote: reescala lo que sale de cada
     * ingrediente, lo que queda y el costo del lote a la cantidad escrita. Es
     * sólo la vista previa: el servidor recalcula al guardar.
     *
     * La cuenta es la de Inventario::producirSubreceta —lo que lleva el lote
     * × (producido ÷ rendimiento), redondeado a milésimas— y no la de una
     * cantidad por unidad ya redondeada: con ésa, 250 g de un lote de 3 l
     * volvían como 249.999 y la vista previa contradecía al servidor.
     */
    function initProduccion(form) {
        var campo = form.querySelector('[data-produccion-cantidad]');
        var costo = form.querySelector('[data-produccion-costo]');
        var resumen = form.querySelector('[data-produccion-resumen]');
        var filas = form.querySelectorAll('[data-produccion-fila]');
        if (!campo || !filas.length) return;

        var rendimiento = parseFloat(form.getAttribute('data-rendimiento'));
        if (isNaN(rendimiento) || rendimiento <= 0) rendimiento = 1;
        var unidadSub = form.getAttribute('data-unidad') || '';
        var esperaResumen = null;

        function num(nodo, atributo) {
            var n = parseFloat(nodo.getAttribute(atributo));
            return isNaN(n) ? 0 : n;
        }

        // Devuelve lo que el lector de pantalla tiene que oír de esta cuenta.
        function pintar() {
            var cantidad = parseFloat(campo.value);
            var valida = !isNaN(cantidad) && cantidad > 0;
            var factor = valida ? cantidad / rendimiento : 0;
            var total = 0;
            var negativos = [];

            Array.prototype.forEach.call(filas, function (fila) {
                var unidad = fila.getAttribute('data-unidad') || '';
                var sale = milesimas(num(fila, 'data-cantidad') * factor);
                // También redondeado: una resta de flotantes que debía dar cero
                // encendía el aviso y pintaba «−0».
                var queda = milesimas(num(fila, 'data-stock') - sale);
                total += sale * num(fila, 'data-costo');

                fila.querySelector('[data-produccion-sale]').textContent = formatoCantidad(sale, 3) + ' ' + unidad;
                fila.querySelector('[data-produccion-queda]').textContent = formatoCantidad(queda, 3) + ' ' + unidad;
                var negativo = fila.querySelector('[data-produccion-negativo]');
                if (negativo) negativo.hidden = queda >= 0;
                if (queda < 0) {
                    var nombre = fila.querySelector('[data-produccion-nombre]');
                    negativos.push(nombre ? nombre.textContent.trim() : '');
                }
            });

            if (costo) {
                costo.textContent = '$' + formatoCantidad(total, 2);
            }

            if (!valida) return 'Escribe una cantidad mayor a cero.';
            return 'Con ' + formatoCantidad(cantidad, 3) + ' ' + unidadSub + ', el lote cuesta $' +
                formatoCantidad(total, 2) + '. ' +
                (negativos.length
                    ? 'Quedarían en negativo: ' + negativos.join(', ') + '.'
                    : 'Ningún ingrediente queda en negativo.');
        }

        campo.addEventListener('input', function () {
            var texto = pintar();
            if (!resumen) return;
            // Se anuncia al dejar de escribir, no con cada tecla.
            window.clearTimeout(esperaResumen);
            esperaResumen = window.setTimeout(function () {
                resumen.textContent = texto;
            }, 700);
        });
        // Sin anuncio: es lo que el servidor ya pintó, salvo que el navegador
        // haya repuesto otra cantidad al volver atrás.
        pintar();
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-produccion]'), initProduccion);

        var forms = document.querySelectorAll('[data-recipe-builder]');
        if (!forms.length) return;
        var picker = crearPicker();
        Array.prototype.forEach.call(forms, function (form) {
            initBuilder(form, picker);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
