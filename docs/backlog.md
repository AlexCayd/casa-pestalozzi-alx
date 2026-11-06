# Backlog posterior a 3E

## Estado posterior a M2

Productos de la casa y sus políticas dejaron de ser una funcionalidad operativa
en A4; no se planea un módulo equivalente. Los datos históricos se preservan.
M2 incorpora artículos unificados, elaboraciones anidadas e inmutables,
presentaciones y variantes administrativas de Panadería, con un único resolvedor
y snapshots históricos. La caja de mostrador de Panadería ya opera (arquitectura
§7). Siguen pendientes integrar presentaciones al POS de Panadería (M3),
producción física por lotes (M2.5) y módulos consolidados.
Las recetas mínimas de fixtures requieren fichas culinarias completas antes de
cualquier uso productivo. No se autoriza comenzar estos trabajos en esta entrega.


El [contrato rector](arquitectura.md) distingue funciones operativas, contratos
preparados y módulos pendientes. Este backlog no autoriza implementarlos como
parte del cierre ni convierte una tabla existente en un módulo terminado.

## Defectos y deuda técnica

Los defectos críticos descubiertos durante la aceptación deben resolverse antes
de presentar la rama como candidata. No trasladar fallos de seguridad, aislamiento,
stock, cobro o pérdida del menú a una lista de mejoras.

Las incidencias arquitectónicas registradas en
[pendientes arquitectónicos](correcciones_pendientes_arquitectura.md) conservan sus
identificadores y requieren evaluación al tocar sus dominios: dependencia del
Model de usuario en `NipService`, preparación de horarios desde vistas,
normalización de horas entre dominios y llamadas recíprocas de horario/impactos.
Son deuda técnica identificada, no una solicitud de refactorización masiva en 3E.

## Deuda conocida a evaluar por dominio

- Integraciones: feedback entrante ya exige JWT HS256 por audiencia. Los exports
  de reservaciones requieren adaptar sus HTTP Request en la instancia antes de
  desplegar; PHP rechaza sus headers anteriores. La firma JWT saliente tiene
  helper, pero los consumidores actuales conservan sus contratos de transporte.
  El disparador de áreas de mejora sigue sin autenticación saliente propia.
  Consultar [API](../API.md#9-jwt-de-integraciones), sin restaurar el antiguo plan.
- KDS: ticket_items no registra hora por transición. Definir medición antes de
  prometer tiempos de cocina/entrega; no reconstruirla desde el estado final.
- UI: revisar la diferencia de tipografía entre button.admin-btn y a.admin-btn
  causada por reglas globales. Requiere regresión visual de todos los módulos.
- Analítica: medir consultas e índices por unidad con EXPLAIN antes de proponer
  optimizaciones. Revisar agrupación por categoría/identidad cuando se renombre
  el catálogo; no modificar snapshots históricos para unificar series.

M1 entrega [Catálogo comercial y Menú](modulos/catalogo-comercial.md): administración
local de ofertas y categorías, asociación de identidades y edición maestra Global
con facultad comercial independiente. La carta pública y ventas de Panadería
siguen pendientes; no habilitar sus acciones desde Menú.

## Mejoras de funcionalidades existentes

| Módulo | Mejora | Límite |
|---|---|---|
| Usuarios | Presentación y filtros adicionales | Mantener permisos directos, delegación y componentes compartidos |
| Almacenes | Refinamientos posteriores a M1.1 | Barras por negocio, indicadores físicos y alta rápida con doble autorización ya disponibles; mantener consulta sin saldo virtual |
| Restaurante | Refinamientos posteriores de pantallas e informes | Preservar contratos operativos y precisión de importes |

## Funcionalidades nuevas pendientes

M1.1 entrega PDF privado Panadería y biblioteca Global autorizada. La publicación
pública Panadería, POS y producción física siguen pendientes. M2 completa sus
recetas administrativas; la revisión culinaria de datos de cada negocio sigue
siendo una tarea operativa previa a su uso real.
Validación histórica pendiente: adaptar `run-contextos-isolated.php` al esquema de
permisos directos; revisar expectativa temporal de `run-pos-api-contrato-db.php`;
actualizar fixtures de impresión POS (`unidad_id`) y llevar múltiple (actor 0).
Estos runners fallan en la revisión M1.1; no se rebajaron sus aserciones.

| Ámbito / dominio | Pendiente | Dependencias y restricciones |
|---|---|---|
| Almacenes | Resolución de pérdidas definitivas | M0.5-B ya incorpora solicitud, autorización, envío, recepción parcial, rechazo/cancelación y retorno. Una pérdida permanece como incidencia trazable; definir un cierre de pérdida explícito sin inventar retorno, recepción ni cargos financieros |
| Artículos | Retiro progresivo de adaptadores anteriores | Asociaciones explícitas verificadas, cero escritores anteriores y suites de regresión antes del retiro; conservar tablas históricas |
| Panadería | Pago mixto, transferencia/QR y propina en caja | La caja v1 cobra efectivo o tarjeta; ampliar `metodo_pago` y Finanzas antes de habilitarlos |
| Panadería | Encargos y venta por peso | Encargos con fecha y anticipo; `ticket_items.cantidad` sólo admite piezas enteras |
| Panadería | Turnos del corte y estación de impresión en su panel | Hoy se leen de `configuracion_pos`/`impresoras` de la unidad sin pantalla propia |
| Global | Tickets consolidados | Contratos de propietario y agregación; no prometidos operativos |
| Global | Ventas consolidadas en el Asistente | El Asistente de Global ya lee el consolidado de almacenes; sumar ventas de los negocios espera el contrato de lecturas consolidadas sin doble conteo |
| Panadería | Ventas por cajero en el Asistente | La caja guarda al cajero en `mesero_id`; falta la pregunta |
| Inventario | Producción y transformación de ingredientes | Definir entradas/salidas, rendimiento, costo, merma y trazabilidad sin duplicar saldos |
| Inventario | M2.5: ejecución física de apertura y transformación | M2 ya modela botella sellada, vino abierto, copa y variantes; registrar entradas/salidas, rendimiento real y costo sin apertura automática al vender |
| Nuevos dominios | Catering | Requiere alcance, entidades, reglas y aceptación propios |
| Comercial | Devoluciones adicionales | Definir cancelación/devolución de venta cobrada de Panadería; no ampliar la cancelación vigente por inferencia |
| Usuarios | Plantillas futuras | Requieren decisión arquitectónica explícita; hoy solo permisos directos |
| Analítica | Identidad voluntaria de clientes walk-in | Definir propósito, consentimiento y privacidad antes de medir recurrencia |
| Impresión | Precuenta | Definir impresión antes del cobro sin cambiar estado del ticket |

## Cómo incorporar una entrega

Identificar estado y ámbito; revisar servicios y DDL existentes; documentar el
contrato; implementar con la interfaz de Restaurante; probar autorización,
aislamiento, integridad y regresión; actualizar documentación y evidencia.
Las reglas completas están en la [guía de desarrollo](desarrollo/desarrollo.md).
