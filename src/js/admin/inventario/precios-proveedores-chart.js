(function () {
    'use strict';

    function textoPunto(punto) {
        var fecha = punto.dataset.date || '';
        var precio = '$' + punto.dataset.value + ' por ' + punto.dataset.unit;
        var diferencia = punto.dataset.difference;
        var variacion = punto.dataset.variation;
        var cambios = diferencia === ''
            ? 'sin cambio comparable en el registro base'
            : 'diferencia ' + (diferencia.charAt(0) === '-' ? '−$' + diferencia.slice(1) : '+$' + diferencia) +
                (variacion === '' ? '; porcentaje no disponible' : '; ' + (Number(variacion) > 0 ? '+' : '') + variacion + '%');
        return fecha + ' · ' + precio + ' · ' + cambios;
    }

    function iniciarGrafica(contenedor) {
        var puntos = Array.prototype.slice.call(contenedor.querySelectorAll('[data-price-point]'));
        var salida = contenedor.querySelector('[data-price-tooltip]');
        var svg = contenedor.querySelector('svg');
        var seleccion = -1;
        if (!puntos.length || !salida || !svg) return;

        function ajustarMarcadores() {
            var matriz = svg.getScreenCTM();
            if (!matriz) return;
            var escalaX = Math.hypot(matriz.a, matriz.b);
            var escalaY = Math.hypot(matriz.c, matriz.d);
            if (!escalaX || !escalaY) return;
            puntos.forEach(function (punto) {
                punto.setAttribute('rx', 3.5 / escalaX);
                punto.setAttribute('ry', 3.5 / escalaY);
            });
        }
        ajustarMarcadores();
        window.addEventListener('resize', ajustarMarcadores);
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(ajustarMarcadores).observe(svg);


        function publicar(indice, fijar) {
            if (indice < 0 || indice >= puntos.length) return;
            puntos.forEach(function (punto, i) {
                punto.classList.toggle('is-preview', !fijar && i === indice);
                punto.classList.toggle('is-seleccionado', fijar ? i === indice : i === seleccion);
                if (fijar) {
                    punto.setAttribute('aria-pressed', i === indice ? 'true' : 'false');
                    punto.tabIndex = i === indice ? 0 : -1;
                }
            });
            if (fijar) seleccion = indice;
            salida.textContent = textoPunto(puntos[indice]);
        }

        function enfocar(indice) {
            indice = Math.max(0, Math.min(puntos.length - 1, indice));
            puntos.forEach(function (punto, i) { punto.tabIndex = i === indice ? 0 : -1; });
            puntos[indice].focus();
            publicar(indice, false);
        }

        function puntoCercano(evento) {
            var matriz = svg.getScreenCTM();
            if (!matriz) return -1;
            var menor = Infinity;
            var elegido = -1;
            puntos.forEach(function (punto, indice) {
                var x = Number(punto.getAttribute('cx'));
                var y = Number(punto.getAttribute('cy'));
                var dx = matriz.a * x + matriz.c * y + matriz.e - evento.clientX;
                var dy = matriz.b * x + matriz.d * y + matriz.f - evento.clientY;
                var distancia = dx * dx + dy * dy;
                if (distancia < menor) { menor = distancia; elegido = indice; }
            });
            return elegido;
        }

        svg.addEventListener('pointermove', function (evento) {
            if (evento.pointerType !== 'mouse') return;
            var cercano = puntoCercano(evento);
            if (cercano >= 0) publicar(cercano, false);
        });
        svg.addEventListener('pointerdown', function (evento) {
            if (evento.pointerType !== 'touch') return;
            var cercano = puntoCercano(evento);
            if (cercano >= 0) publicar(cercano, true);
        });

        puntos.forEach(function (punto, indice) {
            punto.addEventListener('focus', function () { publicar(indice, false); });
            punto.addEventListener('pointerenter', function (evento) {
                if (evento.pointerType === 'mouse') publicar(indice, false);
            });
            punto.addEventListener('click', function () { publicar(indice, true); });
            punto.addEventListener('keydown', function (evento) {
                if (evento.key === 'ArrowRight' || evento.key === 'ArrowUp') {
                    evento.preventDefault(); enfocar(indice + 1);
                } else if (evento.key === 'ArrowLeft' || evento.key === 'ArrowDown') {
                    evento.preventDefault(); enfocar(indice - 1);
                } else if (evento.key === 'Home') {
                    evento.preventDefault(); enfocar(0);
                } else if (evento.key === 'End') {
                    evento.preventDefault(); enfocar(puntos.length - 1);
                } else if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault(); publicar(indice, true);
                } else if (evento.key === 'Escape') {
                    seleccion = -1;
                    puntos.forEach(function (otro) {
                        otro.classList.remove('is-preview', 'is-seleccionado');
                        otro.setAttribute('aria-pressed', 'false');
                    });
                    salida.textContent = contenedor.dataset.priceHelp || 'Enfoca, recorre o toca un punto para consultar su fecha, precio y variación.';
                }
            });
        });

        if (seleccion < 0) salida.textContent = contenedor.dataset.priceHelp || salida.textContent;
    }

    function iniciar() {
        document.querySelectorAll('[data-price-chart]').forEach(iniciarGrafica);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    else iniciar();
})();
