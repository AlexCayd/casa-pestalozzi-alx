# Almacenes

## Refinamiento M1.1

Las tarjetas físicas se distribuyen en una cuadrícula responsive dentro de cada
propietario. Muestran artículos activos asociados, bajo mínimo y agotados (saldo
menor o igual a cero); los indicadores no suman cantidades de unidades diferentes.
Las barras funcionan en los tres contextos sobre almacenes autorizados y
seleccionados. Comparan magnitudes absolutas, identifican negativos con rayado y
muestran el saldo neto aparte; el detalle accesible indica dueño, saldo, unidad,
mínimo y alerta. También hay etiquetas operables con teclado para saldos cero.

`Nuevo artículo` exige `inventario/editar` local y Global más `catalogo.editar`.
El servidor vuelve a comprobar ambos permisos dentro de la transacción. Crea solo
la identidad compartida: asociarla y registrar entrada son pasos explícitos.
Los duplicados conservan `ARTICULO_DUPLICADO`; editar el maestro sigue en Global.
`Registrar entrada` abre un selector de artículos de la página consultada. Para
otros artículos se utiliza la búsqueda. Si hay varios almacenes operables, el
operador debe elegir uno. Entrada y merma presentan una estimación decimal
exacta a milésimas y confirmación con artículo, almacén y resultado; el servidor
conserva la autoridad sobre permisos, saldo, negativos e idempotencia.

