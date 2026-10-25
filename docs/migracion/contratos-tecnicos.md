# Contrato técnico compartido multinegocio

> **Histórico — propuesta técnica acumulada durante 3A–3C.** No es el contrato
> rector ni acredita módulos operativos. Las firmas ejecutables se documentan en
> [autorización 3B](contrato-autorizacion-3b.md), [Almacenes 3C](contrato-almacenes-3c.md)
> y [comercio 3D](etapa-3d-contratos-comerciales.md); el
> [contrato 3E](../contrato.md) resuelve alcance, estado y precedencia actuales.


Versión 1.4 · 2026-10-24 · Autorización 3B y motor/consultas de Almacenes 3C.
Rige el [plan maestro](plan-maestro.md) y la [política de desarrollo](actualizacion-obligatoria-desarrollo.md).
El [modelo relacional 3A](modelo-relacional-3a.md) concreta las tablas/relaciones
implementadas y las diferencias del borrador. Los métodos operativos siguientes
continúan siendo contratos para la adaptación posterior; no están activados.

**Regla común:** adoptar directamente un esquema definitivo. No migrar usuarios,
recetas, variantes, stock, movimientos, tickets ni finanzas de prueba. Única
importación obligatoria: catálogo comercial de Restaurante y dependencias del
[paquete de menú](procedimiento-menu.md). No crear adaptadores para esquemas
descartados. Sí conservar flujos funcionales vigentes y adaptar sus consumidores
y fixtures conjuntamente. La trazabilidad siguiente se refiere a datos nuevos.

## 1. Ámbitos y permisos

Reutilizar `Auth`, `Permisos`, `Contextos`, `roles`, `rol_permisos` y `unidades`.
Global será una fila de `unidades` con slug `global`, propietario administrativo,
no vendedor. Restaurante y Panadería son unidades vendedoras. Resolver por slug;
no asumir IDs de producción. Tipos/signos de claves deben coincidir en todo DDL.

`usuario_unidades(usuario_id, unidad_id, rol_id, activo)` tiene clave única por
usuario/unidad. `rol_id` es metadata nullable, sin permisos efectivos.
`usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel)` referencia esa
membresía y es la única fuente de permisos; no suma `rol_permisos` ni
`usuarios.unidad_id`. Panel usa ver/editar; pdv/area/reservations_map usan operar.
Al reconstruir, reemplazar esa asignación única por las membresías nuevas.
No se migran asignaciones ni respaldos de usuarios descartados.

Roles conservados son infraestructura interna. No hay plantillas ni editor de
roles en 3B. `usuarios.es_superadmin` expresa la excepción organizacional protegida;
el resto requiere asignaciones directas. Global no implica acceso local ni acceso
a todos los módulos. Véase el [contrato efectivo 3B](contrato-autorizacion-3b.md).

`usuario_unidad_facultades` referencia una membresía con unicidad por facultad:
`usuarios.gestionar`, `permisos.delegar`, `almacenes.crear`, `catalogo.editar`,
`transferencias.solicitar`, `transferencias.enviar`, `transferencias.recibir`,
`transferencias.supervisar`. Complementan, no reemplazan, el permiso de módulo.
Crear almacenes y editar identidad maestra solo son facultades válidas de Global.
Nuevas facultades no se conceden automáticamente por agregar un módulo.

Entrada de autorización: actor autenticado, ámbito por ruta/petición, módulo,
nivel ver/editar, operación concreta y recursos. Salida: permitido/código y
`AlcanceAutorizado` con unidades verificadas. Ausencia de permiso deniega;
editar incluye ver. No aceptar actor, roles ni alcance del cliente. Releer cuenta
activa y membresías por petición; fallo de BD deniega acceso. El selector solo
navega; no modifica autorización ni contexto de otra pestaña.

Registrar cada API de personal en `Permisos::APIS`, incluidas las nuevas: hoy
la clasificación 3B deniega las APIs desconocidas y enumera las públicas. CSRF en mutaciones y nada de
mutaciones mediante GET. Revalidar permisos y pertenencia dentro de escrituras;
revocación y operaciones bloquean filas de membresía en el mismo orden.

Global con consulta consolidada puede leer todo el módulo concedido. Operar
almacenes ajenos exige membresía local autorizada o superadmin; Global opera
centrales propios con permisos Global. Supervisar no equivale a recibir por otro.

