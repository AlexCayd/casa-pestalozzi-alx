# Compatibilidad retirada — Etapa 6

La consolidación elimina compatibilidad ejecutable que ya no tiene consumidor
real. Se conserva únicamente compatibilidad de datos o de contenedores que
pertenecen a otra interacción.

## Retirado

| Elemento anterior | Acción | Sustituto |
| --- | --- | --- |
| `CPConfirmationModal` | Eliminado del source JS y de las vistas | `window.ConfirmationModal` |
| Alias `window.CPConfirmationModal` | Eliminado; no se mantiene puente ejecutable | API canónica |
| `.operation-confirm-modal` y sus estilos de conflicto | Eliminados | Host `data-operation-confirmation-host` + shell canónico |
| `.admin-modal` estático de acciones de reservación | Eliminado del detalle | Host `data-reservation-action-confirmation` + formulario oculto |
| Sección pública `.reservation-card__editor-comparison` | Eliminada como sección autónoma | `summaryRows` dentro del shell |
| Confirmaciones nativas de asignación/acciones operativas | Eliminadas | Shell canónico con causa, resumen, consecuencia y acciones |

## Conservado intencionalmente

- `.mesa-modal` del POS continúa siendo el contenedor de ticket, selección de
  mesa y wizard de cobro. No es un segundo shell de confirmación.
- Los formularios persistentes de creación y asignación continúan siendo
  contenedores de trabajo; sólo sus decisiones destructivas o irreversibles
  abren `ConfirmationModal`.
- Campos y nombres de transporte existentes (`request_token`, `csrf_token`,
  `reservacion_id`, `confirmaciones[]`) se conservan porque forman parte de
  contratos backend y no son shells visuales.
- El diálogo nativo de cierre de sesión y avisos técnicos fuera del flujo de
  reservaciones quedan fuera de esta etapa.

## Verificación

El runner estático comprueba que no queden referencias a
`CPConfirmationModal` en `src/js` ni en `views`. El build posterior confirma
que los bundles activos incorporan el shell canónico.