Contrato funcional de la interfaz administrativa compartida de Global,
Restaurante y Panadería. Se apoya en el [motor 3C](../arquitectura.md#5-almacenes-e-inventario)
y en el [contrato rector](../arquitectura.md); no implementa un motor por ámbito.

## Superficies y permisos

| Ámbito | Ruta | Lectura | Escritura |
|---|---|---|---|
| Global | `/admin/global/almacenes` | Propios y consolidado autorizado | Solo físicos Global; creación/asignación de almacenes con facultad |
| Restaurante | `/admin/almacenes` | Almacenes y movimientos propios | Asociaciones y operaciones físicas propias |
| Panadería | `/admin/panaderia/almacenes` | Almacenes y movimientos propios | Las mismas operaciones locales de Restaurante |

El módulo es `inventario`: `ver` permite consultar y `editar` permite mutar según
el alcance del recurso. Global necesita además `almacenes.crear` para crear un
almacén físico y elegir propietario, y `catalogo.editar` para el maestro de
artículos. El consolidado no concede permiso para operar físicamente otro negocio.

`AdminAlmacenesController` obtiene el contexto de la ruta, el actor y nivel de
sesión del servidor y valida CSRF vinculado a la superficie. El coordinador
`AdministracionAlmacenes` adapta formularios al motor, que vuelve a comprobar
perfil, pertenencia, estado y reglas de operación. Los IDs del navegador no
autorizan una unidad, un almacén o un artículo ajenos.

## Consultas

`views/admin/almacenes/index.php` presenta una pantalla única: selección de
almacenes, resumen y existencias por artículo. Las tarjetas se agrupan por
propietario y todos los almacenes autorizados quedan seleccionados por defecto.
La consulta GET envía `almacen_ids[]`; el servidor valida cada ID, elimina
duplicados y rechaza mezclas de IDs autorizados y ajenos. Una selección explícita
vacía se rechaza si existen almacenes autorizados; sin almacenes es válida.

La selección se resume en un control («N de M almacenes» y el reparto por
propietario) que abre un panel con una columna por propietario. Cambiar casillas
requiere confirmar con **Aplicar selección**; cerrar el panel sin aplicar descarta
los cambios. El estado anunciado indica los cambios pendientes de aplicar.
JavaScript protege la última casilla seleccionada y el servidor vuelve a validar el
límite, incluso sin JavaScript. Mientras llega la consulta, la tabla muestra filas
esqueleto ([guía de interfaz](../desarrollo/interfaz.md)).

Global conserva las identidades del catálogo maestro que cumplan la búsqueda,
incluidas las que no tienen asociación en los almacenes elegidos: muestran saldo
y mínimo `0.000` y «Sin asociación en la selección». Restaurante y Panadería
muestran las asociaciones locales seleccionadas. Saldos, mínimos, alertas,
distribución e historial usan exclusivamente los IDs elegidos. La búsqueda y
paginación siguen en servidor y conservan la selección.
El orden predeterminado muestra primero artículos con alertas en el alcance
seleccionado, antes de aplicar la paginación. Dentro de cada grupo conserva
nombre alfabético e ID como desempate estable; también se aplica al buscar.
El resumen cuenta
identidades y artículos con alguna alerta; no suma piezas, gramos y litros
como una misma magnitud ni cuenta varias veces un artículo por sus alertas físicas.

Movimientos y configuración se abren en los modales compartidos del panel.
El catálogo maestro utiliza la superficie persistente de Artículos (UX-4).
`vista=catalogo` y `/admin/global/catalogo` conservan una redirección compatible;
las demás URLs con `vista` abren su modal correspondiente. El historial conserva filtros y
paginación en servidor.

Global representa la distribución con segmentos proporcionales a la magnitud
absoluta de cada saldo. Los negativos tienen rayado; total neto y etiquetas
conservan el decimal exacto del backend. `DecimalInventario` resuelve cantidades;
la coma flotante solo interviene en la proporción visual, sin cambiar el motor de
escritura. Segmentos y etiquetas son botones accesibles, también con saldo cero.
El detalle muestra almacén, propietario, saldo, mínimo y alerta mediante hover,
foco o pulsación; permite pasar el puntero al detalle y se cierra con Escape.

El saldo físico pertenece a `almacen_articulos`. Las alertas requieren artículo,
almacén y asociación activos y saldo inferior a su mínimo. Un excedente de otro
almacén no oculta ese faltante. El consolidado es una consulta, nunca otra fila
física ni un saldo editable global.

## Administración y operaciones

- Global autorizado crea almacenes físicos con propietario Global, Restaurante
  o Panadería. Cambiar propietario después de operaciones no equivale a editar
  libremente una columna: requiere un flujo futuro explícito.
- La configuración controla nombre y estado por almacén dentro del alcance
  permitido. La política «Permitir existencias negativas» se retiró en M3: ningún
  saldo es negativo (arquitectura §16). La baja física está limitada a almacenes sin existencias,
  operaciones o dependencias que impidan eliminarlo.
- El maestro de artículos es compartido y solo se modifica desde Global autorizado.
  Los negocios asocian identidades existentes con mínimo y estado propios.
- Entradas, ajustes y mermas requieren almacén y artículo explícitos. Los ajustes
  envían la versión observada para detectar cambios concurrentes.
- Los formularios generan una clave idempotente y transmiten cantidades/costos
  como cadenas decimales. El motor valida precisión, saldo y bloqueos. Un artículo
  con `contenido_pieza` (750 ml por botella) admite capturar entradas y mermas
  en piezas + resto; el servidor convierte exacto a la unidad base y la
  lectura muestra «8 piezas + 450 ml».
- Saldos, operación y movimientos se confirman atómicamente. No presentar éxito
  mientras un resultado esté pendiente de una transacción exterior.

Entrada y Merma se abren por artículo y exigen un único almacén propio operable.
El conteo físico se retiró (auditoría A-4): una diferencia real se registra como
merma con motivo, o como entrada. Los ajustes anteriores siguen en Movimientos como
«ajuste (histórico)». Asociar, Configurar y Crear conservan facultades, CSRF, versiones
e idempotencia existentes según la operación.

**Asociar y dar entrada entre negocios (9 nov 2026).** «Asociar artículo» muestra
casillas agrupadas por negocio con los almacenes activos de todo negocio donde el
usuario edita Almacenes, y asocia a todos los marcados en un envío (todos o
ninguno). El alta de artículo trae las mismas casillas, opcionales: crea y asocia
en un paso. «Entrada» ofrece cada almacén asociado de esos negocios, rotulado con
su dueño, y los proveedores del dueño del almacén. El servidor autoriza cada
almacén en su negocio: un almacén ajeno forzado se rechaza y no deja asociaciones
parciales. En la pantalla de un negocio sólo se listan sus existencias, así que la
entrada entre negocios se ve desde Global. Merma y Configurar asociación siguen
siendo del contexto actual. Fuera de eso, Global solo opera sus almacenes;
los de otros propietarios son consulta.

## Trazabilidad y límites

La bitácora conserva actor, origen, clave, referencia y movimientos. El saldo de
cada asociación corresponde a sus deltas; se corrige compensando, no editando
movimientos históricos. Los fixtures de apertura cumplen esa misma integridad.

La acción **Transferencias** abre el modal compartido de solicitudes e historial,
con pendientes primero y paginación de cien documentos. Los formularios exigen
CSRF por contexto, clave idempotente, motivo y versión de cabecera. La solicitud
admite varias líneas; enviar y recibir son confirmaciones físicas independientes.
Recepciones parciales dejan incidencia; el retorno lo confirma el origen.
El [contrato de transferencias](transferencias.md) define permisos, estados y
conservación. Su historial documental incluye la contraparte; no expone saldo
ajeno. Esta consulta no cambia al seleccionar almacenes de existencias.
Producción y variantes avanzadas continúan en el [backlog](../backlog.md).
El antiguo Inventario de Restaurante conserva sus consumidores operativos;
Almacenes comparte el motor y no introduce escrituras paralelas de saldo.

## Verificación

Ejecutar el motor y sus pruebas de concurrencia 3C, los ensayos 3E, las
[instalaciones independientes](../desarrollo/instalacion.md) y las
[pruebas HTTP/navegador](../desarrollo/pruebas.md). Comprobar solo consulta,
revocación, IDs ajenos, fallo de una línea, negativos por almacén, replay,
consolidado y las mismas interacciones en escritorio y móvil.


Pruebas específicas desde la raíz (ajustar host, base y entorno a la configuración):

```text
php scripts/tests/run-almacenes-seleccion.php --host 127.0.0.1 --base casa-pestalozzi --entorno development
php scripts/tests/run-almacenes-ux.php
node scripts/tests/run-almacenes-selection-ui.cjs
php scripts/tests/pruebas-http-almacenes-ux.php --fixture <ruta-privada-del-harness>
```

El runner de selección crea y limpia su propia base desechable. El ensayo HTTP
consulta solo el harness temporal 3E identificado por su fixture privado. La
revisión UI usa esa base desechable, sin tocar la base activa ni fixtures
históricos. Completar con build, `npm run test:php`, `npm run test:js` y pruebas
de contextos. Revisar ambos temas, escritorio, móvil, teclado, selección pendiente,
cero, negativos, identidades sin asociación y solo lectura. No incorporar rutas
personales de capturas al repositorio.

## Frontera con artículos M2

`ingredientes.id` sigue siendo la clave de cada artículo físico y del ledger.
Las capacidades y elaboraciones no crean asociaciones, entradas, saldos ni
transferencias. Configurar una receta no convierte ingredientes en producto
terminado. Los orígenes de consumo continúan siendo explícitos y del mismo
negocio, sin escoger un almacén alternativo. Una preparación virtual exige los
orígenes de sus hojas; una almacenada exige el de la preparación.

Elegir un origen en el editor operativo conserva el permiso `productos/editar`;
no permite dar de alta asociaciones ni modificar existencias. El coordinador
Global de recetas conserva su validación adicional de Inventario para fijar
orígenes oficiales. El alta de artículos, costo, unidad y operaciones físicas
mantiene sus permisos y facultades existentes. La unidad maestra no puede
cambiar cuando existan composiciones históricas que dependan de ella.

Desde M3 una venta nunca se bloquea por saldo, tampoco con artículos «Se elabora
antes de vender»: el almacén de consumo descuenta lo disponible, queda en 0 y el
resto se registra como faltante pendiente de origen (sección «Faltantes»). El snapshot M2 no sustituye el ledger:
la comanda y su inventario se confirman juntos; las compensaciones vuelven a las
cantidades y costos de los movimientos originales. `null` expresa costo
insuficientemente conocido y no se transforma silenciosamente en cero.

M2.5 deberá registrar entradas/salidas de transformación, lotes, rendimiento real,
mermas y costos congelados. M2 ofrece composición y previsión, sin esos movimientos.


## Catálogo persistente UX-4

`/admin[/global|/panaderia]/almacenes/catalogo` reutiliza el listado de Artículos
con búsqueda, filtro de actividad y paginación de 30 filas. `/nuevo` y
`/editar?id=…` presentan la identidad compartida fuera de un modal. El permiso de
consulta sigue siendo `inventario/ver`; no implica `productos/ver` ni edición.
Las fichas M2 mantienen `productos/ver`. Cada superficie ofrece los enlaces que
el actor puede utilizar, sin exigir facultades Global para configurar un negocio.

El controlador solo adapta el formulario a `AdministracionAlmacenes::guardar`,
que conserva `Almacenes` como escritor único. Crear requiere edición de Inventario
en el contexto y la facultad de catálogo Global, como el alta rápida anterior.
Editar identidad exige hacerlo en Global con sus permisos y facultad existentes.
La unidad referenciada y la inactivación con saldos continúan protegidas por el
motor. El costo maestro se consulta en Global; el alta local autorizada permite
capturar el costo inicial conforme al contrato existente. No se duplican maestros.

Crear redirige al ID exacto y ofrece capacidades/elaboración solo con permiso de
Productos, y asociaciones/existencias con Inventario. No crea configuración M2,
asociación ni saldo. Los endpoints anteriores de operaciones siguen adaptando al
mismo escritor; se retiraron sus formularios modales extensos y controles obsoletos.
Los modales de entrada, merma, asociación y confirmación se conservan.

Los retornos validados conservan contexto, búsqueda, actividad, página, selección
de almacenes y artículo. Los IDs físicos se comprueban con `AlcanceInventario`;
Global mantiene su consulta consolidada autorizada y los negocios solo sus
almacenes. No se aceptan hosts, selecciones mixtas ajenas o parámetros arbitrarios.

Verificación dirigida: `pruebas-http-ux4.php --fixture ARCHIVO_PRIVADO_DEL_HARNESS`.
Requiere el harness loopback y propiedad de base temporal verificables. Prepara
solo identidades sintéticas y restaura los cambios de permisos de sus perfiles.

La continuidad UX de M2 usa `/admin[/panaderia|global]/almacenes?articulo_id=ID&asociar=ID&volver=…`.
El servidor comprueba que ambos IDs coincidan, conserva la consulta exacta de la
identidad, limita el selector a almacenes activos del contexto y excluye los que
ya tienen una asociación. El modal compartido solo se abre automáticamente si
hay permiso, artículo activo y un almacén elegible. Guardar continúa usando
`AdministracionAlmacenes::guardar` y el escritor existente; la asociación no crea
saldo ni movimiento. El resultado permanece dentro del layout y el retorno
autorizado conduce a la ficha exacta.

## Faltantes por conciliar (M3)

Contrato en [arquitectura §16](../arquitectura.md#16-m3-tipo-de-venta-y-faltantes-de-inventario).

- **Origen.** Una comanda con saldo menor al requerido descuenta lo disponible y
  crea un faltante (`faltantes_inventario`, estado `pendiente`) ligado al item,
  al almacén de consumo y a la operación. Un replay no duplica ni descuento ni
  faltante. La migración `m4-faltantes.sql` convierte cada saldo negativo previo
  en ajuste a 0 + faltante de origen `migracion`.
- **Aviso.** Prioridad alta en el buzón (`inventario.faltante`, por almacén +
  artículo + día) y aviso «N faltantes por conciliar» arriba de Almacenes. Una
  entrada del artículo en cualquier almacén reabre el aviso.
- **Bandeja** (`?vista=faltantes`, `inventario/editar` del dueño del almacén de
  consumo): artículo, almacén, cantidad, ticket, fecha y orígenes posibles. El
  saldo sólo se muestra en almacenes propios; los de otro propietario aparecen por
  identidad. Se marca cuando ningún almacén propio cubre el faltante.
- **Conciliar** (`accion=conciliar_faltante`): reparto entre uno o varios
  almacenes con suma exacta, versión del faltante y clave idempotente. El mismo
  almacén consume directo; otro almacén del negocio aplica una transferencia de
  regularización creada, enviada y recibida; un almacén de otro propietario queda
  en confirmación hasta que su dueño la confirma o la rechaza en Transferencias.
  Ningún origen queda negativo: sin saldo suficiente, el reparto se rechaza
  completo y el faltante sigue pendiente.
- **Costo.** El consumo conciliado guarda el costo del origen; el ticket y
  Finanzas conservan el costo congelado al enviar la comanda.
- **Cancelación.** Cancelar el item anula lo pendiente y la parte en
  confirmación (su transferencia se cancela); lo descontado o conciliado se
  compensa al almacén de consumo como cualquier venta.

## Proveedores (M5)

Pantalla de Almacenes en los tres ámbitos: `/admin/almacenes/proveedores`,
`/admin/global/almacenes/proveedores` y `/admin/panaderia/almacenes/proveedores`, con
entrada propia en el menú lateral después de Almacenes y un acceso en su franja de
herramientas. Mismo permiso `inventario`. Contrato en
[arquitectura §17](../arquitectura.md#17-m5-proveedores-por-ámbito-e-histórico-de-lo-recibido).

- **Directorio:** proveedores del ámbito con artículos asignados, recepciones y última
  entrada. Global alterna entre los suyos y el consolidado de los tres (sólo consulta
  de los de cada negocio).
- **Ficha:** primero el histórico (recepciones, cada cuánto, siguiente estimada, lo que
  se le suele pedir con su evolución de costo y las entradas recientes); después los
  artículos que surte (costo de referencia, código, preferente) y sus datos de contacto.
- **Entrada:** el diálogo ofrece «Proveedor (opcional)» con los del ámbito; los que surten
  el artículo van primero y el preferente se marca. Elegirlo propone su costo de
  referencia y vuelve obligatorio el costo. El servidor lo exige y rechaza proveedores
  ajenos o inactivos sin registrar la entrada.
- **Retiro:** con entradas sólo se desactiva; sin ellas se elimina con confirmación.

Las rutas anteriores `/admin/inventario/proveedores*` redirigen aquí en modo multinegocio.
