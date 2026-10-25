# Casa Pestalozzi — Documento maestro de migración multinegocio

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


**Versión:** 1.0 — Especificación inicial  
**Estado:** Arquitectura propuesta; implementación pendiente  
**Repositorio:** `AlexCayd/casa-pestalozzi-alx`

## 1. Objetivo

Extender Casa Pestalozzi para administrar Restaurante y Panadería desde una infraestructura común, conservando la operación existente de Restaurante.

La migración incorpora:

- Administración Global.
- Almacenes físicos múltiples.
- Distribución y transferencias entre almacenes.
- Catálogo maestro de artículos.
- Menús y recetas por negocio.
- Gestión de usuarios y permisos por ámbito.
- Tickets y finanzas independientes, con consulta consolidada.
- Preparación administrativa y visual del POS de Panadería.
- Ampliación posterior del Asistente de IA.

No se implementarán simultáneamente todos estos componentes.

## 2. Principios obligatorios

### 2.1 Protección de Restaurante

Restaurante es la referencia funcional estable.

Se conservarán:

- Punto de venta con mesas.
- Comandas.
- Reservaciones.
- Mapa.
- KDS e impresión.
- Menú y recetas existentes.
- Inventario y movimientos históricos.
- Tickets y cobros.
- Finanzas y analíticas.
- Autenticación y permisos existentes.

Solo se modificarán cuando una dependencia de la migración lo requiera.

Ante conflictos con el comportamiento existente, priorizar `main`.

### 2.2 Reutilización

Compartir componentes visuales, validaciones y servicios técnicos siempre que sea conveniente.

No duplicar controladores y formularios completos por negocio.

Tampoco forzar un único flujo operativo para puntos de venta con necesidades distintas.

### 2.3 Aislamiento

Cada operación tendrá un ámbito autorizado.

Ningún negocio podrá consultar o modificar información privada de otro sin autorización explícita.

El aislamiento se verificará en servidor, consultas SQL y servicios.

### 2.4 Migración controlada

Los cambios se realizarán por etapas pequeñas.

Cada etapa tendrá:

- Alcance delimitado.
- Contratos aprobados.
- Pruebas específicas.
- Regresión de Restaurante.
- Validación visual cuando corresponda.
- Commit independiente.
- Criterio explícito de aceptación.

No avanzar a la siguiente etapa con regresiones nuevas.

---

## 3. Organización y contextos

Los contextos son:

### Global

Administración de toda Casa Pestalozzi.

Funciones:

- Visibilidad consolidada.
- Administración de usuarios y permisos.
- Catálogo maestro.
- Creación y configuración de almacenes.
- Operación de almacenes centrales.
- Transferencias.
- Finanzas y tickets consolidados.
- Asistente de IA de alcance organizacional, posteriormente.

### Restaurante

Operación propia:

- POS existente.
- Menú y recetas.
- Almacenes asignados.
- Transferencias.
- Usuarios autorizados.
- Tickets, finanzas y demás módulos actuales.

### Panadería

Operación independiente:

- Menú y recetas propios.
- Almacenes asignados.
- Transferencias.
- Usuarios.
- Tickets y finanzas propios.
- POS de venta inmediata, en una etapa posterior.

La navegación se controlará mediante el selector del sidebar.

La interfaz compartirá layout, tablas, formularios y componentes.

---

## 4. Contrato de identidad y permisos

La autorización debe considerar:

1. Identidad del usuario.
2. Ámbito autorizado.
3. Módulo solicitado.
4. Nivel de acceso: lectura o edición.
5. Operación concreta y recursos involucrados.

Un usuario podrá disponer de permisos diferentes en varios ámbitos cuando Global lo autorice.

### Reglas

- Global puede administrar usuarios y permisos de cualquier ámbito, según sus privilegios.
- Los administradores de negocio solo pueden gestionar cuentas dentro de su ámbito.
- Ningún administrador puede conceder permisos superiores a los que tiene delegados.
- Los permisos de edición incluyen lectura.
- La ausencia de un permiso implica denegación.
- El contexto seleccionado en la interfaz nunca constituye autorización.
- Las API deben comprobar permisos y pertenencia de los recursos.

### Restricción arquitectónica

Reutilizar `Auth`, `Permisos`, `Contextos`, `roles`, `rol_permisos` y `unidades` según existan en la base integrada.

No introducir otra autenticación.

La Etapa 1 deberá definir cómo representar accesos a varias unidades y permisos por módulo sin romper roles existentes.

También deberá decidir cómo representar formalmente los almacenes propios de Global y los usuarios con acceso global.

---

## 5. Contrato de almacenes

### 5.1 Estructura lógica

Organización
→ Ámbito propietario
→ Almacén físico
→ Artículos y existencias.

Global puede consultar la organización completa y operar sus almacenes centrales.

