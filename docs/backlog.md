# Backlog posterior a 3E

El [contrato rector](contrato.md) distingue funciones operativas, contratos
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

## Mejoras de funcionalidades existentes

| Módulo | Mejora | Límite |
|---|---|---|
| Usuarios | Presentación y filtros adicionales | Mantener permisos directos, delegación y componentes compartidos |
| Usuarios | Gestión avanzada de permisos y futuras plantillas | Las plantillas no forman parte de 3E |
| Almacenes | Gráficas de distribución, filtros y UX avanzada | El consolidado seguirá siendo una consulta sin saldo virtual |
| Restaurante | Refinamientos posteriores de pantallas e informes | Preservar contratos operativos y precisión de importes |

## Funcionalidades nuevas pendientes

| Ámbito / dominio | Pendiente | Dependencias y restricciones |
|---|---|---|
| Almacenes | Transferencias | Definir recepción parcial, diferencias, pérdidas, rechazo y retorno; no activadas |
| Panadería | Menú administrativo y recetas | Reutilizar oferta, recetas versionadas y UI de Restaurante |
| Panadería | POS operativo sin mesas | Negocio vendedor propio; autorización y consumo de sus almacenes |
| Panadería | Tickets, pagos y finanzas | Snapshots, idempotencia, compensaciones e informes propios |
| Global | Dashboards consolidados | Lectura autorizada, sin ventas Global ni doble conteo |
| Global | Tickets y finanzas consolidados | Contratos de propietario y agregación; no prometidos operativos |
| Global | Asistente organizacional | Herramientas con ámbito y permisos de servidor |
| Nuevos dominios | Producción y catering | Requieren alcance, entidades, reglas y aceptación propios |
| Comercial | Devoluciones adicionales | Definir casos posteriores al cierre; no ampliar la cancelación vigente por inferencia |

## Cómo incorporar una entrega

Identificar estado y ámbito; revisar servicios y DDL existentes; documentar el
contrato; implementar con la interfaz de Restaurante; probar autorización,
aislamiento, integridad y regresión; actualizar documentación y evidencia.
Las reglas completas están en la [guía de desarrollo](desarrollo/desarrollo.md).
