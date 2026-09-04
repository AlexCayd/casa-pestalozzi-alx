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

        agregar.addEventListener('click', function () {
            filas.appendChild(plantilla.content.cloneNode(true));
            renumerar();
            if (window.AdminScrollLock) {
                window.AdminScrollLock.remedir();
            }
        });

        raiz.addEventListener('click', function (event) {
            if (!event.target.closest('[data-proveedor-quitar]')) {
                return;
            }
            var fila = event.target.closest('.admin-proveedores__fila');
            if (fila) {
                fila.remove();
                renumerar();
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
