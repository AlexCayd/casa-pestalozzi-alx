# Estado de cambios sin commit

**Fecha del escaneo:** 26 de julio de 2026  
**Commit base:** `9dcc3cb` — *Temporizador y tipos de anuncios, horarios excepciones*  
**Staging:** vacío

## Resumen ejecutivo

El árbol de trabajo contiene una ampliación importante del módulo de reservaciones, ya integrada parcialmente con horarios, acceso por contacto y punto de venta. Antes de crear este reporte había **46 archivos rastreados modificados** y **27 archivos nuevos sin seguimiento**. El diff rastreado suma aproximadamente **3,826 inserciones y 252 eliminaciones**.

No parece un ajuste aislado: es un bloque funcional grande que toca backend, frontend, base de datos, scripts, pruebas y archivos compilados.

## Cambios principales detectados

- **Reservaciones públicas:** nuevas rutas para consultar disponibilidad, crear una retención temporal, confirmar una reservación verificada, modificarla, cancelarla y consultar “mis reservaciones”.
- **Verificación de contacto:** OTP por correo o teléfono, hash del código, caducidad, límite de intentos, sesión pública independiente y proveedor de notificaciones de desarrollo.
- **Disponibilidad y concurrencia:** nuevos servicios de disponibilidad y locks para contacto, fecha/horario y retenciones; también existe un script para vencer retenciones.
- **Horarios:** la configuración semanal y las excepciones operativas se amplían y se conectan con la disponibilidad pública.
- **Punto de venta:** una reservación puede iniciar su atención desde POS; se incorpora ocupación canónica N:M entre tickets y mesas y registro de eventos de la reservación.
- **Base de datos:** `database/ddl.sql` añade o amplía reservaciones, verificaciones de contacto, eventos y `ticket_mesas`; `database/dml.sql` incluye un bloque considerable de datos reproducibles.
- **Interfaz:** se modifica el formulario público de reservación, la gestión por contacto, la selección de horarios, el POS y la configuración administrativa. También se regeneraron CSS, JS y source maps.
- **Pruebas:** aparecen tres suites principales y dos workers de concurrencia, junto con un ejecutor común.

## Estado técnico observado

- Los **40 archivos PHP** modificados o nuevos pasan `php -l`.
- `git diff --check` no reporta espacios problemáticos ni conflictos de parche.
- No se ejecutaron las suites funcionales porque pueden depender de la base de datos local y alterar datos; sólo se realizó validación estática.
- Git advierte que varios archivos pasarán de **LF a CRLF** cuando vuelva a escribirlos.
- Los archivos de sesión generados bajo `tests/.sessions/` están ignorados por el `.gitignore` nuevo de esa carpeta.

## Riesgos y pendientes antes del commit

1. **Separar el cambio en commits revisables.** Actualmente conviven esquema, backend, frontend, POS, pruebas y builds; un solo commit dificultaría revisión y reversión.
2. **Revisar la eliminación de `storage/.gitignore`.** Si no es intencional, puede cambiar qué archivos temporales o vacíos conserva Git.
3. **Tratar el DDL como reinicialización, no como migración.** El archivo declara operaciones `DROP`/`CREATE`; no debe aplicarse sobre datos importantes sin respaldo y validación.
4. **Verificar configuración por ambiente.** `includes/.env.example` habilita la vista previa de OTP y deshabilita el envío real; producción debe usar valores seguros distintos.
5. **Ejecutar las pruebas con una base aislada.** Conviene validar especialmente retenciones concurrentes, vencimiento, consumo único del OTP y competencia por mesas.
6. **Probar manualmente los recorridos completos:** reserva nueva, reintento idempotente, modificación/cancelación pública, llegada, apertura/cierre de ticket y liberación de mesas.
7. **Confirmar archivos de despliegue y desarrollo.** Revisar el nuevo `public/.htaccess` y si ambos routers de desarrollo (`dev_router.php` y `php-dev-router.php`) son necesarios.
8. **Regenerar los builds al final.** Esto permite comprobar que los artefactos de `assets/` y `public/build/` corresponden exactamente al código fuente.

## División de commits sugerida

1. Esquema y modelos de reservaciones, OTP, eventos y mesas de ticket.
2. Servicios y controladores del flujo público de reservación.
3. Horarios, excepciones y locks de concurrencia.
4. Integración de reservaciones con POS.
5. Interfaz pública y administrativa, seguida por los assets compilados.
6. Scripts, pruebas y documentación.

## Conclusión

El proyecto está en una fase avanzada de una expansión del sistema de reservaciones, con la mayor parte de la estructura funcional ya presente y validación sintáctica limpia. El siguiente paso prudente es probar contra una base desechable, resolver los pendientes de archivos ignorados/configuración y dividir el trabajo antes de llevarlo a commits.