Restaurante y Panadería pueden operar sus almacenes propios.

### 5.2 Propiedad

- Un almacén físico tiene un único propietario.
- Global puede crear almacenes para cualquier ámbito.
- Solo Global puede crear nuevos almacenes.
- Cada ámbito puede tener uno o varios almacenes.
- Los negocios pueden consultar y gestionar los almacenes propios, según permisos.
- No se permite modificar existencias de otro negocio mediante un selector de almacén.

### 5.3 Inventario consolidado

El inventario consolidado es una consulta, no un almacén físico.

Debe presentar:

- Artículo.
- Unidad de medida.
- Existencias totales.
- Total por ámbito.
- Distribución por almacén.
- Mínimos y alertas.
- Movimientos históricos.
- Estado del artículo.

El detalle del artículo incluirá una visualización gráfica de distribución con hover en escritorio y alternativa accesible por toque o tabla.

### 5.4 Entradas

Toda entrada requiere un almacén físico.

No podrá registrarse una cantidad únicamente en Global sin especificar su destino.

### 5.5 Negativos

Cada almacén tendrá una configuración:

`permitir_negativos`

Si está habilitada, se permiten operaciones que generen saldo negativo.

Si está deshabilitada, se bloquean las salidas insuficientes.

La validación será transaccional y aplicará a todas las operaciones que descuenten existencias, salvo excepciones funcionales expresamente definidas.

---

## 6. Contrato de artículos

Existirá un catálogo maestro de artículos inventariables.

Cada artículo tendrá una identidad única y podrá estar asociado a uno o varios almacenes.

Las existencias serán independientes por almacén.

### Ejemplo

Fresa:

- Almacén maestro: 6 kg.
- Cocina: 2 kg.
- Maridaje: 1 kg.
- Panadería: 1 kg.

Total consolidado: 10 kg.

Ninguna de estas cantidades se almacenará dos veces.

### Artículos específicos

Un artículo puede estar disponible únicamente en uno o varios almacenes.

La creación y modificación de atributos compartidos corresponde a Global.

Los negocios gestionan sus existencias, no la identidad maestra del artículo.

Distinguir expresamente los artículos inventariables de los productos comerciales del menú.

---

## 7. Contrato de movimientos

Cada movimiento debe identificar:

- Artículo.
- Almacén físico.
- Tipo.
- Cantidad con signo o dirección consistente.
- Fecha.
- Usuario u origen automatizado.
- Referencia de operación.
- Motivo cuando corresponda.
- Costo histórico cuando aplique.

Los movimientos deberán ser trazables y consistentes con las existencias.

Las cancelaciones deberán referenciar los movimientos originales para reponer en el almacén correcto.

No determinar el almacén de reposición utilizando la configuración vigente si esta ha cambiado desde la venta.

Las operaciones sobre varios artículos o almacenes deberán ser atómicas cuando formen parte de una misma transacción lógica.

---

## 8. Contrato de transferencias

Una transferencia identifica obligatoriamente:

- Almacén origen.
- Ámbito propietario del origen.
- Almacén destino.
- Ámbito propietario del destino.
- Artículos y cantidades.
- Usuario solicitante.
- Usuario que realiza el envío.
- Usuario receptor.
- Estado e historial de acciones.

### Transferencias entre ámbitos

Requieren confirmación de recepción del destinatario.

La operación debe distinguir:

- Solicitud.
- Envío.
- Mercancía en tránsito.
- Confirmación de recepción.
- Rechazo, cancelación o incidencia según corresponda.

La salida del origen y el ingreso al destino no deben duplicar existencias disponibles.

Global debe poder supervisar mercancía en tránsito.

### Movimientos dentro de un ámbito

Los traslados internos serán directos y requerirán autorización del usuario, pero no confirmación de un negocio contraparte.

### Restricciones

- No se generan cargos financieros automáticos.
- No se permite recibir dos veces la misma transferencia.
- No se permite enviar más cantidad que la disponible si el origen tiene negativos deshabilitados.
- Se conservará un historial auditable.
- Cada transición de estado debe ser válida y transaccional.

**Decisión pendiente:** procedimiento definitivo para diferencias entre cantidad enviada y recibida.

---

## 9. Contrato comercial

Los productos comerciales tendrán identidad compartida cuando corresponda, pero sus ofertas serán específicas de cada negocio.

Cada negocio podrá tener:

- Categorías propias.
- Menú propio.
- Precios propios.
- Disponibilidad propia.
- Recetas propias.
- Configuración de variantes propia o compartida, según necesidad.

Un mismo capuchino podrá tener precio y receta diferentes en Restaurante y Panadería.

No modificar una receta de Restaurante al configurar Panadería.

El consumo de un producto debe utilizar exclusivamente almacenes propios del negocio de la venta.

La configuración del origen de consumo se realiza desde administración.

---

