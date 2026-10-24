# Migración multinegocio: contratos técnicos de Etapa 1

Fecha: 2026-10-24. Base inspeccionada: `main`, commit `1aa642a`.
Estado: propuesta técnica para aprobación; ningún contrato nuevo está implementado.
Referencia funcional: [documento maestro v1.0](documento-maestro-multinegocio.md).

Este documento concreta los doce contratos de la sección 16. La anterior
[Etapa 1 de contextos](etapa-1.md) documenta una entrega de navegación y demo;
no acredita inventario multi almacén ni permisos multiunidad. Esta Etapa 1
arquitectónica no aplica migraciones, cambia rutas ni habilita operaciones.
La aprobación de estos contratos es una puerta previa a su implementación.

## 1. Diagnóstico de la base integrada

| Evidencia local | Comportamiento actual | Consecuencia para la migración |
| --- | --- | --- |
| `classes/Auth.php`, `services/Security/Contextos.php` | Una sesión; contexto por ruta; unidad releída; superadministrador entra a los tres contextos | Ampliar estas clases, conservar autenticación y contexto por petición |
| `models/Usuario.php`, `models/Unidad.php` | Una `usuarios.unidad_id`; `unidades` tiene id, nombre y slug | Hace falta membresía multiunidad; Global no es hoy una fila de unidades |
| `services/Security/Permisos.php`, `models/Rol.php` | Permisos por módulo `ver/editar`; `admin` y `super_admin` reciben todos | Recortar por ámbito y facultad concreta sin alterar las claves de módulos |
| `services/Security/RolService.php`, `services/Users/UsuarioService.php` | Servicios existentes de roles y cuentas | Extender sus validaciones; no crear otra gestión de credenciales |
| `models/Ingrediente.php`, `database/ddl.sql` | `ingredientes.stock` y `stock_minimo`; cantidades `DECIMAL(12,3)` | Separar identidad de saldo por almacén conservando los IDs |
| `services/Inventory/Inventario.php` | Venta enlazada por nombre; stock admite negativos; cancelación lee movimientos originales | Resolver identidad comercial y almacén histórico antes de cambiar consumo |
| `controllers/AdminInventarioController.php` | Entradas y ajustes también escriben mediante modelo/controlador | Migrar todos los escritores, no solamente `Inventario::aplicarVenta` |
| `models/Producto.php`, `producto_componentes`, `subrecetas`, `producto_variantes` | Producto reúne carta y receta; categorías, precios y variantes sin negocio | Introducir oferta por negocio y conservar compatibilidad de Restaurante |
| `models/Ticket.php`, `tickets`, `ticket_items`, `ticket_pagos` | Sin identidad de negocio en Ticket; datos históricos consultados también por nombre | Incorporar negocio sin renombrar ni recalcular historia |
| `services/Analytics/ConsultasRapidas.php`, `Analiticas.php`, `AdminFinanzasController.php` | Lecturas de stock y costos actuales en varias consultas | Auditar lectores, reportes y herramientas de IA antes del corte |
| `database/fixtures/panaderia.php` | Fixture PHP de solo consulta en development/test | Mantenerlo fuera de las tablas y reportes reales |

El esquema versionado efectivo está en `database/ddl.sql` y `database/deploy.sql`.
No usar `ddl.sql` como migración sobre una instalación: contiene DROP de tablas.
El estado real de una instalación se verificará con una auditoría de metadatos
antes de generar su migración incremental; no se inspeccionaron datos reales.

## 2. Ámbitos, membresías y permisos

**Decisión propuesta:** reutilizar `unidades` con una fila `slug=global`, junto a
`restaurante` y `panaderia`. Global es un propietario administrativo, nunca un
negocio vendedor. Resolver IDs por slug; ninguna constante de ID de producción.
Los tipos de FK deben coincidir con los existentes, incluidos sus signos.

