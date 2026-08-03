# Etapa 6.1 — Corrección de consulta indefinida de horarios en la landing

**Fecha:** 2026-08-03  
**Repositorio:** `C:/xampp/htdocs/casa-pestalozzi`  
**Estado:** corregida y validada por código, contrato y regresiones; validación visual Apache/XAMPP pendiente por disponibilidad del entorno  
**Alcance:** consulta pública de disponibilidad y ciclo de estado del selector de horarios. No se modificaron reglas de negocio, holds, OTP, POS, mapa, administración, esquema, modificación ni cancelación.

## 1. Resumen ejecutivo

La landing podía quedarse mostrando **“Consultando horarios…”** indefinidamente cuando el componente de hora no estaba disponible y el formulario entraba al fallback legacy. El fallback sólo devolvía `clear`, mientras `reloadAvailability()` esperaba `loadForDate()` y una promesa. Además, esa función dejaba `availabilityPending = true` si no encontraba un picker válido.

La corrección garantiza que toda consulta termine en un estado explícito: éxito, sin disponibilidad, restaurante cerrado, error HTTP/JSON, timeout, aborto o respuesta obsoleta. Se agregaron `finally`, timeout de 10 segundos, `AbortController`, secuencia de solicitudes, validación de fecha/personas, recuperación del fallback y una prueba JS de estados.

Resultado: **la consulta queda estable por contrato y código; la aprobación visual en desktop/mobile queda condicionada a ejecutar Apache/XAMPP desde un navegador con acceso al servidor local.**

## 2. Reproducción inicial

Se intentó reproducir el 2026-08-03 con:

- **fecha:** `2026-08-03`;
- **personas:** `2`;
- **URL pública esperada:** `http://localhost/casa-pestalozzi/public/`;
- **consulta:** `GET /api/reservaciones/disponibilidad?fecha=2026-08-03&personas=2`;
- **estado visual esperado:** `#hourDisplay` y `#hourStatus` en “Consultando horarios…”;
- **estado interno identificado:** `availabilityPending = true`, consulta pendiente y botón de avance bloqueado.

Apache no quedó escuchando en el puerto local. La navegación del navegador integrado terminó en `ERR_CONNECTION_REFUSED`, por lo que no se afirma una reproducción visual observada en pantalla ni se inventan métricas de consola. La causa se reprodujo estáticamente en el flujo fuente: el fallback no exponía `loadForDate()` y `reloadAvailability()` retornaba sin limpiar el estado.

## 3. Evidencia del flujo frontend

La landing monta el formulario en `views/home/_reserva.php` con `data-schedules-endpoint="/api/reservaciones/disponibilidad"` y carga `/build/js/bundle.min.js?v=reservations-public-redesign-v16` desde `views/home/index.php`.

Antes de la corrección:

1. `reloadAvailability()` marcaba el estado como `loading` y `availabilityPending = true`.
2. Si `timePicker` no existía o no tenía `loadForDate`, la función retornaba sin cerrar `availabilityPending`.
3. El fallback tenía una función `loadForDate()` interna, pero no la devolvía en su objeto público.
4. El fallback iniciaba `fetch()` sin devolver la promesa, por lo que el formulario no podía encadenar la finalización.
5. No existía timeout para una conexión que nunca resolviera.

Después:

- el fallback devuelve `loadForDate`, `setDisabled` y `clear`;
- toda variante de `loadForDate` devuelve una promesa;
- `reloadAvailability()` usa `Promise.resolve(...).then(...).catch(...).finally(...)`;
- un picker ausente termina como error recuperable;
- cada solicitud tiene un identificador propio y la respuesta vieja no puede mutar la selección vigente;
- cambiar fecha o personas invalida la solicitud anterior;
- los abortos obsoletos no muestran error al usuario;
- el timeout de 10 segundos libera el estado y muestra “Intenta nuevamente”.

## 4. Evidencia del backend

No fue necesario modificar el backend para corregir el ciclo indefinido. El controlador existente mantiene:

```text
GET /api/reservaciones/disponibilidad
```

La consulta no requiere CSRF porque es lectura. El endpoint responde JSON con `Cache-Control: no-store` y conserva la autoridad del núcleo de horarios, ocupación, elegibilidad y asignación.

Prueba HTTP local ejecutada contra el runtime PHP de la aplicación:

```text
Fecha: 2026-08-03
Personas: 2
Método: GET
Respuesta: HTTP 200
Content-Type: application/json; charset=utf-8
Tiempo observado: 134 ms
```

El cuerpo incluyó `ok: true`, `fecha`, `abierto: true`, horarios públicos con `{hora, disponible}`, disponibilidad y motivo de presentación. Una fecha sin disponibilidad respondió HTTP 422 con JSON y `horarios: []`, sin bloquear el frontend.

