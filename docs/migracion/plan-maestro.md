# Casa Pestalozzi — Plan maestro multinegocio

Versión 1.1 · 2026-10-24 · Plan para revisión; implementación pendiente.

## 1. Política vigente y alcance de esta entrega

Casa Pestalozzi está en desarrollo. No hay datos productivos que migrar.
La única conservación obligatoria es el catálogo actual del menú de Restaurante
y las dependencias necesarias para reconstruir sus fichas, presentación y venta.
La [actualización recibida](actualizacion-obligatoria-desarrollo.md) prevalece
sobre toda exigencia anterior de conservar información histórica de prueba.

Esta entrega actualiza el plan y contratos. **No autoriza ejecutar ahora la
reconstrucción ni comenzar módulos adicionales.** No se modifica el esquema,
se exportan datos ni se crean herramientas de reconstrucción en esta entrega.

Se mantienen los requisitos funcionales del [documento original](../panaderia/documento-maestro-multinegocio.md):
Global, Restaurante y Panadería; almacenes físicos con dueño único; catálogo
maestro; ofertas y recetas por negocio; permisos por ámbito; transferencias;
tickets y finanzas independientes con consolidación autorizada; componentes
compartidos y posterior diseño del POS Panadería sin mesas.

## 2. Datos conservados y datos regenerables

| Tratamiento | Datos |
| --- | --- |
| Conservar | Todos los productos del catálogo actual Restaurante, incluidos inactivos; identidad, nombre, descripción, foto propia o NULL, precio exacto, categoría, carta, disponibilidad y metadatos indispensables |
| Conservar dependencias del menú | Categorías referenciadas con nombre, imagen, carta y estado; archivos asociados; orden de presentación; área de producción o correspondencia necesaria para que el producto sea vendible |
| Preferencia de identidad | Mantener IDs de producto/categoría si conviene; si cambian, mapa verificable de origen a destino, sin fusionar por nombre |
| Regenerar con fixtures nuevos | Usuarios de desarrollo, roles/asignaciones/membresías, almacenes, ingredientes, existencias, movimientos, recetas/subrecetas, variantes, reservaciones, tickets/pagos, finanzas, analíticas, configuraciones de prueba y demo Panadería |

Conservar los productos inactivos no implica publicarlos: se preservan las
banderas del producto y la categoría. Los metadatos se exportan desde las tablas
reales del catálogo, no desde una captura de lo visible en la landing.

El respaldo completo previo es una salvaguarda de recuperación, no una obligación
de importar los datos descartables al esquema nuevo. No se migran ni se concilian
movimientos, tickets, costos, ventas o balances anteriores. La trazabilidad y los
costos históricos sí serán obligatorios para las operaciones nuevas.

## 3. Esquema simplificado de reconstrucción

```text
Identificar explícitamente BD origen y destino como desarrollo/pruebas
  → respaldar estado anterior y archivos
  → exportar menú + dependencias + manifiesto verificable
  → ensayar restauración del respaldo y validar paquete en BD desechable
  → crear esquema definitivo en destino de desarrollo identificado
  → sembrar estructura mínima, importar menú solo a Restaurante
  → verificar datos, imágenes, menú administrativo y vistas públicas
  → cargar fixtures nuevos aislados y probar flujos de ambos negocios
```

El [procedimiento reproducible](procedimiento-menu.md) define entradas, salidas,
precondiciones, verificación y recuperación. La reconstrucción queda bloqueada
si el paquete no está completo o no es verificable. `APP_ENV` por sí solo no
identifica una base: se exige también host/nombre exactos y marca explícita de
destino desechable. Ante una identificación ambigua, abortar antes de DDL/DROP.

Se actualizarán directamente `database/ddl.sql`, `database/deploy.sql` y, cuando
corresponda, `database/development.sql`, fixtures y runners. DDL definitivo sin
estructuras de compatibilidad que hayan quedado sin consumidor. No habrá doble
escritura de stock, reconstrucción de movimientos antiguos, migración de tickets
de prueba ni adaptadores exclusivos para sostener esquemas abandonados.

