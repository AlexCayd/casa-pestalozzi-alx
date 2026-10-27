# Guía técnica de interfaz

El acceso administrativo tiene dos pasos: contraseña y código, sin acceso al
panel mientras el reto está pendiente. El recuadro de código solo existe en
`development`/`test` explícitos; consultar [credenciales](../usuarios/credenciales.md).
El modal de caja permite turno vigente o día; su ventana y totales se rigen por
[operación](../operacion.md#corte-de-caja-por-turnos). El sitio público de
Panadería solo comprueba navegación y no representa una tienda liberada.

Referencia subordinada a [Arquitectura](../arquitectura.md). Conserva los contratos
visuales y componentes compartidos; no concede permisos ni declara módulos funcionales.
La marca se apoya en el [manual de identidad](../../Manual%20de%20Identidad%20Casa%20Pestalozzi%20Draft.pdf).
Consultar [PRODUCT](../../PRODUCT.md) para propósito, usuarios y accesibilidad.

## Sistemas visuales y tokens

Landing: identidad de restaurante. Panel, POS, KDS y login: sistema administrativo.
Global y Panadería reutilizan el panel de Restaurante. Feedback combina colores
de marca con tipografía administrativa.

| Fuente | Contrato |
|---|---|
| `src/scss/layout/_reset.scss` | Marca, escalas y roles públicos por tono |
| `src/scss/admin/shared/base/_globals.scss` | Roles administrativos y temas |
| `src/scss/admin/shared/abstracts/_variables.scss` | Sass estructural, no color |

Marca: verde `#214F36`, oro `#D1AB66`, beige `#e3d5bb`, crema `#F5F1E8`.
Panel: grises derivados de `--admin-ink: #0b0b0c` y `--admin-paper: #fbfbfa`;
`--admin-gris-0` siempre es fondo y `-11` tinta. Consumir roles, sin nuevos
hex/rgba fuera de los archivos de tokens. Excepciones: datos de área en BD,
espejo PHP de PaletaLanding y respaldos de gráficas.

Marca → escalas color-mix → roles. Separar acento de relleno, acento legible como
texto y tinta sobre acento. Oro sobre crema no sirve como texto. Texto tenue:
4.5:1 mínimo; bordes de componente/foco: 3:1. Recalcular al cambiar fondo.
`--scrim` es velo oscuro sobre foto, no fondo de tema.

Tonos públicos: crema, beige, oro, verde y foto; cafe/vino son alias de oro.
Cada sección tiene tono distinto de su vecina. Incluir alias dentro de cada
tono/tema: las referencias CSS se resuelven donde se declaran.
`[data-modo="oscuro"]` conserva soporte público, sin pantalla activa.
El panel usa `[data-admin-theme="light|dark"]`; el layout aplica preferencia
antes del primer paint. POS, áreas y login fuerzan oscuro sin conmutador.

`puente-tokens-publicos` mapea roles públicos a admin solo en mapa, área, login
y shell operativo. No añadir el inverso: produce ciclos y tokens vacíos.
En panel usar `--admin-on-accent`, no `--on-accent` público.
ConfirmationModal/AppNotice tienen roles propios (`--confirmation-*`,
`--app-notice-*`) declarados en ambos sistemas; cada token nuevo existe en ambos.
Separar rojo de relleno y rojo legible como texto. Destructivo: rojo sólido con
`--admin-on-danger` en ambos temas.

Panel neutro, sin filetes laterales de color. Color funcional significa estado,
serie o disparador de acción. Botones tinted usan relleno sólido y tinta calculada
por contraste; acción principal conserva jerarquía, icono y posición final.
Paleta funcional compartida: ámbar, naranja, rojo, azul, lima, índigo, rosa,
magenta, turquesa y verde. Platillos: preparación ámbar, listo verde, entregado
azul, cancelado gris. El KDS tiene su excepción visual descrita abajo.
PaletaLanding emite los cuatro colores editables después de app.css en cada
página de marca. Mantener su espejo y comprobaciones de contraste sincronizados.

## Tipografía

| Rol | Familia y uso |
|---|---|
| Landing `--serif` | The Seasons 400/700, títulos y acentos |
| Landing `--sans` | TT Wellingtons 300/400/700, cuerpo y rótulos |
| Marca `--logo` / `--admin-logo` | KudosKaps, wordmark, peso explícito 400 |
| Admin `--admin-font-sans` | Satoshi variable 300–900, cuerpo/componentes |
| Admin `--admin-font-titular` | Expose 600, solo titular de página/KDS |
| Admin `--admin-font-cifra` | Expose 600, indicadores y totales aislados |
| Admin `--admin-font-mono` | Geist Mono, rótulos, código y cifras de columna |

Panel sin serif. Aplicar titular por rol/clase, no h2 genérico:
`clamp(28px, 3vw, 36px)`, tracking −0.01em. Cuerpo sans sin tracking;
rótulos mono con .14em. `.admin-num` usa tabular-nums, no tablas enteras en mono.
Expose no tiene tnum: reservar ancho al animar cifras. No sintetizar caras;
servir redonda/itálica reales cuando existan, sin pedir itálica a Expose.

Fuentes locales: Satoshi/Expose y licencia FFL en assets/fonts; Geist Mono por
copyVendorFonts. Fuentes comerciales en assets/fonts/marca: the-seasons-regular/bold
y tt-wellingtons-light/regular/bold, woff2. No extraer subconjuntos del manual ni
publicar versiones de prueba. Sin licencia web/archivos se usa respaldo de sistema;
precargaFuente no emite precarga inexistente. Al retirar fuente buscar consumidores.

Landing: títulos regulares de una sola voz, sin br forzado ni cursivas. Acento por
color/subrayado de oro; em en párrafo es negrita. Eyebrows numerados en italiano,
navegación en español. PDF conserva Playfair/Montserrat TTF hasta tener fuentes
admitidas; isologo en trazos de views/admin/menu/_isologo.svg, generado por
scripts/isologo-pdf.py. Si cambia cara/tracking, regenerar y revisar feedback,
que reutiliza isologo y medallón del hero.

## Componentes administrativos

Reutilizar src/scss/admin/shared/components, views/admin/partials,
views/operation/partials y pickers en views/components/reservations.
Vocabulario: admin-page/card/panel, admin-btn, admin-table, admin-badge,
admin-pagination, admin-field, admin-switch, admin-tabs y admin-pills.

- Iconos PHP: admin_icon en _icons.php; JS: AdminIcons.get en core/icons.js.
  Sincronizar ambos catálogos; grosor por CSS. No emoji/glifos como icono.
  POS usa SVG_PATHS/svgIcon y btnLabel. Mantener separación con inline-flex/gap.
- admin-pills sustituye select para pocas opciones excluyentes: radios reales
  recortados, teclado y respaldo sin :has. No ocultar con display:none.
- Select mejorado: core/select.js, combobox etiquetado y lista portada al body.
  AdminSelect.init tras insertar contenido. Piso excluido salvo data-enhance;
  data-native conserva nativo. Revisar z-index al portarlo desde modal.
- Orden: data-sortable y th data-sort-type. Celdas compuestas, fechas o formularios
  requieren data-sort-value. AdminTableSort.reaplicar tras reemplazar contenido;
  admin:reactive-updated vuelve a enlazarlo.
- Paginación: _pagination.php, cinco páginas con elipsis. Métricas por consulta
  total independiente, no sumando solo filas de la página.
- Rango: _range-picker.php, core/range-picker.js y RangoPeriodo. Comparación siempre
  activa con periodo anterior equivalente; default retrospectivo 30 días;
  reservas usa resolverProspectivo. Evitar variable local dias después del include.
- Filtros/tareas: _filtros-estado.php y _tareas-completas.php. Filtro en URL,
  selección aria-current; tareas cuentan lo hecho y métricas independientes de
  búsqueda. Enlace estirado sin position/overflow/transform que reduzca su área.
- Modales alternan hidden e is-open. Prohibidos alert/confirm/prompt nativos.
  ConfirmationModal.get().open devuelve promesa de acción; customContent muestra
  dependencias y requireText exige leer el elemento (data-confirm-require en form).
  Diálogo especializado captura en document antes del genérico, preservando respaldo.
- AppNotice.show comunica errores con texto de respaldo. Ocultado diferido del
  modal se invalida al reabrir; duración desde --confirmation-out. No crear una
  instancia nueva por llamada.

.admin-body button/p/h1…h3 supera una clase sola: acotar bajo .admin-body.
Campos globales tienen alta especificidad; preferir contenedor flex para icono
y campo. No aplicar min-height genérico a radios. Comprobar reglas posteriores
antes de duplicar variantes/disabled. ActiveRecord comparte alertas estáticas:
no concatenar la misma lista obtenida desde dos modelos.

## Selector de contexto y Almacenes

`views/admin/partials/_contexto.php` muestra icono, nombre y chevron con el sidebar
expandido; en escritorio contraído conserva solo el icono centrado. El popover
usa la capa superior para evitar recortes del sidebar. Sus enlaces proceden de
`contextosDisponibles` del servidor y conservan `/admin/contexto` y la autorización
existente; el selector no concede acceso por sí mismo. `src/js/admin/core/contextos.js`
gestiona ArrowUp/ArrowDown, Home/End y Escape con devolución de foco al disparador.
Escape en el selector no cierra el drawer móvil. En móvil, incluido un viewport
de 320 px y una preferencia previa de escritorio contraído, el control recupera
nombre y chevron y el popover conserva margen respecto al viewport. El respaldo
`noscript` usa un select nativo etiquetado y botón de envío.

Almacenes reutiliza tokens, tipografías, iconos y modales administrativos sin una
biblioteca visual nueva. La pantalla única agrupa tarjetas de almacén por
propietario, ofrece selección múltiple con aplicación explícita y anuncia cambios
pendientes mediante `role="status"`. La protección cliente de la última casilla
complementa la validación del servidor; no la sustituye. Búsqueda, paginación e
historial conservan la selección. Configuración, catálogo e historial se muestran
en modales; las URLs anteriores con `vista` siguen abriendo el modal adecuado.

En Global, las barras usan magnitudes absolutas para la proporción y rayado para
negativos. El saldo neto exacto procede del backend; nunca se obtiene sumando
anchos o valores de coma flotante. Las etiquetas textuales y botones de detalle
siguen disponibles con cero. Los detalles se abren por hover, foco y pulsación,
admiten recorrerlos con el puntero y cierran con Escape. No depender del color
para identificar saldo negativo o alerta. El resumen cuenta artículos e
identidades, sin sumar unidades heterogéneas. El estado «Sin asociación en la
selección» mantiene visible la identidad maestra con saldo y mínimo cero.

Transferencias amplía esta misma interfaz en Restaurante, Panadería y Global:
`almacen-transferencias` usa el modal administrativo compartido y
`views/admin/almacenes/_transferencias.php`. Solicitud multilínea, documentos,
acciones e historial usan `details`/`summary`; los documentos pendientes aparecen
abiertos. Estado, origen/destino y propietario se expresan con texto. Las acciones
disponibles llegan del servidor según ámbito y estado; recepción, diferencia y
retorno conservan explicaciones distintas sobre su efecto en existencias.

Al agregar una línea, clonar controles nativos limpios, generar nombres/ids y
labels únicos e invocar `AdminSelect.init(row)` después de insertarla; enfocar el
combobox resultante o el select de respaldo. Al quitarla, devolver foco a
«Agregar artículo». La apertura inicial por `vista=transferencias` despacha
`admin:open-modal` desde el arranque de Almacenes en `DOMContentLoaded` (o de
inmediato con documento completo), para que el gestor compartido reciba la
apertura y gestione el foco. No adelantarla a la evaluación del bundle.

Cantidades documentales son cadenas decimales del backend: conservar solicitado,
enviado, recibido, retornado y tránsito sin recalcularlos con flotantes en JS.
Los campos admiten milésimas; recepción/retorno muestran el tránsito como límite
y ayuda por artículo. A 600 px o menos, las líneas del formulario pasan a una
columna y cada fila de la tabla documental se apila con etiquetas `data-label`;
el caption permanece visible, el encabezado se oculta y se elimina el ancho
mínimo de tabla. Mantener unidades y cantidades legibles en ese formato.

El [contrato de Almacenes](../modulos/almacenes.md) detalla selección autorizada,
límites de escritura y comandos de verificación. Revisar también solo lectura,
negativos, cero, teclado, temas y móvil sobre el harness desechable 3E.

## Piso, reservaciones y POS

Shell/header compartido: POS, KDS y selector de estación. Conservar geometría,
slots y banderas. Reservas se operan en POS: [guía](../reservaciones/reservaciones.md).
Cada pantalla conecta data-confirm-logout; no ofrecer regreso admin al piso.
Centro del header POS: Ahora/proyección, libres y próxima llegada; opciones
ocasionales en menú de usuario.

KDS: color de estación solo en filete superior de tres columnas. Todo lo demás
neutro, incluidos avances. Hora ámbar a 5 minutos, roja a 10; entregados atenuados
al 62%; notas hundidas sin ámbar. Mono cifras, sans palabras, pesos 400/600/700.
Columnas surface-soft sin borde, gap 16px; comanda surface-strong. No llevar color
de estación a la tarjeta donde compita con urgencia.

Cajón: módulos flex base cero; fecha española separada de data-iso. Sincronizar
tablas de nombres en pos-workspace.php y shell.js. Calendario abierto en flujo
para evitar recorte. Agenda administrativa: libreta diaria, hora una vez por
franja, estado con forma y palabra. Solo ausencia elegible es urgencia. Búsqueda
conserva periodo/estado; sincronizar nombres entre presenter y pos-reserva-vista.js.

Modal POS: título solo nombre; número adicional si no está incluido como palabra.
Cancelar mesa se oculta desde primera fila enviada, incluso cancelada. Cerrar se
deshabilita sin filas o con pendientes; backend revalida. Barras/Llevar: tablero,
alta y ticket con conmutador. Confirmar cambio con carrito sin enviar; cobrar o
descartar vuelve al tablero si quedan pedidos. Vidrio --pos-glass-* local,
panel 78%, columnas 64%; prima legibilidad. _voz.scss: mono rótulo/columna, sans
control, Expose titular/total. Danger sólido, release naranja, pending disabled
con borde discontinuo; una definición por variante.

Topbar: notificación roja por tarjetas pendientes, papel/punto azul si seguimiento;
reportar ámbar, salir neutro/rojo al hover. Contar tarjetas con
ReservacionBuzonBandejaService, no filas crudas. Campana se mueve al subir contador;
reduced-motion conserva señal de color. Colores solo por --tb-*.
Tickets usa totalFiltrado; abiertos ignora periodo, cerrados expresa venta.
Impresoras usa área y tinta por contraste, columna Imprime y prueba JSON en fila.
Corte de caja es consulta, no arqueo persistido: [operación](../operacion.md).
Asistente estructurado: [configuración](../config.md#asistente-ia).

## Landing, feedback y medios

- Hero editorial: isologo h1 accesible, texto centrado e ilustración SVG con
  --hero-*; escala contra columna/svh. Despejar botón de menú en móvil. Entrada
  CSS independiente de GSAP. Bucles cubren reduced-motion, no-anim y pausa fuera
  de vista. Marca/índice se retiran mientras cruza hero; boot funciona sin GSAP.
- Después del hero no añadir adornos. No insertar HTML con regex que corte PHP
  en el primer >. Sincronizar secciones, navDestinos, eyebrows y rail.
- Menú crema, destinos españoles sin número, pista solo hover/foco; ficha de hoy,
  dirección y teléfono desde SitioConfig. Foco al abrir, Escape devuelve foco;
  aria-expanded/etiqueta siguen estado. Dibujo activo solo con menú abierto.
- CTA btn-line solid/secondary con SVG y formato en etiqueta. Carta completa en
  Menú, maridaje en Catas. Carta web: una foto grande, producto o fallback categoría,
  sin miniaturas por fila. Escapar textos al usar innerHTML. Maridaje imprime
  todas las bebidas en servidor y conserva secciones sin JS.
- Pestañas accesibles con flechas/indicadores de desborde, arrastre e inercia;
  scroll-snap solo puntero grueso; capturar rueda solo mientras queda recorrido.
- Magnético único magnetic.js, translate separado de transform, puntero fino,
  tope 14×9px, rAF duerme en reposo; apagar con reduced-motion/no-anim.
- Feedback funciona sin JS con formulario/303. Token de un uso se aparta antes
  de insertar y se libera al fallar; usado/desconocido no se vuelve anónimo.
  Caras son radios reales, con estados de carga/éxito/error explícitos.
- Horario público duplicado en _reserva.php/_footer.php: sincronizar ambos.
  Excepción por fecha única; mostrar habitual tachado con rótulo, además del nuevo.
- Fotos con srcsetImagen, decoding async, lazy bajo pliegue; variantes por Gulp.
  CSS/JS usa recursoVersionado, sin versión fija bajo caché immutable.
- SEO usa SitioConfig::url en canonical/OG/robots/sitemap, solo datos reales.
  Token, login, feedback y 404 con noindex. Robots/sitemap son rutas.
- Lightbox con foco, teclado, zoom y gestos, View Transitions con fallback.
  Morfar caja de recorte, no imagen escalada; nombres únicos/pasajeros. currentSrc
  al abrir, original después sin mover caja; cerrar/cambiar resetea zoom.

## Movimiento, scroll y gráficas

Tema/módulo en _finishes.scss y llegada en _transitions.scss conviven por tipo.
Tema congela interpolaciones al capturar; módulo mueve solo contenido y nombra
durante navegación; llegada consume marca una vez, nunca desde prefetch.
Permitir salto y movimiento reducido. Curvas de pseudos View Transition resuelven
en html, donde no viven tokens de .admin-body. Login no enfoca inputmode numeric
por JS al tocar pad, para no abrir teclado del sistema.

Lenis requiere reglas CSS públicas y admin. No overflow-x:hidden en body;
revisar sticky/viewport. Contenedor con scroll: overflow-y auto, overscroll contain
y scrollbar de casa. Panel usa data-scrollable para medir desborde real;
no fijar data-lenis-prevent en tablas sin scroll vertical. Landing lo declara
en contenedores que sí requieren scroll propio. Reservar gutter para modales;
no mezclar barras WebKit y propiedades estándar que las sustituyen.
Partials repetidos limpian parámetros con unset. Tono bajo marca por geometría
de bandas directas de main/footer; data-tono-franja gana sobre contenedora.

Chart.js lee tokens literales resolubles, no color-mix sin resolver; repinta en
admin:themechange. Validar contraste/separación para daltonismo con herramienta
disponible. Series categóricas solo cuando identidad es dato; magnitud en un tono.
Sankey Finanzas es SVG propio: última etiqueta hacia dentro, separación medida,
ancho en píxeles para scroll y ambos overflow. ResizeObserver cubre sidebar.
Diagnóstico/formatos vienen del servicio; conservar cascada/comparación y
formato −$1,234.56. No cargar Chart.js para barra de gastos ni formatear en vista.

## Revisión

Editar fuentes, compilar y revisar diff de bundles. Verificar temas, móvil,
tablet, escritorio, teclado, foco, solo consulta, errores y reduced-motion.
Consultar [pruebas](pruebas.md); reglas de negocio permanecen en backend.