Añadir `usuario_unidades(usuario_id, unidad_id, rol_id, activo)` con unicidad
`(usuario_id, unidad_id)`. Cada membresía usa `roles` y `rol_permisos`. Así una
cuenta puede ser editora en Restaurante y lectora en Panadería mediante roles
distintos. No habrá otra tabla de contraseñas ni otro inicio de sesión.

Los roles personalizados nuevos podrán tener `unidad_id` propietario; NULL
queda reservado a plantillas compartidas existentes y roles de sistema.
Un administrador local solo modifica roles propios. Para adaptar una plantilla
compartida crea un rol local; no puede cambiar la plantilla que afecta a otros.

Añadir `usuario_unidad_facultades(usuario_id, unidad_id, facultad)` como
subconjunto explícito de operaciones delegables: `usuarios.gestionar`,
`permisos.delegar`, `almacenes.crear`, `catalogo.editar`,
`transferencias.solicitar`, `transferencias.enviar`, `transferencias.recibir` y
`transferencias.supervisar`. FK a la membresía y clave única por facultad.
Son restricciones de operación adicionales, no sustituyen los módulos.
`almacenes.crear` y `catalogo.editar` solo son válidas en Global.

`super_admin` conserva su excepción organizacional explícita. `admin` conserva
sus módulos actuales en sus membresías autorizadas, sin convertirse en Global.
Las nuevas facultades se otorgan explícitamente, no por incorporar un módulo al
catálogo. El inventario anterior de Restaurante mantiene entradas, ajustes y
mermas para sus editores durante el corte, pero no crea nuevos almacenes.

Contrato propuesto de autorización, extendiendo Auth, Contextos y Permisos:

```text
autorizar(actor autenticado, ambito solicitado, modulo, nivel, operacion, recursos)
  -> {permitido, codigo, alcanceAutorizado}
```

El servidor carga actor activo y membresías actuales; no acepta actor ni permiso
del formulario. `editar` incluye `ver`. Ausencia de membresía, módulo o facultad
requerida deniega. Un error de lectura de membresías deniega; los respaldos
legados de roles no conceden nuevos ámbitos. La ruta determina contexto, nunca
un selector mutable de sesión. Cada API nueva se registra en `Permisos::APIS`:
actualmente una API ausente del catálogo queda pública.

Para escrituras se revalida autorización dentro de la transacción; revocaciones
y escrituras deben bloquear las mismas filas de membresía en orden estable.
Un permiso global de consulta permite consolidar el módulo autorizado. No
concede automáticamente escritura local: operar un almacén de negocio exige
membresía local o el privilegio explícito de superadministrador. Global opera
sus almacenes centrales con sus propios permisos.

Gestión local de cuentas compartidas: listar solo su membresía y datos mínimos;
editar únicamente membresía y rol locales. Contraseña, NIP, activación global,
borrado de identidad y membresías ajenas se reservan a Global para cuentas
multiunidad. Una cuenta exclusivamente local puede administrarse localmente
según las facultades delegadas. Se conserva la protección del último superadmin.

Delegar exige `users/editar` y `permisos.delegar`; modificar definiciones de rol
exige además `roles/editar` (claves existentes de Permisos). El conjunto propuesto de
módulos, niveles y facultades debe estar contenido en el del actor en ese mismo
ámbito. Un local no puede crear membresías Global, asignar `super_admin`, ni
otorgar `admin` si eso supera su conjunto efectivo. La regla también se aplica
al editar un rol utilizado por otras cuentas y se audita antes/después y actor.
Autenticación por contraseña/NIP conserva sus mecanismos: para multiunidad se
calcula el tipo de acceso con la unión de módulos de membresías activas usando
`Permisos::acceso`; algún módulo de panel exige contraseña, sin acceso por NIP
conservado. Los hashes y HMAC existentes no cambian.

Responsables: Auth valida identidad; Contextos resuelve membresías; Permisos
evalúa módulo/nivel; servicios de usuarios/roles controlan delegación.
Pruebas: usuario distinto en dos unidades, revocación sin relogin, POST directo,
escalamiento de rol, fallo de BD, cuenta compartida y preservación de piso.