La nueva versión del código y su esquema forman una sola entrega activable.
Durante el cambio, detener peticiones y trabajos que escriban y las purgas de
archivos; no mezclar binarios/esquemas. Una BD nueva separada permite mantener
la anterior intacta hasta aceptar la importación; no es un segundo modelo
operativo ni se sincroniza con ella. Una vez validada, retirar el entorno viejo
según la política de respaldo acordada.

## 4. Arquitectura de destino

El [contrato técnico compartido](contratos-tecnicos.md) concreta los doce puntos
exigidos por el documento original. Todos los responsables y cualquier subagente
que participe posteriormente usarán esa misma versión. Un cambio de contrato
se documenta antes de desarrollar sus dependencias; nadie mantiene por su cuenta
retrocompatibilidad de datos descartados.

Decisiones propuestas: una autenticación existente; unidades Global/Restaurante/
Panadería; membresía con rol por unidad; permisos existentes ver/editar más
facultades delegables; almacenes con dueño único; artículos distintos de
productos comerciales; saldo único por artículo/almacén; ofertas y recetas
por negocio; IDs explícitos de oferta en ventas y de almacén en movimientos.

Se conservan los comportamientos funcionales de Restaurante, aunque cambien
tablas o servicios internos. No se conservan firmas o estructuras sin uso por
el mero hecho de haber existido. Se preservan los contratos públicos consumidos
por las interfaces e integraciones vigentes o se actualizan conjuntamente con
sus consumidores y pruebas. Mantener `Auth`, `Permisos`, `Contextos`, `roles`,
`rol_permisos` y `unidades`; ninguna autenticación paralela.

## 5. Secuencia actualizada de etapas

Cada entrega delimita alcance, pruebas, evidencia, commit independiente y
aceptación antes de la siguiente. Sin integración a main previa a aceptación.

| Etapa | Entrega | Dependencia y aceptación |
| --- | --- | --- |
| 1. Plan, actual | Política revisada, contrato único y protocolo de menú | Aprobación de decisiones; no cambios operativos |
| 2. Preservación y ensayo | Herramientas reproducibles de respaldo/exportación/verificación e importación; paquete del menú | Contra origen explícitamente identificado, lectura sin modificar catálogo; checksums y restauración ensayados; todavía sin reconstruir la BD activa |
| 3. Base definitiva | DDL/seeds, membresías y permisos, identidad comercial/ofertas, almacenes/existencias/movimientos, identidad de negocio en tickets y finanzas; adaptar consumidores existentes y runners | Crear desde cero e importar en BD desechable; login y rutas seguras; contrato SQL único, ningún escritor al saldo antiguo |
| 4. Funcionalidad Restaurante | Completar servicios de inventario, recetas, venta/cancelación, administración, consultas, finanzas y herramientas locales existentes sobre la nueva base | Flujos vigentes con fixtures nuevos; atomicidad/concurrencia; datos del menú idénticos; sin consultas sin ámbito |
| 5. Multinegocio administrativo | Catálogo Global, almacenes múltiples, usuarios por ámbito, menú/recetas/inventario Panadería y consulta consolidada | Aislamiento HTTP/SQL y delegación; fixtures Panadería independientes; componentes compartidos |
| 6. Transferencias | Primero internas; después interámbito al aprobar incidencias | Conservación de cantidades, tránsito separado, estados y doble recepción; sin cargos financieros |
| 7. Cierre inicial y POS visual | Tickets/finanzas por negocio con datos nuevos, revisión global de regresión, diseño de POS Panadería sin mesas | Reportes sin fixtures reales, accesibilidad/responsive; ningún cobro Panadería habilitado |

La etapa 3 puede desglosarse en commits revisables sin mantener dos esquemas
funcionando a la vez; el entorno de desarrollo solo cambia al completar 3 y 4
y pasar los ensayos de importación y regresión. No activar un esqueleto que
rompa Restaurante mientras se completan los consumidores. Las columnas de
oferta y negocio se definen desde el inicio; ya no dependen de migrar historia.

