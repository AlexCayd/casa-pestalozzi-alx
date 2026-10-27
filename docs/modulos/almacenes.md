# Almacenes

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

Cambiar casillas requiere confirmar con **Aplicar selección**. El estado anunciado
indica los cambios pendientes de aplicar. JavaScript protege la última casilla
seleccionada y el servidor vuelve a validar el límite, incluso sin JavaScript.

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

Movimientos, configuración y catálogo maestro se abren en los modales compartidos
del panel. Las URLs con `vista` se mantienen y abren el modal correspondiente;
no hay navegación por pestañas separadas. El historial conserva filtros y
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
- La configuración controla nombre, estado y negativos por almacén dentro del
  alcance permitido. La baja física está limitada a almacenes sin existencias,
  operaciones o dependencias que impidan eliminarlo.
- El maestro de artículos es compartido y solo se modifica desde Global autorizado.
  Los negocios asocian identidades existentes con mínimo y estado propios.
- Entradas, ajustes y mermas requieren almacén y artículo explícitos. Los ajustes
  envían la versión observada para detectar cambios concurrentes.
- Los formularios generan una clave idempotente y transmiten cantidades/costos
  como cadenas decimales. El motor valida precisión, negativos y bloqueos.
- Saldos, operación y movimientos se confirman atómicamente. No presentar éxito
  mientras un resultado esté pendiente de una transacción exterior.

Entrada, Merma y Conteo físico se abren por artículo y exigen un único almacén
propio operable. Asociar, Configurar y Crear conservan facultades, CSRF, versiones
e idempotencia existentes según la operación. Global solo opera sus almacenes;
los de otros propietarios son consulta.

## Trazabilidad y límites

La bitácora conserva actor, origen, clave, referencia y movimientos. El saldo de
cada asociación corresponde a sus deltas; se corrige compensando, no editando
movimientos históricos. Los fixtures de apertura cumplen esa misma integridad.

No se implementan transferencias, producción ni variantes avanzadas de inventario.
La distribución por almacén está construida. Transferencias aparece como pendiente, sin formulario ni endpoint nuevo; otras ampliaciones pertenecen al [backlog](../backlog.md).
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
