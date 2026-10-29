# Usuarios y acceso

Contrato funcional de cuentas internas en los tres ámbitos. La precedencia
corresponde al [contrato rector](../arquitectura.md); las asignaciones directas se
detallan en [Permisos y ámbitos](roles-y-permisos.md).

## Identidad y ámbitos

Una cuenta puede tener membresías en Global, Restaurante y Panadería mediante
`usuario_unidades`. Sus permisos se asignan directamente por usuario, ámbito y
módulo en `usuario_unidad_permisos`. Los roles internos son metadatos técnicos;
no suman permisos implícitos ni existe un editor de plantillas.

Global con `users` y las facultades delegadas puede administrar cuentas de los
ámbitos autorizados. Un administrador local gestiona solamente su ámbito. No
puede modificar identidad, credenciales o estado global de una cuenta compartida,
ni revocar membresías ajenas. La excepción organizacional de superadministrador
proviene exclusivamente de `usuarios.es_superadmin`, releída en servidor.

## Inicio de sesión

Se conserva `/login` con dos vías. Cualquier permiso de panel, incluso `ver` y
aunque pertenezca a otro ámbito, exige usuario y contraseña. Solo las cuentas
exclusivamente operativas pueden utilizar un NIP numérico de cuatro dígitos:
POS `pdv`, KDS `area` y mapa operativo `reservations_map` autorizado. El mapa
operativo no equivale a administración de Reservaciones.

El servidor calcula la vía sobre la unión de accesos activos. No la elige el
cliente. Una promoción de piso a panel exige contraseña y nueva autenticación;
una sesión NIP no amplía sus derechos al cambiar el perfil.

Las contraseñas y NIP se almacenan como hashes. `NIP_LOOKUP_SECRET`, fuera de Git,
permite localizar el NIP mediante HMAC; `password_verify` confirma el hash. No se
exponen hashes, secretos ni NIP en listados o respuestas de detalle.

## Alta, edición y regeneración

La interfaz compartida está en `/admin/usuarios`, `/admin/global/usuarios` y
`/admin/panaderia/usuarios`. Reutiliza el layout, filtros, tabla y formulario de
Restaurante, con el selector de contexto bajo el logotipo.

El formulario permite asignar membresías, `ver`/`editar` de panel y `operar` de
piso, además de facultades autorizadas. El catálogo de facultades proviene del
mismo servicio que valida el backend; las facultades exclusivas de Global no
aparecen como opciones de un negocio. El actor no puede delegar derechos por
encima de su techo en el contexto desde el que administra.

Para agilizar el alta, la sección **Perfil sugerido** rellena ámbitos, módulos y
facultades de un paso; después pueden ajustarse antes de guardar. Su contrato
está en [perfiles sugeridos](roles-y-permisos.md#perfiles-sugeridos).

Las mutaciones pasan por `UsuarioService`/`AccesosUsuario`, validan CSRF y vuelven
a comprobar identidad, delegación y datos dentro de su transacción. Estado de
cuenta y revocaciones se releen en las siguientes peticiones protegidas. La
protección del último superadministrador activo se verifica con bloqueos.

El servidor genera el NIP en el alta o regeneración autorizada y lo entrega una
sola vez. El código anterior deja de servir; no existe una consulta posterior
para recuperarlo. Una cuenta compartida conserva las credenciales y los accesos
ajenos cuando un administrador local revoca solo su membresía.

## Configuración y despliegue

El [bootstrap de desarrollo](credenciales.md) valida la cuenta `admin_demo` que
el DML de desarrollo agrega bajo guard CLI con hash bcrypt. El DML estructural de
`deploy.sql` no activa esa cuenta. Consultar la [instalación](../desarrollo/instalacion.md)
y las [pruebas](../desarrollo/pruebas.md).

Los despliegues expuestos requieren sus propios secretos y revisión de accesos.
No versionar archivos de entorno, logs con credenciales ni NIP entregados.

## Referencias vigentes

- [Permisos directos](roles-y-permisos.md).
- [Contrato técnico de autorización](../arquitectura.md#4-autenticacion-autorizacion-y-usuarios).
- [Privacidad](../privacidad/privacidad.md).
- [Reservaciones](../reservaciones/reservaciones.md).
