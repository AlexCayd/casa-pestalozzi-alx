/**
 * Conmutador de tema (claro/oscuro) del panel de administración.
 * Persiste la preferencia en localStorage y sincroniza el atributo
 * data-admin-theme en <html>. Emite el evento `admin:themechange` para que
 * otros módulos (p. ej. las gráficas de analytics) reaccionen.
 */
(function () {
    var STORAGE_KEY = 'cp-admin-theme';
    var root = document.documentElement;

    function currentTheme() {
        return root.getAttribute('data-admin-theme') === 'dark' ? 'dark' : 'light';
    }

    function persist(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // localStorage puede no estar disponible en contextos restringidos.
        }
    }

    function updateToggle(button, theme) {
        var isDark = theme === 'dark';
        var label = isDark ? 'Activar tema claro' : 'Activar tema oscuro';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(isDark));
    }

    function applyTheme(theme) {
        root.setAttribute('data-admin-theme', theme);
        persist(theme);
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
            updateToggle(button, theme);
        });
        window.dispatchEvent(new CustomEvent('admin:themechange', { detail: { theme: theme } }));
    }

    /**
     * Cambia el tema abriendo un círculo desde el botón que se pulsó.
     *
     * `document.startViewTransition` no existe en todos los navegadores, así que
     * NUNCA se llama a pelo: este guardián ejecuta el cambio en seco cuando
     * falta la API o cuando hay movimiento reducido. Es el mismo patrón que
     * conTransicion()/conMorfo() en src/js/modules/lightbox.js — llamarla
     * directamente dejaría sin conmutador al resto de navegadores.
     *
     * El radio es la distancia del botón a la esquina más lejana: así el círculo
     * cubre la pantalla entera sin importar dónde esté el conmutador.
     */
    function conTransicion(origen, cambiar) {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var raiz = document.documentElement;

        /*
         * Congelar TODAS las transiciones mientras se intercambia el tema.
         *
         * Es el arreglo del tirón a media animación. El círculo dura 480 ms,
         * pero .admin-body transiciona `background` y `color` en 260 ms y hay
         * otras cuarenta transiciones de background/border-color repartidas por
         * los componentes. La instantánea "nueva" del View Transition se captura
         * al PRINCIPIO del intercambio, cuando esos colores van por la mitad:
         * el círculo revelaba un tema que todavía no había llegado, y al
         * terminar el DOM real seguía interpolando por su cuenta. De ahí el
         * salto siempre en el mismo punto.
         *
         * Con la clase puesta, lo único que se mueve es el clip-path —que corre
         * en el compositor—, y de paso se ahorran cuarenta transiciones de
         * pintura disparadas a la vez.
         */
        function congelar() {
            raiz.classList.add('admin-theme-switching');
        }

        function descongelar() {
            raiz.classList.remove('admin-theme-switching');
        }

        if (reduce || typeof document.startViewTransition !== 'function') {
            congelar();
            cambiar();
            // Dos fotogramas: uno para que el navegador aplique el tema nuevo y
            // otro para que lo pinte antes de devolver las transiciones. Sin la
            // espera, quitar la clase en el mismo turno las reactiva a tiempo
            // de que el cambio se vuelva a animar.
            requestAnimationFrame(function () {
                requestAnimationFrame(descongelar);
            });
            return;
        }

        var r = origen.getBoundingClientRect();
        var x = r.left + r.width / 2;
        var y = r.top + r.height / 2;
        var radio = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );

        congelar();

        /*
         * El tipo 'tema' distingue esta transición de la de navegación entre
         * módulos: las reglas de ::view-transition-*(root) que apagan el fundido
         * sólo deben aplicarse a ésta (ver _finishes.scss, bloque 4b).
         *
         * La forma con objeto es MÁS NUEVA que la propia API —llegó en Chrome
         * 125, y startViewTransition existe desde la 111—, así que en ese tramo
         * de versiones pasar un objeto rompería: no es invocable. Se prueba y se
         * cae a la firma con callback, donde no hay tipos y el bloque 4b actúa
         * sobre todas las transiciones, que es exactamente como se comportaba
         * antes de esta ronda.
         */
        var transicion;
        try {
            transicion = document.startViewTransition({
                update: cambiar,
                types: ['tema']
            });
        } catch (e) {
            transicion = document.startViewTransition(cambiar);
        }

        // finished resuelve tanto si la animación termina como si se salta;
        // `skipTransition` en un cambio rápido de ida y vuelta pasa por aquí
        // igual, así que las transiciones siempre se devuelven.
        transicion.finished.then(descongelar, descongelar);

        transicion.ready.then(function () {
            document.documentElement.animate(
                { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)',
                             'circle(' + radio + 'px at ' + x + 'px ' + y + 'px)'] },
                {
                    duration: 480,
                    easing: 'cubic-bezier(.16,1,.3,1)',
                    // Sólo la instantánea nueva se recorta; la vieja se queda
                    // quieta debajo, que es lo que da la sensación de revelado
                    // en vez de un fundido.
                    pseudoElement: '::view-transition-new(root)'
                }
            );
        }).catch(function () {
            // Si la animación no llega a arrancar, el tema ya cambió igualmente.
        });
    }

    function initThemeToggle() {
        var toggles = document.querySelectorAll('[data-admin-theme-toggle]');

        if (!toggles.length) {
            return;
        }

        toggles.forEach(function (button) {
            updateToggle(button, currentTheme());
            button.addEventListener('click', function () {
                var siguiente = currentTheme() === 'dark' ? 'light' : 'dark';
                conTransicion(button, function () {
                    applyTheme(siguiente);
                });
            });
        });
    }

    // Sincroniza entre pestañas abiertas del panel.
    window.addEventListener('storage', function (event) {
        if (event.key === STORAGE_KEY && event.newValue) {
            var theme = event.newValue === 'dark' ? 'dark' : 'light';
            if (theme !== currentTheme()) {
                root.setAttribute('data-admin-theme', theme);
                document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
                    updateToggle(button, theme);
                });
                window.dispatchEvent(new CustomEvent('admin:themechange', { detail: { theme: theme } }));
            }
        }
    });

    document.addEventListener('DOMContentLoaded', initThemeToggle);
})();
