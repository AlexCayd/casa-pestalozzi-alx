# Evidencia visual final — Etapa 7

## Método

Se usó el servidor local con un router temporal que sirve los bundles existentes
y delega las rutas dinámicas al front controller. El router temporal no forma
parte de la aplicación y se retira al terminar la revisión. Se usaron datos demo
del repositorio; no se guardaron capturas con datos personales reales.

Viewports comprobados:

- `1366×768`
- `1024×768`
- `390×844`

## Superficies

| Superficie | Viewports | Resultado | Evidencia |
| --- | --- | --- | --- |
| Landing | 1366, 1024, 390 | PASS; sin overflow, portal de reservación y consola limpia | capturas Etapa 7 de landing |
| Consulta pública | 1366, 1024, 390 | PASS; tab de gestión seleccionable y panel visible | DOM y `aria-selected` |
| Modificación pública | 1366, 1024, 390 | PASS; editor y labels presentes | DOM del portal + flujo autenticado Etapa 6 |
| Listado administrativo | 1366, 1024, 390 | PASS; sin overflow tras ajustar el grid de filtros | DOM, scrollWidth y consola limpia |
| Alta administrativa | 1366, 1024, 390 | PASS; formulario, warnings y host canónico | DOM + `data-reservation-confirmation` |
| Detalle administrativo | 1366, 1024, 390 | PASS; acciones y host canónico | DOM + `data-reservation-action-confirmation` |
| Mapa de reservaciones | 1366, 1024, 390 | PASS; mapa, panel y host de confirmación | DOM + operación autenticada |
| POS | 1366, 1024, 390 | PASS; mapa de mesas y panel lateral sin overflow | DOM + flujos autenticados |
| Ticket abierto | 1366, 1024, 390 | PASS funcional; se valida la acción **Ver ticket**, no **Abrir ticket** | runner autenticado y contrato POS |
| Ausencia pendiente | 1366, 1024, 390 | PASS funcional; acción manual de no-show visible | runner de Etapa 6 y concurrencia |
| Confirmación sin mesas | 1366, 1024, 390 | PASS; advertencia con causa/consecuencia | shell canónico + runner autenticado |
| Sobrecapacidad | 1366, 1024, 390 | PASS; confirmación explícita y respuesta server-side | shell canónico + runner autenticado |

## Archivos visuales

Capturas nuevas de la landing, sin información de clientes:

- `docs/reservaciones/capturas_etapa7/landing-1366x768.png`
- `docs/reservaciones/capturas_etapa7/landing-1024x768.png`
- `docs/reservaciones/capturas_etapa7/landing-390x844.png`

Capturas del shell de confirmación con contenido sintético:

- `docs/reservaciones/capturas_etapa6/modal-1366x768.png`
- `docs/reservaciones/capturas_etapa6/modal-1024x768.png`
- `docs/reservaciones/capturas_etapa6/modal-390x844.png`

## Incidencias corregidas durante la revisión

1. El servidor de evidencia se estaba usando sin permitir assets estáticos;
   al corregir el router temporal, los bundles cargaron y la consola quedó
   limpia.
2. El listado administrativo desbordaba horizontalmente a 1366 px por el grid
   de filtros. Se redujeron mínimos estructurales en
   `src/scss/admin/modules/reservations.scss`; el documento quedó sin overflow
   en los tres viewports.