No hace falta esperar una migración financiera para preparar ofertas, recetas o
usuarios. El trabajo entre áreas solo puede dividirse después de aprobar claves,
tipos y servicios comunes. No se delega trabajo de implementación en esta entrega.

## 6. Criterios y pruebas de aceptación

- Reconstrucción: DDL y deploy completos sobre BD vacía, sin errores ni esquema
  legado; development/fixtures compatibles, proceso repetible en destinos vacíos.
- Preservación: conteos por carta/categoría/estado, correspondencias biyectivas,
  igualdad de campos/precios/NULL, archivos con hash y presentación equivalente;
  sin importar productos Restaurante a Panadería. Ver protocolo de menú.
- Seguridad: servidor/SQL aplican membresía, módulo/nivel, facultad y propiedad;
  probar lector/editor, Global, ambos negocios, IDs ajenos, revocación y CSRF.
- Consistencia nueva: transacciones multiartículo, saldos negativos configurables,
  idempotencia, concurrencia con conexiones distintas, cancelación usando salidas
  originales y mercancía en tránsito sin duplicaciones. Sin conciliación antigua.
- Restaurante: login, POS/mesas/comandas, reservas/mapa, menú/recetas/variantes,
  inventario, tickets/pagos, finanzas, analíticas, KDS e impresión con datos nuevos.
- Pruebas existentes: mantener las funcionales vigentes; adaptar fixtures y
  contratos solo ante cambios justificados de esquema/comportamiento aprobado.
  No retener registros viejos ni borrar aserciones para ocultar regresiones.
- Navegador: admin, landing comida, maridaje/PDF y temporada; teclado, móvil y
  escritorio; selector único y distribución con alternativa accesible a hover.

La suite PHP actual tiene un fallo previo en el contrato de entrega del POS.
Revisar el comportamiento esperado y resolverlo con evidencia; la política nueva
no autoriza eliminar esa prueba. Cada entrega registra resultados y pendientes
reales, sin dar por ejecutados los escenarios definidos en los documentos.

## 7. Riesgos funcionales restantes

| Riesgo | Control / puerta de aceptación |
| --- | --- |
| Exportar solo productos visibles y perder inactivos o una carta | Exportar catálogo completo y categorías referenciadas; comparar por estado/carta |
| Foto propia NULL o imagen compartida de categoría perdida | Conservar semántica NULL, archivos dependientes y hashes; comprobar fallback |
| Cambiar IDs y alterar orden, enlaces o rutas de imagen | Mapas producto/categoría/área/archivo y orden explícito; probar consumidores |
| Seeds pisan menú o duplican productos | Separar estructura de contenido; importación transaccional y fixtures separados |
| Nuevo esquema rompe SQL de POS/KDS/finanzas/IA local | Inventario de consumidores y regresión con datos nuevos antes de activar |
| Cuenta administradora perdida al reiniciar usuarios | Seed seguro de superadmin de desarrollo y login probado; sin secretos versionados |
| Cambio dirigido a una BD equivocada o respaldo incompleto | Identificación explícita, preflight que aborta y restauración ensayada |
| Recepción con diferencias sin política definida | Bloquea transferencias interámbito operativas hasta acordarla |

Los riesgos de historia financiera descartable y doble modelo operativo dejan
de ser tareas de migración. Continúan los de seguridad, concurrencia y exactitud
de todas las operaciones nuevas. KDS/impresión y credenciales externas requieren
evidencia funcional propia, no solo simulaciones.

## 8. Fuera de alcance y decisiones pendientes

Siguen fuera: catering, producción avanzada, transformaciones de materias primas,
botellas abiertas/cerradas, nuevas variantes complejas, POS operativo completo
Panadería, devolución de ventas cobradas y asistente global antes de validar los
asistentes locales. No introducirlos al regenerar fixtures.

Requieren aprobación: contrato de permisos/Global, esquema propuesto y tratamiento
de incidencias. La ejecución futura de reconstrucción exige todas las puertas del
protocolo; esta actualización documental no las acredita ni las ejecuta.
