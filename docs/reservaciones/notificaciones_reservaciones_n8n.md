# Notificaciones de reservaciones con n8n

Este nombre se conserva como enlace de compatibilidad documental. El contrato
normativo vigente está en
[Arquitectura de comunicaciones de reservaciones con n8n](arquitectura_notificaciones_reservaciones_n8n.md).

La implementación usa un único workflow versionado, acceso público genérico y
estados de transporte separados del estado de dominio. No se deben recuperar
de revisiones anteriores claves de deduplicación basadas en hora, rutas
específicas por evento ni tablas que dupliquen PII.
