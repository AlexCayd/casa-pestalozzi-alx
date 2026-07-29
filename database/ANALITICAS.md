# Casa Pestalozzi — Catálogo de analíticas propuestas

> Documento de análisis y propuesta. **Nada de lo aquí descrito está implementado.**
> Fecha: 2026-07-27 · Base: esquema de `database/ddl.sql`

Este documento propone analíticas orientadas a la **toma de decisiones operativas**
del restaurante, más allá de los agregados descriptivos que el panel ya muestra.
Incluye, al final, la deuda técnica de la base de datos que limita o bloquea
algunas de estas mediciones, con su solución concreta.

---

## Índice

1. [Analíticas que ya existen](#1-analíticas-que-ya-existen)
2. [Datos disponibles](#2-datos-disponibles)
3. [Nivel 1 — Alto impacto, datos listos](#3-nivel-1--alto-impacto-datos-listos)
4. [Nivel 2 — Alto valor, con matices](#4-nivel-2--alto-valor-con-matices)
5. [Nivel 3 — Bloqueadas por el esquema](#5-nivel-3--bloqueadas-por-el-esquema)
6. [Complementarias](#6-complementarias)
7. [Deuda técnica de la base de datos](#7-deuda-técnica-de-la-base-de-datos)
8. [Orden de implementación sugerido](#8-orden-de-implementación-sugerido)

---

## 1. Analíticas que ya existen

Inventario de lo que el panel calcula hoy, para que las propuestas se lean como
adicionales y no como duplicados.

| Módulo | Qué calcula |
|---|---|
| `/admin/analytics` | Ventas acumuladas, propinas, ticket promedio, comensales, unidades vendidas, reservaciones del rango |
| `/admin/analytics` | Ventas por día, ventas por categoría, métodos de pago, top de productos |
| `/admin/analytics` | Reservaciones por día y distribución por estado; tabla de tickets del rango |
| `/admin/finanzas` | Ingresos, propinas, costo y margen por producto (vía recetas), gastos fijos, cortes diarios por método de pago |
| `/admin/feedback` | Promedios de las 4 dimensiones, comentarios, ranking de meseros (atención + ventas), áreas de mejora vía n8n |
| `/admin/inventario` | Stock actual, mínimos, movimientos, ajustes y entradas |

**Carácter común:** son *descriptivas* — informan qué pasó. Las propuestas de
este documento son *diagnósticas y prescriptivas* — explican por qué y sugieren
qué hacer.

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

## 3. Nivel 1 — Alto impacto, datos listos

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

### 3.2 RevPASH — ingreso por asiento disponible por hora

**Pregunta:** ¿qué franjas horarias justifican el personal que les asigno?

```
RevPASH = ingresos_de_la_franja / (asientos_disponibles × horas_de_la_franja)
```

**Cálculo:** numerador de `ticket_items` agrupado por hora de `tickets.hora_apertura`;
denominador de `SUM(mesas.capacidad)` donde `activo = 1`, acotado por
`horarios_operacion` y descontando `excepciones_operacion`.

**Tablas:** `ticket_items`, `tickets`, `mesas`, `horarios_operacion`, `excepciones_operacion`

**Por qué agrega valor:** "ventas por día" premia los días largos. RevPASH mide
qué tan bien se monetiza la capacidad instalada, y suele revelar que un martes
de 13:00-15:00 rinde más por asiento que un sábado completo. Es la métrica con
la que los restaurantes deciden horarios y plantilla.

**Decide:** cuántos meseros programar por franja, si abrir o cerrar un horario,
cuándo lanzar menú ejecutivo. Con `excepciones_operacion` mide además el costo
de oportunidad real de cada cierre extraordinario.

---

### 3.3 Varianza de inventario — consumo teórico vs. real

**Pregunta:** ¿dónde se está fugando el dinero en la cocina?

| Mitad | Origen |
|---|---|
| **Teórico** | Ventas (`ticket_items`) × recetas explotadas |
| **Real** | `movimientos_inventario` tipo `ajuste` y `entrada` |

La diferencia, valorizada con `ingredientes.costo`, es **merma en pesos**:
desperdicio, porcionado inconsistente, error de captura o robo.

**Tablas:** `movimientos_inventario`, `ingredientes`, `producto_componentes`, `subreceta_ingredientes`, `ticket_items`

**Por qué agrega valor:** el sistema ya registra ambas mitades y nadie las
compara. Es probablemente la analítica de mayor retorno inmediato del catálogo,
porque convierte datos que ya existen en una cifra accionable. Presentada como
ranking descendente por `$ de varianza`, señala el ingrediente exacto donde
apretar el porcionado.

**Refinamiento:** segmentar por turno y por día para localizar *cuándo* ocurre.

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

## 4. Nivel 2 — Alto valor, con matices

### 4.1 No-show y anticipación de reserva

**Pregunta:** ¿cuánto *overbooking* puedo permitirme sin arriesgar?

**Cálculo:** `DATEDIFF(reservaciones.fecha, DATE(reservaciones.created_at))` da la
anticipación; `estado = 'no_show'` da el desenlace. Segmentar la tasa por
anticipación, día de la semana, franja horaria y tamaño de grupo.

**Tablas:** `reservaciones`, `reservacion_mesas`

**Decide:** nivel de sobreventa por franja, a qué reservas conviene mandar
recordatorio, y cuándo liberar la mesa. Saber que las reservas de 6+ personas
hechas con 10 días de anticipación tienen 18 % de no-show cambia la operación
del viernes.

---

### 4.2 Calidad de servicio contra carga operativa

**Pregunta:** ¿la baja calificación es del mesero o del sistema saturado?

**Cálculo:** cruzar cada `feedback` con las condiciones del momento — mesas
simultáneas del mesero (`tickets` abiertos con ese `mesero_id` en esa ventana),
ocupación del salón, tamaño de la comanda, densidad de comandas por área.

**Tablas:** `feedback`, `feedback_tokens`, `tickets`, `ticket_items`, `mesas`, `usuarios`

**Por qué agrega valor:** el ranking actual compara personas. Esto separa
**desempeño individual de saturación del sistema**, que es exactamente la
diferencia entre despedir a alguien y contratar a alguien.

**Decide:** número máximo de mesas por mesero antes de que la satisfacción caiga;
umbral de ocupación en que hay que meter refuerzo.

---

### 4.3 Elasticidad-precio desde el histórico de tickets

**Pregunta:** ¿cuánto puedo subir un platillo antes de perder demanda?

`ticket_items.precio` es un *snapshot* al momento de la venta. Esa aparente
redundancia es en realidad **una serie histórica de precios cobrados**,
disponible sin haber diseñado versionado de precios.

**Cálculo:** detectar cambios de precio por producto en la serie de
`ticket_items.precio` y comparar la media de unidades diarias antes y después.

**Tablas:** `ticket_items`, `tickets`, `productos`

**Salvedad:** necesita histórico suficiente y cambios de precio reales. Con pocos
datos es ruido. Conviene dejarla instrumentada aunque tarde meses en dar señal.

**Combinación:** junto con la matriz de §3.1, indica cuáles de las "Vacas"
aguantan un aumento.

---

### 4.4 Mapa de calor de rentabilidad del salón

**Pregunta:** ¿qué mesas rinden y cuáles se subutilizan?

**Cálculo:** ingreso acumulado, número de tickets y ticket promedio por
`tickets.mesa_id`, proyectado sobre las coordenadas `mesas.pos_x` / `pos_y`.

**Tablas:** `tickets`, `ticket_items`, `mesas`

**Por qué agrega valor:** la capa visual del mapa **ya existe** en el POS, así
que es de las más baratas de construir y de las más persuasivas de mostrar.

**Decide:** detectar mesas penalizadas por ubicación (junto al baño, paso de
meseros, corriente de aire), orden de asignación en horas pico, y dónde sentar
grupos grandes.

---

## 5. Nivel 3 — Bloqueadas por el esquema

> Las dos de mayor valor operativo puro. Requieren los cambios descritos en
> [§7 Deuda técnica](#7-deuda-técnica-de-la-base-de-datos).

### 5.1 Tiempos de cocina por área ⚠️

**Bloqueo:** `ticket_items` modela el flujo `enviado → en_preparacion → listo →
entregado` pero **solo guarda `created_at`**. Sin marca de tiempo por transición
no se puede medir cuánto tarda cada área ni dónde está el cuello de botella.

**Desbloquea** (ver [D-2](#d-2--ticket_items-sin-marcas-de-tiempo-por-transición)):
- Tiempo medio de preparación por área y por platillo.
- Minutos que un plato pasa en barra esperando a que lo recojan — que es un
  problema **distinto** de que la cocina sea lenta, y hoy son indistinguibles.
- Saturación por franja y por estación.
- Correlación entre demora y calificación de `tiempo_espera` en `feedback`.

**Impacto:** es la analítica que más directamente mejora la experiencia del
comensal, porque ataca la queja más común de cualquier restaurante.

---

### 5.2 Rotación real de mesa ⚠️

**Bloqueo:** `tickets` tiene `hora_apertura` pero **no `hora_cierre`**. La
duración solo se aproxima con el `created_at` del último `ticket_items`, lo que
subestima sistemáticamente: no cuenta sobremesa ni tiempo de cobro.

**Desbloquea** (ver [D-1](#d-1--tickets-sin-hora_cierre)):
- Duración real por tipo de mesa y tamaño de grupo.
- Turnos por mesa por servicio → insumo directo del RevPASH de §3.2.
- Política de reservas basada en duración medida, no supuesta.

---

## 6. Complementarias

| Analítica | Qué decide | Salvedad |
|---|---|---|
| **Punto de equilibrio diario** — `gastos_fijos` mensuales ÷ margen de contribución promedio | Convierte la contabilidad en una meta de cubiertos/día que el equipo entiende | — |
| **RFM de clientes** — vía `reservaciones.email` → `tickets` → consumo | A quién invitar, qué clientes se están perdiendo | Solo cubre a quien reservó; los *walk-ins* son anónimos (ver [D-4](#d-4--sin-identidad-de-cliente-para-walk-ins)) |
| **Cancelación de ítems** — `ticket_items.estado = 'cancelado'` por mesero, área y platillo | Distingue error de captura de platillo que la cocina no logra sacar | — |
| **Tasa de respuesta de feedback** — `feedback_tokens.usado` | Meta-analítica honesta: si responde el 8 %, los promedios tienen sesgo de autoselección y conviene declararlo en pantalla | — |
| **Propina normalizada por mesero** — propina ÷ total, controlando método de pago | Señal de calidad de servicio comparable | Efectivo y tarjeta tienen comportamientos de propina muy distintos; sin controlar se comparan manzanas con naranjas |
| **Carga por área de producción** — comandas entrantes por `area_id` en ventanas de 15 min | Balanceo de estaciones y dotación de cocina | Mide carga entrante, no tiempo de servicio, hasta resolver [D-2](#d-2--ticket_items-sin-marcas-de-tiempo-por-transición) |
| **Salud del software** — `reportes_sistema` por módulo y tiempo a resolución | Prioriza el backlog técnico con datos de uso real | — |

---

## 7. Deuda técnica de la base de datos

Cada punto indica el problema, su costo real y la solución propuesta.

> ⚠️ **Ninguno de estos `ALTER` está aplicado.** Son propuestas. Sobre una base
> con datos, aplicar primero en una copia y respaldar antes.

---

### D-1 · `tickets` sin `hora_cierre`

**Problema:** no se registra cuándo se cerró la cuenta. La duración de la visita
solo se puede aproximar con el último `ticket_items.created_at`.

**Costo:** bloquea la rotación real de mesa (§5.2) y degrada el RevPASH (§3.2),
que depende de saber cuántos turnos cabe por mesa.

**Solución:**

```sql
ALTER TABLE tickets
  ADD COLUMN hora_cierre DATETIME NULL
    COMMENT 'Momento del cobro; NULL mientras el ticket sigue abierto'
    AFTER hora_apertura;

CREATE INDEX idx_tickets_cierre ON tickets (hora_cierre);
```

Fijarla en `PuntoVentaController::cerrarTicket()` en el mismo `UPDATE` que ya
cambia `estado` a `'cerrado'`. Coste: una línea.

**Retroactividad:** los tickets ya cerrados pueden rellenarse de forma
aproximada con el último ítem, dejando constancia de que es una estimación:

```sql
UPDATE tickets t
   SET t.hora_cierre = (SELECT MAX(ti.created_at) FROM ticket_items ti
                         WHERE ti.ticket_id = t.id)
 WHERE t.estado = 'cerrado' AND t.hora_cierre IS NULL;
```

---

### D-2 · `ticket_items` sin marcas de tiempo por transición

**Problema:** la columna `estado` avanza por el flujo KDS
(`enviado → en_preparacion → listo → entregado`) **sobrescribiendo el valor
anterior**. No queda rastro de cuándo ocurrió cada paso.

**Costo:** bloquea por completo la analítica de tiempos de cocina (§5.1) — la de
mayor impacto operativo del catálogo. Hoy es imposible responder si la cocina es
lenta o si los platos se enfrían esperando a que un mesero los recoja.

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
es lo que permite la elasticidad-precio de §4.3 y que un ticket viejo se
reimprima con el precio que se cobró.

> **Nota:** mientras esto no exista, `productos.nombre` **debe** seguir siendo
> `UNIQUE` y hay que evitar renombrar productos con histórico.

---

### D-4 · Sin identidad de cliente para *walk-ins*

**Problema:** la única identidad de cliente es `reservaciones.email`. Un comensal
que llega sin reserva no deja rastro identificable entre visitas.

**Costo:** el RFM de §6 cubre solo a quien reservó, sesgando la muestra hacia un
perfil concreto. No se puede medir recurrencia real ni valor de vida del cliente.

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
introduce ruido silencioso en toda la analítica de calidad de §4.2.

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

**Costo:** la trazabilidad del descuento de inventario —justamente el insumo de
la varianza de §3.3— se degrada sin aviso.

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
visible**, y eso contamina la ingeniería de menú de §3.1 y la varianza de §3.3.

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

| # | Deuda | Severidad | Esfuerzo | Bloquea |
|---|---|---|---|---|
| D-1 | `tickets` sin `hora_cierre` | Alta | Muy bajo | §5.2, §3.2 |
| D-2 | Sin timestamps de transición en `ticket_items` | **Crítica** | Bajo | §5.1 |
| D-3 | `JOIN` por nombre en vez de `producto_id` | **Crítica** | Medio | Integridad general |
| D-4 | Sin identidad de *walk-in* | Media | Medio | RFM completo |
| D-5 | Faltan índices de analítica | Alta | Muy bajo | Rendimiento |
| D-6 | `feedback.ticket_id` sin FK | Media | Muy bajo | §4.2 |
| D-7 | `movimientos_inventario` sin FK | Media | Muy bajo | §3.3 |
| D-8 | Polimorfismo sin integridad | Media | Bajo / Alto | §3.1, §3.3 |
| D-9 | Horarios duplicados | Media | Medio | §3.2 |
| D-10 | `DROP` duplicado | Cosmética | Trivial | — |
| D-11 | Categoría congelada | Baja | Documental | Series largas |

---

## 8. Orden de implementación sugerido

### Fase 0 — Higiene (horas)
`D-5` (índices), `D-6` y `D-7` (llaves foráneas), `D-10` (limpieza). Bajo riesgo,
mejora inmediata de rendimiento e integridad.

### Fase 1 — Habilitadores (días)
`D-1` y `D-2`. Son dos `ALTER` y unas pocas líneas en dos controladores, pero
**empiezan a acumular datos que hoy se pierden para siempre**. Cuanto antes se
apliquen, antes habrá histórico que analizar — es el argumento más fuerte para
priorizarlos aunque las gráficas lleguen después.

### Fase 2 — Analíticas de retorno inmediato
`§3.3` varianza de inventario y `§3.1` ingeniería de menú. Solo requieren datos
ya existentes y producen decisiones económicas directas.

### Fase 3 — Analíticas de operación
`§3.2` RevPASH y `§4.4` mapa de calor del salón (la capa visual ya está hecha),
más `§5.1` y `§5.2` una vez que la Fase 1 haya acumulado histórico.

### Fase 4 — Inteligencia
`§3.4` reglas de asociación realimentando n8n, `§4.1` no-show, `§4.2` calidad vs.
carga, `§4.3` elasticidad-precio.

### Fase 5 — Estructural
`D-3` (migración a `producto_id`), `D-9` (consolidación de horarios), `D-4`
(identidad de cliente). Mayor alcance; conviene abordarlos cuando las analíticas
ya justifiquen la inversión.