## 3. Almacenes y catálogo de artículos

**Identidad maestra:** conservar `ingredientes.id` como `articulo_id` del dominio.
No crear otra copia de los mismos artículos. `productos` sigue siendo identidad
comercial. Global modifica nombre, unidad y estado maestro; un negocio modifica
solo asociación, mínimo y saldo de sus almacenes. Con historial, unidad de medida
es inmutable: una conversión requiere migración específica y no se hace aquí.

Modelo objetivo (nombres propuestos, todavía sin DDL):

| Entidad | Datos y restricciones |
| --- | --- |
| `almacenes` | id, unidad_id NOT NULL, nombre, activo, permitir_negativos; un propietario; Global puede ser propietario |
| `almacen_articulos` | PK (almacen_id, articulo_id), saldo DECIMAL(12,3), minimo DECIMAL(12,3), activo; FK a ingredientes y almacenes |
| `operaciones_inventario` | id, clave_idempotencia, hash_solicitud, tipo, actor/origen, referencia, fecha; unicidad por actor/origen y clave |
| `movimientos_inventario` ampliada | almacén, operación, artículo existente, delta firmado, origen de reversión, costo histórico y autor/origen |

No se borra físicamente un almacén o artículo con historial. El propietario no
se reasigna después de movimientos; se usa transferencia. Baja con saldo o
tránsito pendiente se bloquea. Inactivar una asociación no oculta su historial.
No se crean asociaciones con saldo implícito: inicio cero y movimiento explícito.
Crear almacén exige `global + inventario/editar + almacenes.crear`; asignar
propietario valida una unidad conocida. Global no es un almacén físico.

Cantidades viajan como cadenas decimales exactas, máximo tres decimales y dentro
del rango SQL. Costos unitarios usan cuatro decimales; importes financieros
conservan su precisión y regla de redondeo vigente. No usar float para validar
saldos nuevos. No sumar cantidades de artículos o unidades diferentes.

Responsables: servicio de catálogo para identidad; servicio de almacenes para
propiedad; servicio de inventario para saldo. Pruebas: artículo compartido con
saldos distintos, propietario manipulado, unidad incompatible y baja bloqueada.

## 4. Interfaz del servicio de inventario

Se amplía `Services\Inventory\Inventario` mediante métodos explícitos y un
adaptador de compatibilidad. Las firmas actuales permanecen mientras los
consumidores de Restaurante se migran. Las nuevas operaciones devuelven errores
verificables; no heredan la captura silenciosa de errores de la venta antigua.

| Operación propuesta | Entrada específica | Salida / invariantes |
| --- | --- | --- |
| `consultarExistencias` | alcance autorizado, filtros de artículo/almacén, paginación | totales por artículo, unidad, ámbito, distribución, mínimos y estado |
| `registrarEntrada` | almacén, líneas artículo/cantidad positiva/costo, referencia | operación, movimientos y saldos; destino físico obligatorio |
| `registrarAjuste` | almacén, artículo, saldo contado, versión esperada, motivo | delta calculado bajo bloqueo; conflicto si cambió versión |
| `registrarMerma` | almacén, líneas positivas, motivo vigente y nota | deltas negativos y costos congelados |
| `aplicarVentaPorOferta` | negocio del ticket, item, oferta, cantidad, variantes válidas | consumo agregado por artículo/almacén con referencias persistidas |
| `revertirMovimientos` | referencia original, motivo, cantidades a revertir | movimientos compensatorios en los almacenes originales |

Toda escritura recibe además actor resuelto por Auth y clave de idempotencia;
el servicio verifica ámbito, permisos y propiedad aunque lo invoque otro servicio.
Automatizaciones usan origen identificado y alcance previamente autorizado,
nunca un `usuario_id=NULL` que evada controles. No implementar aún herramientas
globales de IA ni aceptar SQL libre como mecanismo de autorización.

