# Catálogo comercial y Menú

## Estado vigente M1.2

Productos de la casa conserva `/admin/global/productos`: búsqueda, filtros por
administración/negocio/uso, paginación y fichas de página completa. El recetario
estándar se encuentra en `/admin/global/productos/recetario`. Crear y convertir
un producto local usan `/create` y `/casa?id=…`; editar un producto adherido abre
esta última ficha. Los productos anteriores mantienen su experiencia local.

La identidad comercial no se duplica al compartirla. Global publica valores
oficiales y seis reglas: nombre, descripción, imagen, precio, receta y disponibilidad.
Estándar, compartido y exclusivo son configuraciones de interfaz; la autorización
se verifica por campo en el servidor. Exclusivo permite un solo negocio y no
concede edición. Global no vende. Categoría/carta, área y orden son locales.

Guardar borrador no crea ofertas ni afecta la operación. Publicar exige revisión,
huella del estado leído, clave de idempotencia y confirmación. La vista previa
muestra valores oficiales y efectivos antes/después por negocio, personalizaciones
conservadas/retiradas y cambios de política. El historial guarda actor, fecha,
versión e impacto inmutable. Retirar un destino desactiva su oferta.

`productos_oferta` resuelve nombre/descripcion/imagen efectivos para Menú, carta,
PDF, POS y consultas de ofertas actuales. El precio obligatorio se materializa
en `ofertas_producto.precio` DECIMAL(8,2), junto con su historial en la misma
transacción; no se recalculan importes con floats. Las consultas históricas siguen
sus snapshots. Vacío y null explícito son valores locales distintos de ausencia;
volver a la casa elimina la personalización. Las referencias publicadas y locales
impiden borrar fotografías oficiales o históricas todavía utilizadas.

| Acción | Permisos efectivos requeridos |
|---|---|
| Consultar catálogo/recetario Global | `menu/ver` Global; ofertas limitadas por lectura del propietario |
| Crear/editar estándar | `menu/editar` Global y `comercial.identidad.editar` |
| Publicar producto | Los anteriores y `menu/editar` en cada negocio afectado |
| Personalizar oferta | `menu/editar` del dueño y regla vigente habilitada |
| Publicar receta y aplicarla | Facultad Global, Menú de los destinos y `productos/editar` local |
| Adaptar/volver a receta | `productos/editar` local; desde Menú también `menu/editar`; adaptar exige regla |
| Elegir origen de consumo | Facultad Global, `productos/editar` e `inventario/editar` del dueño |

No hay privilegios implícitos por pertenecer a Global ni por editar Menú. La
revocación se comprueba de nuevo bajo bloqueo; IDs y contexto del cliente no
conceden acceso. CSRF se valida por ámbito. Todos los formularios protegidos
conservan controles legibles y acciones locales válidas.

