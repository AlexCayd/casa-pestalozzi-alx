/**
 * La llegada tras iniciar sesión: «subir la cortina».
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
 *   2. Se sostiene (PAUSA) con el saludo debajo, la luz encendiéndose detrás y
 *      el filete abriéndose como reloj.
 *   3. La cortina sube en ola, paño a paño, empezando por el que cubre la marca
 *      de la pantalla, y el nombre vuela en arco hasta ella: primero sube con
 *      la cortina y después se desliza a su sitio. Cada PALABRA vuela por
 *      separado, así que CASA va de centrada a su sitio aunque el destino la
 *      alinee distinto.
 *
 * El tercer tiempo ya no es una View Transition. Lo era, y tenía dos cosas en
 * el hilo principal —el `clip-path` del velo y el ancho y alto de los grupos—
 * justo cuando el panel arranca sus gráficas: se trababa. Ahora es WAAPI sobre
 * el propio velo y sólo mueve `transform`, que va por el compositor. De paso
 * funciona igual donde no hay tipos de View Transition.
 *
 * El color del nombre no se sincroniza: el centro se funde con `difference`
 * (_transitions.scss), así que sale papel sobre la tinta y tinta sobre el
 * panel claro, y llega a la marca ya en su color.
 *
 * Todo se puede saltar con cualquier tecla o toque. Sin WAAPI o con
 * movimiento reducido, el CSS retira el velo solo y aquí sólo se recoge el
 * nodo al terminar.
 */