Delegación: `users/editar`, `usuarios.gestionar` y `permisos.delegar`.
El conjunto concedido debe quedar dentro del techo del actor en el destino,
o su techo Global al delegar desde Global. Un local no crea Global, no asigna superadmin
ni concede admin por encima de sus privilegios. Auditar actor y antes/después.
Una cuenta multiunidad admite edición local de su membresía; credenciales,
desactivación global y borrado de identidad se gestionan desde Global. Cuentas
exclusivamente locales se gestionan según facultades delegadas sin salir del ámbito.

Conservar contraseñas/NIP y HMAC como mecanismos de autenticación, con usuarios
nuevos. El modo usa `PermisosUsuario::tipoAcceso` sobre unión de módulos activos:
un módulo de panel exige contraseña; no admite NIP residual. La sesión registra
auth_nivel y exige reautenticación al promover una sesión PIN. No crear otro login.
Responsables: Auth/Contextos/Permisos y servicios de usuarios/roles existentes.
Pruebas: matriz multiunidad, revocación, IDs ajenos, fallo BD, escalamiento,
edición de cuenta compartida y acceso NIP/contraseña con fixtures nuevos.

## 2. Almacén físico y propietario

`almacenes(id, unidad_id, nombre, activo, permitir_negativos)` tiene propietario
obligatorio y único. Global puede poseer centrales. Crear almacén exige
Global + inventario/editar + almacenes.crear; propietario válido de unidades.
El inventario consolidado es una consulta, no una fila de almacén ficticia.
No reasignar dueño tras registrar movimientos: transferir mercancía. Baja con
saldo o tránsito pendiente se bloquea; historial nuevo impide borrado físico.
Responsable: servicio de almacenes. Pruebas: alta local denegada, dueño
manipulado, selector de almacén ajeno y baja con operaciones pendientes.
Implementación y API interna de 3C: [contrato del motor](contrato-almacenes-3c.md).
El coordinador externo recibe estado pendiente hasta que confirme su transacción.

## 3. Artículos y existencias

`ingredientes` puede conservarse como nombre de tabla del catálogo maestro de
artículos; sus filas se generan nuevas. No es `productos`, el catálogo comercial.
Identidad compartida del artículo: nombre, unidad y activo; Global la modifica.
Con movimientos nuevos, unidad de medida inmutable para evitar reinterpretarlos.

`almacen_articulos`: PK (almacen_id, articulo_id), saldo DECIMAL(12,3), mínimo
DECIMAL(12,3), activo y versión de concurrencia. No hay saldo maestro editable
en ingredientes ni tabla adicional de totales. Asociación explícita empieza en
cero; saldos de fixture se generan mediante entradas trazables del esquema nuevo.
Cada negocio administra sus existencias/asociaciones, no la identidad compartida.

Cantidades se transportan como cadenas decimales exactas, hasta tres decimales;
costos unitarios con cuatro; validar rango y unidad. Rechazar valores no finitos
y precisión excesiva. No usar float para comprobaciones nuevas de saldo ni sumar
artículos o unidades diferentes. Totales derivados suman asociaciones una vez;
mostrar artículo, unidad, ámbitos, almacenes, mínimos/alertas, estado y movimientos.
La distribución visual tiene alternativa táctil/tabular accesible.
Responsable: inventario. Pruebas: saldos independientes, unidad inválida,
consolidado exacto y ausencia de doble escritura.

## 4. Servicio de inventario: entradas y salidas

Evolucionar `Services\Inventory\Inventario` y actualizar sus consumidores juntos.
No conservar métodos silenciosos o enlaces por nombre solo por compatibilidad
con esquema descartado. Mantener el comportamiento funcional acordado, con
resultado explícito cuando una operación no pueda completarse.

Todas las escrituras reciben actor resuelto, clave de idempotencia, referencia
y alcance verificado. El servicio valida también si se invoca sin controlador.