Ver [Recetas](recetas.md), [arquitectura](../arquitectura.md) y el
[informe de validación M1.2](../desarrollo/pruebas.md#cierre-m12).

Las secciones M1/M1.1 siguientes documentan sus verificaciones previas; las reglas
y resultados M1.2 de esta sección y del cierre prevalecen sobre ese estado anterior.


## Refinamiento M1.1

Rama `codex/m11-refinamiento-menus-ux`, base `7ebb36b492a018cfd925cac6c07f048542570c83`.
Entregas: A `f6ca88f` (inicios), B `1a9ee11` (catálogo), C `ca1064e`
(PDF/fixtures) y D `Refina Almacenes y documenta validación de M1.1` (consultar
`git log -4 --oneline` para su hash). Sin push ni merge. El cierre D incluye
fuentes, assets, pruebas y estas siete referencias vigentes; no añade una segunda
documentación histórica. El inventario exacto de archivos se obtiene con
`git diff --name-only 7ebb36b HEAD`.

### Comportamiento y rutas

`/admin/global` y `/admin/panaderia` usan tarjetas compartidas con permisos reales
y enlaces a módulos operativos. Restaurante conserva su dashboard. El catálogo
`/admin/global/productos` presenta 30 identidades por página, búsqueda y filtros
por negocio autorizado y uso (con/sin oferta visible). Las cuentas sin lectura
del negocio no ven sus usos ni pueden filtrar por él. La ficha y edición maestra
conservan confirmación de impacto y huella concurrente de M1.

| Ruta PDF | Propietario y carta |
|---|---|
| `/admin/panaderia/menu/pdf` | Panadería, comida |
| `/admin/global/menus` | Biblioteca autorizada |
| `/admin/global/menus/restaurante` | Restaurante, comida |
| `/admin/global/menus/maridaje` | Restaurante, maridaje |
| `/admin/global/menus/panaderia` | Panadería, comida |

Los PDF privados usan las ofertas/categorías/cartas activas del dueño validado y
el mismo `items-pdf.php`. El título Panadería es propio. Una carta ajena,
inexistente, sin contenido o distinta de la ruta fija se rechaza; no se sustituye
silenciosamente por comida. Los PDF públicos `/menu/pdf` y `/maridaje/pdf`
conservan datos y plantilla Restaurante. No hay publicación pública Panadería.
Los documentos privados llevan `Cache-Control: private, no-store`.

### Matriz de permisos

| Operación | Requisitos |
|---|---|
| Acceso rápido | Lectura real del módulo/contexto destino |
| Catálogo Global | `menu/ver` Global; usos limitados por `menu/ver` del dueño |
| Cambio maestro comercial | `menu/editar` Global + `comercial.identidad.editar`; confirmación si compartida |
| PDF local | `menu/ver` del dueño vendedor |
| Biblioteca/PDF Global | `menu/ver` Global y del dueño |
| Almacenes y barras | `inventario/ver` sobre propietarios autorizados |
| Nuevo artículo desde negocio | `inventario/editar` local y Global + `catalogo.editar` |
| Operación física | `inventario/editar` dueño del único almacén seleccionado |

Cambios principales: controladores AdminContextos/AdminMenu/AdminAlmacenes;
servicios InicioAdministrativo, CatalogoComercial, MenuPdf, AdministracionAlmacenes
y ConsultasAlmacen; vistas contextos/menu/almacenes/sidebar; fuentes SCSS y JS de
esos módulos y sus compilados; rutas, seeds y runners M1.1. Se reutilizan servicios
de autorización, transacciones, stock y componentes visuales actuales.

### Datos, PDF y aislamiento

Los [fixtures vigentes](../desarrollo/fixtures-almacenes.md#fixtures-comerciales-m11)
añaden 12 ofertas sintéticas Panadería: 13 en total, 12 activas, cuatro categorías.
Los 112 originales y sus imágenes permanecen íntegros; no se crean recetas,
producción ni stock. Las dos instalaciones independientes pasan 957 comprobaciones.
PDF reales guardados fuera de Git: `m11-restaurante.pdf` (6 páginas),
`m11-maridaje.pdf` (2) y `m11-panaderia.pdf` (2). Extracción de texto confirma rol de
canela/brownie, ausencia de ofertas Restaurante y exclusión de Pan de chocolate
inactivo. La segunda página Panadería conserva la paginación por categoría de la
plantilla, con Focaccia. Se inspeccionaron visualmente sus dos páginas y la primera
Restaurante, sin recortes ni superposición. No se sustituyó la plantilla original.

### Validación actual

| Suite | Resultado |
|---|---|
| PHP general / JS general / build | PASS (avisos deprecación Sass/Node del tooling existente) |
| Inicio M1.1 / UI decimal | 9 comprobaciones / PASS |
| Menú M1 + extensión M1.1 | 74 comprobaciones PASS (52 existentes conservadas) |
| HTTP M1 / HTTP M1.1 | 47 / 47 PASS |
| Contextos puro / autorización 3B | 252 / 128 PASS |
| Almacenes 3C / concurrencia / selección | 158 / 17 / 70 PASS |
| Almacenes representación / selección JS | 16 / PASS |
| Fixtures M0.5-B / Transferencias | 378 / 114 PASS |
| HTTP instalación / Almacenes UX / Transferencias | 121 / 39 / 35 PASS |
| Instalaciones 3E | 957 PASS |
| Preservación menú puro / DB / PDF reservaciones | 34 / 7 casos / PASS |

`test:notifications` y su variante `--migrations` pasan 15 de 16 runners, pero
fallan en `run-pos-api-contrato-db.php`: mesa de cena libre a las 18:30 en vez de
próxima. `test:contextos:db` falla por columna retirada `usuarios.rol`.
`test:pos-print-alerts` falla por `unidad_id` ausente en su fixture y
`test:pos-llevar` por actor 0 no autenticado. **Los cuatro fallos se reprodujeron
también en una copia temporal del commit base 7ebb36b**, con vendor local y bases
aisladas. No son resultados PASS ni se redujeron aserciones para ocultarlos.
No se modifica POS ni el modelo de permisos para satisfacer fixtures antiguos.
Las bases temporales se limpian; no se ejecutó DDL sobre la base activa.
Los runners que exigen paquete privado histórico mantienen la limitación
documentada en M1: no se inventó ni reconstruyó esa referencia.

### Evidencia visual y límites

Capturas reales privadas bajo la carpeta de visualizaciones del chat (fuera de
Git): `m11-home-global.png`, `m11-home-panaderia.png`,
`m11-home-panaderia-movil.png`, `m11-catalogo-listado.png`,
`m11-catalogo-ficha.png`, `m11-biblioteca-pdf.png`,
`m11-almacenes-restaurante.png`, `m11-almacenes-panaderia.png`,
`m11-almacenes-global.png`, `m11-alta-articulo.png`,
`m11-grafica-tooltip.png`, `m11-operacion-fisica.png` y
`m11-confirmacion-fisica.png`, `m11-biblioteca-pdf-claro.png`,
`m11-almacenes-movil-claro.png` y `m11-catalogo-movil-claro.png`. Las páginas PDF
se renderizan además en PNG para inspección. El visor PDF del navegador integrado
mostró un lienzo vacío: no se presenta esa captura como validación visual; se
usaron el PDF descargado, su texto y los PNG renderizados. Se revisan temas
claro/oscuro, móvil 390×844 sin desbordamiento horizontal, foco y confirmación;
las comprobaciones visuales no se deducen de un HTTP 200.

No hay migración M1.1 para bases existentes ni reaplicación de development.sql.
Los seeds nuevos son exclusivos de instalaciones nuevas development/test.
Persisten los límites de M1: M2, POS y producción Panadería, integraciones,
credenciales externas, impresoras físicas, reinicio y restauración no certificados.
El cierre verifica cuatro commits en la rama indicada y árbol Git limpio. Se
retiran el servidor, las bases de fixtures, sesiones y copia temporal del baseline;
se conservan las capturas y PDF de evidencia fuera del repositorio.

## M1-A: auditoría y contrato previo

Base local y remota `branch-leo`: `404df872a387f59ee7a7a43d30d4f463e4d33259`.
Checkout limpio; rama de trabajo `codex/m1-catalogo-menu-multinegocio`.
No se modifican otros worktrees ni se publica la rama.

### Inventario y dependencias

| Archivos / componentes | Clasificación | Decisión y consumidores |
|---|---|---|
| `controllers/AdminMenuController.php`, `views/admin/menu/{index,form,categories,category-form}.php` | Adaptación multinegocio | Un solo recorrido contextual; sustituir destinos fijos, conservar diseño |
| `controllers/AdminContextosController.php`, `services/Security/{Contextos,ContextoNegocio,Permisos,PermisosUsuario}.php`, sidebar del layout admin | Compartido / adaptación | Autorizar rutas y habilitar solo superficies completas |
| `models/Producto.php`, `models/CategoriasMenu.php` | Compartido y legado con consumidores | Fachadas con implementación multinegocio; conservar API para POS, recetas y pruebas históricas |
| `services/Menu/Ofertas.php` | Compartido / adaptación | Asociación explícita, bloqueo de identidad y unicidad producto/unidad |
| `services/Menu/{CategoriaMenuService,FotoPlatillo}.php`, `classes/ImagenUploader.php` | Compartido | Propiedad local, compensación, pendientes y protección de referencias |
| `services/Menu/{Carta,MenuPdf,CataService}.php`, `controllers/MenuController.php`, `views/admin/menu/items-pdf.php` | Exclusivo Restaurante en M1 | Conservar carta pública, especiales, PDF y maridaje |
| `services/Menu/{Variantes,RecetasOferta}.php`, `views/admin/menu/variantes.php`, `controllers/AdminRecetasController.php` | Legado con consumidores / fuera de M1 para Panadería | Oferta como identidad operativa; no habilitar M2 |
| `controllers/PanaderiaController.php`, `views/panaderia/` | Fuera de M1 | Andamio público sin pedidos ni pagos |
| `src/scss/admin/modules/menu.scss`, parciales de menú, `src/js/admin/core/{reactive-filters,foto-platillo,historial-precios}.js`, `src/js/admin/menu/variantes.js` | Compartido / consumidores vigentes | Reutilizar filtros, foto, tabla, botones, alertas y modales; no duplicar bundles |
| POS, KDS, tickets, snapshots, Analytics, Asistente | Consumidores vigentes / fuera de M1 | Mantener IDs de oferta, precio local y snapshots; ninguna escritura de stock nueva |
| `database/{ddl,deploy,menu-restaurante,development}.sql` | Compartido / preservación | FK y unicidad ya presentes; añadir solo facultad comercial sin concesiones implícitas |
| Redirecciones `/admin/menu/items*`, `/admin/menu/categories*`, `/admin/productos*` | Legado con consumidores | Mantener compatibilidad; no hay eliminación demostrada en la auditoría |

No se ha identificado código obsoleto sin consumidores con evidencia suficiente
para eliminarlo. La antigüedad de las fachadas no acredita obsolescencia.

### Propiedad de datos

`productos`: ID permanente, nombre, descripción e imagen compartidos. No fusionar
por nombre. `ofertas_producto`: negocio vendedor, categoría (que determina carta),
precio DECIMAL, área, activo y orden. Una identidad admite una oferta por negocio;
Global nunca es vendedor. Categorías y áreas pertenecen a la misma unidad que la
oferta. Las cartas conservan claves `comida`, `maridaje`, `temporada` y sus FK;
Panadería adapta etiquetas, sin copiar categorías ni ofertas de Restaurante.
Recetas y variantes pertenecen a ofertas; existencias a almacenes físicos.
Retirar significa desactivar la oferta, nunca borrar identidad ni historial.

### Rutas y autorización

| Superficie | Lectura | Escritura |
|---|---|---|
| `/admin/menu` y destinos históricos | `menu/ver` Restaurante | `menu/editar` Restaurante; operaciones actuales |
| `/admin/panaderia/menu` y `create`, `edit`, `toggle`, `delete`, `categorias/*`, `asociar` | `menu/ver` Panadería | `menu/editar` Panadería; sin POS, PDF público, variantes ni editor de recetas |
| `/admin/global/productos` y `edit` | `menu/ver` Global; uso comercial solo en negocios con `menu/ver` | `menu/editar` Global + `comercial.identidad.editar`; solo atributos maestros |
| `/admin/global/catalogo` | Contrato vigente Almacenes | Sin cambios |

La nueva facultad `comercial.identidad.editar` es exclusiva de Global y separada
de `catalogo.editar`, que continúa siendo inventariable. No migrar asignaciones
existentes automáticamente. Una identidad con más de una oferta exige dicha
facultad y consentimiento explícito de impacto compartido, incluso desde una
edición local. Sin facultad, el formulario conserva identidad y permite editar
condiciones locales. El servidor relee membresías, permisos y facultades; las
mutaciones vuelven a comprobarlos con bloqueo dentro de la transacción.

Asociar permite consultar identidades maestras para elegir un ID permanente,
sin revelar precios/categorías de otros negocios. No clona ni crea oferta ajena.
La ruta efectiva establece el contexto; campos del navegador no cambian unidad,
ID de oferta ni ID maestro. CSRF y listas permitidas protegen las mutaciones.

### Cambios mínimos, riesgos y aceptación inicial

Cambios: contextualizar rutas/vistas; exponer asociación existente; agregar
consulta/edición maestra autorizada; mantener exactitud decimal, validación y
bloqueos; proteger sincronización contra IDs manipulados. El DDL comercial no
requiere cambios. Nueva facultad estructural necesita DML en instalaciones
existentes, mediante despliegue revisado, nunca sobre la base activa en pruebas.

Riesgos detectados: sincronización genérica admite IDs; consentimiento ausente
para identidad compartida; precio convertido a float; rutas Restaurante fijas;
acciones de recetas/variantes no disponibles en Panadería; imágenes pendientes
de alta comparten ID cero entre contextos. Se resolverán antes de habilitar Menú.

Aceptación: alta nueva en ambos negocios, asociación sin duplicación, independencia
de precio/estado/categoría/área, rechazo Global vendedor e IDs ajenos, solo lectura,
revocación, CSRF, confirmación de identidad, archivos referenciados, unicidad
concurrente, rollback, retiro con historial, menú original y consumidores
Restaurante. Ejecutar suites generales, 3E/3B/3C/M0.5 y recorridos HTTP y navegador
en base desechable. No certificar UI por HTTP 200 ni paquetes privados ausentes.

No hay incompatibilidad que requiera cambiar el modelo comercial establecido.
M2, M3, Finanzas, Analíticas, Asistente e integraciones quedan fuera del alcance.

## M1-B y M1-C: implementación final

El núcleo sigue en `Producto`/`ProductoMultinegocio`, `Ofertas`,
`CategoriaMenuService`, `FotoPlatillo` y `AdminMenuController`. No hay un
controlador comercial duplicado. `AdministracionMenu` prepara contexto, rutas,
CSRF y capacidades; `CatalogoComercial` coordina identidades y uso autorizado;
`ImporteOferta` preserva el contrato exacto DECIMAL(8,2).

Se modifican controlador/rutas, fachadas de producto e histórico, servicios de oferta,
categoría/foto, catálogo de permisos, módulos habilitados, navegación y vistas
compartidas. Se reutilizan sin duplicación Carta, PDF, variantes, recetas,
uploader, filtros reactivos, selector, botones, temas y confirmaciones. Los
archivos concretos modificados y añadidos se enumeran con `git diff --name-status
404df872..HEAD`; no se elimina ningún archivo. Se retiran las constantes de rutas
fijas y la comprobación privada de nombres repetidos sustituida por identidad
permanente y unicidad de oferta, con consumidores revisados en la auditoría.
Las ramas mononegocio conservan consumidores de pruebas: no se borran.

| Grupo | Archivos modificados o añadidos |
|---|---|
| Controladores/rutas | `controllers/AdminMenuController.php`, `controllers/AdminContextosController.php`, `controllers/AdminController.php`, `public/index.php` |
| Modelos | `models/Producto.php`, `models/HistorialPrecio.php` |
| Servicios Menú | `services/Menu/AdministracionMenu.php`, `services/Menu/CatalogoComercial.php`, `services/Menu/ImporteOferta.php`, `services/Menu/Ofertas.php`, `services/Menu/FotoPlatillo.php`, `services/Menu/CategoriaMenuService.php` |
| Seguridad | `services/Security/ContextoNegocio.php`, `services/Security/Permisos.php`, `services/Security/PermisosUsuario.php` |
| Vistas | `views/admin/menu/index.php`, `form.php`, `categories.php`, `category-form.php`, `variantes.php`, `asociar.php`, `global.php` en la misma carpeta |
| Fuentes visuales | `src/scss/admin/modules/menu.scss`, `src/js/components/confirmation-modal.js` |
| Compilados afectados | `public/build/css/admin/menu.css`; `public/build/js/admin.js`, `admin/map.js`, `admin/reservation-form.js`, `bundle.min.js` y sus mapas; copia pública `assets/js/bundle.min.js` y su mapa |
| Persistencia/configuración | `database/deploy.sql`, `database/actualizaciones/m1-facultad-comercial.sql`, `package.json` |
| Pruebas | `scripts/tests/run-menu-m1.php`, `pruebas-http-menu-m1.php`, `run-menu-confirmacion.cjs`, `cliente-http-3e.php`, `servir-instalacion-3e.php`, `pruebas-http-3e.php` en la misma carpeta |
| Guías | Este contrato, `docs/arquitectura.md`, `docs/backlog.md`, `docs/desarrollo/interfaz.md`, `docs/desarrollo/pruebas.md`, `docs/panaderia/README.md` |

| Negocio | Disponible en M1 | Fuera de M1 |
|---|---|---|
| Restaurante | Recorridos anteriores y asociación explícita de identidades; categorías, foto, orden, precio, estado, PDF y variantes conservados | Refactorizar POS, KDS, finanzas o recetas |
| Panadería | Menú, alta nueva, asociación, consulta/edición local, precio, categoría/carta, área, orden, foto, disponibilidad y retiro | Recetas administrativas completas, variantes comerciales, PDF/publicación, POS, pagos y producción |
| Global | Identidades maestras, ofertas consultables y edición de nombre/descripción/imagen con facultad y confirmación | Ventas, ofertas Global y edición consolidada de condiciones locales |

Las rutas y la matriz de permisos de M1-A son el contrato implementado. Un
administrador exclusivamente de Menú aterriza en la ruta de su contexto. Los
formularios no aceptan IDs para sustituir el recurso cargado; las consultas y
mutaciones validan permisos actuales. El CSRF de Restaurante no sirve en Panadería.
Las identidades compartidas se bloquean también al asociar una nueva oferta;
las ediciones maestras detectan una huella obsoleta antes de escribir.

## M1-D: aceptación y entrega

Revisión del 27 de octubre de 2026. Rama
`codex/m1-catalogo-menu-multinegocio`, HEAD inicial `404df872`. Commits por fase:
`9a9d690` M1-A, `0de84d9` M1-B, `6fd4412` M1-C y el commit de cierre M1-D
que contiene esta aceptación. El HEAD final y estado limpio se comprueban con
`git log -4 --oneline` y `git status --short`. No se hizo push, merge ni cambios
en otros worktrees.

### Pruebas ejecutadas

| Verificación | Resultado |
|---|---|
| `npm run build` | OK; advertencias existentes de API Sass y fs.Stats |
| `npm run test:php` | OK; ejecutado con acceso al destino de sesiones local |
| `npm run test:js` | OK; incluye confirmación escrita real en JSDOM |
| `npm run test:notifications` | OK, normal y `--migrations`; migraciones históricas retiradas se omiten explícitamente |
| `npm run test:menu-preservacion` | 34 aserciones OK |
| `run-menu-m1.php` | 52 comprobaciones OK; altas, asociación, permisos, revocación, aislamiento, DECIMAL, retiro con ventas, imágenes referenciadas, carreras y rollback |
| `pruebas-http-menu-m1.php` | 47 comprobaciones OK; auth/CSRF, IDs inyectados, categorías, estructura inválida, carta desconocida, ofertas, fotos pendientes, facultades, confirmación, huella y PDF |
| `run-instalacion-3e.php` | 957 comprobaciones OK en dos instalaciones nuevas |
| `run-multinegocio-3b.php` | 128 comprobaciones OK |
| `run-almacenes-3c.php` / `run-almacenes-3c-concurrencia.php` | 158 / 17 comprobaciones OK |
| `run-almacenes-seleccion.php` / `run-almacenes-ux.php` / `run-almacenes-selection-ui.cjs` | 70 / 16 y contratos de selección/tooltip OK |
| `npm run test:contextos` | 246 comprobaciones OK |
| `run-m05b-fixtures.php` / `run-transferencias-m05b.php` | 378 / 114 comprobaciones OK |
| `pruebas-http-3e.php` | 121 comprobaciones OK |
| `pruebas-http-almacenes-ux.php` / `pruebas-http-transferencias-m05b.php` | 39 / 35 comprobaciones OK |
| Aislado: `run-reservaciones-menu-pdf.php` / `run-punto-venta-cierre-db.php` | PASS ambos |

Todos los runners DB recibieron `--host 127.0.0.1 --base casa-pestalozzi --entorno
development`, para identificar origen, y crearon/limpiaron sus destinos propios.
Los HTTP usaron exclusivamente el harness 3E loopback y credenciales efímeras.
La primera ejecución PHP bajo sandbox no accedió a la ruta de sesiones esperada;
la repetición con acceso autorizado pasó sin cambiar aserciones ni configuración.

Las dos instalaciones verificaron los mismos 112 productos y 112 ofertas
originales, 15 categorías, 3 cartas, 4 áreas y 13 imágenes, además de 7 almacenes,
30 artículos y 47 asociaciones físicas. Los snapshots completos de una oferta
con ventas permanecieron idénticos tras retirarla. No se ejecutó DDL, DML de
pruebas ni migraciones sobre la base activa; tampoco se sustituyó el menú original.

La matriz inicial de aceptación quedó cubierta por estos servicios/HTTP y la
revisión visual. Rollback ensaya fallos deliberados en la segunda escritura de
alta y después de cambiar identidad antes de guardar oferta, además del fallo de
registro del histórico que revierte el precio. Dos procesos
compiten por asociar una identidad y editar una misma huella: un único ganador.
El ensayo de imágenes usa un archivo propio referenciado por dos identidades;
no intenta borrar archivos del menú original.

### Revisión visual y regresiones corregidas

Navegador real sobre el harness: Global en claro y ficha maestra en oscuro,
Restaurante escritorio oscuro, Panadería escritorio, móvil 390×844 en claro y
formulario oscuro, categorías, selector colapsado, Escape/Tab, estado de solo
lectura, error de precio y modal de retiro. El documento no desbordó horizontalmente
en los tamaños comprobados. Las capturas privadas, fuera de Git, se llaman:

- `m1-global-escritorio.png`, `m1-global-ficha-oscuro.png`.
- `m1-restaurante-escritorio-oscuro.png`, `m1-panaderia-escritorio.png`.
- `m1-panaderia-movil-claro.png`, `m1-formulario-movil-oscuro.png`.
- `m1-contexto-categorias.png`, `m1-modal-retiro.png`.
- `m1-error-validacion.png`, `m1-panaderia-solo-lectura.png`.

Se corrigió el rechazo de facultad comercial que devolvía error interno: ahora
devuelve 403. El modal compartido ocultaba el campo de confirmación escrita y
bloqueaba la acción; ahora el campo es visible, accesible, recibe foco y habilita
la confirmación al coincidir. Su test ejecuta el componente real. Se corrigieron
destinos de login mononegocio, IDs sincronizables, precios con precisión excesiva,
cartas desconocidas que se normalizaban silenciosamente e imágenes pendientes
reclamables desde otro contexto. Ninguna aserción existente fue reducida.

### Despliegue y límites

Registrar la facultad con `database/actualizaciones/m1-facultad-comercial.sql`
antes de desplegar sobre una instalación existente. Es aditivo e idempotente;
no asigna permisos automáticamente. Delegar la facultad comercial desde Usuarios
con los techos actuales. Las instalaciones nuevas ya la obtienen de `deploy.sql`.
Este SQL no se aplicó a la base activa durante la revisión.

No se proporcionó el paquete privado exigido por `run-multinegocio-3a.php`,
`run-restaurante-3d1.php`, `run-restaurante-3d2.php` y `run-restaurante-3d3.php`;
no se ejecutaron ni se reconstruyó una referencia histórica artificial. La
preservación se contrastó con los mecanismos versionados y las instalaciones 3E.
La aceptación local no certifica SMTP, Meta, n8n, impresoras físicas, reinicios o
restauración. No se implementa M2/M3 ni integraciones externas.

Deuda conservada: fachadas mononegocio con consumidores de pruebas históricas;
publicación pública y producción de Panadería pendientes del contrato correspondiente;
los consumidores mononegocio del histórico conservan su API. En multinegocio,
alta/asociación/cambio de precio y su histórico se confirman en una transacción.
No se refactorizan consumidores ajenos a M1.
