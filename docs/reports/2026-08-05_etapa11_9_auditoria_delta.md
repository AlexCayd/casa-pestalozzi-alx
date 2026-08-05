# Etapa 11.9 — Auditoría delta contra Etapa 11.8

Fecha de corte: 2026-08-05  
Referencia base: `docs/reports/2026-08-04_etapa11_8_auditoria_conformidad_reservaciones.md`, su matriz y el inventario de limpieza.

## Delta de decisión

Etapa 11.8 identificó cinco divergencias funcionales y dos divergencias documentales que impedían una limpieza final segura. Etapa 11.9 las cierra o las deja explícitamente acotadas:

| Hallazgo | Estado delta | Evidencia principal |
|---|---|---|
| F-01 | Cerrado | Revalidación canónica final y rollback en `confirmarReemplazo()` |
| F-02 | Cerrado | Holds incluidos en ocupación POS y validación final de walk-in/ticket |
| F-03 | Cerrado | CSRF incondicional para creación pública verificada |
| F-04 | Cerrado | Sin rutas/vista web de borrado; CLI aislado con guardas |
| F-05 | Cerrado | `StaffCsrfService` común para todas las mutaciones POS |
| D-01 | Cerrado | Visibilidad y máximo por fecha local del restaurante |
| D-02 | Documentado | `database/ddl.sql` + `database/dml.sql` |
| D-03 | Documentado | Índice que apunta al nombre real del reporte de Etapa 8 |
| D-04 | Verificado | Una sola sección 15 en la fuente de verdad |
| D-05 | Reconciliado | Barrera de Etapa 9.5 sincronizada antes del snapshot |
| P-01 | Deuda controlada | Alias heredado documentado; no se agrega un segundo OTP |

## Cambios de contrato y alcance

La fuente de verdad documenta antes del código:

1. la revalidación final con original y reemplazo bloqueados;
2. la inclusión de holds en la ocupación canónica del POS;
3. CSRF obligatorio para todas las mutaciones públicas y para mutaciones POS autenticadas;
4. la prohibición de borrado físico desde interfaces web;
5. la fecha actual del restaurante como límite de visibilidad y cuenta.

En código se tocaron servicios canónicos de ocupación, asignación y vigencia, el controlador público, el controlador y vista POS, la pantalla de herramientas de desarrollo, el router, la suite de concurrencia y los bundles generados. No se añadió tabla, columna, enum, ruta de negocio ni alias nuevo. Las únicas rutas retiradas son las dos acciones web destructivas de mantenimiento.

## Instalación limpia y regresión

`tests/php/etapa11_9_instalacion_limpia.php` crea una base `casa_pestalozzi_etapa119_clean_*`, ejecuta `database/ddl.sql` y `database/dml.sql`, corre los casos focalizados, encadena Etapa 9.5 reconciliada, Etapa 11.5 y Etapa 11.7.2, y finalmente elimina la base temporal. La ejecución final devolvió `ok=true` y `dropped=true` en todos los niveles.

Los casos focalizados verifican que:

- un conflicto introducido después de crear el reemplazo no cambia la reserva original;
- un hold vigente no permite crear un walk-in ni abrir el ticket de una reserva;
- una creación pública sin CSRF no inserta una fila;
- una mutación POS sin CSRF no crea un ticket;
- hoy pasado sigue visible, ayer queda fuera y el máximo cuenta holds nuevos del día sin contar el reemplazo pendiente;
- la limpieza destructiva no está expuesta por web.

## Decisión de continuidad

Etapa 12 permanece no iniciada por alcance explícito de la solicitud. También quedan fuera de esta entrega la consolidación de aliases, la eliminación de compatibilidad P-01 y cualquier limpieza destructiva sobre la base activa.