## 10. Contrato de tickets y finanzas

Todo ticket nuevo debe pertenecer a un negocio.

Los tickets históricos de Restaurante conservarán su identidad y datos.

### Restaurante

Mantiene su operación con mesas y comandas.

### Panadería

Tendrá tickets de venta inmediata sin mesa.

### Global

Podrá consultar y filtrar tickets por negocio o toda la organización.

Los reportes financieros deberán:

- Identificar el negocio de cada operación.
- Mostrar resultados independientes.
- Permitir consolidación autorizada.
- Conservar importes y costos históricos.
- Excluir fixtures de demostración de los reportes reales.

La interfaz reutilizará las tablas, filtros, tarjetas y reportes existentes siempre que sea viable.

**Pendiente:** definición del procedimiento de cancelación y devolución de una venta ya cobrada de Panadería.

---

## 11. Contrato de usuarios

Global podrá crear y administrar cuentas con permisos en Global, Restaurante y Panadería.

Los administradores locales solo podrán crear y gestionar usuarios dentro de su ámbito y facultades delegadas.

La interfaz de Panadería reutilizará el módulo de usuarios existente.

La seguridad no dependerá exclusivamente de ocultar botones o rutas.

---

## 12. Contrato del Asistente de IA

El asistente deberá respetar los mismos permisos de datos que el usuario.

Restaurante consultará información de Restaurante.

Panadería consultará información de Panadería.

Global podrá consultar información consolidada o específica de un negocio.

La validación de ámbito debe aplicarse a las herramientas y consultas SQL que use el asistente.

No implementar este alcance global hasta definir y validar los asistentes operativos de cada negocio.

---

## 13. Interfaz y experiencia de usuario

### Navegación

- Selector de contexto debajo del logotipo.
- Identificador visual del ámbito activo.
- Menús condicionados por permisos.
- Diseño responsive.
- Componentes compartidos.
- Sin selectores de contexto repetidos en cada pantalla.

### Almacenes

La vista Global mostrará existencias totales y distribución.

Las vistas Restaurante y Panadería mostrarán:

- Total del negocio.
- Filtros por almacén.
- Detalle de existencias.
- Entradas, ajustes y mermas autorizadas.
- Movimientos.
- Transferencias.

Global será el único contexto desde el que se crearán almacenes.

### Otros módulos

Menú, Recetas, Usuarios, Tickets y Finanzas reutilizarán la estructura visual actual de Restaurante.

El POS de Panadería tendrá un diseño propio, sin mesas, y solo se definirá visualmente en el alcance inicial.

---

## 14. Datos de prueba

Crear o reutilizar fixtures aislados de Panadería para:

- Artículos.
- Almacenes.
- Existencias.
- Menú.
- Recetas.
- Tickets y finanzas ilustrativos cuando sean necesarios.

Los fixtures no deben contaminar Restaurante ni reportes organizacionales reales.

Las pruebas que escriban datos utilizarán bases desechables.

---

## 15. Funcionalidades pendientes

Fuera de la migración inicial:

- Catering.
- Producción avanzada.
- Transformaciones de materias primas.
- Control de botellas abiertas y cerradas.
- Nuevas variantes complejas.
- POS operativo completo de Panadería.
- Procedimiento definitivo de devolución de ventas cobradas.
- Asistente global antes de que funcionen los asistentes de cada negocio.

Estas funcionalidades no deben implementarse anticipadamente.

---

## 16. Contratos técnicos que deben definirse en la Etapa 1

Sol deberá concretar y documentar:

1. Modelo de ámbitos y permisos.
2. Identidad de almacenes y propietario.
3. Modelo de existencias por almacén.
4. API o interfaz del servicio de inventario.
5. Tipos y referencias de movimientos.
6. Estados de transferencias y transiciones.
7. Identidad del negocio en tickets y operaciones.
8. Relación entre productos, ofertas y recetas.
9. Criterios para filtrar consultas globales y locales.
10. Estrategia de migración de datos históricos.
11. Estrategia para mantener compatible Restaurante.
12. Contratos de errores y resultados para interfaces.

No basta con enumerar clases o tablas: documentar entradas, salidas, invariantes, responsables y pruebas.

---

## 17. Reglas de aceptación

Ninguna etapa podrá considerarse terminada sin verificar:

- Compatibilidad con Restaurante.
- Integridad de datos históricos.
- Autorización del servidor.
- Aislamiento entre ámbitos.
- Atomicidad cuando corresponda.
- Ausencia de duplicación de existencias.
- Pruebas automatizadas.
- Pruebas funcionales en navegador para cambios visuales.
- Documentación actualizada.

La integración a `main` debe ser posterior a la aceptación de cada entrega.

**Este documento es la referencia funcional. Las decisiones técnicas pendientes deben resolverse explícitamente antes de implementar los módulos que dependen de ellas.**