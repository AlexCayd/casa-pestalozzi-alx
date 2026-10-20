/**
 * Asistente IA del panel (views/admin/ia/index.php).
 *
 * Una conversación con los datos del restaurante. Quien responde NO es un
 * modelo de lenguaje: es el motor de consultas rápidas.
 *
 *   GET /admin/api/consultas?intencion=&id=&opcion=&periodo=&ambito=todo
 *   ← { ok, entendida, interpretacion, plantilla, respuesta, periodo, … }
 *
 * (Services\Analytics\ConsultasRapidas). La conversación es la forma: cada
 * respuesta sale de una consulta a la base, y la pantalla no finge más de lo
 * que hay —el estado de espera dice «Consultando los datos», no «Pensando»—.
 *
 * NO HAY TEXTO LIBRE. El cliente lo retiró: dejaba preguntar cosas que el
 * motor no sabe contestar. El compositor es una pregunta ARMADA —una de las
 * preguntas base del catálogo con sus variables como huecos («Ventas de
 * [Cappuccino ▾] · [Ayer ▾]»)— y siempre hay una: nace con la primera del
 * catálogo y, tras enviar, se queda con la que se acaba de contestar, lista
 * para cambiarle un valor y volver a preguntar.
 *
 * Todo lo que habla con el motor pasa por `consultar()`. La respuesta se pinta
 * con la tarjeta de las consultas rápidas (`window.ConsultasRapidas.montar`,
 * de core/consultas-rapidas.js, que llega en admin.js): la pregunta dicha de
 * vuelta con sus variables en HUECOS («Ventas de [Cappuccino ▾] · [Ayer ▾]»),
 * que se cambian sin reescribir. Si esa API no está, `nodoFicha()` pinta una
 * ficha propia sin huecos: el asistente responde igual.
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

    // El tope lo fija el controlador y lo dice el pie del historial: al abrir
    // una conversación más se borra la más antigua.
    var MAX_CONVERSACIONES = parseInt(raiz.getAttribute('data-ia-max-conversaciones'), 10) || 10;
    var MAX_MENSAJES = 200;
    // Tope de lo que se guarda como texto de una pregunta (el del motor).
    var MAX_TEXTO = 160;
    // Una consulta a la base contesta en milisegundos; veinte segundos ya no
    // es una espera, es que algo se colgó.
    var TIEMPO_LIMITE = 20000;
    // La respuesta llega casi siempre antes de que se lea «Consultando…». Sin
    // un mínimo, el estado de espera parpadeaba un fotograma y la respuesta
    // parecía saltar sobre la pregunta. No es latencia fingida: es el tiempo
    // que tarda en leerse que hubo un paso.
    var PAUSA_MINIMA = 420;
    // «Detener» sólo cuando de verdad hay algo que detener: en una espera de
    // medio segundo, cambiar el botón de enviar por el de detener era un
    // destello en la esquina más visible del compositor.
    var UMBRAL_LENTO = 1200;
    var UMBRAL_FINAL = 48;
    var TITULO_MAX = 52;

    var API = (raiz.getAttribute('data-ia-api') || '').trim() || '/admin/api/consultas';

    var mqCajon = window.matchMedia('(max-width: 992px)');
    var mqTactil = window.matchMedia('(hover: none) and (pointer: coarse)');
    var mqReducido = window.matchMedia('(prefers-reduced-motion: reduce)');

    var TEXTOS = {
        consultando: 'Consultando los datos',
        detenida: 'Detuviste la consulta. La pregunta sigue aquí por si quieres repetirla.',
        sinRespuesta: 'La página se cerró o se recargó antes de que llegara la respuesta. Puedes pedirla otra vez.',
        noEntendi: 'No supe qué consultar con esa pregunta.',
        catalogo: 'Esto es lo que sé responder',
        error: {
            red: 'No se pudo contactar con el servidor. Revisa la conexión e inténtalo de nuevo.',
            tiempo: 'La consulta tardó más de 20 segundos y se dejó de esperar. Inténtalo de nuevo.',
            http: 'El servidor respondió con un error{codigo}. Inténtalo de nuevo en unos minutos.',
            formato: 'La respuesta llegó en un formato que esta pantalla no sabe leer.',
            sesion: 'Tu sesión terminó. Vuelve a entrar al panel para seguir preguntando.'
        }
    };

    var AVISOS = {
        error: { titulo: 'No hubo respuesta', icono: 'error', clase: 'ia-aviso--error' },
        detenida: { titulo: 'Consulta detenida', icono: 'info', clase: 'ia-aviso--nota' },
        sinRespuesta: { titulo: 'Sin respuesta', icono: 'info', clase: 'ia-aviso--nota' }
    };

    // Rótulo e icono de la ficha por tipo de respuesta del motor. Los iconos
    // son del catálogo JS (core/icons.js): tickets para ventas, inventario para
    // almacén, la flecha de subida para el ranking.
    // Es también la lista de lo que se da por ENTENDIDO (ver entendida()): un
    // tipo que el motor aprenda y que no esté aquí se pinta como no entendido.
    var RUBROS = {
        ventas_producto: { nombre: 'Ventas', icono: 'tickets' },
        ventas_categoria: { nombre: 'Ventas por categoría', icono: 'tickets' },
        ventas_total: { nombre: 'Ventas', icono: 'tickets' },
        top: { nombre: 'Lo más vendido', icono: 'sube' },
        ticket_promedio: { nombre: 'Ticket promedio', icono: 'caja' },
        sin_venta: { nombre: 'Platillos sin venta', icono: 'nota' },
        meseros: { nombre: 'Ventas por mesero', icono: 'grupo' },
        horas_pico: { nombre: 'Horas pico', icono: 'reloj' },
        cancelaciones: { nombre: 'Cancelaciones', icono: 'error' },
        stock: { nombre: 'Almacén', icono: 'inventario' },
        consumo: { nombre: 'Consumo', icono: 'gota' },
        bajo_stock: { nombre: 'Almacén', icono: 'inventario' }
    };

    // Respuestas que dependen del periodo: llevan el periodo en el rótulo y
    // ofrecen preguntarlas en otro.
    var CON_PERIODO = {
        ventas_producto: true, ventas_categoria: true, ventas_total: true, top: true, ticket_promedio: true,
        sin_venta: true, meseros: true, horas_pico: true, cancelaciones: true, consumo: true
    };

    // Cómo se pregunta lo mismo en otro periodo, dicho como se diría en una
    // conversación. La clave es la del motor (ConsultasRapidas::PERIODOS).
    // Una clave nueva del motor sin entrada aquí sale como «¿Y <etiqueta>?».
    var PREGUNTA_PERIODO = {
        hoy: '¿Y hoy?',
        ayer: '¿Y ayer?',
        antier: '¿Y antier?',
        semana: '¿Y esta semana?',
        semana_pasada: '¿Y la semana pasada?',
        '7d': '¿Y en los últimos 7 días?',
        mes: '¿Y este mes?',
        mes_pasado: '¿Y el mes pasado?',
        '30d': '¿Y en los últimos 30 días?'
    };

    // Los campos de una consulta estructurada (intención + entidad + opción +
    // periodo): es lo que mandan los huecos y los ejemplos del catálogo, que
    // no deben depender de que el intérprete vuelva a adivinar una frase.
    var CAMPOS = ['intencion', 'id', 'opcion', 'periodo'];

    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    var DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    /* ── Nodos ──────────────────────────────────────────────────── */

    var el = {
        historial: raiz.querySelector('[data-ia-historial]'),
        lista: raiz.querySelector('[data-ia-lista]'),
        listaVacia: raiz.querySelector('[data-ia-lista-vacia]'),
        almacen: raiz.querySelector('[data-ia-almacen]'),
        toggle: raiz.querySelector('[data-ia-historial-toggle]'),
        cerrar: raiz.querySelector('[data-ia-historial-cerrar]'),
        telon: raiz.querySelector('[data-ia-telon]'),
        chat: raiz.querySelector('[data-ia-chat]'),
        titulo: raiz.querySelector('[data-ia-titulo]'),
        escenario: raiz.querySelector('[data-ia-escenario]'),
        hilo: raiz.querySelector('[data-ia-hilo]'),
        interior: raiz.querySelector('.ia-hilo__interior'),
        mensajes: raiz.querySelector('[data-ia-mensajes]'),
        irFinal: raiz.querySelector('[data-ia-ir-final]'),
        catalogo: raiz.querySelector('[data-ia-catalogo]'),
        form: raiz.querySelector('[data-ia-compositor]'),
        enviar: raiz.querySelector('[data-ia-enviar]'),
        detener: raiz.querySelector('[data-ia-detener]'),
        anuncio: raiz.querySelector('[data-ia-anuncio]'),
        nuevaHistorial: raiz.querySelector('.ia-historial [data-ia-nueva]'),
        caja: raiz.querySelector('[data-ia-caja]'),
        armadoFrase: raiz.querySelector('[data-ia-constructor-frase]'),
        ayuda: raiz.querySelector('[data-ia-constructor-ayuda]'),
        bases: raiz.querySelector('[data-ia-bases]'),
        basesTexto: raiz.querySelector('[data-ia-bases-texto]'),
        basesIcono: raiz.querySelector('[data-ia-bases-icono]'),
        periodos: raiz.querySelector('[data-ia-periodos]')
    };

    if (!el.chat || !el.hilo || !el.mensajes || !el.form || !el.lista || !el.armadoFrase || !el.enviar) return;

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
        // id → { controlador, inicio, lento, relojLento }. Una por
        // conversación: se puede seguir preguntando en otra mientras ésta
        // espera.
        pendientes: {},
        // Ids borrados en esta pestaña, para que la fusión con lo que escribió
        // otra pestaña no los resucite.
        borradas: {},
        // La pregunta armada en el compositor: { consulta, partes }. Nunca
        // falta mientras haya catálogo.
        armada: null,
        // La armada de cada conversación ('' es la nueva), para que volver a
        // una la deje como estaba. Sólo en memoria: una pregunta a medio
        // armar no merece sobrevivir a una recarga (al reabrir, se arma con la
        // última que se contestó).
        armadas: {},
        // Si se cambió algo de la armada desde el último envío. Mientras no,
        // la respuesta que llega la reemplaza por su versión (que puede traer
        // un hueco más, como la opción de un platillo); si sí, se respeta.
        armadaTocada: false,
        pegadoAlFinal: true,
        programado: null,
        memoriaSola: !almacen.disponible(),
        avisoCuota: false
    };

    /* ── Utilidades ─────────────────────────────────────────────── */

    function crear(etiqueta, clase, texto) {
        var nodo = document.createElement(etiqueta);
        if (clase) nodo.className = clase;
        if (texto !== undefined && texto !== null) nodo.textContent = String(texto);
        return nodo;
    }

    function icono(nombre, tamano) {
        var catalogo = window.AdminIcons;
        if (!catalogo || typeof catalogo.get !== 'function') return '';
        return catalogo.get(nombre, tamano);
    }

    // El icono en su propia caja y fuera del árbol de accesibilidad: el texto
    // de al lado ya dice lo que significa.
    function nodoIcono(nombre, tamano, clase) {
        var caja = crear('span', clase || 'ia-ico');
        caja.setAttribute('aria-hidden', 'true');
        caja.innerHTML = icono(nombre, tamano);
        return caja;
    }

    function textoOculto(texto) {
        return crear('span', 'admin-visually-hidden', texto);
    }

    function numero(valor) {
        var n = Number(valor);
        return isFinite(n) && n > 0 ? n : 0;
    }

    function esTexto(valor) {
        return typeof valor === 'string' && valor.trim() !== '';
    }

    // Lo que llega del motor acaba en nombres de clase: sólo letras, cifras y
    // guiones bajos.
    function claseSegura(valor) {
        return String(valor || '').replace(/[^a-z0-9_-]/gi, '').toLowerCase();
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

    function sinAcentos(texto) {
        return String(texto || '').toLowerCase()
            .replace(/[áàäâ]/g, 'a').replace(/[éèëê]/g, 'e').replace(/[íìïî]/g, 'i')
            .replace(/[óòöô]/g, 'o').replace(/[úùüû]/g, 'u').replace(/ñ/g, 'n');
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
     * Fecha de una conversación en la lista. El grupo («Hoy», «Ayer») ya dice
     * el día, así que dentro de él basta la hora; más atrás, el día de la
     * semana o la fecha.
     */
    function fechaEnLista(ms) {
        var fecha = new Date(ms);
        var hora = dos(fecha.getHours()) + ':' + dos(fecha.getMinutes());
        var grupo = grupoDe(ms);
        if (grupo === 'Hoy' || grupo === 'Ayer') return hora;
        if (grupo === 'Últimos 7 días') return DIAS[fecha.getDay()] + ' ' + hora;
        var anio = fecha.getFullYear() !== new Date().getFullYear() ? ' ' + fecha.getFullYear() : '';
        return fecha.getDate() + ' ' + MESES[fecha.getMonth()] + anio;
    }

    /**
     * Título de una conversación: su primera pregunta, recortada por palabra.
     * El admin reconoce su propia pregunta mejor que cualquier resumen.
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

    /* ── Datos de una respuesta ─────────────────────────────────── *
     *
     * Lo que devuelve el motor se guarda tal cual en el mensaje (es poco y es
     * nuestro), para poder volver a pintar la ficha al reabrir la
     * conversación. Se valida la forma, no el contenido: todo se pinta con
     * textContent, nunca con innerHTML, y el único atributo que se toma de
     * ahí —el href del enlace— se limita a rutas del panel.
     */

    function esObjeto(valor) {
        return !!valor && typeof valor === 'object' && !Array.isArray(valor);
    }

    function limpiarDatos(datos) {
        if (!esObjeto(datos)) return null;
        var r = datos.respuesta;
        var periodo = esObjeto(datos.periodo) ? datos.periodo : null;
        return {
            consulta: typeof datos.consulta === 'string' ? datos.consulta.slice(0, MAX_TEXTO) : '',
            periodo: periodo && esTexto(periodo.clave)
                ? { clave: String(periodo.clave), etiqueta: String(periodo.etiqueta || ''), frase: String(periodo.frase || '') }
                : null,
            respuesta: esObjeto(r) && esTexto(r.tipo) ? r : null,
            alternativas: Array.isArray(datos.alternativas) ? datos.alternativas.slice(0, 6) : [],
            periodos: esObjeto(datos.periodos) ? datos.periodos : null,
            // Lo que la tarjeta con huecos necesita para volver a pintarse al
            // reabrir la conversación: qué se entendió y la frase con sus
            // variables.
            entendida: datos.entendida === false ? false : (datos.entendida === true ? true : null),
            interpretacion: esObjeto(datos.interpretacion) ? datos.interpretacion : null,
            plantilla: Array.isArray(datos.plantilla) ? datos.plantilla : null,
            sugerencias: Array.isArray(datos.sugerencias) ? datos.sugerencias.slice(0, 8) : []
        };
    }

    /** Una consulta estructurada válida, o null. */
    function consultaEstructurada(valor) {
        if (!esObjeto(valor) || typeof valor.intencion !== 'string' || !/^[a-z_]{1,32}$/.test(valor.intencion)) return null;
        var entero = function (v) {
            var n = parseInt(v, 10);
            return isFinite(n) && n > 0 ? n : null;
        };
        return {
            intencion: valor.intencion,
            id: entero(valor.id),
            opcion: entero(valor.opcion),
            periodo: typeof valor.periodo === 'string' && /^[a-z0-9_]{1,16}$/.test(valor.periodo) ? valor.periodo : ''
        };
    }

    function leerJson(texto) {
        if (!esTexto(texto)) return null;
        try { return JSON.parse(texto); } catch (error) { return null; }
    }

    function tieneCifra(r) {
        return r.cifra !== undefined && r.cifra !== null && String(r.cifra) !== '';
    }

    function detalleDe(r) {
        return Array.isArray(r.detalle) ? r.detalle.filter(function (d) {
            return d && typeof d === 'object' && esTexto(String(d.etiqueta || ''));
        }) : [];
    }

    // Cómo se lee el valor de un renglón: `valor_texto` cuando el motor lo da
    // («$12,340.00 · 34 tickets»); `valor` es la magnitud de la barra.
    function valorMostrado(d) {
        return d.valor_texto !== undefined && d.valor_texto !== null ? String(d.valor_texto) : String(d.valor);
    }

    function enlaceValido(enlace) {
        return !!(enlace && typeof enlace.href === 'string' && /^\/admin(\/[A-Za-z0-9_\-\/?=&.]*)?$/.test(enlace.href) && esTexto(enlace.texto));
    }

    function etiquetaPeriodo(datos) {
        return datos.periodo && esTexto(datos.periodo.etiqueta) ? datos.periodo.etiqueta : '';
    }

    // El motor dice `entendida: false` cuando no supo qué consultar; las
    // respuestas guardadas antes de esa marca se juzgan por su tipo.
    function entendida(datos) {
        var r = datos && datos.respuesta;
        if (!r || datos.entendida === false) return false;
        return !!RUBROS[r.tipo];
    }

    /** Texto plano de una respuesta: lo que se copia y lo que se anuncia. */
    function resumenDe(datos) {
        var r = datos && datos.respuesta;
        if (!entendida(datos)) {
            if (r && r.tipo === 'fuera_de_alcance') return String(r.frase || TEXTOS.noEntendi);
            if (r && r.tipo === 'sin_resultado') return [r.titulo, r.frase].filter(esTexto).join('. ');
            return TEXTOS.noEntendi;
        }
        var lineas = [];
        var periodo = CON_PERIODO[r.tipo] ? etiquetaPeriodo(datos) : '';
        if (esTexto(r.titulo)) lineas.push(r.titulo + (periodo ? ' (' + periodo + ')' : ''));
        if (tieneCifra(r)) lineas.push(String(r.cifra) + (esTexto(r.unidad) ? ' ' + r.unidad : ''));
        if (esTexto(r.frase)) lineas.push(r.frase);
        if (r.comparacion && esTexto(r.comparacion.texto)) lineas.push(r.comparacion.texto);
        var detalle = detalleDe(r);
        if (detalle.length) {
            if (esTexto(r.detalle_titulo)) lineas.push(r.detalle_titulo + ':');
            detalle.forEach(function (d) { lineas.push('- ' + d.etiqueta + ': ' + valorMostrado(d)); });
        }
        return lineas.join('\n');
    }

    /* ── Persistencia ───────────────────────────────────────────── */

    var TIPOS_ASISTENTE = { consulta: true, error: true, detenida: true };

    /**
     * Lo que se manda al motor cuando no es el texto de la pregunta tal cual:
     * una frase con periodo forzado ({ q, periodo }) o una consulta
     * estructurada ({ intencion, id, opcion, periodo }).
     */
    function normalizarPeticion(p) {
        var estructurada = consultaEstructurada(p);
        if (estructurada) return estructurada;
        if (!esObjeto(p) || !esTexto(p.q)) return null;
        var periodo = typeof p.periodo === 'string' && /^[a-z0-9_]{1,16}$/.test(p.periodo) ? p.periodo : '';
        return { q: p.q.slice(0, MAX_TEXTO), periodo: periodo };
    }

    function normalizarMensaje(m) {
        if (!m || typeof m !== 'object' || typeof m.texto !== 'string') return null;
        var rol = m.rol === 'usuario' || m.rol === 'asistente' ? m.rol : null;
        if (!rol) return null;
        var base = {
            id: idValido(m.id) ? m.id : nuevoId('m'),
            rol: rol,
            texto: m.texto,
            fecha: numero(m.fecha) || Date.now()
        };
        if (rol === 'usuario') {
            base.tipo = 'pregunta';
            base.peticion = normalizarPeticion(m.peticion);
            // Pregunta hecha desde un renglón o una sugerencia, no tecleada:
            // su texto se reescribe con lo que el motor entendió.
            if (m.generada === true) base.generada = true;
            return base;
        }
        // Los avisos de la vista previa («conexión pendiente», tipo `estado`)
        // y las respuestas en markdown del flujo de n8n, que nunca llegó a
        // conectarse, se descartan. La pregunta queda sin respuesta y la
        // pantalla ofrece pedirla: ahora sí hay quien conteste.
        if (!TIPOS_ASISTENTE[m.tipo]) return null;
        base.tipo = m.tipo;
        if (m.tipo === 'consulta') {
            base.datos = limpiarDatos(m.datos);
            if (!base.datos) return null;
        }
        return base;
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

    /**
     * Deja sólo las MAX_CONVERSACIONES más recientes: al abrir una más se va la
     * más antigua (la última de la lista). Vale también para lo que quedó
     * guardado de antes del tope, que se recorta al cargar. Las que salen se
     * marcan como borradas para que la fusión con otra pestaña no las devuelva,
     * y si alguna esperaba respuesta, se deja de esperar.
     */
    function recortarConversaciones() {
        ordenar();
        if (estado.conversaciones.length <= MAX_CONVERSACIONES) return 0;
        var sobrantes = estado.conversaciones.slice(MAX_CONVERSACIONES);
        estado.conversaciones = estado.conversaciones.slice(0, MAX_CONVERSACIONES);
        sobrantes.forEach(function (c) {
            estado.borradas[c.id] = true;
            delete estado.armadas[c.id];
            var pendiente = estado.pendientes[c.id];
            if (pendiente) {
                delete estado.pendientes[c.id];
                window.clearTimeout(pendiente.relojLento);
                if (pendiente.controlador) pendiente.controlador.abort();
            }
        });
        return sobrantes.length;
    }

    function guardar() {
        if (estado.memoriaSola) return;
        incorporarAjenas();
        recortarConversaciones();

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

    /* ── El motor ───────────────────────────────────────────────── *
     *
     * ÚNICO punto de contacto con la base. Devuelve una promesa que resuelve
     * con { tipo: 'consulta', datos } y rechaza con { motivo }: 'detenida',
     * 'tiempo', 'red', 'sesion', 'http' (con `codigo`) o 'formato'. Respeta el
     * AbortController del botón Detener.
     *
     * Manda la consulta estructurada de la pregunta. Una pregunta guardada sin
     * ella es de antes de retirar el texto libre: se repite como frase.
     */

    function consultar(pregunta, signal) {
        var peticion = pregunta.peticion || { q: pregunta.texto, periodo: '' };

        var partes = ['ambito=todo'];
        if (peticion.intencion) {
            CAMPOS.forEach(function (campo) {
                var valor = peticion[campo];
                if (valor !== null && valor !== undefined && valor !== '') partes.push(campo + '=' + encodeURIComponent(String(valor)));
            });
        } else {
            partes.push('q=' + encodeURIComponent(peticion.q));
            if (peticion.periodo) partes.push('periodo=' + encodeURIComponent(peticion.periodo));
        }
        var url = API + '?' + partes.join('&');

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

            window.fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: interno ? interno.signal : undefined
            }).then(function (respuesta) {
                // Auth::proteger contesta 401/403 en JSON a las API; un
                // redirect al login es la sesión caída por otro camino.
                if (respuesta.status === 401 || respuesta.status === 403 ||
                    (respuesta.redirected && /\/login/.test(respuesta.url || ''))) {
                    throw { motivo: 'sesion' };
                }
                if (!respuesta.ok) throw { motivo: 'http', codigo: respuesta.status };
                return respuesta.json().then(null, function () { throw { motivo: 'formato' }; });
            }).then(function (json) {
                window.clearTimeout(reloj);
                if (!json || json.ok !== true) throw { motivo: 'formato' };
                resolve({ tipo: 'consulta', datos: limpiarDatos(json) });
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
    // anterior (dos respuestas iguales seguidas), sin el vaciado no se lee.
    function anunciar(texto) {
        if (!el.anuncio) return;
        window.clearTimeout(relojAnuncio);
        el.anuncio.textContent = '';
        relojAnuncio = window.setTimeout(function () {
            el.anuncio.textContent = texto;
        }, 80);
    }

    function textoAnunciable(m) {
        if (m.tipo === 'consulta') {
            var r = m.datos && m.datos.respuesta;
            if (!entendida(m.datos)) return resumenDe(m.datos) + ' ' + TEXTOS.catalogo + ' en la lista que sigue.';
            var partes = [];
            if (esTexto(r.titulo)) partes.push(r.titulo);
            if (tieneCifra(r)) partes.push(String(r.cifra) + (esTexto(r.unidad) ? ' ' + r.unidad : ''));
            if (esTexto(r.frase)) partes.push(r.frase);
            if (r.comparacion && esTexto(r.comparacion.texto)) partes.push(r.comparacion.texto);
            return unirFrases(partes);
        }
        var datos = AVISOS[m.tipo] || AVISOS.error;
        return datos.titulo + '. ' + m.texto;
    }

    /* ── Pintado: historial ─────────────────────────────────────── */

    function contarPreguntas(c) {
        var n = 0;
        c.mensajes.forEach(function (m) { if (m.rol === 'usuario') n++; });
        return n;
    }

    function itemConversacion(c) {
        var li = crear('li', 'ia-conv' + (c.id === estado.activaId ? ' is-activa' : ''));

        var abrir = crear('button', 'ia-conv__abrir');
        abrir.type = 'button';
        abrir.setAttribute('data-ia-abrir', c.id);
        abrir.title = c.titulo;
        if (c.id === estado.activaId) abrir.setAttribute('aria-current', 'true');

        var cuerpo = crear('span', 'ia-conv__cuerpo');
        cuerpo.appendChild(crear('span', 'ia-conv__texto', c.titulo));
        var n = contarPreguntas(c);
        var meta = crear('span', 'ia-conv__meta');
        var hora = crear('time', '', fechaEnLista(c.actualizada));
        try { hora.dateTime = new Date(c.actualizada).toISOString(); } catch (error) { /* fecha inválida */ }
        meta.appendChild(hora);
        meta.appendChild(document.createTextNode(' · ' + n + (n === 1 ? ' pregunta' : ' preguntas')));
        cuerpo.appendChild(meta);
        abrir.appendChild(cuerpo);

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
            var titulo = crear('p', 'ia-grupo__titulo', grupo.nombre);
            titulo.id = 'ia-grupo-' + indice;
            var lista = crear('ul', 'ia-grupo__lista');
            lista.setAttribute('aria-labelledby', titulo.id);
            grupo.items.forEach(function (c) { lista.appendChild(itemConversacion(c)); });
            bloque.appendChild(titulo);
            bloque.appendChild(lista);
            fragmento.appendChild(bloque);
        });
        el.lista.appendChild(fragmento);
    }

    /* ── Pintado: la respuesta ──────────────────────────────────── *
     *
     * Una respuesta se lee como la de un asistente: primero la frase —lo que
     * contestaría una persona—, después la ficha con la cifra y el desglose
     * —la prueba—, y al final por dónde seguir: el mismo dato en otro periodo,
     * las otras lecturas de la palabra y el módulo donde está todo.
     *
     * La entrada es progresiva sólo en las respuestas nuevas: la frase aparece
     * palabra a palabra, la ficha llega detrás y la cifra cuenta hasta su
     * valor. Los retrasos los pone el JS en custom properties y el
     * movimiento, el CSS; con movimiento reducido todo aparece de golpe.
     */

    var PASO_PALABRA = 24;
    var TOPE_FRASE = 520;

    function animar() {
        return !mqReducido.matches;
    }

    /** La frase, con cada palabra en su caja y las cifras en mono. */
    function nodoFrase(texto, nuevo) {
        var p = crear('p', 'ia-resp__frase');
        escribirPalabras(p, texto, nuevo);
        return p;
    }

    /**
     * Llena `p` con la frase palabra a palabra, cada una con su retraso de
     * entrada. Sirve también para la frase de la tarjeta del motor, que llega
     * ya pintada: se vuelve a escribir encima con el mismo texto.
     */
    function escribirPalabras(p, texto, nuevo) {
        while (p.firstChild) p.removeChild(p.firstChild);
        if (nuevo && animar()) p.classList.add('is-escribiendo');
        var trozos = String(texto || '').split(/(\s+)/);
        var indice = 0;
        var palabras = 0;
        trozos.forEach(function (t) { if (t && !/^\s+$/.test(t)) palabras++; });
        // En frases largas el paso se acorta para que ninguna tarde más que
        // el tope en escribirse.
        var paso = palabras > 0 ? Math.min(PASO_PALABRA, TOPE_FRASE / palabras) : PASO_PALABRA;
        trozos.forEach(function (trozo) {
            if (!trozo) return;
            if (/^\s+$/.test(trozo)) {
                p.appendChild(document.createTextNode(trozo));
                return;
            }
            var palabra = crear('span', 'ia-palabra' + (/\d/.test(trozo) ? ' ia-num' : ''), trozo);
            palabra.style.setProperty('--ia-retraso', Math.round(indice * paso) + 'ms');
            indice++;
            p.appendChild(palabra);
        });
        p.setAttribute('data-ia-duracion', String(Math.round(indice * paso)));
        return Math.round(indice * paso);
    }

    /**
     * La cifra cuenta desde cero hasta su valor, con el mismo formato que trae
     * («$1,234.50», «2.1», «46»). Tabular y en mono, así que los dígitos no
     * bailan; el ancho final se reserva para que la unidad no se desplace.
     */
    function contar(nodo, retraso) {
        if (!animar() || typeof window.requestAnimationFrame !== 'function') return;
        var final = nodo.textContent;
        var partes = /^([^\d]*?)(\d[\d,]*)(\.\d+)?(.*)$/.exec(final);
        if (!partes) return;
        var conComas = partes[2].indexOf(',') !== -1;
        var decimales = partes[3] ? partes[3].length - 1 : 0;
        var valor = parseFloat(partes[2].replace(/,/g, '') + (partes[3] || ''));
        if (!isFinite(valor) || valor <= 0) return;

        function formato(v) {
            var fijo = v.toFixed(decimales).split('.');
            var entero = conComas ? miles(fijo[0]) : fijo[0];
            return partes[1] + entero + (fijo[1] ? '.' + fijo[1] : '') + partes[4];
        }

        // Corto a propósito: la cifra ES la respuesta, y mientras cuenta se lee
        // un número que no es. Con 900 ms «¿cuánto vendimos hoy?» tardaba casi
        // un segundo en decir lo que preguntaron; a ≤520 ms y con la curva de
        // salida, a la mitad ya está a un 6 % del valor final.
        var duracion = Math.min(520, 300 + partes[2].length * 40);
        nodo.style.minWidth = final.length + 'ch';
        nodo.textContent = formato(0);
        window.setTimeout(function () {
            var inicio = null;
            function paso(t) {
                if (inicio === null) inicio = t;
                var avance = Math.min(1, (t - inicio) / duracion);
                var curva = 1 - Math.pow(1 - avance, 4);
                if (avance < 1) {
                    nodo.textContent = formato(valor * curva);
                    window.requestAnimationFrame(paso);
                } else {
                    nodo.textContent = final;
                    nodo.style.minWidth = '';
                }
            }
            window.requestAnimationFrame(paso);
        }, retraso);
    }

    /**
     * Un botón que hace una pregunta nueva en la conversación. `texto` es lo
     * que se ve como pregunta del usuario; `peticion`, lo que se manda al motor
     * cuando no es ese texto tal cual (ver normalizarPeticion).
     */
    function botonPregunta(clase, etiqueta, texto, peticion) {
        var boton = crear('button', clase);
        boton.type = 'button';
        boton.setAttribute('data-ia-preguntar', '');
        boton.setAttribute('data-ia-texto', texto);
        var limpia = normalizarPeticion(peticion);
        if (limpia && !(limpia.q === texto && !limpia.periodo)) {
            boton.setAttribute('data-ia-peticion', JSON.stringify(limpia));
        }
        if (etiqueta !== null) boton.appendChild(crear('span', '', etiqueta));
        return boton;
    }

    // «¿Qué quieres consultar?» + «Pregunta por…»: sin el punto que pondría un
    // join a ciegas detrás de un signo de cierre.
    function unirFrases(partes) {
        return partes.filter(esTexto).reduce(function (acumulado, parte) {
            if (!acumulado) return parte;
            return acumulado + (/[.?!…]$/.test(acumulado) ? ' ' : '. ') + parte;
        }, '');
    }

    function nodoFicha(datos, nuevo, retraso) {
        var r = datos.respuesta;
        var rubro = RUBROS[r.tipo];
        var estadoFicha = r.estado === 'bajo' || r.estado === 'agotado' ? r.estado : '';
        var ficha = crear('article', 'ia-ficha ia-ficha--' + claseSegura(r.tipo) + (estadoFicha ? ' is-' + estadoFicha : ''));
        ficha.style.setProperty('--ia-retraso', retraso + 'ms');

        var cabeza = crear('header', 'ia-ficha__cabeza');
        var rotulo = crear('p', 'ia-ficha__rubro');
        rotulo.appendChild(nodoIcono(rubro.icono, 14));
        var periodo = CON_PERIODO[r.tipo] ? etiquetaPeriodo(datos) : '';
        rotulo.appendChild(crear('span', '', rubro.nombre + (periodo ? ' · ' + periodo : '')));
        cabeza.appendChild(rotulo);
        // El estado va en palabras además de en color: «bajo mínimo» no puede
        // depender de distinguir el ámbar.
        if (estadoFicha) {
            cabeza.appendChild(crear('span', 'ia-ficha__estado', estadoFicha === 'agotado' ? 'Agotado' : 'Bajo mínimo'));
        }
        ficha.appendChild(cabeza);

        // h2: en modo conversación el saludo y el catálogo no están, así que
        // cada respuesta es una sección directa del <h1> de la conversación,
        // y el lector de pantalla puede saltar de respuesta en respuesta.
        // «Lo más vendido» o «Ventas hoy» ya los dice el rótulo de arriba: el
        // título sólo se pinta cuando nombra la cosa (el platillo, la familia,
        // el ingrediente). Para el lector de pantalla se queda siempre.
        if (esTexto(r.titulo)) {
            var titulo = crear('h2', 'ia-ficha__titulo', r.titulo);
            if (sinAcentos(r.titulo).indexOf(sinAcentos(rubro.nombre)) === 0) titulo.className = 'admin-visually-hidden';
            ficha.appendChild(titulo);
        }

        if (tieneCifra(r)) {
            var dato = crear('p', 'ia-ficha__dato');
            var cifra = crear('span', 'ia-ficha__cifra', String(r.cifra));
            dato.appendChild(cifra);
            if (esTexto(r.unidad)) dato.appendChild(crear('span', 'ia-ficha__unidad', r.unidad));
            ficha.appendChild(dato);
            // Empieza a contar cuando la ficha se hace visible (su entrada va
            // retrasada `retraso`), no después: el cero no debe quedarse a la vista.
            if (nuevo) contar(cifra, retraso);
        }

        if (r.comparacion && esTexto(r.comparacion.texto)) {
            var tendencia = claseSegura(r.comparacion.tendencia) || 'igual';
            // La flecha dice la dirección y el color lo que significa (más
            // cancelaciones suben y son malas); sin `lectura` (respuestas
            // guardadas antes de que existiera), subir se lee como bien.
            var lectura = claseSegura(r.comparacion.lectura) || (tendencia === 'sube' ? 'bien' : (tendencia === 'baja' ? 'mal' : 'igual'));
            var comparacion = crear('p', 'ia-ficha__comparacion is-' + tendencia + ' is-' + lectura);
            if (tendencia === 'sube' || tendencia === 'baja') comparacion.appendChild(nodoIcono(tendencia, 14));
            comparacion.appendChild(crear('span', '', r.comparacion.texto));
            ficha.appendChild(comparacion);
        }

        var detalle = detalleDe(r);
        if (detalle.length) {
            var bloque = crear('div', 'ia-ficha__detalle');
            var lista = crear('ul', 'ia-ficha__lista');
            if (esTexto(r.detalle_titulo)) {
                var tituloDetalle = crear('p', 'ia-ficha__detalle-titulo', r.detalle_titulo);
                tituloDetalle.id = nuevoId('ia-det');
                lista.setAttribute('aria-labelledby', tituloDetalle.id);
                bloque.appendChild(tituloDetalle);
            }
            var maximo = 0;
            detalle.forEach(function (d) { if (typeof d.valor === 'number' && d.valor > maximo) maximo = d.valor; });
            detalle.forEach(function (d, i) {
                var fila = crear('li', 'ia-ficha__fila');
                fila.style.setProperty('--ia-retraso', (retraso + 160 + i * 45) + 'ms');
                var consulta = consultaEstructurada(d.consulta);
                if (consulta) {
                    // El renglón no trae el periodo a propósito: lo pone quien
                    // pregunta, que es el que se está mirando.
                    consulta.periodo = datos.periodo ? datos.periodo.clave : '';
                    var item = botonPregunta('ia-ficha__item', null, d.etiqueta, consulta);
                    item.appendChild(crear('span', 'ia-ficha__item-texto', d.etiqueta));
                    item.appendChild(nodoIcono('flecha-derecha', 14, 'ia-ficha__item-flecha'));
                    item.setAttribute('aria-label', 'Preguntar por ' + d.etiqueta);
                    fila.appendChild(item);
                } else {
                    fila.appendChild(crear('span', 'ia-ficha__item', d.etiqueta));
                }
                fila.appendChild(crear('span', 'ia-ficha__valor', valorMostrado(d)));
                if (typeof d.valor === 'number' && maximo > 0) {
                    var barra = crear('span', 'ia-ficha__barra');
                    barra.setAttribute('aria-hidden', 'true');
                    var relleno = crear('span', '');
                    relleno.style.width = Math.max(4, Math.round((d.valor / maximo) * 100)) + '%';
                    barra.appendChild(relleno);
                    fila.appendChild(barra);
                }
                lista.appendChild(fila);
            });
            bloque.appendChild(lista);
            ficha.appendChild(bloque);
        }

        if (enlaceValido(r.enlace)) {
            var pie = crear('footer', 'ia-ficha__pie');
            var enlace = crear('a', 'ia-ficha__enlace');
            enlace.href = r.enlace.href;
            enlace.appendChild(crear('span', '', r.enlace.texto));
            enlace.appendChild(nodoIcono('flecha-derecha', 14));
            pie.appendChild(enlace);
            ficha.appendChild(pie);
        }

        return ficha;
    }

    /** Un grupo rotulado de botones para seguir preguntando. */
    function grupoSeguir(rotulo, botones, clase) {
        var grupo = crear('div', 'ia-seguir__grupo' + (clase ? ' ' + clase : ''));
        var id = nuevoId('ia-seg');
        var titulo = crear('p', 'ia-seguir__rotulo', rotulo);
        titulo.id = id;
        var lista = crear('ul', 'ia-seguir__lista');
        lista.setAttribute('aria-labelledby', id);
        botones.forEach(function (b) {
            var li = crear('li', '');
            li.appendChild(b);
            lista.appendChild(li);
        });
        grupo.appendChild(titulo);
        grupo.appendChild(lista);
        return grupo;
    }

    /**
     * Las otras lecturas de la palabra («leche» como ingrediente cuando se
     * preguntó por ventas) y, si no se entendió, lo que se le parece. Sólo con
     * consulta estructurada: sin texto libre, una frase no es una pregunta.
     */
    function chipsAlternativas(lista) {
        return lista.filter(function (a) {
            return a && esTexto(a.etiqueta || a.nombre) && consultaEstructurada(a.consulta);
        }).map(function (a) {
            var nombre = String(a.etiqueta || a.nombre);
            var texto = esTexto(a.texto) ? a.texto : (a.tipo === 'stock' ? nombre + ' en almacén' : 'Ventas de ' + nombre);
            var b = botonPregunta('ia-chip', null, texto, consultaEstructurada(a.consulta));
            b.appendChild(nodoIcono(a.tipo === 'stock' ? 'inventario' : 'tickets', 14));
            b.appendChild(crear('span', '', nombre));
            b.setAttribute('aria-label', 'Preguntar: ' + texto);
            return b;
        });
    }

    function nodoSeguir(datos, retraso) {
        var r = datos.respuesta;
        var caja = crear('div', 'ia-seguir');
        caja.style.setProperty('--ia-retraso', retraso + 'ms');

        if (CON_PERIODO[r.tipo] && datos.periodos && esTexto(datos.consulta)) {
            var actual = datos.periodo ? datos.periodo.clave : '';
            // La misma consulta en otro periodo: estructurada si el motor dijo
            // qué entendió, para no volver a interpretar la frase.
            var base = consultaEstructurada(datos.interpretacion);
            var chips = [];
            Object.keys(datos.periodos).forEach(function (clave) {
                if (clave === actual || !esTexto(datos.periodos[clave])) return;
                var etiqueta = String(datos.periodos[clave]);
                var texto = PREGUNTA_PERIODO[clave] || ('¿Y ' + etiqueta.toLowerCase() + '?');
                var peticion = base
                    ? { intencion: base.intencion, id: base.id, opcion: base.opcion, periodo: clave }
                    : { q: datos.consulta, periodo: clave };
                var b = botonPregunta('ia-chip', etiqueta, texto, peticion);
                b.setAttribute('aria-label', texto);
                chips.push(b);
            });
            if (chips.length) caja.appendChild(grupoSeguir('Otro periodo', chips));
        }

        var alternativas = chipsAlternativas(datos.alternativas);
        if (alternativas.length) caja.appendChild(grupoSeguir('Otras coincidencias', alternativas));

        return caja.childNodes.length ? caja : null;
    }

    /**
     * Las preguntas base del estado vacío, leídas del marcado que pintó PHP:
     * título, tema, icono (ya en SVG: el catálogo JS de iconos no tiene los
     * de los módulos) y el borrador que se carga en el constructor.
     */
    function leerCatalogo() {
        if (!el.catalogo) return [];
        var botones = el.catalogo.querySelectorAll('[data-ia-base]');
        var lista = [];
        for (var i = 0; i < botones.length; i++) {
            var b = botones[i];
            var borrador = leerJson(b.getAttribute('data-ia-borrador'));
            if (!esObjeto(borrador) || !Array.isArray(borrador.partes)) continue;
            var variantes = leerJson(b.getAttribute('data-ia-borradores'));
            variantes = Array.isArray(variantes)
                ? variantes.filter(function (v) { return esObjeto(v) && Array.isArray(v.partes); })
                : [];
            if (!variantes.length) variantes = [borrador];
            var titulo = b.querySelector('.ia-base__titulo');
            var icono = b.querySelector('.ia-base__icono');
            var desc = document.getElementById(b.getAttribute('aria-describedby') || '');
            lista.push({
                intencion: b.getAttribute('data-ia-base'),
                titulo: titulo ? titulo.textContent.trim() : '',
                tema: b.getAttribute('data-ia-tema') || '',
                descripcion: desc ? desc.textContent.trim() : '',
                icono: icono ? icono.innerHTML : '',
                borrador: borrador,
                borradores: variantes,
                ejemplo: textoDeBorrador(borrador)
            });
        }
        return lista;
    }

    /**
     * La frase de un borrador tal como se diría: los textos y los valores de
     * sus huecos, sin el «·» y con el periodo en minúscula («Ventas de
     * Cappuccino ayer»). Es lo que se ve como pregunta al enviarla, hasta que
     * el motor devuelve su texto canónico, y lo que se anuncia al armarla.
     */
    function textoDeBorrador(borrador) {
        var trozos = [];
        (borrador && borrador.partes || []).forEach(function (p) {
            if (!p) return;
            if (p.tipo === 'hueco') {
                var valor = String(p.etiqueta || '');
                trozos.push(p.campo === 'periodo' ? valor.toLowerCase() : valor);
            } else if (esTexto(p.texto) && p.texto !== '·') {
                trozos.push(String(p.texto));
            }
        });
        return trozos.join(' ').replace(/\s+/g, ' ').trim();
    }

    var catalogo = leerCatalogo();

    function basePorIntencion(intencion) {
        for (var i = 0; i < catalogo.length; i++) {
            if (catalogo[i].intencion === intencion) return catalogo[i];
        }
        return null;
    }

    /**
     * Cuando no hay nada parecido a lo escrito, la tarjeta del motor ofrece
     * tres ejemplos genéricos («Prueba: …»). Aquí, debajo, va el catálogo
     * entero de preguntas base, así que eran dos listas de ejemplos seguidas
     * y tres salidas para el mismo atasco: se retiran los de la tarjeta, como
     * ya se hacía sin ella. Los «¿Buscabas…?» se quedan: ésos hablan de ESTA
     * pregunta.
     */
    function quitarEjemplosRepetidos(contenedor, datos) {
        var hayParecidos = (datos.sugerencias || []).some(function (s) { return s && s.motivo === 'parecido'; });
        if (hayParecidos) return;
        var boton = contenedor.querySelector('[data-origen="sugerencia"]');
        var grupo = boton && boton.closest('.admin-consulta__alternativas');
        if (!grupo || !grupo.parentNode) return;
        var pie = grupo.parentNode;
        pie.removeChild(grupo);
        // Sin nada más dentro, el pie sólo dejaría su filete superior.
        if (pie.classList.contains('admin-consulta__pie') && !pie.children.length && pie.parentNode) {
            pie.parentNode.removeChild(pie);
        }
    }

    /**
     * Lo que el motor no pudo contestar. Sin texto libre ya sólo pasa con una
     * cosa que dejó de existir («Ese platillo ya no está en la carta») o con
     * una pregunta de texto guardada de antes. Nunca una caja vacía: se dice
     * qué pasó, y debajo va el catálogo entero de lo que sí se sabe
     * responder, de un toque.
     */
    function nodoNoEntendi(m, nuevo) {
        var datos = m.datos || {};
        var r = datos.respuesta;
        var caja = crear('div', 'ia-resp ia-resp--no-entendi');
        var seguir = crear('div', 'ia-seguir');
        var retraso = 160;

        // Con la tarjeta del motor, su frase y los huecos «Elige [un platillo]
        // o [un ingrediente]» para escoger de la lista entera, con buscador.
        // Las respuestas `fuera_de_alcance` son de antes (ia.js las contestaba
        // a las frases sobre reservaciones, márgenes u opiniones) y no tienen
        // tarjeta: se pintan con su frase y su enlace.
        var tarjeta = r && r.tipo !== 'fuera_de_alcance' && Array.isArray(datos.plantilla) && datos.plantilla.length
            ? nodoTarjeta(m, nuevo)
            : null;

        if (tarjeta) {
            if (catalogo.length) quitarEjemplosRepetidos(tarjeta, datos);
            caja.appendChild(tarjeta);
        } else {
            var frase = r && r.tipo === 'fuera_de_alcance'
                ? r.frase
                : (r ? unirFrases([r.titulo, r.frase]) : '');
            caja.appendChild(nodoFrase(frase || TEXTOS.noEntendi, nuevo));

            if (r && enlaceValido(r.enlace)) {
                var enlace = crear('a', 'ia-resp__enlace');
                enlace.href = r.enlace.href;
                enlace.style.setProperty('--ia-retraso', retraso + 'ms');
                enlace.appendChild(crear('span', '', r.enlace.texto));
                enlace.appendChild(nodoIcono('flecha-derecha', 14));
                caja.appendChild(enlace);
            }

            // Lo que se parece a lo escrito, si el motor encontró algo; los
            // ejemplos genéricos que también trae no se repiten: el catálogo
            // de abajo ya los tiene todos.
            var parecidos = (datos.sugerencias || []).filter(function (s) { return s && s.motivo === 'parecido'; });
            var alternativas = chipsAlternativas(parecidos.concat(datos.alternativas || []));
            if (alternativas.length) seguir.appendChild(grupoSeguir('¿Buscabas…?', alternativas));
        }

        seguir.style.setProperty('--ia-retraso', (retraso + 120) + 'ms');
        // Las preguntas base, como en el estado vacío: elegir una la ARMA en
        // el compositor, no la manda.
        if (catalogo.length) {
            var filas = catalogo.map(function (c) {
                var b = crear('button', 'ia-chip ia-chip--ejemplo');
                b.type = 'button';
                b.setAttribute('data-ia-cargar', c.intencion);
                if (c.icono) {
                    var ico = crear('span', 'ia-chip__icono');
                    ico.setAttribute('aria-hidden', 'true');
                    ico.innerHTML = c.icono;
                    b.appendChild(ico);
                }
                b.appendChild(crear('span', 'ia-chip__texto', c.titulo));
                if (c.ejemplo) b.appendChild(crear('span', 'ia-chip__que', c.ejemplo));
                b.setAttribute('aria-label', 'Armar: ' + c.titulo + (c.ejemplo ? '. Por ejemplo, ' + c.ejemplo : ''));
                return b;
            });
            seguir.appendChild(grupoSeguir(TEXTOS.catalogo, filas, 'ia-seguir__grupo--catalogo'));
        }
        if (seguir.childNodes.length) caja.appendChild(seguir);
        return caja;
    }

    /* ── La tarjeta del motor, con huecos ───────────────────────── *
     *
     * core/consultas-rapidas.js publica `window.ConsultasRapidas.montar()`: la
     * tarjeta de respuesta del motor, con la pregunta dicha de
     * vuelta y sus variables en huecos que se cambian sin reescribir. Aquí se
     * monta una por respuesta, y se le dice qué hacer con cada cambio:
     *
     *   - un HUECO (platillo, opción, periodo) rehace la respuesta en su sitio:
     *     es la misma pregunta con otro valor, y queda guardada así;
     *   - un renglón del desglose, una alternativa o una sugerencia abren una
     *     pregunta NUEVA en la conversación: la respuesta de la que salieron se
     *     queda en el hilo, como en cualquier conversación.
     */

    function apiTarjeta() {
        var api = window.ConsultasRapidas;
        return api && typeof api.montar === 'function' ? api : null;
    }

    // El texto visible del último renglón o sugerencia pulsado dentro de una
    // tarjeta: la API entrega la consulta estructurada, no su nombre, y la
    // pregunta nueva necesita algo que enseñar mientras llega la respuesta.
    var ultimoPulsado = '';

    function mensajeActivo(id) {
        var c = activa();
        if (!c) return null;
        for (var i = 0; i < c.mensajes.length; i++) {
            if (c.mensajes[i].id === id) return c.mensajes[i];
        }
        return null;
    }

    function alCambiarTarjeta(mensajeId, consulta, info) {
        if (info && info.origen === 'hueco') return true;
        var peticion = consultaEstructurada(consulta);
        if (peticion) preguntar(ultimoPulsado || 'Consulta', peticion, true);
        return false;
    }

    function alRepintarTarjeta(mensajeId, datos, error, info) {
        if (!info || info.origen !== 'hueco' || error || !datos) return;
        var limpios = limpiarDatos(datos);
        var m = mensajeActivo(mensajeId);
        if (!limpios || !m) return;
        m.datos = limpios;
        m.texto = resumenDe(limpios);
        // Una pregunta no entendida que se resolvió eligiendo en sus huecos ya
        // es una respuesta: el catálogo de rescate de debajo sobra.
        if (entendida(limpios)) {
            var rescate = el.mensajes.querySelector('[data-ia-msg="' + mensajeId + '"] .ia-resp--no-entendi');
            if (rescate) {
                rescate.classList.remove('ia-resp--no-entendi');
                var sobrante = rescate.querySelector(':scope > .ia-seguir');
                if (sobrante) sobrante.parentNode.removeChild(sobrante);
            }
        }
        var c = activa();
        if (c) {
            c.actualizada = Date.now();
            ordenar();
            guardar();
            pintarHistorial();
        }
        anunciar(textoAnunciable(m));
    }

    /**
     * Entrada progresiva de una tarjeta nueva: su frase se vuelve a escribir
     * palabra a palabra y la cifra cuenta hasta su valor. Sólo toca texto ya
     * pintado; si la tarjeta cambia de marcado, simplemente no se anima.
     */
    function revelarTarjeta(contenedor) {
        var frase = contenedor.querySelector('.admin-consulta__frase');
        if (frase) escribirPalabras(frase, frase.textContent, true);
        var cifra = contenedor.querySelector('.admin-consulta__cifra');
        // Sin espera: `contar()` pone la cifra a cero en cuanto se llama, y la
        // tarjeta ya está a la vista, así que cada milisegundo de retraso era
        // un «$0.00» falso en pantalla (antes, entre 150 y 250 ms).
        if (cifra) contar(cifra, 0);
    }

    function nodoTarjeta(m, nuevo) {
        var api = apiTarjeta();
        if (!api) return null;
        var contenedor = crear('div', 'ia-resp__tarjeta');
        var id = m.id;
        try {
            api.montar(contenedor, {
                ambito: 'todo',
                // La región viva es la del asistente: dos anunciarían dos veces.
                anunciar: false,
                nivelTitulo: 2,
                alCambiar: function (consulta, info) { return alCambiarTarjeta(id, consulta, info); },
                alPintar: function (datos, error, info) { alRepintarTarjeta(id, datos, error, info); }
            }).pintar(m.datos);
        } catch (error) {
            return null;
        }
        if (!contenedor.firstChild) return null;
        // La entrada de la tarjeta la hace aquí el mensaje entero
        // (`.ia-msg.is-nuevo`); la suya propia, sumada, movía la tarjeta dos
        // veces y fundía su opacidad dos veces seguidas. Y al reabrir una
        // conversación el hilo se pinta de golpe, sin ninguna de las dos.
        var tarjeta = contenedor.querySelector('.is-entrando');
        if (tarjeta) tarjeta.classList.remove('is-entrando');
        if (nuevo && animar()) revelarTarjeta(contenedor);
        return contenedor;
    }

    function nodoRespuesta(m, nuevo) {
        var datos = m.datos || {};
        var r = datos.respuesta;
        if (!entendida(datos)) return nodoNoEntendi(m, nuevo);

        var caja = crear('div', 'ia-resp');

        // Con plantilla (lo que el motor entendió, con sus huecos) y la API a
        // mano, la tarjeta del motor; si no, la ficha propia de abajo.
        if (Array.isArray(datos.plantilla) && datos.plantilla.length) {
            var tarjeta = nodoTarjeta(m, nuevo);
            if (tarjeta) {
                caja.appendChild(tarjeta);
                return caja;
            }
        }

        var frase = nodoFrase(esTexto(r.frase) ? r.frase : r.titulo, nuevo);
        caja.appendChild(frase);
        // La ficha llega cuando la frase va por la mitad: esperarla entera se
        // hacía largo, y a la vez se leía como un bloque.
        var retraso = nuevo && animar() ? Math.round(parseInt(frase.getAttribute('data-ia-duracion'), 10) * 0.55) + 90 : 0;
        // Sin cifra ni desglose («no hay ventas cerradas esta semana») la
        // ficha sólo repetiría el rótulo: basta la frase y por dónde seguir.
        if (tieneCifra(r) || detalleDe(r).length) caja.appendChild(nodoFicha(datos, nuevo, retraso));
        var seguir = nodoSeguir(datos, retraso + 260);
        if (seguir) caja.appendChild(seguir);
        return caja;
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
        boton.appendChild(crear('span', 'ia-accion__texto', etiqueta));
        return boton;
    }

    function horaMensaje(m) {
        var hora = crear('time', 'ia-msg__hora', formatoHora(m.fecha));
        try { hora.dateTime = new Date(m.fecha).toISOString(); } catch (error) { /* fecha inválida */ }
        return hora;
    }

    function nodoAviso(clave, texto) {
        var datos = AVISOS[clave] || AVISOS.error;
        var aviso = crear('div', 'ia-aviso' + (datos.clase ? ' ' + datos.clase : ''));
        var chip = crear('span', 'ia-aviso__icono');
        chip.setAttribute('aria-hidden', 'true');
        chip.innerHTML = icono(datos.icono, 16);
        aviso.appendChild(chip);
        aviso.appendChild(crear('p', 'ia-aviso__titulo', datos.titulo));
        aviso.appendChild(crear('p', 'ia-aviso__texto', texto));
        return aviso;
    }

    function nodoMensaje(m, nuevo) {
        var li = crear('li', 'ia-msg ia-msg--' + m.rol + (nuevo ? ' is-nuevo' : ''));
        li.setAttribute('data-ia-msg', m.id);
        var pie = crear('div', 'ia-msg__pie');

        if (m.rol === 'usuario') {
            li.appendChild(textoOculto('Tú:'));
            li.appendChild(crear('div', 'ia-msg__burbuja', m.texto));
            pie.appendChild(horaMensaje(m));
            pie.appendChild(botonAccion('copiar', 'Copiar', 'data-ia-copiar', m.id, 'Copiar la pregunta'));
            li.appendChild(pie);
            return li;
        }

        li.appendChild(marcaAsistente(false));
        var contenido = crear('div', 'ia-msg__contenido');
        contenido.appendChild(textoOculto('Asistente:'));

        if (m.tipo === 'consulta') {
            contenido.appendChild(nodoRespuesta(m, nuevo));
            pie.appendChild(botonAccion('copiar', 'Copiar', 'data-ia-copiar', m.id, 'Copiar la respuesta'));
        } else {
            contenido.appendChild(nodoAviso(m.tipo, m.texto));
        }
        pie.appendChild(botonAccion('reintentar', 'Repetir', 'data-ia-reintentar', m.id, 'Hacer la consulta otra vez'));
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
        pie.appendChild(botonAccion('reintentar', 'Consultar ahora', 'data-ia-reintentar', '', 'Hacer la consulta ahora'));
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
        fila.appendChild(crear('span', 'ia-pensando__texto', TEXTOS.consultando));
        var segundos = crear('span', 'ia-pensando__tiempo');
        segundos.setAttribute('data-ia-segundos', '');
        segundos.hidden = true;
        fila.appendChild(segundos);
        contenido.appendChild(fila);
        li.appendChild(contenido);
        return li;
    }

    function quitarNodo(selector) {
        var nodo = el.mensajes.querySelector(selector);
        if (nodo && nodo.parentNode) nodo.parentNode.removeChild(nodo);
    }

    function pintarTitulo() {
        var c = activa();
        if (el.titulo) el.titulo.textContent = c ? c.titulo : 'Nueva conversación';
    }

    /* ── Los dos modos ──────────────────────────────────────────── *
     *
     * Conversación nueva: saludo, compositor y catálogo en un bloque
     * centrado. Con mensajes: hilo arriba y compositor fijo abajo. El
     * compositor es el MISMO nodo en los dos, así que al cambiar de modo viaja
     * de un sitio al otro (FLIP sobre `transform`, en el compositor del
     * navegador) en vez de desaparecer y reaparecer: se ve que la pregunta
     * que se escribió en el centro es la que abre la conversación.
     */

    function esVacia() {
        return el.chat.classList.contains('is-vacia');
    }

    function curvaDelSistema() {
        var valor = '';
        try { valor = window.getComputedStyle(raiz).getPropertyValue('--admin-ease').trim(); } catch (error) { valor = ''; }
        return valor || 'cubic-bezier(0.16, 1, 0.3, 1)';
    }

    var relojLlegada = null;

    function cambiarModo(hayMensajes, conMovimiento) {
        var vacia = !hayMensajes;
        if (esVacia() === vacia) return;

        var mover = conMovimiento && animar() && typeof el.form.animate === 'function';
        var antes = mover ? el.form.getBoundingClientRect() : null;

        el.chat.classList.toggle('is-vacia', vacia);
        if (el.escenario) el.escenario.scrollTop = 0;

        if (vacia && mover) {
            // El saludo y el catálogo entran escalonados sólo cuando se vuelve
            // a una conversación nueva; en la primera pintura ya están.
            window.clearTimeout(relojLlegada);
            el.chat.classList.add('is-llegando');
            relojLlegada = window.setTimeout(function () { el.chat.classList.remove('is-llegando'); }, 900);
        }

        if (!antes) return;
        var despues = el.form.getBoundingClientRect();
        var dy = antes.top - despues.top;
        if (Math.abs(dy) < 2) return;
        el.form.animate(
            [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
            // Por debajo de medio segundo: es una herramienta de trabajo, y el
            // viaje tiene que leerse sin hacer esperar a la respuesta.
            { duration: 420, easing: curvaDelSistema() }
        );
    }

    function pintarHilo(conMovimiento) {
        var c = activa();
        // Un selector de hueco abierto vive en el <body>, fuera del hilo: se
        // cierra antes de que desaparezca la tarjeta que lo abrió.
        var api = apiTarjeta();
        if (api && typeof api.cerrarSelector === 'function') api.cerrarSelector();
        el.mensajes.innerHTML = '';
        var hay = !!(c && c.mensajes.length);
        cambiarModo(hay, conMovimiento);

        if (hay) {
            var fragmento = document.createDocumentFragment();
            c.mensajes.forEach(function (m) { fragmento.appendChild(nodoMensaje(m, false)); });
            var ultima = c.mensajes[c.mensajes.length - 1];
            if (estado.pendientes[c.id]) {
                fragmento.appendChild(nodoPensando(false));
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
        var mostrar = !estado.pegadoAlFinal && !esVacia() && !estado.programado;
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
     * Al llegar una respuesta larga no se salta a su ÚLTIMA línea: se deja a
     * la vista la pregunta y el arranque de la respuesta. Si cabe todo, es lo
     * mismo que ir al final.
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

    // El hilo cambia de alto sin que nadie haga scroll: la pregunta armada
    // pasa a dos renglones, se pliega el historial, una ficha termina de
    // entrar. Si se estaba al final, se sigue.
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

    /**
     * El compositor no tiene campo de texto: «ir al compositor» es ir a su
     * primer hueco por elegir o, si están todos, al primero. Sin pregunta
     * armada (sin catálogo) no hay a dónde ir.
     */
    function enfocarCompositor() {
        var destino = el.armadoFrase.querySelector('.ia-hueco.is-vacio') ||
            el.armadoFrase.querySelector('.ia-hueco');
        if (!destino) return;
        try { destino.focus({ preventScroll: true }); } catch (error) { destino.focus(); }
    }

    /** Tras cambiar de conversación: el compositor con teclado, el título en táctil. */
    function enfocarTrasCambio() {
        if (!mqTactil.matches) {
            enfocarCompositor();
            return;
        }
        if (el.titulo) {
            try { el.titulo.focus({ preventScroll: true }); } catch (error) { el.titulo.focus(); }
        }
    }

    /**
     * El botón que tenía el foco desapareció o se deshabilitó (enviar mientras
     * espera, Detener al llegar la respuesta, Repetir al retirarse, una
     * pregunta del catálogo al pasar a modo conversación). Sin esto el foco
     * cae al <body> y quien usa teclado vuelve a empezar desde arriba.
     */
    function rescatarFoco() {
        window.requestAnimationFrame(function () {
            var actual = document.activeElement;
            var visible = actual && actual !== document.body && document.documentElement.contains(actual) &&
                !actual.disabled && actual.getClientRects().length > 0 &&
                window.getComputedStyle(actual).visibility !== 'hidden';
            if (visible) return;
            if (!mqTactil.matches) {
                enfocarCompositor();
                return;
            }
            try { el.hilo.focus({ preventScroll: true }); } catch (error) { el.hilo.focus(); }
        });
    }

    /* ── Compositor ─────────────────────────────────────────────── */

    function actualizarCompositor() {
        var c = activa();
        var pendiente = c ? estado.pendientes[c.id] : null;
        var lento = !!(pendiente && pendiente.lento);

        el.chat.classList.toggle('is-esperando', !!pendiente);
        el.enviar.hidden = lento;
        if (el.detener) el.detener.hidden = !lento;
        // Un hueco por elegir no deshabilita: enviar lo señala y lleva a él
        // (ver enviar()), que dice más que un botón apagado.
        el.enviar.disabled = !estado.armada || !!pendiente;
    }

    /* ── La pregunta armada ─────────────────────────────────────── *
     *
     * El compositor ES una pregunta base del motor con sus variables como
     * huecos —«Ventas de [Cappuccino ▾] · [Ayer ▾]»—, cada uno con el selector
     * del motor (buscador, teclado, lector de pantalla;
     * `ConsultasRapidas.elegir`). Elegir un valor no consulta: la consulta
     * ESTRUCTURADA sale al enviar, así que el resultado no depende de que el
     * intérprete adivine una frase.
     *
     * Siempre hay una armada. Nace con la primera del catálogo; elegir otra
     * pregunta base (el catálogo, el botón de la pregunta o Ctrl/⌘+K) la
     * cambia; y al llegar una respuesta, si no se tocó desde el envío, toma
     * su forma —la del motor, que puede traer un hueco más, como la opción de
     * un platillo—, lista para cambiarle un valor y volver a preguntar.
     */

    var OPCIONES_PERIODO = (function () {
        var lista = el.periodos ? leerJson(el.periodos.textContent) : null;
        return Array.isArray(lista) ? lista : [];
    })();

    function apiSelector() {
        var api = window.ConsultasRapidas;
        return api && typeof api.elegir === 'function' ? api : null;
    }

    function claveArmada() {
        return estado.activaId || '';
    }

    // Sólo el hueco de la COSA puede quedar sin elegir (una base sin datos
    // para su ejemplo). La opción de un platillo vacía es «cualquier opción».
    function huecoPorElegir(p) {
        return p.tipo === 'hueco' && p.campo === 'entidad' && !p.valor;
    }

    function preguntaIncompleta() {
        var c = estado.armada;
        if (!c) return true;
        for (var i = 0; i < c.partes.length; i++) {
            if (huecoPorElegir(c.partes[i])) return true;
        }
        return false;
    }

    /** Una pregunta armada a partir de una respuesta entendida, o null. */
    function borradorDeRespuesta(datos) {
        var consulta = datos && datos.entendida !== false ? consultaEstructurada(datos.interpretacion) : null;
        if (!consulta || !Array.isArray(datos.plantilla) || !datos.plantilla.length) return null;
        var partes = [];
        datos.plantilla.forEach(function (p) {
            if (!esObjeto(p)) return;
            if (p.tipo !== 'hueco') {
                partes.push({ tipo: 'texto', texto: String(p.texto || '') });
                return;
            }
            var hueco = {
                tipo: 'hueco',
                campo: String(p.campo || ''),
                rotulo: String(p.rotulo || ''),
                valor: p.valor === null || p.valor === undefined ? '' : String(p.valor),
                etiqueta: String(p.etiqueta || '')
            };
            if (esTexto(p.catalogo)) hueco.catalogo = p.catalogo;
            if (esTexto(p.intencion)) hueco.intencion = p.intencion;
            if (Array.isArray(p.opciones)) hueco.opciones = p.opciones;
            partes.push(hueco);
        });
        return { consulta: consulta, partes: partes };
    }

    /** La última pregunta contestada de una conversación, armada. */
    function borradorDeConversacion(c) {
        if (!c) return null;
        for (var i = c.mensajes.length - 1; i >= 0; i--) {
            var m = c.mensajes[i];
            if (m.rol === 'asistente' && m.tipo === 'consulta') {
                var b = borradorDeRespuesta(m.datos);
                if (b) return b;
            }
        }
        return null;
    }

    /*
     * Un ejemplo AL AZAR de una pregunta base, distinto del que está armado
     * si hay de dónde elegir. El servidor manda varias combinaciones reales de
     * cosa y periodo (ConsultasRapidas::capacidades): con una sola, la
     * pregunta salía siempre con el mismo platillo o ingrediente y no se
     * entendía que el hueco admite cualquiera.
     */
    function borradorAlAzar(base) {
        if (!base) return null;
        var lista = base.borradores && base.borradores.length ? base.borradores : [base.borrador];
        var actual = estado.armada && estado.armada.consulta ? JSON.stringify(estado.armada.consulta) : '';
        var candidatos = lista.filter(function (b) {
            return JSON.stringify(b.consulta) !== actual;
        });
        if (!candidatos.length) candidatos = lista;
        return candidatos[Math.floor(Math.random() * candidatos.length)];
    }

    // La primera vez, el que pintó el servidor (ya elegido al azar allá: si
    // aquí se eligiera otro, la frase cambiaría ante los ojos al cargar). Las
    // siguientes —una conversación nueva—, otro al azar.
    var inicialServido = false;
    function borradorInicial() {
        if (!catalogo.length) return null;
        if (!inicialServido) {
            inicialServido = true;
            return catalogo[0].borrador;
        }
        return borradorAlAzar(catalogo[0]);
    }

    function pintarConstructor() {
        var c = estado.armada;
        var frase = el.armadoFrase;
        if (!c) return;
        frase.innerHTML = '';
        // El «·» se envuelve con la parte que le sigue: al partirse la frase
        // en un ancho chico, ningún renglón termina en el separador (ver
        // `.ia-constructor__par`). `par` es esa envoltura mientras espera a
        // su compañera. La vista pinta la primera igual, sin JS.
        var par = null;
        c.partes.forEach(function (p, i) {
            var destino = par || frase;
            par = null;
            // Un espacio real entre partes: el hueco del flex no es texto, y
            // sin él el lector y el portapapeles leían «Ventas deLatte».
            if (i > 0) destino.appendChild(document.createTextNode(' '));
            if (p.tipo === 'hueco') {
                var vacio = huecoPorElegir(p);
                var boton = crear('button', 'ia-hueco admin-consulta__hueco' + (vacio ? ' is-vacio' : ''));
                boton.type = 'button';
                boton.setAttribute('data-ia-hueco', String(i));
                boton.setAttribute('data-ia-hueco-campo', p.campo);
                boton.setAttribute('aria-haspopup', p.catalogo ? 'dialog' : 'listbox');
                boton.setAttribute('aria-expanded', 'false');
                // El valor primero y después qué es, como en la tarjeta del
                // motor: el lector dice lo mismo que se ve.
                boton.setAttribute('aria-label', p.etiqueta + ', ' + String(p.rotulo || '').toLowerCase() + (vacio ? ', sin elegir' : ''));
                boton.appendChild(crear('span', 'admin-consulta__hueco-valor', p.etiqueta));
                boton.appendChild(nodoIcono('chevron-abajo', 14, 'admin-consulta__hueco-chevron'));
                destino.appendChild(boton);
            } else if (p.texto === '·') {
                var sep = crear('span', 'admin-consulta__plantilla-sep', '·');
                sep.setAttribute('aria-hidden', 'true');
                par = crear('span', 'ia-constructor__par');
                par.appendChild(sep);
                destino.appendChild(par);
            } else if (esTexto(p.texto)) {
                destino.appendChild(crear('span', 'ia-constructor__texto', p.texto));
            }
        });
    }

    /**
     * La pregunta base de la armada, en los tres sitios que la dicen: el botón
     * de la pregunta (icono y título), la línea de qué contesta, y la píldora
     * marcada del catálogo. Cambiar la cosa de «Ventas de [todo el restaurante]»
     * a un platillo cambia la pregunta base, y con ella los tres.
     */
    function sincronizarBase() {
        var intencion = estado.armada && estado.armada.consulta ? estado.armada.consulta.intencion : '';
        var base = basePorIntencion(intencion);
        if (el.bases) {
            el.bases.hidden = !estado.armada || !catalogo.length;
            el.bases.setAttribute('aria-label', 'Pregunta: ' + (base ? base.titulo : 'sin elegir') + '. Elegir otra');
        }
        if (el.basesTexto) el.basesTexto.textContent = base ? base.titulo : 'Preguntas';
        if (el.basesIcono && base && base.icono) el.basesIcono.innerHTML = base.icono;
        if (el.ayuda) el.ayuda.textContent = base ? base.descripcion : '';
        if (el.catalogo) {
            var pildoras = el.catalogo.querySelectorAll('[data-ia-base]');
            for (var i = 0; i < pildoras.length; i++) {
                pildoras[i].setAttribute('aria-pressed', pildoras[i].getAttribute('data-ia-base') === intencion ? 'true' : 'false');
            }
        }
    }

    function enfocarHueco(campo) {
        var boton = el.armadoFrase.querySelector('[data-ia-hueco-campo="' + campo + '"]');
        if (boton) {
            try { boton.focus({ preventScroll: true }); } catch (error) { boton.focus(); }
        }
    }

    // Un destello en la caja al armar: dice «está aquí» cuando la pregunta se
    // eligió lejos del compositor y no hubo transición que la llevara.
    function destacarCaja() {
        // Sólo fondo, nada se desplaza: se queda también con movimiento reducido.
        if (!el.caja) return;
        el.caja.classList.remove('is-recien-armada');
        void el.caja.offsetWidth;
        el.caja.classList.add('is-recien-armada');
    }

    function cajaALaVista() {
        if (!el.caja || !el.escenario) return false;
        var r = el.caja.getBoundingClientRect();
        var marco = el.escenario.getBoundingClientRect();
        return r.height > 0 && r.top >= marco.top && r.bottom <= marco.bottom;
    }

    /**
     * La píldora elegida viaja al compositor y se convierte en la frase armada.
     * View Transition con nombre propio y PASAJERO: se pone en la píldora justo
     * antes, pasa a la frase durante el cambio y se retira al terminar (dos
     * elementos no pueden compartirlo, y uno fijo le daría grupo propio en
     * todas las transiciones del panel, la del tema incluida). El tipo
     * 'ia-armar' apaga el fundido de la raíz (ia.scss): sólo se mueve la
     * pregunta.
     *
     * Devuelve false si no la hace —sin la API, con movimiento reducido, o con
     * la caja fuera de la vista, donde el viaje acabaría fuera de pantalla— y
     * entonces quien llama aplica el cambio en seco.
     */
    function transicionArmar(origen, aplicar) {
        if (!origen || !animar() || typeof document.startViewTransition !== 'function' || !cajaALaVista()) return false;
        if (!origen.getClientRects().length) return false;
        var limpiar = function () {
            origen.style.viewTransitionName = '';
            el.armadoFrase.style.viewTransitionName = '';
        };
        var actualizar = function () {
            origen.style.viewTransitionName = '';
            aplicar();
            el.armadoFrase.style.viewTransitionName = 'ia-pregunta';
        };
        origen.style.viewTransitionName = 'ia-pregunta';
        var transicion;
        // La forma con objeto (tipos) llegó en Chrome 125 y la API en la 111:
        // en ese tramo se cae a la firma con callback, igual que theme.js.
        try {
            transicion = document.startViewTransition({ update: actualizar, types: ['ia-armar'] });
        } catch (error) {
            try {
                transicion = document.startViewTransition(actualizar);
            } catch (otro) {
                limpiar();
                return false;
            }
        }
        transicion.finished.then(limpiar, limpiar);
        // Si otra transición (el cambio de tema) se salta ésta, `ready` rechaza:
        // sin el catch acabaría en la consola como promesa sin atender.
        if (transicion.ready) transicion.ready.catch(function () {});
        return true;
    }

    /**
     * Pone una pregunta en el compositor.
     *   origen     el elemento desde el que viaja (píldora del catálogo).
     *   tocada     si cuenta como un cambio del usuario (ver armadaTocada).
     *   enfocar    lleva el foco a su primer hueco.
     *   anunciar   la dice por la región viva.
     */
    function armar(borrador, op) {
        op = op || {};
        if (!esObjeto(borrador) || !Array.isArray(borrador.partes)) return;
        var copia = leerJson(JSON.stringify(borrador));
        var nueva = {
            consulta: esObjeto(copia.consulta) ? copia.consulta : {},
            partes: copia.partes.filter(esObjeto)
        };
        // Si el foco estaba en un hueco, vuelve al del mismo campo: repintar
        // los deja huérfanos.
        var enfocado = document.activeElement;
        var campo = enfocado && el.armadoFrase.contains(enfocado) ? enfocado.getAttribute('data-ia-hueco-campo') : '';

        function aplicar() {
            if (window.ConsultasRapidas && typeof window.ConsultasRapidas.cerrarSelector === 'function') {
                window.ConsultasRapidas.cerrarSelector();
            }
            estado.armada = nueva;
            estado.armadas[claveArmada()] = nueva;
            pintarConstructor();
            sincronizarBase();
            actualizarCompositor();
            if (op.enfocar) enfocarCompositor();
            else if (campo) enfocarHueco(campo);
        }

        estado.armadaTocada = !!op.tocada;
        if (!transicionArmar(op.origen, aplicar)) {
            aplicar();
            if (op.origen) {
                destacarCaja();
                if (el.caja && typeof el.caja.scrollIntoView === 'function') {
                    el.caja.scrollIntoView({ block: 'nearest', behavior: animar() ? 'smooth' : 'auto' });
                }
            }
        }
        if (op.anunciar) {
            anunciar('Pregunta armada: ' + textoDeBorrador(nueva) + '. Cambia sus valores y envíala.');
        }
    }

    /** La armada de la conversación activa: la que se dejó, la última contestada o la inicial. */
    function restaurarArmada() {
        var borrador = estado.armadas[claveArmada()] || borradorDeConversacion(activa()) || borradorInicial();
        if (borrador) armar(borrador, {});
        estado.armadaTocada = false;
    }

    function abrirHueco(boton, filtro) {
        var api = apiSelector();
        var c = estado.armada;
        if (!api || !c) return;
        var i = parseInt(boton.getAttribute('data-ia-hueco'), 10);
        var p = c.partes[i];
        if (!p) return;
        api.elegir(boton, {
            rotulo: p.rotulo,
            valor: p.valor,
            catalogo: p.catalogo || null,
            opciones: p.opciones || (p.campo === 'periodo' ? OPCIONES_PERIODO : null)
        }, function (op) { aplicarEleccion(p, op); }, filtro);
    }

    /**
     * Un valor elegido en un hueco. La intención la puede cambiar la cosa
     * (de «todo el restaurante» a un platillo: de ventas totales a ventas de
     * un platillo) salvo que el hueco la fije (el consumo); y otra cosa deja
     * sin sentido la opción del platillo anterior, que sale con su hueco.
     */
    function aplicarEleccion(p, op) {
        var c = estado.armada;
        if (!c || !op || c.partes.indexOf(p) === -1) return;
        var api = window.ConsultasRapidas;
        var cambio = api && typeof api.consultaDeOpcion === 'function'
            ? api.consultaDeOpcion(p, op)
            : (esObjeto(op.consulta) ? op.consulta : {});
        Object.keys(cambio).forEach(function (k) { c.consulta[k] = cambio[k]; });
        if (p.campo === 'entidad') {
            c.consulta.opcion = null;
            quitarHuecoOpcion(c);
        }
        p.valor = op.valor;
        p.etiqueta = op.valor === 'ventas_total' ? 'todo el restaurante' : String(op.etiqueta || '');
        estado.armadaTocada = true;
        pintarConstructor();
        sincronizarBase();
        actualizarCompositor();
        enfocarHueco(p.campo);
    }

    function quitarHuecoOpcion(c) {
        for (var i = 0; i < c.partes.length; i++) {
            if (c.partes[i].tipo === 'hueco' && c.partes[i].campo === 'opcion') {
                var desde = i > 0 && c.partes[i - 1].tipo === 'texto' && c.partes[i - 1].texto === 'con' ? i - 1 : i;
                c.partes.splice(desde, i - desde + 1);
                return;
            }
        }
    }

    /** Envía la pregunta armada. Se queda en el compositor, lista para variarla. */
    function enviar() {
        var c = estado.armada;
        var actual = activa();
        if (!c || (actual && estado.pendientes[actual.id])) return;
        if (preguntaIncompleta()) {
            var vacio = el.armadoFrase.querySelector('.ia-hueco.is-vacio');
            if (vacio) {
                vacio.focus();
                anunciar('Falta elegir ' + (vacio.textContent || '').replace(/^elige\s+/i, '') + ' antes de enviar.');
            }
            return;
        }
        estado.armadaTocada = false;
        // `generada`: lo que se ve como pregunta es la frase armada hasta que
        // el motor devuelve su texto canónico, que la reemplaza.
        if (preguntar(textoDeBorrador(c), c.consulta, true)) rescatarFoco();
    }

    /** La lista de preguntas base, sobre el botón de la pregunta. */
    function abrirBases() {
        var api = apiSelector();
        if (!api || !el.bases || el.bases.hidden || !catalogo.length) return;
        api.elegir(el.bases, {
            rotulo: 'Pregunta',
            valor: estado.armada && estado.armada.consulta ? estado.armada.consulta.intencion : null,
            buscar: false,
            ancho: 400,
            opciones: catalogo.map(function (c) {
                return { valor: c.intencion, etiqueta: c.titulo, detalle: c.descripcion, grupo: c.tema, icono: c.icono, borrador: c.borrador };
            })
        }, function (op) {
            var elegida = op ? basePorIntencion(op.valor) : null;
            var borrador = elegida ? borradorAlAzar(elegida) : (op && op.borrador);
            if (borrador) armar(borrador, { tocada: true, enfocar: true, anunciar: true, origen: null });
        });
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

    /**
     * Una pregunta hecha desde un renglón o una sugerencia de la tarjeta se
     * enseñó con el nombre pulsado mientras llegaba la respuesta; con ella ya
     * aquí, se reescribe con lo que el motor entendió («Ventas de Chilaquiles
     * esta semana»), que es lo que de verdad se preguntó.
     */
    function reescribirPregunta(c, datos) {
        var pregunta = c.mensajes[c.mensajes.length - 1];
        if (!pregunta || pregunta.rol !== 'usuario' || !pregunta.generada) return;
        var texto = datos && datos.interpretacion && esTexto(datos.interpretacion.texto) ? datos.interpretacion.texto : '';
        if (!texto) return;
        pregunta.texto = texto.slice(0, MAX_TEXTO);
        delete pregunta.generada;
        // Si era la primera, también es el título de la conversación.
        if (c.mensajes[0] === pregunta) {
            c.titulo = derivarTitulo(pregunta.texto);
            if (c.id === estado.activaId) pintarTitulo();
        }
        var burbuja = el.mensajes.querySelector('[data-ia-msg="' + pregunta.id + '"] .ia-msg__burbuja');
        if (burbuja) burbuja.textContent = pregunta.texto;
    }

    function terminar(id, pendiente, resultado) {
        // Otra petición tomó su lugar (se detuvo, o la conversación se
        // borró): esta respuesta ya no le pertenece a nadie.
        if (estado.pendientes[id] !== pendiente) return;
        delete estado.pendientes[id];
        window.clearTimeout(pendiente.relojLento);

        var c = buscar(id);
        if (!c) {
            pintarHistorial();
            return;
        }

        var m = {
            id: nuevoId('m'),
            rol: 'asistente',
            tipo: resultado.tipo,
            texto: resultado.tipo === 'consulta' ? resumenDe(resultado.datos) : resultado.texto,
            fecha: Date.now()
        };
        if (resultado.tipo === 'consulta') {
            m.datos = resultado.datos;
            reescribirPregunta(c, resultado.datos);
        }
        c.mensajes.push(m);
        recortar(c);
        c.actualizada = m.fecha;
        var esActiva = id === estado.activaId;
        if (!esActiva) c.noLeida = true;
        ordenar();
        guardar();

        if (esActiva) {
            quitarNodo('[data-ia-pensando]');
            el.mensajes.removeAttribute('aria-busy');
            var nodo = nodoMensaje(m, true);
            el.mensajes.appendChild(nodo);
            // La pregunta contestada pasa al compositor con la forma que le dio
            // el motor (con el hueco de la opción, si el platillo tiene), salvo
            // que se haya tocado mientras se esperaba.
            if (resultado.tipo === 'consulta' && !estado.armadaTocada) {
                var contestada = borradorDeRespuesta(resultado.datos);
                if (contestada) armar(contestada, {});
            }
            actualizarCompositor();
            if (estado.pegadoAlFinal) revelarRespuesta(nodo);
            else actualizarIrFinal();
            anunciar(textoAnunciable(m));
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
        var pendiente = { controlador: controlador, inicio: Date.now(), lento: false, relojLento: null };
        estado.pendientes[id] = pendiente;
        pendiente.relojLento = window.setTimeout(function () {
            pendiente.lento = true;
            if (id === estado.activaId) actualizarCompositor();
        }, UMBRAL_LENTO);

        if (id === estado.activaId) {
            quitarNodo('[data-ia-sin-respuesta]');
            el.mensajes.appendChild(nodoPensando(true));
            el.mensajes.setAttribute('aria-busy', 'true');
            actualizarCompositor();
        }
        pintarHistorial();
        arrancarReloj();

        function alLlegar(resultado) {
            var resto = PAUSA_MINIMA - (Date.now() - pendiente.inicio);
            if (resto > 0) window.setTimeout(function () { terminar(id, pendiente, resultado); }, resto);
            else terminar(id, pendiente, resultado);
        }

        consultar(ultima, controlador ? controlador.signal : undefined).then(
            alLlegar,
            function (fallo) { alLlegar(resultadoDeFallo(fallo)); }
        );
    }

    /**
     * Una pregunta nueva en la conversación activa. `texto` es lo que se ve;
     * `peticion`, la consulta estructurada que se manda al motor. Con
     * `generada`, el texto se reemplaza por el canónico del motor al llegar la
     * respuesta.
     */
    function preguntar(textoCrudo, peticion, generada) {
        var texto = String(textoCrudo || '').replace(/^\s+|\s+$/g, '');
        if (!texto) return false;
        if (texto.length > MAX_TEXTO) texto = texto.slice(0, MAX_TEXTO);

        var c = activa();
        if (c && estado.pendientes[c.id]) return false;

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
            // La pregunta del compositor pasa a ser la de esta conversación.
            if (estado.armadas['']) estado.armadas[c.id] = estado.armadas[''];
            delete estado.armadas[''];
            // Una más que el tope: se va la más antigua (lo dice el pie del
            // historial). guardar() también recorta, pero aquí se avisa.
            if (recortarConversaciones()) anunciar('Se borró la conversación más antigua para guardar ésta.');
        }

        var m = {
            id: nuevoId('m'),
            rol: 'usuario',
            tipo: 'pregunta',
            texto: texto,
            fecha: ahora,
            peticion: normalizarPeticion(peticion)
        };
        if (generada) m.generada = true;
        c.mensajes.push(m);
        recortar(c);
        c.actualizada = ahora;
        ordenar();
        guardar();

        cambiarModo(true, true);
        quitarNodo('[data-ia-sin-respuesta]');
        el.mensajes.appendChild(nodoMensaje(m, true));
        pintarTitulo();
        pintarHistorial();
        pedirRespuesta(c.id);
        actualizarCompositor();
        irAlFinal(true);
        return true;
    }

    function detener() {
        var c = activa();
        var pendiente = c && estado.pendientes[c.id];
        if (!pendiente) return;
        if (pendiente.controlador) pendiente.controlador.abort();
        // Se cierra aquí y no al llegar el rechazo de la petición: si la
        // respuesta ya había llegado y sólo esperaba la pausa mínima, abortar
        // no produce ningún rechazo y la respuesta saldría igual.
        terminar(c.id, pendiente, { tipo: 'detenida', texto: TEXTOS.detenida });
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
        estado.activaId = id;
        almacen.escribir(CLAVE_ACTIVA, id);
        if (c.noLeida) {
            c.noLeida = false;
            guardar();
        }
        pintarHistorial();
        pintarHilo(true);
        restaurarArmada();
        if (enCajon()) cerrarCajon(false);
        enfocarTrasCambio();
    }

    function nuevaConversacion() {
        if (estado.activaId) {
            estado.activaId = null;
            almacen.borrar(CLAVE_ACTIVA);
            pintarHistorial();
            pintarHilo(true);
            restaurarArmada();
        }
        if (enCajon()) cerrarCajon(false);
        enfocarCompositor();
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
            window.clearTimeout(pendiente.relojLento);
            if (pendiente.controlador) pendiente.controlador.abort();
        }
        estado.borradas[id] = true;
        estado.conversaciones = estado.conversaciones.filter(function (c) { return c.id !== id; });
        guardar();

        if (estado.activaId === id) {
            // La pregunta del compositor se queda: se borró la conversación,
            // no lo que se estaba armando.
            estado.armadas[''] = estado.armada;
            estado.activaId = null;
            almacen.borrar(CLAVE_ACTIVA);
            pintarHilo(true);
        }
        delete estado.armadas[id];
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
        // Etiqueta fija y el estado sólo en aria-expanded: cambiar las dos a
        // la vez se leía «Ocultar el historial, expandido», dos veces lo mismo.
        el.toggle.setAttribute('aria-expanded', historialVisible() ? 'true' : 'false');
    }

    /**
     * Con el cajón abierto, la columna del chat queda detrás del velo. La
     * trampa de Tab sólo cubre el teclado; el cursor virtual de un lector de
     * pantalla seguiría leyendo el hilo de detrás como si estuviera a la vista.
     * `inert` lo saca de los dos. La región viva vive fuera de esta columna
     * (ver la vista) para que los anuncios sigan llegando mientras tanto.
     */
    function aislarChat(aislado) {
        el.chat.inert = !!aislado;
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

    // La frase armada se describe sólo con qué contesta. La pista de teclas
    // («Enter cambia un valor · Ctrl Enter envía») se retiró de la caja; los
    // atajos los anuncian los aria-keyshortcuts de los botones.
    function sincronizarPista() {
        el.armadoFrase.setAttribute('aria-describedby', 'ia-constructor-ayuda');
    }

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
        // El tope vale también para lo que llegó de la otra pestaña.
        recortarConversaciones();

        var ahora = activa();
        if (estado.activaId && !ahora) {
            // La borraron en la otra pestaña.
            estado.activaId = null;
            estado.armadas[''] = estado.armada;
            pintarHilo(false);
        } else if (ahora && !estado.pendientes[ahora.id]) {
            var firma = ahora.mensajes.length + ':' + ahora.actualizada;
            // Sólo se repinta si cambió: repintar en cada escritura ajena
            // movería el scroll de quien está leyendo.
            if (firma !== firmaAnterior) pintarHilo(false);
        }
        pintarHistorial();
    });

    /* ── Eventos ────────────────────────────────────────────────── */

    el.form.addEventListener('submit', function (evento) {
        evento.preventDefault();
        enviar();
    });

    // Ctrl/⌘+Enter envía desde cualquier punto del compositor. Enter a secas
    // NO: sobre un hueco abre su lista, que es lo que hace un botón, y sobre
    // el de enviar ya envía por sí solo.
    el.form.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Enter' || !(evento.ctrlKey || evento.metaKey) || evento.isComposing) return;
        evento.preventDefault();
        enviar();
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
        if (repetir) {
            reintentar(repetir.getAttribute('data-ia-reintentar'));
            return;
        }
        var seguir = evento.target.closest('[data-ia-preguntar]');
        if (seguir) {
            var peticion = leerJson(seguir.getAttribute('data-ia-peticion'));
            // Una consulta estructurada se ve como el nombre pulsado hasta que
            // el motor devuelve su texto canónico.
            preguntar(seguir.getAttribute('data-ia-texto'), peticion, !!(peticion && peticion.intencion));
            return;
        }
        var cargar = evento.target.closest('[data-ia-cargar]');
        if (cargar) {
            var base = basePorIntencion(cargar.getAttribute('data-ia-cargar'));
            if (base) armar(borradorAlAzar(base), { origen: cargar, tocada: true, enfocar: true, anunciar: true });
        }
    });

    // En captura, antes que la tarjeta del motor: guarda el nombre de lo que
    // se pulsó para enseñarlo como pregunta (ver alCambiarTarjeta).
    el.mensajes.addEventListener('click', function (evento) {
        var pulsado = evento.target.closest('[data-consulta-ir]');
        if (pulsado) ultimoPulsado = (pulsado.textContent || '').replace(/\s+/g, ' ').trim();
    }, true);

    // Una pregunta base del catálogo se ARMA en el compositor; no consulta.
    // Cada vez con un ejemplo distinto, también al volver a pulsar la marcada:
    // así se descubre que el hueco admite cualquier platillo o periodo.
    if (el.catalogo) {
        el.catalogo.addEventListener('click', function (evento) {
            var base = evento.target.closest('[data-ia-base]');
            if (!base) return;
            var datos = basePorIntencion(base.getAttribute('data-ia-base'));
            armar(datos ? borradorAlAzar(datos) : leerJson(base.getAttribute('data-ia-borrador')), { origen: base, tocada: true, enfocar: true, anunciar: true });
        });
    }

    el.armadoFrase.addEventListener('click', function (evento) {
        var hueco = evento.target.closest('[data-ia-hueco]');
        if (hueco) abrirHueco(hueco);
    });

    // Sobre un hueco, además de Enter y Espacio (que son del botón): flecha
    // abajo lo abre, como un <select>, y en los que tienen buscador una letra
    // lo abre ya filtrado: «Tab, lec» en vez de «Tab, Enter, lec». La regla
    // es la del motor, para que un hueco responda igual aquí y en la tarjeta.
    el.armadoFrase.addEventListener('keydown', function (evento) {
        var hueco = evento.target.closest && evento.target.closest('[data-ia-hueco]');
        var api = window.ConsultasRapidas;
        if (!hueco || evento.isComposing || !api || typeof api.teclaDeHueco !== 'function') return;
        var tecla = api.teclaDeHueco(evento, hueco);
        if (tecla === null) return;
        evento.preventDefault();
        abrirHueco(hueco, tecla);
    });

    // El botón de la pregunta sólo si hay de dónde elegir y con qué: sin la
    // API del selector sería un botón que no abre nada.
    if (el.bases) {
        el.bases.addEventListener('click', abrirBases);
        el.bases.addEventListener('keydown', function (evento) {
            if (evento.key !== 'ArrowDown' || evento.ctrlKey || evento.metaKey) return;
            evento.preventDefault();
            abrirBases();
        });
    }

    function hayCapaAbierta() {
        return !!document.querySelector(
            '.confirmation-modal:not([hidden]), .admin-modal:not([hidden]), #admin-inbox-drawer:not([hidden])'
        ) || document.body.classList.contains('is-sidebar-open');
    }

    // Ctrl/⌘+K abre la lista de preguntas desde cualquier punto de la
    // pantalla, como la paleta de comandos de las herramientas que el admin ya
    // usa. Con un diálogo abierto no se roba el foco. Ya no hay «/»: llevaba
    // a un campo de texto que no existe.
    document.addEventListener('keydown', function (evento) {
        if (evento.defaultPrevented) return;
        var tecla = evento.key;
        if ((evento.ctrlKey || evento.metaKey) && !evento.altKey && !evento.shiftKey && (tecla === 'k' || tecla === 'K')) {
            if (hayCapaAbierta()) return;
            evento.preventDefault();
            // Con el cajón abierto el compositor está inerte: se aparta.
            cerrarCajon(false);
            abrirBases();
            return;
        }
        if (tecla === 'Escape' && enCajon() && raiz.classList.contains('is-historial-abierto')) {
            cerrarCajon(true);
        }
    });

    /* ── Arranque ───────────────────────────────────────────────── */

    function iniciar() {
        estado.conversaciones = estado.memoriaSola ? [] : leerConversaciones();
        // Lo guardado de antes del tope se recorta al cargar, y se reescribe
        // para que el almacenamiento diga lo mismo que la lista.
        if (recortarConversaciones()) guardar();

        // Se retoma la última conversación sólo si es de hoy: volver mañana y
        // caer en medio de la charla de ayer es peor que un saludo limpio. La
        // misma regla corre en línea en la vista, antes de la primera pintura.
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
        pintarHilo(false);
        // Sin foco automático: el compositor son botones, y enfocar el primero
        // al cargar le pintaría el anillo antes de que nadie toque nada.
        restaurarArmada();
        if (!apiSelector()) {
            // Sin el selector del motor no hay de dónde elegir valores: la
            // pregunta inicial se puede enviar, pero no cambiar.
            if (el.bases) el.bases.hidden = true;
        }
    }

    iniciar();
}(window, document));
