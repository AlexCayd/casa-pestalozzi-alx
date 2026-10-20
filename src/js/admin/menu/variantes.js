/**
 * Editor de variantes de platillo (Menú → Variantes).
 *
 * Todo el estado vive aquí y se guarda de una vez por JSON
 * (POST /admin/api/menu/variantes). Dos reglas de la casa que el editor
 * enseña en vez de esconder:
 *
 *  · Un grupo con opción predeterminada es la receta base: el POS la trae
 *    marcada y la comanda no la imprime. Un grupo SIN predeterminada es de
 *    elección obligatoria (el término de un corte).
 *  · «Cambiar» un ingrediente es proporcional: reemplaza la parte indicada de
 *    lo que el platillo lleve en ese momento, también lo que sume otra opción.
 *    La vista previa repite la explosión de Inventario::explotarReceta(); si
 *    cambia allí, cambia aquí.
 *
 * Los inputs de texto actualizan el estado sin repintar (para no perder el
 * foco); sólo los cambios de estructura vuelven a dibujar la lista.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-variantes-editor]');
    var nodoDatos = raiz && raiz.querySelector('[data-variantes-datos]');
    if (!raiz || !nodoDatos) {
        return;
    }

    var datos;
    try {
        datos = JSON.parse(nodoDatos.textContent || 'null');
    } catch (e) {
        datos = null;
    }
    if (!datos) {
        return;
    }

    var csrf = raiz.getAttribute('data-admin-csrf') || '';
    var cGrupos = raiz.querySelector('[data-variantes-grupos]');
    var cPlantillas = raiz.querySelector('[data-variantes-plantillas]');
    var cPos = raiz.querySelector('[data-variantes-pos]');
    var cReceta = raiz.querySelector('[data-variantes-receta]');
    var cErrores = raiz.querySelector('[data-variantes-errores]');
    var cEstado = raiz.querySelector('[data-variantes-estado]');
    var btnGuardar = raiz.querySelector('[data-variantes-guardar]');
    var btnDescartar = raiz.querySelector('[data-variantes-descartar]');

    var LIMITE_GRUPOS = (datos.limites && datos.limites.grupos) || 6;
    var LIMITE_OPCIONES = (datos.limites && datos.limites.opciones) || 12;

    // ── Catálogo de componentes ─────────────────────────────────

    var ingredientes = {};
    (datos.ingredientes || []).forEach(function (i) { ingredientes[i.id] = i; });
    var subrecetas = {};
    (datos.subrecetas || []).forEach(function (s) { subrecetas[s.id] = s; });
    var base = datos.base || [];
    var enBase = {};
    base.forEach(function (c) { enBase[c.tipo + ':' + c.ref_id] = (enBase[c.tipo + ':' + c.ref_id] || 0) + c.cantidad; });

    function componente(tipo, ref) {
        if (tipo === 'subreceta') {
            var s = subrecetas[ref];
            return s ? { nombre: s.nombre, unidad: s.unidad, tipo: 'subreceta' } : null;
        }
        var i = ingredientes[ref];
        return i ? { nombre: i.nombre, unidad: i.unidad, tipo: 'ingrediente' } : null;
    }

    function nombreComponente(tipo, ref) {
        var c = componente(tipo, ref);
        return c ? c.nombre : 'Ingrediente eliminado';
    }

    function unidadDe(tipo, ref) {
        var c = componente(tipo, ref);
        return c ? c.unidad : '';
    }

    // ── Utilidades ──────────────────────────────────────────────

    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icono(nombre, tam) {
        return window.AdminIcons ? window.AdminIcons.get(nombre, tam || 16) : '';
    }

    function normal(texto) {
        return String(texto || '').toLocaleLowerCase('es').normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function dinero(n) {
        var v = Math.round((Number(n) || 0) * 100) / 100;
        return '$' + v.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function delta(n) {
        var v = Number(n) || 0;
        if (v === 0) return 'Sin costo extra';
        return (v > 0 ? '+' : '−') + dinero(Math.abs(v));
    }

    function cantidad(n) {
        var v = Math.round((Number(n) || 0) * 1000) / 1000;
        return v.toLocaleString('es-MX', { maximumFractionDigits: 3 });
    }

    var siguienteClave = 1;
    function clave() { return 'k' + (siguienteClave++); }

    function aviso(texto, variante) {
        if (window.AppNotice && texto) {
            window.AppNotice.show({ text: texto, variant: variante || 'info' });
        }
    }

    // ── Estado ──────────────────────────────────────────────────

    var estado = { grupos: [] };
    var guardado = '';
    var abiertos = {};       // clave de opción → panel de receta desplegado
    var seleccion = {};      // clave de grupo → clave de opción (vista previa)
    var guardando = false;

    function desdeServidor(grupos) {
        return (grupos || []).map(function (g) {
            return {
                key: clave(),
                nombre: g.nombre,
                opciones: (g.opciones || []).map(function (o) {
                    return {
                        key: clave(),
                        id: o.id || 0,
                        nombre: o.nombre,
                        precio_delta: Number(o.precio_delta) || 0,
                        predeterminada: !!o.predeterminada,
                        activo: o.activo !== false,
                        ajustes: (o.ajustes || []).map(function (a) {
                            return {
                                accion: a.accion,
                                tipo: a.tipo,
                                ref_id: a.ref_id,
                                cantidad: Number(a.cantidad) || 0,
                                nuevo_tipo: a.nuevo_tipo || null,
                                nuevo_ref_id: a.nuevo_ref_id || null,
                                nueva_cantidad: a.nueva_cantidad !== null && a.nueva_cantidad !== undefined ? Number(a.nueva_cantidad) : null
                            };
                        })
                    };
                })
            };
        });
    }

    function serializar() {
        return estado.grupos.map(function (g) {
            return {
                nombre: g.nombre.trim(),
                opciones: g.opciones.map(function (o) {
                    return {
                        id: o.id || 0,
                        nombre: o.nombre.trim(),
                        precio_delta: Number(o.precio_delta) || 0,
                        predeterminada: !!o.predeterminada,
                        activo: !!o.activo,
                        ajustes: o.ajustes.map(function (a) {
                            return {
                                accion: a.accion,
                                tipo: a.tipo,
                                ref_id: a.ref_id,
                                cantidad: Number(a.cantidad) || 0,
                                nuevo_tipo: a.accion === 'sustituir' ? a.nuevo_tipo : null,
                                nuevo_ref_id: a.accion === 'sustituir' ? a.nuevo_ref_id : null,
                                nueva_cantidad: a.accion === 'sustituir' ? (Number(a.nueva_cantidad) || 0) : null
                            };
                        })
                    };
                })
            };
        });
    }

    function sucio() {
        return JSON.stringify(serializar()) !== guardado;
    }

    function grupoObligatorio(g) {
        return !g.opciones.some(function (o) { return o.predeterminada; });
    }

    // ── Explosión de receta (espejo de Inventario::explotarReceta) ─

    function explotarComponente(totales, tipo, ref, cant) {
        if (!ref || !cant) return;
        if (tipo === 'ingrediente') {
            totales[ref] = (totales[ref] || 0) + cant;
            return;
        }
        var s = subrecetas[ref];
        if (!s) return;
        var rend = s.rendimiento > 0 ? s.rendimiento : 1;
        var factor = cant / rend;
        s.ingredientes.forEach(function (si) {
            totales[si.id] = (totales[si.id] || 0) + si.cantidad * factor;
        });
    }

    function explotar(opciones) {
        var totales = {};
        var actual = {};
        base.forEach(function (c) {
            var k = c.tipo + ':' + c.ref_id;
            actual[k] = (actual[k] || 0) + c.cantidad;
            explotarComponente(totales, c.tipo, c.ref_id, c.cantidad);
        });

        var sustituciones = [];
        opciones.forEach(function (o) {
            o.ajustes.forEach(function (a) {
                if (!a.ref_id || !(a.cantidad > 0)) return;
                if (a.accion === 'sustituir') {
                    sustituciones.push(a);
                    return;
                }
                var signo = a.accion === 'quitar' ? -1 : 1;
                var k = a.tipo + ':' + a.ref_id;
                actual[k] = Math.max(0, (actual[k] || 0) + signo * a.cantidad);
                explotarComponente(totales, a.tipo, a.ref_id, signo * a.cantidad);
            });
        });

        sustituciones.forEach(function (a) {
            if (!a.nuevo_ref_id || !a.nuevo_tipo) return;
            var nueva = a.nueva_cantidad > 0 ? a.nueva_cantidad : a.cantidad;
            var k = a.tipo + ':' + a.ref_id;
            var cantBase = enBase[k] || 0;
            var sale = cantBase > 0 ? (actual[k] || 0) * Math.min(1, a.cantidad / cantBase) : a.cantidad;
            if (!(sale > 0)) return;
            var entra = sale * (nueva / a.cantidad);
            actual[k] = Math.max(0, (actual[k] || 0) - sale);
            var kn = a.nuevo_tipo + ':' + a.nuevo_ref_id;
            actual[kn] = (actual[kn] || 0) + entra;
            explotarComponente(totales, a.tipo, a.ref_id, -sale);
            explotarComponente(totales, a.nuevo_tipo, a.nuevo_ref_id, entra);
        });

        Object.keys(totales).forEach(function (id) {
            if (totales[id] <= 0.0005) delete totales[id];
        });
        return totales;
    }

    function costo(totales) {
        return Object.keys(totales).reduce(function (suma, id) {
            var ing = ingredientes[id];
            return suma + totales[id] * (ing ? ing.costo : 0);
        }, 0);
    }

    // ── Frases de un ajuste ─────────────────────────────────────

    function fraseAjuste(a) {
        var u = unidadDe(a.tipo, a.ref_id);
        if (a.accion === 'sustituir') {
            var nu = unidadDe(a.nuevo_tipo, a.nuevo_ref_id);
            return nombreComponente(a.tipo, a.ref_id) + ' → ' +
                (a.nuevo_ref_id ? nombreComponente(a.nuevo_tipo, a.nuevo_ref_id) : '…') +
                (a.nueva_cantidad ? ' (' + cantidad(a.nueva_cantidad) + ' ' + nu + ')' : '');
        }
        return (a.accion === 'quitar' ? 'Sin ' : '+ ') + nombreComponente(a.tipo, a.ref_id) +
            (a.accion === 'quitar' && enBase[a.tipo + ':' + a.ref_id] === a.cantidad ? '' : ' ' + cantidad(a.cantidad) + ' ' + u);
    }

    // ── Selector de componentes (uno solo, portado al <body>) ───

    var selector = (function () {
        var caja = document.createElement('div');
        caja.className = 'admin-vpicker';
        caja.hidden = true;
        caja.innerHTML =
            '<div class="admin-vpicker__buscar">' + icono('buscar', 16) +
            '<input type="search" class="admin-vpicker__input" placeholder="Buscar ingrediente o subreceta" aria-label="Buscar ingrediente o subreceta" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="admin-vpicker-lista" aria-autocomplete="list">' +
            '</div>' +
            '<ul class="admin-vpicker__lista" id="admin-vpicker-lista" role="listbox" data-scrollable></ul>';
        document.body.appendChild(caja);
        var input = caja.querySelector('input');
        var lista = caja.querySelector('ul');
        var alElegir = null;
        var disparador = null;
        var activo = -1;
        var visibles = [];

        function opciones() {
            var salida = [];
            var vistos = {};
            base.forEach(function (c) {
                var k = c.tipo + ':' + c.ref_id;
                if (vistos[k] || !componente(c.tipo, c.ref_id)) return;
                vistos[k] = true;
                salida.push({ grupo: 'En la receta base', tipo: c.tipo, ref: c.ref_id, nombre: nombreComponente(c.tipo, c.ref_id), unidad: unidadDe(c.tipo, c.ref_id), cantidad: c.cantidad });
            });
            (datos.subrecetas || []).forEach(function (s) {
                if (vistos['subreceta:' + s.id]) return;
                salida.push({ grupo: 'Subrecetas', tipo: 'subreceta', ref: s.id, nombre: s.nombre, unidad: s.unidad });
            });
            (datos.ingredientes || []).forEach(function (i) {
                if (vistos['ingrediente:' + i.id] || !i.activo) return;
                salida.push({ grupo: 'Ingredientes', tipo: 'ingrediente', ref: i.id, nombre: i.nombre, unidad: i.unidad });
            });
            return salida;
        }

        function pintar() {
            var q = normal(input.value.trim());
            visibles = opciones().filter(function (o) { return !q || normal(o.nombre).indexOf(q) !== -1; });
            if (!visibles.length) {
                lista.innerHTML = '<li class="admin-vpicker__vacio" role="presentation">Nada coincide con «' + esc(input.value.trim()) + '».</li>';
                activo = -1;
                input.removeAttribute('aria-activedescendant');
                return;
            }
            var html = '';
            var grupoActual = '';
            visibles.forEach(function (o, i) {
                if (o.grupo !== grupoActual) {
                    grupoActual = o.grupo;
                    html += '<li class="admin-vpicker__grupo" role="presentation">' + esc(o.grupo) + '</li>';
                }
                html += '<li class="admin-vpicker__opcion" role="option" id="admin-vpicker-op-' + i + '" data-i="' + i + '" aria-selected="false">' +
                    '<span>' + esc(o.nombre) + '</span>' +
                    '<span class="admin-vpicker__meta admin-num">' + (o.cantidad ? cantidad(o.cantidad) + ' ' : '') + esc(o.unidad) + '</span></li>';
            });
            lista.innerHTML = html;
            marcar(0);
        }

        function marcar(i) {
            var nodos = lista.querySelectorAll('[role="option"]');
            if (!nodos.length) return;
            activo = Math.max(0, Math.min(nodos.length - 1, i));
            nodos.forEach(function (n, j) {
                n.classList.toggle('is-active', j === activo);
                n.setAttribute('aria-selected', j === activo ? 'true' : 'false');
            });
            input.setAttribute('aria-activedescendant', nodos[activo].id);
            nodos[activo].scrollIntoView({ block: 'nearest' });
        }

        function posicionar() {
            if (!disparador) return;
            var r = disparador.getBoundingClientRect();
            var ancho = Math.max(r.width, 300);
            var izquierda = Math.min(r.left, window.innerWidth - ancho - 12);
            var abajo = window.innerHeight - r.bottom;
            caja.style.width = ancho + 'px';
            caja.style.left = Math.max(12, izquierda) + 'px';
            if (abajo < 320 && r.top > abajo) {
                caja.style.top = '';
                caja.style.bottom = (window.innerHeight - r.top + 6) + 'px';
            } else {
                caja.style.bottom = '';
                caja.style.top = (r.bottom + 6) + 'px';
            }
        }

        function cerrar(devolverFoco) {
            if (caja.hidden) return;
            caja.hidden = true;
            if (disparador) {
                disparador.setAttribute('aria-expanded', 'false');
                if (devolverFoco) disparador.focus();
            }
            disparador = null;
            alElegir = null;
        }

        function elegir(i) {
            var o = visibles[i];
            if (!o || !alElegir) return;
            var cb = alElegir;
            var d = disparador;
            cerrar(false);
            cb(o);
            if (d && document.body.contains(d)) d.focus();
        }

        input.addEventListener('input', pintar);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(activo + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(activo - 1); }
            else if (e.key === 'Enter') { e.preventDefault(); elegir(activo); }
            else if (e.key === 'Escape') { e.preventDefault(); cerrar(true); }
            else if (e.key === 'Tab') { cerrar(false); }
        });
        lista.addEventListener('mousedown', function (e) { e.preventDefault(); });
        lista.addEventListener('click', function (e) {
            var li = e.target.closest('[data-i]');
            if (li) elegir(Number(li.getAttribute('data-i')));
        });
        document.addEventListener('mousedown', function (e) {
            if (!caja.hidden && !caja.contains(e.target) && e.target !== disparador && !(disparador && disparador.contains(e.target))) {
                cerrar(false);
            }
        });
        window.addEventListener('resize', posicionar);
        window.addEventListener('scroll', posicionar, true);

        return {
            abrir: function (boton, cb) {
                if (disparador === boton) { cerrar(true); return; }
                disparador = boton;
                alElegir = cb;
                boton.setAttribute('aria-expanded', 'true');
                input.value = '';
                caja.hidden = false;
                pintar();
                posicionar();
                input.focus();
            },
            cerrar: cerrar
        };
    })();

    // ── Render ──────────────────────────────────────────────────

    function botonComponente(tipo, ref, rol, gi, oi, ai) {
        var vacio = !ref;
        return '<button type="button" class="admin-vajuste__comp' + (vacio ? ' is-empty' : '') + '" aria-haspopup="listbox" aria-expanded="false"' +
            ' data-elegir="' + rol + '" data-g="' + gi + '" data-o="' + oi + '" data-a="' + ai + '">' +
            '<span>' + (vacio ? (rol === 'nuevo' ? 'Elegir sustituto' : 'Elegir ingrediente') : esc(nombreComponente(tipo, ref))) + '</span>' +
            '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg></button>';
    }

    function pintarAjuste(a, gi, oi, ai) {
        var acciones = [['sustituir', 'Cambiar'], ['agregar', 'Agregar'], ['quitar', 'Quitar']];
        var id = 'vaj-' + gi + '-' + oi + '-' + ai;
        var html = '<li class="admin-vajuste">' +
            '<div class="admin-vajuste__accion" role="radiogroup" aria-label="Qué hace este ajuste">';
        acciones.forEach(function (par) {
            var on = a.accion === par[0];
            // Roving tabindex: un solo tope de tabulador por grupo, y las
            // flechas se mueven dentro (ver `moverEnRadiogroup`).
            html += '<button type="button" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="' + (on ? '0' : '-1') + '" class="admin-vajuste__pill' + (on ? ' is-on' : '') + '"' +
                ' data-accion-ajuste="' + par[0] + '" data-g="' + gi + '" data-o="' + oi + '" data-a="' + ai + '">' + par[1] + '</button>';
        });
        html += '</div><div class="admin-vajuste__cuerpo">' +
            botonComponente(a.tipo, a.ref_id, 'comp', gi, oi, ai) +
            '<label class="admin-vajuste__cant"><span class="admin-visually-hidden">Cantidad</span>' +
            '<input type="number" inputmode="decimal" min="0" step="any" value="' + (a.cantidad || '') + '" data-campo-ajuste="cantidad" data-g="' + gi + '" data-o="' + oi + '" data-a="' + ai + '" id="' + id + '-c">' +
            '<span class="admin-vajuste__unidad" data-unidad-comp>' + esc(unidadDe(a.tipo, a.ref_id)) + '</span></label>';
        if (a.accion === 'sustituir') {
            html += '<span class="admin-vajuste__por">por</span>' +
                botonComponente(a.nuevo_tipo, a.nuevo_ref_id, 'nuevo', gi, oi, ai) +
                '<label class="admin-vajuste__cant"><span class="admin-visually-hidden">Cantidad del sustituto</span>' +
                '<input type="number" inputmode="decimal" min="0" step="any" value="' + (a.nueva_cantidad || '') + '" data-campo-ajuste="nueva_cantidad" data-g="' + gi + '" data-o="' + oi + '" data-a="' + ai + '">' +
                '<span class="admin-vajuste__unidad">' + esc(unidadDe(a.nuevo_tipo, a.nuevo_ref_id)) + '</span></label>';
        }
        html += '</div><button type="button" class="admin-vajuste__borrar" data-borrar-ajuste data-g="' + gi + '" data-o="' + oi + '" data-a="' + ai + '" aria-label="Quitar este ajuste">' + icono('cerrar', 14) + '</button></li>';
        return html;
    }

    function pintarOpcion(g, o, gi, oi) {
        var obligatorio = grupoObligatorio(g);
        var abierto = !!abiertos[o.key];
        var resumen = o.ajustes.length
            ? o.ajustes.map(fraseAjuste).join(' · ')
            : (o.predeterminada ? 'Receta base, sin cambios' : 'No cambia la receta');
        var html = '<li class="admin-vopcion' + (o.activo ? '' : ' is-off') + (abierto ? ' is-open' : '') + '" data-opcion="' + o.key + '">' +
            '<div class="admin-vopcion__fila">';
        if (!obligatorio) {
            html += '<label class="admin-vopcion__base" title="Opción predeterminada: la receta base">' +
                '<input type="radio" name="base-' + g.key + '" ' + (o.predeterminada ? 'checked' : '') + ' data-base data-g="' + gi + '" data-o="' + oi + '">' +
                '<span class="admin-visually-hidden">Predeterminada</span></label>';
        } else {
            html += '<span class="admin-vopcion__base admin-vopcion__base--vacia" aria-hidden="true"></span>';
        }
        html += '<label class="admin-vopcion__nombre"><span class="admin-visually-hidden">Nombre de la opción</span>' +
            '<input type="text" maxlength="60" value="' + esc(o.nombre) + '" placeholder="Nombre de la opción" data-campo="nombre" data-g="' + gi + '" data-o="' + oi + '"></label>' +
            '<label class="admin-vopcion__precio"><span class="admin-vopcion__signo" aria-hidden="true">$</span>' +
            '<span class="admin-visually-hidden">Cambio de precio</span>' +
            '<input type="number" inputmode="decimal" step="0.5" value="' + (o.precio_delta || 0) + '" data-campo="precio_delta" data-g="' + gi + '" data-o="' + oi + '"></label>' +
            // El resumen se recorta con puntos suspensivos en la fila; el
            // `title` devuelve la frase entera al pasar el puntero.
            '<button type="button" class="admin-vopcion__receta" aria-expanded="' + (abierto ? 'true' : 'false') + '" title="' + esc(resumen) + '" data-toggle-receta data-g="' + gi + '" data-o="' + oi + '">' +
            icono('variantes', 15) + '<span class="admin-vopcion__resumen">' + esc(resumen) + '</span></button>' +
            '<button type="button" class="admin-icon-button admin-icon-button--danger admin-vopcion__borrar" data-borrar-opcion data-g="' + gi + '" data-o="' + oi + '" aria-label="Eliminar la opción ' + esc(o.nombre || '') + '">' + icono('basura', 16) + '</button>' +
            '</div>';
        if (abierto) {
            html += '<div class="admin-vopcion__panel">';
            html += '<div class="admin-vopcion__panel-head"><p class="admin-vopcion__panel-titulo">Ajustes de receta de «' + esc(o.nombre || 'esta opción') + '»</p>' +
                '<label class="admin-switch admin-vopcion__activo"><input type="checkbox" ' + (o.activo ? 'checked' : '') + (o.predeterminada ? ' disabled' : '') + ' data-campo="activo" data-g="' + gi + '" data-o="' + oi + '">' +
                '<span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>' +
                '<span class="admin-switch__label">Disponible en el POS</span></label></div>';
            if (o.ajustes.length) {
                html += '<ol class="admin-vajustes">';
                o.ajustes.forEach(function (a, ai) { html += pintarAjuste(a, gi, oi, ai); });
                html += '</ol>';
            } else {
                html += '<p class="admin-vopcion__vacio">' + (o.predeterminada
                    ? 'Es la receta base: normalmente no lleva ajustes.'
                    : 'Sin ajustes, esta opción sólo cambia el nombre y el precio de la línea. Si cambia lo que se prepara, dilo aquí para que el inventario cuadre.') + '</p>';
            }
            html += '<div class="admin-vopcion__atajos">' +
                '<button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-agregar-ajuste="sustituir" data-g="' + gi + '" data-o="' + oi + '">' + icono('mas', 14) + 'Cambiar un ingrediente</button>' +
                '<button type="button" class="admin-btn admin-btn--ghost admin-btn--small" data-agregar-ajuste="agregar" data-g="' + gi + '" data-o="' + oi + '">Agregar</button>' +
                '<button type="button" class="admin-btn admin-btn--ghost admin-btn--small" data-agregar-ajuste="quitar" data-g="' + gi + '" data-o="' + oi + '">Quitar</button>' +
                '</div>';
            if (o.ajustes.some(function (a) { return a.accion === 'sustituir'; })) {
                html += '<p class="admin-vopcion__nota">«Cambiar» reemplaza la parte indicada de lo que lleve el platillo, también lo que sume otra opción: con 120 de 120 ml de leche, un «Grande» con almendra lleva toda su leche de almendra.</p>';
            }
            html += '</div>';
        }
        return html + '</li>';
    }

    function pintarGrupos() {
        if (!estado.grupos.length) {
            cGrupos.innerHTML = '<div class="admin-variantes__vacio admin-card">' +
                '<span class="admin-variantes__vacio-icono" aria-hidden="true">' + icono('variantes', 28) + '</span>' +
                '<p class="admin-variantes__vacio-titulo">«' + esc(datos.nombre) + '» todavía no tiene variantes</p>' +
                '<p class="admin-variantes__vacio-texto">Se vende tal como está en la receta base. Agrega un grupo —leche, tamaño, término— para que el mesero elija al tomar la orden.</p></div>';
            return;
        }
        var html = '';
        estado.grupos.forEach(function (g, gi) {
            var obligatorio = grupoObligatorio(g);
            var def = g.opciones.filter(function (o) { return o.predeterminada; })[0];
            html += '<article class="admin-vgrupo admin-card" data-grupo="' + g.key + '">' +
                '<header class="admin-vgrupo__head">' +
                '<span class="admin-vgrupo__num admin-num" aria-hidden="true">' + String(gi + 1).padStart(2, '0') + '</span>' +
                '<label class="admin-vgrupo__nombre"><span class="admin-visually-hidden">Nombre del grupo</span>' +
                '<input type="text" maxlength="40" value="' + esc(g.nombre) + '" placeholder="Nombre del grupo (p. ej. Leche)" data-campo-grupo="nombre" data-g="' + gi + '"></label>' +
                '<div class="admin-vgrupo__acciones">' +
                '<button type="button" class="admin-icon-button" data-mover-grupo="-1" data-g="' + gi + '" aria-label="Subir el grupo"' + (gi === 0 ? ' disabled' : '') + '>' + icono('sube', 16) + '</button>' +
                '<button type="button" class="admin-icon-button" data-mover-grupo="1" data-g="' + gi + '" aria-label="Bajar el grupo"' + (gi === estado.grupos.length - 1 ? ' disabled' : '') + '>' + icono('baja', 16) + '</button>' +
                '<button type="button" class="admin-icon-button admin-icon-button--danger" data-borrar-grupo data-g="' + gi + '" aria-label="Eliminar el grupo ' + esc(g.nombre) + '">' + icono('basura', 16) + '</button>' +
                '</div></header>' +
                '<div class="admin-vgrupo__regla">' +
                '<label class="admin-switch"><input type="checkbox" ' + (obligatorio ? 'checked' : '') + ' data-obligatorio data-g="' + gi + '">' +
                '<span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>' +
                '<span class="admin-switch__label">El mesero siempre elige</span></label>' +
                '<p class="admin-vgrupo__regla-texto">' + (obligatorio
                    ? 'No hay opción predeterminada: el POS no deja agregar el platillo sin elegir.'
                    : 'Si nadie elige, va <strong>' + esc(def ? def.nombre || 'la predeterminada' : '') + '</strong>, que es la receta base y no se imprime en la comanda.') + '</p>' +
                '</div>' +
                '<div class="admin-vgrupo__cabecera" aria-hidden="true"><span>' + (obligatorio ? '' : 'Base') + '</span><span>Opción</span><span>Precio</span><span>Receta</span><span></span></div>' +
                '<ul class="admin-vgrupo__opciones">';
            g.opciones.forEach(function (o, oi) { html += pintarOpcion(g, o, gi, oi); });
            html += '</ul>' +
                '<button type="button" class="admin-vgrupo__agregar" data-agregar-opcion data-g="' + gi + '"' + (g.opciones.length >= LIMITE_OPCIONES ? ' disabled' : '') + '>' +
                icono('mas', 16) + 'Agregar opción</button>' +
                '</article>';
        });
        cGrupos.innerHTML = html;
    }

    function pintarPlantillas() {
        var nombres = estado.grupos.map(function (g) { return normal(g.nombre.trim()); });
        var lleno = estado.grupos.length >= LIMITE_GRUPOS;
        var html = '<button type="button" class="admin-btn admin-btn--primary" data-plantilla=""' + (lleno ? ' disabled' : '') + '>' + icono('mas', 16) + 'Grupo en blanco</button>';
        Object.keys(datos.plantillas || {}).forEach(function (nombre) {
            var usado = nombres.indexOf(normal(nombre)) !== -1;
            var ops = datos.plantillas[nombre].map(function (o) { return o.nombre; }).join(', ');
            html += '<button type="button" class="admin-variantes__plantilla" data-plantilla="' + esc(nombre) + '"' + (usado || lleno ? ' disabled' : '') + '>' +
                '<span class="admin-variantes__plantilla-nombre">' + esc(nombre) + '</span>' +
                '<span class="admin-variantes__plantilla-ops">' + esc(ops) + '</span></button>';
        });
        if (lleno) {
            html += '<p class="admin-variantes__limite">Un platillo admite hasta ' + LIMITE_GRUPOS + ' grupos.</p>';
        }
        cPlantillas.innerHTML = html;
    }

    function opcionesSeleccionadas() {
        var elegidas = [];
        var faltan = [];
        estado.grupos.forEach(function (g) {
            var activas = g.opciones.filter(function (o) { return o.activo; });
            if (activas.length < 2) return;
            var k = seleccion[g.key];
            var o = activas.filter(function (x) { return x.key === k; })[0] ||
                activas.filter(function (x) { return x.predeterminada; })[0];
            if (o) {
                elegidas.push(o);
            } else {
                faltan.push(g.nombre || 'un grupo');
            }
        });
        return { elegidas: elegidas, faltan: faltan };
    }

    function pintarPreview() {
        var sel = opcionesSeleccionadas();
        var precio = (Number(datos.precio) || 0) + sel.elegidas.reduce(function (s, o) { return s + (Number(o.precio_delta) || 0); }, 0);
        var rotulo = sel.elegidas.filter(function (o) { return !o.predeterminada; }).map(function (o) { return o.nombre; }).join(' · ');

        var html = '<div class="admin-vpos">' +
            '<div class="admin-vpos__cabeza"><span class="admin-vpos__nombre">' + esc(datos.nombre) + '</span>' +
            '<span class="admin-vpos__precio admin-num">' + dinero(precio) + '</span></div>';
        var visibles = 0;
        estado.grupos.forEach(function (g) {
            var activas = g.opciones.filter(function (o) { return o.activo; });
            if (activas.length < 2) return;
            visibles++;
            var obligatorio = grupoObligatorio(g);
            var elegida = sel.elegidas.filter(function (o) { return activas.indexOf(o) !== -1; })[0];
            html += '<div class="admin-vpos__grupo" role="radiogroup" aria-label="' + esc(g.nombre) + '">' +
                '<p class="admin-vpos__grupo-nombre">' + esc(g.nombre || 'Sin nombre') +
                (obligatorio && !elegida ? ' <span class="admin-vpos__falta">Elige</span>' : '') + '</p><div class="admin-vpos__pills">';
            activas.forEach(function (o, i) {
                var on = elegida === o;
                var foco = on || (!elegida && i === 0);
                html += '<button type="button" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="' + (foco ? '0' : '-1') + '" class="admin-vpos__pill' + (on ? ' is-on' : '') + '" data-probar="' + o.key + '" data-grupo-key="' + g.key + '">' +
                    '<span>' + esc(o.nombre || '—') + '</span>' +
                    (Number(o.precio_delta) ? '<span class="admin-vpos__delta admin-num">' + (o.precio_delta > 0 ? '+' : '−') + '$' + Math.abs(o.precio_delta) + '</span>' : '') +
                    '</button>';
            });
            html += '</div></div>';
        });
        if (!visibles) {
            html += '<p class="admin-vpos__sin">Sin opciones que elegir: el platillo se agrega con un toque.</p>';
        }
        html += '<div class="admin-vpos__linea"><span class="admin-vpos__linea-label">En la comanda</span><span class="admin-vpos__linea-texto">1 × ' + esc(datos.nombre) +
            (rotulo ? '<strong>' + esc(rotulo) + '</strong>' : '') + '</span></div>';
        html += '<button type="button" class="admin-vpos__agregar" disabled>' + (sel.faltan.length ? 'Falta elegir ' + esc(sel.faltan.join(', ')) : 'Agregar · ' + dinero(precio)) + '</button></div>';
        cPos.innerHTML = html;

        pintarReceta(sel.elegidas, precio);
    }

    function pintarReceta(elegidas, precio) {
        var baseT = explotar([]);
        var t = explotar(elegidas);
        var ids = Object.keys(t);
        Object.keys(baseT).forEach(function (id) { if (ids.indexOf(id) === -1) ids.push(id); });

        if (!ids.length) {
            cReceta.innerHTML = '<p class="admin-vreceta__vacia">Este platillo no tiene receta base, así que vender no descuenta inventario. ' +
                '<a href="/admin/recetas/editar?id=' + Number(datos.producto_id) + '">Captura la receta</a> y aquí verás qué descuenta cada combinación.</p>';
            return;
        }
        ids.sort(function (a, b) { return nombreComponente('ingrediente', a).localeCompare(nombreComponente('ingrediente', b), 'es'); });

        var html = '<ul class="admin-vreceta">';
        ids.forEach(function (id) {
            var ahora = t[id] || 0;
            var antes = baseT[id] || 0;
            var dif = ahora - antes;
            var marca = '';
            var clase = '';
            if (!antes && ahora) { marca = 'nuevo'; clase = ' is-new'; }
            else if (antes && !ahora) { marca = 'fuera'; clase = ' is-out'; }
            else if (Math.abs(dif) > 0.0005) { marca = (dif > 0 ? '+' : '−') + cantidad(Math.abs(dif)); clase = ' is-diff'; }
            html += '<li class="admin-vreceta__fila' + clase + '">' +
                '<span class="admin-vreceta__nombre">' + esc(nombreComponente('ingrediente', id)) + '</span>' +
                (marca ? '<span class="admin-vreceta__marca admin-num">' + marca + '</span>' : '<span></span>') +
                '<span class="admin-vreceta__cant admin-num">' + (ahora ? cantidad(ahora) : cantidad(antes)) + ' ' + esc(unidadDe('ingrediente', id)) + '</span></li>';
        });
        html += '</ul>';
        var c = costo(t);
        var margen = precio - c;
        var pct = precio > 0 ? Math.round((margen / precio) * 100) : 0;
        html += '<dl class="admin-vreceta__totales">' +
            '<div><dt>Costo de insumos</dt><dd class="admin-num">' + dinero(c) + '</dd></div>' +
            '<div><dt>Precio</dt><dd class="admin-num">' + dinero(precio) + '</dd></div>' +
            '<div class="admin-vreceta__margen' + (margen < 0 ? ' is-neg' : '') + '"><dt>Margen</dt><dd class="admin-num">' + dinero(margen) + ' <span>' + pct + '%</span></dd></div>' +
            '</dl>';
        cReceta.innerHTML = html;
    }

    function pintarBarra() {
        var cambios = sucio();
        btnGuardar.disabled = !cambios || guardando;
        btnDescartar.disabled = !cambios || guardando;
        raiz.classList.toggle('has-changes', cambios);
        cEstado.textContent = guardando ? 'Guardando…' : (cambios ? 'Cambios sin guardar' : 'Todo guardado');
    }

    function pintarTodo() {
        pintarGrupos();
        pintarPlantillas();
        pintarPreview();
        pintarBarra();
    }

    // ── Mutaciones ──────────────────────────────────────────────

    function g(i) { return estado.grupos[Number(i)]; }
    function op(gi, oi) { var gr = g(gi); return gr ? gr.opciones[Number(oi)] : null; }

    function nuevaOpcion(nombre, deltaPrecio, predeterminada) {
        return { key: clave(), id: 0, nombre: nombre || '', precio_delta: deltaPrecio || 0, predeterminada: !!predeterminada, activo: true, ajustes: [] };
    }

    function agregarGrupo(plantilla) {
        if (estado.grupos.length >= LIMITE_GRUPOS) return;
        var ops = plantilla && datos.plantillas[plantilla]
            ? datos.plantillas[plantilla].map(function (o) { return nuevaOpcion(o.nombre, o.precio_delta, o.predeterminada); })
            : [nuevaOpcion('', 0, true), nuevaOpcion('', 0, false)];
        estado.grupos.push({ key: clave(), nombre: plantilla || '', opciones: ops });
        pintarTodo();
        var inputs = cGrupos.querySelectorAll('.admin-vgrupo');
        var ultimo = inputs[inputs.length - 1];
        if (ultimo) {
            ultimo.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var foco = plantilla ? ultimo.querySelector('[data-campo="nombre"]') : ultimo.querySelector('[data-campo-grupo]');
            if (foco) foco.focus({ preventScroll: true });
        }
    }

    function confirmar(opciones) {
        if (window.ConfirmationModal && window.ConfirmationModal.get) {
            return window.ConfirmationModal.get().open(opciones).then(function (r) { return r && r.action === 'primary'; });
        }
        return Promise.resolve(true);
    }

    // ── Eventos ─────────────────────────────────────────────────

    raiz.addEventListener('input', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-campo-grupo')) {
            g(t.getAttribute('data-g')).nombre = t.value;
            pintarPreview(); pintarBarra();
            return;
        }
        if (t.hasAttribute('data-campo') && t.type !== 'checkbox') {
            var o = op(t.getAttribute('data-g'), t.getAttribute('data-o'));
            if (!o) return;
            var campo = t.getAttribute('data-campo');
            o[campo] = campo === 'precio_delta' ? (t.value === '' ? 0 : Number(t.value)) : t.value;
            pintarPreview(); pintarBarra();
            return;
        }
        if (t.hasAttribute('data-campo-ajuste')) {
            var a = (op(t.getAttribute('data-g'), t.getAttribute('data-o')) || { ajustes: [] }).ajustes[Number(t.getAttribute('data-a'))];
            if (!a) return;
            a[t.getAttribute('data-campo-ajuste')] = t.value === '' ? 0 : Number(t.value);
            pintarPreview(); pintarBarra();
        }
    });

    // El resumen de la fila (las frases de los ajustes) se repinta al salir
    // del campo, no en cada tecla: repintar mueve el foco.
    raiz.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-base')) {
            var gr = g(t.getAttribute('data-g'));
            gr.opciones.forEach(function (o, i) {
                o.predeterminada = i === Number(t.getAttribute('data-o'));
                if (o.predeterminada) o.activo = true;
            });
            pintarTodo();
            return;
        }
        if (t.hasAttribute('data-obligatorio')) {
            var grupo = g(t.getAttribute('data-g'));
            if (t.checked) {
                grupo.opciones.forEach(function (o) { o.predeterminada = false; });
            } else if (grupo.opciones.length) {
                grupo.opciones[0].predeterminada = true;
                grupo.opciones[0].activo = true;
            }
            pintarTodo();
            var sw = cGrupos.querySelector('[data-obligatorio][data-g="' + t.getAttribute('data-g') + '"]');
            if (sw) sw.focus();
            return;
        }
        if (t.getAttribute('data-campo') === 'activo') {
            var o = op(t.getAttribute('data-g'), t.getAttribute('data-o'));
            if (o) o.activo = t.checked;
            pintarTodo();
            return;
        }
        if (t.hasAttribute('data-campo-ajuste') || t.getAttribute('data-campo') === 'nombre') {
            var activo = document.activeElement;
            var sel = activo && activo.getAttribute ? '[data-campo="' + activo.getAttribute('data-campo') + '"][data-g="' + activo.getAttribute('data-g') + '"][data-o="' + activo.getAttribute('data-o') + '"]' : null;
            pintarGrupos();
            if (sel && activo !== document.body) {
                var vuelta = cGrupos.querySelector(sel);
                if (vuelta) vuelta.focus();
            }
        }
    });

    // Patrón de radiogroup: las flechas mueven la elección dentro del grupo y
    // eligen al moverse, como en el POS. El clic sintético reutiliza el mismo
    // camino que el ratón (estado, repintado y devolución del foco).
    function moverEnRadiogroup(e) {
        var t = e.target;
        if (!t.getAttribute || t.getAttribute('role') !== 'radio') return false;
        if (!/^Arrow(Left|Right|Up|Down)$/.test(e.key)) return false;
        var grupo = t.closest('[role="radiogroup"]');
        if (!grupo) return false;
        var hermanos = Array.prototype.slice.call(grupo.querySelectorAll('[role="radio"]'));
        var paso = (e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : -1;
        var siguiente = hermanos[(hermanos.indexOf(t) + paso + hermanos.length) % hermanos.length];
        e.preventDefault();
        if (siguiente && siguiente !== t) siguiente.click();
        return true;
    }

    raiz.addEventListener('keydown', function (e) {
        var t = e.target;
        if (moverEnRadiogroup(e)) return;
        // Enter en el nombre de la última opción agrega otra: capturar
        // «Entera, Deslactosada, Almendra, Avena» sin soltar el teclado.
        if (e.key === 'Enter' && t.getAttribute && t.getAttribute('data-campo') === 'nombre') {
            e.preventDefault();
            var gi = Number(t.getAttribute('data-g'));
            var oi = Number(t.getAttribute('data-o'));
            var gr = g(gi);
            if (oi === gr.opciones.length - 1 && t.value.trim() !== '' && gr.opciones.length < LIMITE_OPCIONES) {
                gr.opciones.push(nuevaOpcion('', 0, false));
                pintarTodo();
            }
            var siguiente = cGrupos.querySelector('[data-campo="nombre"][data-g="' + gi + '"][data-o="' + (oi + 1) + '"]');
            if (siguiente) siguiente.focus();
        }
    });

    raiz.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b || !raiz.contains(b)) return;

        if (b.hasAttribute('data-plantilla')) {
            agregarGrupo(b.getAttribute('data-plantilla'));
            return;
        }
        if (b.hasAttribute('data-agregar-opcion')) {
            var gi = b.getAttribute('data-g');
            g(gi).opciones.push(nuevaOpcion('', 0, false));
            pintarTodo();
            var campos = cGrupos.querySelectorAll('[data-campo="nombre"][data-g="' + gi + '"]');
            if (campos.length) campos[campos.length - 1].focus();
            return;
        }
        if (b.hasAttribute('data-mover-grupo')) {
            var i = Number(b.getAttribute('data-g'));
            var j = i + Number(b.getAttribute('data-mover-grupo'));
            if (j < 0 || j >= estado.grupos.length) return;
            var tmp = estado.grupos[i];
            estado.grupos[i] = estado.grupos[j];
            estado.grupos[j] = tmp;
            pintarTodo();
            var mismo = cGrupos.querySelector('[data-mover-grupo="' + b.getAttribute('data-mover-grupo') + '"][data-g="' + j + '"]');
            (mismo && !mismo.disabled ? mismo : cGrupos.querySelector('[data-campo-grupo][data-g="' + j + '"]')).focus();
            return;
        }
        if (b.hasAttribute('data-borrar-grupo')) {
            var gb = g(b.getAttribute('data-g'));
            confirmar({
                eyebrow: 'Variantes',
                title: '¿Eliminar el grupo «' + (gb.nombre || 'sin nombre') + '»?',
                description: 'Se quitan sus ' + gb.opciones.length + ' opciones. Las ventas ya registradas conservan lo que se eligió.',
                consequence: 'El cambio se aplica al guardar.',
                primaryLabel: 'Eliminar grupo',
                variant: 'danger'
            }).then(function (si) {
                if (!si) return;
                estado.grupos.splice(estado.grupos.indexOf(gb), 1);
                pintarTodo();
            });
            return;
        }
        if (b.hasAttribute('data-borrar-opcion')) {
            var grupo = g(b.getAttribute('data-g'));
            var o = grupo.opciones[Number(b.getAttribute('data-o'))];
            var eraBase = o.predeterminada;
            grupo.opciones.splice(grupo.opciones.indexOf(o), 1);
            if (eraBase && grupo.opciones.length) {
                grupo.opciones[0].predeterminada = true;
                grupo.opciones[0].activo = true;
            }
            pintarTodo();
            aviso('Opción «' + (o.nombre || 'sin nombre') + '» quitada. Se elimina al guardar.', 'info');
            return;
        }
        if (b.hasAttribute('data-toggle-receta')) {
            var oo = op(b.getAttribute('data-g'), b.getAttribute('data-o'));
            abiertos[oo.key] = !abiertos[oo.key];
            pintarGrupos();
            var mismoBtn = cGrupos.querySelector('[data-toggle-receta][data-g="' + b.getAttribute('data-g') + '"][data-o="' + b.getAttribute('data-o') + '"]');
            if (mismoBtn) mismoBtn.focus();
            return;
        }
        if (b.hasAttribute('data-agregar-ajuste')) {
            var oa = op(b.getAttribute('data-g'), b.getAttribute('data-o'));
            oa.ajustes.push({ accion: b.getAttribute('data-agregar-ajuste'), tipo: 'ingrediente', ref_id: 0, cantidad: 0, nuevo_tipo: null, nuevo_ref_id: null, nueva_cantidad: null });
            pintarGrupos();
            var botones = cGrupos.querySelectorAll('[data-elegir="comp"][data-g="' + b.getAttribute('data-g') + '"][data-o="' + b.getAttribute('data-o') + '"]');
            if (botones.length) botones[botones.length - 1].click();
            return;
        }
        if (b.hasAttribute('data-accion-ajuste')) {
            var aj = op(b.getAttribute('data-g'), b.getAttribute('data-o')).ajustes[Number(b.getAttribute('data-a'))];
            aj.accion = b.getAttribute('data-accion-ajuste');
            pintarGrupos(); pintarPreview(); pintarBarra();
            var pill = cGrupos.querySelector('[data-accion-ajuste="' + aj.accion + '"][data-g="' + b.getAttribute('data-g') + '"][data-o="' + b.getAttribute('data-o') + '"][data-a="' + b.getAttribute('data-a') + '"]');
            if (pill) pill.focus();
            return;
        }
        if (b.hasAttribute('data-borrar-ajuste')) {
            var ob = op(b.getAttribute('data-g'), b.getAttribute('data-o'));
            ob.ajustes.splice(Number(b.getAttribute('data-a')), 1);
            pintarGrupos(); pintarPreview(); pintarBarra();
            var panel = cGrupos.querySelector('[data-toggle-receta][data-g="' + b.getAttribute('data-g') + '"][data-o="' + b.getAttribute('data-o') + '"]');
            if (panel) panel.focus();
            return;
        }
        if (b.hasAttribute('data-elegir')) {
            var rol = b.getAttribute('data-elegir');
            var gi2 = b.getAttribute('data-g'), oi2 = b.getAttribute('data-o'), ai2 = b.getAttribute('data-a');
            selector.abrir(b, function (elegido) {
                var a = op(gi2, oi2).ajustes[Number(ai2)];
                if (rol === 'nuevo') {
                    a.nuevo_tipo = elegido.tipo;
                    a.nuevo_ref_id = elegido.ref;
                    if (!(a.nueva_cantidad > 0)) a.nueva_cantidad = a.cantidad || null;
                } else {
                    a.tipo = elegido.tipo;
                    a.ref_id = elegido.ref;
                    // De la receta base trae su cantidad: «quitar el parmesano»
                    // casi siempre es quitarlo entero.
                    if (!(a.cantidad > 0) && elegido.cantidad) a.cantidad = elegido.cantidad;
                }
                pintarGrupos(); pintarPreview(); pintarBarra();
                var siguiente = rol === 'comp' && a.accion === 'sustituir'
                    ? cGrupos.querySelector('[data-elegir="nuevo"][data-g="' + gi2 + '"][data-o="' + oi2 + '"][data-a="' + ai2 + '"]')
                    : cGrupos.querySelector('[data-campo-ajuste="' + (rol === 'nuevo' ? 'nueva_cantidad' : 'cantidad') + '"][data-g="' + gi2 + '"][data-o="' + oi2 + '"][data-a="' + ai2 + '"]');
                if (siguiente) {
                    siguiente.focus();
                    if (siguiente.select) siguiente.select();
                }
            });
            return;
        }
        if (b.hasAttribute('data-probar')) {
            seleccion[b.getAttribute('data-grupo-key')] = b.getAttribute('data-probar');
            pintarPreview();
            var pb = cPos.querySelector('[data-probar="' + b.getAttribute('data-probar') + '"]');
            if (pb) pb.focus();
        }
    });

    // ── Validación local y guardado ─────────────────────────────

    function validar() {
        var errores = [];
        var nombres = {};
        estado.grupos.forEach(function (gr, i) {
            var nombre = gr.nombre.trim();
            var etiqueta = nombre ? '«' + nombre + '»' : 'el grupo ' + (i + 1);
            if (!nombre) errores.push('Ponle nombre al grupo ' + (i + 1) + ' (por ejemplo «Leche» o «Término»).');
            if (nombre && nombres[normal(nombre)]) errores.push('Hay dos grupos llamados «' + nombre + '».');
            nombres[normal(nombre)] = true;
            if (gr.opciones.length < 2) errores.push('En ' + etiqueta + ' agrega al menos dos opciones.');
            var vistas = {};
            gr.opciones.forEach(function (o) {
                var n = o.nombre.trim();
                if (!n) { errores.push('Hay una opción sin nombre en ' + etiqueta + '.'); return; }
                if (vistas[normal(n)]) errores.push('«' + n + '» aparece dos veces en ' + etiqueta + '.');
                vistas[normal(n)] = true;
                o.ajustes.forEach(function (a) {
                    if (!a.ref_id) errores.push('En «' + n + '» hay un ajuste sin ingrediente.');
                    else if (!(a.cantidad > 0)) errores.push('En «' + n + '» falta la cantidad de ' + nombreComponente(a.tipo, a.ref_id) + '.');
                    if (a.accion === 'sustituir' && !a.nuevo_ref_id) errores.push('En «' + n + '» elige por qué se cambia ' + (a.ref_id ? nombreComponente(a.tipo, a.ref_id) : 'el ingrediente') + '.');
                });
            });
        });
        return errores.filter(function (v, i, arr) { return arr.indexOf(v) === i; });
    }

    function mostrarErrores(errores) {
        if (!errores.length) {
            cErrores.hidden = true;
            cErrores.innerHTML = '';
            return;
        }
        cErrores.innerHTML = '<strong>Antes de guardar, revisa:</strong><ul>' + errores.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
        cErrores.hidden = false;
        cErrores.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function guardar() {
        if (guardando || !sucio()) return;
        var errores = validar();
        mostrarErrores(errores);
        if (errores.length) return;

        guardando = true;
        pintarBarra();
        fetch('/admin/api/menu/variantes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: JSON.stringify({ admin_csrf: csrf, producto_id: datos.producto_id, grupos: serializar() })
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, errores: ['El servidor no respondió como se esperaba.'] }; });
        }).then(function (res) {
            guardando = false;
            if (!res || !res.ok) {
                mostrarErrores((res && res.errores) || ['No fue posible guardar las variantes.']);
                pintarBarra();
                return;
            }
            // Los ids nuevos llegan del servidor; se conservan los paneles
            // abiertos por posición, que es lo que el usuario estaba mirando.
            var abiertosPorPosicion = {};
            estado.grupos.forEach(function (gr, gi) {
                gr.opciones.forEach(function (o, oi) { if (abiertos[o.key]) abiertosPorPosicion[gi + ':' + oi] = true; });
            });
            estado.grupos = desdeServidor(res.grupos);
            abiertos = {};
            estado.grupos.forEach(function (gr, gi) {
                gr.opciones.forEach(function (o, oi) { if (abiertosPorPosicion[gi + ':' + oi]) abiertos[o.key] = true; });
            });
            seleccion = {};
            guardado = JSON.stringify(serializar());
            mostrarErrores([]);
            pintarTodo();
            aviso('Variantes guardadas. El POS las toma al recargar el mapa.', 'success');
        }).catch(function () {
            guardando = false;
            pintarBarra();
            aviso('Sin conexión con el servidor. Tus cambios siguen en pantalla.', 'error');
        });
    }

    btnGuardar.addEventListener('click', guardar);
    btnDescartar.addEventListener('click', function () {
        confirmar({
            eyebrow: 'Variantes',
            title: '¿Descartar los cambios sin guardar?',
            description: 'Las variantes vuelven a como estaban en el último guardado.',
            primaryLabel: 'Descartar cambios',
            variant: 'danger'
        }).then(function (si) {
            if (!si) return;
            estado.grupos = desdeServidor(JSON.parse(guardado));
            abiertos = {};
            seleccion = {};
            mostrarErrores([]);
            pintarTodo();
        });
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            guardar();
        }
    });

    // ── Arranque ────────────────────────────────────────────────

    estado.grupos = desdeServidor(datos.grupos);
    guardado = JSON.stringify(serializar());
    // Un platillo sin variantes abre con la primera plantilla a la vista; uno
    // con variantes, con su primer grupo listo para editar.
    pintarTodo();
})();
