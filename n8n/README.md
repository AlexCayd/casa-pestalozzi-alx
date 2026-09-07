# Flujos de n8n

Exports importables de Casa Pestalozzi. n8n mantiene la ejecución en su propia base; exportar los cambios de su interfaz al repositorio.

| Archivo | Workflow |
|---|---|
| `reservaciones-comunicaciones.json` | Reservaciones - comunicaciones |
| `sugerencias.json` | Sugerencias |
| `areas-de-mejora.json` | Areas de mejora |

Reservaciones conserva un único workflow para `reservation.confirmed`,
`reservation.schedule_change` y `reservation.reminder_next_day`. Webhook y
Schedule Trigger cada cinco minutos convergen en email SMTP / WhatsApp Business
Cloud API (Send Template), con callback a PHP.

Importar mediante **Workflows → Import from File**, seleccionar el JSON y asignar
manualmente SMTP a Enviar email y WhatsApp Business Cloud API a Enviar WhatsApp.
Configurar variables, número TEST y templates; probar ambos canales y callbacks
antes de activar. La guía completa es [n8n de reservaciones](../docs/reservaciones/n8n.md).

Para comparar la instancia local: `node n8n/exportar.js`. El comando es de
sólo lectura y no escribe si detecta diferencias; revisar el diff y repetir con
`node n8n/exportar.js --write` para confirmar la actualización. No versionar
credentials, pinData, tokens, contactos ni ejecuciones. La exportación sin
credenciales es estructural y no demuestra transporte real.

Los contratos `N8N_WEBHOOK_SUGERENCIAS_URL` y `N8N_WEBHOOK_AREAS_MEJORA_URL`
permanecen separados.
