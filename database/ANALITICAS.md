# Analíticas de Restaurante

Referencia técnica subordinada al [contrato rector](../docs/arquitectura.md).
Implementación: Services\Analytics\Analiticas, AdminController::analytics y
views/admin/analytics.php. Los consolidados Global y la analítica comercial de
Panadería siguen pendientes. No aplicar DDL ni seeds desde esta guía.

## Datos y alcance

La instalación sigue [la guía única](../docs/desarrollo/instalacion.md): ddl.sql,
deploy.sql, menu-restaurante.sql y development.sql solo en desarrollo/pruebas.
Las fixtures no deben contaminar informes reales. El menú preservado mantiene
112 productos/ofertas; el desarrollo añade ofertas sintéticas independientes.
Hay 365 tickets analíticos/803 líneas y una semana RevPASH de 26 tickets sobre
11 mesas reservables/44 asientos, además del conjunto local del Asistente.

- tickets y ticket_items identifican unidad; las líneas conservan oferta,
  producto, categoría, precio y costo histórico conocido.
- ofertas_producto contiene precio/disponibilidad/categoría comercial por negocio;
  productos no contiene la decisión comercial local.
- recetas/versiones/componentes pertenecen a oferta. Inventario vive en
  almacen_articulos y movimientos_inventario, sin stock maestro duplicado.
- ticket_pagos desglosa pagos; ticket_mesas identifica ocupación de salón.
- horarios_operacion/excepciones_operacion determinan el calendario efectivo.

