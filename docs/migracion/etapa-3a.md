# Etapa 3A — Esquema definitivo e importador aislado

Fecha: 2026-10-24. Rama `codex/esquema-multinegocio-etapa-3a`, creada desde
`0ed55ab` (Etapa 2), sin push/merge/stashes ni cambios en main. El commit exclusivo
de cierre se identifica con git log -1 en esta rama. package-lock.json mantiene
su modificación anterior y no forma parte de esta entrega.

## Alcance implementado

- [Modelo y diagrama revisados](modelo-relacional-3a.md) antes de delegar DDL,
  importador y pruebas a responsables con archivos separados y contrato compartido.
- `database/ddl.sql`: 59 tablas y 12 triggers del contrato multinegocio-3a-v1.
- `database/deploy.sql`: unidades Global/Restaurante/Panadería, roles existentes,
  permisos de piso, facultades, cartas y configuración estructural. Sin usuarios,
  credenciales, stock, productos ni categorías/áreas de muestra.
- `database/development.sql`: retira la carga incompatible del esquema descartado;
  los nuevos fixtures de integridad los crea el runner en su base desechable.
- `scripts/menu/ImportadorMenu.php`: importación del paquete real sobre el DDL
  definitivo, transacción, archivos, correspondencias, proyección persistida y
  repetición sin duplicar. Bootstrap de preservación incorpora la clase.
- `scripts/multinegocio/SqlArchivo.php`: ejecutor con DELIMITER/comillas/comentarios
  y guardas de destino, empleado por el runner, nunca contra DB_NAME.
- `scripts/tests/run-multinegocio-3a.php`: reconstrucción/importación en dos bases
  nuevas por ejecución, casos de integridad y limpieza comprobada.

Se conserva una sola autenticación: columnas de contraseña/NIP/HMAC y roles/
permisos existentes. La resolución de membresías en Auth todavía no se adapta.
La BD configurada no se ha seleccionado para pruebas, reconstruido ni sustituido.
Los módulos operativos, vistas y frontend no se modificaron en esta subetapa.

## Tablas y restricciones principales

| Grupo | Entidades y responsabilidad |
| --- | --- |
| Organización | unidades, roles, rol_permisos, usuarios, usuario_unidades, facultades y usuario_unidad_facultades; rol local no cruza unidad y facultad global no puede asignarse localmente |
| Almacén y artículos | almacenes con dueño obligatorio, ingredientes como identidad compartida y almacen_articulos como saldo/mínimo/versión únicos por almacén/artículo |
| Operaciones de inventario | operaciones_inventario para actor/origen/referencia/idempotencia y movimientos_inventario para deltas firmados, costos y referencias de venta/compensación/transferencia |
| Comercial | productos maestros, cartas/categorias/areas por negocio y ofertas_producto con precio/estado/orden; FK compuestas impiden mezclar categoría/área de otro negocio |
| Preparación | recetas versionadas, receta_componentes, subrecetas/subreceta_ingredientes, producto_variantes/variante_ajustes; referencias explícitas con FK y origen de consumo en oferta_consumos |
| Venta y finanzas | tickets de unidad vendedora, ticket_items con oferta/producto/receta y snapshots, pagos/mesas vinculados por unidad, operaciones_financieras y gastos_fijos con propietario |
| Transferencias | cabecera, líneas y historial; dueños de origen/destino comprobados por FK; cantidades solicitadas/enviadas/recibidas/retornadas acotadas, tránsito derivado |
| Preservación | esquema_versiones y registro importaciones_menu con huella de paquete, conteos, correspondencias y raíz aislada de imágenes |

Se conservaron las tablas y atributos funcionales de mesas, reservas,
verificaciones, reubicaciones, recordatorios, feedback, horarios/impactos,
impresión/alertas, proveedores, configuraciones, buzón y reportes. Su acceso por
unidad requiere adaptar consumidores; ver [mapa de dependencias](dependencias-3b.md).

CHECK/FK y triggers rechazan Global vendedor, rol local ajeno, facultades locales
solo_global, saldo negativo no habilitado, desactivación de negativos con saldo
pendiente, recetas/consumos/items/pagos cruzados y cantidades inválidas.
La bandera permitir_negativos es booleana. El NIP de roles de sistema se protege
al cambiar cuentas/membresías/tipo de rol; hash y lookup deben existir juntos.
El acceso NIP de roles personalizados con módulos de panel debe seguir pasando
por Permisos/Auth en la adaptación posterior; el DDL no sustituye esa evaluación.

No hay stock editable en ingredientes, almacén ficticio consolidado, tabla menu
paralela, enlace de venta por nombre ni ref_id polimórfico de componentes sin FK.
Los movimientos no se borran por CASCADE de catálogo; las referencias restrictivas
protegen hechos nuevos. El servicio futuro debe aplicar inmutabilidad, autorización,
límites de reversión y transiciones válidas: no se ejecutan esos procesos en 3A.

## Importación real y resultados

Paquete de entrada: Etapa 2, preservacion-menu-etapa2-02/paquete, fuera del
repositorio y de htdocs. Se verificó el manifiesto conocido:
`2dd893a9e13d9267d5d02f571df173ec9148db84659e214e338de68566db2287`.
SHA256 de menu.json:
`49051f6ef17446efef69862a235e4515c5dc7d8b010dad43694cf249402a7bd0`.
No se sobrescribió ese paquete ni sus archivos originales.