Unidad transaccional: misma conexión InnoDB para documento (venta/transferencia),
saldo y movimientos. El coordinador inicia/termina; inventario participa sin
commits internos ni transacciones anidadas. Una llamada independiente utiliza
el mismo coordinador. Bloquear asociaciones por `(almacen_id, articulo_id)`
ordenado, agregar líneas repetidas y validar todas antes de escribir. Si falta
una asociación se deniega: solo su alta autorizada puede crearla con saldo cero.
Fallo de cualquier línea revierte la operación completa. Reintentos por deadlock
son acotados y repiten toda la transacción con la misma clave.

Si `permitir_negativos=false`, cualquier delta de salida debe dejar saldo >= 0.
Aplica a venta, merma, ajuste negativo, envío y devolución de tránsito al origen
cuando corresponda; no hay excepción nueva implícita. Con true se registra el
saldo negativo completo. Cambiar la bandera requiere inventario/editar del dueño;
no se puede deshabilitar con saldos negativos pendientes. El almacén inicial
de Restaurante nace con true para conservar su política actual.

Idempotencia: reservar clave y huella del payload normalizado bajo índice único
en la misma transacción. Repetición idéntica retorna el resultado original sin
movimientos adicionales; payload diferente produce conflicto. No persistir éxito
si el commit falla. Las referencias de negocio también tienen unicidad para
evitar duplicados aunque el cliente cambie la clave.

Pruebas: dos salidas concurrentes de 4 sobre saldo 5 dejan solo una aceptada;
fallo SQL en segunda línea no deja cambios; doble reintento no duplica; ninguna
venta consume almacén ajeno; la merma sigue el mismo bloqueo de negativos.

## 5. Movimientos y conciliación

Convención única: `cantidad` es delta; positivo ingresa, negativo sale. Tipos
nuevos: `apertura_migracion`, `transferencia_salida`, `transferencia_entrada`,
`transferencia_retorno`. Se conservan `venta`, `cancelacion`, `ajuste`, `entrada`
y `merma`. Un cero no genera movimiento. Correcciones son compensaciones, no
ediciones de bitácora. Conservar fecha, costo histórico conocido (NULL si no lo
era), usuario/origen, motivo, operación y referencia estable (ticket item,
transferencia/línea o documento administrativo). No inventar costos históricos.

`movimiento_origen_id` referencia la salida original. Bloquearla y garantizar
que la suma de compensaciones no exceda la salida; para cancelación completa,
una unicidad por origen evita doble reposición concurrente. No leer receta ni
almacén configurado hoy para cancelar una venta pasada. Borrar artículo o
usuario no debe borrar movimientos: sustituir el CASCADE histórico del artículo
por restricción y mantener identificación auditable de actores inactivos.

Después del corte, por asociación:
`saldo = saldo al corte + suma(deltas posteriores al corte)`.
El movimiento de apertura documenta ese saldo y cuenta una sola vez. La historia
previa queda consultable y separada por versión/corte; no se vuelve a aplicar
para calcular saldo. Una conciliación detecta divergencias y bloquea la aceptación,
sin corregir silenciosamente importes o movimientos anteriores.

## 6. Transferencias

Cabecera: id, origen/destino físicos distintos, propietarios derivados y
congelados, solicitante, emisor, receptor, estado y versión. Líneas: artículo,
cantidad positiva enviada/recibida, unidad y costo al envío. Historial inmutable
registra cada transición, actor, fecha y motivo. No hay cargo financiero.

