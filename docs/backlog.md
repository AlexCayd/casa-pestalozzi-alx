# Backlog posterior a 3E

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
pública Panadería, sus recetas completas, POS y producción siguen pendientes.
Validación histórica pendiente: adaptar `run-contextos-isolated.php` al esquema de
permisos directos; revisar expectativa temporal de `run-pos-api-contrato-db.php`;
actualizar fixtures de impresión POS (`unidad_id`) y llevar múltiple (actor 0).
Estos runners fallan en la revisión M1.1; no se rebajaron sus aserciones.

| Ámbito / dominio | Pendiente | Dependencias y restricciones |
|---|---|---|
| Almacenes | Resolución de pérdidas definitivas | M0.5-B ya incorpora solicitud, autorización, envío, recepción parcial, rechazo/cancelación y retorno. Una pérdida permanece como incidencia trazable; definir un cierre de pérdida explícito sin inventar retorno, recepción ni cargos financieros |
| Panadería | M2: recetas administrativas completas | Menú M1 completado; reutilizar recetas versionadas sobre ofertas propias |
| Panadería | POS operativo sin mesas | Negocio vendedor propio; autorización y consumo de sus almacenes |
| Panadería | Tickets, pagos y finanzas | Snapshots, idempotencia, compensaciones e informes propios |
| Global | Dashboards consolidados | Lectura autorizada, sin ventas Global ni doble conteo |
| Global | Tickets y finanzas consolidados | Contratos de propietario y agregación; no prometidos operativos |
| Global | Asistente organizacional | Definir/validar primero asistentes locales; herramientas y SQL con ámbito y permisos de servidor |
| Inventario | Producción y transformación de ingredientes | Definir entradas/salidas, rendimiento, costo, merma y trazabilidad sin duplicar saldos |
| Inventario | Botellas abiertas/cerradas y variantes complejas | Definir alcance y aceptación; no inferir soporte desde entidades existentes |
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
