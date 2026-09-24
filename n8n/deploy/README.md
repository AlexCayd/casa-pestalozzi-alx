# Receta Compose para n8n

**Preparada, no desplegada.** Estos archivos no cambian la instancia n8n viva.
Los comandos de operación de este documento son instrucciones para una futura
ventana de trabajo del operador, no acciones ejecutadas durante esta etapa.
Leer primero la [documentación vigente y los contratos](../README.md#documentacion-vigente).

## Decisiones y versión

La receta usa `docker.n8n.io/n8nio/n8n:2.38.7` y `n8nio/runners:2.38.7`.
El 2026-09-13 se verificó la release oficial
[n8n@2.38.7](https://github.com/n8n-io/n8n/releases/tag/n8n%402.38.7), publicada
el 2026-09-11 y señalada como estable en
[releases](https://github.com/n8n-io/n8n/releases). No usar etiquetas móviles
`latest`, `stable`, `beta`, `2` ni actualización automática de imágenes.
La publicación de la versión y su código fueron verificados; no se descargaron
las imágenes ni se verificaron sus manifiestos, arquitectura o digest en Docker.
Antes del despliegue, resolver ambos tags oficiales y registrar sus digests;
para fijar también el contenido usar `repositorio:2.38.7@sha256:<digest verificado>`
en la copia operativa. No inventar un digest ni cambiar a una versión flotante
si un tag no está disponible.

Se consultó el [índice oficial de avisos de seguridad](https://github.com/n8n-io/n8n/security/advisories).
En particular, [GHSA-6xcw-7xm6-48c6](https://github.com/n8n-io/n8n/security/advisories/GHSA-6xcw-7xm6-48c6)
declara corregida la rama 2.38 desde 2.38.2; 2.38.7 supera ese umbral. Esto no
es una auditoría exhaustiva ni una garantía de ausencia de vulnerabilidades:
revisar nuevamente todos los avisos aplicables y las notas al promover la receta.

Topología prevista: **Docker Engine y Compose v2 en un host Linux**, un proxy
TLS administrado en ese mismo host, una instancia n8n con SQLite y un runner
externo sólo para JavaScript. No se configura un proxy real, DNS, certificados,
firewall, servicio Windows, PostgreSQL, Redis ni modo queue. SQLite sirve esta
receta de instancia única; no montar su volumen en dos n8n simultáneos ni
escalarlo a múltiples réplicas. Los tres workflows siguen independientes.
La persistencia y timezone siguen la
[guía Docker oficial](https://docs.n8n.io/deploy/host-n8n/install-options/install-with-docker).

Si el destino exige Windows, validar primero una VM Linux persistente o definir
por separado el servicio admitido por ese servidor. Docker Desktop iniciado por
sesión de usuario no prueba recuperación desatendida tras un reboot.

## Archivos y preparación sin arrancar n8n

- [compose.yaml](compose.yaml): receta con versiones exactas, volumen y secrets.
- [.env.example](.env.example): sólo parámetros no secretos y rutas ilustrativas.
- [.gitignore](.gitignore): defensa frente a archivos locales accidentales.

En el futuro host, trabajar en un directorio operativo fuera del checkout y
del web root (por ejemplo `/opt/casa-n8n`). Copiar allí `compose.yaml`; copiar
`.env.example` como `/etc/casa-n8n/compose.env`. Cambiar dominios `.invalid`,
nombre de proyecto, volumen y puerto después de comprobar que son exclusivos
y no pertenecen a la instancia existente. **No montar la SQLite viva, ni
`%USERPROFILE%/.n8n`, ni apuntar a su volumen para probar esta receta.**

Provisionar con el gestor de secretos del operador dos archivos diferentes,
cada uno con al menos 32 bytes aleatorios representados como 64 caracteres
hexadecimales. Un archivo contiene la clave de cifrado; el otro, el token del
runner. Guardar sólo el valor, sin BOM, comillas, espacios o `NOMBRE=`. No hay
valores predeterminados ni secretos de ejemplo utilizables en este repositorio.
No regenerar la clave al reiniciar o recrear el contenedor.

Restringir el directorio privado al administrador y los archivos al usuario
efectivo del contenedor: en Linux sin remapeo de usuarios, UID/GID 1000 y modo
`0400` para cada archivo; el directorio padre puede ser `0700` del administrador
que invoca Docker. En rootless/userns ajustar los UID del host al mapeo efectivo.
Comprobar lectura sin imprimir el contenido. Compose monta secrets como
archivos: no asumir cifrado en disco del host ni que `uid/gid/mode` de un secret
basado en archivo modifiquen sus permisos originales. Véase
[secrets de Compose](https://docs.docker.com/compose/how-tos/use-secrets/).

`N8N_ENCRYPTION_KEY_FILE=/run/secrets/n8n_encryption_key` está soportado:
lo documenta el [código exacto 2.38.7 de InstanceSettingsConfig](https://github.com/n8n-io/n8n/blob/n8n%402.38.7/packages/%40n8n/config/src/configs/instance-settings-config.ts)
y lo implementa el [lector de `_FILE`](https://github.com/n8n-io/n8n/blob/n8n%402.38.7/packages/%40n8n/config/src/decorators.ts).
No definir a la vez `N8N_ENCRYPTION_KEY`, que tendría precedencia sobre `_FILE`.
El volumen conserva BD, workflows, credenciales cifradas y configuración;
contiene material sensible aunque las credenciales estén cifradas.

El runner usa otro secret. n8n lee su token mediante `_FILE`; para el launcher
se usa un adaptador mínimo de arranque que lee el archivo y ejecuta su binario
oficial. No se presupone soporte `_FILE` del launcher. El token existe en el
entorno del launcher durante la ejecución; no es el secreto de webhook ni la
clave de cifrado. No habilitar trazas de shell ni inspeccionar/imprimir entornos.
La imagen y ruta del launcher se contrastaron con el
[Dockerfile de runners 2.38.7](https://github.com/n8n-io/n8n/blob/n8n%402.38.7/docker/images/runners/Dockerfile).

Comprobaciones futuras sin arrancar contenedores (Bash, desde `/opt/casa-n8n`):

```bash
docker compose version
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml config --quiet
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml config --images
```

`config --quiet` comprueba el modelo Compose, no la legibilidad del secret en
el contenedor ni conectividad. No publicar un `config`, `inspect` o log completo
con información operativa. No se requiere ejecutar `set` tras un reboot: los
parámetros viven en archivos, el contenedor retiene su configuración y el motor
Docker debe estar habilitado al arrancar el host.

## Red, seguridad y salud

Sólo se publica `127.0.0.1:5678` (o el puerto local elegido). `0.0.0.0` dentro
del contenedor permite recibir el tráfico Docker; no equivale a publicar el
puerto en todas las interfaces del host. Los puertos del broker `5679` y del
runner no se publican. El runner sólo participa en una red interna y no monta
la BD ni la clave; n8n tiene salida para PHP, SMTP y Meta. No se añaden sockets
Docker, montajes del host ni paquetes comunitarios. El runner usa usuario sin
privilegios, raíz de sólo lectura y `/tmp` efímero. Mantener los allowlists
restrictivos de la imagen. La separación sigue
[task runners](https://docs.n8n.io/deploy/host-n8n/configure-n8n/set-up-task-runners)
y [hardening oficial](https://docs.n8n.io/deploy/host-n8n/configure-n8n/security/harden-task-runners).
La variante distroless y un perfil AppArmor específico requerirían validación
del host; no están incluidos. No activar `N8N_RUNNERS_INSECURE_MODE`.

El proxy del host termina TLS y reenvía HTTP a `127.0.0.1:5678`, conservando
rutas. Usar subdominio en `/`, no un prefijo de ruta. Debe fijar/sanear
`X-Forwarded-For`, `X-Forwarded-Host` y `X-Forwarded-Proto`, preservar los headers
de autenticación y admitir las conexiones persistentes del editor. La receta
fija `N8N_PROXY_HOPS=1`; si existen más proxies revisar el número real de saltos
y las fronteras de confianza. Se usa `N8N_WEBHOOK_URL`, nombre vigente en
2.38.7, en lugar del alias deprecado `WEBHOOK_URL`, según la
[guía de proxy](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/configuration-examples/configure-webhook-urls-with-reverse-proxy).
`N8N_EDITOR_BASE_URL` y el hostname deben coincidir con el acceso HTTPS previsto.

Restringir editor/API administrativa a operadores (VPN o ACL), completar el
alta del owner por una ruta privada y activar MFA. Exponer sólo los webhooks
necesarios hacia PHP; no publicar `/webhook-test/`, métricas o readiness por
el proxy público. No registrar headers, cuerpos ni parámetros sensibles en
access logs. No cachear ni reintentar los POST. Configurar el timeout de PHP
mayor que el presupuesto de transporte de n8n y el del proxy por encima del
cliente, revisando también el límite de ejecución PHP. No hay valores de
timeout funcional certificados por esta receta.

Si el proxy estuviera en otro contenedor, su `127.0.0.1` no es el host: esa
topología requiere adaptar la red de forma explícita. Lo mismo vale para PHP
y para `RESERVATION_APP_BASE_URL`: el localhost del contenedor no apunta a
XAMPP. Validar DNS, TLS y la ruta autorizada a PHP sin deshabilitar globalmente
controles SSRF; una API privada puede necesitar una excepción acotada a su
destino en la configuración de seguridad de la versión elegida.

El healthcheck usa Node incluido en la imagen y consulta
`/healthz/readiness`, que comprueba conexión y migraciones de BD; `/healthz`
sólo demuestra que responde el proceso. No prueba Code nodes, credenciales,
SMTP, Meta ni callbacks. Véase [monitorización oficial](https://docs.n8n.io/deploy/host-n8n/keep-n8n-running/monitor-n8n).
`depends_on` espera readiness antes de iniciar el runner; su registro se prueba
con un Code node de datos ficticios. `restart: unless-stopped` recupera un
proceso que termina y respeta paradas manuales. **Un contenedor `unhealthy`
no se reinicia automáticamente por esa política**: requiere diagnóstico y
monitorización del operador. No se instala ningún monitor en esta etapa.

Los defaults de [persistencia de ejecuciones](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/use-environment-variables/executions)
se restringen para no conservar payloads de OTP o enlaces. Revisar overrides
de cada workflow y posibles logs de error; pruning no borra inmediatamente
datos históricos ni garantiza ausencia de PII. Sólo probar con datos ficticios
hasta validar esta política. No añadir Wait nodes que persistan payloads
sensibles ni usar los logs como historial funcional; ese estado pertenece a PHP.

## Arranque futuro y comprobación de persistencia

Sólo tras resolver las condiciones del contrato y preparar el entorno TEST:

```bash
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml pull
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml up -d
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml ps
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml exec -T n8n n8n --version
```

Registrar digests de ambas imágenes y esperar `healthy`. Completar importación
inactiva, Configuración y credenciales según [el README principal](../README.md).
En TEST, comprobar Code nodes y cada canal; luego reiniciar ambos servicios y
el host de prueba, y verificar mismos workflows, zona horaria, credenciales
legibles y registro del runner. Para comprobar recreación usar de nuevo
`up -d --force-recreate`, conservando nombre de volumen y clave. No usar
`down -v`, `volume rm`, `system prune --volumes` ni compartir un volumen entre
entornos. Un cambio de nombre de volumen puede parecer pérdida de workflows.

## Backup consistente

Respaldar al menos diariamente y antes de cada actualización, migración o
rotación. Definir con el operador retención, RPO/RTO y destino cifrado fuera
del host. La receta usa SQLite: copiar únicamente `database.sqlite` mientras
n8n escribe no es un backup consistente.

1. Poner entrada PHP/proxy en mantenimiento, pausar la publicación del scheduler
   y drenar ejecuciones. Registrar qué workflows estaban activos sin incluir
   payloads; impedir que la otra instancia ejecute recordatorios en paralelo.
2. Parar los dos servicios de forma ordenada. Si hubo terminación forzada,
   no dar por consistente el backup sin verificar SQLite en una copia.
3. Copiar **todo** `/home/node/.n8n` del contenedor detenido, incluidos config,
   SQLite y sus archivos auxiliares, con permisos privados. Conservar también
   la receta/parámetros exactos, digests y ambos secrets en custodia cifrada
   separada del repositorio. No exportar credenciales con la CLI de n8n.

Ejemplo futuro en Bash; el directorio se crea de forma única, no sobreescribe
un backup anterior (raíz de backups ya provisionada con permisos privados):

```bash
umask 077
backup_dir=$(mktemp -d /var/backups/casa-n8n/snapshot-XXXXXXXX)
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml stop
docker compose --env-file /etc/casa-n8n/compose.env -f compose.yaml cp n8n:/home/node/.n8n "$backup_dir/data"
tar -C "$backup_dir" -czf "$backup_dir/n8n-data.tar.gz" data
sha256sum "$backup_dir/n8n-data.tar.gz"
```

El hash comprueba integridad, no confidencialidad. Custodiar el directorio y el
archivo como datos sensibles: config puede contener material de cifrado y la
BD contiene credenciales cifradas. Cifrar el paquete en el sistema de backups,
custodiar la clave de descifrado por separado y verificar la copia externa
antes de reanudar. Probar regularmente restore; un export de workflows no
sustituye el backup. Reanudar con `up -d`, restaurar sólo la publicación que
estaba activa y reconciliar pendientes PHP antes de abrir entrada.

## Restore en un volumen nuevo

1. Seleccionar un backup íntegro, su clave original y la misma versión/digests.
   No crear otra clave para esa BD. Recuperar una copia en un host aislado
   con tráfico saliente bloqueado hacia PHP/proveedores reales: los workflows
   restaurados podrían conservar activación, ejecuciones o tareas pendientes.
2. Preparar otro `compose.env` con proyecto, **volumen nuevo**, puerto y URLs
   propios. Comprobar que el volumen de destino no existe o está vacío y no
   pertenece a una instancia viva. Verificar hash y extraer el backup confiable
   en un directorio privado, conservando la estructura `data/`.
3. Crear el contenedor sin arrancarlo, copiar datos y ajustar UID/GID 1000 y
   permisos en el volumen nuevo antes de iniciar. Ejemplo desde la copia de
   receta correspondiente al backup; `/srv/restore-casa-n8n/data` representa
   la carpeta ya verificada:

```bash
docker compose --env-file /etc/casa-n8n/restore.env -f compose.yaml create n8n
docker compose --env-file /etc/casa-n8n/restore.env -f compose.yaml cp /srv/restore-casa-n8n/data/. n8n:/home/node/.n8n
```

La copia puede quedar propiedad de root. El operador debe aplicar propietario
`1000:1000` y permisos privados mediante su herramienta de restauración del
volumen, limitada al **volumen nuevo verificado**; n8n debe poder escribir y
`config` debe ser `0600`. No se presupone que `compose cp` preserve propietario.
Validar integridad SQLite offline en la copia, sin abrir la BD viva.

4. Arrancar la copia con `up -d` manteniendo aislamiento de red. Comprobar
   readiness, acceso al editor y presencia de workflows; mantenerlos inactivos,
   reasignar configuración/credenciales TEST antes de habilitar egress de prueba.
   Verificar descifrado con un proveedor de prueba, registro del runner y
   contratos. Conservar la instancia original parada o separada durante el ensayo.
5. En una recuperación real, reconciliar en PHP intentos aceptados, pendientes
   y callbacks desde la fecha del backup antes de permitir nuevos envíos. El
   restore de n8n no revierte la BD PHP ni deshace mensajes ya aceptados. Documentar
   pérdida potencial desde el snapshot y el tiempo real de recuperación.

## Actualización y rollback

1. Revisar releases/avisos, cambios incompatibles y ruta de migración desde la
   versión instalada. Ensayar sobre un restore aislado, nunca sobre la BD viva.
2. Fijar la nueva versión exacta **en las dos imágenes** de una copia de la
   receta y registrar sus digests. Respaldar BD/volumen, configuración y secrets
   con el procedimiento anterior. Conservar también las imágenes anteriores
   en el registro/caché administrado para poder recuperarlas.
3. Durante mantenimiento, drenar/parar, ejecutar `pull` y `up -d` con la nueva
   receta. Validar readiness, runner, credenciales y matriz de contratos antes
   de reabrir entrada y scheduler. No actualizar automáticamente al reiniciar.
4. Si falla, parar la nueva versión y conservar su volumen para investigación
   privada. **No ejecutar la imagen antigua sobre una BD ya migrada**. Restaurar
   el snapshot previo en otro volumen, con la imagen/configuración/clave que le
   correspondían; probar y cambiar el proxy/PHP sólo cuando esté listo.
5. Reconciliar los efectos externos y datos PHP posteriores al backup para no
   repetir avisos. No intentar solucionar una migración fallida borrando la BD.

## Rotación de secretos

Mantener un inventario privado por entorno de custodios, fecha de rotación y
credenciales/nodos dependientes, sin anotar sus valores en documentación/logs.

| Secreto | Procedimiento coordinado |
|---|---|
| PHP → n8n | Pausar solicitudes PHP, crear valor nuevo, actualizar la credencial Header Auth de ambos Webhook y `N8N_RESERVATIONS_WEBHOOK_SECRET` en PHP; recargar su configuración, probar y reanudar. |
| n8n → PHP | Pausar scheduler/nuevos trabajos y drenar callbacks; actualizar `N8N_RESERVATIONS_CALLBACK_SECRET` en PHP y la otra Header Auth en preparar/reclamar/resultado; probar los tres endpoints y reanudar. |
| SMTP / Meta | Crear la nueva credencial del proveedor, asignarla a todos los nodos correspondientes de los tres workflows, probar y revocar la anterior. Si el proveedor permite coexistencia, usar esa ventana. |
| Token del runner | Drenar/parar ambos servicios, reemplazar su archivo con otro valor aleatorio manteniendo permisos y recrear ambos con `up -d --force-recreate`; comprobar registro y ejecución de Code nodes. |

No suponer que Header Auth ni PHP acepten dos secretos simultáneamente: usar
la ventana de mantenimiento indicada. Probar credencial nueva válida y vieja
rechazada, sin enviar secretos en historial del shell o reportes. No volver
a un valor comprometido para resolver un fallo; emitir otro y repetir la
coordinación. Los jobs que no pudieron devolver callback requieren reconciliación.

**Clave maestra `N8N_ENCRYPTION_KEY`:** no sustituir su archivo como rotación
ordinaria; la BD y sus backups dependen de esa clave. Su pérdida impide recuperar
credenciales. La [rotación oficial de claves de datos](https://docs.n8n.io/deploy/host-n8n/configure-n8n/security/rotate-encryption-keys)
mantiene la maestra y rota una clave de datos interna. La receta no habilita
esa función: `N8N_ENV_FEAT_ENCRYPTION_KEY_ROTATION=true` cambia el formato de
cifrado y requiere backup y ensayo previo. Después de escribir en ese formato
no se debe desactivar el flag ni volver a una versión que no lo entienda;
recuperar el estado anterior exige el backup previo y su configuración.

Ante compromiso de la maestra, aislar y reconstruir una instancia limpia con
otra clave, reemitir credenciales de proveedores y ambos secretos PHP, e importar
sólo workflows sanitizados. No exportar credenciales descifradas ni dar por
protegidos los backups antiguos: aplicar la política de custodia/retención del
incidente. Este procedimiento no se ejecuta ni automatiza aquí.

## Validación y límites

Antes de publicar en producción, completa y registra para el entorno concreto:

1. `docker compose config --quiet`, descarga de imágenes y arranque del servicio
   con permisos de secretos, SQLite y healthcheck correctos.
2. Reinicio de contenedor y host; comprobar persistencia de workflows y
   credenciales.
3. Importación de los tres workflows sanitizados, prueba de rechazo de headers
   inválidos y ausencia de `$env`/`$vars` en Code.
4. En TEST, probar Email y WhatsApp, aceptación y fallo, timeout, HTTP 4xx/5xx,
   JSON inválido, respuesta OTP 200 posterior al proveedor y callbacks
   `accepted|failed`.
5. Probar claim/deduplicación, callback obsoleto, recuperación de resultados
   reintentables, backup, restore en un volumen nuevo y rollback.
6. Ejecutar `npm run test:notifications` para los contratos locales PHP/Node.

Los tests locales y la respuesta de readiness no sustituyen la ejecución de los
workflows en una instancia TEST con proveedores y credenciales controlados. No
registrar payloads, contactos, códigos, tokens ni secretos como evidencia.