| Desde → hacia | Quién / condición | Efecto atómico en existencias |
| --- | --- | --- |
| nueva → solicitada | Actor con solicitar en origen o destino y visibilidad mínima de contraparte | Ninguno; no reserva stock |
| solicitada → enviada | Enviar y editar inventario de origen; validar saldo al momento del envío | Salida origen; mercancía en tránsito |
| enviada → recibida | Recibir y editar inventario de destino; cantidades exactamente iguales | Entrada destino; cierra tránsito |
| solicitada → cancelada | Solicitante autorizado o emisor de origen, motivo | Ninguno |
| solicitada → rechazada | Contraparte autorizada, motivo | Ninguno |
| enviada → incidencia | Destinatario autorizado, motivo y cantidades observadas | Ninguno; conserva tránsito |
| nueva → recibida (interna) | Origen/destino del mismo dueño; editar ambos y facultad enviar | Salida y entrada juntas; sin confirmación contraparte |

La solicitud puede iniciarla destino (pedido) u origen (ofrecimiento); envío solo
lo confirma origen y recepción solo destino. Si un usuario tiene ambas membresías
puede desempeñar ambos papeles, registrándose cada acción y su ámbito. Global
con supervisar consulta cabecera y tránsito de toda la organización; no recibe
por el destinatario por ese solo permiso. Exponer a contraparte únicamente datos
de almacén y líneas necesarios, no su inventario completo ni finanzas.

Estado/cabecera bloqueados `FOR UPDATE`, versión esperada y referencia única de
movimiento por línea/transición. Recibir dos veces se resuelve idempotentemente
o como estado inválido sin nueva entrada. No modificar líneas después de envío.
Antes de enviar no hay stock reservado; una solicitud puede fallar por saldo.

En tránsito no hay saldo disponible en destino. Consolidado muestra por separado
disponible físico y tránsito; la suma física + tránsito conserva cantidades entre
envío y recepción. Movimiento interno no deja tránsito al finalizar el commit.

**Decisión funcional todavía pendiente:** diferencias, recepción parcial, pérdida
y retorno después del envío. Propuesta conservadora para revisión: bloquear
recepción desigual, pasar a incidencia y conservar tránsito, sin reponer origen
ni abonar destino automáticamente. El tipo retorno queda reservado y no se
habilita hasta aprobar responsables, cantidades y cierre de incidencias. Esta
decisión bloquea habilitar transferencias entre ámbitos en operación real.
Pruebas: envío concurrente, receptor ajeno, doble recepción, destino manipulado,
fallo entre salida/entrada interna y conservación de tránsito en incidencia.

## 7. Productos, ofertas y recetas

Conservar `productos.id` como identidad comercial compartible, distinta de
`ingredientes.id`. Añadir `ofertas_producto(id, producto_id, unidad_id,
categoria_id, precio, activo, ...)` con UNIQUE(producto_id, unidad_id).
Categorías tienen negocio propietario; recetas y subrecetas operativas quedan
vinculadas a la oferta/unidad, con versiones de composición. Migrar la receta
existente a la oferta Restaurante conservando referencias mediante mapa de IDs.
No copiar automáticamente recetas a Panadería ni compartir componentes mutables.

Variantes existentes se vinculan a la oferta Restaurante y conservan su cálculo.
Panadería puede configurar variantes propias; compartir una plantilla exige
versión explícita y no propaga cambios a recetas publicadas de otro negocio.
No implementar variantes complejas nuevas en esta migración.

Consumo administrable por `(oferta_id, articulo_id, almacen_id)`, cantidades
resueltas desde receta/variantes. Todo almacén pertenece al negocio vendedor;
no hay elección automática de otro negocio si falta saldo. La primera versión
configura un origen por artículo/oferta; no implementa reparto automático entre
almacenes. Falta de configuración en una oferta inventariable impide publicarla.
Ofertas explícitamente sin receta conservan su comportamiento sin consumo.

Ticket item nuevo persiste oferta, producto y snapshot de nombre, precio,
variantes, costo conocido y versión de receta; los movimientos congelan almacén
y cantidades reales. Ya no se resuelve una venta nueva por nombre. Para items
legados se conserva lectura histórica; el enlace inicial por nombre exige
auditoría de ambigüedades. Un nombre coincidente nunca fusiona identidades.
Responsables: servicios de menú/recetas y adaptador POS. Pruebas: mismo capuchino
con precios y recetas distintos; editar Panadería no altera Restaurante; variante
ajena rechazada; cancelar después de cambiar receta y almacén repone el original.

