/**
 * Asistente IA del panel (views/admin/ia/index.php).
 *
 * Una conversación con los datos del restaurante. Hoy es SÓLO LA VISTA: quien
 * responde será un flujo de n8n que todavía no existe. Todo lo que habla con
 * él pasa por un único adaptador, `enviarMensaje()`, y el contrato está
 * documentado en docs/config.md («Asistente IA»):
 *
 *   POST <IA_N8N_WEBHOOK_URL>
 *   { conversation_id, message, history: [{ role, content }] }
 *   ← { reply: "<markdown>" }
 *
 * Sin URL configurada no se hace red y no se inventa nada: la respuesta es un
 * aviso de estado que dice que la conexión está pendiente.
 *
 * Las conversaciones viven en localStorage, sólo en este navegador. Si el
 * almacenamiento no está disponible, todo funciona en memoria y el pie del
 * historial lo advierte.
 *
 * ConfirmationModal, AppNotice y AdminIcons llegan en admin.js, que el layout
 * carga antes que este bundle.
 */
(function (window, document) {
    'use strict';

    var raiz = document.querySelector('[data-ia]');
    if (!raiz) return;

    /* ── Constantes ─────────────────────────────────────────────── */

    var CLAVE_CONVERSACIONES = 'cp-admin-ia-conversaciones-v1';
    var CLAVE_ACTIVA = 'cp-admin-ia-activa';
    // La lee también el script en línea de la vista, antes de pintar.
    var CLAVE_HISTORIAL = 'cp-admin-ia-historial-oculto';

    var MAX_CONVERSACIONES = 60;
    var MAX_MENSAJES = 200;
    // Lo que viaja a n8n como contexto. Veinte turnos sobran para seguir una
    // conversación y mantienen acotado el cuerpo de la petición.
    var MAX_HISTORIAL_ENVIADO = 20;
    // Una respuesta desmesurada no debe llenar el almacenamiento del navegador.
    var MAX_RESPUESTA = 40000;
    // Un flujo con un modelo de lenguaje puede tardar; más de minuto y medio ya
    // no es «pensando», es que algo se colgó.
    var TIEMPO_LIMITE = 90000;
    var UMBRAL_FINAL = 48;
    var UMBRAL_CONTADOR = 0.9;
    var TITULO_MAX = 52;

    var ENDPOINT = (raiz.getAttribute('data-ia-endpoint') || '').trim();
    var MAX_CARACTERES = parseInt(raiz.getAttribute('data-ia-max'), 10) || 4000;

    var mqCajon = window.matchMedia('(max-width: 992px)');
    var mqTactil = window.matchMedia('(hover: none) and (pointer: coarse)');
    var mqReducido = window.matchMedia('(prefers-reduced-motion: reduce)');

    var TEXTOS = {
        pendiente: 'El asistente todavía no está conectado con su flujo de n8n, así que no puede consultar los datos ni contestar. Tu pregunta queda guardada en esta conversación: cuando la conexión esté lista podrás reintentarla desde aquí.',
        detenida: 'Detuviste la respuesta. La pregunta sigue aquí por si quieres reintentarla.',
        sinRespuesta: 'La página se cerró o se recargó mientras el asistente pensaba. Puedes pedir la respuesta otra vez.',
        error: {
            red: 'No se pudo contactar con el asistente. Revisa la conexión a internet e inténtalo de nuevo.',
            tiempo: 'El asistente tardó más de 90 segundos y se dejó de esperar. Inténtalo de nuevo; si se repite, el flujo de n8n puede estar saturado.',
            http: 'El servicio del asistente respondió con un error{codigo}. Inténtalo de nuevo en unos minutos.',
            formato: 'El asistente contestó en un formato que esta pantalla no sabe leer. Hay que revisar la respuesta del flujo de n8n.'
        }
    };

    // «Conexión pendiente» lleva el reloj y no la «i»: no informa de algo que
    // pasó, avisa de algo que todavía no está. Es la misma familia ámbar que el
    // punto de la cabecera (ver .ia-aviso__icono en ia.scss).
    var AVISOS = {
        estado: { titulo: 'Conexión pendiente', icono: 'reloj', clase: '' },
        error: { titulo: 'No hubo respuesta', icono: 'error', clase: 'ia-aviso--error' },
        detenida: { titulo: 'Respuesta detenida', icono: 'info', clase: 'ia-aviso--nota' },
        sinRespuesta: { titulo: 'Sin respuesta', icono: 'info', clase: 'ia-aviso--nota' }
    };

    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /* ── Nodos ──────────────────────────────────────────────────── */

    var el = {
        historial: raiz.querySelector('[data-ia-historial]'),
        lista: raiz.querySelector('[data-ia-lista]'),
        listaVacia: raiz.querySelector('[data-ia-lista-vacia]'),
        almacen: raiz.querySelector('[data-ia-almacen]'),
        toggle: raiz.querySelector('[data-ia-historial-toggle]'),
        cerrar: raiz.querySelector('[data-ia-historial-cerrar]'),
        telon: raiz.querySelector('[data-ia-telon]'),
        titulo: raiz.querySelector('[data-ia-titulo]'),
        hilo: raiz.querySelector('[data-ia-hilo]'),
        interior: raiz.querySelector('.ia-hilo__interior'),
        vacio: raiz.querySelector('[data-ia-vacio]'),
        mensajes: raiz.querySelector('[data-ia-mensajes]'),
        irFinal: raiz.querySelector('[data-ia-ir-final]'),
        form: raiz.querySelector('[data-ia-compositor]'),
        campo: raiz.querySelector('[data-ia-campo]'),
        contador: raiz.querySelector('[data-ia-contador]'),
        enviar: raiz.querySelector('[data-ia-enviar]'),
        detener: raiz.querySelector('[data-ia-detener]'),
        anuncio: raiz.querySelector('[data-ia-anuncio]'),
        chat: raiz.querySelector('.ia-chat'),
        nuevaHistorial: raiz.querySelector('.ia-historial [data-ia-nueva]')
    };

    if (!el.hilo || !el.mensajes || !el.campo || !el.form || !el.lista) return;

    /* ── Almacenamiento ─────────────────────────────────────────── *
     * Todo acceso a localStorage va envuelto: en una ventana privada, con los
     * datos de sitio bloqueados o con la cuota llena, el accesor lanza. La
     * pantalla tiene que seguir funcionando igual, sólo que sin memoria. */

    var almacen = (function () {
        var disponible = false;
        try {
            var prueba = '__cp_ia_prueba__';
            window.localStorage.setItem(prueba, '1');
            window.localStorage.removeItem(prueba);
            disponible = true;
        } catch (error) {
            disponible = false;
        }

        return {
            disponible: function () { return disponible; },
            leer: function (clave) {
                if (!disponible) return null;
                try { return window.localStorage.getItem(clave); } catch (error) { return null; }
            },
            escribir: function (clave, valor) {
                if (!disponible) return false;
                try { window.localStorage.setItem(clave, valor); return true; } catch (error) { return false; }
            },
            borrar: function (clave) {
                if (!disponible) return;
                try { window.localStorage.removeItem(clave); } catch (error) { /* sin almacenamiento */ }
            }
        };
    })();

    /* ── Estado ─────────────────────────────────────────────────── */

    var estado = {
        conversaciones: [],
        // null = conversación nueva, todavía sin mensajes: no se guarda hasta
        // que tiene la primera pregunta.
        activaId: null,
        // id → { controlador, inicio }. Una por conversación: se puede seguir
        // preguntando en otra mientras ésta espera.
        pendientes: {},
        // Ids borrados en esta pestaña, para que la fusión con lo que escribió
        // otra pestaña no los resucite.
        borradas: {},
        // Texto a medio escribir por conversación ('' es la nueva). Sólo en
        // memoria: un borrador no merece sobrevivir a una recarga.
        borradores: {},
        pegadoAlFinal: true,
        programado: null,
        memoriaSola: !almacen.disponible(),
        avisoCuota: false
    };

    /* ── Utilidades ─────────────────────────────────────────────── */

    function crear(etiqueta, clase) {
        var nodo = document.createElement(etiqueta);
        if (clase) nodo.className = clase;
        return nodo;
    }

    function icono(nombre, tamano) {
        var catalogo = window.AdminIcons;
        if (!catalogo || typeof catalogo.get !== 'function') return '';
        return catalogo.get(nombre, tamano);
    }

    function textoOculto(texto) {
        var nodo = crear('span', 'admin-visually-hidden');
        nodo.textContent = texto;
        return nodo;
    }

    function numero(valor) {
        var n = Number(valor);
        return isFinite(n) && n > 0 ? n : 0;
    }

    // Los ids acaban en selectores de atributo; uno arbitrario leído del
    // almacenamiento rompería querySelector, así que sólo se aceptan éstos.
    function idValido(valor) {
        return typeof valor === 'string' && /^[A-Za-z0-9_-]{1,64}$/.test(valor);
    }

    function nuevoId(prefijo) {
        var aleatorio = '';
        try {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                aleatorio = window.crypto.randomUUID().replace(/-/g, '').slice(0, 16);
            }
        } catch (error) {
            aleatorio = '';
        }
        if (!aleatorio) {
            aleatorio = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        }
        return prefijo + '-' + aleatorio;
    }

    function dos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function miles(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Tabla propia y no toLocaleDateString: el idioma del navegador no es una
    // garantía (ver «El cajón» en CLAUDE.md, que resolvió lo mismo en el POS).
    function formatoHora(ms) {
        var fecha = new Date(ms);
        var hoy = new Date();
        var hora = dos(fecha.getHours()) + ':' + dos(fecha.getMinutes());
        if (fecha.toDateString() === hoy.toDateString()) return hora;
        return fecha.getDate() + ' ' + MESES[fecha.getMonth()] + ' · ' + hora;
    }

    function inicioDelDia(fecha) {
        return new Date(fecha.getFullYear(), fecha.getMonth(), fecha.getDate()).getTime();
    }

    function grupoDe(ms) {
        var hoy = new Date();
        var inicioHoy = inicioDelDia(hoy);
        var inicioAyer = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 1).getTime();
        var inicioSemana = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 7).getTime();
        if (ms >= inicioHoy) return 'Hoy';
        if (ms >= inicioAyer) return 'Ayer';
        if (ms >= inicioSemana) return 'Últimos 7 días';
        return 'Anteriores';
    }

    /**
     * Título de una conversación: su primera pregunta, recortada por palabra.
     * Sin llamar al modelo para «resumirla»: el admin reconoce su propia
     * pregunta mejor que cualquier resumen, y no cuesta una petición.
     */
    function derivarTitulo(texto) {
        var limpio = String(texto || '').replace(/\s+/g, ' ').trim();
        if (!limpio) return 'Conversación sin título';
        if (limpio.length <= TITULO_MAX) return limpio;
        var corte = limpio.slice(0, TITULO_MAX);
        var espacio = corte.lastIndexOf(' ');
        if (espacio > TITULO_MAX * 0.6) corte = corte.slice(0, espacio);
        return corte.replace(/[\s,.;:¿¡\-]+$/, '') + '…';
    }

    /* ── Persistencia ───────────────────────────────────────────── */

    var TIPOS_ASISTENTE = { respuesta: true, estado: true, error: true, detenida: true };

    function normalizarMensaje(m) {
        if (!m || typeof m !== 'object' || typeof m.texto !== 'string') return null;
        var rol = m.rol === 'usuario' || m.rol === 'asistente' ? m.rol : null;
        if (!rol) return null;
        var tipo = rol === 'usuario' ? 'pregunta' : (TIPOS_ASISTENTE[m.tipo] ? m.tipo : 'respuesta');
        return {
            id: idValido(m.id) ? m.id : nuevoId('m'),
            rol: rol,
            tipo: tipo,
            texto: m.texto,
            fecha: numero(m.fecha) || Date.now()
        };
    }

    function normalizarConversacion(c) {
        if (!c || typeof c !== 'object' || !idValido(c.id)) return null;
        var mensajes = Array.isArray(c.mensajes)
            ? c.mensajes.map(normalizarMensaje).filter(Boolean)
            : [];
        if (!mensajes.length) return null;
        var creada = numero(c.creada) || mensajes[0].fecha;
        var primera = '';
        for (var i = 0; i < mensajes.length; i++) {
            if (mensajes[i].rol === 'usuario') { primera = mensajes[i].texto; break; }
        }
        return {
            id: c.id,
            titulo: typeof c.titulo === 'string' && c.titulo.trim() ? c.titulo : derivarTitulo(primera),
            creada: creada,
            actualizada: numero(c.actualizada) || creada,
            noLeida: c.noLeida === true,
            mensajes: mensajes.slice(-MAX_MENSAJES)
        };
    }

    function leerConversaciones() {
        var crudo = almacen.leer(CLAVE_CONVERSACIONES);
        if (!crudo) return [];
        try {
            var datos = JSON.parse(crudo);
            var lista = datos && Array.isArray(datos.conversaciones) ? datos.conversaciones : [];
            return lista.map(normalizarConversacion).filter(Boolean);
        } catch (error) {
            return [];
        }
    }

    function ordenar() {
        estado.conversaciones.sort(function (a, b) { return b.actualizada - a.actualizada; });
    }

    function buscar(id) {
        if (!id) return null;
        for (var i = 0; i < estado.conversaciones.length; i++) {
            if (estado.conversaciones[i].id === id) return estado.conversaciones[i];
        }
        return null;
    }

    function activa() {
        return buscar(estado.activaId);
    }

    /**
     * Incorpora las conversaciones que otra pestaña haya guardado y esta no
     * conozca. Sin esto, dos pestañas abiertas se pisaban: la última en
     * escribir borraba lo que la otra había creado.
     */
    function incorporarAjenas() {
        var propias = {};
        estado.conversaciones.forEach(function (c) { propias[c.id] = true; });
        leerConversaciones().forEach(function (c) {
            if (!propias[c.id] && !estado.borradas[c.id]) estado.conversaciones.push(c);
        });
        ordenar();
    }

    function guardar() {
        if (estado.memoriaSola) return;
        incorporarAjenas();
        if (estado.conversaciones.length > MAX_CONVERSACIONES) {
            estado.conversaciones = estado.conversaciones.slice(0, MAX_CONVERSACIONES);
        }

        var lista = estado.conversaciones;
        var carga = JSON.stringify({ version: 1, conversaciones: lista });
        if (almacen.escribir(CLAVE_CONVERSACIONES, carga)) return;

        // Cuota llena: se guardan las más recientes que quepan. Las demás
        // siguen en pantalla hasta recargar, y se avisa una vez.
        while (lista.length > 1) {
            lista = lista.slice(0, Math.ceil(lista.length * 0.75));
            carga = JSON.stringify({ version: 1, conversaciones: lista });
            if (almacen.escribir(CLAVE_CONVERSACIONES, carga)) {
                avisarCuota('El navegador se quedó sin espacio: sólo se guardaron las ' + lista.length + ' conversaciones más recientes.');
                return;
            }
        }
        avisarCuota('El navegador no deja guardar más: esta conversación se perderá al recargar.');
    }

    function avisarCuota(texto) {
        if (estado.avisoCuota) return;
        estado.avisoCuota = true;
        if (window.AppNotice && typeof window.AppNotice.show === 'function') {
            window.AppNotice.show({ text: texto, variant: 'warning' });
        }
    }

    /* ── Markdown mínimo y seguro ───────────────────────────────── *
     *
     * La respuesta llega de un servicio externo y puede traer cualquier cosa,
     * así que el orden no se negocia: PRIMERO se escapa el HTML y DESPUÉS se da
     * formato. Las únicas etiquetas que salen de aquí son las que este código
     * escribe, y lo único que se copia a un atributo es la URL de un enlace,
     * restringida a http(s) o a una ruta del propio panel.
     *
     * Soporta lo que de verdad usa una respuesta sobre datos: párrafos,
     * negritas, cursivas, listas, código, citas, encabezados y tablas simples.
     * Las cifras se marcan para que salgan en mono tabular.
     */

    function escapar(texto) {
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Un número suelto: con signo, $, separadores, hora o porcentaje. No marca
    // los pegados a letras («3er», «12kg»): ésos son palabras, no cifras.
    var RE_NUMERO = /(^|[^\w\u00C0-\u024F])([+\-\u2212]?\$?\d(?:[\d.,:]*\d)?%?)(?![\w\u00C0-\u024F])/g;

    // Una celda que es una cifra, con unidad corta opcional («12 kg», «$1,200»).
    var RE_CELDA_CIFRA = /^\(?[+\-\u2212]?\s*[$€]?\s*[+\-\u2212]?\d[\d.,:/\s]*%?\)?(?:\s*[a-záéíóúñ.]{1,5})?$/i;

    var RE_ITEM = /^(\s*)([-*+\u2022]|\d{1,3}[.)])\s+(.*)$/;

    function marcarCifras(html) {
        var partes = html.split(/(<[^>]+>)/);
        var enCodigo = 0;
        for (var k = 0; k < partes.length; k++) {
            var parte = partes[k];
            if (!parte) continue;
            if (parte.charAt(0) === '<') {
                if (/^<code\b/.test(parte)) enCodigo++;
                else if (/^<\/code>/.test(parte)) enCodigo = Math.max(0, enCodigo - 1);
                continue;
            }
            if (enCodigo > 0) continue;
            partes[k] = parte.replace(RE_NUMERO, '$1<span class="ia-num">$2</span>');
        }
        return partes.join('');
    }

    function enLinea(texto) {
        var apartados = [];
        // Lo que no debe recibir más formato (el código y la APERTURA de un
        // enlace, con su href) se aparta tras un marcador y se restituye al
        // final. Sin esto, un `*` o un `_` dentro de una URL abría una
        // cursiva a mitad del atributo y marcarCifras metía un <span> en él.
        // Los marcadores no llevan dígitos (área de uso privado): si los
        // llevaran, marcarCifras los envolvería y no se podrían restituir.
        function apartar(html) {
            apartados.push(html);
            return '\u0000' + String.fromCharCode(0xE000 + apartados.length - 1) + '\u0000';
        }

        var s = escapar(String(texto || '').replace(/\u0000/g, ''));

        s = s.replace(/`([^`]+)`/g, function (coincidencia, codigo) {
            return apartar('<code>' + codigo + '</code>');
        });

        // La etiqueta del enlace sí queda en el texto: puede llevar negritas
        // o cifras como cualquier otra frase.
        s = s.replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/(?![\/\\]))[^\s)"\\]*)\)/g, function (coincidencia, etiqueta, url) {
            var externo = /^https?:/i.test(url);
            return apartar('<a href="' + url + '"' + (externo ? ' target="_blank" rel="noopener noreferrer"' : '') + '>') +
                etiqueta + apartar('</a>');
        });

        s = s.replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/__(?=\S)([\s\S]*?\S)__/g, '<strong>$1</strong>');
        s = s.replace(/(^|[^*\w])\*(?=\S)([^*]*?\S)\*(?![*\w])/g, '$1<em>$2</em>');
        s = s.replace(/(^|[^_\w])_(?=\S)([^_]*?\S)_(?![_\w])/g, '$1<em>$2</em>');
        s = s.replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<del>$1</del>');

        s = marcarCifras(s);

        return s.replace(/\u0000([\uE000-\uF8FF])\u0000/g, function (coincidencia, marca) {
            return apartados[marca.charCodeAt(0) - 0xE000] || '';
        });
    }

    function partirFila(linea) {
        var s = linea.trim();
        if (s.charAt(0) === '|') s = s.slice(1);
        if (s.charAt(s.length - 1) === '|' && s.charAt(s.length - 2) !== '\\') s = s.slice(0, -1);
        var celdas = [];
        var actual = '';
        for (var k = 0; k < s.length; k++) {
            var ch = s.charAt(k);
            if (ch === '\\' && s.charAt(k + 1) === '|') { actual += '|'; k++; continue; }
            if (ch === '|') { celdas.push(actual.trim()); actual = ''; continue; }
            actual += ch;
        }
        celdas.push(actual.trim());
        return celdas;
    }

    function esFilaTabla(linea) {
        return linea.indexOf('|') !== -1 && partirFila(linea).length >= 2;
    }

    function esSeparadorTabla(linea) {
        if (!linea || linea.indexOf('-') === -1) return false;
        var celdas = partirFila(linea);
        for (var k = 0; k < celdas.length; k++) {
            if (!/^:?-+:?$/.test(celdas[k].replace(/\s/g, ''))) return false;
        }
        return celdas.length > 0;
    }

    function tablaMd(lineas, i) {
        var cabecera = partirFila(lineas[i]);
        var alineaciones = partirFila(lineas[i + 1]).map(function (celda) {
            var c = celda.replace(/\s/g, '');
            var izquierda = c.charAt(0) === ':';
            var derecha = c.charAt(c.length - 1) === ':';
            if (izquierda && derecha) return 'centro';
            return derecha ? 'derecha' : '';
        });
        var filas = [];
        i += 2;
        while (i < lineas.length && !/^\s*$/.test(lineas[i]) && lineas[i].indexOf('|') !== -1) {
            filas.push(partirFila(lineas[i]));
            i++;
        }

        // Una columna es numérica si TODAS sus celdas con contenido son cifras:
        // basta una palabra para que alinear a la derecha la descoloque.
        var numericas = cabecera.map(function (titulo, col) {
            var vistas = 0;
            var cifras = 0;
            filas.forEach(function (fila) {
                var valor = (fila[col] || '').replace(/[*_`]/g, '').trim();
                if (!valor) return;
                vistas++;
                if (RE_CELDA_CIFRA.test(valor)) cifras++;
            });
            return vistas > 0 && cifras === vistas;
        });

        function clase(col) {
            if (numericas[col]) return ' class="ia-md__celda-num"';
            if (alineaciones[col] === 'centro') return ' class="ia-md__al-centro"';
            if (alineaciones[col] === 'derecha') return ' class="ia-md__al-derecha"';
            return '';
        }

        var html = '<div class="ia-md__tabla"><table><thead><tr>';
        cabecera.forEach(function (titulo, col) {
            html += '<th scope="col"' + clase(col) + '>' + enLinea(titulo) + '</th>';
        });
        html += '</tr></thead><tbody>';
        filas.forEach(function (fila) {
            html += '<tr>';
            for (var col = 0; col < cabecera.length; col++) {
                html += '<td' + clase(col) + '>' + enLinea(fila[col] || '') + '</td>';
            }
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return { html: html, siguiente: i };
    }

    function listaMd(lineas, i) {
        var primera = lineas[i].match(RE_ITEM);
        var ordenada = /\d/.test(primera[2]);
        var inicio = ordenada ? parseInt(primera[2], 10) : 1;
        var items = [];
        while (i < lineas.length) {
            var item = lineas[i].match(RE_ITEM);
            if (item) {
                if (/\d/.test(item[2]) !== ordenada) break;
                items.push(item[3]);
                i++;
                continue;
            }
            // Continuación del punto anterior: una línea sangrada.
            if (items.length && /^\s{2,}\S/.test(lineas[i])) {
                items[items.length - 1] += '\n' + lineas[i].trim();
                i++;
                continue;
            }
            break;
        }
        var etiqueta = ordenada ? 'ol' : 'ul';
        var apertura = '<' + etiqueta + (ordenada && inicio > 1 ? ' start="' + inicio + '"' : '') + '>';
        var cuerpo = items.map(function (texto) {
            return '<li>' + texto.split('\n').map(enLinea).join('<br>') + '</li>';
        }).join('');
        return { html: apertura + cuerpo + '</' + etiqueta + '>', siguiente: i };
    }

    function iniciaBloque(lineas, i) {
        var linea = lineas[i];
        return /^\s*(```|~~~)/.test(linea) ||
            /^\s{0,3}#{1,6}\s+/.test(linea) ||
            /^\s{0,3}>/.test(linea) ||
            RE_ITEM.test(linea) ||
            (esFilaTabla(linea) && esSeparadorTabla(lineas[i + 1]));
    }

    function markdown(fuente) {
        var lineas = String(fuente || '').replace(/\r\n?/g, '\n').split('\n');
        var html = [];
        var i = 0;

        while (i < lineas.length) {
            var linea = lineas[i];

            if (/^\s*$/.test(linea)) { i++; continue; }

            var valla = linea.match(/^\s*(```|~~~)/);
            if (valla) {
                var codigo = [];
                i++;
                while (i < lineas.length && lineas[i].trim().indexOf(valla[1]) !== 0) {
                    codigo.push(lineas[i]);
                    i++;
                }
                i++;
                html.push('<pre><code>' + escapar(codigo.join('\n')) + '</code></pre>');
                continue;
            }

            // h1/h2 → h3 y el resto → h4: dentro de un mensaje no hay sitio para
            // titulares de página, y el <h1> ya es el de la conversación.
            var titulo = linea.match(/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/);
            if (titulo) {
                var nivel = titulo[1].length <= 2 ? 3 : 4;
                html.push('<h' + nivel + ' class="ia-md__h">' + enLinea(titulo[2]) + '</h' + nivel + '>');
                i++;
                continue;
            }

            if (/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(linea)) {
                html.push('<hr>');
                i++;
                continue;
            }

            if (esFilaTabla(linea) && esSeparadorTabla(lineas[i + 1])) {
                var tabla = tablaMd(lineas, i);
                html.push(tabla.html);
                i = tabla.siguiente;
                continue;
            }

            if (/^\s{0,3}>/.test(linea)) {
                var cita = [];
                while (i < lineas.length && /^\s{0,3}>/.test(lineas[i])) {
                    cita.push(lineas[i].replace(/^\s{0,3}>\s?/, ''));
                    i++;
                }
                html.push('<blockquote>' + markdown(cita.join('\n')) + '</blockquote>');
                continue;
            }

            if (RE_ITEM.test(linea)) {
                var lista = listaMd(lineas, i);
                html.push(lista.html);
                i = lista.siguiente;
                continue;
            }

            // Párrafo: la primera línea entra siempre (si no, una línea que no
            // encaja en nada dejaría el bucle sin avanzar).
            var parrafo = [linea.trim()];
            i++;
            while (i < lineas.length && !/^\s*$/.test(lineas[i]) && !iniciaBloque(lineas, i)) {
                parrafo.push(lineas[i].trim());
                i++;
            }
            html.push('<p>' + parrafo.map(enLinea).join('<br>') + '</p>');
        }

        return html.join('');
    }

    /* ── El adaptador ───────────────────────────────────────────── *
     *
     * ÚNICO punto de contacto con el asistente. Devuelve una promesa que
     * resuelve con { tipo, texto } —'respuesta' con el markdown, o 'estado'
     * cuando no hay conexión configurada— y rechaza con { motivo } cuando la
     * petición falla: 'detenida', 'tiempo', 'red', 'http' (con `codigo`) o
     * 'formato'. Respeta el AbortController del botón Detener.
     */

    function historialPara(conversacion, texto) {
        var mensajes = conversacion.mensajes.slice();
        var ultima = mensajes[mensajes.length - 1];
        // La pregunta en curso viaja en `message`, no repetida en el historial.
        if (ultima && ultima.rol === 'usuario' && ultima.texto === texto) mensajes.pop();
        var historial = [];
        mensajes.forEach(function (m) {
            // Los avisos de estado y error los escribió esta pantalla, no el
            // asistente: mandárselos como si fueran suyos lo confundiría.
            if (m.rol === 'usuario') historial.push({ role: 'user', content: m.texto });
            else if (m.tipo === 'respuesta') historial.push({ role: 'assistant', content: m.texto });
        });
        return historial.slice(-MAX_HISTORIAL_ENVIADO);
    }

    function leerRespuesta(crudo, tipoContenido) {
        var texto = String(crudo || '');
        var datos = null;
        if (texto.trim()) {
            try { datos = JSON.parse(texto); } catch (error) { datos = null; }
        }
        if (datos !== null) {
            // «Respond to Webhook» de n8n devuelve una lista cuando responde
            // con todos los ítems; se toma el primero.
            if (Array.isArray(datos)) datos = datos[0];
            var respuesta = null;
            if (datos && typeof datos.reply === 'string') respuesta = datos.reply;
            // `output` es la salida por defecto del nodo AI Agent: se acepta
            // para que un flujo recién armado funcione sin un nodo de mapeo.
            else if (datos && typeof datos.output === 'string') respuesta = datos.output;
            if (respuesta && respuesta.trim()) return respuesta;
            throw { motivo: 'formato' };
        }
        if (/^text\/(plain|markdown)/i.test(tipoContenido || '') && texto.trim()) return texto;
        throw { motivo: 'formato' };
    }

    function enviarMensaje(conversacion, texto, signal) {
        if (!ENDPOINT) {
            // Sin integración no hay red ni espera simulada: el aviso sale en
            // el mismo fotograma que la pregunta, y la entrada escalonada la
            // pone el CSS (.is-nuevo-tras), no un temporizador.
            return Promise.resolve({ tipo: 'estado', texto: TEXTOS.pendiente });
        }

        var cuerpo = {
            conversation_id: conversacion.id,
            message: texto,
            history: historialPara(conversacion, texto)
        };

        return new Promise(function (resolve, reject) {
            var vencido = false;
            var interno = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var reloj = window.setTimeout(function () {
                vencido = true;
                if (interno) interno.abort();
                else reject({ motivo: 'tiempo' });
            }, TIEMPO_LIMITE);

            if (signal && interno) {
                if (signal.aborted) interno.abort();
                else signal.addEventListener('abort', function () { interno.abort(); });
            }

            window.fetch(ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json, text/plain;q=0.9'
                },
                body: JSON.stringify(cuerpo),
                // n8n vive en otro origen: la cookie de sesión del panel no
                // tiene nada que hacer allí.
                credentials: 'omit',
                cache: 'no-store',
                signal: interno ? interno.signal : undefined
            }).then(function (respuesta) {
                if (!respuesta.ok) {
                    throw { motivo: 'http', codigo: respuesta.status };
                }
                var tipo = respuesta.headers.get('Content-Type') || '';
                return respuesta.text().then(function (crudo) {
                    return leerRespuesta(crudo, tipo);
                });
            }).then(function (respuesta) {
                window.clearTimeout(reloj);
                resolve({ tipo: 'respuesta', texto: respuesta.slice(0, MAX_RESPUESTA) });
            }).catch(function (error) {
                window.clearTimeout(reloj);
                if (error && error.motivo) { reject(error); return; }
                if (vencido) { reject({ motivo: 'tiempo' }); return; }
                if ((signal && signal.aborted) || (error && error.name === 'AbortError')) {
                    reject({ motivo: 'detenida' });
                    return;
                }
                reject({ motivo: 'red' });
            });
        });
    }

    function resultadoDeFallo(fallo) {
        var motivo = fallo && fallo.motivo;
        if (motivo === 'detenida') return { tipo: 'detenida', texto: TEXTOS.detenida };
        var plantilla = TEXTOS.error[motivo] || TEXTOS.error.red;
        var codigo = fallo && fallo.codigo ? ' (código ' + fallo.codigo + ')' : '';
        return { tipo: 'error', texto: plantilla.replace('{codigo}', codigo) };
    }

    /* ── Anuncios para lector de pantalla ───────────────────────── */

    var relojAnuncio = null;

    // Se vacía y se rellena en dos tiempos: si el texto nuevo es idéntico al
    // anterior (dos «Conexión pendiente» seguidos), sin el vaciado no se lee.
    function anunciar(texto) {
        if (!el.anuncio) return;
        window.clearTimeout(relojAnuncio);
        el.anuncio.textContent = '';
        relojAnuncio = window.setTimeout(function () {
            el.anuncio.textContent = texto;
        }, 80);
    }

    /* ── Pintado: historial ─────────────────────────────────────── */

    function itemConversacion(c) {
        var li = crear('li', 'ia-conv' + (c.id === estado.activaId ? ' is-activa' : ''));

        var abrir = crear('button', 'ia-conv__abrir');
        abrir.type = 'button';
        abrir.setAttribute('data-ia-abrir', c.id);
        abrir.title = c.titulo;
        if (c.id === estado.activaId) abrir.setAttribute('aria-current', 'true');

        var texto = crear('span', 'ia-conv__texto');
        texto.textContent = c.titulo;
        abrir.appendChild(texto);

        if (estado.pendientes[c.id]) {
            var esperando = crear('span', 'ia-conv__marca ia-conv__marca--esperando');
            esperando.setAttribute('aria-hidden', 'true');
            abrir.appendChild(esperando);
            abrir.appendChild(textoOculto(', esperando respuesta'));
        } else if (c.noLeida) {
            var marca = crear('span', 'ia-conv__marca');
            marca.setAttribute('aria-hidden', 'true');
            abrir.appendChild(marca);
            abrir.appendChild(textoOculto(', respuesta nueva'));
        }

        var borrar = crear('button', 'ia-conv__borrar');
        borrar.type = 'button';
        borrar.setAttribute('data-ia-borrar', c.id);
        borrar.setAttribute('aria-label', 'Borrar la conversación «' + c.titulo + '»');
        borrar.title = 'Borrar conversación';
        borrar.innerHTML = icono('basura', 16);
        if (!borrar.innerHTML) borrar.textContent = 'Borrar';

        li.appendChild(abrir);
        li.appendChild(borrar);
        return li;
    }

    function pintarHistorial() {
        var viejos = el.lista.querySelectorAll('.ia-grupo');
        for (var k = 0; k < viejos.length; k++) viejos[k].parentNode.removeChild(viejos[k]);

        if (el.listaVacia) el.listaVacia.hidden = estado.conversaciones.length > 0;

        var grupos = [];
        var actual = null;
        estado.conversaciones.forEach(function (c) {
            var nombre = grupoDe(c.actualizada);
            if (!actual || actual.nombre !== nombre) {
                actual = { nombre: nombre, items: [] };
                grupos.push(actual);
            }
            actual.items.push(c);
        });

        var fragmento = document.createDocumentFragment();
        grupos.forEach(function (grupo, indice) {
            var bloque = crear('div', 'ia-grupo');
            var titulo = crear('p', 'ia-grupo__titulo');
            titulo.id = 'ia-grupo-' + indice;
            titulo.textContent = grupo.nombre;
            var lista = crear('ul', 'ia-grupo__lista');
            lista.setAttribute('aria-labelledby', titulo.id);
            grupo.items.forEach(function (c) { lista.appendChild(itemConversacion(c)); });
            bloque.appendChild(titulo);
            bloque.appendChild(lista);
            fragmento.appendChild(bloque);
        });
        el.lista.appendChild(fragmento);
    }

    /* ── Pintado: hilo ──────────────────────────────────────────── */

    function marcaAsistente(viva) {
        var marca = crear('span', 'ia-marca' + (viva ? ' is-viva' : ''));
        marca.setAttribute('aria-hidden', 'true');
        marca.innerHTML = icono('ia', 18);
        return marca;
    }

    function botonAccion(nombreIcono, etiqueta, atributo, valor, descripcion) {
        var boton = crear('button', 'ia-accion');
        boton.type = 'button';
        boton.setAttribute(atributo, valor);
        if (descripcion) boton.setAttribute('aria-label', descripcion);
        var svg = icono(nombreIcono, 15);
        if (svg) {
            var caja = crear('span', 'ia-accion__icono');
            caja.setAttribute('aria-hidden', 'true');
            caja.innerHTML = svg;
            boton.appendChild(caja);
        }
        var texto = crear('span', 'ia-accion__texto');
        texto.textContent = etiqueta;
        boton.appendChild(texto);
        return boton;
    }

    function horaMensaje(m) {
        var hora = crear('time', 'ia-msg__hora');
        var fecha = new Date(m.fecha);
        try { hora.dateTime = fecha.toISOString(); } catch (error) { /* fecha inválida */ }
        hora.textContent = formatoHora(m.fecha);
        return hora;
    }

    function nodoAviso(clave, texto) {
        var datos = AVISOS[clave] || AVISOS.estado;
        var aviso = crear('div', 'ia-aviso' + (datos.clase ? ' ' + datos.clase : ''));
        var chip = crear('span', 'ia-aviso__icono');
        chip.setAttribute('aria-hidden', 'true');
        chip.innerHTML = icono(datos.icono, 16);
        var titulo = crear('p', 'ia-aviso__titulo');
        titulo.textContent = datos.titulo;
        var cuerpo = crear('p', 'ia-aviso__texto');
        cuerpo.textContent = texto;
        aviso.appendChild(chip);
        aviso.appendChild(titulo);
        aviso.appendChild(cuerpo);
        return aviso;
    }

    function nodoMensaje(m, nuevo) {
        var li = crear('li', 'ia-msg ia-msg--' + m.rol + (nuevo ? ' is-nuevo' : ''));
        li.setAttribute('data-ia-msg', m.id);
        var pie = crear('div', 'ia-msg__pie');

        if (m.rol === 'usuario') {
            li.appendChild(textoOculto('Tú:'));
            var burbuja = crear('div', 'ia-msg__burbuja');
            burbuja.textContent = m.texto;
            li.appendChild(burbuja);
            pie.appendChild(horaMensaje(m));
            pie.appendChild(botonAccion('copiar', 'Copiar', 'data-ia-copiar', m.id, 'Copiar la pregunta'));
            li.appendChild(pie);
            return li;
        }

        li.appendChild(marcaAsistente(false));
        var contenido = crear('div', 'ia-msg__contenido');
        contenido.appendChild(textoOculto('Asistente:'));

        if (m.tipo === 'respuesta') {
            var md = crear('div', 'ia-md');
            md.innerHTML = markdown(m.texto);
            contenido.appendChild(md);
            pie.appendChild(botonAccion('copiar', 'Copiar', 'data-ia-copiar', m.id, 'Copiar la respuesta'));
        } else {
            contenido.appendChild(nodoAviso(m.tipo, m.texto));
        }
        pie.appendChild(botonAccion('reintentar', 'Reintentar', 'data-ia-reintentar', m.id, 'Pedir la respuesta otra vez'));
        pie.appendChild(horaMensaje(m));
        contenido.appendChild(pie);
        li.appendChild(contenido);
        return li;
    }

    // La última pregunta no tiene respuesta y nada la está esperando: la
    // página se cerró a medio camino. No se guarda; se deduce al pintar.
    function nodoSinRespuesta() {
        var li = crear('li', 'ia-msg ia-msg--asistente');
        li.setAttribute('data-ia-sin-respuesta', '');
        li.appendChild(marcaAsistente(false));
        var contenido = crear('div', 'ia-msg__contenido');
        contenido.appendChild(nodoAviso('sinRespuesta', TEXTOS.sinRespuesta));
        var pie = crear('div', 'ia-msg__pie');
        pie.appendChild(botonAccion('reintentar', 'Reintentar', 'data-ia-reintentar', '', 'Pedir la respuesta otra vez'));
        contenido.appendChild(pie);
        li.appendChild(contenido);
        return li;
    }

    function nodoPensando(nuevo) {
        var li = crear('li', 'ia-msg ia-msg--asistente' + (nuevo ? ' is-nuevo' : ''));
        li.setAttribute('data-ia-pensando', '');
        li.appendChild(marcaAsistente(true));
        var contenido = crear('div', 'ia-msg__contenido');
        var fila = crear('div', 'ia-pensando');
        var texto = crear('span', 'ia-pensando__texto');
        texto.textContent = 'Pensando';
        var segundos = crear('span', 'ia-pensando__tiempo');
        segundos.setAttribute('data-ia-segundos', '');
        segundos.hidden = true;
        fila.appendChild(texto);
        fila.appendChild(segundos);
        contenido.appendChild(fila);
        li.appendChild(contenido);
        return li;
    }

    function quitarNodo(selector) {
        var nodo = el.mensajes.querySelector(selector);
        if (nodo && nodo.parentNode) nodo.parentNode.removeChild(nodo);
    }

    function mostrarHilo(hayMensajes) {
        if (el.vacio) el.vacio.hidden = hayMensajes;
        el.mensajes.hidden = !hayMensajes;
    }

    function pintarTitulo() {
        var c = activa();
        if (el.titulo) el.titulo.textContent = c ? c.titulo : 'Nueva conversación';
    }

    function pintarHilo() {
        var c = activa();
        el.mensajes.innerHTML = '';
        var hay = !!(c && c.mensajes.length);
        mostrarHilo(hay);

        if (hay) {
            var fragmento = document.createDocumentFragment();
            c.mensajes.forEach(function (m) { fragmento.appendChild(nodoMensaje(m, false)); });
            var ultima = c.mensajes[c.mensajes.length - 1];
            if (estado.pendientes[c.id]) {
                if (ENDPOINT) fragmento.appendChild(nodoPensando(false));
            } else if (ultima.rol === 'usuario') {
                fragmento.appendChild(nodoSinRespuesta());
            }
            el.mensajes.appendChild(fragmento);
            tic();
        }

        pintarTitulo();
        actualizarCompositor();
        estado.pegadoAlFinal = true;
        desplazarA(Infinity, false);
    }

    /* ── Scroll del hilo ────────────────────────────────────────── *
     *
     * Se sigue el final mientras el admin esté abajo. Si subió a releer, nada
     * lo arrastra: aparece la píldora «Ir al final». Mientras corre un
     * desplazamiento programado (suave), las posiciones intermedias no cuentan
     * —si contaran, la píldora parpadearía durante el propio viaje al final—.
     */

    function distanciaAlFinal() {
        return el.hilo.scrollHeight - el.hilo.scrollTop - el.hilo.clientHeight;
    }

    function actualizarIrFinal() {
        if (!el.irFinal) return;
        var mostrar = !estado.pegadoAlFinal && !el.mensajes.hidden && !estado.programado;
        el.irFinal.classList.toggle('is-visible', mostrar);
        el.irFinal.setAttribute('aria-hidden', mostrar ? 'false' : 'true');
        el.irFinal.tabIndex = mostrar ? 0 : -1;
    }

    function recalcularPegado() {
        estado.pegadoAlFinal = distanciaAlFinal() <= UMBRAL_FINAL;
        actualizarIrFinal();
    }

    function desplazarA(destino, suave) {
        var maximo = Math.max(0, el.hilo.scrollHeight - el.hilo.clientHeight);
        var objetivo = Math.max(0, Math.min(destino, maximo));
        var lejos = Math.abs(el.hilo.scrollTop - objetivo) >= 2;

        if (!suave || mqReducido.matches || !lejos || typeof el.hilo.scrollTo !== 'function') {
            estado.programado = null;
            el.hilo.scrollTop = objetivo;
            recalcularPegado();
            return;
        }

        var viaje = { destino: objetivo };
        estado.programado = viaje;
        actualizarIrFinal();
        el.hilo.scrollTo({ top: objetivo, behavior: 'smooth' });
        // Por si el navegador corta el viaje sin un último evento de scroll.
        window.setTimeout(function () {
            if (estado.programado === viaje) {
                estado.programado = null;
                recalcularPegado();
            }
        }, 900);
    }

    function irAlFinal(suave) {
        estado.pegadoAlFinal = true;
        desplazarA(Infinity, suave);
    }

    function alturaRelativa(nodo) {
        return nodo.getBoundingClientRect().top - el.hilo.getBoundingClientRect().top + el.hilo.scrollTop;
    }

    /**
     * Al llegar una respuesta larga no se salta a su ÚLTIMA línea —no hay
     * streaming que ir siguiendo, el texto llega entero—: se deja a la vista la
     * pregunta y el arranque de la respuesta. Si cabe todo, es lo mismo que ir
     * al final.
     */
    function revelarRespuesta(nodoRespuesta) {
        var pregunta = nodoRespuesta.previousElementSibling;
        var ancla = pregunta && pregunta.classList.contains('ia-msg--usuario') ? pregunta : nodoRespuesta;
        desplazarA(alturaRelativa(ancla) - 16, true);
    }

    el.hilo.addEventListener('scroll', function () {
        var viaje = estado.programado;
        if (viaje) {
            if (Math.abs(el.hilo.scrollTop - viaje.destino) < 2) estado.programado = null;
            else return;
        }
        recalcularPegado();
    }, { passive: true });

    // Si el admin toca la rueda o el hilo, el desplazamiento programado deja
    // de mandar: es él quien decide dónde leer.
    ['wheel', 'touchstart', 'keydown'].forEach(function (tipo) {
        el.hilo.addEventListener(tipo, function () {
            if (estado.programado) {
                estado.programado = null;
                window.setTimeout(recalcularPegado, 0);
            }
        }, { passive: true });
    });

    // El hilo cambia de alto sin que nadie haga scroll: llega una fuente, crece
    // el campo de texto, se pliega el historial. Si se estaba al final, se sigue.
    if (typeof window.ResizeObserver === 'function') {
        var observador = new window.ResizeObserver(function () {
            if (estado.pegadoAlFinal && !estado.programado) {
                el.hilo.scrollTop = el.hilo.scrollHeight;
            }
            actualizarIrFinal();
        });
        observador.observe(el.hilo);
        if (el.interior) observador.observe(el.interior);
    }

    if (el.irFinal) {
        el.irFinal.addEventListener('click', function () {
            irAlFinal(true);
            rescatarFoco();
        });
    }

    /* ── Foco ───────────────────────────────────────────────────── */

    function enfocarCampo(forzar) {
        // En táctil, enfocar el campo despliega el teclado y tapa media
        // pantalla: sólo cuando la persona lo pidió.
        if (!forzar && mqTactil.matches) return;
        try { el.campo.focus({ preventScroll: true }); } catch (error) { el.campo.focus(); }
        var fin = el.campo.value.length;
        try { el.campo.setSelectionRange(fin, fin); } catch (error) { /* sin selección */ }
    }

    /** Tras cambiar de conversación: el campo con teclado, el título en táctil. */
    function enfocarTrasCambio() {
        if (!mqTactil.matches) {
            enfocarCampo(true);
            return;
        }
        if (el.titulo) {
            try { el.titulo.focus({ preventScroll: true }); } catch (error) { el.titulo.focus(); }
        }
    }

    /**
     * El botón que tenía el foco desapareció (Detener al llegar la respuesta,
     * Reintentar al retirarse, la píldora al llegar al final). Sin esto el foco
     * cae al <body> y quien usa teclado vuelve a empezar desde arriba.
     */
    function rescatarFoco() {
        window.requestAnimationFrame(function () {
            var actual = document.activeElement;
            var visible = actual && actual !== document.body && document.documentElement.contains(actual) &&
                actual.getClientRects().length > 0 &&
                window.getComputedStyle(actual).visibility !== 'hidden';
            if (visible) return;
            if (!mqTactil.matches) {
                enfocarCampo(true);
                return;
            }
            try { el.hilo.focus({ preventScroll: true }); } catch (error) { el.hilo.focus(); }
        });
    }

    /* ── Compositor ─────────────────────────────────────────────── */

    function ajustarAltura() {
        el.campo.style.height = 'auto';
        var maximo = parseFloat(window.getComputedStyle(el.campo).maxHeight) || 220;
        el.campo.style.height = Math.min(el.campo.scrollHeight, maximo) + 'px';
    }

    function actualizarCompositor() {
        var c = activa();
        var esperando = !!(c && estado.pendientes[c.id]);
        var largo = el.campo.value.length;
        var vacio = !el.campo.value.trim();

        el.enviar.hidden = esperando;
        if (el.detener) el.detener.hidden = !esperando;
        el.enviar.disabled = vacio || largo > MAX_CARACTERES;

        if (el.contador) {
            if (largo >= Math.floor(MAX_CARACTERES * UMBRAL_CONTADOR)) {
                el.contador.hidden = false;
                el.contador.textContent = miles(largo) + ' / ' + miles(MAX_CARACTERES);
                el.contador.classList.toggle('is-limite', largo >= MAX_CARACTERES);
            } else {
                el.contador.hidden = true;
            }
        }
    }

    function guardarBorrador() {
        estado.borradores[estado.activaId || ''] = el.campo.value;
    }

    function restaurarBorrador() {
        el.campo.value = estado.borradores[estado.activaId || ''] || '';
        ajustarAltura();
        actualizarCompositor();
    }

    /* ── Conversación ───────────────────────────────────────────── */

    function recortar(c) {
        if (c.mensajes.length > MAX_MENSAJES) c.mensajes = c.mensajes.slice(-MAX_MENSAJES);
    }

    var relojSegundos = null;

    function tic() {
        var c = activa();
        var pendiente = c && estado.pendientes[c.id];
        var nodo = el.mensajes.querySelector('[data-ia-segundos]');
        if (!pendiente || !nodo) return;
        var segundos = Math.floor((Date.now() - pendiente.inicio) / 1000);
        // Los dos primeros segundos son lo esperable; el contador aparece
        // cuando empieza a ser información.
        if (segundos >= 2) {
            nodo.hidden = false;
            nodo.textContent = segundos + ' s';
        }
    }

    function arrancarReloj() {
        if (relojSegundos) return;
        relojSegundos = window.setInterval(function () {
            if (!Object.keys(estado.pendientes).length) {
                window.clearInterval(relojSegundos);
                relojSegundos = null;
                return;
            }
            tic();
        }, 1000);
    }

    function textoAnunciable(nodo, m) {
        if (m.tipo === 'respuesta') {
            var md = nodo.querySelector('.ia-md');
            return 'El asistente respondió: ' + (md ? md.textContent : m.texto);
        }
        var datos = AVISOS[m.tipo] || AVISOS.estado;
        return datos.titulo + '. ' + m.texto;
    }

    function terminar(id, pendiente, resultado) {
        // Otra petición tomó su lugar (Detener sin AbortController, o la
        // conversación se borró): esta respuesta ya no le pertenece a nadie.
        if (estado.pendientes[id] !== pendiente) return;
        delete estado.pendientes[id];

        var c = buscar(id);
        if (!c) {
            pintarHistorial();
            return;
        }

        var m = {
            id: nuevoId('m'),
            rol: 'asistente',
            tipo: resultado.tipo,
            texto: resultado.texto,
            fecha: Date.now()
        };
        c.mensajes.push(m);
        recortar(c);
        c.actualizada = m.fecha;
        var esActiva = id === estado.activaId;
        if (!esActiva) c.noLeida = true;
        ordenar();
        guardar();

        if (esActiva) {
            quitarNodo('[data-ia-pensando]');
            var nodo = nodoMensaje(m, true);
            // Sin red la respuesta sale en el mismo fotograma que la pregunta;
            // el retraso de entrada lo pone el CSS para que no se pisen.
            if (Date.now() - pendiente.inicio < 150) nodo.classList.add('is-nuevo-tras');
            el.mensajes.appendChild(nodo);
            actualizarCompositor();
            if (estado.pegadoAlFinal) revelarRespuesta(nodo);
            else actualizarIrFinal();
            anunciar(textoAnunciable(nodo, m));
            rescatarFoco();
        }
        pintarHistorial();
    }

    function pedirRespuesta(id) {
        var c = buscar(id);
        if (!c || estado.pendientes[id]) return;
        var ultima = c.mensajes[c.mensajes.length - 1];
        if (!ultima || ultima.rol !== 'usuario') return;

        var controlador = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var pendiente = { controlador: controlador, inicio: Date.now() };
        estado.pendientes[id] = pendiente;

        if (id === estado.activaId) {
            quitarNodo('[data-ia-sin-respuesta]');
            // «Pensando» sólo cuando de verdad hay alguien pensando.
            if (ENDPOINT) {
                el.mensajes.appendChild(nodoPensando(true));
                anunciar('El asistente está pensando.');
            }
            actualizarCompositor();
        }
        if (ENDPOINT) {
            pintarHistorial();
            arrancarReloj();
        }

        enviarMensaje(c, ultima.texto, controlador ? controlador.signal : undefined).then(
            function (resultado) { terminar(id, pendiente, resultado); },
            function (fallo) { terminar(id, pendiente, resultadoDeFallo(fallo)); }
        );
    }

    function enviar(textoCrudo) {
        var texto = String(textoCrudo || '').replace(/^\s+|\s+$/g, '');
        if (!texto) return;
        if (texto.length > MAX_CARACTERES) texto = texto.slice(0, MAX_CARACTERES);

        var c = activa();
        if (c && estado.pendientes[c.id]) return;

        var ahora = Date.now();
        if (!c) {
            c = {
                id: nuevoId('c'),
                titulo: derivarTitulo(texto),
                creada: ahora,
                actualizada: ahora,
                noLeida: false,
                mensajes: []
            };
            estado.conversaciones.unshift(c);
            estado.activaId = c.id;
            almacen.escribir(CLAVE_ACTIVA, c.id);
            delete estado.borradores[''];
        } else {
            delete estado.borradores[c.id];
        }

        var m = { id: nuevoId('m'), rol: 'usuario', tipo: 'pregunta', texto: texto, fecha: ahora };
        c.mensajes.push(m);
        recortar(c);
        c.actualizada = ahora;
        ordenar();
        guardar();

        el.campo.value = '';
        ajustarAltura();

        mostrarHilo(true);
        quitarNodo('[data-ia-sin-respuesta]');
        el.mensajes.appendChild(nodoMensaje(m, true));
        pintarTitulo();
        pintarHistorial();
        pedirRespuesta(c.id);
        actualizarCompositor();
        irAlFinal(true);
    }

    function enviarDesdeCampo() {
        var c = activa();
        if (c && estado.pendientes[c.id]) return;
        if (!el.campo.value.trim()) return;
        enviar(el.campo.value);
        rescatarFoco();
    }

    function detener() {
        var c = activa();
        var pendiente = c && estado.pendientes[c.id];
        if (!pendiente) return;
        if (pendiente.controlador) {
            pendiente.controlador.abort();
        } else {
            // Sin AbortController la petición sigue viva, pero su respuesta ya
            // no encontrará a quién pertenecer (ver terminar()).
            terminar(c.id, pendiente, { tipo: 'detenida', texto: TEXTOS.detenida });
        }
    }

    function reintentar(mensajeId) {
        var c = activa();
        if (!c || estado.pendientes[c.id]) return;
        var ultima = c.mensajes[c.mensajes.length - 1];
        if (mensajeId && ultima && ultima.id === mensajeId && ultima.rol === 'asistente') {
            c.mensajes.pop();
            quitarNodo('[data-ia-msg="' + mensajeId + '"]');
            guardar();
        }
        ultima = c.mensajes[c.mensajes.length - 1];
        if (!ultima || ultima.rol !== 'usuario') return;
        estado.pegadoAlFinal = true;
        pedirRespuesta(c.id);
        irAlFinal(true);
        rescatarFoco();
    }

    function copiarAlPortapapeles(texto) {
        if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(texto);
        }
        // Respaldo para http sin TLS (el panel en la red local del
        // restaurante), donde la API moderna no existe.
        return new Promise(function (resolve, reject) {
            var previo = document.activeElement;
            var area = document.createElement('textarea');
            area.value = texto;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.top = '0';
            area.style.left = '0';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var hecho = false;
            try { hecho = document.execCommand('copy'); } catch (error) { hecho = false; }
            document.body.removeChild(area);
            if (previo && typeof previo.focus === 'function') previo.focus();
            if (hecho) resolve(); else reject(new Error('copiar'));
        });
    }

    function copiar(boton, mensajeId) {
        var c = activa();
        if (!c) return;
        var m = null;
        for (var i = 0; i < c.mensajes.length; i++) {
            if (c.mensajes[i].id === mensajeId) { m = c.mensajes[i]; break; }
        }
        if (!m) return;
        var aviso = window.AppNotice && typeof window.AppNotice.show === 'function' ? window.AppNotice : null;

        copiarAlPortapapeles(m.texto).then(function () {
            var etiqueta = boton.querySelector('.ia-accion__texto');
            var caja = boton.querySelector('.ia-accion__icono');
            var iconoPrevio = caja ? caja.innerHTML : '';
            boton.classList.add('is-hecho');
            if (etiqueta) etiqueta.textContent = 'Copiado';
            if (caja) caja.innerHTML = icono('check', 15);
            window.setTimeout(function () {
                boton.classList.remove('is-hecho');
                if (etiqueta) etiqueta.textContent = 'Copiar';
                if (caja) caja.innerHTML = iconoPrevio;
            }, 1600);
            if (aviso) {
                aviso.show({
                    text: m.rol === 'usuario' ? 'Pregunta copiada al portapapeles.' : 'Respuesta copiada al portapapeles.',
                    variant: 'success',
                    timeout: 3000
                });
            }
        }, function () {
            if (aviso) {
                aviso.show({ text: 'El navegador no dejó copiar. Selecciona el texto y cópialo a mano.', variant: 'error' });
            }
        });
    }

    /* ── Navegar entre conversaciones ───────────────────────────── */

    function abrirConversacion(id) {
        var c = buscar(id);
        if (!c) return;
        if (id === estado.activaId) {
            if (enCajon()) cerrarCajon(false);
            enfocarTrasCambio();
            return;
        }
        guardarBorrador();
        estado.activaId = id;
        almacen.escribir(CLAVE_ACTIVA, id);
        if (c.noLeida) {
            c.noLeida = false;
            guardar();
        }
        restaurarBorrador();
        pintarHistorial();
        pintarHilo();
        if (enCajon()) cerrarCajon(false);
        enfocarTrasCambio();
    }

    function nuevaConversacion() {
        if (estado.activaId) {
            guardarBorrador();
            estado.activaId = null;
            almacen.borrar(CLAVE_ACTIVA);
            restaurarBorrador();
            pintarHistorial();
            pintarHilo();
        }
        if (enCajon()) cerrarCajon(false);
        // La pidió explícitamente: aquí sí se abre el teclado en táctil.
        enfocarCampo(true);
    }

    function borrarConversacion(id) {
        var botones = el.lista.querySelectorAll('.ia-conv__abrir');
        var posicion = 0;
        for (var i = 0; i < botones.length; i++) {
            if (botones[i].getAttribute('data-ia-abrir') === id) { posicion = i; break; }
        }

        var pendiente = estado.pendientes[id];
        if (pendiente) {
            delete estado.pendientes[id];
            if (pendiente.controlador) pendiente.controlador.abort();
        }
        estado.borradas[id] = true;
        estado.conversaciones = estado.conversaciones.filter(function (c) { return c.id !== id; });
        delete estado.borradores[id];
        guardar();

        if (estado.activaId === id) {
            estado.activaId = null;
            almacen.borrar(CLAVE_ACTIVA);
            restaurarBorrador();
            pintarHilo();
        }
        pintarHistorial();
        anunciar('Conversación borrada.');

        // El foco a la conversación que ocupó su lugar o, si era la última,
        // a «Nueva conversación».
        var restantes = el.lista.querySelectorAll('.ia-conv__abrir');
        var destino = restantes[Math.min(posicion, restantes.length - 1)] || el.nuevaHistorial;
        if (destino && historialVisible()) destino.focus();
        else rescatarFoco();
    }

    function pedirBorrado(id) {
        var c = buscar(id);
        if (!c) return;
        // Sin el componente no se borra: nunca un borrado sin confirmación, y
        // nunca un confirm() nativo (ver CLAUDE.md).
        if (!window.ConfirmationModal || typeof window.ConfirmationModal.get !== 'function') return;
        var n = c.mensajes.length;
        window.ConfirmationModal.get().open({
            variant: 'danger',
            eyebrow: 'Asistente IA',
            title: '¿Borrar esta conversación?',
            description: '«' + c.titulo + '» y ' + (n === 1 ? 'su mensaje' : 'sus ' + n + ' mensajes') + ' se borrarán de este navegador.',
            consequence: 'No se puede deshacer.',
            secondaryLabel: 'Cancelar',
            primaryLabel: 'Borrar conversación'
        }).then(function (resultado) {
            if (resultado && resultado.action === 'primary') borrarConversacion(id);
        });
    }

    /* ── Historial: columna en escritorio, cajón en tablet y móvil ── */

    function enCajon() {
        return mqCajon.matches;
    }

    function historialVisible() {
        return enCajon()
            ? raiz.classList.contains('is-historial-abierto')
            : !raiz.classList.contains('is-historial-oculto');
    }

    function sincronizarToggle() {
        if (!el.toggle) return;
        var visible = historialVisible();
        // Etiqueta fija y el estado sólo en aria-expanded: cambiar las dos a
        // la vez se leía «Ocultar el historial, expandido», dos veces lo mismo.
        el.toggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
    }

    /**
     * Con el cajón abierto, la columna del chat queda detrás del velo. La
     * trampa de Tab sólo cubre el teclado; el cursor virtual de un lector de
     * pantalla seguiría leyendo el hilo de detrás como si estuviera a la vista.
     * `inert` lo saca de los dos. La región viva vive fuera de esta columna
     * (ver la vista) para que los anuncios sigan llegando mientras tanto.
     */
    function aislarChat(aislado) {
        if (el.chat) el.chat.inert = !!aislado;
    }

    function abrirCajon() {
        raiz.classList.add('is-historial-abierto');
        aislarChat(true);
        sincronizarToggle();
        window.requestAnimationFrame(function () {
            var destino = el.lista.querySelector('.is-activa .ia-conv__abrir') || el.nuevaHistorial;
            if (destino) destino.focus();
        });
    }

    function cerrarCajon(devolverFoco) {
        if (!raiz.classList.contains('is-historial-abierto')) return;
        raiz.classList.remove('is-historial-abierto');
        // Antes de devolver el foco: un nodo inerte no lo acepta.
        aislarChat(false);
        sincronizarToggle();
        if (devolverFoco && el.toggle) el.toggle.focus();
    }

    function alternarHistorial() {
        if (enCajon()) {
            if (raiz.classList.contains('is-historial-abierto')) cerrarCajon(true);
            else abrirCajon();
            return;
        }
        var oculto = raiz.classList.toggle('is-historial-oculto');
        almacen.escribir(CLAVE_HISTORIAL, oculto ? '1' : '0');
        sincronizarToggle();
    }

    function enfocables(contenedor) {
        var candidatos = contenedor.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
        var lista = [];
        for (var i = 0; i < candidatos.length; i++) {
            if (candidatos[i].getClientRects().length) lista.push(candidatos[i]);
        }
        return lista;
    }

    if (el.historial) {
        // Trampa de foco mientras el cajón tapa la pantalla: tabular fuera de
        // él llevaría a controles que están detrás del velo.
        el.historial.addEventListener('keydown', function (evento) {
            if (!enCajon() || !raiz.classList.contains('is-historial-abierto')) return;
            if (evento.key === 'Escape') {
                evento.preventDefault();
                cerrarCajon(true);
                return;
            }
            if (evento.key !== 'Tab') return;
            var lista = enfocables(el.historial);
            if (!lista.length) return;
            var primero = lista[0];
            var ultimo = lista[lista.length - 1];
            if (evento.shiftKey && document.activeElement === primero) {
                evento.preventDefault();
                ultimo.focus();
            } else if (!evento.shiftKey && document.activeElement === ultimo) {
                evento.preventDefault();
                primero.focus();
            }
        });
    }

    function alCambiarCajon() {
        if (!enCajon()) {
            raiz.classList.remove('is-historial-abierto');
            aislarChat(false);
        }
        sincronizarToggle();
    }

    if (typeof mqCajon.addEventListener === 'function') mqCajon.addEventListener('change', alCambiarCajon);
    else if (typeof mqCajon.addListener === 'function') mqCajon.addListener(alCambiarCajon);

    // La pista habla de Enter y Shift+Enter, que en táctil no son ciertos: ahí
    // Enter es salto de línea y la pista ni se pinta. Pero un aria-describedby
    // que apunta a un nodo oculto se sigue leyendo, así que se retira.
    function sincronizarPista() {
        if (mqTactil.matches) el.campo.removeAttribute('aria-describedby');
        else el.campo.setAttribute('aria-describedby', 'ia-pista');
    }

    if (typeof mqTactil.addEventListener === 'function') mqTactil.addEventListener('change', sincronizarPista);
    else if (typeof mqTactil.addListener === 'function') mqTactil.addListener(sincronizarPista);

    /* ── Alto útil ──────────────────────────────────────────────── *
     * La pantalla ocupa el alto de la ventana menos la barra del panel, y esa
     * barra no tiene un alto fijo: cambia entre breakpoints y si un nombre
     * largo la parte en dos filas. Se mide en vez de suponerlo. */

    var topbar = document.querySelector('.admin-topbar');

    function medirCabecera() {
        if (!topbar) return;
        var alto = topbar.getBoundingClientRect().height;
        if (alto > 0) raiz.style.setProperty('--ia-cabecera-panel', Math.round(alto) + 'px');
    }

    if (topbar && typeof window.ResizeObserver === 'function') {
        new window.ResizeObserver(medirCabecera).observe(topbar);
    }

    /* ── Otra pestaña ───────────────────────────────────────────── */

    window.addEventListener('storage', function (evento) {
        if (evento.key !== CLAVE_CONVERSACIONES || estado.memoriaSola) return;
        var porId = {};
        leerConversaciones().forEach(function (c) {
            if (!estado.borradas[c.id]) porId[c.id] = c;
        });
        // Las que esperan respuesta aquí conservan su versión local.
        Object.keys(estado.pendientes).forEach(function (id) {
            var local = buscar(id);
            if (local) porId[id] = local;
        });

        var anterior = activa();
        var firmaAnterior = anterior ? anterior.mensajes.length + ':' + anterior.actualizada : '';

        estado.conversaciones = Object.keys(porId).map(function (id) { return porId[id]; });
        ordenar();

        var ahora = activa();
        if (estado.activaId && !ahora) {
            // La borraron en la otra pestaña.
            estado.activaId = null;
            restaurarBorrador();
            pintarHilo();
        } else if (ahora && !estado.pendientes[ahora.id]) {
            var firma = ahora.mensajes.length + ':' + ahora.actualizada;
            // Sólo se repinta si cambió: repintar en cada escritura ajena
            // movería el scroll de quien está leyendo.
            if (firma !== firmaAnterior) pintarHilo();
        }
        pintarHistorial();
    });

    /* ── Eventos ────────────────────────────────────────────────── */

    el.form.addEventListener('submit', function (evento) {
        evento.preventDefault();
        enviarDesdeCampo();
    });

    el.campo.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Enter' || evento.shiftKey || evento.altKey) return;
        // Composición de un IME (acentos con tecla muerta en algunos teclados,
        // o un teclado asiático): ese Enter confirma el carácter, no envía.
        if (evento.isComposing || evento.keyCode === 229) return;
        // En táctil Enter es salto de línea: el teclado de pantalla no tiene
        // Shift+Enter, y el envío está en el botón.
        if (mqTactil.matches) return;
        evento.preventDefault();
        enviarDesdeCampo();
    });

    el.campo.addEventListener('input', function () {
        ajustarAltura();
        actualizarCompositor();
        estado.borradores[estado.activaId || ''] = el.campo.value;
    });

    if (el.detener) {
        el.detener.addEventListener('click', function () {
            detener();
            rescatarFoco();
        });
    }

    var nuevas = raiz.querySelectorAll('[data-ia-nueva]');
    for (var n = 0; n < nuevas.length; n++) {
        nuevas[n].addEventListener('click', nuevaConversacion);
    }

    if (el.toggle) el.toggle.addEventListener('click', alternarHistorial);
    if (el.cerrar) el.cerrar.addEventListener('click', function () { cerrarCajon(true); });
    if (el.telon) el.telon.addEventListener('click', function () { cerrarCajon(true); });

    el.lista.addEventListener('click', function (evento) {
        var borrar = evento.target.closest('[data-ia-borrar]');
        if (borrar) {
            pedirBorrado(borrar.getAttribute('data-ia-borrar'));
            return;
        }
        var abrir = evento.target.closest('[data-ia-abrir]');
        if (abrir) abrirConversacion(abrir.getAttribute('data-ia-abrir'));
    });

    // Supr sobre una conversación pide borrarla, como en cualquier lista.
    el.lista.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Delete') return;
        var abrir = evento.target.closest('[data-ia-abrir]');
        if (!abrir) return;
        evento.preventDefault();
        pedirBorrado(abrir.getAttribute('data-ia-abrir'));
    });

    el.mensajes.addEventListener('click', function (evento) {
        var copia = evento.target.closest('[data-ia-copiar]');
        if (copia) {
            copiar(copia, copia.getAttribute('data-ia-copiar'));
            return;
        }
        var repetir = evento.target.closest('[data-ia-reintentar]');
        if (repetir) reintentar(repetir.getAttribute('data-ia-reintentar'));
    });

    if (el.vacio) {
        el.vacio.addEventListener('click', function (evento) {
            var sugerencia = evento.target.closest('[data-ia-sugerencia]');
            if (!sugerencia) return;
            enviar(sugerencia.getAttribute('data-ia-sugerencia'));
            rescatarFoco();
        });
    }

    function esEditable(nodo) {
        if (!nodo || nodo === document.body) return false;
        var etiqueta = nodo.tagName;
        return etiqueta === 'INPUT' || etiqueta === 'TEXTAREA' || etiqueta === 'SELECT' || nodo.isContentEditable;
    }

    function hayCapaAbierta() {
        return !!document.querySelector(
            '.confirmation-modal:not([hidden]), .admin-modal:not([hidden]), #admin-inbox-drawer:not([hidden])'
        ) || document.body.classList.contains('is-sidebar-open');
    }

    // «/» (fuera de un campo) y Ctrl/⌘+K llevan al compositor desde cualquier
    // punto de la pantalla, como en los buscadores y los chats que el admin ya
    // usa. Con un diálogo abierto no se roba el foco.
    document.addEventListener('keydown', function (evento) {
        if (evento.defaultPrevented) return;
        var tecla = evento.key;
        if ((evento.ctrlKey || evento.metaKey) && !evento.altKey && !evento.shiftKey && (tecla === 'k' || tecla === 'K')) {
            if (hayCapaAbierta()) return;
            evento.preventDefault();
            // Con el cajón abierto el campo está inerte y no tomaría el foco:
            // el atajo pide escribir, así que el cajón se aparta.
            cerrarCajon(false);
            enfocarCampo(true);
            return;
        }
        if (tecla === '/' && !evento.ctrlKey && !evento.metaKey && !evento.altKey) {
            if (esEditable(document.activeElement) || hayCapaAbierta()) return;
            evento.preventDefault();
            cerrarCajon(false);
            enfocarCampo(true);
            return;
        }
        if (tecla === 'Escape' && enCajon() && raiz.classList.contains('is-historial-abierto')) {
            cerrarCajon(true);
        }
    });

    /* ── Arranque ───────────────────────────────────────────────── */

    function iniciar() {
        estado.conversaciones = estado.memoriaSola ? [] : leerConversaciones();
        ordenar();

        // Se retoma la última conversación sólo si es de hoy: volver mañana y
        // caer en medio de la charla de ayer es peor que un saludo limpio.
        var guardada = almacen.leer(CLAVE_ACTIVA);
        var previa = guardada ? buscar(guardada) : null;
        estado.activaId = previa && grupoDe(previa.actualizada) === 'Hoy' ? previa.id : null;
        if (previa && previa.id === estado.activaId && previa.noLeida) {
            previa.noLeida = false;
            guardar();
        }

        if (estado.memoriaSola && el.almacen) {
            el.almacen.textContent = 'Este navegador no deja guardar: el historial se perderá al recargar.';
            el.almacen.classList.add('is-sin-almacen');
        }

        // El título recibe el foco al cambiar de conversación en táctil; no es
        // un control, así que queda fuera del recorrido con Tab.
        if (el.titulo) el.titulo.setAttribute('tabindex', '-1');

        medirCabecera();
        alCambiarCajon();
        sincronizarPista();
        pintarHistorial();
        pintarHilo();
        ajustarAltura();
        enfocarCampo(false);
    }

    iniciar();
}(window, document));
