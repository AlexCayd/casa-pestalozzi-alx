# Casa Pestalozzi — Analíticas diagnósticas

> Documentación de las analíticas **implementadas** en la vista
> `/admin/analytics` (`Services\Analiticas` → `AdminController::analytics` →
> `views/admin/analytics.php`).
> Base: esquema de `database/ddl.sql`
>
> | Sección | Analítica |
> | --- | --- |
> | §3.1 | Ingeniería de menú (Kasavana-Smith) |
> | §3.2 | RevPASH — mapa de calor hora × día |
> | §3.4 | Reglas de asociación con lift |
>
> Todos los datos de demostración del panel están en
> `database/analiticas-datos-ex.sql`, que se carga **después** de `dml.sql`.
> Orden completo: `ddl.sql` → `dml.sql` → `analiticas-datos-ex.sql`.
>
> **La numeración salta el §3.3 a propósito.** Las etiquetas `3.1`, `3.2` y `3.4`
> aparecen literalmente en el encabezado de cada panel de la interfaz y en los
> comentarios de `Services\Analiticas`; renumerar aquí desincronizaría el
> documento del código y de lo que el usuario ve en pantalla.

Este documento describe las analíticas **diagnósticas y prescriptivas** del panel
—las que explican por qué pasó algo y sugieren qué hacer—, con su cálculo exacto,
las tablas de las que salen y las salvedades para interpretarlas. Al final está
la deuda técnica de la base de datos que las limita, con su solución concreta.

---

## Índice