## 5. Causa raíz y evidencia

La causa raíz fue un contrato asíncrono incompleto entre `src/js/modules/form.js` y `src/js/components/reservation-time-picker.js`:

```text
reloadAvailability() -> espera timePicker.loadForDate(...).then(...)
fallback legacy    -> devuelve sólo { clear: ... }
```

La segunda condición era especialmente peligrosa porque `reloadAvailability()` ya había puesto el estado en `loading`. El tercer problema era que el fallback ejecutaba `fetch()` pero no retornaba su promesa. La ausencia de timeout dejaba una cuarta ruta sin finalización si el navegador conservaba la conexión abierta.

La evidencia posterior está cubierta por la prueba de estados: `loading`, `ready`, `unavailable`, `error`, aborto, obsolescencia y limpieza tienen salidas deterministas y conservan nombre/contacto.

## 6. Archivos modificados

- `src/js/modules/form.js`: fallback con promesa, timeout, manejo HTTP/JSON, cierre de estado, secuencia de solicitudes y `finally`.
- `src/js/components/reservation-time-picker.js`: timeout de 10 segundos, aborto seguro, parseo explícito, manejo de HTTP no exitoso y emisión de error recuperable.
- `src/js/components/reservation-form-state.js`: transición pura de disponibilidad para loading, respuestas, errores, abortos, obsolescencia y limpieza.
- `tests/js/reservation-form-state.test.js`: prueba automatizada requerida por `package.json`.
- `views/home/index.php`: versión de cache del bundle de la landing incrementada a `v17`.
- `public/build/js/bundle.min.js` y `public/build/js/bundle.js.min.map`.
- `assets/js/bundle.min.js` y `assets/js/bundle.js.min.map`.
- Este reporte.

No se modificaron `controllers/`, `services/`, `database/`, POS, mapa ni rutas de modificación/cancelación en Etapa 6.1.

## 7. Contrato final de disponibilidad

Ejemplo público representativo:

```http
GET /api/reservaciones/disponibilidad?fecha=2026-08-03&personas=2
Accept: application/json
```

```json
{
  "ok": true,
  "fecha": "2026-08-03",
  "abierto": true,
  "horarios": [
    { "hora": "12:00", "disponible": true },
    { "hora": "12:30", "disponible": true }
  ],
  "disponible": true,
  "motivo": "disponible",
  "alternativas": []
}
```

Para una respuesta cerrada o sin disponibilidad se conserva la terminación JSON, con `horarios: []` y un mensaje de presentación. Un error de validación o servidor se convierte en error recuperable para la interfaz; no se reintenta infinitamente.

## 8. Solicitudes simultáneas y respuestas obsoletas

El frontend conserva dos controles:

- `AbortController` cancela la consulta anterior cuando cambia fecha/personas o inicia una nueva consulta;
- `availabilityRequestId`, `requestId`, fecha/personas y `availabilityKey` descartan respuestas tardías.

Si una respuesta obsoleta llega después de una nueva selección, no cambia horarios, mensaje, disponibilidad ni `availabilityPending` de la selección vigente. Un aborto intencional no se presenta como error.

## 9. Manejo de errores y finalización

| Caso | Resultado final |
|---|---|
| HTTP 200 con horarios | `ready`, horarios visibles, control habilitado |
| HTTP 200 sin horarios | `unavailable`, control deshabilitado, mensaje comprensible |
| Restaurante cerrado | `closed`, horarios vacíos, mensaje de cierre |
| HTTP 4xx/5xx | `error`, consulta terminada y reintento manual posible |
| JSON inválido | `error`, consulta terminada y mensaje recuperable |
| Timeout de 10 s | `error`, aborta la consulta y libera loading |
| Aborto por nueva selección | se ignora sin error visible |
| Respuesta obsoleta | se ignora sin mutar el estado actual |
| Sin fecha o grupo grande | `idle`, no se hace consulta de disponibilidad |
| Selector ausente | `error`, no queda `availabilityPending` activo |

## 10. Assets realmente cargados

El build de la landing se genera con `gulpfile.js` desde `src/js/**/*.js` y se copia a ambos destinos:

- `public/build/js/bundle.min.js` para vistas bajo el webroot público;
- `assets/js/bundle.min.js` para la landing y usos estáticos.

Se ejecutó `node node_modules/gulp/bin/gulp.js js` después de la corrección. La vista home carga el bundle público con el query string `v=reservations-public-redesign-v17`, evitando reutilizar el bundle anterior en una sesión con caché.

## 11. Prueba JS automatizada

Comando:

```text
node tests/js/reservation-form-state.test.js
```

