# Usuarios y acceso

Fuente de verdad vigente para cuentas internas, roles y credenciales de acceso. Las reglas aplican a la aplicación y no sustituyen la configuración de producción.

## Roles

Cada cuenta tiene un rol, y cada rol abre módulos del sistema en modo consulta o edición. El detalle —modelo de datos, decisión de la guardia, reglas de gobierno y el plan multiempresa— está en [Roles y permisos](roles-y-permisos.md).

| Rol | Tipo | Acceso principal |
| --- | --- | --- |
| Super admin | sistema | Todo el sistema. Único que asigna unidades y gestiona cuentas de administrador. |
| Administrador | sistema | Todos los módulos. No toca roles de sistema ni cuentas de administrador. |
| Mesero | predefinido | Punto de venta (editable desde Roles y permisos). |
| Cocinero | predefinido | Tableros de producción (editable desde Roles y permisos). |
| Personalizados | personalizado | Los módulos que se les den, cada uno en consulta o edición. |

El backend autoriza cada acción por sesión y rol, y relee el rol de la cuenta en cada petición protegida. Ocultar un botón en el navegador no es una medida de seguridad.

## Inicio de sesión

La vía de acceso la deciden los módulos del rol: con algún módulo del panel, usuario y contraseña; si sólo tiene pantallas de piso (punto de venta, producción), un NIP numérico de cuatro dígitos. El frontend no solicita que el usuario invente o escriba manualmente un NIP durante el alta normal: el servidor lo genera y lo entrega una sola vez por el flujo autorizado.

Las contraseñas y NIP se almacenan con hash. El secreto de lookup o derivación del NIP (`NIP_LOOKUP_SECRET`) vive únicamente en variables de entorno; no se coloca en Git, documentación, logs, HTML ni respuestas de API.

## Alta, edición y regeneración

Al crear un usuario se define un rol válido y el servidor aplica las reglas de credenciales correspondientes. Cambiar de rol debe revisar el acceso resultante y no debe conservar credenciales incompatibles.

La regeneración de NIP invalida el anterior. El nuevo NIP sólo se muestra o entrega en el momento autorizado y no se vuelve a revelar desde una consulta posterior. Las respuestas deben evitar confirmar información sensible cuando la cuenta no existe o no está autorizada.

No se permite retirar el último super admin activo ni dejar usuarios sin un rol válido. Las acciones de alta, edición, regeneración y baja requieren autorización, CSRF cuando corresponda y límites contra abuso.

## Configuración y despliegue

La configuración sensible se carga desde el entorno según
[Configuración](../config.md). Los seeds de demostración sólo se usan en
desarrollo o QA.

Antes de producción se deben reemplazar credenciales de prueba, definir secretos fuera del repositorio, revisar permisos de la base de datos y comprobar que ningún log o export contenga contraseñas o NIP.

## Reglas de privacidad

El tratamiento y la visibilidad de datos personales se rigen por la fuente de
[Privacidad](../privacidad/privacidad.md).

## Referencias vigentes

- [Credenciales de desarrollo](credenciales.md)
- [Reservaciones](../reservaciones/reservaciones.md)
- [Privacidad](../privacidad/privacidad.md)
