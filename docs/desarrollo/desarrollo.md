# Guía de desarrollo

La entrada obligatoria es [docs/contrato.md](../contrato.md). Consultar también
[CLAUDE.md](../../CLAUDE.md) para las convenciones y los componentes de interfaz.
Esta guía no convierte un módulo preparado en funcional.

## Antes de implementar

1. Revisar rama, HEAD, estado de Git y worktrees. Conservar cambios ajenos.
2. Identificar el ámbito propietario y los módulos afectados.
3. Leer el servicio compartido, el DDL, su contrato y sus consumidores existentes.
4. Revisar el estado del módulo y su [backlog](../backlog.md).
5. Registrar cualquier contradicción de contrato antes de modificar la arquitectura.

No empezar una entrega posterior desde `main` si no incluye sus dependencias.
No aplicar stashes históricos ni ejecutar reconstrucciones implícitas.

## Capas y archivos

El ruteo exacto vive en `public/index.php`; las clases PSR-4 se distribuyen entre
`controllers/`, `models/`, `classes/` y `services/<Dominio>/`. La
[arquitectura](../arquitectura.md) describe la dirección de dependencias.

Los controladores coordinan HTTP, CSRF y respuestas; los servicios validan dominio,
autorización y transacciones; los modelos persisten. Las vistas reciben datos
preparados y reutilizan layouts/parciales. No crear una segunda autenticación,
otro motor de stock ni servicios casi idénticos por negocio.

## Propiedad y seguridad

`unidades.slug` distingue `global`, `restaurante` y `panaderia`; los IDs no se
suponen constantes en las decisiones de dominio. El servidor obtiene actor y
nivel de autenticación de la sesión y comprueba permisos directos 3B.

Panel: `ver`/`editar`. Piso: `operar`, solo para POS, KDS y mapa operativo de
reservaciones autorizados. La unión de accesos de panel en cualquier ámbito
exige contraseña. Los roles internos no conceden derechos implícitos.

Registrar rutas privadas en `Permisos::clasificarDirecto()` o su catálogo efectivo.
Las APIs desconocidas no se abren como públicas; los endpoints públicos existentes
conservan sus verificaciones de token y de integración. Validar también en el
servicio y en cada detalle, exportación, búsqueda o mutación por ID.

Mantener CSRF, límites de intentos, reautenticación de cambios sensibles, protección
del último superadministrador y efectos inmediatos de revocación. El selector de
contexto y los controles ocultos del cliente no autorizan operaciones.

## Interfaz compartida

Global y Panadería reutilizan la pantalla equivalente de Restaurante: mismo
layout, sidebar, topbar, encabezados, tablas, filtros, formularios, botones,
modales, alertas, iconos y comportamiento responsive. Parametrizar ámbito, datos
y capacidades; evitar copiar una interfaz completa para cada negocio.

Conservar el selector bajo el logotipo y el nombre visible del ámbito activo.
No rediseñar Restaurante como parte de un módulo funcional nuevo. Preferir los
componentes descritos en `CLAUDE.md`; no usar diálogos nativos `alert`, `confirm`
o `prompt`. Verificar escritorio, móvil, teclado, solo consulta y errores.

## DDL, DML y fixtures

El orden de instalación es único: `ddl.sql`, `deploy.sql`,
`menu-restaurante.sql`, `development.sql` y bootstrap seguro. No crear seeds
paralelos con datos iniciales contradictorios ni dependencias privadas.

El menú preservado es información comercial original; los escenarios ficticios
usan identidades/ofertas sintéticas identificadas. No modificar esos 112 productos
para construir fixtures. Toda existencia inicial tiene operaciones y movimientos
que explican su saldo; no sembrar stock sin bitácora.

Las cantidades y costos respetan las precisiones DECIMAL y viajan como cadenas
exactas al motor. Las ventas identifican oferta y negocio; los consumos usan un
almacén del mismo vendedor. Global no vende. Un consolidado es una consulta,
no un almacén virtual ni un permiso de escritura sobre negocios ajenos.

Al cambiar estructura, actualizar DDL, DML, servicios, consumidores y pruebas en
la misma entrega. No reintroducir compatibilidad con históricos descartados.

## Operaciones y confirmación

El coordinador confirma el documento comercial y sus movimientos en la misma
conexión/transacción. `ok=true` público exige commit; un savepoint pendiente no
se anuncia como éxito. Preservar idempotencia y bloqueos ordenados.

Las compensaciones usan movimientos originales, su costo y su almacén; no
recalculan recetas actuales. Los errores de impresión posteriores al commit no
duplican comanda ni deshacen la venta confirmada.

## Entrega

Ejecutar las [pruebas](pruebas.md), informar lo ejecutado y lo pendiente, y
actualizar contrato/ficha del módulo con el estado real. Distinguir defectos,
mejoras y funcionalidades nuevas. Las tablas preparadas no habilitan rutas de
escritura incompletas. Registrar límites de hardware y proveedores externos.

Crear commits acotados y descriptivos. Comparar con `main` tanto textual como
funcionalmente, preservando el comportamiento correcto de Restaurante. Un
worktree candidato limpio no requiere borrar cambios ajenos del original.
No hacer push ni merge sin autorización explícita.
