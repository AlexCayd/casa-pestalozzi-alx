/**
 * Campo «Foto del platillo» del formulario de Menú (views/admin/menu/form.php).
 *
 * Enseña al instante lo que la carta va a mostrar: la foto nueva en cuanto se
 * elige (o se arrastra), la de la categoría cuando el platillo no tiene la
 * suya —y la cambia al elegir otra categoría—, y el «Quitar foto» con su
 * «Deshacer». El servidor no depende de nada de esto: sin JS el input y la
 * casilla envían lo mismo y Services\Menu\FotoPlatillo resuelve.
 *
 * Viaja en admin.js y no en un bundle de Menú porque Menú no tiene bundle
 * propio (igual que historial-precios.js); sin [data-foto-platillo] en la
 * página, sale en la primera línea.
 */
(function () {
    'use strict';

    var campos = document.querySelectorAll('[data-foto-platillo]');
    if (!campos.length) {
        return;
    }

    // Espejo de ImagenUploader (MAX_BYTES y MIME_PERMITIDOS). El servidor
    // vuelve a comprobarlo; esto sólo evita esperar una subida de 20 MB para
    // enterarse de que no valía.
    var MAX_BYTES = 5 * 1024 * 1024;
    var TIPOS = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    function cita(nombre) {
        return nombre ? '«' + nombre + '»' : '';
    }

    function traeArchivos(evento) {
        var tipos = evento.dataTransfer && evento.dataTransfer.types;
        return !!tipos && Array.prototype.indexOf.call(tipos, 'Files') !== -1;
    }

    function enZona(nodo) {
        return !!(nodo && nodo.closest && nodo.closest('[data-foto-zona]'));
    }

    function problemaDe(archivo) {
        // Un tipo vacío (algunos AVIF en Windows) no se rechaza aquí: que lo
        // decida el servidor, que mira el contenido y no la extensión.
        if (archivo.type && TIPOS.indexOf(archivo.type) === -1) {
            return 'Formato no permitido. Usa JPG, PNG, WebP, GIF o AVIF.';
        }
        if (archivo.size > MAX_BYTES) {
            return 'La imagen pesa ' + (archivo.size / 1048576).toFixed(1) + ' MB; el máximo es 5 MB.';
        }
        return '';
    }

    function iniciar(campo) {
        var form = campo.closest('form');
        var input = campo.querySelector('[data-foto-input]');
        var img = campo.querySelector('[data-foto-img]');
        var vacia = campo.querySelector('[data-foto-vacia]');
        var estadoTxt = campo.querySelector('[data-foto-estado]');
        var elegirTxt = campo.querySelector('[data-foto-elegir-texto]');
        var quitar = campo.querySelector('[data-foto-quitar]');
        var quitarBoton = campo.querySelector('[data-foto-quitar-boton]');
        var deshacer = campo.querySelector('[data-foto-deshacer]');
        var error = campo.querySelector('[data-foto-error]');
        var zona = campo.querySelector('[data-foto-zona]');

        if (!input || !img || !vacia || !estadoTxt || !deshacer) {
            return;
        }

        // La pendiente (foto que sobrevivió a un POST fallido) gana a la
        // guardada: es la que se guardará si nadie la quita.
        var propia = campo.getAttribute('data-pendiente') || campo.getAttribute('data-actual') || '';
        var esPendiente = !!campo.getAttribute('data-pendiente');
        var urlNueva = '';
        var nombreNuevo = '';

        function categoria() {
            var radio = form ? form.querySelector('input[name="categoria_id"]:checked') : null;
            return {
                foto: radio ? radio.getAttribute('data-foto') || '' : '',
                nombre: radio ? radio.getAttribute('data-nombre') || '' : ''
            };
        }

        function soltarNueva() {
            if (urlNueva) {
                URL.revokeObjectURL(urlNueva);
            }
            urlNueva = '';
            nombreNuevo = '';
        }

        function ponerError(texto) {
            if (error) {
                error.textContent = texto;
            }
            campo.classList.toggle('has-error', !!texto);
        }

        function pintar() {
            var cat = categoria();
            var quitando = !urlNueva && !!(quitar && quitar.checked);
            var estado;
            var src;
            var texto;

            if (urlNueva) {
                estado = 'nueva';
                src = urlNueva;
                texto = 'Foto nueva' + (nombreNuevo ? ' (' + nombreNuevo + ')' : '') + ', todavía sin guardar: se guarda junto con el platillo.';
            } else if (propia && !quitando) {
                estado = esPendiente ? 'pendiente' : 'propia';
                src = propia;
                texto = esPendiente
                    ? 'Foto nueva, todavía sin guardar: se guarda junto con el platillo.'
                    : 'La carta muestra esta foto.';
            } else {
                estado = cat.foto ? 'categoria' : 'vacia';
                src = cat.foto;
                if (quitando) {
                    // Espejo de form.php: los dos textos tienen que coincidir.
                    if (cat.foto) {
                        texto = 'Se quitará al guardar. La carta volverá a la de ' + cita(cat.nombre) + '.';
                    } else if (cat.nombre) {
                        texto = 'Se quitará al guardar, y ' + cita(cat.nombre) + ' tampoco tiene foto.';
                    } else {
                        texto = 'Se quitará al guardar.';
                    }
                } else if (cat.foto) {
                    texto = 'Sin foto propia: la carta muestra la de ' + cita(cat.nombre) + '.';
                } else if (cat.nombre) {
                    texto = 'Ni el platillo ni ' + cita(cat.nombre) + ' tienen foto.';
                } else {
                    texto = 'Sin foto propia: la carta usará la de su categoría.';
                }
            }

            campo.setAttribute('data-estado', estado);

            if (src) {
                if (img.getAttribute('src') !== src) {
                    campo.classList.add('is-cargando');
                    img.setAttribute('src', src);
                }
                img.hidden = false;
                vacia.hidden = true;
            } else {
                // Quitar el src a media carga no dispara ni load ni error: sin
                // esto, la clase se quedaba puesta hasta la siguiente imagen.
                campo.classList.remove('is-cargando');
                img.removeAttribute('src');
                img.hidden = true;
                vacia.hidden = false;
            }

            // Sólo si cambia: la región es aria-live y reescribir el mismo texto
            // lo volvería a anunciar en algunos lectores.
            if (estadoTxt.textContent !== texto) {
                estadoTxt.textContent = texto;
            }
            if (elegirTxt) {
                elegirTxt.textContent = estado === 'categoria' || estado === 'vacia' ? 'Subir foto' : 'Cambiar foto';
            }
            if (quitarBoton) {
                quitarBoton.hidden = !propia || quitando || !!urlNueva;
            }
            deshacer.hidden = !urlNueva && !quitando;
        }

        img.addEventListener('load', function () {
            campo.classList.remove('is-cargando');
        });
        img.addEventListener('error', function () {
            campo.classList.remove('is-cargando');
        });

        input.addEventListener('change', function () {
            var archivo = input.files && input.files[0];
            soltarNueva();
            ponerError('');

            if (archivo) {
                var problema = problemaDe(archivo);
                if (problema) {
                    input.value = '';
                    ponerError(problema);
                } else {
                    urlNueva = URL.createObjectURL(archivo);
                    nombreNuevo = archivo.name || '';
                    // Elegir una foto nueva ya es reemplazar: «Quitar» deja de
                    // tener sentido y el servidor le daría prioridad al archivo.
                    if (quitar) {
                        quitar.checked = false;
                    }
                }
            }

            pintar();
        });

        if (quitar) {
            quitar.addEventListener('change', function () {
                if (quitar.checked) {
                    input.value = '';
                    soltarNueva();
                }
                ponerError('');
                pintar();
                // El botón que se acaba de pulsar desaparece; el foco pasa al
                // que lo deshace para no quedarse en un nodo oculto.
                if (quitar.checked) {
                    deshacer.focus();
                }
            });
        }

        deshacer.addEventListener('click', function () {
            if (urlNueva) {
                input.value = '';
                soltarNueva();
            } else if (quitar) {
                quitar.checked = false;
            }
            ponerError('');
            pintar();
            (quitarBoton && !quitarBoton.hidden ? quitar : input).focus();
        });

        // La categoría decide la foto por defecto: al cambiarla, la vista previa
        // tiene que decir la verdad sobre lo que enseñará la carta.
        if (form) {
            form.addEventListener('change', function (evento) {
                if (evento.target && evento.target.name === 'categoria_id') {
                    pintar();
                }
            });
        }

        if (zona) {
            zona.addEventListener('dragover', function (evento) {
                if (traeArchivos(evento)) {
                    evento.preventDefault();
                    zona.classList.add('is-arrastrando');
                }
            });
            zona.addEventListener('dragleave', function (evento) {
                if (!zona.contains(evento.relatedTarget)) {
                    zona.classList.remove('is-arrastrando');
                }
            });
            zona.addEventListener('drop', function (evento) {
                zona.classList.remove('is-arrastrando');
                if (!traeArchivos(evento)) {
                    return;
                }
                evento.preventDefault();

                // Sólo la primera: el campo es de una foto, y con varias PHP se
                // quedaría con la última sin avisar.
                try {
                    var transferencia = new DataTransfer();
                    transferencia.items.add(evento.dataTransfer.files[0]);
                    input.files = transferencia.files;
                } catch (e) {
                    return;
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        pintar();
    }

    for (var i = 0; i < campos.length; i++) {
        iniciar(campos[i]);
    }

    // Soltar la foto unos píxeles fuera de la zona hace que el navegador la
    // ABRA en la pestaña, y el formulario se va con todo lo escrito sin
    // preguntar. Fuera de la zona, arrastrar un archivo enseña el cursor de
    // «no se puede» y soltarlo no hace nada.
    document.addEventListener('dragover', function (evento) {
        if (traeArchivos(evento) && !enZona(evento.target)) {
            evento.preventDefault();
            evento.dataTransfer.dropEffect = 'none';
        }
    });
    document.addEventListener('drop', function (evento) {
        if (traeArchivos(evento) && !enZona(evento.target)) {
            evento.preventDefault();
        }
    });
})();
