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

- Integraciones ajenas a reservaciones: feedback entrante solo exige N8N_SECRET
  si está configurado (AreasMejora::secret); el disparador de áreas de mejora
  no añade autenticación (AdminController::llamarWebhook). Evaluar autenticación
  obligatoria y separación de secretos en una entrega de seguridad; el antiguo
  plan JWT no es implementación ni obliga a usar ese mecanismo.
- KDS: ticket_items no registra hora por transición. Definir medición antes de
  prometer tiempos de cocina/entrega; no reconstruirla desde el estado final.
- UI: revisar la diferencia de tipografía entre button.admin-btn y a.admin-btn
  causada por reglas globales. Requiere regresión visual de todos los módulos.
- Analítica: medir consultas e índices por unidad con EXPLAIN antes de proponer
  optimizaciones. Revisar agrupación por categoría/identidad cuando se renombre
  el catálogo; no modificar snapshots históricos para unificar series.

## Mejoras de funcionalidades existentes

| Módulo | Mejora | Límite |
|---|---|---|
| Usuarios | Presentación y filtros adicionales | Mantener permisos directos, delegación y componentes compartidos |
| Almacenes | Gráficas de distribución, filtros y UX avanzada | El consolidado seguirá siendo una consulta sin saldo virtual |
| Restaurante | Refinamientos posteriores de pantallas e informes | Preservar contratos operativos y precisión de importes |

## Funcionalidades nuevas pendientes

| Ámbito / dominio | Pendiente | Dependencias y restricciones |
|---|---|---|
| Almacenes | Transferencias | Solicitud, envío y tránsito supervisable por Global; recepción confirmada entre ámbitos, parcial/diferencias/pérdida/rechazo/retorno; traslado interno directo autorizado; transiciones atómicas, sin doble recepción ni cargos financieros automáticos |
| Panadería | Menú administrativo y recetas | Reutilizar oferta, recetas versionadas y UI de Restaurante |
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
