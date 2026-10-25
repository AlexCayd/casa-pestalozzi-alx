# Etapa 3D — cierre de integración de Restaurante

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../contrato.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


Fecha: 25 de octubre de 2026. Rama: `codex/adaptacion-restaurante-etapa-3d`.

## Entregas

| Subetapa | Commit / descripción |
|---|---|
| 3D.1 | `19de645` — Menú, ofertas, variantes y recetas propias por negocio |
| 3D.2 | `03dadf7` — POS y consumo atómico por oferta |
| 3D.3 | `10c9280` — Consumidores y permisos de Restaurante |
| 3D.4 | Commit final «Valida integracion HTTP y visual de Restaurante 3D» |

No se hizo push ni merge. No se inició 3E. La base configurada de la aplicación
no recibió el esquema definitivo ni las escrituras de los recorridos de prueba.

## Consumidores y contratos

El mapa detallado está en [la auditoría](etapa-3d-auditoria.md), y los contratos
de oferta, composición y transacciones en
[contratos comerciales](etapa-3d-contratos-comerciales.md).

- Menú, categorías, carta pública y PDF resuelven ofertas locales. El DTO distingue
  identidad compartida, oferta y unidad; no se vinculan productos por nombre.
- Recetas y variantes pertenecen a la oferta. Las recetas conservan versiones;
  sus artículos de composición se pueden consultar con permiso de Recetas sin
  otorgar acceso a existencias o movimientos de Inventario.
- `Comercial`, `ConsumoOferta` y `Almacenes` comparten conexión y transacción.
  El consumo ocurre al enviar comanda; la clave idempotente conserva el resultado.
  La cancelación compensa los movimientos originales una vez, con su almacén y
  costo. También se congela la decisión de vender sin consumo.
- Reservas, asignación, mapa, tokens, buzón, horarios y notificaciones conservan
  sus flujos y acotan las raíces y relaciones a Restaurante.
- KDS usa áreas locales, valida ticket abierto y mantiene sus transiciones.
  Impresoras, configuración y alertas tienen alcance local. La impresión de
  comanda ocurre después del commit y no se repite en un replay.
- Historial, tickets administrativos, finanzas, analíticas y herramientas locales
  consultan ofertas y snapshots propios. Los costos ausentes siguen identificados;
  no se rellenan con el costo de la receta actual. El consumo netea cancelaciones
  y presenta merma separadamente.
- Inventario reutiliza la pantalla existente y el motor 3C con almacén explícito.
  Un excedente físico no oculta el faltante de otra asociación. Las transacciones
  revertidas no producen mensajes de éxito. Restaurante no modifica el maestro
  compartido desde esta interfaz.
- Los permisos se comprueban con asignaciones directas 3B. Usuarios de piso por
  NIP no abren Finanzas ni administración. Los módulos futuros de Global/Panadería
  permanecen cerrados, salvo Usuarios; no se agregó POS de Panadería.

## Evidencia automatizada

| Verificación | Resultado |
|---|---|
| Esquema y preservación 3A | 114 comprobaciones |
| Identidad, autenticación y permisos 3B | 128 comprobaciones |
| Autenticación y permisos HTTP 3B | 28 comprobaciones |
| Motor Almacenes 3C | 158 comprobaciones |
| Concurrencia Almacenes 3C | 17 comprobaciones |
| Menú, ofertas y recetas 3D.1 | 56 comprobaciones |
| POS/consumo/compensación 3D.2 | 58 comprobaciones |
| Consumidores y reportes 3D.3 | 64 comprobaciones |
| Presentación de inventario | 10 comprobaciones sin DB |
| Smoke HTTP de instalación 3D.4 | 6 comprobaciones |
| Recorrido HTTP ampliado 3D.4 | 59 comprobaciones |
| `npm run test:notifications` | 16 suites DB × 2, 32 ejecuciones correctas |
| `npm run test:php` y `npm run test:js` | Suites completas correctas |
| Assets POS | `npx gulp adminMapJs` correcto |

Se verificaron los 112 productos preservados, 15 categorías, 4 áreas, 112 ofertas
y 13 archivos. El fixture HTTP agrega una oferta sintética separada por negocio;
no sustituye productos del paquete preservado. Las bases de cada runner tienen
nombres aleatorios; los runners 3A–3D validan su propietario antes de eliminarlas.

La variante de notificaciones `--migrations` informa que los scripts históricos
fueron retirados y valida el DDL vigente. No se restauraron migraciones históricas
ni se deshabilitaron las aserciones funcionales para obtener resultados correctos.

El runner `run-restaurante-3d4-http.php` comprueba páginas administrativas,
PDF reales, autenticación, solo lectura, rechazo de ofertas y áreas ajenas,
apertura, comanda, variante, consumo, replay, cancelación compensada, entrega y
cobro. Recibe un archivo privado `--fixture` con las credenciales efímeras del
harness; esas credenciales no se versionan.

## Navegador y revisión visual

Se recorrieron en el navegador real el menú preservado, inventario y mapa POS.
Inventario se revisó a 1440 × 1000 y 390 × 844, conservando los componentes
existentes. Se comprobó el ciclo operativo mediante NIP: abrir ticket, elegir
Extra, enviar comanda, avanzar preparación/listo desde KDS, entregar y cobrar
34.50 en efectivo simulado. El ticket quedó cerrado y la mesa volvió a libre.

La evidencia PNG se conserva como artefactos privados de esta conversación:
`3d4-inventario-escritorio.png`, `3d4-inventario-movil.png`,
`3d4-kds-listo.png` y `3d4-pos-ticket-cerrado.png`. No contiene datos productivos.
La revisión estática adicional cubrió permisos de solo consulta, etiquetas de
selectores, alertas físicas, escape de contenido y textos de costos.

## Correcciones encontradas durante integración

Se resolvieron la aserción inicial de estados de entrega, contratos estáticos del
DDL sensibles a espacios, una referencia a controlador retirado, una concatenación
SQL de OTP, un filtro de impactos sin `WHERE`, consultas de meseros basadas en el
campo retirado `rol`, un import faltante en Recetas y la dependencia indebida de
Inventario para elegir componentes. Los fixtures se adaptaron a unidad obligatoria,
autenticación directa, FK restrictivas y grupos completos de variantes.

El navegador detectó además un renglón POS que redondeaba 34.50 a 35; ahora usa
el formateador monetario existente y coincide con el importe de cobro.

## Límites y entrega a 3E

Las integraciones del harness se forzaron a loopback sin proveedor y con secretos
sintéticos; la impresión física quedó desactivada. Las pruebas locales no certifican
SMTP, Meta/WhatsApp, credenciales n8n, impresoras reales, reinicios o restauración.

3E recibe la interfaz de Almacenes para los tres ámbitos, los refinamientos de
Usuarios, el DML versionado de los 112 productos/imágenes, datos simulados
reproducibles y la inicialización segura de la cuenta de desarrollo conforme al
contrato rector. El paquete preservado continúa privado hasta esa etapa.

Se conservaron fuera de estos commits los cambios previos del usuario en
`database/deploy.sql`, `package-lock.json` y el archivo no versionado
`docs/contrato.md`. No se publicaron secretos ni archivos de entorno.