1. [Analíticas que ya existen](#1-analíticas-que-ya-existen)
2. [Datos disponibles](#2-datos-disponibles)
3. [Analíticas diagnósticas implementadas](#3-analíticas-diagnósticas-implementadas)
4. [Deuda técnica de la base de datos](#4-deuda-técnica-de-la-base-de-datos)

---

## 1. Analíticas que ya existen

Inventario de lo que el panel calcula hoy, para separar los agregados
descriptivos de las analíticas diagnósticas del §3.

| Módulo | Qué calcula |
|---|---|
| `/admin/analytics` | Ventas acumuladas, propinas, ticket promedio, comensales, unidades vendidas, reservaciones del rango |
| `/admin/analytics` | Ventas por día, ventas por categoría, métodos de pago, top de productos |
| `/admin/analytics` | Reservaciones por día y distribución por estado; tabla de tickets del rango |
| `/admin/finanzas` | Ingresos, propinas, costo y margen por producto (vía recetas), gastos fijos, cortes diarios por método de pago |
| `/admin/feedback` | Promedios de las 4 dimensiones, comentarios, ranking de meseros (atención + ventas), áreas de mejora vía n8n |
| `/admin/inventario` | Stock actual, mínimos, movimientos, ajustes y entradas |

**Carácter común:** son *descriptivas* — informan qué pasó. Las tres analíticas
del §3 son *diagnósticas y prescriptivas* — explican por qué y sugieren qué hacer.

---

## 2. Datos disponibles

Tablas con valor analítico y la señal que aporta cada una.

| Tabla | Señal analítica |
|---|---|
| `tickets` | Mesa, comensales, apertura, método de pago, propina, mesero, reserva de origen |
| `ticket_items` | Consumo línea a línea con **precio congelado**, área, comensal, nota, estado, `created_at` |
| `ticket_pagos` | Pago desglosado por comensal en cuentas divididas |
| `productos` | Catálogo unificado: precio vigente, categoría, área, activo |
| `producto_componentes`, `subrecetas`, `subreceta_ingredientes` | Receta explotable → costo teórico por platillo |
| `ingredientes` | Stock, mínimo y **costo unitario** → valorización de merma |
| `movimientos_inventario` | Bitácora `venta` / `cancelacion` / `ajuste` |
| `gastos_fijos` | Estructura de costo mensual → punto de equilibrio |
| `reservaciones` | `created_at` vs `fecha` (anticipación), estado incl. `no_show`, email como identidad de cliente |
| `reservacion_mesas` | Asignación real de mesas por reserva |
| `mesas` | Capacidad, tipo, y **coordenadas `pos_x` / `pos_y`** del salón |
| `feedback`, `feedback_tokens` | 4 dimensiones de calidad, comentario libre, y tasa de respuesta |
| `areas_produccion` | Ruteo de comandas → carga por estación |
| `horarios_operacion`, `excepciones_operacion` | Capacidad disponible real (denominador de productividad) |
| `usuarios` | Rol y actividad del personal de piso |
| `reportes_sistema` | Salud del software reportada por los usuarios |

---

## 3. Analíticas diagnósticas implementadas

> **De dónde salen los datos de cada gráfica.** Todo el seed que existe para que
> el panel tenga algo que graficar vive en un solo archivo,
> `database/analiticas-datos-ex.sql`:
>
> | Gráfica | Datos |
> |---|---|
> | Ventas diarias, ingreso por familia, métodos de pago, productos más vendidos, ticket promedio, propinas, tabla de tickets | `tickets` 200-299 + sus `ticket_items` |
> | Reservaciones por día y por estado | `reservaciones` con token `fx-analytics-res-%` |
> | §3.1 Ingeniería de menú | `ingredientes` 10-99 + recetas de comida (dan el margen real) |
> | §3.2 RevPASH | `tickets` 200-299 repartidos por franja y día |
> | §3.4 Reglas de asociación | pares recurrentes dentro de esos mismos tickets |
>
> `dml.sql` aporta lo que el sistema necesita para arrancar y la analítica solo
> lee de pasada: catálogo de productos, categorías, mesas, usuarios, horarios,
> ingredientes 1-9 con sus recetas de bebida y los tickets del POS.

### 3.1 Ingeniería de menú (matriz popularidad × margen)

**Pregunta:** ¿qué platillo sube de precio, cuál se rediseña y cuál sale de la carta?

Clasificación de Kasavana-Smith. Se cruzan dos ejes que el sistema ya conoce por
separado: unidades vendidas y margen de contribución real por receta.

|  | Margen alto | Margen bajo |
|---|---|---|
| **Popular** | ⭐ **Estrella** — proteger; no tocar receta ni precio | 🐎 **Vaca** — subir precio con cuidado, abaratar guarnición |
| **Impopular** | ❓ **Incógnita** — reposicionar en la carta, entrenar venta sugerida | 🐕 **Perro** — retirar del catálogo |

**Cálculo:**
- Popularidad = `SUM(ticket_items.cantidad)` por producto ÷ total de unidades, comparado contra el promedio de su categoría.
- Margen unitario = `productos.precio` − `Inventario::costoDeProducto()`.
- Corte: 70 % del promedio de popularidad de la categoría (regla estándar).

**Tablas:** `ticket_items`, `productos`, `producto_componentes`, `subreceta_ingredientes`, `ingredientes`

**Por qué agrega valor:** `/admin/finanzas` ya muestra el margen por producto,
pero aislado no dice qué hacer. Un platillo con 70 % de margen que vende 3
unidades al mes no merece el mismo trato que uno con 40 % que vende 300. La
matriz es lo que convierte el dato en decisión.

**Extra:** cruzar con `productos.tag` responde si las etiquetas ("Especialidad
C.P.", "Estrella") realmente mueven unidades o son decorativas.

---

### 3.2 RevPASH — mapa de calor hora × día de la semana

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

**Cálculo:** numerador de `ticket_items` agrupado por `DAYOFWEEK` y `HOUR` de
`tickets.hora_apertura`; denominador de los asientos del comedor por el número
de fechas del rango que cayeron en ese día de la semana **y** tuvieron el local
abierto en esa franja, según `horarios_operacion` descontando
`excepciones_operacion`.

Ese conteo por día es lo que hace comparables las columnas: en 30 días puede
haber cuatro viernes y cinco sábados, y sin dividir entre los días efectivos el
sábado se vería más fuerte solo por ocurrir más veces.

Los asientos son la constante `Analiticas::ASIENTOS_COMEDOR` (**44**), no
`SUM(mesas.capacidad)`: esa suma da 64 porque incluye las barras (Barra Blanca 8
+ Barra Roja 6 + Barra Roja 2 = 20) además de las 11 mesas de sala (11 × 4 = 44).

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

> El horario del negocio lo siembra **`dml.sql`**, que es el mismo que usa el
> flujo público de reservaciones. El archivo de datos de ejemplo no toca esa
> tabla: cuando lo hacía, el orden de carga decidía el horario del restaurante y
> las ventas de lunes salían marcadas como fuera de horario.

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

### 3.4 Reglas de asociación con *lift*

**Pregunta:** ¿qué se pide junto con qué, de verdad?

Agrupando `ticket_items` por `ticket_id`, calcular para cada par:

```
soporte(A,B)    = tickets con A y B / tickets totales
confianza(A→B)  = tickets con A y B / tickets con A
lift(A,B)       = confianza(A→B) / soporte(B)
```

**Tablas:** `ticket_items`, `tickets`, `productos`

**Por qué agrega valor:** el flujo actual de n8n rankea por **conteo crudo de
coocurrencia**, lo que siempre favorece a los platillos populares — sugerirá
Chilaquiles con todo, porque Chilaquiles se vende con todo. El **lift** corrige
por popularidad base y encuentra afinidades reales: pares que se piden juntos
*más de lo que el azar predice* (`lift > 1`).

**Decide:** adyacencias en el diseño de la carta, armado de combos, y sobre todo
**eleva el motor de venta sugerida** de heurística a fundamento estadístico. Es
la mejora de mayor apalancamiento sobre el módulo de sugerencias ya construido.

---

## 4. Deuda técnica de la base de datos

Cada punto indica el problema, su costo real y la solución propuesta.

> ⚠️ **Salvo D-1, ninguno de estos `ALTER` está aplicado.** Son propuestas. Sobre
> una base con datos, aplicar primero en una copia y respaldar antes.

---

### D-1 · `tickets` sin `hora_cierre` — ✅ resuelta

**Problema (histórico):** no se registraba cuándo se cerró la cuenta, así que la
duración de la visita solo se podía aproximar con el último
`ticket_items.created_at`.

**Estado:** `ddl.sql` ya declara la columna y la analítica la consume:

```sql
hora_cierre        DATETIME NULL,   -- Momento del cobro usado por analítica y finanzas
```

Se conserva el punto para dejar constancia de que la deuda existió y de dónde
salió la columna. Pendiente menor: no tiene índice propio, y las consultas que
filtren por cobro se beneficiarían de `CREATE INDEX idx_tickets_cierre ON
tickets (hora_cierre);`.

---

### D-2 · `ticket_items` sin marcas de tiempo por transición

**Problema:** la columna `estado` avanza por el flujo KDS
(`enviado → en_preparacion → listo → entregado`) **sobrescribiendo el valor
anterior**. No queda rastro de cuándo ocurrió cada paso.

**Costo:** no se puede medir cuánto tarda cada área ni dónde está el cuello de
botella. Hoy es imposible responder si la cocina es lenta o si los platos se
enfrían en barra esperando a que un mesero los recoja, que son dos problemas
distintos con soluciones opuestas. Además, cada día que pasa sin las columnas es
histórico que se pierde para siempre: la transición se sobrescribe y no hay forma
de reconstruirla después.

**Solución:**

```sql
ALTER TABLE ticket_items
  ADD COLUMN en_preparacion_at DATETIME NULL AFTER estado,
  ADD COLUMN listo_at          DATETIME NULL AFTER en_preparacion_at,
  ADD COLUMN entregado_at      DATETIME NULL AFTER listo_at;
```

Llenarlas en `AreaController::avanzarItem()` y `retrocederItem()`, donde ya se
hace la transición de estado.

**Alternativa considerada:** una tabla `ticket_item_eventos` con historial
completo. Es más pura, pero para cuatro estados fijos las tres columnas dan el
99 % del valor analítico con una fracción del trabajo. **Recomendación: las
columnas.**

---

### D-3 · `ticket_items` se une a `productos` por **nombre**, no por id

**Problema:** `ticket_items` guarda `nombre`, `precio`, `categoria` y `area_id`
como *snapshot*, sin `producto_id`. Todo el sistema —`Services\Sugerencias`, el
flujo de n8n, `Services\Inventario::aplicarVenta()`— resuelve el producto
haciendo `JOIN ... ON ti.nombre = p.nombre`.

**Costo:**
- Renombrar un producto **rompe silenciosamente** el vínculo con todo su
  histórico. El `JOIN` simplemente deja de encontrar filas, sin error.
- El descuento de inventario deja de funcionar para ese producto.
- Es la razón por la que `productos.nombre` tuvo que hacerse `UNIQUE`.

**Solución** — añadir la llave real conservando el snapshot para el ticket impreso:

```sql
ALTER TABLE ticket_items
  ADD COLUMN producto_id INT UNSIGNED NULL
    COMMENT 'Llave al catálogo; nombre/precio se conservan como snapshot histórico'
    AFTER ticket_id,
  ADD CONSTRAINT fk_ticket_items_producto
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL;

-- Backfill por nombre, que es la única llave disponible hoy.
UPDATE ticket_items ti
  JOIN productos p ON p.nombre = ti.nombre
   SET ti.producto_id = p.id
 WHERE ti.producto_id IS NULL;

CREATE INDEX idx_ti_producto ON ticket_items (producto_id);
```

Después, el POS debe mandar `producto_id` al insertar la comanda, y las
consultas deben unir por id. El snapshot de `nombre`/`precio` **se mantiene** —
es lo que permite que un ticket viejo se reimprima con el precio que se cobró y
que exista una serie histórica de precios realmente cobrados.

> **Nota:** mientras esto no exista, `productos.nombre` **debe** seguir siendo
> `UNIQUE` y hay que evitar renombrar productos con histórico.

---

### D-4 · Sin identidad de cliente para *walk-ins*

**Problema:** la única identidad de cliente es `reservaciones.email`. Un comensal
que llega sin reserva no deja rastro identificable entre visitas.

**Costo:** cualquier medición por cliente cubre solo a quien reservó, sesgando la
muestra hacia un perfil concreto. No se puede medir recurrencia real ni valor de
vida del cliente.

**Solución** — tabla ligera de clientes, poblada desde ambos flujos:

```sql
CREATE TABLE IF NOT EXISTS clientes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NULL,
  email      VARCHAR(150) NULL,
  telefono   VARCHAR(30)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clientes_email (email),
  INDEX idx_clientes_telefono (telefono)
);

ALTER TABLE tickets
  ADD COLUMN cliente_id INT UNSIGNED NULL AFTER reservacion_id,
  ADD CONSTRAINT fk_tickets_cliente
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL;
```

**Salvedad realista:** capturar identidad en un *walk-in* tiene fricción y roza
datos personales. Alternativa de menor fricción: usar el token de feedback ya
existente como identificador voluntario y opt-in.

---

### D-5 · Sin índice en las columnas por las que se filtra analítica

**Problema:** `tickets` solo indexa `(estado, mesa_id)` y `reservacion_id`. **Toda**
consulta de `/admin/analytics` y `/admin/finanzas` filtra por rango de
`hora_apertura`, que no tiene índice. `ticket_items` tampoco indexa `nombre`,
usado como llave de `JOIN` en cada consulta de sugerencias e inventario.

**Costo:** *full table scan* en cada carga del panel. Con pocos tickets no se
nota; crece linealmente y será el primer cuello de botella real en producción.

**Solución:**

```sql
CREATE INDEX idx_tickets_apertura        ON tickets       (hora_apertura);
CREATE INDEX idx_tickets_estado_apertura ON tickets       (estado, hora_apertura);
CREATE INDEX idx_ti_nombre               ON ticket_items  (nombre);
CREATE INDEX idx_ti_created              ON ticket_items  (created_at);
CREATE INDEX idx_feedback_ticket         ON feedback      (ticket_id);
```

`idx_tickets_estado_apertura` es el más valioso: cubre el patrón
`WHERE estado = 'cerrado' AND hora_apertura BETWEEN ...` que repiten casi todas
las consultas del panel.

---

### D-6 · `feedback.ticket_id` sin llave foránea

**Problema:** `feedback` declara FK sobre `token_id` pero **no sobre `ticket_id`**,
que es la columna por la que realmente se cruza con la operación (mesero, mesa,
consumo).

**Costo:** permite feedback huérfano apuntando a tickets inexistentes, lo que
introduce ruido silencioso en el ranking de meseros y en cualquier cruce entre
calidad percibida y operación.

**Solución:**

```sql
-- Limpiar huérfanos antes de imponer la restricción.
UPDATE feedback f
   LEFT JOIN tickets t ON t.id = f.ticket_id
   SET f.ticket_id = NULL
 WHERE f.ticket_id IS NOT NULL AND t.id IS NULL;

ALTER TABLE feedback
  ADD CONSTRAINT fk_feedback_ticket
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL;
```

---

### D-7 · `movimientos_inventario.ticket_item_id` sin llave foránea

**Problema:** tiene índice (`idx_mi_ti`) pero no restricción. Al borrarse un
ticket en cascada, sus movimientos quedan apuntando al vacío.

**Costo:** la trazabilidad entre una venta y el descuento de stock que provocó se
degrada sin aviso, y con ella la posibilidad de auditar el consumo real de
ingredientes contra el teórico.

**Solución:**

```sql
ALTER TABLE movimientos_inventario
  ADD CONSTRAINT fk_mi_ticket_item
    FOREIGN KEY (ticket_item_id) REFERENCES ticket_items(id) ON DELETE SET NULL;
```

---

### D-8 · `producto_componentes.ref_id` es polimórfico sin integridad

**Problema:** `ref_id` apunta a `ingredientes.id` o a `subrecetas.id` según
`tipo`. La relación polimórfica no admite llave foránea, y el `ddl.sql` ya lo
documenta como decisión consciente.

**Costo:** borrar un ingrediente deja componentes de receta apuntando a un id
inexistente. El costo teórico del platillo se calcula de menos, **sin error
visible**, y eso contamina el margen de contribución sobre el que se apoya la
ingeniería de menú de §3.1.

**Solución** — dos opciones según cuánto se quiera reescribir:

1. **Mínima (recomendada):** conservar el diseño y añadir validación en
   `AdminInventarioController::delete()` — impedir borrar un ingrediente
   referenciado, igual que `CategoriaMenuService` ya hace con las categorías.
   Complementar con una consulta de integridad periódica.

2. **Estructural:** partir en dos columnas excluyentes `ingrediente_id` y
   `subreceta_id`, cada una con su FK y un `CHECK` de que exactamente una es no
   nula. Correcto pero obliga a reescribir el constructor de recetas.

---

### D-9 · Dos sistemas de horarios solapados

**Problema:** `dias_reservacion` (`dia_semana`, `hora_apertura`, `hora_cierre`,
`activo`) y `horarios_operacion` (`dia_semana`, `abierto`, `hora_apertura`,
`hora_cierre`) modelan lo mismo, ambas con `UNIQUE` sobre `dia_semana`.

**Costo:** dos fuentes de verdad para "¿está abierto?". Pueden divergir, y el
denominador del RevPASH (§3.2) depende de elegir la correcta. Un cambio de
horario aplicado en una sola deja la otra mintiendo.

**Solución:** consolidar en `horarios_operacion`, que es la más completa
(tiene `abierto`, auditoría con `updated_by`/`updated_at`, y su complemento
`excepciones_operacion`). `dias_reservacion` quedaría solo como padre de
`horarios_reservacion` (los *slots* reservables), sin duplicar el horario:

```sql
ALTER TABLE dias_reservacion
  DROP COLUMN hora_apertura,
  DROP COLUMN hora_cierre;
```

Requiere migrar antes `Services\HorarioReservacionService` y
`ReservacionConfig` a leer el horario de `horarios_operacion`.

> **Variante ya corregida:** además de las dos tablas, hubo un tiempo **dos
> semillas** escribiendo sobre `horarios_operacion` — `dml.sql` y el archivo de
> datos de analítica, con `ON DUPLICATE KEY UPDATE` —, así que el orden de carga
> decidía el horario del restaurante. `analiticas-datos-ex.sql` ya no toca esa
> tabla: la fuente es `dml.sql`.

---

### D-10 · `DROP TABLE IF EXISTS ticket_pagos` duplicado

**Problema:** aparece dos veces en el bloque de reset de `ddl.sql` (líneas 27 y 32).

**Costo:** cosmético — el segundo `DROP` es inofensivo. Pero delata que el orden
de reset se editó a mano y sugiere revisar que el resto respete las dependencias.

**Solución:** eliminar la línea 32.

---

### D-11 · `ticket_items.categoria` es texto congelado

**Problema:** guarda el nombre de la categoría como cadena al momento de la venta.

**Costo:** renombrar una categoría **parte las series históricas en dos**. La
gráfica de "ventas por categoría" que ya existe mostraría "Pizzas" y "Pizzas
Artesanales" como líneas separadas.

**Solución:** no es un defecto sino una decisión correcta —el snapshot es lo que
permite reimprimir un ticket viejo tal como se emitió—, pero **la analítica de
largo plazo no debe agrupar por esa columna**. Debe unir a `productos` (por
`producto_id` una vez resuelta [D-3](#d-3--ticket_items-se-une-a-productos-por-nombre-no-por-id))
y agrupar por `categorias.id`.

Conviene dejarlo escrito en el propio `ddl.sql` como advertencia para quien
escriba la siguiente consulta.

---

### Resumen de deuda

| # | Deuda | Severidad | Esfuerzo | A qué afecta |
|---|---|---|---|---|
| D-1 | `tickets` sin `hora_cierre` | ✅ Resuelta | — | — |
| D-2 | Sin timestamps de transición en `ticket_items` | **Crítica** | Bajo | Tiempos de cocina; histórico que se pierde a diario |
| D-3 | `JOIN` por nombre en vez de `producto_id` | **Crítica** | Medio | Integridad general |
| D-4 | Sin identidad de *walk-in* | Media | Medio | Recurrencia y valor de cliente |
| D-5 | Faltan índices de analítica | Alta | Muy bajo | Rendimiento de todo el panel |
| D-6 | `feedback.ticket_id` sin FK | Media | Muy bajo | Calidad de servicio |
| D-7 | `movimientos_inventario` sin FK | Media | Muy bajo | Trazabilidad de inventario |
| D-8 | Polimorfismo sin integridad | Media | Bajo / Alto | §3.1 (margen de contribución) |
| D-9 | Horarios duplicados | Media | Medio | §3.2 (denominador del RevPASH) |
| D-10 | `DROP` duplicado | Cosmética | Trivial | — |
| D-11 | Categoría congelada | Baja | Documental | Series largas |