| Operación | Entrada específica | Salida / invariante |
| --- | --- | --- |
| consultarExistencias | alcance, filtros artículo/almacén, paginación | totales/desglose por artículo y unidad dentro de alcance |
| registrarEntrada | almacén físico, líneas artículo/cantidad positiva/costo | operación, movimientos y saldos; nunca destino Global sin almacén |
| registrarAjuste | almacén, artículo, conteo, versión esperada, motivo | delta calculado bajo bloqueo; conflicto si cambió saldo |
| registrarMerma | almacén, líneas positivas, motivo catálogo/nota | deltas negativos y costo al momento |
| aplicarVentaPorOferta | negocio/ticket item, oferta, cantidad, variantes válidas | consumo propio por artículo/almacén con referencias persistidas |
| revertirMovimientos | salidas originales, cantidades a compensar, motivo | compensación en almacenes originales, limitada a lo consumido |

Unidad transaccional: misma conexión InnoDB para venta/transferencia, saldos y
movimientos. El coordinador inicia/termina; inventario participa sin commits
internos ni transacciones anidadas. Una llamada independiente usa el coordinador.
Bloquear asociaciones por (almacen_id, articulo_id) ordenado, agregar líneas
repetidas y validar todo antes de escribir. Asociación ausente se rechaza; su
alta autorizada es otra operación. Cualquier fallo revierte el conjunto completo.

Si permitir_negativos=false, toda salida debe dejar saldo >= 0: venta, ajuste
negativo, merma y envío. Si true, se permite saldo negativo trazable. Configurar
el almacén inicial Restaurante con true mantiene su comportamiento vigente;
deshabilitar negativos exige no tener saldos negativos pendientes. Automatismos
tienen origen identificado y ámbito explícito; usuario NULL no es autorización.

`operaciones_inventario` registra tipo, actor/origen, referencia, clave única y
huella de solicitud normalizada. Reservar idempotencia y datos en la misma
transacción. Reintento idéntico retorna resultado original; distinto payload con
misma clave da conflicto. Referencias de negocio únicas evitan duplicar cambiando
la clave. Reintentos de deadlock acotados repiten toda la operación. Éxito solo
tras commit. Responsable: inventario y coordinadores de cada documento.
Pruebas: dos salidas de 4 sobre saldo 5, fallo en segunda línea, reintento,
negativos true/false y negocio de venta distinto al dueño del almacén.

## 5. Movimientos y referencias nuevas

`movimientos_inventario`: operación, artículo, almacén físico, tipo, cantidad
firmada, fecha, usuario/origen, referencia de documento, motivo, costo al momento
cuando aplique y movimiento origen de reversión. Tipos: entrada (+), venta (-),
merma (-), ajuste (+/-), cancelacion (+), transferencia_salida (-),
transferencia_entrada (+); cero no genera movimiento. Retorno de tránsito queda
reservado a la política de incidencias aún pendiente.

La bitácora nueva es inmutable; corregir con compensación. No borrar por CASCADE
de artículo ni perder identificación del actor inactivo. Saldo por asociación
equivale a suma de sus deltas nuevos, incluidas entradas iniciales de fixtures.
No existe apertura de migración ni se reconstruyen movimientos viejos.

Cancelar usa salidas originales, nunca receta/almacén configurado hoy. Bloquear
orígenes y limitar suma de compensaciones a lo consumido; cancelación completa
única por origen y operación impide reposición concurrente duplicada. Costos se
congelan cuando se producen los hechos nuevos; lo desconocido no se inventa.
Responsables: inventario y venta/cancelación. Pruebas: cambiar receta/almacén
después de venta, cancelar dos veces y fallar entre compensación y actualización.

## 6. Transferencias y transiciones

Cabecera: origen/destino físicos distintos, propietarios derivados y congelados,
solicitante/emisor/receptor, estado y versión. Líneas: artículo, cantidad positiva,
unidad y costo al envío. Historial auditable de acciones con usuario, fecha y motivo.
No hay cargos financieros automáticos ni edición de líneas después del envío.

| Transición | Autorización/condición | Efecto atómico |
| --- | --- | --- |
| nueva → solicitada | Solicitar en origen o destino; acceso mínimo a contraparte | Ninguno; sin reserva de stock |
| solicitada → enviada | Enviar + inventario/editar de origen; validar saldo actual | Salida origen y tránsito |
| enviada → recibida | Recibir + inventario/editar de destino; cantidad exacta | Entrada destino; cierra tránsito |
| solicitada → cancelada/rechazada | Solicitante/contraparte correspondiente con motivo | Ninguno |
| enviada → incidencia | Destinatario autorizado, motivo/cantidad observada | Conserva tránsito; sin entrada automática |
| nueva → recibida interna | Mismo dueño; editar ambos almacenes y enviar | Salida y entrada juntas sin recepción de otro negocio |

