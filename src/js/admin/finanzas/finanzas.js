/**
 * Finanzas: el diagnóstico del periodo y la descomposición del ingreso
 * (Sankey).
 *
 * Los datos del Sankey llegan desde PHP como window.AdminFinanzasData (ver
 * views/admin/finanzas/index.php). Se re-renderiza al cambiar el tema, igual
 * que las gráficas de analíticas, escuchando admin:themechange.
 *
 * El diagnóstico NO depende de este archivo para funcionar: los escalones de
 * la cascada son radios y el detalle visible lo decide :has() en el SCSS. Lo
 * que se añade aquí es lo que el CSS no puede hacer:
 *   · elegir un escalón aísla su cadena en el Sankey (y al revés, soltar el
 *     Sankey no deja la cascada mintiendo);
 *   · el respaldo con clases para los motores sin :has().
 *
 * Ya no hay dona de Chart.js: el reparto del gasto fijo es HTML en la sección
 * de gastos fijos y el módulo dejó de cargar la librería.
 */
(function () {
    var sankeyControl = null;
    // Lo que la cascada pidió resaltar. Se guarda porque el Sankey se
    // redibuja entero al cambiar de tamaño o de tema, y sin esto el resaltado
    // se perdía en el primer giro de la tablet.
    var seleccionSankey = null;

    function readToken(name, fallback) {
        var host = document.querySelector('.admin-body') || document.body;
        var value = getComputedStyle(host).getPropertyValue(name).trim();
        return value || fallback;
    }

    /**
     * El mismo código de color que la cascada del diagnóstico, para que los dos
     * dibujos del mismo dinero hablen igual: el dinero que entra y lo que queda
     * de él en tinta, lo que se consume en gris, y el color sólo en el
     * desenlace —verde si sobró, rojo si faltó—. Antes eran verde, naranja,
     * rojo y oro: un arcoíris donde el verde significaba «ingreso» aquí y
     * «ganancia» arriba.
     *
     * Los colores de estado salen de los tokens de gráfica (--admin-chart-*),
     * los mismos que lee analytics/charts.js.
     */
    // Sin hex de respaldo: la hoja va en el <head> y este bundle es `defer`,
    // así que los tokens siempre están resueltos cuando se lee. Si alguno
    // faltara, currentColor pinta en la tinta del panel en vez de inventar un
    // color fuera del sistema.
    function palette() {
        return {
            texto: readToken('--admin-text', 'currentColor'),
            dinero: readToken('--admin-text', 'currentColor'),
            consumo: readToken('--admin-faint', 'currentColor'),
            positivo: readToken('--admin-chart-ventas', 'currentColor'),
            negativo: readToken('--admin-chart-bajo', 'currentColor')
        };
    }

    function money(n) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency', currency: 'MXN', maximumFractionDigits: 0
        }).format(n || 0);
    }

    /**
     * La descomposición del ingreso. El dibujo lo hace sankey.js en SVG; aquí
     * queda lo que es de finanzas: qué color le toca a cada nodo y con qué
     * formato se escribe el dinero.
     */
    function renderSankey(data) {
        var contenedor = document.getElementById('flujoFinanciero');
        var flujos = data.sankey || [];
        if (!contenedor || !flujos.length || !window.AdminSankey) {
            return;
        }

        var pal = palette();
        var colores = {
            'Ingresos': pal.dinero,
            'Utilidad bruta': pal.dinero,
            'Costo de insumos': pal.consumo,
            'Merma': pal.consumo,
            'Utilidad neta': pal.positivo,
            // El tramo que falta cuando los fijos se comen la utilidad bruta.
            'Faltante': pal.negativo
        };

        sankeyControl = window.AdminSankey.render(contenedor, flujos, {
            // Las categorías de gasto caen al gris del consumo: en un Sankey el
            // ancho ya es el dato, y darle color a cada una haría creer que la
            // identidad importa.
            color: function (nombre) { return colores[nombre] || pal.consumo; },
            texto: pal.texto,
            moneda: money,
            // Con la tinta como color del ingreso, al 62 % de siempre la cinta
            // principal salía casi negra y aplastaba al resto del panel.
            opacidadCinta: 0.42,
            // Si el usuario suelta o cambia el resaltado desde el propio
            // Sankey, eso manda: al redibujar no se vuelve a imponer el de la
            // cascada.
            alFijar: function (lista) { seleccionSankey = lista; }
        });

        if (sankeyControl && seleccionSankey) {
            sankeyControl.resaltar(seleccionSankey);
        }
    }

    function renderAll() {
        var data = window.AdminFinanzasData;
        if (!data) {
            return;
        }
        renderSankey(data);
    }

    function nodosDe(radio) {
        try {
            var lista = JSON.parse(radio.getAttribute('data-nodos') || '[]');
            return lista && lista.length ? lista : null;
        } catch (error) {
            return null;
        }
    }

    function resaltarEnSankey(radio) {
        seleccionSankey = nodosDe(radio);
        if (sankeyControl) {
            sankeyControl.resaltar(seleccionSankey);
        }
    }

    /**
     * La cascada del diagnóstico. Guarda de existencia primero: este bundle
     * sólo se carga en Finanzas, pero una excepción aquí se llevaría también
     * el Sankey.
     */
    function initDiagnostico() {
        var raiz = document.querySelector('[data-fin-diag]');
        if (!raiz) {
            return;
        }

        var radios = raiz.querySelectorAll('input[name="fin-paso"]');
        var paneles = raiz.querySelectorAll('.fin-detalle__panel');
        var comparar = raiz.querySelector('[data-fin-comparar]');
        var detalle = raiz.querySelector('.fin-detalle');
        var cascada = raiz.querySelector('.fin-cascada');
        var reducido = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
        var porPuntero = false;

        /*
         * En columna única (teléfono, panel estrecho) el detalle queda debajo
         * de los seis renglones: un toque cambiaba algo que no se veía y
         * parecía que el renglón no respondía. Si el detalle no asoma, se
         * acerca lo justo para verlo entero ('nearest'), sin perder de vista
         * más cascada de la necesaria.
         *
         * Sólo con puntero: quien recorre la cascada con las flechas ya oye la
         * explicación (aria-describedby) y no quiere que la página salte a
         * cada tecla. Lado a lado no se mueve nada.
         */
        function acercarDetalle() {
            if (!detalle || !cascada || typeof detalle.scrollIntoView !== 'function') {
                return;
            }
            var cajaDetalle = detalle.getBoundingClientRect();
            if (cajaDetalle.top < cascada.getBoundingClientRect().bottom - 1) {
                return;
            }
            var alto = window.innerHeight || document.documentElement.clientHeight;
            if (cajaDetalle.top < alto - 160) {
                return;
            }
            detalle.scrollIntoView({
                behavior: reducido && reducido.matches ? 'auto' : 'smooth',
                block: 'nearest'
            });
        }

        raiz.addEventListener('pointerdown', function (evento) {
            porPuntero = !!(evento.target && evento.target.closest && evento.target.closest('.fin-paso'));
        });
        raiz.addEventListener('keydown', function () {
            porPuntero = false;
        });

        /*
         * Clases espejo del estado de los controles. Con :has() no hacen
         * falta —el SCSS sólo las lee dentro de @supports not selector(:has(*))—,
         * pero se mantienen siempre para que los dos caminos no puedan
         * contradecirse. Se leen del DOM y no se dan por supuestas: Firefox
         * restaura el radio marcado al recargar.
         */
        function sincronizar() {
            var elegido = raiz.querySelector('input[name="fin-paso"]:checked');
            var clave = elegido ? elegido.value : '';
            Array.prototype.forEach.call(radios, function (radio) {
                var fila = radio.closest('.fin-paso');
                if (fila) {
                    fila.classList.toggle('is-elegido', radio === elegido);
                }
            });
            Array.prototype.forEach.call(paneles, function (panel) {
                panel.classList.toggle('is-activo', panel.getAttribute('data-paso') === clave);
            });
            if (comparar) {
                raiz.classList.toggle('is-comparando', comparar.checked);
            }
        }

        raiz.addEventListener('change', function (evento) {
            var objetivo = evento.target;
            sincronizar();
            // Sólo una elección del usuario resalta el Sankey. El escalón que
            // viene marcado al entrar no lo toca: el diagrama tiene que abrir
            // completo.
            if (objetivo && objetivo.name === 'fin-paso') {
                resaltarEnSankey(objetivo);
            }
        });

        // Volver a pulsar el escalón ya elegido no dispara `change`, y es justo
        // lo que hace quien quiere ver el de por defecto en el flujo.
        raiz.addEventListener('click', function (evento) {
            var objetivo = evento.target;
            if (objetivo && objetivo.name === 'fin-paso' && objetivo.checked) {
                resaltarEnSankey(objetivo);
                if (porPuntero) {
                    porPuntero = false;
                    acercarDetalle();
                }
            }

            var enlace = objetivo && objetivo.closest ? objetivo.closest('[data-fin-ver-flujo]') : null;
            if (enlace) {
                var radio = raiz.querySelector('#fin-paso-' + enlace.getAttribute('data-fin-ver-flujo'));
                if (radio) {
                    resaltarEnSankey(radio);
                }
            }
        });

        sincronizar();
    }

    function init() {
        initDiagnostico();
        renderAll();
        document.addEventListener('admin:themechange', renderAll);

        /*
         * El SVG se calcula contra el ancho real del contenedor —es lo que
         * permite medir las etiquetas—, así que al cambiar de tamaño hay que
         * rehacerlo. Con debounce: durante un arrastre de ventana se disparan
         * decenas de eventos y cada uno reconstruye el diagrama entero.
         */
        var pendiente = null;
        function redibujar() {
            clearTimeout(pendiente);
            pendiente = setTimeout(renderAll, 180);
        }
        window.addEventListener('resize', redibujar);

        /*
         * Y un ResizeObserver sobre el propio contenedor, porque `resize` no
         * cubre el caso más frecuente: plegar el sidebar del panel cambia el
         * ancho disponible sin que la VENTANA cambie de tamaño, así que el
         * diagrama se quedaba dibujado contra la medida vieja —encogido en la
         * mitad izquierda, o cortado— hasta la siguiente recarga.
         *
         * La primera notificación llega al observar, y esa sobra: renderAll()
         * acaba de correr. Se descarta para no dibujarlo dos veces al cargar.
         */
        var contenedorSankey = document.getElementById('flujoFinanciero');
        if (contenedorSankey && typeof window.ResizeObserver === 'function') {
            var anchoPrevio = contenedorSankey.clientWidth;
            new window.ResizeObserver(function () {
                var anchoActual = contenedorSankey.clientWidth;
                if (anchoActual === anchoPrevio) return;
                anchoPrevio = anchoActual;
                redibujar();
            }).observe(contenedorSankey);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
