# Etapa 3B.1 — Núcleo de autorización directa

Base 73de92d; rama codex/usuarios-permisos-multinegocio-3b. La nueva política
sustituye la inferencia por roles por permisos usuario/unidad/módulo. El
[contrato central](contrato-autorizacion-3b.md) define la interfaz y límites.

DDL: usuarios.es_superadmin como única excepción organizacional explícita;
usuario_unidades.rol_id nullable y sin derechos implícitos; tabla
usuario_unidad_permisos con PK triple/FK membresía y nivel ver/editar/operar.
Roles/rol_permisos siguen como metadata, no se consultan para decidir acceso.
No hay plantillas ni editor nuevo de roles. Se conserva el esquema comercial,
almacenes, tickets y finanzas sin cambios de modelo.

Auth sigue siendo login/guardia única. El marcador multinegocio-3b-v1 selecciona
de forma exclusiva la resolución directa; la BD principal aún antigua conserva
su implementación existente sin migrarla ni sumar derechos entre ambos modelos.
No se modifica includes/.env. Las consultas de perfil fallidas deniegan acceso
y no recuperan permisos de roles. La marca de sesión _auth3b impide retroceder
a roles si una BD nueva deja de poder verificarse.

La unión de permisos activos decide contraseña si existe un módulo de panel;
solo pdv/area/reservations_map operativos en Restaurante permiten PIN. El mapa
operativo no equivale a reservations administrativo. La sesión registra auth_nivel;
promocionar permisos no eleva una sesión PIN: exige volver a autenticarse.
Permisos y membresías se releen en cada petición protegida. Superadmin se relee
desde BD, no desde un rol o permiso enviado por el cliente.

Los recursos se comprueban por unidad, módulo compatible y pertenencia real;
Global de lectura consolidada no concede escritura local ni membresías ajenas.
Las APIs desconocidas se deniegan por defecto en 3B; se enumeran las públicas
vigentes y se conservan sus guardias de token/integración. Las rutas de roles y
las administrativas de configuración operativa no se abren a una sesión PIN.

Validación: 68 comprobaciones de núcleo correctas en una base aleatoria nueva
marcada y eliminada. Cubre ámbitos independientes/mixtos/Global limitado,
leer/editar/operar, rol metadata sin concesiones, NIP/contraseña, promoción de
sesión, revocación y cuenta inactiva. Ejecutar:

```powershell
php scripts/tests/run-multinegocio-3b.php --host 127.0.0.1 --base casa-pestalozzi --entorno development --solo-nucleo
```

La interfaz/CRUD directo se integra en el segundo commit 3B.2. El resto de
consultas operativas del esquema nuevo sigue sin adaptar; la guardia no las
ejecuta sobre datos multiunidad para aparentar una regresión completa de POS,
KDS o reservas. En el esquema nuevo responden módulo pendiente después de
comprobar la autorización. La BD original y sus flujos no se activan parcialmente.