Bloquear cabecera y líneas; verificar versión/estado y unicidad de movimientos
por línea/transición. Doble recepción es idempotente o conflicto sin duplicación.
Origen puede ofrecer y destino solicitar; solo origen envía y solo destino recibe.
Un actor con ambas membresías puede ejercer ambos papeles dejando cada acción
auditada. Global supervisa tránsito sin convertirse por ello en destinatario.
Exponer a contraparte solo cabecera/líneas necesarias, no su inventario privado.

En tránsito no hay saldo disponible en destino. Consolidado separa existencias
físicas y tránsito: la suma de ambos conserva cantidades durante envío/recepción.
Traslado interno no deja tránsito al commit. La solicitud puede fallar al enviar
porque no reserva disponibilidad. No omitir validación de negativos del origen.

Pendiente funcional: diferencias, pérdidas, recepción parcial y retorno después
de envío. Propuesta conservadora: cantidad desigual pasa a incidencia conservando
tránsito; no reponer ni ingresar automáticamente. Debe aprobarse política definitiva
antes de habilitar transferencias interámbito operativas. Responsable: servicio
de transferencias. Pruebas: doble envío/recepción concurrente, receptor ajeno,
fallo entre salida/entrada interna, saldo insuficiente y conservación de tránsito.

## 7. Negocio en tickets y hechos financieros

Todo ticket nuevo lleva unidad vendedora NOT NULL; Global nunca vende. Items y
pagos se autorizan vía su ticket. Gastos e ingresos independientes tienen ámbito
propietario explícito; gastos Global no se prorratean por defecto. Mesas/comandas
siguen siendo operación Restaurante; diseño Panadería es venta inmediata sin mesa,
con operación completa diferida. No migrar tickets/pagos de prueba anteriores.

Congelar precios/costos de operaciones nuevas en sus snapshots. No recalcular
historia nueva con precios actuales ni inventar costos ausentes. Consultas agregan
items/pagos por separado antes de unir para evitar duplicaciones. Transferencias
no son ingresos/egresos financieros. Reportes separan negocios y permiten conjunto
consolidado solo autorizado. Fixtures identificados/desechables no entran en
reportes reales; una consulta ilustrativa declara explícitamente su dataset.
Responsables: tickets/finanzas/analíticas. Pruebas: ticket sin negocio, Global
vendedor, pago ajeno, pagos múltiples, snapshots y consolidado sin doble conteo.

## 8. Producto, oferta y receta

`productos` conserva identidad comercial, nombre/descripción/imagen compartibles;
`ofertas_producto` tiene producto, negocio, categoría, precio, disponibilidad y
área operativa. UNIQUE(producto_id, unidad_id); categorías pertenecen al negocio
y expresan carta/orden/imagen/estado. Definir campos sin copias editables del mismo
valor; la importación proyecta exactamente la ficha preservada de Restaurante.
No necesita conservar la forma vieja de `productos` ni su tabla paralela `menu`.

Recetas y subrecetas tienen unidad/versión; composición se vincula a la oferta.
Variantes pertenecen a la oferta; compartir plantilla exige referencia explícita
versionada sin modificar la receta publicada de otro negocio. Se generan nuevas
recetas/variantes de prueba, no se importan las anteriores. Producto sin receta
explícitamente permitida sigue vendiéndose sin consumo. Una receta inventariable
incompleta no se publica ni se sustituye por consumo silencioso.

Origen de consumo administrable por oferta/artículo/almacén; primera versión
elige un origen por artículo/oferta. Siempre del negocio vendedor; no repartir
automáticamente entre almacenes ni usar negocio ajeno por falta de saldo.
Ticket item persiste oferta/producto, nombre/precio/variantes/costo conocido y
versión de receta; movimientos persistidos guardan cantidades/almacenes reales.
Ventas se resuelven por ID, no por nombre ni mapas de tickets descartados.
Responsables: menú/recetas y POS. Pruebas: mismo capuchino con precio/receta
distintos, edición aislada, variante ajena y consumo de origen propio.

## 9. Filtros locales y globales