El panel descriptivo muestra ventas, propinas, ticket promedio, comensales,
unidades, categorías, pagos, reservas y tickets por periodo. Finanzas conserva
tratamiento explícito de costos desconocidos y evita sumar de nuevo ventas
ya calculadas desde tickets. El Asistente usa 12 intenciones locales;
[configuración](../docs/config.md#asistente-ia) detalla alcance y límites.

## Ingeniería de menú

En el esquema vigente ingenieriaMenuRestaurante agrupa por oferta_id y categoria_id
de snapshots, con tickets cerrados de Restaurante, líneas no canceladas y rango
por hora_apertura. Precio y costo unitario son promedios ponderados por cantidad
vendida. Si hay unidades de costo desconocido, la oferta no entra en la matriz;
no se sustituye el costo con la receta actual.

Popularidad alta: unidades de la oferta al menos 70% del promedio de unidades
de su categoría. Margen alto: margen unitario al menos igual al margen promedio
ponderado del conjunto conocido. Clases: Estrella (ambos altos), Vaca
(popular/margen bajo), Incógnita (poco popular/margen alto) y Perro (ambos bajos).
El porcentaje de popularidad se refiere al total elegible; no es rentabilidad
completa ni incorpora todos los gastos del negocio.

## RevPASH — mapa de calor hora × día de la semana

**Pregunta:** ¿qué franjas horarias justifican el personal que les asigno, y en
qué días?

```
RevPASH(día, hora) = ingreso(día, hora) / (asientos × días_abiertos(día, hora))
```

**Presentación:** matriz con las **horas como filas** y los **siete días de la
semana como columnas**. Se reporta por celda y no por hora agregada porque las
dos preguntas de operación —qué franjas rinden y dónde hay huecos— dependen del
día: un viernes a las 21:00 y un martes a las 21:00 son la misma hora y negocios
distintos. Agregar las columnas escondía exactamente eso.

**Cálculo:** numerador de `ticket_items` **de tickets de salón** agrupado por
`DAYOFWEEK` y `HOUR` de `tickets.hora_apertura`; denominador de los asientos del
salón por el número
de fechas del rango que cayeron en ese día de la semana **y** tuvieron el local
abierto en esa franja, según `horarios_operacion` descontando
`excepciones_operacion`.

Ese conteo por día es lo que hace comparables las columnas: en 30 días puede
haber cuatro viernes y cinco sábados, y sin dividir entre los días efectivos el
sábado se vería más fuerte solo por ocurrir más veces.

**Sólo el salón, arriba y abajo.** Un ticket es de salón si alguna de sus mesas
es `tipo = 'mesa'`; uno sin filas en `ticket_mesas` (histórico) también cuenta.
Los tickets que viven sólo en barras, Llevar o Caja salen del numerador porque
no ocupan un asiento del comedor: sumarlos sobre los asientos del salón inflaba
el rendimiento justo en las franjas de más barra. El mismo filtro aplica a los
respaldos de días y horas con venta, para que un día con sólo barra no pase por
día operado.

Los asientos salen de `Mesa::capacidadReservableTotal()`: la suma de capacidad
de las mesas `tipo = 'mesa'` activas y reservables (hoy 11 × 4 = **44**), la
misma definición que el «N/44» del POS. Sustituyó a la constante
`ASIENTOS_COMEDOR`, que era la tercera copia escrita a mano de ese número. No
hay historial de capacidad: desactivar una mesa reescala también los periodos
pasados.

**Codificación visual:** la intensidad del color es relativa a la celda más
fuerte del periodo. Se distinguen tres estados que no hay que confundir:

| Estado | Significado |
|---|---|
| Celda con color | Abierto y vendiendo; la saturación es el rendimiento |
| Celda en cero | **Abierto y sin vender** — el hueco de desempeño |
| Celda con `·` | **Cerrado** — calendario, no desempeño; no entra en ningún promedio |

Las celdas con venta fuera del horario declarado llevan borde punteado: su
denominador son los días con venta y no los días abiertos, así que usan otra
base y no son estrictamente comparables. Por eso la **franja más fuerte y la más
floja del pie solo compiten entre celdas dentro de horario** — son consejo
operativo y recomendar una franja cerrada no accionaría nada. La escala de color
sí incluye a todas, para que ninguna celda se salga de la rampa.

**Resiliencia ante el horario configurado:** el denominador se deriva de lo que
haya en `horarios_operacion` y `excepciones_operacion`, sin franjas fijas en el
código. `Analiticas::horasAbiertas()` resuelve los casos que antes se perdían:

| Caso | Comportamiento |
|---|---|
| Apertura o cierre a media hora (`08:30`, `22:30`) | Cuenta la hora si estuvo abierta aunque sea un minuto |
| Cierre en punto (`19:00`) | La hora 19 **no** cuenta: nadie se sienta ya |
| Turno que cruza medianoche (`18:00`→`02:00`) | Da la vuelta al reloj en vez de descartar el día |
| Excepción `horario_especial` sin horas capturadas | Cae al horario semanal del día en vez de descartar la fecha |
| Día abierto con horas nulas o ilegibles | No suma horas; esa fecha cae al conteo por días observados |
| Tabla `horarios_operacion` vacía | Todo el cálculo cae a días observados |

El calendario se prepara desde los archivos versionados de la instalación;
no cargar seeds de analítica antiguos como una segunda fuente de horarios.

**Tablas:** `ticket_items`, `tickets`, `horarios_operacion`, `excepciones_operacion`

**Por qué agrega valor:** "ventas por día" premia los días largos. RevPASH mide
qué tan bien se monetiza la capacidad instalada, y suele revelar que un martes
de 13:00-15:00 rinde más por asiento que un sábado completo. Es la métrica con
la que los restaurantes deciden horarios y plantilla.

En forma de mapa se lee además por franjas completas: una **fila** pálida es una
hora floja toda la semana (problema de horario), y una **columna** pálida es un
día flojo a cualquier hora (problema de demanda). El diagnóstico y la solución
son distintos.

**Decide:** cuántos meseros programar por franja y día, si abrir o cerrar un
horario, cuándo lanzar menú ejecutivo. Con `excepciones_operacion` mide además
el costo de oportunidad real de cada cierre extraordinario.

**Nota de implementación:** el mapa no usa Chart.js. Es una `<table>` real —
horas como `<th scope="row">`, días como `<th scope="col">`— donde cada celda
lleva una variable CSS `--heat` (0-1) y el color lo resuelve la hoja de estilos
con los tokens del tema. Así el mapa acompaña el cambio de tema claro/oscuro sin
repintar nada, y la matriz queda recorrible por lector de pantalla.

---

## Venta por hora — qué se vende a cada hora

`Analiticas::ventasPorHora($desde, $hasta)` → `agregarPorHora()` (pura). Panel
entre el RevPASH y las reglas de asociación: **una sola tabla**, una fila por
hora con venta, con los tres platillos que más salieron y el total de unidades.

```
unidades(hora) = todo lo que se pidió a esa hora en las fechas elegidas
```

- **Todas las ventas**: tickets cerrados, renglones no cancelados, sin separar
  por canal (salón, barra, para llevar) ni por estación del KDS.
- **Fechas**: dos `<input type="date">` («Desde», «Hasta»); un solo día es la
  misma fecha en las dos. Arrancan en el periodo del selector de la página y
  vienen en el JSON inicial; al cambiarlas, `nivel1.js` pide
  `GET /admin/api/analytics/venta-por-hora?desde=&hasta=` (permiso
  `analytics`, validación de `RangoPeriodo::resolver`). En un periodo las
  cifras son **totales**, no promedio por día.
- **Hora y fecha del pedido** (`ticket_items.created_at`), no de la apertura
  del ticket: el día elegido es el día en que se pidió el platillo.
- Variantes sumadas al platillo (agrupa por `ticket_items.nombre`).
- ⚠️ `created_at` es `TIMESTAMP`: se lee en la zona de la sesión. La app fija
  `-06:00` (`includes/database.php`) y `development.sql` también; cualquier otro
  cliente que lea esta tabla tiene que hacer lo mismo o verá las horas
  corridas seis horas.
- **Consultas rápidas por hora** (`ConsultasRapidas::horario()`): «lo más
  vendido en la mañana», «capuchinos los sábados a las 10». Misma base de hora;
  filtra lo más vendido y las ventas de platillo o categoría, no el total.

Pruebas: `scripts/tests/run-venta-por-hora.php` y `run-consultas-por-hora.php`.

---


## Asociación de productos

reglasAsociacion forma canastas de ofertas distintas por ticket cerrado local,
sin líneas canceladas, con rango por apertura. Calcula soporte(A,B) como
coocurrencias/total, confianza en la dirección de menor base y lift como
coocurrencias×total/(tickets_con_A×tickets_con_B). Solo muestra lift > 1,
con al menos 3 coocurrencias para 40 o más canastas, o 2 para menos; devuelve
hasta 12 pares ordenados por lift. No acredita causalidad ni sustituye pruebas
comerciales. Esta gráfica no es una intención implementada del Asistente.

## Límites y pruebas

No hay historial de capacidad física: cambiar mesas puede reescalar RevPASH
pasado. Tampoco hay timestamps de transición KDS ni identidad de walk-in para
recurrencia; requisitos y deuda vigentes en [backlog](../docs/backlog.md).
El esquema ya tiene identidad de oferta/producto y claves por unidad en líneas,
feedback y movimientos: no reinstalar las propuestas SQL mononegocio antiguas.

Ejecutar suites PHP/JS y run-venta-por-hora.php/run-consultas-por-hora.php según
[pruebas](../docs/desarrollo/pruebas.md). No presentar una fixture ni un resultado
local como certificación de proveedor externo o consolidado Global.
