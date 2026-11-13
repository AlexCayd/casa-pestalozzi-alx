# Pruebas con curl de las integraciones n8n (JWT)

Comprueba con `curl` que los cuatro endpoints entrantes de n8n
([API §2 y §9](docs/API.md#2-integraciones-máquina-a-máquina)) funcionan y que su
JWT no se puede saltar ni reutilizar en otro endpoint.

| Ruta (POST) | Audiencia (`aud`) |
| --- | --- |
| `/api/integraciones/n8n/reservaciones/recordatorios/preparar` | `reservaciones.recordatorios.preparar` |
| `/api/integraciones/n8n/reservaciones/recordatorios/reclamar` | `reservaciones.recordatorios.reclamar` |
| `/api/integraciones/n8n/reservaciones/notificacion-resultado` | `reservaciones.notificacion-resultado` |
| `/api/integraciones/n8n/feedback/areas-mejora` | `feedback.areas-mejora` |

Contrato que se prueba (`Services\Security\JwtIntegracion`, `Auth::protegerIntegracion`):

- `Authorization: Bearer <jwt>`, firmado con HS256 y `JWT_N8N_ENTRADA_SECRET`.
- Claims obligatorios: `iss=n8n`, una sola `aud` exacta, `iat`, `exp` y `jti`.
- Vida (`exp − iat`) de 300 s como máximo y tolerancia de reloj de 30 s.
- Todo rechazo responde **401** con el mismo cuerpo, `{"ok":false,"codigo":"N8N_JWT_INVALIDO"}`,
  más `WWW-Authenticate: Bearer error="invalid_token"` y `Cache-Control: no-store`.
  El motivo sólo va al log.
- Una ruta bajo `/api/integraciones/` que no esté en `Permisos::INTEGRACIONES` no
  tiene audiencia y ningún token la abre.
- Sin secreto configurado, o con uno de menos de 32 bytes, responde **500**
  `N8N_JWT_NO_CONFIGURADO`.

> **Sólo en un entorno de prueba.** `preparar` crea recordatorios y devuelve datos
> de contacto con enlaces de gestión válidos. `reclamar` y `notificacion-resultado`
> cambian su estado, y `areas-mejora` reemplaza las tarjetas del panel. No ejecutar
> contra producción ni con el secreto real; tampoco pegar tokens en tickets o chats.

## 1. Preparación

### 1.1 Entorno

1. Base de desarrollo nueva (`php scripts/desarrollo/instalar.php`, ver
   [instalación](docs/desarrollo/instalacion.md)).
2. En `includes/.env` del entorno de prueba, un secreto **de prueba** distinto del
   de salida:

   ```sh
   openssl rand -hex 32   # 64 caracteres; copiarlo a JWT_N8N_ENTRADA_SECRET
   ```

3. Servidor local:

   ```sh
   php -S 127.0.0.1:8080 -t public
   ```

4. Herramientas: Git Bash o Linux con `curl`, `openssl` y `jq`.

### 1.2 Funciones auxiliares

Pegar en la misma terminal. El secreto se lee del entorno y nunca se imprime.

```sh
export BASE="http://127.0.0.1:8080"
export R="/api/integraciones/n8n"
read -rs -p "JWT_N8N_ENTRADA_SECRET de prueba: " JWT_N8N_ENTRADA_SECRET; echo
export JWT_N8N_ENTRADA_SECRET

b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

# firmar HEADER PAYLOAD [SECRETO] [DIGEST] → JWT. La firma usa DIGEST (sha256 por
# omisión) aunque el header diga otra cosa: así se prueban headers que mienten.
firmar() {
  local h p s
  h=$(printf '%s' "$1" | b64url)
  p=$(printf '%s' "$2" | b64url)
  s=$(printf '%s' "$h.$p" | openssl dgst "-${4:-sha256}" -hmac "${3:-$JWT_N8N_ENTRADA_SECRET}" -binary | b64url)
  printf '%s.%s.%s' "$h" "$p" "$s"
}
HS256='{"alg":"HS256","typ":"JWT"}'

# claims AUD [DESPLAZAMIENTO_IAT] [VIDA] [ISS] → payload con jti aleatorio
claims() {
  local iat=$(( $(date +%s) + ${2:-0} ))
  printf '{"iss":"%s","aud":"%s","iat":%d,"exp":%d,"jti":"%s"}' \
    "${4:-n8n}" "$1" "$iat" $(( iat + ${3:-120} )) "$(openssl rand -hex 16)"
}

# token AUD [DESPLAZAMIENTO_IAT] [VIDA] [ISS] → JWT válido salvo lo que se cambie
token() { firmar "$HS256" "$(claims "$@")"; }

# llamar RUTA TOKEN [CUERPO] [MÉTODO] → cuerpo y «HTTP nnn». TOKEN vacío = sin header.
llamar() {
  local cuerpo=${3:-'{}'} auth=()
  [ -n "$2" ] && auth=(-H "Authorization: Bearer $2")
  curl -sS -X "${4:-POST}" "$BASE$1" "${auth[@]}" \
       -H 'Content-Type: application/json' --data "$cuerpo" -w '\nHTTP %{http_code}\n'
}

# cabeceras RUTA TOKEN → sólo estado y headers de la respuesta
cabeceras() {
  local auth=(); [ -n "$2" ] && auth=(-H "Authorization: Bearer $2")
  curl -sS -o /dev/null -D - -X POST "$BASE$1" "${auth[@]}" -H 'Content-Type: application/json' --data '{}'
}

# Atajos por endpoint
P="$R/reservaciones/recordatorios/preparar";     AP="reservaciones.recordatorios.preparar"
C="$R/reservaciones/recordatorios/reclamar";     AC="reservaciones.recordatorios.reclamar"
N="$R/reservaciones/notificacion-resultado";     AN="reservaciones.notificacion-resultado"
F="$R/feedback/areas-mejora";                    AF="feedback.areas-mejora"
```

Comprobación rápida de las funciones: `token "$AC" | cut -d. -f2 | tr '_-' '/+' | base64 -d 2>/dev/null; echo`
debe mostrar los claims con `"iss":"n8n"`.

## 2. Funcionamiento con un JWT válido

### 2.1 Preparar recordatorios

```sh
llamar "$P" "$(token "$AP")" | tee /tmp/preparar.txt
```

| Esperado | Cómo se verifica |
| --- | --- |
| HTTP 200 y `{"ok":true,"due":...,"notifications":[...]}` | Salida del comando |
| Con `due:true`, cada notificación trae `event:"reservation.reminder"`, `source_id`, `attempt`, `contact.type` (`email` o `whatsapp`) y `data.management_url` | `head -n -1 /tmp/preparar.txt \| jq '.notifications[0]'` |
| Repetir antes de 5 minutos no duplica: la misma reservación no vuelve a salir | Segunda llamada: `notifications` sin ese `source_id` |
| Respuesta con `Cache-Control: no-store` | `cabeceras "$P" "$(token "$AP")"` |

Si responde `due:false`, el recordatorio está desactivado o todavía no es la hora
configurada. Hay que activarlo en Configuración de reservaciones, con una hora ya
pasada, y crear una reservación **confirmada para mañana** con contacto. Sin ese
dato no se pueden ejecutar 2.2 y 2.3.

```sh
SRC=$(head -n -1 /tmp/preparar.txt | jq -r '.notifications[0].source_id')
ATT=$(head -n -1 /tmp/preparar.txt | jq -r '.notifications[0].attempt')
CH=$(head -n -1 /tmp/preparar.txt | jq -r '.notifications[0].contact.type')
echo "source_id=$SRC attempt=$ATT channel=$CH"
```

### 2.2 Reclamar un recordatorio

| # | Comando | Esperado |
| --- | --- | --- |
| 2.2a | `llamar "$C" "$(token "$AC")" "{\"source_id\":$SRC,\"attempt\":$ATT,\"channel\":\"$CH\"}"` | 200 `{"ok":true,"claimed":true}` |
| 2.2b | El mismo comando otra vez | 200 `{"ok":true,"claimed":false}`: ya se reclamó y no se envía dos veces |
| 2.2c | `llamar "$C" "$(token "$AC")" "{\"source_id\":$SRC,\"attempt\":4,\"channel\":\"$CH\"}"` | 422 `NOTIFICACION_CALLBACK_INVALIDO` (máximo 3 intentos) |
| 2.2d | `llamar "$C" "$(token "$AC")" "{\"source_id\":$SRC,\"attempt\":$ATT,\"channel\":\"sms\"}"` | 422 `NOTIFICACION_CALLBACK_INVALIDO` |
| 2.2e | `llamar "$C" "$(token "$AC")" 'no es json'` | 422 `NOTIFICACION_CALLBACK_INVALIDO`, `claimed:false` |
| 2.2f | `llamar "$C" "$(token "$AC")" '{"source_id":999999,"attempt":1,"channel":"email"}'` | 200 `claimed:false`: no reclama una fuente inexistente |

### 2.3 Resultado de la notificación

```sh
RES() { printf '{"event":"%s","source_id":%s,"attempt":%s,"channel":"%s","status":"%s","retryable":false}' "$@"; }
```

| # | Comando | Esperado |
| --- | --- | --- |
| 2.3a | `llamar "$N" "$(token "$AN")" "$(RES reservation.reminder $SRC $ATT $CH accepted)"` | 200 `NOTIFICACION_CALLBACK_REGISTRADO` |
| 2.3b | El mismo comando otra vez | 200 `NOTIFICACION_CALLBACK_IDEMPOTENTE`: no cambia el estado |
| 2.3c | `llamar "$N" "$(token "$AN")" "$(RES reservation.reminder $SRC $((ATT+1)) $CH accepted)"` | 200 `NOTIFICACION_CALLBACK_STALE`: un intento viejo o futuro no pisa el actual |
| 2.3d | `llamar "$N" "$(token "$AN")" "$(RES reservation.reminder 999999 1 email accepted)"` | 404 `NOTIFICACION_SOURCE_NO_ENCONTRADO` |
| 2.3e | `llamar "$N" "$(token "$AN")" "$(RES reservation.reminder $SRC $ATT $CH entregado)"` | 422 `NOTIFICACION_CALLBACK_INVALIDO` (sólo `accepted` o `failed`) |
| 2.3f | `llamar "$N" "$(token "$AN")" "$(RES reservation.confirmation $SRC $ATT $CH accepted)"` | 422: evento no admitido en este callback |
| 2.3g | Canal contrario al contacto (`whatsapp` si `$CH` es `email`) | 422 `NOTIFICACION_CALLBACK_INVALIDO` |

### 2.4 Áreas de mejora del feedback

```sh
AREAS='{"areas_de_mejora":[
  {"categoria":"Servicio","titulo":"Prueba curl 1","descripcion":"Texto de prueba","prioridad_tipo":"alta","icono":"clock"},
  {"categoria":"Cocina","titulo":"Prueba curl 2","descripcion":"Texto de prueba","prioridad_tipo":"oportunidad","icono":"utensils"}]}'
```

| # | Comando | Esperado |
| --- | --- | --- |
| 2.4a | `llamar "$F" "$(token "$AF")" "$AREAS"` | 200 `{"ok":true,"guardadas":2}`. Las dos tarjetas aparecen en Feedback del panel de Restaurante |
| 2.4b | `llamar "$F" "$(token "$AF")" '{}'` | 422 «No se encontraron areas_de_mejora…» y las tarjetas de 2.4a siguen |
| 2.4c | `llamar "$F" "$(token "$AF")" 'no es json'` | 400 «JSON invalido» |
| 2.4d | `llamar "$F" "$(token "$AF")" '{"contexto":"panaderia","areas_de_mejora":[{"titulo":"x"}]}'` | 422 «Ámbito de integración inválido»: sólo Restaurante |
| 2.4e | `llamar "$F" "$(token "$AF")" '{"unidad_id":3,"areas_de_mejora":[{"titulo":"x"}]}'` | 422 «Ámbito de integración inválido» |
| 2.4f | Payload con `"titulo":"<script>alert(1)</script>"` y abrir Feedback | El texto se muestra escapado y no se ejecuta |

### 2.5 Método distinto de POST

| # | Comando | Esperado |
| --- | --- | --- |
| 2.5a | `llamar "$C" "$(token "$AC")" '' GET` | 404 del router (la ruta sólo existe en POST), nunca 200 |
| 2.5b | `llamar "$C" '' '' GET` | 401: la guardia JWT corre antes que el router |

## 3. Seguridad del JWT

Todos los casos de esta sección esperan **401** y el cuerpo exacto
`{"ok":false,"codigo":"N8N_JWT_INVALIDO"}`, salvo que la tabla diga otra cosa. Se
usa `reclamar` porque su cuerpo vacío no modifica datos. Para comparar el cuerpo,
guardar la referencia del caso 3.1a:

```sh
REF=$(llamar "$C" '' | head -n 1)
igual() { [ "$(llamar "$@" | head -n 1)" = "$REF" ] && echo "OK: mismo 401" || echo "REVISAR"; }
```

### 3.1 Header `Authorization`

| # | Comando | Esperado |
| --- | --- | --- |
| 3.1a | `cabeceras "$C" ''` | 401, `WWW-Authenticate: Bearer error="invalid_token"`, `Cache-Control: no-store`, `Content-Type: application/json` |
| 3.1b | `curl -sS -X POST "$BASE$C" -H "Authorization: Basic $(printf 'n8n:x' \| base64)" -d '{}' -w '\nHTTP %{http_code}\n'` | 401 |
| 3.1c | `curl -sS -X POST "$BASE$C" -H "Authorization: bearer $(token "$AC")" -d '{}' -w '\nHTTP %{http_code}\n'` | 401: el esquema se exige como `Bearer`, que es el que envía n8n |
| 3.1d | `curl -sS -X POST "$BASE$C" -H "Authorization: Bearer  $(token "$AC") extra" -d '{}' -w '\nHTTP %{http_code}\n'` | 401 |
| 3.1e | `igual "$C" "no.es.jwt!"` | OK: mismo 401 |
| 3.1f | `curl -sS -X POST "$BASE$C?token=$(token "$AC")" -d '{}' -w '\nHTTP %{http_code}\n'` | 401: el token no se acepta por query string |
| 3.1g | `curl -sS -X POST "$BASE$C" -H "X-N8N-Secret: $JWT_N8N_ENTRADA_SECRET" -d '{}' -w '\nHTTP %{http_code}\n'` | 401: header heredado rechazado |
| 3.1h | Igual que 3.1g con `X-N8N-Callback-Secret` | 401 |

### 3.2 Firma y algoritmo

| # | Comando | Esperado |
| --- | --- | --- |
| 3.2a | `igual "$C" "$(firmar "$HS256" "$(claims "$AC")" "$(openssl rand -hex 32)")"` | OK: otra llave |
| 3.2b | `igual "$C" "$(firmar '{"alg":"none","typ":"JWT"}' "$(claims "$AC")")"` | OK: `alg:none` |
| 3.2c | `T=$(firmar '{"alg":"none"}' "$(claims "$AC")"); igual "$C" "${T%.*}."` | OK: sin firma |
| 3.2d | `igual "$C" "$(firmar '{"alg":"HS512","typ":"JWT"}' "$(claims "$AC")" "" sha512)"` | OK: HS512 bien firmado con la misma llave también se rechaza |
| 3.2e | `igual "$C" "$(firmar '{"alg":"HS384","typ":"JWT"}' "$(claims "$AC")" "" sha384)"` | OK |
| 3.2f | `igual "$C" "$(firmar '{"alg":"RS256","typ":"JWT"}' "$(claims "$AC")")"` | OK: confusión de algoritmo |
| 3.2g | Payload alterado conservando la firma, ver el bloque siguiente | OK |

```sh
# 3.2g: token válido para preparar al que se le cambia la audiencia sin volver a firmar
T=$(token "$AP"); H=${T%%.*}; S=${T##*.}
P2=$(claims "$AC" | b64url)
igual "$C" "$H.$P2.$S"
```

### 3.3 Claims

| # | Comando | Esperado |
| --- | --- | --- |
| 3.3a | `igual "$C" "$(token "$AC" 0 120 otro)"` | OK: `iss` distinto |
| 3.3b | `igual "$C" "$(token "$AP")"` | OK: token de otra audiencia (preparar) en reclamar |
| 3.3c | `igual "$F" "$(token "$AC")"` | OK: token de reclamar en áreas de mejora |
| 3.3d | `igual "$C" "$(firmar "$HS256" "$(claims "$AC" \| jq -c ".aud=[\"$AC\"]")")"` | OK: `aud` como lista, aunque contenga la correcta |
| 3.3e | `igual "$C" "$(firmar "$HS256" "$(claims "$AC" \| jq -c ".aud=\"${AC^^}\"")")"` | OK: la audiencia distingue mayúsculas |
| 3.3f | Sin `jti`: `igual "$C" "$(firmar "$HS256" "$(claims "$AC" \| jq -c 'del(.jti)')")"` | OK |
| 3.3g | Sin `iat`: `... jq -c 'del(.iat)' ...` | OK |
| 3.3h | Sin `exp`: `... jq -c 'del(.exp)' ...` | OK |
| 3.3i | Sin `iss`: `... jq -c 'del(.iss)' ...` | OK |
| 3.3j | `jti` vacío: `... jq -c '.jti=""' ...` | OK |

### 3.4 Tiempo

`token AUD DESPLAZAMIENTO VIDA` mueve `iat` en segundos y fija `exp = iat + VIDA`.

| # | Comando | Esperado |
| --- | --- | --- |
| 3.4a | `igual "$C" "$(token "$AC" -200 120)"` | OK: venció hace 80 s |
| 3.4b | `llamar "$C" "$(token "$AC" -140 120)"` | **422** del controlador: venció hace 20 s, dentro de la tolerancia de 30 s, y el JWT pasa |
| 3.4c | `igual "$C" "$(token "$AC" 120 120)"` | OK: `iat` 2 minutos en el futuro |
| 3.4d | `llamar "$C" "$(token "$AC" 20 120)"` | 422 del controlador: `iat` 20 s adelantado se tolera |
| 3.4e | `igual "$C" "$(token "$AC" 0 301)"` | OK: vida de 301 s |
| 3.4f | `llamar "$C" "$(token "$AC" 0 300)"` | 422 del controlador: 300 s es el máximo admitido |
| 3.4g | `igual "$C" "$(token "$AC" 0 86400)"` | OK: token de un día |

En 3.4b, 3.4d y 3.4f el 422 es correcto: el cuerpo vacío no es un reclamo válido.
Lo que se prueba es que **no** sea 401.

### 3.5 Rutas

| # | Comando | Esperado |
| --- | --- | --- |
| 3.5a | `igual "$R/no-existe" "$(token "$AC")"` | OK: ruta sin audiencia, ningún token la abre |
| 3.5b | `igual "$C/" "$(token "$AC")"` | OK: con `/` final ya no es la ruta exacta |
| 3.5c | `igual "/api/integraciones/otra" "$(token "$AC")"` | OK |
| 3.5d | `llamar "/API/INTEGRACIONES/n8n/reservaciones/recordatorios/reclamar" "$(token "$AC")"` | Nunca 200: 404, 403 o redirección al login |
| 3.5e | `llamar "/api/feedback-n8n" "$(token "$AF")" "$AREAS"` | 403 o 404: la ruta retirada no existe |

### 3.6 Sesión administrativa

Iniciar sesión en `/login` con un usuario de prueba con contraseña y segundo
factor, y copiar el valor de la cookie de sesión desde las herramientas del
navegador.

| # | Comando | Esperado |
| --- | --- | --- |
| 3.6a | `curl -sS -X POST "$BASE$C" -H "Cookie: PHPSESSID=<valor>" -d '{}' -w '\nHTTP %{http_code}\n'` | 401: una sesión del panel no abre las integraciones |
| 3.6b | `curl -sS -D - -o /dev/null -X POST "$BASE$C" -H "Authorization: Bearer $(token "$AC")" -d '{}' \| grep -i set-cookie` | Sin `Set-Cookie`: la guardia no abre sesión |

### 3.7 Reutilización del token (`jti`)

`JwtIntegracion` no guarda los `jti` usados: un token válido se acepta varias veces
hasta su `exp`. La protección contra el reenvío es la idempotencia de negocio.

```sh
T=$(token "$AC")
llamar "$C" "$T" "{\"source_id\":$SRC,\"attempt\":$ATT,\"channel\":\"$CH\"}"   # 1.ª vez
llamar "$C" "$T" "{\"source_id\":$SRC,\"attempt\":$ATT,\"channel\":\"$CH\"}"   # mismo token
```

| Esperado | Lectura |
| --- | --- |
| Las dos llamadas dan 200, y la segunda `claimed:false` | El token se reutiliza, pero no envía dos veces |
| Con `notificacion-resultado` el reenvío da `NOTIFICACION_CALLBACK_IDEMPOTENTE` | Igual |
| Con `areas-mejora` el reenvío vuelve a escribir el mismo contenido | Sin efecto nuevo, pero no es idempotente por diseño (ver §5) |

Para limitar ese margen, n8n debe firmar cada petición con una vida corta (por
ejemplo 60 s) y un `jti` nuevo, y el tráfico debe ir por HTTPS.

### 3.8 Configuración del secreto

Requiere reiniciar `php -S` con otro `includes/.env`. Restaurar el secreto de
prueba al terminar.

| # | Configuración | Comando | Esperado |
| --- | --- | --- | --- |
| 3.8a | `JWT_N8N_ENTRADA_SECRET=` vacío | `llamar "$C" "$(token "$AC")"` | 500 `N8N_JWT_NO_CONFIGURADO`: falla a la vista, no queda abierto |
| 3.8b | Secreto de 31 caracteres | Token firmado con ese secreto | 500 `N8N_JWT_NO_CONFIGURADO` |
| 3.8c | Sin header y secreto vacío | `llamar "$C" ''` | 401: sin token no se llega a leer la llave |

### 3.9 Lo que queda en el log

Con `php -S`, los rechazos salen en la consola del servidor (`error_log`).

| Esperado | Cómo |
| --- | --- |
| Cada 401 registra la ruta y el motivo («audiencia equivocada», «vida mayor de 300 s», «sin Bearer»…) con el `jti` | Consola de `php -S` tras la sección 3 |
| El token completo y el secreto **no** aparecen en el log | Buscar en la consola un fragmento de la firma de un token usado (`${T##*.}`) y el secreto: cero coincidencias |
| La respuesta no dice el motivo | Todos los `igual` de la sección 3 dieron «OK: mismo 401» |

## 4. Servidor de despliegue

Antes de activar los workflows, repetir en el **entorno de prueba hospedado**
(con su propio secreto de prueba) los casos 3.1a, 3.2a, 3.3b, 3.4a y 2.2f:

| # | Comprobación | Esperado |
| --- | --- | --- |
| 4.1 | `llamar "$C" "$(token "$AC")" '{"source_id":999999,"attempt":1,"channel":"email"}'` | 200 `claimed:false`. Un 401 con un token válido indica que Apache o nginx no pasan el header `Authorization` a PHP: revisar `public/.htaccess` o `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` |
| 4.2 | `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' "http://<host>$C"` | Redirección a HTTPS o conexión rechazada, nunca un 401 o 200 por HTTP plano |
| 4.3 | Hora de n8n y del servidor PHP | Diferencia menor de 30 s (NTP en ambos); si no, 3.4 falla en producción |
| 4.4 | `JWT_N8N_ENTRADA_SECRET` de producción | 32 bytes aleatorios o más, distinto de `JWT_N8N_SALIDA_SECRET` y del de prueba, sólo en la credencial de n8n y en el `.env` del servidor |
| 4.5 | Workflows de n8n | Cada HTTP Request firma con su `aud` propia (tabla del inicio), `iss=n8n` y una vida de 300 s o menos; no queda ningún `X-N8N-Secret` entrante |

## 5. Observaciones de la revisión del código

Salen de leer el código al escribir estas pruebas; no se han ejecutado.

| # | Observación | Prueba que la confirma | Sugerencia |
| --- | --- | --- | --- |
| O-1 | `FeedbackController::recibirN8n` responde 500 con `'Error al guardar: ' . $e->getMessage()`, que puede revelar rutas o detalles internos a quien tenga un token | Quitar el permiso de escritura a `storage/areas_de_mejora/` y repetir 2.4a: el cuerpo incluye el mensaje interno | Mensaje genérico en la respuesta y el detalle en `error_log` |
| O-2 | `areas-mejora` reemplaza todo el archivo en cada llamada: no guarda historial y un token filtrado puede sobrescribir las tarjetas hasta que venza | 2.4a con otras áreas y abrir Feedback: las anteriores desaparecen | Aceptable si n8n siempre manda el conjunto completo; documentarlo |
| O-3 | No hay registro antirreplay de `jti` (documentado en API §9) | 3.7 | Mantener una vida corta en n8n; un registro de `jti` sólo si alguna ruta deja de ser idempotente |
| O-4 | El esquema `Bearer` distingue mayúsculas, aunque el RFC 7235 dice que no deberían distinguirse | 3.1c | No afecta a n8n; tenerlo presente si otro cliente se integra |
| O-5 | `areas-mejora` no limita tamaño ni número de áreas | 2.4a con miles de áreas | Un tope de áreas y de longitud de texto en `AreasMejora::guardar` |

## 6. Complemento automatizado

`php scripts/tests/run-jwt-integraciones.php` cubre lo mismo sin servidor:
verificación, header, firma saliente, llave ausente o corta, y la guardia que niega
por defecto. Estas pruebas con curl añaden lo que sólo se ve por HTTP: el servidor
web, las cabeceras de la respuesta, las sesiones y los controladores.
