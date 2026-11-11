# Casa Pestalozzi — Arquitectura de informes documentales PDF

**Estado:** VALIDADO localmente para los cinco informes de Restaurante. Resultados y límites en [Pruebas](desarrollo/pruebas.md#informes-pdf-validación-local).
**Destino en el repositorio:** `docs/arquitectura-informes-pdf.md`
**Alcance de la primera entrega:** cinco informes de **Restaurante**: Finanzas, Análisis de datos, Reservaciones, Inventario y Tickets.
**Extensión prevista:** Panadería y Global, **sin habilitarlos** en esta entrega.
**Referencia histórica de preflight:** `main` en `972d5df8972c22e658cb487ee9b8dec8022cad3e` (6 de noviembre de 2026). La implementación posterior se realizó sobre la rama de trabajo de informes.

> **Autoridad documental.** Este archivo es la **fuente de verdad específica para los informes PDF**: alcance, métricas, seguridad, contratos, UX y aceptación. Está subordinado a `docs/arquitectura.md`, única norma general de arquitectura y seguridad, y a las reglas funcionales vigentes de cada módulo. Si hubiera contradicción, no inventar compatibilidad: detener el cambio relevante, contrastar código y norma general, y reconciliar ambos documentos. No convertir este documento en una segunda fuente normativa para otros módulos.

## 1. Decisión arquitectónica

Implementar **una función de exportación documental en módulos existentes**, no un nuevo módulo de navegación llamado «Reportes». Habrá **un solo renderizador PDF compartido** y preparación de datos propia de cada dominio. El PDF informa sobre datos del sistema; no crea ventas, movimientos, cambios de estado ni hechos nuevos.

**Diagrama de responsabilidades**

```text
Restaurante: Finanzas / Análisis / Reservaciones / Inventario / Tickets
   └─ Acción «Generar informe» (mismo periodo visible)
       └─ Ruta GET específica del módulo
           ├─ autenticación, contexto y permiso de lectura del módulo
           ├─ periodo validado estrictamente
           ├─ servicio(s) del dominio: datos y reglas vigentes
           └─ documento normalizado (indicadores, secciones, notas)
               └─ Services\Shared\GeneradorPdf
                   └─ plantilla HTML institucional → Dompdf → PDF HTTP

Panadería / Global: misma ruta conceptual + mismo renderizador,
pero con permiso, alcance y cálculo propios; NO activos en primera entrega.
```

**Dos responsabilidades, sin capas adicionales:**

1. **Módulo (fuente de información):** obtiene datos, define métricas, selecciona contexto, aplica autorización y arma contenido documental. Si falta un agregado, añadir un método de lectura pequeño en su servicio de dominio; no introducir consultas de negocio en vistas ni en el generador PDF.
2. **`GeneradorPdf` (presentación):** recibe el documento ya preparado, lo valida estructuralmente, compone HTML seguro y lo transmite con Dompdf. No conoce la estructura SQL, no decide qué significa una cancelación y no obtiene datos por sí mismo.

**No implementar:** microframework de reportes, adaptadores abstractos por negocio, interfaz universal de proveedores, registro en BD, tabla de PDFs, scheduler, histórico de archivos, envío por email/WhatsApp, IA narrativa, constructor visual, exportación Excel, módulo lateral adicional ni bibliotecas nuevas.

## 2. Alcance y matriz de activación

| Informe lógico  | Restaurante (entrega 1)     | Panadería (futuro)                           | Global (futuro)                                     |
| --------------- | --------------------------- | -------------------------------------------- | --------------------------------------------------- |
| `finanzas`      | **Implementar y habilitar** | Reservar integración con finanzas locales    | Reservar informe consolidado, no suma de documentos |
| `analytics`     | **Implementar y habilitar** | Reservar indicadores específicos de caja     | Reservar comparativos entre negocios                |
| `reservaciones` | **Implementar y habilitar** | No aplica actualmente                        | No aplica actualmente sin contrato explícito        |
| `inventario`    | **Implementar y habilitar** | Reservar inventario local                    | Reservar consolidación por unidades/almacenes       |
| `tickets`       | **Implementar y habilitar** | Reservar tickets de caja con contrato propio | No habilitar sin un contrato de reporte Global      |

«Reservar» **no** implica crear rutas funcionales, acceso efectivo, permisos ficticios, servicios vacíos ni botones engañosos. Significa que la plantilla y `GeneradorPdf` son neutrales al contexto y que la integración futura no exige reescribirlos. La activación es explícita por **ruta existente + autorización + datos compatibles + pruebas**, no por detectar tablas.

La primera entrega **no modifica** los módulos de Panadería/Global salvo un cambio estrictamente necesario y demostrado en componentes compartidos. No crear una bandera persistente `habilitado` ni una matriz en BD. Por ahora, la presencia de una ruta implementada y testeada determina la disponibilidad.

## 3. Estado real de los componentes reutilizables

Inspección de la referencia anterior; revalidar en preflight:

| Componente vigente                                             | Uso permitido                                                                                                                                           |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer.json` (`dompdf/dompdf:^3.1`)                         | Renderizar HTML en PDF sin nueva dependencia                                                                                                            |
| `services/Menu/MenuPdf.php`                                    | Referencia de configuración segura de Dompdf, fuentes, márgenes y folios; **no usarlo como generador de informes**, pues pertenece a cartas comerciales |
| `services/Shared/RangoPeriodo.php`                             | Ventanas retrospectivas, rango libre y comparativo anterior; **complementar con validación estricta de entrada para exportación**                       |
| `services/Finance/FinanzasNegocio.php`                         | Agregados y `resumen()` financieros por negocio; distinguir métricas históricas de foto actual                                                          |
| `services/Finance/ConsolidadoFinanciero.php`                   | Contrato futuro de Global                                                                                                                               |
| `services/Analytics/AnalisisNegocio.php`, `Analiticas.php`     | Desempeño y diagnóstico comercial local                                                                                                                 |
| `services/Analytics/AnalisisConsolidado.php`                   | Contrato futuro de Global                                                                                                                               |
| `models/Reservacion.php`, `services/Reservations/*`            | Hechos y estados operativos; crear lectura agregada **sin efectos colaterales**                                                                         |
| `services/Inventory/ConsultasAlmacen.php`, `Almacenes.php`     | Existencias, almacenes y movimientos con alcance de unidad                                                                                              |
| `services/Pos/HistorialTickets.php`                            | Conteos, ventas, tendencias y tickets por periodo; **no exportar clientes ni paginación como resumen del periodo**                                      |
| `services/Security/ContextoNegocio.php`, `PermisosUsuario.php` | Contexto y autorización reales de usuario, no confiados al query string                                                                                 |
| `public/index.php`, `services/Security/Permisos.php`           | Rutas y clasificación explícitas de permisos                                                                                                            |
| `services/Configuration/ReporteSistemaService.php`             | **No reutilizar**: gestiona incidentes/problemas, no informes de gestión                                                                                |

**Nota sobre diferencias de dominios:** no asumir que los KPIs de Finanzas, Analíticas y Tickets son matemáticamente idénticos sin verificar filtros de `ticket_items`, `estado`, fecha efectiva, propinas y anulaciones. Cuando se desee mostrar el mismo indicador, reutilizar una autoridad de cálculo o demostrar equivalencia mediante pruebas; documentar expresamente cualquier definición distinta.

## 4. Contrato mínimo de solicitud y generación

### 4.1 Entrada HTTP

- Una **ruta `GET` autenticada por informe** dentro de la navegación administrativa del módulo. Sin generación por `POST` salvo una necesidad técnica probada; generar no modifica datos.
- Usar el **contexto resuelto en servidor**, no aceptar `unidad_id`, `negocio` ni `slug` arbitrarios de GET para cambiar propietario de datos.
- Admitir `desde` y `hasta` en `YYYY-MM-DD` y opcionalmente un preset vigente (`rango`) **solo si se valida**. El botón preserva el periodo realmente seleccionado en pantalla. No heredar filtros irrelevantes, parámetros privados ni campos de búsqueda libre por defecto.
- Para **Reservaciones**, el botón traduce `fecha_inicio`/`fecha_fin` visibles a los parámetros canónicos `desde`/`hasta` del PDF, validados independientemente y resueltos como `start`/`end`; el endpoint rechaza `fecha_inicio`/`fecha_fin` y no invoca el controlador de Agenda para obtener el rango.
- **Validación estricta:** fecha real (`checkdate`), orden inicio ≤ fin, límites de fechas y máximo inicial de **366 días naturales** (ajustable tras perfilado). Un rango libre inválido responde con error controlado, **no** cambia silenciosamente a «últimos 30 días». Rango por defecto cuando se omiten parámetros: 30 días retrospectivos, salvo Reservaciones, para las que debe mostrarse explícitamente la política temporal descrita en §8.3.
- Resolver cortes de días en la **zona horaria del negocio**. Preferir intervalo SQL `[inicio 00:00:00, día siguiente al fin 00:00:00)` y fechas parametrizadas; no cambiar el periodo de los tableros sin prueba. En métricas existentes que usen otro límite, reconciliar o documentar la diferencia antes de declarar paridad.

### 4.2 Contrato de datos hacia el renderizador

Un **array sencillo**, sin DTOs, interfaces ni objetos extra. Forma lógica (nombres definitivos pueden ajustarse de manera mínima antes de implementar, siempre preservando esta semántica):

```php
$documento = [
    'titulo'     => 'Informe de Finanzas',
    'contexto'   => 'restaurante',          // ya resuelto/autorizado
    'periodo'    => ['inicio' => '2026-10-01', 'fin' => '2026-10-31',
                     'comparacion' => ['inicio' => '2026-09-01', 'fin' => '2026-09-30']],
    'generadoEn' => '2026-11-06 18:00:00',  // reloj oficial local
    'corteDatos' => '2026-11-06 18:00:00',  // importante en estado actual
    'resumen'    => 'Texto basado únicamente en hechos validados.',
    'kpis'       => [
        ['clave' => 'ingresos', 'etiqueta' => 'Ingresos por ventas',
         'valor' => 0.0, 'formato' => 'moneda', 'comparacion' => null],
    ],
    'secciones' => [
        ['titulo' => 'Evolución', 'tipo' => 'tabla', 'columnas' => [], 'filas' => []],
    ],
    'notas'      => ['Definiciones, límites y exclusiones de métricas.'],
];
```

Reglas: números reales se pasan **sin formatear** (la vista aplica formato visual); porcentajes nulos significan «no comparable» y **nunca** `+∞ %`; valores ausentes ≠ cero (mostrar «No disponible» cuando corresponde); no introducir HTML arbitrario ni consultas SQL en los campos. `secciones` acepta `tabla`, `indicadores`, `texto` y, si aporta información, `serie` con datos limitados. Las series numéricas de dos columnas se presentan mediante el parcial fijo `views/admin/reportes/_serie.php`: tabla con barras horizontales HTML/CSS estáticas compatibles con Dompdf, sin bibliotecas ni imágenes remotas. No se incorporó un motor de gráficas ni SVG.

El parcial conserva etiquetas, valores exactos, orden cronológico y filas de cero. La escala visual compara magnitudes absolutas desde cero respecto al máximo del mismo cuadro; el signo escrito y una tinta diferenciada identifican valores negativos. Cero y ausencia no dibujan barras; una ausencia sigue diciendo «No disponible». La anchura se calcula como proporción antes de multiplicar por 100 y se acota a 0–100, evitando desbordamientos incluso con números finitos extremos. Las series sin formato numérico mantienen su tabla. Los totales por semana o mes no son tasas diarias: se advierte que los tramos extremos pueden cubrir menos días.

### 4.3 Salida

- A4 vertical, tipografía local del proyecto, márgenes uniformes; orientación horizontal **solo** si un reporte concreto la requiere y se prueba.
- Encabezado: Casa Pestalozzi, módulo, contexto y periodo exacto.
- Contenido: resumen ejecutivo **determinista**, 3–6 KPIs clave, hasta 2–3 secciones con tablas/series resumidas; notas sobre alcance y fuentes.
- Pie: fecha/hora de generación, folios y carácter informativo del documento. Meta inicial: **2–4 páginas**, no truncar datos para cumplir un número arbitrario de páginas.
- Respuesta `application/pdf`; `Content-Disposition: inline` inicialmente (descarga opcional vía acción explícita); nombre de archivo fijo/saneado (`informe-finanzas-restaurante-2026-10-01_2026-10-31.pdf`). `Cache-Control: private, no-store` y encabezados anti-caché apropiados.
- Generación en memoria o temporal estrictamente efímero. **No almacenar** PDF o datos personales en `public/`, BD o carpetas versionadas.
- Si la consulta no devuelve registros, emitir un documento válido con «Sin actividad en el periodo», KPIs cero únicamente donde sea correcto, y notas explícitas. Si falla una fuente imprescindible, devolver error controlado y **no PDF parcial presentado como completo**.

**Presentación institucional vigente (11 de noviembre de 2026).** La plantilla compartida utiliza verde `#214F36`, oro `#D1AB66`, beige `#E3D5BB` y crema `#F5F1E8`, derivados de `src/scss/layout/_reset.scss`: títulos y cabeceras verdes, texto crema en cabeceras, separadores oro y superficies claras imprimibles. Se conservan las fuentes TTF locales compatibles con Dompdf, Montserrat y Playfair, la repetición de cabeceras y los folios. Análisis conserva sus siete indicadores existentes para distinguir tickets con y sin consumo sin eliminar comensales ni piezas; la meta de 3–6 no justifica perder información esencial.

## 5. Seguridad y consistencia obligatorias

1. `ContextoNegocio::exigir('<modulo>', 'ver', '<contexto-resuelto>')` **en el endpoint** y antes de leer datos. El renderizador no tiene bypass. Autenticación de panel con el nivel vigente; **NIP operativo por sí solo no habilita** informes gerenciales.
2. Asegurar la clasificación exacta de cada ruta en `Permisos::clasificarDirecto()` / mecanismo vigente. Pruebas contra acceso anónimo, NIP, rol sin permiso, permiso solo en otro contexto, parámetro manipulado e ID de otro negocio. **No confiar solamente en ocultar el botón.**
3. Informes agregados por defecto, **sin nombre/contacto/notas de clientes, teléfono, datos de pago, token o información personal**. Tampoco incluir identificadores de empleados en los documentos ejecutivos.
4. Consultas de **solo lectura**; nunca llamar `procesarEnLectura()` o equivalentes que actualicen estados al abrir una página. En particular, `AdminReservacionController::index()` hoy tiene un procesamiento de ausencias al leer: **no invocarlo desde el PDF**.
5. `GeneradorPdf` mantiene `isRemoteEnabled=false`, carga únicamente assets locales desde un `chroot` restringido, escapa texto (`htmlspecialchars`) y selecciona plantillas mediante lista fija; nunca acepta plantilla/ruta/HTML arbitrario de GET.
6. No registrar SQL con valores personales ni volcar el PDF a logs. Errores internos se registran sin exposición de datos. No usar enlaces de descarga permanentes o públicos.
7. No añadir escrituras ni transacciones si no son necesarias; para informes grandes, limitar ventana/filas, agrupar en SQL o por lotes para evitar N+1 y memoria excesiva.
8. Metadatos de periodo y corte claros: un PDF es una **instantánea a la fecha de generación**, no prueba de inmutabilidad de estados que después cambien. Para evitar que un cierre concurrente haga diferir un KPI de su serie, preparar todos los datos de cada informe en una transacción breve `REPEATABLE READ`, `READ ONLY`, con instantánea consistente. La autenticación, autorización y validación del rango preceden a esa lectura; confirmar la transacción antes de invocar Dompdf. No usar `FOR UPDATE` ni `FOR SHARE` ni bloquear operaciones de caja. Rechazar conexiones con transacción externa o `autocommit` desactivado sin confirmar ni revertir trabajo ajeno. Si no se puede establecer o completar la lectura consistente, fallar sin PDF, sin fallback a lecturas independientes ni reintentos automáticos.

## 6. Interfaz y rutas

### 6.1 UI

Colocar una **única acción secundaria «Generar informe»** junto al selector de periodo de cada módulo elegible. Reutilizar el patrón de botón del panel, sin crear menú lateral, pantalla «Reportes», wizard, formulario independiente ni overlay de configuración. La acción abre el PDF en pestaña nueva y conserva el rango mostrado; accesibilidad: etiqueta explícita, navegación con teclado y aviso de que abre documento PDF.

**En esta entrega, únicamente Restaurante muestra cinco acciones operativas.** En Panadería y Global se puede reutilizar el mismo parcial/estilo ya preparado, pero no mostrar botones falsamente habilitados ni publicar endpoints hasta validar sus informes específicos. Evitar crear componentes vacíos exclusivamente para «estar listos».

### 6.2 Rutas registradas

| Informe       | Ruta GET Restaurante               | Permiso            | Fuente actual de pantalla                  |
| ------------- | ---------------------------------- | ------------------ | ------------------------------------------ |
| Finanzas      | `/admin/finanzas/informe.pdf`      | `finanzas:ver`     | `/admin/finanzas`                          |
| Análisis      | `/admin/analytics/informe.pdf`     | `analytics:ver`    | `/admin/analytics`                         |
| Reservaciones | `/admin/reservaciones/informe.pdf` | `reservations:ver` | `/admin/reservaciones`                     |
| Inventario    | `/admin/almacenes/informe.pdf`     | `inventario:ver`   | `/admin/almacenes` (interfaz multinegocio) |
| Tickets       | `/admin/tickets/informe.pdf`       | `tickets:ver`      | `/admin/tickets`                           |

No cambiar la semántica de rutas antiguas ni aceptar un slug de negocio como sustituto de autorización. Si la aplicación distingue rutas exactas de prefijos, registrar las cinco explícitamente y probar su clasificación. El patrón conceptual futuro será `/admin/panaderia/<modulo>/informe.pdf` y `/admin/global/<modulo>/informe.pdf` **solo donde ya exista módulo y permiso autorizados**; no registrar esos endpoints ahora.

Las cinco rutas anteriores están registradas para Restaurante. `/admin/inventario` no expone una ruta adicional de informe.

### 6.3 Reportes dentro del Asistente IA

«Reportes» es un **rubro del catálogo existente**, no un módulo lateral ni una pantalla nueva. `AdminIaController` añade una píldora por informe autorizado, con el mismo borrador/compositor y los mismos huecos y menús de `ConsultasRapidas.elegir`: «Generar informe de [Finanzas] · [Mes pasado]». El hueco de informe permite cambiar entre los disponibles sin ejecutar la consulta; el botón de enviar vigente abre el PDF en una nueva pestaña. Las consultas convencionales conservan su recorrido y sus respuestas.

El catálogo se filtra en servidor con el perfil efectivo recién cargado: requiere `ia:ver` y lectura del módulo del informe, en Restaurante. Una cuenta con IA sin permisos PDF no ve el rubro; una con permiso PDF pero sin IA puede usar el módulo conforme a sus guardias, pero no resolver informes mediante el asistente. Panadería y Global no reciben píldoras ni resolución de informes.

Las intenciones documentales `informe_finanzas`, `informe_analytics`, `informe_reservaciones`, `informe_inventario` e `informe_tickets` se interceptan exclusivamente en `AdminConsultasController::consultar`, sobre **GET `/admin/api/consultas`**, antes del motor convencional. `AdminIaController::resolverInforme` valida y devuelve `{ok:true,informe:{url:…}}` con una ruta PDF de la lista fija anterior. No consulta hechos de negocio ni genera el documento: la nueva pestaña solicita el endpoint PDF original, que vuelve a comprobar autenticación, permiso, contexto y rango.

Los nueve periodos predefinidos son los de `ConsultasRapidas::PERIODOS` y sus fechas se resuelven al enviar mediante `ConsultasRapidas::periodo`, con el reloj/zona horaria del servidor. Se transmiten al PDF como `desde`/`hasta`; sus comparadores documentales mantienen el periodo anterior de igual duración definido por `RangoPeriodo`, sin adoptar los comparadores particulares de las consultas convencionales.

Solo los borradores de informes añaden **Personalizado** al menú. Al elegirlo aparecen «Fecha de inicio» y «Fecha de fin» dentro del mismo compositor, sin otro formulario. Ambas son inclusivas y `RangoPeriodo::resolverInforme` exige fechas reales, ordenadas, dentro de sus límites y hasta 366 días naturales. Fechas incompletas, arrays, campos ajenos, intenciones desconocidas, rutas/negocios del cliente o fechas junto a un preset se rechazan; no hay fallback silencioso. La resolución responde 400 ante rango/entrada inválidos y 403 ante acceso denegado. `ConsultasRapidas::INTENCIONES`, `PERIODOS` y capacidades convencionales no incorporan estas opciones.

El navegador reserva la pestaña durante el gesto de enviar para evitar bloqueos del popup, elimina su `opener` y solo navega a enlaces locales de los cinco endpoints permitidos. Un error de resolución o timeout cierra la pestaña vacía, muestra el aviso existente y conserva el borrador; si el navegador bloquea el popup, informa cómo reintentar. Una revocación posterior a la resolución sigue siendo responsabilidad del endpoint PDF. No se guardan PDFs ni se crea un histórico documental en el chat.

## 7. Componente PDF compartido y archivos esperados

**Superficie nueva mínima:**

```text
services/Shared/GeneradorPdf.php              # SOLO PDF/HTML/folios/seguridad
services/Shared/LecturaConsistente.php         # transacción breve de lectura; sin SQL de negocio ni proveedores
views/admin/reportes/base.php                 # plantilla visual única
views/admin/partials/_generar-informe.php     # botón compartido, si realmente evita duplicar UI
# Sin services/Reports/* obligatorio; sin providers abstractos.

# Acciones GET pequeñas en controladores ya existentes:
controllers/AdminFinanzasController.php
controllers/AdminController.php               # analytics y tickets
controllers/AdminReservacionController.php
controllers/AdminAlmacenesController.php      # o controlador vigente de inventario

# Métodos de preparación en los servicios del dominio que lo requieran:
services/Finance/FinanzasNegocio.php
services/Analytics/{AnalisisNegocio,Analiticas}.php
services/Reservations/*                       # agregado nuevo solo-lectura y acotado
services/Inventory/{Almacenes,ConsultasAlmacen}.php
services/Pos/HistorialTickets.php
```

Se permite una segunda plantilla por informe **solo** si sus contenidos son verdaderamente diferentes y la plantilla base se mantiene compartida. No forzar todos los reportes a compartir una mega-plantilla repleta de `if`; preferir parciales internos sencillos. Mantener nombres existentes si una pequeña adaptación es suficiente. Un componente nuevo debe eliminar más duplicación de la que introduce.

**Firma conceptual:** `GeneradorPdf::emitir(array $documento, string $nombreArchivo): void`; el generador no recibe `$db`, `$_GET`, `$_SESSION`, identificadores de negocio o rutas de plantilla suministradas por el cliente. El controlador autoriza y construye el documento; el servicio compartido imprime.

## 8. Los cinco contratos de contenido de Restaurante

Los siguientes campos y reglas son **obligatorios para la aceptación**. El agente puede reducir tablas/gráficos secundarios, pero no eliminar KPI principal, exclusiones o notas sobre temporalidad. Evitar reportes que sean copias masivas de los listados.

### 8.1 FIN — Informe financiero

**Responsable:** Finanzas. **Fuente primaria:** `FinanzasNegocio::resumen()` y servicios financieros existentes. **Tiempo:** periodo retrospectivo de **cierre/cobro** de tickets conforme a la autoridad financiera, no creación de reservas.

**KPIs:** ingresos por venta (sin duplicar propina), tickets cerrados, ticket promedio, costos de insumos (`cogs`), utilidad bruta y utilidad neta; opcional margen y merma en sección secundaria si existe fuente válida. Incluir comparación de ingresos/utilidad contra periodo inmediatamente anterior cuando los datos sean comparables.

**Secciones:** balance resumido de ingresos − costos − merma − gastos fijos; comparación actual/anterior; notas metodológicas.

**Reglas críticas:**

- Gastos fijos registrados como importe **mensual sin fecha**, **prorrateados** por días según el contrato vigente; nunca presentarlos como egresos históricos efectivamente ocurridos en cada día.
- Valor del inventario, si se incluye, es **foto al generar**, no saldo histórico al `hasta` elegido; rotularlo claramente o excluirlo del PDF inicial.
- `costo_unitario` nulo ≠ costo cero conocido. Mostrar advertencia/importe de ventas sin costo cuando proceda.
- No sumar ingresos de venta por tickets y por operaciones financieras si representan el mismo cobro. Probar tickets cerrados, artículos cancelados, propinas, pago dividido y unidad ajena.
- No sustituir sin explicación un periodo por otro al calcular margen o gastos.

**Exclusión:** detalle de proveedores, tickets individuales, facturas, impuestos/conciliación no soportados por el modelo y predicciones.

### 8.2 ANA — Informe de desempeño comercial

**Responsable:** Análisis de datos. **Fuentes:** `AnalisisNegocio::{totales,ventasPorDia,masVendidos,descriptivo}` y, solo si está probado, `Analiticas` para horas/ingeniería de menú.

**KPIs:** ventas, tickets cerrados, ticket promedio, piezas vendidas y comensales asociados a tickets cerrados. Comparación de ventas contra periodo anterior (delta nulo si el denominador es cero).

**Secciones:** tendencia de ventas por día (serie acotada); hasta **7** productos más vendidos por piezas e importe; hasta una sección diagnóstica adicional (hora/categoría) si reproduce la definición vigente.

**Reglas críticas:**

- Excluir `ticket_items.estado='cancelado'`; respetar unidad y `tickets.estado='cerrado'`.
- Producto vendido usa nombre/snapshot de la partida cuando el servicio ya lo hace; no reatribuir retrospectivamente a una identidad actual diferente.
- No considerar el contador genérico de `reservaciones` del dashboard equivalente a «reservas atendidas»: el informe de Reservaciones tiene otra población y exclusiones.
- No introducir tasas de ocupación/RevPASH sin fijar explícitamente denominadores, horarios y cobertura de mesas. Si se omite, documentarlo.

### 8.3 RES — Informe de reservaciones

**Responsable:** Reservaciones. **Fuente:** agregación nueva de **solo lectura** sobre hechos existentes, reutilizando catálogos de estados vigentes (`ReservacionListadoPresenter` / `ReservacionService`) sin pasar por el controlador de Agenda.

**Periodo:** **fecha programada del servicio** (`reservaciones.fecha`), no fecha de registro (`created_at`) ni fecha en que se canceló. Incluye intervalos históricos; si incluye el día actual o futuro, etiquetar el resultado **provisional** y **suprimir tasas finales** que dependerían de visitas aún no concluidas. Si el usuario no elige rango, por defecto usar los **últimos 30 días retrospectivos** para el PDF gerencial, distinto a la Agenda operativa que muestra próximos 30 días. Indicar esa diferencia en UX/ayuda; botón con fechas expresas usa las del selector.

**Población del negocio:** estados `confirmada`, `en_curso`, `completada`, `no_show`, `cancelada`. Excluir de totales de negocio `pendiente_verificacion`, `expirada`, `reemplazada` (técnicos, incluidas versiones sustituidas). No sumar dos veces una visita reemplazada.

**KPIs:** reservas de negocio del periodo, comensales programados (no confundir con asistencias reales), `completada` (atendidas), `no_show` registrado y canceladas. Separar confirmadas/en curso **no finalizadas**. Una tasa de cancelación o ausencia, si se muestra, usa **denominador nombrado** (`total de reservas de negocio del periodo`) y solo se presenta como tasa definitiva para fechas concluidas y estados consolidados; nunca inferir automáticamente `no_show` de una reserva confirmada que ya pasó.

**Secciones:** distribución de estados, reservas por día, días de mayor demanda (conteos, no nombres de personas).

**Reglas críticas:**

- No llamar `ReservacionAusenciaAutomaticaService::procesarEnLectura` ni alterar estados/tolerancias por generar el documento.
- No incluir cliente, contacto, notas, motivos personalizados o tokens.
- Si un periodo concluyó pero todavía contiene confirmadas/en curso, informar **estados pendientes de cierre** y evitar llamar «tasa definitiva» a los resultados.
- No equiparar `comensales` reservados a clientes efectivamente atendidos; esa última métrica requiere reconciliación con tickets.
- Las consultas agregadas deben preservar filtros de unidad y una definición de estado idéntica en todas las secciones.

### 8.4 INV — Informe de inventario y movimientos

**Responsable:** Inventario/Almacenes. **Fuentes:** `ConsultasAlmacen`, `Almacenes`, `movimientos_inventario` mediante los servicios actuales. **Tiene dos dimensiones temporales diferentes:**

- **Existencias, mínimos y alertas:** **fotografía al momento de generar el PDF**. Nunca decir «existencias al 31 de octubre» si se consulta el saldo actual.
- **Entradas, salidas, ventas, mermas, cancelaciones y ajustes:** **movimientos registrados en el periodo** según `fecha`, tipología y unidad de cada almacén.

El informe cubre todos los almacenes de Restaurante, sin aplicar selección de almacén, búsqueda ni filtros de artículo o tipo de la pantalla. La fotografía incluye únicamente almacenes, asociaciones y artículos activos; los movimientos incluyen también el historial de almacenes actualmente inactivos.

**KPIs:** total de artículos/relaciones físicas activas (etiquetar correctamente), artículos bajo mínimo, almacenes activos y recuento de movimientos; importe de merma del periodo solo con costo conocido y regla explícita. Valor actual del inventario, si se añade, indicar método de valoración y desconocidos.

**Secciones:** resumen por tipo de movimiento; hasta **10** alertas relevantes; desglose por almacén sin sumar unidades incompatibles (`kg` + `pza` nunca como «total de stock»).

**Reglas críticas:**

- Stock real reside en asociaciones físicas `almacen_articulos`, no en una tabla global virtual. No duplicar saldo al recorrer almacenes ni contar múltiples veces el mismo artículo como identidad única.
- Respetar el dueño de cada almacén y asociaciones activas; saldos negativos y productos sin costo no se convierten silenciosamente en cero.
- Si la consulta actual devuelve páginas, **no** generar un resumen solo con la primera página: consulta agregada específica o paginación completa acotada.
- No reconstruir saldos históricos sumando movimientos de manera informal; hacerlo requiere un contrato separado y pruebas.

### 8.5 TIC — Informe de tickets y operación de cobro

**Responsable:** Tickets. **Fuente:** `Services\Pos\HistorialTickets` y hechos de tickets, pagos y partidas cuando el agregado existente lo requiera.

**KPIs:** tickets **cerrados** del periodo, tickets **cancelados**, ventas netas de partidas no canceladas en tickets cerrados, ticket promedio de tickets cerrados y tendencia diaria. Indicar explícitamente si las propinas están fuera del importe de venta.

**Secciones:** cerrados vs cancelados, ventas por día, opcional medios de pago si están completos y conciliados. No incluir el listado de tickets con nombre de comensales.

**Reglas críticas:**

- El historial utiliza `COALESCE(hora_cierre, hora_apertura)` para un periodo en algunas métricas. Confirmar consistencia con Finanzas antes de llamar a ambos importes «ventas», y conservar sus decisiones de exclusión.
- `HistorialTickets::resumen()['abierto']` es una **foto de tickets abiertos ahora**, no un recuento histórico del periodo. **Excluir del reporte retrospectivo** o presentarlo como indicador independiente rotulado «Abiertos al generar».
- La tasa de cancelación, si se muestra, debe usar un denominador **declarado**, por ejemplo `cerrados + cancelados`, nunca dividir por tickets que incluyen abiertos del momento sin explicarlo.
- Cancelación de ticket ≠ cancelación de ítem. No duplicar devoluciones ni ignorar `ticket_items.estado='cancelado'`.
- No incluir el detalle de clientes, mesas identificables, personal, métodos de pago sensibles o referencias privadas.

## 9. Reglas documentales comunes

### Decisiones de síntesis por módulo

| Informe       | Información ejecutiva y elección visual                                                                                                                                                                                                                                                                      |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Finanzas      | Resume ingresos, utilidad neta, variación y costos desconocidos; avisa si los egresos definidos superan ingresos. Balance y comparación siguen tabulares: permiten leer gastos prorrateados y resultados negativos sin imponer un Sankey. No cambia la definición de utilidad bruta ni el prorrateo vigente. |
| Análisis      | Añade variación de ventas al resumen y mantiene el producto líder por piezas. Barras temporales permiten localizar concentración o tramos sin venta, junto a todos los valores exactos; el top de productos sigue tabular para distinguir piezas e importe.                                                  |
| Reservaciones | Añade fecha y cantidad de mayor demanda programada, conservando provisionalidad y pendientes sin inferir ausencias. Barras temporales muestran concentración de reservas; estados y días de mayor demanda permanecen como tablas.                                                                            |
| Inventario    | Aclara desde el resumen si las diez alertas son una muestra y que describen el estado actual. Mantiene tablas de movimientos, almacenes y alertas: no suma unidades incompatibles ni convierte existencias actuales en saldos históricos.                                                                    |
| Tickets       | Añade variación de ventas y distingue cancelaciones de tickets completos frente a partidas. Barras de ventas muestran actividad del periodo; estados y comparación mantienen cifras exactas. Los abiertos actuales siguen fuera.                                                                             |

Los textos son deterministas y usan agregados existentes; no se añadieron consultas, métricas de negocio, predicciones ni recomendaciones generadas por IA.

- **Resumen ejecutivo:** 2–4 oraciones deterministas a partir de métricas verificadas. Ej.: «Las ventas aumentaron X% respecto al periodo anterior» **solo si** existe comparador positivo, periodo equivalente y resultado calculado. Nada de IA generativa ni recomendaciones inventadas.
- **Comparativas:** mismo número de días o una base explícita comparable. `null` se representa como «Sin base comparable», no `0 %`.
- **Presentación de números:** moneda MXN con dos decimales cuando sea relevante, unidades enteras/milésimas según el dominio, porcentajes con precisión coherente. Cálculo decimal sensible en el servicio, nunca en CSS/HTML.
- **Periodo vacío:** distinguir no hubo operaciones (cero válido) de fuente ausente/fallo (error o N/D).
- **Series:** orden cronológico, incluir días sin movimientos si la definición es «por día». Top N sin sesgo por carga parcial o paginación.
- **Interpretación:** una observación debe derivarse de un dato y su contexto. No presentar «recomendación estratégica» automática sin reglas justificadas.
- **Responsable del reporte:** usar solo el ámbito de negocio, nunca identidad personal del empleado. No emitir nombres/IDs de usuarios como firma o dato implícito.

## 10. Escalabilidad de Panadería y Global, sin implementación prematura

### 10.1 Panadería

El generador visual, contrato de entrada, seguridad de PDF y periodos se reutilizan **sin modificación**. La futura acción de Panadería usará la autenticación y los permisos locales y la misma plantilla por tipo, pero **los indicadores deben provenir de caja y almacenes propios**, sin heredar conceptos de salón (mesas, comensales de tickets, RevPASH, no-shows). La caja de Panadería ya existe; eso **no** autoriza publicar reportes sin verificación por módulo.

Para habilitar un informe futuro bastará, en principio, crear su ruta autorizada, adaptar/seleccionar los agregados locales, comprobar paridad y exponer su botón. **No hacer esa habilitación en esta entrega.**

### 10.2 Global

Global no vende ni tiene tickets comerciales propios. Un informe Global **no concatena PDFs locales**: debe usar `ConsolidadoFinanciero`, `AnalisisConsolidado` o un contrato específico de Inventario, con permiso del módulo en Global.

Sumar solo magnitudes aditivas compatibles; **recalcular** porcentajes y promedios a partir de sus denominadores globales. No mezclar productos por coincidencia de nombre ni saldos de almacenes que representen la misma existencia. Indicar por separado lo que solo existe en un negocio. No habilitar Reservaciones ni Tickets Global por inferencia; requieren decisión funcional independiente.

### 10.3 Prueba de extensibilidad

La entrega Restaurante pasa esta prueba **sin implementar Panadería/Global**: los métodos de PDF no incluyen nombres fijos, logo comercial local, acceso SQL específico ni `if ($contexto==='restaurante')` en el renderizador; el botón y la plantilla aceptan etiquetas/contexto mediante datos ya preparados. Las rutas locales futuras y permisos quedan documentados; ninguna ruta futura responde con PDF hasta estar aprobada.

## 11. Pruebas de aceptación y criterios de no-regresión

### 11.1 Obligatorio por cada uno de los cinco informes

1. Usuario Restaurante autorizado genera PDF válido (`%PDF-`) con título, periodo, contexto y folios; enlace de UI conserva exactamente los filtros de periodo.
2. Sin permiso o con NIP sin acceso al módulo: rechazo HTTP seguro, **no bytes PDF** ni filtración de datos; mismo resultado si intenta parámetros de otra unidad.
3. Comparación del documento contra el servicio del módulo y fixture controlada: cifras iguales en periodos normales, vacíos y con cancelaciones/anulaciones.
4. Rango libre válido y borde de día; fecha imposible, rango invertido o mayor de 366 días: error controlado, sin fallback engañoso.
5. Rango sin actividad: PDF correcto y honesto. Error SQL/módulo no disponible: error, **no informe incompleto**.
6. Acentos y caracteres especiales (incluyendo `<`, `&`, HTML hostil) no rompen diseño ni ejecutan HTML; sin imágenes remotas ni archivos privados accesibles.
7. PDF imprimible A4, claro y legible, sin columnas cortadas, tablas que se montan sobre el pie, páginas vacías inesperadas o fuente inexistente. Revisar casos de varias páginas y móvil solo para el botón (el PDF no es responsive).
8. Tiempo/memoria acotados con dataset representativo. Prueba de 366 días y múltiples almacenes; no consultar toda la historia ni cargar detalle ilimitado.
9. Emisión **sin mutar BD**. Comparar estados/saldos/históricos antes y después, especialmente Reservaciones e Inventario.
10. Prueba de aislamiento: usuarios con permiso en otro negocio o Global no obtienen información local del informe. No se habilitan routes de Panadería/Global.

### 11.2 Regresiones existentes

Antes de ejecutar suites SQL, verificar que **crean su propia BD de pruebas, identifican propiedad y jamás destruyen la configurada**. Reutilizar pruebas representativas de `npm run test:php`, `npm run test:js`, pruebas de A4/M2, Finanzas, Analíticas, Almacenes, Reservaciones y Tickets. Registrar `PASS / FAIL_NUEVO / FAIL_PREEXISTENTE / BLOQUEADO_ENTORNO / NO_EJECUTADO`; no inventar aprobaciones ni suponer que el entorno reproduce un despliegue real.

**Pruebas específicas mínimas:**

| Informe | Casos críticos                                                                                                     |
| ------- | ------------------------------------------------------------------------------------------------------------------ |
| FIN     | propinas, partidas canceladas, costo nulo/cero, gastos mensuales prorrateados, comparativo sin base                |
| ANA     | series con días cero, top productos y snapshots, ventas sin partidas canceladas, período anterior                  |
| RES     | estados técnicos y reemplazos, periodos históricos/futuros, pendientes sin inferir no-show, lectura sin escrituras |
| INV     | más de una página, varios almacenes y unidades, bajo mínimo, mermas/cancelación de consumos, foto vs movimiento    |
| TIC     | cerrados/cancelados/abiertos, ítems cancelados, promedio, propinas separadas, periodos limítrofes                  |

### 11.3 Definición de terminado

La primera entrega solo se considera aceptada cuando **los cinco** informes Restaurante están disponibles desde sus módulos; números y permisos coinciden con las autoridades del dominio; Dompdf es único; no hay regresiones críticas nuevas ni cambios destructivos; y la documentación declara con claridad Panadería/Global **pendientes de activación**.

## 12. Guía para la implementación con subagentes

El documento es normativo para la funcionalidad; lo siguiente es un **procedimiento operativo sugerido**, no autorización para hacer cambios en `main`.

### Organización mínima (sin enjambre)

- **Orquestador:** modelo de alta capacidad (p. ej., GPT-6.1 Sol/High). Controla alcance, preflight, una rama de trabajo y un worktree; descompone tareas, protege invariantes y coordina revisión/commits.
- **Ejecutor:** modelo económico/intermedio (GPT-6 Luna para UI, plantillas y cambios pequeños; GPT-6.1 Terra para integración de servicios). **Único escritor activo**; tareas delimitadas por archivos; no decide nuevos módulos ni refactors masivos.
- **Validador independiente:** GPT-6.1 Terra/High para pruebas/UI/diff; escalar a Sol/High cuando se toquen consultas financieras, autorizaciones, inventario, transacciones o definiciones históricas. No valida su propio trabajo.

**Reglas:** una sola rama de intervención nacida de la versión actual autorizada de `main`; un único worktree; sin ramas por informe, sin cherry-picks, sin múltiples escritores, sin PR/merge/push automático. No imponer un número arbitrario de commits, pero **preferir uno por entrega validada** (fundación compartida y los informes por lotes), evitando commits intermedios de experimentación. Jamás regenerar el DDL contra datos operativos. Verificar `git status`, HEAD y diferencias locales antes de empezar y preservar todo cambio ajeno.

**Handoff máximo 12 líneas:** etapa/tarea, objetivo, archivos permitidos, invariantes (máx. 3), pruebas obligatorias, resultados con evidencia, bloqueo, decisión de aprobación. Un agente no arrastra conversaciones ni logs completos. Leer este documento inicialmente, y durante cada etapa sólo la sección relevante más las reglas de seguridad.

### Orden secuencial recomendado

| Etapa  | Ejecución                                                                                                   | Puerta de salida                                                             |
| ------ | ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| **I0** | Preflight, confirmar HEAD/estado, rutas y servicios; identificar pruebas DB seguras; congelar reglas de KPI | Invariantes/métricas sin ambigüedad                                          |
| **I1** | Crear generador PDF y plantilla base + botón reutilizable, una prueba de render seguro                      | PDF sintético, autorización y visual PASS                                    |
| **I2** | Implementar **FIN** (Finanzas Restaurante) sobre servicios existentes                                       | Paridad financiera y seguridad PASS                                          |
| **I3** | Implementar **ANA** (Análisis Restaurante)                                                                  | Totales, series y top PASS                                                   |
| **I4** | Implementar **RES** (Reservaciones Restaurante) con nuevo agregado puro de lectura                          | Sin doble conteo ni escritura; estados PASS                                  |
| **I5** | Implementar **INV** (Inventario Restaurante)                                                                | Foto vs movimientos, almacenes y paginación PASS                             |
| **I6** | Implementar **TIC** (Tickets Restaurante)                                                                   | Cierres/cancelaciones, ventas y exclusiones PASS                             |
| **I7** | Cierre integral: visual, seguridad, rendimiento, docs y coherencia de extensibilidad                        | Cinco informes activos; Panadería/Global inactivos; aprobación independiente |

Un mismo ejecutor puede completar etapas contiguas; **no crear un subagente nuevo por archivo**. Cada etapa corrige su propia regresión antes de avanzar. Si un KPI no puede calcularse de forma fiable, no falsificarlo: documentar el bloqueo y conservar la función sin publicarla hasta la decisión del orquestador.

### Criterios de modificación de este documento

- El implementador no puede reinterpretar silenciosamente «comensales atendidos», «ausencia» o «saldo histórico».
- Toda variación funcional propuesta exige: definición previa, razón, impacto en otros informes, cambio en este documento y validación independiente.
- Tras terminar, registrar enlaces a tests y resultados en `docs/desarrollo/pruebas.md`, actualizar `docs/desarrollo/clasificacion-documental.md` y enlazar esta especificación desde `docs/arquitectura.md` **sin duplicar el contrato**.

## 13. Límites que no deben confundirse con pendientes de implementación

- **Informe gerencial ≠ exportación completa de datos.** No contiene cada reserva, ticket ni movimiento; es un resumen de gestión.
- **Documento inmediato ≠ reporte programado.** No se generan, almacenan o distribuyen PDFs en segundo plano.
- **Permiso de ver un módulo ≠ permiso de ver otros módulos.** Cada informe exige el suyo.
- **Global ≠ tercer punto de venta.** Solo consolida hechos autorizados.
- **Fotografía actual ≠ dato histórico.** Especialmente en inventario y tickets abiertos.
- **PDF válido ≠ KPI correcto.** Las verificaciones deben cubrir semántica y no solo apariencia.

---

**Resultado esperado al aprobar este contrato:** una única especificación que habilita la implementación secuencial de cinco informes de Restaurante, con presentación compartida y arquitectura extensible, **sin activar ni prometer reportes incompletos de Panadería o Global**.
