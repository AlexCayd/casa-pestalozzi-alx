# Reporte — Presentación visual del acceso de piso

## Resultado

- “Acceso de piso” y “Estado” comparten una subrejilla de dos columnas con
  título, control y descripción; en móvil se apilan.
- Los usuarios de piso existentes muestran “Regenerar NIP” como acción
  secundaria visible, sin competir con “Guardar cambios”.
- `admin` oculta completamente el bloque de acceso y el estado ocupa la fila
  disponible; admin → staff muestra sólo el aviso de generación futura.
- El modal posterior al commit mantiene la entrega de cuatro dígitos, copia
  con feedback temporal y cierre no cancelable.
- La ventana de visualización continúa siendo configurable desde
  `Services\\UsuarioConfig`; ahora el track y la barra activa son perceptibles.
- El tiempo configurable, el temporizador funcional y la lógica de cierre no
  cambiaron.

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
