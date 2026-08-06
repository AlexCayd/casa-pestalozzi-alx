# B1 — Confirmación administrativa sin mesas

Captura accesible del estado visible en `1024×768`, tomada después de introducir 13 comensales, nombre sintético y hora válida en el alta administrativa.

```text
dialog "Confirmar sin mesas"
heading "Confirmar sin mesas"
button "Volver"
button "Asignar más tarde"
```

Contrato comprobado en runtime: la asignación automática está deshabilitada y no aparece `Asignar después`. La captura PNG del navegador conserva el formulario padre cuando el shell global se monta bajo `document.body`; por eso este archivo preserva el estado accesible exacto del `ConfirmationModal`.
