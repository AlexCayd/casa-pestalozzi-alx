/*
 * Contrato de la hoja de opciones por pasos del POS (punto-de-venta.js,
 * «Opciones de un platillo»).
 *
 * Ejecuta en un contexto aislado las funciones que arman el marcado —un paso,
 * la fila de pasos, el pie y el botón de agregar— con datos de CP_MENU, sin
 * DOM: son cadenas. Lo demás (transición, foco, teclado) se comprueba por su
 * presencia en el código, porque sin navegador no hay cómo ejecutarlo.
 *
 * Lo que protege: un paso por grupo; el icono op-* o la inicial en el mismo
 * hueco; «Siguiente» apagado sólo si el paso visible es obligatorio y falta;
 * el camino corto a «Agregar» cuando no falta nada; el botón que lleva al paso
 * que falta en vez de apagarse; y el precio orientativo de siempre.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(root, 'src/js/modules/punto-de-venta.js');
const source = fs.readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');

let comprobaciones = 0;
function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
  comprobaciones++;
}

// Recorta `function nombre(...) { ... }` contando llaves y saltando cadenas y
// comentarios. Las funciones extraídas no llevan expresiones regulares.
function extraer(nombre) {
  const inicio = source.indexOf(`  function ${nombre}(`);
  assert(inicio !== -1, `existe function ${nombre}()`);
  let i = source.indexOf('{', inicio);
  let nivel = 0;
  for (; i < source.length; i++) {
    const c = source[i];
    if (c === '/' && source[i + 1] === '/') { i = source.indexOf('\n', i); continue; }
    if (c === '/' && source[i + 1] === '*') { i = source.indexOf('*/', i) + 1; continue; }
    if (c === "'" || c === '"' || c === '`') {
      for (i++; i < source.length && source[i] !== c; i++) if (source[i] === '\\') i++;
      continue;
    }
    if (c === '{') nivel++;
    if (c === '}' && --nivel === 0) return source.slice(inicio, i + 1);
  }
  throw new Error(`no se pudo recortar ${nombre}`);
}

const funciones = [
  'dineroCorto', 'resumenOpciones', 'opcionElegida', 'primerPasoFaltante',
  'botonConfirmarOpciones', 'iconoOpcionHtml', 'pasoOpcionesHtml',
  'pasosOpcionesHtml', 'pieOpcionesHtml', 'eyebrowOpciones',
].map(extraer).join('\n');

const contexto = {
  opcionesHoja: null,
  commandaItems: [],
  window: {
    AdminIcons: {
      get(nombre, tam) { return /^op-/.test(nombre) ? `<svg data-icono="${nombre}" width="${tam}"></svg>` : ''; },
    },
  },
  escHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  svgIcon: (nombre) => `<svg data-svg="${nombre}"></svg>`,
  btnLabel: (icono, texto) => `<span data-btn-icono="${icono}">${texto}</span>`,
};
vm.createContext(contexto);
vm.runInContext(funciones, contexto, { filename: sourcePath });
const f = (nombre, ...args) => vm.runInContext(nombre, contexto)(...args);

function hoja(dish, seleccion, extra) {
  const h = Object.assign({ dish, ctx: {}, seleccion, paso: 0, visitados: { 0: true } }, extra || {});
  contexto.opcionesHoja = h;
  return h;
}

const cappuccino = {
  n: 'Cappuccino', p: 55,
  g: [
    { n: 'Leche', req: false, o: [
      { id: 1, n: 'Normal', d: 0, def: true, i: 'op-leche' },
      { id: 2, n: 'Avena', d: 8, def: false, i: 'op-planta' },
      { id: 3, n: 'Sin leche', d: 0, def: false, i: 'op-sin' },
    ] },
    { n: 'Tamaño', req: false, o: [
      { id: 4, n: 'Regular', d: 0, def: true, i: 'op-tamano-mediano' },
      { id: 5, n: 'Grande', d: 15, def: false, i: 'op-tamano-grande' },
    ] },
    { n: 'Término', req: true, o: [
      { id: 6, n: 'Tibio', d: 0, def: false, i: null },
      { id: 7, n: 'Ardiente', d: 0, def: false, i: 'desconocido' },
    ] },
  ],
};

// ── Paso 1 con un obligatorio pendiente en el 3 ──────────────
let h = hoja(cappuccino, { 0: 1, 1: 4, 2: null });
let r = f('resumenOpciones');
assert(f('eyebrowOpciones', h) === 'Paso 1 de 3', 'la cabecera dice el paso y el total');

