/**
 * La llegada tras iniciar sesión.
 *
 * El marcado (views/components/llegada.php) sólo existe en la PRIMERA página
 * que se pinta después del login, así que en cualquier otra carga esto sale en
 * la primera línea. Viaja en dos bundles porque son dos los destinos posibles:
 * admin.js (panel) y bundle.min.js (POS y tableros, que cargan éste).
 *
 * La coreografía, en tres tiempos:
 *   1. El nombre toma el centro. Lo hace la transición de documento cruzado:
 *      el login nombra su marca `cp-marca` en `pageswap` y el velo trae la suya
 *      con ese mismo nombre, así que el navegador la lleva de un sitio a otro.
 *      No hay JS en este tiempo.
 *   2. Se sostiene (PAUSA) con el saludo debajo.
 *   3. Vuela a la marca de la pantalla mientras el velo se cierra sobre ella.
 *      Es una View Transition de mismo documento con el tipo 'llegada', y cada
 *      PALABRA es un grupo propio: CASA va de centrada a su sitio aunque el
 *      destino la alinee distinto, sin deformarse.
 *
 * Todo se puede saltar con cualquier tecla o toque. Sin la API, sin tipos de
 * transición o con movimiento reducido, el CSS retira el velo solo y aquí sólo
 * se recoge el nodo al terminar.
 */
