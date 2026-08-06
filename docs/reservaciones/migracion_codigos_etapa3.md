# Migración de códigos — Etapa 3

Fecha: 2026-08-05  
Rama: `modulo-reservaciones`  
Catálogo ejecutable: `services/ReservacionErrorCatalog.php`

## Resultado

La migración queda aprobada estáticamente: servicios, controladores,
serializadores y consumidores JS usan códigos canónicos; la traducción visible
se produce desde el catálogo. El auditor reporta 0 errores, 0 warnings de
contrato, 0 mensajes literales en servicios, 0 consumidores heredados, 0
comparaciones textuales y 0 contextos sensibles interpolados fuera del
catálogo.

El catálogo contiene 191 definiciones y el auditor encontró 115 códigos
emitidos en los 24 archivos PHP relacionados. Los códigos de campo se
transportan en `field_codes`; el enriquecedor genera `errors` visibles y
respeta únicamente el contexto permitido.

## Decisiones de aliases

| Código heredado | Código canónico | Decisión | Evidencia |
|---|---|---|---|
| `RESERVACION_NO_EXISTE` | `RESERVACION_NO_ENCONTRADA` | Unificar | No se emite el nombre heredado; las fachadas PHP que aún lo nombran contienen el valor canónico. |
| `SIN_CAPACIDAD` | `CAPACIDAD_INSUFICIENTE` | Unificar | POS, asignación y JS comparan sólo el código canónico. |
| `TICKET_ABIERTO_EXISTENTE` | `TICKET_ABIERTO` | Retirar | No existe en el catálogo ni en consumidores runtime. |

`ReservacionErrorCatalog::aliases()` queda vacío. Los nombres públicos de
constantes heredados que aún son necesarios para referencias internas no son
aliases ejecutables: no contienen una segunda cadena de código y no llegan al
payload.

## Contrato final

Los servicios devuelven, según el caso:

```php
[
    'ok' => false,
    'codigo' => 'CAPACIDAD_INSUFICIENTE',
    'contexto' => [
        'capacidad_solicitada' => 8,
        'capacidad_disponible' => 6,
    ],
    'field_codes' => [
        'comensales' => ['COMENSALES_FUERA_DE_RANGO'],
    ],
]
```

La superficie HTTP llama a `enriquecer()`, que agrega tipo, HTTP, clave de
mensaje, mensaje, consecuencia, acciones y `commit`. El método elimina
siempre `msg`, `message` y `mensaje_bloqueo`, incluso si un adaptador antiguo
los recibe accidentalmente.

Las advertencias anidadas de POS llevan `codigo`, `contexto` y
`presentacion`; el cliente no reconstruye mensajes con hora, minutos,
capacidad, mesas o tickets.

## Clasificación de mensajes

| Grupo | Tratamiento |
|---|---|
| Visible de negocio | Se trasladó a `TEXTS`/`FIELD_TEXTS` del catálogo. |
| Dinámico | Se expresa como `{placeholder}` y contexto seguro allowlisted. |
| Técnico | Sólo se registra en logs; el payload usa `ERROR_INTERNO` o el código de configuración correspondiente. |
| Detalle operacional | Viaja como datos estructurados (`mesa_ids`, `conflictos`, `warnings`); no como texto-causa. |
| UI local | Se conservan sólo etiquetas de interacción no contractuales; toda causa de dominio proviene del payload catalogado. |

## Duplicados y contratos heredados

Las 12 declaraciones duplicadas detectadas en la línea base se resolvieron
retirando literales secundarios. Cuando una referencia PHP interna no podía
eliminarse sin cambiar la API de una clase, se dejó una constante-fachada que
apunta al valor de la clase canónica. El auditor ya no encuentra declaraciones
literales duplicadas.

No quedan consumidores de `msg`, `message` o `mensaje_bloqueo` en las
superficies de reservaciones/POS. El formulario administrativo y el mapa
consumen `mensaje`; el POS consume `mensaje`, `acciones` y presentaciones
anidadas.

## Auditoría y pruebas

Comandos puros ejecutados:

```text
php scripts/tests/run-reservaciones-catalogo.php
php scripts/auditar-errores-reservaciones.php
npm.cmd test
npm.cmd run audit:reservaciones
composer validate --no-check-publish
npx.cmd gulp --tasks-simple
```

El runner de catálogo cubre unicidad, tipos, HTTP, acciones, conflictos,
`commit`, aliases/ciclos, interpolación, field codes, mojibake, contratos
heredados, códigos JS y comparaciones textuales. La prueba dinámica contra DB,
Apache y navegador no se ejecutó porque no había un servicio activo; no se
modificaron DDL, DML, estados, locks, CSRF ni el motor de asignación.

## Criterios de cierre

| Criterio | Estado |
|---|---|
| 0 errores de catálogo/auditor | Cumplido |
| 0 mensajes visibles directos en servicios | Cumplido |
| 0 comparaciones textuales JS | Cumplido |
| 0 mojibake detectado | Cumplido |
| 0 aliases ejecutables ambiguos | Cumplido |
| 0 códigos emitidos fuera del catálogo | Cumplido |
| 0 campos heredados reemitidos | Cumplido |
| Pruebas dinámicas DB/HTTP/browser | Pendiente de entorno |