## 8. Tickets, finanzas y filtros de consulta

`tickets.unidad_id` será obligatorio y solo admite unidades vendedoras, nunca
Global. Histórico se atribuye a Restaurante sin cambiar IDs, fechas, mesas,
estados, cobros ni snapshots. Items y pagos heredan negocio por ticket; siempre
se unen al padre autorizado antes de leer/escribir. Gastos fijos y otros ingresos
o egresos independientes reciben propietario explícito. Gastos Global son Global;
no se prorratean automáticamente. Cada concepto se contabiliza una sola vez.

Todo repositorio nuevo recibe un `AlcanceAutorizado` generado en servidor:
`{actor, contexto, modulo, unidadesPermitidas}`. Una consulta local impone
`WHERE unidad_id = ?`; Global autorizado usa el conjunto validado o subconjunto
solicitado, nunca una condición opcional que omita el filtro por valor vacío.
Joins de artículos pasan por almacén/propietario; joins de pagos por ticket.
Detalle, búsqueda, conteo, exportación, gráficos, impresión y herramientas de IA
usan el mismo alcance. Cachés incluyen ámbito y autorización vigente; nunca
comparten una respuesta consolidada con una consulta local.

No se suma dos veces un ticket por unir simultáneamente pagos e items: agregar
cada relación antes del JOIN. Conservar importes registrados; costos históricos
desconocidos se muestran como desconocidos, sin recalcular al precio de hoy.
Separar estimaciones vigentes del costo histórico financiero. Las transferencias
no son ingresos ni egresos monetarios. Fixtures solo en base desechable; la demo
PHP nunca alimenta consultas financieras reales. Futuras importaciones de demo
exigen marca de dataset y exclusión obligatoria en reportes reales.

Panadería: venta inmediata sin `ticket_mesas`, con negocio obligatorio; el POS
solo se diseña visualmente en una etapa posterior, sin abrir cobro operativo.
Devolución de venta cobrada sigue pendiente y no se implementa anticipadamente.
Las integraciones públicas de Restaurante siguen su identidad explícita de
Restaurante; un contexto de sesión no las convierte en Panadería. IA global
permanece deshabilitada hasta validar los asistentes locales.

Responsables: servicios de tickets, finanzas, consultas y adaptadores existentes.
Pruebas: IDs ajenos en detalle/pago/exportación, filtros vacíos y manipulados,
Global con solo lectura, totales independientes que concilian con consolidado,
sin fixtures, sin duplicación por pagos múltiples y sin costos inventados.

## 9. Errores y resultados para interfaces

Contrato nuevo común; adaptadores conservan respuestas de endpoints existentes
hasta migrar sus clientes. No cambiar masivamente contratos legacy.

```json
{"ok":true,"codigo":"ENTRADA_REGISTRADA","mensaje":"Entrada registrada.","datos":{"operacion_id":123,"movimientos":[456],"saldos":[{"almacen_id":8,"articulo_id":3,"cantidad":"2.500"}]},"errores":{},"request_id":"identificador"}
```

| HTTP | Código estable | Uso |
| --- | --- | --- |
| 400 | SOLICITUD_INVALIDA | Estructura o tipo de entrada inválidos |
| 401 | NO_AUTORIZADO | Sesión ausente o cuenta inactiva |
| 403 | PERMISO_DENEGADO / AMBITO_DENEGADO | No puede usar módulo u operación |
| 404 | RECURSO_NO_ENCONTRADO | ID ausente o ajeno en consulta de recurso; mismo mensaje |
| 409 | STOCK_INSUFICIENTE / ESTADO_INVALIDO / VERSION_CONFLICTO / IDEMPOTENCIA_CONFLICTO | Conflicto de estado o concurrencia |
| 422 | DATOS_INVALIDOS / CONFIGURACION_INCOMPLETA | Errores por campo o receta/origen sin configurar |
| 503 | SERVICIO_NO_DISPONIBLE | No se verificó autorización o BD temporalmente indisponible |
| 500 | ERROR_INTERNO | Fallo inesperado; referencia en log, sin SQL ni secretos en respuesta |