(function (window, document) {
    'use strict';

    var capa = document.querySelector('[data-llegada]');
    if (!capa) {
        return;
    }

    var cortina = capa.querySelector('[data-llegada-cortina]');
    var centro = capa.querySelector('.cp-llegada__centro');
    var marca = capa.querySelector('.cp-llegada__marca');

    // Desde la primera pintura. La toma del centro dura 560 ms, así que el
    // nombre queda quieto unos 300: lo justo para leer el saludo, y lo que
    // tarda el filete en abrirse.
    var PAUSA = 860;
    // El vuelo de cada palabra. PESTALOZZI sale un instante después que CASA:
    // el bloque se despliega en vez de moverse en una pieza.
    var VUELO = 780;
    var DESFASE = 45;
    // Cada paño, y el escalón entre uno y el siguiente. El primero dura menos
    // que el vuelo: tiene que haber destapado la marca cuando el nombre llega.
    // Con 720 y 55 la ola se alargaba a 940 ms y la llegada entera a 1.8 s,
    // más que la anterior; con estos queda en ~1.65 s en el panel y ~1.6 en
    // el piso, y el escalón todavía se lee como ola. Los repite el modo sin JS
    // (_transitions.scss).
    var SUBIDA = 640;
    var ESCALON = 40;
    // Ida y vuelta para los paños (arrancan y se posan, como una cortina con
    // peso); el vuelo despega más rápido y se posa largo.
    var CURVA_SUBIDA = 'cubic-bezier(0.76, 0, 0.24, 1)';
    var CURVA_VUELO = 'cubic-bezier(0.62, 0, 0.18, 1)';
    // Cuánto se curva el vuelo: el punto de control está casi encima del
    // destino en vertical y cerca del origen en horizontal, así que la palabra
    // sube primero y se desliza después. 0 sería una recta.
    var ARCO = 0.82;
    var PASOS = 20;

    var terminado = false;
    var saliendo = false;
    var temporizador = null;
    var animaciones = [];
    var destinoOculto = null;

    function lista(coleccion) {
        return Array.prototype.slice.call(coleccion || []);
    }

    // Una promesa que se cumple al terminar o al cancelar: un salto no debe
    // dejar la llegada colgada esperando a una animación que ya no existe.
    function alTerminar(animacion) {
        if (!animacion || !animacion.finished) {
            return Promise.resolve();
        }
        return animacion.finished.then(function () {}, function () {});
    }

    /*
     * Fase pública, para quien tenga que coordinarse (core/motion.js retrasa
     * los reveals del panel hasta 'sale', para que las tarjetas suban mientras
     * la cortina se abre y no debajo de ella). Atributo y evento: el atributo
     * lo lee quien llegue tarde; el evento, quien ya estaba escuchando.
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

    function mostrarDestino() {
        if (destinoOculto) {
            destinoOculto.el.style.visibility = destinoOculto.antes;
            destinoOculto = null;
        }
    }

    function retirar() {
        if (terminado) {
            return;
        }
        terminado = true;
        window.clearTimeout(temporizador);
        quitarEscuchas();
        mostrarDestino();
        avisar('sale');
        if (capa.parentNode) {
            capa.parentNode.removeChild(capa);
        }
        avisar('fin');
    }

    /*
     * Saltar. Con la cortina ya subiendo, todo se adelanta a su final (el
     * nombre aterriza y los paños desaparecen en el mismo fotograma). Antes,
     * un fundido de 160 ms y no un corte: de la tinta al panel claro en un
     * fotograma es un fogonazo. La cortina sigue recibiendo el puntero
     * mientras se funde, así que el toque que la salta no cae a ciegas sobre
     * un botón que todavía no se ve.
     */
    function saltar() {
        quitarEscuchas();
        window.clearTimeout(temporizador);
        if (saliendo) {
            animaciones.forEach(function (animacion) {
                try { animacion.finish(); } catch (error) { retirar(); }
            });
            return;
        }
        avisar('sale');
        var capas = [cortina, centro].filter(Boolean);
        if (capas.length && typeof capas[0].animate === 'function') {
            Promise.all(capas.map(function (el) {
                return alTerminar(el.animate([{ opacity: 1 }, { opacity: 0 }], {
                    duration: 160,
                    easing: 'ease-out',
                    fill: 'forwards'
                }));
            })).then(retirar);
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

    function primerTexto(el) {
        var caminante = document.createTreeWalker(el, 4 /* NodeFilter.SHOW_TEXT */, null, false);
        var nodo;
        while ((nodo = caminante.nextNode())) {
            if (/\S/.test(nodo.nodeValue || '')) {
                return nodo;
            }
        }
        return null;
    }

    // La caja del TEXTO, no la del elemento: es lo único que mide lo mismo en
    // el velo y en el destino (en el sidebar cada palabra es un bloque del
    // ancho de PESTALOZZI; en la barra del piso las dos comparten un nodo).
    function cajaDeTexto(nodo, desde, hasta) {
        if (!nodo) {
            return null;
        }
        var rango = document.createRange();
        if (typeof desde === 'number') {
            rango.setStart(nodo, desde);
            rango.setEnd(nodo, hasta);
        } else {
            rango.selectNodeContents(nodo);
        }
        var r = rango.getBoundingClientRect();
        return r.width > 0 && r.height > 0 ? r : null;
    }

    function palabrasDelDestino(destino) {
        var propias = destino.querySelectorAll('.hcp-header__palabra');
        if (propias.length === 2) {
            var casa = cajaDeTexto(primerTexto(propias[0]));
            var pestalozzi = cajaDeTexto(primerTexto(propias[1]));
            return casa && pestalozzi ? [casa, pestalozzi] : null;
        }

        // La barra del piso escribe «CASA PESTALOZZI» en un solo nodo de
        // texto: se mide cada palabra con un rango sobre su trozo, sin tocar
        // el marcado.
        var caminante = document.createTreeWalker(destino, 4, null, false);
        var nodo;
        while ((nodo = caminante.nextNode())) {
            var partes = /^(\s*)(CASA)(\s+)(PESTALOZZI)\s*$/i.exec(nodo.nodeValue || '');
            if (!partes) {
                continue;
            }
            var iCasa = partes[1].length;
            var iPest = iCasa + partes[2].length + partes[3].length;
            var a = cajaDeTexto(nodo, iCasa, iCasa + partes[2].length);
            var b = cajaDeTexto(nodo, iPest, iPest + partes[4].length);
            return a && b ? [a, b] : null;
        }
        return null;
    }

    function ocultarDestino(el) {
        if (destinoOculto) {
            return;
        }
        destinoOculto = { el: el, antes: el.style.visibility };
        el.style.visibility = 'hidden';
    }

    /*
     * El vuelo de una palabra, de la caja de su texto en el velo a la del
     * destino. Escala desde la esquina superior izquierda del TEXTO (el
     * `transform-origin` se corre ahí) y traslada esa esquina a la del
     * destino, así que al final las dos cajas coinciden píxel a píxel.
     *
     * El arco es una Bézier cuadrática muestreada en PASOS fotogramas clave;
     * la curva de tiempo va en el efecto, no en cada tramo, así que la
     * velocidad es continua a lo largo de todo el recorrido.
     */
    function volar(el, desde, hasta, retraso) {
        var caja = el.getBoundingClientRect();
        var k = hasta.width / desde.width;
        var dx = hasta.left - desde.left;
        var dy = hasta.top - desde.top;
        var cx = dx * (1 - ARCO);
        var cy = dy * ARCO;
        var cuadros = [];

        for (var i = 0; i <= PASOS; i++) {
            var u = i / PASOS;
            var v = 1 - u;
            var x = 2 * v * u * cx + u * u * dx;
            var y = 2 * v * u * cy + u * u * dy;
            var s = 1 + (k - 1) * u;
            cuadros.push({
                transform: 'translate(' + x.toFixed(2) + 'px, ' + y.toFixed(2) + 'px) scale(' + s.toFixed(5) + ')'
            });
        }

        el.style.transformOrigin = (desde.left - caja.left).toFixed(2) + 'px '
            + (desde.top - caja.top).toFixed(2) + 'px';

        return el.animate(cuadros, {
            duration: VUELO,
            delay: retraso,
            easing: CURVA_VUELO,
            fill: 'both'
        });
    }

    /*
     * El monograma del sidebar plegado no tiene palabras: el nombre entero se
     * encoge hacia él y se apaga en el último tercio, mientras el monograma
     * aparece en su sitio.
     */
    function haciaElMonograma(destino) {
        var desde = marca.getBoundingClientRect();
        var hasta = destino.getBoundingClientRect();
        var k = Math.min(hasta.width / desde.width, hasta.height / desde.height);
        var dx = (hasta.left + hasta.width / 2) - (desde.left + desde.width / 2);
        var dy = (hasta.top + hasta.height / 2) - (desde.top + desde.height / 2);

        animaciones.push(destino.animate([{ opacity: 0 }, { opacity: 1 }], {
            duration: VUELO * 0.4,
            delay: VUELO * 0.6,
            easing: 'ease-out',
            fill: 'backwards'
        }));

        return marca.animate([
            { transform: 'none', opacity: 1, offset: 0 },
            { opacity: 1, offset: 0.6 },
            { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + k + ')', opacity: 0, offset: 1 }
        ], { duration: VUELO, easing: CURVA_VUELO, fill: 'both' });
    }

    /*
     * Los paños. Con la marca en una columna lateral (el sidebar), el primero
     * mide esa columna: la casa se abre por sus propias columnas y el paño que
     * destapa la marca es exactamente el que la cubre. El orden sale de la
     * distancia a la marca (o al centro, si no hay), en anchos de paño, así
     * que dos paños a la misma distancia suben a la vez.
     */
    function subirCortina(destino, origenX) {
        var panos = lista(cortina.querySelectorAll('.cp-llegada__pano')).filter(function (p) {
            return p.getClientRects().length > 0;
        });
        if (!panos.length) {
            return [];
        }

        var columna = destino && destino.closest ? destino.closest('.admin-sidebar') : null;
        if (columna && panos.length > 2) {
            var c = columna.getBoundingClientRect();
            if (c.left <= 1 && c.width > 0 && c.width < window.innerWidth * 0.4) {
                panos[0].style.flex = '0 0 ' + Math.round(c.width + 1) + 'px';
            }
        }

        var ancho = window.innerWidth / panos.length;
        var alto = window.innerHeight;

        return panos.map(function (pano) {
            var r = pano.getBoundingClientRect();
            var sombra = parseFloat(window.getComputedStyle(pano, '::after').height) || 0;
            var distancia = Math.abs((r.left + r.width / 2) - origenX) / ancho;
            var retraso = Math.round(Math.min(distancia, panos.length - 1) * ESCALON);
            return pano.animate([
                { transform: 'translateY(0)' },
                { transform: 'translateY(' + (-(alto + sombra + 2)) + 'px)' }
            ], {
                duration: SUBIDA,
                delay: retraso,
                easing: CURVA_SUBIDA,
                fill: 'both'
            });
        });
    }

    function salir() {
        if (saliendo || terminado) {
            return;
        }
        saliendo = true;
        window.clearTimeout(temporizador);

        var destino = buscarDestino();
        var origenX = window.innerWidth / 2;
        var vuelos = [];
        var palabras = marca ? marca.querySelectorAll('.hcp-header__palabra') : [];

        if (destino && marca) {
            var d = destino.getBoundingClientRect();
            origenX = d.left + d.width / 2;

            var finales = palabrasDelDestino(destino);
            var iniciales = palabras.length === 2
                ? [cajaDeTexto(primerTexto(palabras[0])), cajaDeTexto(primerTexto(palabras[1]))]
                : null;

            if (finales && iniciales && iniciales[0] && iniciales[1]) {
                vuelos.push(volar(palabras[0], iniciales[0], finales[0], 0));
                vuelos.push(volar(palabras[1], iniciales[1], finales[1], DESFASE));
                // La marca real espera escondida: en el aterrizaje cambia por
                // las palabras que vuelan en el mismo fotograma.
                ocultarDestino(destino);
            } else {
                vuelos.push(haciaElMonograma(destino));
            }
        } else if (marca) {
            // Sin destino (POS, panel en el teléfono): el nombre se apaga en
            // su sitio mientras la cortina se abre desde el centro.
            vuelos.push(marca.animate([
                { opacity: 1, transform: 'none' },
                { opacity: 0, transform: 'scale(0.94)' }
            ], { duration: 420, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', fill: 'both' }));
        }

        var subidas = cortina ? subirCortina(destino, origenX) : [];
        animaciones = animaciones.concat(vuelos, subidas);

        capa.classList.add('is-saliendo');
        avisar('sale');

        Promise.all(vuelos.map(alTerminar)).then(function () {
            if (marca) {
                marca.style.visibility = 'hidden';
            }
            mostrarDestino();
        });
        Promise.all(animaciones.map(alTerminar)).then(retirar);
    }

    // ── Arranque ─────────────────────────────────────────────────────
    var reducido = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false;

    var estilo = window.getComputedStyle(capa);

    // Sin su hoja el velo sigue con el `hidden` del marcado: no hay nada que
    // coreografiar, sólo recoger el nodo.
    if (estilo.display === 'none') {
        retirar();
        return;
    }

    function animacionDe(el) {
        if (!el || typeof el.getAnimations !== 'function') {
            return null;
        }
        var propias = el.getAnimations().filter(function (a) {
            return a.effect && a.effect.target === el;
        });
        return propias[0] || null;
    }

    // El retardo de la salida CSS se lee de la propia animación del centro:
    // no hay un segundo número que mantener sincronizado con _transitions.scss.
    var animacion = animacionDe(centro);
    var retardo = 0;
    if (animacion && animacion.effect && typeof animacion.effect.getTiming === 'function') {
        retardo = Number(animacion.effect.getTiming().delay) || 0;
    }
    var transcurrido = animacion && typeof animacion.currentTime === 'number' ? animacion.currentTime : 0;

    // Salvavidas: pase lo que pase, el velo no se queda.
    window.setTimeout(retirar, 6000);
    escuchar();

    if (reducido || typeof capa.animate !== 'function' || !cortina || !centro
        || estilo.display !== 'contents' || transcurrido > retardo - 80) {
        // El CSS lleva el velo hasta el final; aquí sólo se avisa y se recoge.
        // Lo último que termina es la cortina (su `visibility: hidden`).
        var finCortina = animacionDe(cortina);
        var duracion = finCortina && finCortina.effect && typeof finCortina.effect.getComputedTiming === 'function'
            ? Number(finCortina.effect.getComputedTiming().endTime) || 0
            : 0;
        temporizador = window.setTimeout(function () { avisar('sale'); }, Math.max(0, retardo - transcurrido));
        if (cortina) {
            cortina.addEventListener('animationend', function (evento) {
                if (evento.target === cortina) {
                    retirar();
                }
            });
        }
        // Por si `animationend` no llega (pestaña en segundo plano).
        window.setTimeout(retirar, Math.max(retardo, duracion) - transcurrido + 400);
        return;
    }

    // El JS toma el mando: la salida CSS se anula y la cortina sube a su hora.
    capa.classList.add('is-js');
    temporizador = window.setTimeout(salir, Math.max(0, PAUSA - transcurrido));
})(window, document);
