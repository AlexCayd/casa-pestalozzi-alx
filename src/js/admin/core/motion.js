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

        lenis.on('scroll', function () {
            if (window.ScrollTrigger) { window.ScrollTrigger.update(); }
        });

        window.gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
        window.gsap.ticker.lagSmoothing(0);
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