Resultado:

```text
PASS (loading/success/empty/http/json/abort/stale/guests/cleanup)
```

La prueba cubre carga, éxito, vacío, error HTTP, JSON inválido, aborto, respuesta obsoleta, cambios de personas, limpieza y conservación de nombre/contacto. También valida que un `requestId` viejo no pueda reemplazar el estado actual.

## 12. Pruebas PHP ejecutadas

- `php tests/php/etapa5_nucleo.php`: **PASS, 56/56**.
- `php tests/php/etapa6_publica.php`: **PASS, 46/46**.
- `php tests/php/etapa6_concurrencia.php`: **PASS, 1 retención creada + 1 duplicado, limpieza correcta**.
- `php tests/php/pos_reservacion_contrato.php`: **PASS**.
- `php tests/php/pos_reservacion_integrado.php --db=casa_pestalozzi_etapa4_test`: **PASS**.
- `php tests/php/etapa5_instalacion_limpia.php`: **PASS**, DDL/DML, smoke y eliminación de base temporal.
- `php tests/php/etapa4_estructura.php --db=casa_pestalozzi_etapa4_test`: **PASS**.
- `php -l` de PHP modificado: **PASS**.
- `node --check` de módulos y prueba JS: **PASS**.
- `git diff --check`: sin errores de whitespace; sólo advertencias de conversión LF/CRLF de Git.

## 13. Verificación visual desktop y mobile

No se otorga aprobación visual en esta ejecución. Se intentó abrir:

```text
http://localhost/casa-pestalozzi/public/
```

Apache/XAMPP reportó configuración válida con `httpd -t`, pero no quedó escuchando de forma persistente. El navegador integrado devolvió `ERR_CONNECTION_REFUSED` para localhost; tampoco pudo abrir el servidor PHP temporal en `127.0.0.1:8000` por la política de URLs del navegador.

Por tanto, quedan pendientes de confirmación manual en un navegador con acceso real a Apache/XAMPP:

- desktop: calendario, selector, transición loading → horarios y mensajes de error;
- mobile: ancho del selector, scroll del dropdown, botón de avance y mensajes accesibles;
- consola sin excepciones y Network con terminación de cada request.

## 14. Consola y Network antes/después

**Antes:** no se pudo capturar consola/Network de una landing servida porque localhost rechazó la conexión. La evidencia de código mostraba el estado visual de carga sin cierre cuando faltaba `loadForDate()`.

**Después, contrato de red:** el endpoint ejecutado localmente respondió JSON HTTP 200 para `2026-08-03`/2 personas y JSON HTTP 422 para una selección sin disponibilidad. El cliente ahora consume `response.text()` + `JSON.parse()` explícito, valida `response.ok`, cancela requests obsoletas y tiene timeout.

**Después, navegador:** pendiente de captura real porque el navegador integrado no pudo alcanzar Apache/XAMPP. No se reportan errores de consola visual como si hubieran sido observados.

## 15. Regresiones de creación, hold y OTP

Etapa 6 pública continúa pasando 46/46, incluyendo:

- creación atómica de retención;
- asignación y rollback lógico;
- hold de 15 minutos;
- hash y expiración de OTP;
- confirmación e idempotencia;
- límites por contacto y holds pendientes;
- expiración de retenciones.

La concurrencia continúa entregando exactamente una retención creada y un duplicado. No se modificaron las rutas de modificación o cancelación pública; siguen fuera de alcance.

## 16. Riesgos residuales

| Riesgo | Severidad | Tratamiento |
|---|---|---|
| No fue posible aprobación visual en Apache/XAMPP | Media | Repetir desktop/mobile desde un navegador con acceso al servidor local |
| Proveedor real de correo/WhatsApp sigue fuera de alcance | Media | Mantener la política de preview de desarrollo y auditar el proveedor en etapa separada |
| Fallback legacy conserva código duplicado | Baja | Mantenerlo con el mismo contrato asíncrono hasta retirar el flujo heredado |
| Cambios de infraestructura podrían alterar rutas de assets | Baja | Verificar que home siga cargando `/build/js/bundle.min.js` y que el build copie ambos destinos |

No se alteraron reglas de disponibilidad para esconder el problema ni se agregaron reintentos infinitos.

## 17. Decisión de cierre

**¿La landing puede consultar horarios de forma estable?** **Sí, con condiciones.**

El contrato backend, el ciclo asíncrono, timeout, abortos, respuestas obsoletas, fallback y pruebas automatizadas ya tienen terminación determinista. La condición pendiente es repetir la verificación visual desktop/mobile y consola/Network en un navegador que pueda abrir Apache/XAMPP local; esta ejecución no la presenta como aprobada.
