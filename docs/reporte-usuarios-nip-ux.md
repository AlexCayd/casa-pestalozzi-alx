# Reporte — Simplificación UX del NIP

## Resultado

- El NIP configurado aparece como una línea compacta debajo del selector de
  rol, con la acción “Regenerar” y sin un bloque visual independiente.
- Al seleccionar `admin`, la línea y sus acciones desaparecen sin dejar
  espacio residual.
- Al cambiar de `admin` a `waiter` o `cook`, se muestra únicamente la
  indicación de generación automática al guardar.
- El modal posterior al commit conserva la entrega de cuatro dígitos, copia
  con feedback temporal y cierre no cancelable.
- La ventana de visualización es configurable desde
  `Services\\UsuarioConfig`; el modal usa una barra de progreso fina y cierra
  por “Aceptar” o automáticamente hacia el mismo destino.

## Alcance preservado

No se modificaron el algoritmo de NIP, los dígitos, el hash, el lookup HMAC,
el secreto, el login, los permisos, el rate limiting ni los módulos de
reservaciones, POS, KDS, tickets, OTP, n8n, inventario o finanzas.

## Verificación

- `npm.cmd test`: OK; contratos PHP y JavaScript completos.
- `php -l`: OK en la configuración y vistas modificadas.
- `node --check`: OK en los módulos JavaScript modificados.
- `npm.cmd run build`: OK; bundles administrativos compilados.
- No fue posible realizar la verificación manual porque no había un servidor HTTP del proyecto disponible.
