/**
 * Interactividad de movimiento del panel admin (enfoque productividad).
 * Reveals escalonados con GSAP/ScrollTrigger, botones magnéticos en CTAs y
 * smooth-scroll Lenis suave y opcional. Respeta prefers-reduced-motion y
 * degrada con elegancia si las librerías CDN no cargan.
 */
(function () {
    var root = document.documentElement;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var isTouch = window.matchMedia('(pointer: coarse)').matches;

    // Selectores que reciben reveal automáticamente (además de [data-reveal]).
    var AUTO_REVEAL = [
        '.admin-page__header',
        '.admin-page-header',
        '.admin-card',
        '.admin-panel',
        '.admin-reservations__metric',
        '.admin-grid > *'
    ].join(',');

    function showEverything() {
        root.classList.remove('admin-anim-ready');
    }

    function initReveals() {
        if (!window.gsap || !window.ScrollTrigger) {
            showEverything();
            return;
        }

        window.gsap.registerPlugin(window.ScrollTrigger);

        document.querySelectorAll(AUTO_REVEAL).forEach(function (el) {
            if (!el.hasAttribute('data-reveal') && !el.closest('[data-reveal]')) {
                el.setAttribute('data-reveal', '');
            }
        });

        var els = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));

        if (!els.length) {
            showEverything();
            return;
        }

        window.ScrollTrigger.batch(els, {
            start: 'top 90%',
            once: true,
            onEnter: function (batch) {
                window.gsap.to(batch, {
                    opacity: 1,
                    y: 0,
                    duration: 0.7,
                    ease: 'power3.out',
                    stagger: 0.08,
                    overwrite: true,
                    onStart: function () {
                        batch.forEach(function (el) { el.classList.add('is-in'); });
                    }
                });
            }
        });

        // Salvavidas: revela cualquier elemento que siga oculto tras 1.8s.
        window.setTimeout(function () {
            els.forEach(function (el) {
                if (!el.classList.contains('is-in')) {
                    el.classList.add('is-in');
                    window.gsap.set(el, { opacity: 1, y: 0 });
                }
            });
            // Quitar el translateY(24px) de golpe a media página cambia el alto
            // del documento, y el límite que Lenis midió al arrancar se queda
            // corto.
            window.AdminScrollLock.remedir();
        }, 1800);
    }

    function initMagnetic() {
        if (isTouch || reduce || !window.gsap) {
            return;
        }

        // CTAs primarios reciben magnetismo automáticamente, más cualquier
        // elemento marcado explícitamente con [data-admin-magnetic].
        document.querySelectorAll('[data-admin-magnetic], .admin-btn--primary, .admin-menu__button--primary').forEach(function (el) {
            el.addEventListener('mousemove', function (event) {
                var rect = el.getBoundingClientRect();
                var x = event.clientX - rect.left - rect.width / 2;
                var y = event.clientY - rect.top - rect.height / 2;
                window.gsap.to(el, { x: x * 0.28, y: y * 0.28, duration: 0.5, ease: 'power3.out' });
            });

            el.addEventListener('mouseleave', function () {
                window.gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: 'elastic.out(1, 0.4)' });
            });
        });
    }

    /*
     * Contenedores del panel con scroll propio.
     *
     * Lenis intercepta el evento wheel del documento y lo cancela salvo dentro
     * de un elemento marcado con [data-lenis-prevent]. Sin la marca, la rueda
     * desplaza la página y el contenedor de debajo se queda quieto: había que
     * arrastrar la barra a mano para leer la tabla de reglas de asociación, un
     * modal largo o la lista de horas. Se marcan aquí, en un solo sitio, en vez
     * de repetir el atributo en cada vista.
     */
    var SCROLLABLES = [
        // Scroll propio de verdad (height:100vh + overflow-y:auto). Ya venía
        // marcado a mano en views/admin/partials/_sidebar.php; se registra aquí
        // para que el inventario de contenedores con scroll esté completo en un
        // solo sitio.
        '.admin-sidebar',
        '.admin-modal',
        // El diálogo de confirmación se monta en <body> fuera de .admin-modal:
        // sin marcarlo, la rueda encima de él la capturaba Lenis y el diálogo
        // no se movía mientras la página de detrás sí.
        '.confirmation-modal',
        '.admin-range__pop',
        '.admin-select__list',
        '.cp-calendar',
        '.hour-dropdown',
        '.admin-area-col__items',
        '.mapa-reservas-list',
        '[data-scrollable]'
    ].join(',');

    /*
     * Marca sólo lo que de verdad desborda en vertical.
     *
     * `.admin-table-wrap` estaba en la lista de arriba y es `overflow-x: auto` a
     * secas: se llevaba la rueda de toda la tabla sin tener nada que desplazar,
     * y la página se plantaba justo donde empezaba el listado. Las tres que sí
     * desbordan (analíticas e inventario) traen el atributo escrito a mano en la
     * vista, así que el marcado automático sobraba.
     *
     * `data-lenis-auto` distingue lo que puso esta función de lo que escribió
     * una vista: sólo se retira lo propio. Un contenedor puede dejar de
     * desbordar (se filtró la tabla, se agrandó la ventana) y entonces hay que
     * devolverle la rueda a la página.
     */
    function marcarScrollables(raiz) {
        if (!raiz || raiz.nodeType !== 1) {
            raiz = document.body;
        }

        function evaluar(el) {
            var propio = el.hasAttribute('data-lenis-auto');

            // Un [data-lenis-prevent] escrito en la vista es una decisión
            // deliberada del autor de esa pantalla: ni se retira ni se adopta
            // como propio (adoptarlo lo dejaría expuesto a que un barrido
            // posterior lo quitara).
            if (el.hasAttribute('data-lenis-prevent') && !propio) {
                return;
            }

            if (el.scrollHeight > el.clientHeight + 1) {
                el.setAttribute('data-lenis-prevent', '');
                el.setAttribute('data-lenis-auto', '');
            } else if (propio) {
                el.removeAttribute('data-lenis-prevent');
                el.removeAttribute('data-lenis-auto');
            }
        }

        if (raiz.matches && raiz.matches(SCROLLABLES)) {
            evaluar(raiz);
        }
        var nodos = raiz.querySelectorAll(SCROLLABLES);
        for (var i = 0; i < nodos.length; i++) {
            evaluar(nodos[i]);
        }
    }

    /*
     * Bloqueo de scroll para modales.
     *
     * `overflow: hidden` en el <body> frena al usuario pero NO al scroll
     * programático, que es justo con el que Lenis desplaza la página: con un
     * modal abierto la rueda seguía moviendo el fondo y, al cerrarlo, la
     * posición ya no era la de antes ("el scroll se va hacia arriba").
     *
     * Lleva contador porque hay dos sistemas de modal (initAdminModals y
     * ConfirmationModal) que pueden solaparse. Antes cada uno reseteaba el
     * overflow por su cuenta y el cruce dejaba el body bloqueado sin ningún
     * modal abierto — el otro síntoma, "el scroll se traba".
     */
    var bloqueos = 0;
    var overflowPrevio = null;
    var lenisActivo = null;

    window.AdminScrollLock = {
        bloquear: function () {
            bloqueos++;
            if (bloqueos > 1) {
                return;
            }
            overflowPrevio = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            if (lenisActivo) { lenisActivo.stop(); }
        },
        desbloquear: function () {
            if (bloqueos === 0) {
                return;
            }
            bloqueos--;
            if (bloqueos > 0) {
                return;
            }
            document.body.style.overflow = overflowPrevio || '';
            overflowPrevio = null;
            if (lenisActivo) { lenisActivo.start(); }
            // Un modal alto puede haber cambiado el alto del documento mientras
            // estuvo abierto; sin remedir, Lenis vuelve con el límite viejo.
            window.AdminScrollLock.remedir();
        },
        /**
         * Salida de emergencia. Si un modal se destruye sin llamar a
         * desbloquear() —o si dos sistemas se cruzan— el contador se queda en
         * alto y la página aparece congelada sin nada abierto. Volver con el
         * botón atrás (bfcache) restaura el DOM con el bloqueo puesto, así que
         * ahí es donde más se nota.
         */
        reset: function () {
            bloqueos = 0;
            document.body.style.overflow = overflowPrevio || '';
            overflowPrevio = null;
            if (lenisActivo) { lenisActivo.start(); }
        },
        /** Tras cambiar el alto del documento, para que no clampee y salte. */
        remedir: function () {
            if (lenisActivo && typeof lenisActivo.resize === 'function') {
                lenisActivo.resize();
            }
            if (window.ScrollTrigger) { window.ScrollTrigger.refresh(); }
        }
    };

    window.addEventListener('pageshow', function (evento) {
        if (evento.persisted) {
            window.AdminScrollLock.reset();
        }
    });

    function initSmoothScroll() {
        // Suave y opcional: desactivado en táctil, reduced-motion o si la vista
        // pide scroll nativo con [data-admin-no-smooth] en <html>.
        if (isTouch || reduce || !window.Lenis || !window.gsap) {
            return;
        }
        if (root.hasAttribute('data-admin-no-smooth')) {
            return;
        }

        var lenis = new window.Lenis({
            duration: 1.0,
            smoothWheel: true,
            easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); }
        });
        lenisActivo = lenis;

        lenis.on('scroll', function () {
            if (window.ScrollTrigger) { window.ScrollTrigger.update(); }
        });

        window.gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
        window.gsap.ticker.lagSmoothing(0);

        // Si ya había un modal abierto cuando arrancó el motor, respétalo.
        if (bloqueos > 0) {
            lenis.stop();
        }

        marcarScrollables(document.body);

        /*
         * Lenis cachea el límite de scroll y sólo lo recalcula cuando se le
         * dice. Si el documento crece después de arrancar —las gráficas de
         * Chart.js dimensionan tarde, los reveals sueltan su translateY(24px),
         * una fila de tabla se despliega— el motor sigue frenando en el límite
         * viejo: la página se detiene a media altura aunque la barra nativa
         * muestre que queda contenido. Observar el contenedor real es lo que
         * cierra esa clase entera de fallos, venga de donde venga el cambio.
         */
        var remedirPendiente = null;
        function remedirDebounce() {
            if (remedirPendiente) { window.clearTimeout(remedirPendiente); }
            remedirPendiente = window.setTimeout(function () {
                remedirPendiente = null;
                marcarScrollables(document.body);
                window.AdminScrollLock.remedir();
            }, 150);
        }

        var contenido = document.querySelector('.admin-content');
        if (contenido && typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(remedirDebounce).observe(contenido);
        }
        window.addEventListener('resize', remedirDebounce);

        /*
         * Reevaluar justo bajo el puntero, en fase de captura.
         *
         * Un modal o un desplegable que se abre alternando [hidden] no dispara
         * el MutationObserver (cambia un atributo, no la lista de hijos) y
         * mientras estuvo oculto medía cero, así que el barrido lo había
         * descartado por "no desborda". Comprobarlo en el momento del wheel
         * evita perseguir a cada componente que abre algo.
         *
         * La captura en `document` corre antes que el listener de Lenis, que
         * está en `window` sin capture y por tanto burbujea el último.
         */
        document.addEventListener('wheel', function (evento) {
            var destino = evento.target;
            if (!destino || !destino.closest) {
                return;
            }
            var candidato = destino.closest(SCROLLABLES);
            if (candidato) {
                marcarScrollables(candidato);
            }
        }, { capture: true, passive: true });

        // Los filtros reactivos sustituyen el listado entero: el documento
        // cambia de alto y Lenis anima hacia el nuevo límite si no se le avisa.
        document.addEventListener('admin:reactive-updated', function (evento) {
            marcarScrollables((evento.detail && evento.detail.target) || document.body);
            window.AdminScrollLock.remedir();
        });

        // Solo los nodos que entran: re-escanear el documento entero en cada
        // mutación de <body> costaba un querySelectorAll completo por frame.
        // `subtree` es obligatorio: sin él sólo se veían los hijos directos de
        // <body>, y todo lo que se inyecta dentro de .admin-content —que es casi
        // todo— nunca llegaba a marcarse.
        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(function (mutaciones) {
                for (var i = 0; i < mutaciones.length; i++) {
                    var nuevos = mutaciones[i].addedNodes;
                    for (var j = 0; j < nuevos.length; j++) {
                        marcarScrollables(nuevos[j]);
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }

        // Las fuentes de Google llegan después del DOMContentLoaded y recolocan
        // todo el layout: sin este refresh, ScrollTrigger conserva las posiciones
        // que midió antes del swap.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                window.AdminScrollLock.remedir();
            });
        }
    }

    function boot() {
        if (reduce) {
            showEverything();
            return;
        }
        initReveals();
        initMagnetic();
        initSmoothScroll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