Repositorios reciben `AlcanceAutorizado` generado en servidor con actor, contexto,
módulo y unidadesPermitidas. Local impone unidad; Global consulta solo conjunto
validado o subconjunto solicitado. Filtro vacío no significa todas las unidades.
SQL parametrizado aplica pertenencia también en detalle, búsqueda, conteo,
exportación, gráficos, impresión y herramientas de asistentes locales existentes.
Items/pagos se unen al ticket; existencias al almacén propietario. Identidad
maestra puede compartirse sin revelar existencias/recetas ajenas.

Cachés incluyen ámbito y autorización vigente; revocar invalida alcance. Una
sesión Panadería no convierte integraciones públicas Restaurante en Panadería.
No habilitar SQL libre como autorización ni implementar aún IA Global.
Responsables: servicios de consulta y herramientas por módulo. Pruebas: IDs
ajenos manipulados, filtros ausentes, exports y cachés cruzadas, revocación.

## 10. Estrategia de datos

Solo exportación/importación de menú según [procedimiento](procedimiento-menu.md).
Respaldo completo anterior y paquete verificado preceden cualquier reconstrucción.
Usar esquema definitivo vacío, seed estructural, importación de menú Restaurante
y fixtures nuevos separados. Sin corte de saldos, membresías heredadas, enlace de
movimientos previos ni conciliación financiera antigua. Ensayos en BD desechable.
Responsables: base/importador. Pruebas: creación limpia, restauración del respaldo,
correspondencias de IDs, igualdad de campos y hashes, repetición sin duplicación.

## 11. Compatibilidad funcional

Actualizar DDL/deploy/development y todos los escritores/lectores del esquema
en conjunto: controllers/modelos, servicios, Carta/PDF, POS, KDS/impresión,
reservaciones, finanzas/analíticas y herramientas locales. No mantener dos
esquemas operativos ni doble escritura. No activar la nueva BD hasta que esos
consumidores y sus pruebas funcionen. Los contratos públicos vigentes y la UI
se preservan o actualizan coordinadamente por necesidad funcional, no para
conservar datos antiguos. Mantener componentes compartidos, selector único y
aislamiento en servidor. Responsables: cada módulo, coordinados por este contrato.
Pruebas: regresión de flujos completos con fixtures nuevos; revisión en navegador
de los cambios visibles. No eliminar pruebas funcionales para acomodar el DDL.

## 12. Errores y resultados

Interfaz nueva común; actualizar consumidores conjuntamente. Respuesta incluye
`ok`, `codigo`, `mensaje`, `datos`, `errores` por campo y `request_id` de log.
En éxito datos contiene operación/movimientos/saldos con decimales como cadena.
En fallo datos es NULL; no revelar SQL, secretos ni saldos/propietarios ajenos.

| HTTP | Código estable | Caso |
| --- | --- | --- |
| 400 | SOLICITUD_INVALIDA | Estructura/tipos inválidos |
| 401 | NO_AUTORIZADO | Sin sesión válida/cuenta activa |
| 403 | PERMISO_DENEGADO / AMBITO_DENEGADO | Módulo u operación no autorizados |
| 404 | RECURSO_NO_ENCONTRADO | Ausente o ajeno en acceso por recurso; mismo mensaje |
| 409 | STOCK_INSUFICIENTE / ESTADO_INVALIDO / VERSION_CONFLICTO / IDEMPOTENCIA_CONFLICTO | Conflicto de estado o concurrencia |
| 422 | DATOS_INVALIDOS / CONFIGURACION_INCOMPLETA | Campos o receta/origen incompletos |
| 503 | SERVICIO_NO_DISPONIBLE | No se pudo verificar BD/autorización |
| 500 | ERROR_INTERNO | Fallo inesperado correlacionado en log |

No retornar éxito antes de commit. HTML reutiliza alertas/campos válidos; JS
no interpreta un error como venta exitosa ni reintenta sin idempotencia.
Responsables: servicios/controladores y clientes. Pruebas: fallos inyectados,
doble envío, contrato de respuesta y ausencia de información ajena en errores.

## Aprobación y pruebas

Las decisiones anteriores son propuestas explícitas para revisión, no aprobadas
por existir en el repositorio. El [plan](plan-maestro.md) fija las puertas de
aceptación y etapas. Casos descritos aquí son pruebas requeridas futuras, no
resultados obtenidos ni módulos ya implementados. El fallo PHP previo de entrega
POS sigue abierto; no justifica borrar pruebas ni conservar registros descartables.