| Resultado en cada destino vacío | Cantidad |
| --- | --- |
| Identidades de producto importadas | 112 |
| Ofertas exclusivamente Restaurante | 112 |
| Categorías / cartas de Restaurante | 15 / 3 |
| Áreas de producción | 4 |
| Imágenes verificadas por bytes/SHA256/MIME | 13 |
| Ofertas del menú importado en Panadería | 0 |

Los IDs originales de producto/categoría/área se conservaron, sin fusionar por
nombre. El registro contiene producto origen → oferta destino y mapas de las
demás identidades/rutas. Orden explícito, nombres, descripciones, precio exacto,
carta, disponibilidad y NULL/vacío coinciden campo por campo con el paquete.
La proyección se consulta desde las tablas persistidas y sus ordinales reales.

El mismo paquete devuelve YA_IMPORTADO sin cambios. Antes de responder se vuelven
a comprobar datos, correspondencias e imágenes reales del destino. Una alteración
de precio, orden o bytes de foto se rechaza; un paquete diferente no sobrescribe
un catálogo ya importado. Un fallo SQL tras empezar las inserciones revierte todo
el catálogo y su registro. Una carpeta de staging puede quedar tras un fallo:
no equivale a importación confirmada, no se activa y el runner la limpia.

El importador exige esquema_versiones correcto y base
cp_multinegocio_test_<20hex> con token propio. No admite una transacción ajena
abierta ni acepta DB_NAME por omisión. Esta limitación de ejecución es deliberada
para 3A; la misma lógica de formato/SQL usa el esquema definitivo, no uno provisional.
La activación futura deberá definir su guardia de destino explícita en otra entrega.

## Ejecución reproducible

Desde el repositorio, reemplazar PAQUETE_ABSOLUTO por la carpeta privada de Etapa 2:

```powershell
npm run test:multinegocio:3a -- --host 127.0.0.1 --base casa-pestalozzi --entorno development --paquete PAQUETE_ABSOLUTO
```

Host/base/entorno sirven para confirmar la configuración antes de conectar al
servidor sin esquema; no seleccionan la base original. Cada destino se genera
aleatoriamente, recibe marca y token de propiedad antes de cargar DDL/seed.
Solo se elimina un destino creado en esa llamada y cuya marca coincida.
El runner falla si no puede verificar o limpiar algún recurso.

API: `MenuPreservacion\ImportadorMenu::importar($db,$paquete,$raizNueva,$token)` y
`::proyectar($db,$paquete)`. Las imágenes se escriben en una raíz nueva fuera del
repositorio/paquete. No hay ruta web ni instalación en la aplicación actual.
El comparador de Etapa 2 sigue disponible para contrastar una proyección v1.

## Evidencia de validación

- Matriz 3A: 114 comprobaciones correctas en dos bases creadas desde cero,
  importadas y eliminadas tras confirmar propiedad. Servidor validado MySQL 9.7.1.
- Pruebas negativas: FK/unicidad/precisión, membresías y NIP, facultades Global,
  propiedad de almacén, negativos/mínimos, receta/variante/consumo cruzados,
  tickets Global/items/pagos/mesas, referencias de idempotencia, deltas cero o de
  signo inválido y cantidades de transferencia inconsistentes.
- Importador: fallo SQL parcial sin filas confirmadas, equivalencia completa,
  duplicados/reintentos, precio/orden persistidos y bytes alterados, dos destinos
  reproducibles, ausencia de ofertas Panadería y transacción del llamador intacta.
- Preservación v1: 34 aserciones correctas, sin modificar su formato ni su paquete.
- PHP lint de archivos nuevos/modificados y git diff --check correctos.
- La suite PHP general alcanza el fallo previo de entrega del POS en
  run-reservaciones-cierre-static.php. Se actualizaron únicamente contratos
  estáticos de esquema de temporada/roles/NIP y espacios de configuración:
  mantienen pruebas funcionales; ya no exigen ENUM/usuario único/seed descartado.
- La suite JS completa pasó sobre código operativo sin modificaciones, incluidos
  130 enlaces locales y los contratos del POS.

El runner elimina los destinos y las copias de prueba; los respaldos privados
originales de Etapa 2 permanecen en su ubicación documentada. No se presentan
pruebas de navegador contra el esquema nuevo: la UI aún consume el esquema antiguo.

## Riesgos restantes y cierre

1. No activar este DDL con los servicios actuales. El mapa de dependencias cubre
   cada grupo que aún consulta usuarios.rol, producto.precio, ingredientes.stock
   u otros contratos retirados. La pareja código/esquema se activará junta.
2. Los seeds no proporcionan cuentas/credenciales, impresoras ni un almacén
   operativo de Restaurante. Prepararlos expresamente en la fase de adaptación;
   conservar su política de negativos y probar login/KDS/impresión reales.
3. Los CHECK/FK no prueban permisos de un usuario autenticado, atomicidad entre
   documento/saldo/bitácora, carreras, límite de compensaciones ni estados de
   transferencias. Los servicios se implementarán después, sin doble escritura.
4. Diferencias de recepción/retorno y devolución de venta cobrada siguen pendientes
   funcionales. Sus campos no habilitan operaciones ni cargos financieros.
5. El fallo previo de POS y los runners ligados al esquema descartado requieren
   resolución/fixtures nuevos antes de aceptación operativa. No se borraron
   pruebas ni se alteró el POS para ocultar el fallo.

La subetapa entrega estructura e importación aisladas. No inicia 3B, no reconstruye
desarrollo ni certifica servicios aún no adaptados.
