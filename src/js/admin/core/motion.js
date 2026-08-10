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
        '.admin-table-wrap',
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

    function marcarScrollables(raiz) {
        if (!raiz || raiz.nodeType !== 1) {
            raiz = document.body;
        }
        if (raiz.matches && raiz.matches(SCROLLABLES)) {
            raiz.setAttribute('data-lenis-prevent', '');
        }
        var nodos = raiz.querySelectorAll(SCROLLABLES);
        for (var i = 0; i < nodos.length; i++) {
            nodos[i].setAttribute('data-lenis-prevent', '');
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
        },
        /** Tras cambiar el alto del documento, para que no clampee y salte. */
        remedir: function () {
            if (lenisActivo && typeof lenisActivo.resize === 'function') {
                lenisActivo.resize();
            }
            if (window.ScrollTrigger) { window.ScrollTrigger.refresh(); }
        }
    };

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

        // Los filtros reactivos sustituyen el listado entero: el documento
        // cambia de alto y Lenis anima hacia el nuevo límite si no se le avisa.
        document.addEventListener('admin:reactive-updated', function (evento) {
            marcarScrollables((evento.detail && evento.detail.target) || document.body);
            window.AdminScrollLock.remedir();
        });

        // Solo los nodos que entran: re-escanear el documento entero en cada
        // mutación de <body> costaba un querySelectorAll completo por frame.
        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(function (mutaciones) {
                for (var i = 0; i < mutaciones.length; i++) {
                    var nuevos = mutaciones[i].addedNodes;
                    for (var j = 0; j < nuevos.length; j++) {
                        marcarScrollables(nuevos[j]);
                    }
                }
            }).observe(document.body, { childList: true });
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