En error: `ok=false`, `datos=null`, `errores` por campo solo si son visibles al
actor. El mensaje no revela saldos o propietarios ajenos. `request_id` relaciona
log y respuesta. No retornar éxito antes del commit. POST incluye la protección
CSRF existente; ninguna mutación nueva usa GET. Formularios HTML reutilizan
alertas y preservan campos válidos; UI no repite operaciones no idempotentes.

## 10. Migración histórica y compatibilidad de Restaurante

Secuencia incremental, ensayada primero sobre base desechable:

1. Inventariar escritores/lectores SQL, endpoints, integraciones e índices;
   auditar nombres ambiguos, huérfanos, reversos y correspondencia de esquemas.
   Establecer respaldo restaurable y checksum de historia antes del corte.
2. Agregar tablas/columnas compatibles sin activar lectores nuevos. Migraciones
   versionadas con precondiciones y registro de ejecución. Separar DDL de DML:
   MySQL puede hacer commit implícito en DDL; no prometer rollback de esquema.
3. Resolver unidades por slug y crear Global. Generar membresías a partir de
   asignaciones válidas; personal legado sin unidad conserva Restaurante;
   administradores sin unidad válida siguen denegados. No asignarlos por omisión.
   Mantener `usuarios.rol` como tipo de acceso legado y unidad principal durante
   transición; no consultar ambas fuentes sumando permisos. Activar membresías
   como fuente única solo al completar la migración de seguridad.
4. Crear almacén inicial Restaurante con negativos habilitados. Bajo pausa de
   todas las escrituras de stock (incluidos POS, administración y automatismos),
   copiar `ingredientes.stock` y mínimos una sola vez a sus asociaciones; guardar
   corte, apertura y comparación por artículo. No reconstruir el saldo sumando
   historia incompleta. Atribuir movimientos anteriores a ese almacén conservando
   IDs/deltas/costos/fechas; marcar procedencia histórica inferida por migración.
5. En el mismo corte, cambiar todos los escritores y lectores al saldo por
   almacén. `ingredientes.stock` deja de ser fuente operativa: retirar escritura
   y ofrecer alias de lectura de Restaurante a consumidores compatibles hasta
   retirarlo. No mantener dos saldos editables ni escritura doble. Si queda un
   escritor o lector de stock sin adaptar, abortar la activación antes de reabrir.
6. Crear ofertas Restaurante y migrar componentes/categorías/variantes con mapas
   de correspondencia. Asignar negocio a tickets y hechos financieros existentes
   por lotes verificables. Capturar consumos originales de ventas abiertas; una
   cancelación posterior debe compensar exactamente movimientos previos al corte.
7. Activar por módulo tras comparar resultados viejos/nuevos de solo lectura.
   Añadir NOT NULL/FK/índices definitivos cuando no queden filas incompatibles.
   Mantener adaptadores de rutas y respuestas Restaurante hasta migrar clientes.

Cancelaciones anteriores: auditar ventas ya repuestas y construir referencias
de compensación por item/artículo sin inventar correspondencias si son ambiguas.
Los casos no conciliables requieren resolución documentada antes del corte;
no volver a reponer por ausencia de la FK nueva.

Rollback: antes de escrituras nuevas, volver a lectores antiguos y descartar
estructuras aditivas no usadas. Después del primer movimiento multi almacén,
no volver a un binario que escriba `ingredientes.stock`: pausar operación,
restaurar respaldo y reproducir operaciones verificadas o aplicar reparación
hacia adelante. Ensayar restauración y medir ventana; no declararla validada
por pasar pruebas unitarias. Nunca ejecutar fixtures ni DROP sobre la BD real.