(function (window, document) {
    'use strict';

    var capa = document.querySelector('[data-llegada]');
    if (!capa) {
        return;
    }

    var raiz = document.documentElement;

    // Desde la primera pintura. La toma del centro dura ~460 ms, así que el
    // nombre queda quieto unos 300 ms: lo justo para leerlo, no para esperar.
    var PAUSA = 760;
    // Duración del cierre del velo. Las palabras llegan al 80 % (CSS, bloque de
    // la llegada en _transitions.scss) y el último 20 % es el fundido final.
    var VUELO = 820;

    var terminado = false;
    var transicion = null;
    var temporizador = null;
    var pares = [];
    var restaurarPalabras = null;

    /*
     * Fase pública, para quien tenga que coordinarse (core/motion.js retrasa
     * los reveals del panel hasta 'sale', para que las tarjetas suban mientras
     * el velo se abre y no debajo de él). Atributo y evento: el atributo lo lee
     * quien llegue tarde; el evento, quien ya estaba escuchando.
     */
    function avisar(fase) {
        if (capa.getAttribute('data-llegada-fase') === fase) {
            return;
        }
        capa.setAttribute('data-llegada-fase', fase);
        var evento;
        try {
            evento = new CustomEvent('cp-llegada:' + fase);
        } catch (error) {
            evento = document.createEvent('CustomEvent');
            evento.initCustomEvent('cp-llegada:' + fase, false, false, null);
        }
        document.dispatchEvent(evento);
    }

    function retirar() {
        if (terminado) {
            return;
        }
        terminado = true;
        window.clearTimeout(temporizador);
        quitarEscuchas();
        avisar('sale');
        if (capa.parentNode) {
            capa.parentNode.removeChild(capa);
        }
        avisar('fin');
    }

    /*
     * Saltar. Antes del vuelo, un fundido de 160 ms y no un corte: de la tinta
     * del velo al panel claro en un fotograma es un fogonazo. El velo sigue
     * recibiendo el puntero mientras se funde, así que el toque que lo salta
     * no cae a ciegas sobre un botón que todavía no se ve.
     */
    function saltar() {
        quitarEscuchas();
        window.clearTimeout(temporizador);
        if (transicion) {
            try { transicion.skipTransition(); } catch (error) { terminar(); }
            return;
        }
        avisar('sale');
        if (typeof capa.animate === 'function') {
            var fundido = capa.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'ease-out', fill: 'forwards' });
            fundido.onfinish = retirar;
            fundido.oncancel = retirar;
        } else {
            retirar();
        }
    }

    function escuchar() {
        document.addEventListener('pointerdown', saltar, true);
        document.addEventListener('keydown', saltar, true);
    }

    function quitarEscuchas() {
        document.removeEventListener('pointerdown', saltar, true);
        document.removeEventListener('keydown', saltar, true);
    }

    function animacionPropia() {
        if (typeof capa.getAnimations !== 'function') {
            return null;
        }
        var lista = capa.getAnimations();
        for (var i = 0; i < lista.length; i++) {
            if (lista[i].animationName === 'cp-llegada-fuera') {
                return lista[i];
            }
        }
        return lista[0] || null;
    }

    function aLaVista(el) {
        if (!el || !el.getClientRects().length) {
            return false;
        }
        var r = el.getBoundingClientRect();
        if (r.width < 4 || r.height < 4) {
            return false;
        }
        // El sidebar del móvil es un cajón trasladado fuera de la pantalla:
        // existe, mide y es visible para el CSS, pero no se ve.
        if (r.right <= 0 || r.bottom <= 0 || r.left >= window.innerWidth || r.top >= window.innerHeight) {
            return false;
        }
        var estilo = window.getComputedStyle(el);
        return estilo.visibility !== 'hidden' && parseFloat(estilo.opacity) > 0.05;
    }

    function buscarDestino() {
        var selector = capa.getAttribute('data-llegada-destino');
        if (!selector) {
            return null;
        }
        var candidatos;
        try {
            candidatos = document.querySelectorAll(selector);
        } catch (error) {
            return null;
        }
        for (var i = 0; i < candidatos.length; i++) {
            if (aLaVista(candidatos[i])) {
                return candidatos[i];
            }
        }
        return null;
    }

    /*
     * Las dos palabras del destino, cada una en su caja.
     *
     * El sidebar ya las trae (es el mismo componente de wordmark). La barra
     * del piso escribe «CASA PESTALOZZI» en un solo nodo de texto, y un grupo
     * de View Transition no puede ser un trozo de texto: se envuelven en dos
     * spans mientras dura el vuelo y se devuelve el nodo original al terminar.
     * Con `inline-block` y el mismo espacio entre ellas, la línea no se mueve
     * un píxel.
     */
    function palabrasDelDestino(destino) {
        var propias = destino.querySelectorAll('.hcp-header__palabra');
        if (propias.length === 2) {
            return { palabras: [propias[0], propias[1]], restaurar: null };
        }

        var caminante = document.createTreeWalker(destino, 4 /* NodeFilter.SHOW_TEXT */, null, false);
        var nodo;
        while ((nodo = caminante.nextNode())) {
            var partes = /^(\s*)(CASA)(\s+)(PESTALOZZI)(\s*)$/i.exec(nodo.nodeValue || '');
            if (!partes) {
                continue;
            }
            var padre = nodo.parentNode;
            var casa = document.createElement('span');
            var pestalozzi = document.createElement('span');
            casa.className = 'cp-llegada-palabra';
            pestalozzi.className = 'cp-llegada-palabra';
            casa.textContent = partes[2];
            pestalozzi.textContent = partes[4];

            var insertados = [];
            var poner = function (n) {
                padre.insertBefore(n, nodo);
                insertados.push(n);
            };
            if (partes[1]) { poner(document.createTextNode(partes[1])); }
            poner(casa);
            poner(document.createTextNode(partes[3]));
            poner(pestalozzi);
            if (partes[5]) { poner(document.createTextNode(partes[5])); }
            padre.removeChild(nodo);

            var original = nodo;
            return {
                palabras: [casa, pestalozzi],
                restaurar: function () {
                    if (!insertados.length || !insertados[0].parentNode) {
                        return;
                    }
                    insertados[0].parentNode.insertBefore(original, insertados[0]);
                    for (var i = 0; i < insertados.length; i++) {
                        if (insertados[i].parentNode) {
                            insertados[i].parentNode.removeChild(insertados[i]);
                        }
                    }
                    insertados = [];
                }
            };
        }
        return null;
    }

    /*
     * El velo se CIERRA sobre la marca de destino: un círculo que encoge hasta
     * rodearla y se funde. Es el gesto del cambio de tema al revés, y no es
     * adorno: el nombre vuela en papel sobre la tinta del velo, y si el velo se
     * fundiera entero a la vez, en el tema claro el nombre pasaría por un gris
     * idéntico al fondo a mitad de camino. Encogiendo hacia donde va, siempre
     * tiene tinta detrás hasta que llega, y entonces cambia de color con el
     * fundido final.
     */
    function cerrarVelo(foco) {
        var x = foco.left + foco.width / 2;
        var y = foco.top + foco.height / 2;
        var lejos = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );
        var cerca = Math.max(foco.width, foco.height) / 2 + 28;
        function circulo(radio) {
            return 'circle(' + radio + 'px at ' + x + 'px ' + y + 'px)';
        }
        document.documentElement.animate([
            { clipPath: circulo(lejos), opacity: 1, offset: 0, easing: 'cubic-bezier(0.7, 0, 0.2, 1)' },
            { clipPath: circulo(cerca), opacity: 1, offset: 0.8, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' },
            { clipPath: circulo(cerca), opacity: 0, offset: 1 }
        ], {
            duration: VUELO,
            fill: 'both',
            pseudoElement: '::view-transition-old(root)'
        });
    }

    function terminar() {
        for (var i = 0; i < pares.length; i++) {
            pares[i].hasta.style.viewTransitionName = '';
        }
        pares = [];
        raiz.classList.remove('cp-llegada-vuelo');
        if (restaurarPalabras) {
            restaurarPalabras();
            restaurarPalabras = null;
        }
        retirar();
    }

    function volar() {
        if (terminado) {
            return;
        }

        var marca = capa.querySelector('.cp-llegada__marca');
        var delVelo = capa.querySelectorAll('.hcp-header__palabra');
        var destino = buscarDestino();
        var porPalabra = null;

        if (destino && marca && delVelo.length === 2) {
            porPalabra = palabrasDelDestino(destino);
        }

        if (porPalabra) {
            restaurarPalabras = porPalabra.restaurar;
            pares.push({ desde: delVelo[0], hasta: porPalabra.palabras[0], nombre: 'cp-marca-casa' });
            pares.push({ desde: delVelo[1], hasta: porPalabra.palabras[1], nombre: 'cp-marca-pestalozzi' });
            // El nombre del bloque (el del primer tiempo) se apaga: ahora
            // viajan las palabras por separado.
            marca.style.viewTransitionName = 'none';
            for (var i = 0; i < pares.length; i++) {
                pares[i].desde.style.viewTransitionName = pares[i].nombre;
            }
        } else if (destino && marca) {
            // El monograma del sidebar plegado no tiene palabras: el bloque
            // entero se resuelve en él.
            pares.push({ desde: marca, hasta: destino, nombre: 'cp-marca' });
        }
        // Sin destino (POS, móvil), la marca conserva su `cp-marca` y, como no
        // hay pareja en el estado nuevo, se apaga en su sitio (CSS :only-child).

        var foco = destino ? destino.getBoundingClientRect() : null;

        avisar('sale');

        var actualizar = function () {
            capa.style.display = 'none';
            raiz.classList.add('cp-llegada-vuelo');
            for (var j = 0; j < pares.length; j++) {
                pares[j].hasta.style.viewTransitionName = pares[j].nombre;
            }
        };

        try {
            transicion = document.startViewTransition({ update: actualizar, types: ['llegada'] });
        } catch (error) {
            // Sin la forma con tipos el CSS de la llegada no se aplicaría y el
            // velo desaparecería de golpe: mejor el corte limpio de siempre.
            actualizar();
            terminar();
            return;
        }

        if (foco) {
            transicion.ready.then(function () { cerrarVelo(foco); }).catch(function () {});
        }
        transicion.finished.then(terminar, terminar);
    }

    // ── Arranque ─────────────────────────────────────────────────────
    var reducido = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false;

    // Los tipos llegaron después que la API (Chrome 125 frente a 111). Sin
    // ellos las reglas de esta transición no se distinguen de las de las otras
    // dos, así que no se intenta.
    var conTipos = false;
    try {
        conTipos = !!(window.CSS && window.CSS.supports
            && window.CSS.supports('selector(:active-view-transition-type(llegada))'));
    } catch (error) {}

    var animacion = animacionPropia();
    // El retardo de la salida CSS se lee de la propia animación: no hay un
    // segundo número que mantener sincronizado con _transitions.scss.
    var retardo = 0;
    if (animacion && animacion.effect && typeof animacion.effect.getTiming === 'function') {
        retardo = Number(animacion.effect.getTiming().delay) || 0;
    }
    var transcurrido = animacion && typeof animacion.currentTime === 'number' ? animacion.currentTime : 0;

    // Sin su hoja (una versión vieja en caché) el velo sigue con el `hidden`
    // del marcado: no hay nada que coreografiar, sólo recoger el nodo.
    if (window.getComputedStyle(capa).display === 'none') {
        retirar();
        return;
    }

    // Salvavidas: pase lo que pase, el velo no se queda.
    window.setTimeout(retirar, 6000);
    escuchar();

    if (reducido || !conTipos || typeof document.startViewTransition !== 'function'
        || transcurrido > retardo - 80) {
        // El CSS lleva el velo hasta el final; aquí sólo se avisa y se recoge.
        temporizador = window.setTimeout(function () { avisar('sale'); }, Math.max(0, retardo - transcurrido));
        capa.addEventListener('animationend', function (evento) {
            if (evento.target === capa) {
                retirar();
            }
        });
        // Por si `animationend` no llega (pestaña en segundo plano).
        window.setTimeout(retirar, Math.max(0, retardo - transcurrido) + 1200);
        return;
    }

    // El JS toma el mando: la salida CSS se anula y el vuelo sale a su hora.
    capa.classList.add('is-js');
    temporizador = window.setTimeout(volar, Math.max(0, PAUSA - transcurrido));
})(window, document);
