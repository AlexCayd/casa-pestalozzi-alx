/**
 * Filas equilibradas para un grupo de pestañas que envuelve
 * ([data-filas-equilibradas], hoy las categorías del formulario de platillo).
 *
 * Con flex-wrap el navegador llena la primera fila hasta donde cabe y deja el
 * resto abajo: dieciocho categorías salían 11 arriba y 7 abajo. Aquí se
 * simula ese mismo reparto (mismos anchos, mismo hueco) para saber cuántas
 * filas va a haber y se rehace en ESAS filas, nunca en más:
 *   1. Con el mismo número de pestañas por fila (9 y 9; 6, 6 y 6; 5, 5, 4 y
 *      4), si así caben: es lo que se pidió.
 *   2. Si no, con los LARGOS lo más parecidos posible (10 y 8 cuando el 9 y
 *      9 no entra): lo que el ojo compara es dónde acaba cada fila.
 * Las dos salen de la misma cuenta (repartir), y la segunda siempre tiene
 * solución porque el propio reparto del flex es una.
 *
 * No es una rejilla a propósito: con columnas alineadas cada columna mide su
 * pestaña más ancha, y en anchos medianos eso añadía filas (en tablet, cinco
 * en vez de tres). Cada fila es un contenedor flex propio
 * (.admin-tabs__fila) y la pestaña sigue midiendo su nombre.
 *
 * Mover los <label> a sus filas no toca el formulario: los radios conservan
 * su estado y sus escuchas, y las flechas recorren el grupo por su `name`.
 * Se mide el TEXTO con un Range, no la caja, así que medir no exige deshacer
 * el reparto vigente. Sin JS, el flex-wrap.
 *
 * Viaja en admin.js porque Menú no tiene bundle propio (como foto-platillo.js);
 * sin el atributo en la página, sale en la primera línea.
 */