## 11. Entregas posteriores y aceptación

Cada fila será una entrega con commit independiente y aprobación antes de avanzar.
El orden evita habilitar negocios sobre consultas todavía sin aislamiento.

| Entrega | Alcance | Criterio de aceptación |
| --- | --- | --- |
| 1, actual | Documento maestro preservado, diagnóstico y contratos propuestos | Revisión explícita de contratos; ninguna operación habilitada |
| 2 | Membresías, ámbito de roles, delegación, filtros de usuarios y alcance SQL | Matriz HTTP de privilegios, revocación y cuentas compartidas; acceso Restaurante conservado |
| 3 | Catálogo, almacenes, existencias, servicio y migración de todos los consumidores de stock | Conciliación exacta, concurrencia/atomicidad y cancelación histórica en almacén original |
| 4 | Transferencias internas y, tras resolver incidencias, entre ámbitos | Invariantes de tránsito, transiciones, doble recepción y permisos de ambas partes |
| 5 | Ofertas, recetas, categorías y variantes por negocio | Edición independiente sin cambios en menú, receta, POS o carta Restaurante |
| 6 | Tickets, finanzas y consolidación autorizada | Historia intacta, reportes conciliados, exports aislados, sin fixtures reales |
| 7 | Administración de Panadería y diseño de su POS sin mesas | Flujos administrativos probados, revisión visual responsive; sin cobro operativo |

Las dependencias de identidad comercial/ticket necesarias para consumir stock
se preparan antes del corte de la entrega 3, aunque su UI completa llegue en 5/6.
Ninguna entrega activa una ruta a medias ni deja escritores legados operando
contra una fuente retirada. IA global, producción avanzada, catering, botellas,
devolución cobrada y POS operativo Panadería permanecen fuera de alcance.

Matriz obligatoria para entregas con implementación:

- Automatizadas sin BD: decisiones de permisos y transiciones/resultados.
- Integración en BD desechable: restricciones, migración repetida, idempotencia,
  concurrencia real con conexiones distintas, fallos inyectados y rollback.
- HTTP autenticado: cada lectura/escritura con Global, cada negocio, lector,
  editor, usuario sin permiso, cuenta inactiva e IDs ajenos manipulados.
- Regresión Restaurante: mesas, comandas, reservas/mapa, KDS/impresión, recetas
  y variantes, inventario/cancelación, tickets/cobros y reportes. Integraciones
  reales requieren su evidencia propia; simulaciones no certifican credenciales.
- Navegador si cambia UI: selector único, responsive, teclado, estados vacíos,
  errores y distribución con hover más alternativa táctil/tabular accesible.
- Comparación de historia: conteos, IDs e importes/costos conocidos antes/después,
  integridad referencial y conciliación de saldos; sin datos demo en reportes.

No se requieren capturas nuevas para esta entrega exclusivamente documental.
No se avanza con regresiones nuevas ni se integra a main sin aceptación.

## 12. Decisiones que requieren aprobación

Se proponen explícitamente Global como unidad administrativa, membresías con rol
por unidad, facultades delegables, conservación de ingredientes como identidad,
ofertas por negocio, origen único por artículo/oferta y corte sin doble escritura.
Sus contratos son revisables aquí; no se consideran aprobados por estar escritos.

Antes de transferencias reales falta aprobar el tratamiento definitivo de
incidencias/diferencias. Antes del POS operativo Panadería falta la política de
cancelación/devolución cobrada, fuera del alcance inicial. No ejecutar esas
funciones con una decisión técnica improvisada como sustituto de la funcional.

## 13. Evidencia de esta entrega

Ver [validación de Etapa 1](validacion-multinegocio-etapa-1.md). Se distingue
la línea base ejecutada de las pruebas futuras: los escenarios descritos arriba
son criterios de aceptación, no pruebas implementadas ni resultados obtenidos.
