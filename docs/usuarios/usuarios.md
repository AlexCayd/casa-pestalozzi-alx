# Usuarios y acceso

Fuente de verdad vigente para cuentas internas, roles y credenciales de acceso. Las reglas aplican a la aplicación y no sustituyen la configuración de producción.

## Roles

| Rol | Acceso principal |
| --- | --- |
| Administrador | Administración, usuarios, reservaciones, configuración y datos operativos autorizados. |
| Mesero | Operación de piso, mapa, tickets y tareas de atención permitidas. |
| Cocinero | Funciones de cocina permitidas por el sistema. |

El backend autoriza cada acción por sesión y rol. Ocultar un botón en el navegador no es una medida de seguridad.

## Inicio de sesión

El administrador entra con usuario y contraseña. El personal operativo entra con un NIP numérico de cuatro dígitos cuando la superficie lo permita. El frontend no solicita que el usuario invente o escriba manualmente un NIP durante el alta normal: el servidor lo genera y lo entrega una sola vez por el flujo autorizado.

Las contraseñas y NIP se almacenan con hash. El secreto de lookup o derivación del NIP (`NIP_LOOKUP_SECRET`) vive únicamente en variables de entorno; no se coloca en Git, documentación, logs, HTML ni respuestas de API.

## Alta, edición y regeneración

Al crear un usuario se define un rol válido y el servidor aplica las reglas de credenciales correspondientes. Cambiar de rol debe revisar el acceso resultante y no debe conservar credenciales incompatibles.

La regeneración de NIP invalida el anterior. El nuevo NIP sólo se muestra o entrega en el momento autorizado y no se vuelve a revelar desde una consulta posterior. Las respuestas deben evitar confirmar información sensible cuando la cuenta no existe o no está autorizada.

No se permite retirar el último administrador activo ni dejar usuarios operativos sin un rol válido. Las acciones de alta, edición, regeneración y baja requieren autorización, CSRF cuando corresponda y límites contra abuso.

## Configuración y despliegue

La configuración sensible se carga desde el entorno. Las migraciones de acceso deben ejecutarse antes de habilitar el flujo nuevo en una instalación existente y los seeds de demostración sólo se usan en desarrollo o QA.

Antes de producción se deben reemplazar credenciales de prueba, definir secretos fuera del repositorio, revisar permisos de la base de datos y comprobar que ningún log o export contenga contraseñas o NIP.

## Reglas de privacidad

El rol no amplía automáticamente el acceso a datos personales. La visibilidad de teléfono y correo se limita al personal autorizado que los necesita para la operación. Para la política completa, consultar [Privacidad](../privacidad/privacidad.md).

## Referencias vigentes

- [Credenciales de desarrollo](credenciales.md)
- [Reservaciones](../reservaciones/reservaciones.md)
- [Privacidad](../privacidad/privacidad.md)