(function () {
    'use strict';

    var grupos = document.querySelectorAll('[data-filas-equilibradas]');
    if (!grupos.length || !document.createRange) {
        return;
    }

    var CLASE = 'is-equilibrada';
    var CLASE_FILA = 'admin-tabs__fila';

    function px(valor) {
        var n = parseFloat(valor);
        return isNaN(n) ? 0 : n;
    }

    function anchoNatural(item, rango) {
        var etiqueta = item.querySelector('span') || item;
        var estilo = window.getComputedStyle(etiqueta);
        rango.selectNodeContents(etiqueta);
        // Sin redondear: un margen por pestaña se acumula a lo largo de la
        // fila (dieciocho veces) y la simulación contaba filas que el flex no
        // arma. Una fracción de píxel de error no se ve: la fila es nowrap y
        // la pastilla encoge esa fracción.
        return rango.getBoundingClientRect().width
            + px(estilo.paddingLeft) + px(estilo.paddingRight)
            + px(estilo.borderLeftWidth) + px(estilo.borderRightWidth);
    }

    // Cuántas filas arma el flex-wrap: llena cada una hasta donde cabe.
    function filasDelFlex(anchos, disponible, hueco) {
        var filas = 1;
        var linea = 0;
        anchos.forEach(function (ancho) {
            var conHueco = linea === 0 ? ancho : linea + hueco + ancho;
            if (linea > 0 && conHueco > disponible) {
                filas++;
                linea = ancho;
            } else {
                linea = conHueco;
            }
        });
        return filas;
    }

    /*
     * Parte las pestañas en `filas` tramos seguidos, ninguno más largo que
     * `disponible`, con los largos lo más parecidos posible (mínima suma de
     * cuadrados). `minimo`/`maximo` acotan cuántas pestañas lleva cada tramo:
     * con floor/ceil de n ÷ filas sale el reparto por número; sin cota, el
     * reparto por largo. Programación dinámica: n² × filas, con n ≈ 20.
     * Devuelve las cuentas por fila, o null si no hay forma.
     */
    function repartir(anchos, filas, disponible, hueco, minimo, maximo) {
        var n = anchos.length;
        var acumulado = [0];
        var i;
        var f;
        var j;
        for (i = 0; i < n; i++) {
            acumulado.push(acumulado[i] + anchos[i]);
        }
        var coste = [];
        var corte = [];
        for (f = 0; f <= filas; f++) {
            coste.push([]);
            corte.push([]);
            for (i = 0; i <= n; i++) {
                coste[f].push(Infinity);
                corte[f].push(-1);
            }
        }
        coste[0][0] = 0;
        for (f = 1; f <= filas; f++) {
            for (i = f; i <= n; i++) {
                for (j = f - 1; j < i; j++) {
                    var cuenta = i - j;
                    var largo = acumulado[i] - acumulado[j] + (cuenta - 1) * hueco;
                    if (coste[f - 1][j] === Infinity || largo > disponible
                        || cuenta < minimo || cuenta > maximo) {
                        continue;
                    }
                    var total = coste[f - 1][j] + largo * largo;
                    if (total < coste[f][i]) {
                        coste[f][i] = total;
                        corte[f][i] = j;
                    }
                }
            }
        }
        if (coste[filas][n] === Infinity) {
            return null;
        }
        var cuentas = [];
        for (i = n, f = filas; f > 0; f--) {
            j = corte[f][i];
            cuentas.unshift(i - j);
            i = j;
        }
        return cuentas;
    }

    function decidir(anchos, disponible, hueco) {
        var filas = filasDelFlex(anchos, disponible, hueco);
        if (filas < 2) {
            return null;
        }
        var n = anchos.length;
        return repartir(anchos, filas, disponible, hueco, Math.floor(n / filas), Math.ceil(n / filas))
            || repartir(anchos, filas, disponible, hueco, 1, n);
    }

    function iniciar(grupo) {
        // Las pestañas, en su orden original: después de agrupar ya no son
        // hijas directas del grupo.
        var items = Array.prototype.slice.call(grupo.children);
        var firma = '';

        function aplicar(cuentas) {
            var nueva = cuentas ? cuentas.join(',') : '';
            if (nueva === firma) {
                return;
            }
            firma = nueva;

            // Quitar un nodo enfocado del DOM le quita el foco: se devuelve.
            var activo = grupo.contains(document.activeElement) ? document.activeElement : null;

            Array.prototype.slice.call(grupo.querySelectorAll('.' + CLASE_FILA)).forEach(function (fila) {
                fila.remove();
            });

            if (!cuentas) {
                items.forEach(function (item) { grupo.appendChild(item); });
                grupo.classList.remove(CLASE);
            } else {
                var i = 0;
                cuentas.forEach(function (cuenta) {
                    var fila = document.createElement('div');
                    fila.className = CLASE_FILA;
                    // La guarda de `items.length` no debería hacer falta (las
                    // cuentas suman las visibles), pero un appendChild(undefined)
                    // cortaría el resto de admin.js, que es un concat.
                    for (var n = 0; n < cuenta && i < items.length; i++) {
                        fila.appendChild(items[i]);
                        // Una pestaña oculta viaja con su vecina pero no cuenta.
                        if (!items[i].hidden) {
                            n++;
                        }
                    }
                    grupo.appendChild(fila);
                });
                // Las ocultas del final, detrás de la última fila.
                for (; i < items.length; i++) {
                    grupo.lastChild.appendChild(items[i]);
                }
                grupo.classList.add(CLASE);
            }

            if (activo && activo !== document.activeElement && activo.focus) {
                activo.focus({ preventScroll: true });
            }
        }

        function equilibrar() {
            var estilo = window.getComputedStyle(grupo);
            var disponible = grupo.clientWidth - px(estilo.paddingLeft) - px(estilo.paddingRight);
            if (disponible <= 0) {
                return;
            }
            var visibles = items.filter(function (item) { return !item.hidden; });
            if (visibles.length < 2) {
                aplicar(null);
                return;
            }
            var rango = document.createRange();
            var anchos = visibles.map(function (item) { return anchoNatural(item, rango); });
            aplicar(decidir(anchos, disponible, px(estilo.columnGap)));
        }

        var ultimoAncho = -1;
        var pendiente = 0;
        function programar() {
            if (pendiente) {
                return;
            }
            pendiente = window.requestAnimationFrame(function () {
                pendiente = 0;
                equilibrar();
            });
        }

        equilibrar();

        // La medida depende de la cara: antes de que cargue Satoshi el texto
        // mide lo que la fuente de respaldo.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(programar);
        }

        // Plegar el sidebar cambia el ancho sin que la ventana cambie de
        // tamaño. Sólo el ANCHO decide: el alto lo cambia el propio reparto.
        if (window.ResizeObserver) {
            new ResizeObserver(function (entradas) {
                var ancho = Math.round(entradas[0].contentRect.width);
                if (ancho !== ultimoAncho) {
                    ultimoAncho = ancho;
                    programar();
                }
            }).observe(grupo);
        } else {
            window.addEventListener('resize', programar);
        }
    }

    Array.prototype.forEach.call(grupos, iniciar);
})();
