# Casa Pestalozzi

Aplicación PHP MVC/MySQL con ámbitos Global, Restaurante y Panadería.
El [contrato rector](docs/arquitectura.md) describe la arquitectura y distingue los
módulos funcionales de los contratos preparados para desarrollo posterior.

## Empezar

1. Leer el [contrato](docs/arquitectura.md) y las [instrucciones para agentes](CLAUDE.md).
2. Seguir la [instalación reproducible](docs/desarrollo/instalacion.md).
3. Consultar la [guía de desarrollo](docs/desarrollo/desarrollo.md) y las
   [pruebas obligatorias](docs/desarrollo/pruebas.md).

Usuarios y Almacenes comparten implementación en los tres ámbitos. Restaurante
conserva su operación pública, menú, reservaciones, POS, KDS, cobro e informes.
El POS y los módulos comerciales de Panadería, la consolidación financiera Global,
transferencias, producción y catering continúan en el [backlog](docs/backlog.md).

El menú original se reconstruye desde `database/menu-restaurante.sql` y sus
archivos versionados; no requiere un respaldo o paquete privado. La cuenta
`admin_demo` se provisiona únicamente con un bootstrap CLI de desarrollo y una
contraseña aportada por configuración local; ningún SQL instala una contraseña
conocida automáticamente.

## Documentación

- [Arquitectura y capas](docs/arquitectura.md).
- [Usuarios y acceso](docs/usuarios/usuarios.md).
- [Permisos directos](docs/usuarios/roles-y-permisos.md).
- [Almacenes](docs/modulos/almacenes.md).
- [Reservaciones](docs/reservaciones/reservaciones.md) y [notificaciones](docs/reservaciones/notificaciones.md).
- [Operación POS e impresión](docs/operacion.md).
- [Configuración](docs/config.md) y [privacidad](docs/privacidad/privacidad.md).
- [Clasificación documental](docs/desarrollo/clasificacion-documental.md).

La evidencia de cada entrega debe leerse en la revisión indicada por su informe;
la existencia de una tabla o de una pantalla no acredita un flujo terminado.
