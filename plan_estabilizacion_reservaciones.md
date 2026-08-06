# Plan de estabilización del módulo de reservaciones

**Estado del documento:** historial de ejecución cerrado.
**Última actualización:** 2026-08-06.
**Fuente normativa:** `reservaciones_fuente_de_verdad.md`.

Este archivo conserva el objetivo, el orden y los resultados principales de la
estabilización. No define reglas funcionales nuevas: cuando exista una duda,
la única referencia vigente es la fuente de verdad.

## Objetivo histórico

Reducir las regresiones del módulo de reservaciones hasta que landing,
administración, mapa y POS compartan horario, ocupación, capacidad, asignación,
estados, errores, seguridad y componentes de confirmación canónicos.

## Estado de las etapas

| Etapa | Objetivo principal | Resultado | Evidencia |
| --- | --- | --- | --- |
| 1 | Congelación, alcance y línea base | CERRADA | `docs/reservaciones/linea_base_regresiones.md` |
| 2 | Catálogo y contrato de errores | CERRADA | `docs/reservaciones/catalogo_errores.md` |
| 3 | Centralización de códigos y respuestas | CERRADA | `docs/reservaciones/migracion_codigos_etapa3.md` |
| 4 | Motor temporal y ausencia pendiente | CERRADA | `docs/reservaciones/reporte_etapa4_motor_temporal.md` |
| 5 | Capacidad y libertad administrativa | CERRADA | `docs/reservaciones/reporte_etapa5_capacidad.md` |
| 6 | Shell modal y flujos autenticados | CERRADA | `docs/reservaciones/reporte_etapa6.md` |
| 7 | Limpieza, validación integral y cierre | CERRADA | `docs/reservaciones/reporte_etapa7.md` |

## Resultados consolidados

- El catálogo de errores es canónico y las superficies comparan códigos, no
  mensajes.
- Horarios, tolerancia, liberación estimada y proyección temporal se resuelven
  en servicios compartidos.
- La capacidad distingue asientos físicos, demanda sin mesas y asignación
  automática; administración puede confirmar mediante decisión explícita.
- POS y mapa resuelven el mismo ticket abierto desde cualquiera de sus mesas y
  las mutaciones críticas son transaccionales e idempotentes.
- Las confirmaciones usan `window.ConfirmationModal` con foco, Escape, `inert`,
  retorno de foco y comportamiento responsive documentado.
- Las validaciones de Etapa 7 agregan instalación limpia, concurrencia, suite
  integral, revisión de consultas, seguridad, accesibilidad, observabilidad y
  evidencia visual final.

## Orden de ejecución conservado

1. Congelar la fuente normativa y la línea base.
2. Inventariar errores, contratos y consumidores.
3. Corregir el motor temporal.
4. Corregir capacidad y asignación.
5. Integrar tickets, ausencia y mapa/POS.
6. Consolidar catálogo, modales y flujos autenticados.
7. Ejecutar limpieza, instalación limpia, concurrencia y suite integral.
8. Documentar resultados y cerrar el working tree.

## Criterio de cierre

La implementación se considera estabilizada cuando el reporte de Etapa 7 indica
qué se retiró, qué se conserva y por qué; los runners reproducibles pasan; la
fuente normativa no contradice el código; los documentos históricos no compiten
con ella; y los cambios pendientes del usuario están identificados de forma
explícita, sin incorporarlos automáticamente a los commits de estabilización.