let paso = f('pasoOpcionesHtml', h, 0);
assert(paso.includes('data-opts-paso="0"'), 'el paso lleva su índice');
assert(paso.includes('Paso 1 de 3: </span>'), 'la etiqueta del grupo anuncia el paso al lector de pantalla');
assert(paso.includes('role="radiogroup" aria-labelledby="mmodal-opts-g0"'), 'las opciones son un radiogroup etiquetado por el grupo');
assert((paso.match(/role="radio"/g) || []).length === 3, 'una tesela por opción del grupo, y sólo las de ese grupo');
assert(paso.includes('data-icono="op-leche"') && paso.includes('data-icono="op-planta"'), 'cada opción pinta su icono op-*');
assert(/aria-checked="true" tabindex="0"[^>]*data-opt-id="1"/.test(paso), 'la predeterminada nace elegida y es el tope de tabulador');
assert(/aria-checked="false" tabindex="-1"[^>]*data-opt-id="2"/.test(paso), 'las demás quedan fuera del tabulador (tabindex móvil)');
assert(paso.includes('+$8'), 'el ajuste de precio de la opción se ve en la tesela');
assert(paso.includes('mmodal-opts__pill-marca'), 'la elección también se marca con forma (palomita)');

let pasos = f('pasosOpcionesHtml', h);
assert((pasos.match(/<li>/g) || []).length === 3, 'la fila tiene un paso por grupo');
assert(/is-actual" data-opts-ir="0" aria-current="step" tabindex="0"/.test(pasos), 'el paso actual lleva aria-current y es el único tope de tabulador');
assert(/is-pendiente" data-opts-ir="1"[^>]*tabindex="-1"/.test(pasos), 'un paso no visitado con predeterminada queda pendiente, con número');
assert(/is-falta" data-opts-ir="2"/.test(pasos) && pasos.includes('Elige una'), 'el obligatorio sin elegir se marca y lo dice con texto');
assert(pasos.includes('>Normal<') && pasos.includes('>Regular<'), 'cada paso dice lo que lleva elegido');

let pie = f('pieOpcionesHtml', h, r);
assert(pie.includes('data-opts-cerrar>Cancelar'), 'en el primer paso la izquierda es «Cancelar»');
assert(/data-opts-siguiente>/.test(pie), '«Siguiente» activo: el paso visible ya tiene su elección');
assert(!pie.includes('data-opts-confirmar'), 'mientras falta un obligatorio no se ofrece el camino corto');
assert(!pie.includes('acciones--tres'), 'dos botones, no tres');
assert(pie.includes('Como en la carta'), 'el resumen dice que va como en la carta');

// ── Paso 3 (último) sin elegir ───────────────────────────────
h = hoja(cappuccino, { 0: 2, 1: 5, 2: null }, { paso: 2, visitados: { 0: true, 1: true, 2: true } });
r = f('resumenOpciones');
pie = f('pieOpcionesHtml', h, r);
assert(pie.includes('data-opts-atras'), 'después del primero la izquierda es «Atrás»');
assert(/mmodal-btn--primary mmodal-opts__confirmar" data-opts-confirmar disabled/.test(pie), 'en el último paso, si falta ESE paso, agregar se apaga');
assert(pie.includes('Elige término') && pie.includes('$78'), 'dice qué falta y el precio con lo elegido (55 + 8 + 15)');
pasos = f('pasosOpcionesHtml', h);
assert(/is-hecho" data-opts-ir="0"/.test(pasos) && pasos.includes('data-svg="check"'), 'un paso visto lleva palomita');
assert(pie.includes('Avena · Grande'), 'el resumen junta lo que cambia');
paso = f('pasoOpcionesHtml', h, 2);
assert(paso.includes('mmodal-opts__grupo is-falta'), 'el paso obligatorio sin elegir se marca');
assert(paso.includes('mmodal-opts__pill-icono--inicial" aria-hidden="true">T<'), 'sin icono va la inicial en el mismo hueco');
assert(paso.includes('mmodal-opts__pill-icono--inicial" aria-hidden="true">A<'), 'un icono que el catálogo no conoce también cae a la inicial');

// ── Lo que falta está en OTRO paso ───────────────────────────
const dosObligatorios = {
  n: 'Hamburguesa', p: 180,
  g: [
    { n: 'Pan', req: true, o: [{ id: 10, n: 'Brioche', d: 0, def: false, i: 'op-pan' }, { id: 11, n: 'Integral', d: 5, def: false, i: 'op-pan' }] },
    { n: 'Término', req: true, o: [{ id: 12, n: 'Medio', d: 0, def: false, i: 'op-termino-medio' }, { id: 13, n: 'Bien cocido', d: 0, def: false, i: 'op-termino-bien' }] },
  ],
};
h = hoja(dosObligatorios, { 0: null, 1: 12 }, { paso: 1, visitados: { 0: true, 1: true } });
r = f('resumenOpciones');
pie = f('pieOpcionesHtml', h, r);
assert(/data-opts-confirmar data-opts-ir="0">/.test(pie), 'si falta un paso anterior, el botón lleva a él en vez de apagarse');
assert(pie.includes('Elige pan') && pie.includes('desde $180'), 'y dice qué falta con el precio mínimo');
h = hoja(dosObligatorios, { 0: null, 1: null });
pie = f('pieOpcionesHtml', h, f('resumenOpciones'));
assert(/data-opts-siguiente disabled/.test(pie), '«Siguiente» se apaga si el paso visible es obligatorio y falta');

// ── Nada falta: camino corto desde el primer paso ────────────
h = hoja(cappuccino, { 0: 1, 1: 4, 2: 6 });
r = f('resumenOpciones');
pie = f('pieOpcionesHtml', h, r);
assert(pie.includes('mmodal-opts__acciones--tres'), 'Atrás · Agregar · Siguiente');
assert(/mmodal-btn--outline mmodal-opts__confirmar" data-opts-confirmar>/.test(pie), 'agregar va en contorno junto a «Siguiente»');
assert(pie.indexOf('data-opts-confirmar') < pie.indexOf('data-opts-siguiente'), '«Siguiente» queda al final, donde vive la principal');
assert(pie.includes('>Agregar</span> <span class="mmodal-opts__precio">$55<'), 'agregar con el precio base: las predeterminadas no suman');

// ── Editar una línea con dos unidades ────────────────────────
contexto.commandaItems = [{ n: 'Cappuccino', qty: 2 }];
h = hoja(cappuccino, { 0: 2, 1: 4, 2: 7 }, { ctx: { editIdx: 0, ids: [2, 4, 7] } });
r = f('resumenOpciones');
assert(f('eyebrowOpciones', h) === 'Cambiar opciones · Paso 1 de 3', 'al editar la cabecera lo dice');
pie = f('pieOpcionesHtml', h, r);
assert(pie.includes('>Guardar</span>') && pie.includes('2 × $63'), 'guardar con el multiplicador de la línea');
contexto.commandaItems = [];

// ── Un solo grupo: sin ceremonia ─────────────────────────────
const unGrupo = { n: 'Arrachera', p: 320, g: [dosObligatorios.g[1]] };
h = hoja(unGrupo, { 0: null });
assert(f('eyebrowOpciones', h) === 'Opciones', 'con un grupo no hay «Paso 1 de 1»');
assert(f('pasosOpcionesHtml', h) === '', 'ni fila de pasos');
assert(!f('pasoOpcionesHtml', h, 0).includes('Paso 1'), 'ni anuncio de paso');
pie = f('pieOpcionesHtml', h, f('resumenOpciones'));
assert(/mmodal-btn--primary mmodal-opts__confirmar" data-opts-confirmar disabled/.test(pie), 'su único paso es el último: agregar, apagado hasta elegir');

// ── Lo que no se ejecuta aquí, por su presencia ──────────────
const tecla = extraer('teclaOpciones');
assert(tecla.includes("e.key === 'Backspace'") && tecla.includes('irAPasoOpciones(h.paso - 1)'), 'Retroceso vuelve al paso anterior');
assert(/e\.key === 'Enter' && pill[\s\S]*irAPasoOpciones\(gi \+ 1\)[\s\S]*confirmarOpciones\(\)/.test(tecla), 'Enter avanza y, en el último paso, agrega');
assert(tecla.includes("elegirOpcion(Number(siguiente.getAttribute('data-opt-g'))") && tecla.includes(', false);'), 'las flechas eligen sin avanzar');
assert(tecla.includes(".filter(function(b) { return !b.closest('.is-saliendo'); })"), 'la trampa de Tab ignora el paso que sale');
assert(source.includes('e.detail > 0);'), 'sólo el clic o toque avanza solo (Espacio llega como clic con detail 0)');
assert(/function sinMovimientoOpciones\(\)[\s\S]{0,200}prefers-reduced-motion: reduce/.test(source), 'el cambio de paso respeta movimiento reducido');
const ir = extraer('irAPasoOpciones');
assert(ir.includes('detenerCambioOpciones(h);') && ir.includes(".animate("), 'el cambio de paso es interrumpible (Web Animations)');
assert(ir.includes("vieja.inert = true"), 'el paso que sale queda inerte');
const seccion = source.slice(source.indexOf('// ── Opciones de un platillo (variantes)'), source.indexOf('function addSuggestionItem('));
assert(!/\b(alert|confirm|prompt)\(/.test(seccion), 'sin diálogos nativos');

console.log(`POS · opciones por pasos: ${comprobaciones} comprobaciones OK`);
