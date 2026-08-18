# Privacidad

Fuente de verdad vigente para el tratamiento de datos personales en Casa Pestalozzi. La aplicación sólo debe capturar y mostrar los datos necesarios para gestionar la reservación y la operación del restaurante.

## Datos capturados

Una reservación puede contener:

- nombre de la persona que reserva;
- correo electrónico y/o teléfono para contacto y verificación;
- fecha, hora y número de personas;
- notas operativas, como celebración, ubicación solicitada o necesidades de accesibilidad;
- estado de la reservación y datos necesarios para su atención.

Las notas son operativas. No se solicitan datos sensibles que no sean necesarios para la atención y no se usan las reservaciones para marketing no solicitado.

## Acceso por rol

El administrador autorizado puede consultar los datos de contacto cuando son necesarios para gestionar la reservación. El personal de piso y el punto de venta reciben los datos operativos necesarios para atenderla, pero no teléfono ni correo.

El acceso se limita por sesión, rol y propósito. Una vista que no necesita contacto no debe recibir sus claves desde el backend, aunque el usuario tenga una sesión válida.

## Verificación de contacto

Los códigos OTP se usan únicamente para comprobar un correo o teléfono. Son de un solo uso, tienen caducidad y están sujetos a límites de intentos. El código en claro no debe quedar en la interfaz, logs, respuestas públicas ni documentación; la aplicación conserva sólo el material necesario para validar el intento y el proveedor externo recibe el código para entregarlo.

## Proveedores externos

Los proveedores de correo, SMS u otros servicios de verificación pueden procesar los datos de contacto por cuenta del restaurante y sólo para la operación solicitada. No se les debe enviar información operativa adicional que no sea necesaria.

Los flujos de automatización no deben versionar ni exportar datos de ejecución en `pinData`. Los archivos de workflow pueden conservar metadatos vacíos, pero nunca PII, códigos OTP o payloads de clientes.

## Conservación y anonimización

Los datos identificables se conservan sólo durante el tiempo necesario para gestionar la reservación, atender incidencias y mantener el registro operativo exigible. Después se eliminan o se anonimizan; los registros estadísticos pueden conservarse únicamente sin identificadores directos ni datos de contacto.

El repositorio no fija un plazo legal numérico. Cualquier plazo operativo debe ser aprobado por la revisión legal correspondiente y no puede interpretarse como una autorización para conservar PII indefinidamente.

## Desarrollo y versionado

Los archivos de sesión son runtime y no se versionan. No se deben subir credenciales, códigos OTP, datos de contacto reales, exports de producción ni logs con PII a Git. Las credenciales de prueba se documentan por separado y sólo aplican a desarrollo.

## Derechos y contacto

La persona puede solicitar acceso, rectificación, cancelación u oposición por los canales definidos por el restaurante. Las solicitudes deben tramitarse sin exponer datos de otras personas.

## Referencias vigentes

- [Reservaciones](../reservaciones/reservaciones.md)
- [Usuarios](../usuarios/usuarios.md)
- [Credenciales de desarrollo](../usuarios/credenciales.md)
