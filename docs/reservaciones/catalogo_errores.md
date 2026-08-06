# Catálogo canónico de errores y decisiones

## Convenciones

La fuente ejecutable del catálogo es `services/ReservacionErrorCatalog.php`.
El archivo contiene el registro de códigos y la traducción única de:

```text
codigo + contexto
→ tipo
→ HTTP canónico
→ título
→ mensaje
→ consecuencia
→ acciones
→ commit
```

Los servicios devuelven `codigo`, contexto seguro y, cuando corresponde,
`field_codes`. El enriquecedor añade la presentación canónica; no reemite
`msg`, `message` ni `mensaje_bloqueo`.

El campo `commit` expresa el resultado transaccional conocido por la capa que
creó la respuesta. Si el servicio no lo informa, el catálogo aporta el valor
esperado para el código y no inventa detalles de SQL, locks o excepciones.

No se exponen SQL, stack traces, rutas locales, excepciones sin filtrar ni
datos personales.

## Contrato común

```php
[
    'ok' => false,
    'codigo' => 'MESA_OCUPADA',
    'codigo_canonico' => 'MESA_OCUPADA',
    'tipo' => 'conflicto_recuperable',
    'http_status' => 409,
    'mensaje_key' => 'reservaciones.codigo.mesa_ocupada',
    'mensaje' => 'Una de las mesas cambió de estado y ya no está disponible.',
    'consecuencia' => 'No se aplicó la asignación solicitada.',
    'acciones' => [
        ['id' => 'ACTUALIZAR_MAPA', 'tipo' => 'primary'],
    ],
    'commit' => false,
]
```

Los campos heredados `msg`, `message` y `mensaje_bloqueo` están retirados del
contrato. JavaScript debe leer `codigo`, `tipo`, `mensaje`, `consecuencia` y
`acciones`; nunca debe deducir la causa comparando textos.

## Tipos

| Tipo | Uso |
|---|---|
| `error` | Entrada inválida, seguridad, sesión, estado o fallo interno |
| `conflicto_recuperable` | El estado cambió; se puede actualizar y reintentar |
| `advertencia` | Riesgo o condición relevante sin confirmación especial |
| `decision_requerida` | El operador debe aceptar conscientemente una consecuencia |
| `informacion` | Resultado exitoso o estado informativo sin error |

## Seguridad, sesión y OTP

| Código | HTTP | Causa canónica | Acción primaria | Commit |
|---|---:|---|---|---:|
| `SESION_PUBLICA_EXPIRADA` | 401 | No existe sesión pública verificable | `VERIFICAR_CONTACTO` | false |
| `CSRF_INVALIDO` | 403 | Token CSRF ausente, inválido o vencido | `ACTUALIZAR` | false |
| `NO_AUTORIZADO` | 401 | No existe sesión de personal | `INICIAR_SESION` | false |
| `PERMISO_DENEGADO` | 403 | El rol no puede usar la superficie | `CERRAR` | false |
| `CONTACTO_NO_VERIFICADO` | 401 | La operación pública exige contacto verificado | `VERIFICAR_CONTACTO` | false |
| `CONTACTO_NO_COINCIDE` | 401 | El contacto enviado no coincide con la sesión | `VERIFICAR_CONTACTO` | false |
| `OTP_INCORRECTO` | 422 | El código no coincide | `REINTENTAR` | false |
| `OTP_EXPIRADO` | 410 | El código superó su vigencia | `SOLICITAR_CODIGO` | false |
| `OTP_INTENTOS_AGOTADOS` | 429 | Se agotó el máximo de intentos | `SOLICITAR_CODIGO` | false |
| `VERIFICACION_NO_ENCONTRADA` | 422 | No hay una verificación activa utilizable | `SOLICITAR_CODIGO` | false |
| `REENVIO_NO_DISPONIBLE` | 429 | El reenvío aún está dentro de su intervalo | `CERRAR` | false |
| `DATOS_INVALIDOS` | 422 | La entrada no satisface la validación del caso | `CORREGIR_DATOS` | false |
| `DATOS_INCOMPLETOS` | 422 | Faltan campos requeridos para la operación | `CORREGIR_DATOS` | false |
| `METODO_NO_PERMITIDO` | 405 | La ruta no admite el método recibido | `CERRAR` | false |
| `METODO_INVALIDO` | 422 | La operación solicitada no es válida | `CERRAR` | false |
| `ERROR_INTERNO` | 500 | Falla interna filtrada | `REINTENTAR` | false |

## Horarios

`FECHA_INVALIDA`, `FECHA_PASADA`, `FECHA_FUERA_DE_HORIZONTE`, `FECHA_CERRADA`,
`FECHA_PASADA_SOLO_LECTURA`, `HORARIO_INVALIDO`, `HORARIO_NO_VALIDO`,
`HORARIO_PASADO`, `HORARIO_SIN_CONFIGURACION`, `DIA_INACTIVO`,
`ANTICIPACION_INSUFICIENTE`, `DESPUES_DE_ULTIMA_RESERVACION`,
`ULTIMA_RESERVACION_SUPERADA` y `JORNADA_TERMINADA` tienen una causa de
horario independiente. La traducción explica la regla aplicable sin exponer
constantes internas. `FECHA_PASADA_SOLO_LECTURA` es informativa y no bloquea la
consulta histórica; las demás son errores de validación o conflicto operativo.

## Disponibilidad, capacidad y asignación

| Código | Tipo | HTTP | Política |
|---|---|---:|---|
| `SIN_DISPONIBILIDAD` | conflicto | 409 | Landing bloquea y ofrece cambiar horario |
| `CAPACIDAD_INSUFICIENTE` | conflicto | 409 | No existe capacidad suficiente para continuar automáticamente |
| `CAPACIDAD_OPERATIVA_EXCEDIDA` | decisión | 200 | Administración debe confirmar explícitamente |
| `SIN_ASIGNACION` | decisión | 200 | Administración puede confirmar y asignar después |
| `REQUIERE_CONFIRMACION_CAPACIDAD` | decisión | 200 | Confirmación reforzada por capacidad |
| `REQUIERE_CONFIRMACION_SIN_CONTACTO` | decisión | 200 | Confirmación administrativa sin contacto |
| `REQUIERE_CONFIRMACION` | decisión | 200 | Confirmación por advertencia operativa |
| `REQUIERE_REASIGNACION` | conflicto | 409 | Las mesas originales dejaron de ser válidas |
| `ASIGNACION_VACIA` | error | 422 | No se recibió una asignación utilizable |
| `MESAS_INVALIDAS` | error | 422 | IDs o mesas no cumplen elegibilidad |
| `MESA_NO_RESERVABLE` | error | 422 | La mesa no participa en reservaciones |
| `MESA_OCUPADA` | conflicto | 409 | La mesa fue ocupada durante la operación |
| `MESA_OCUPADA_EN_HORARIO` | conflicto | 409 | Existe conflicto en el intervalo solicitado |
| `GRUPO_NO_DISPONIBLE` | conflicto | 409 | El grupo autorizado no puede utilizarse completo |
| `AGRUPACION_NO_AUTORIZADA` | error | 422 | La combinación no pertenece a grupos válidos |
| `SUPERPOSICION_NO_AUTORIZADA` | conflicto | 409 | La asignación se traslapa con otra ocupación |
| `CONFLICTO_DE_ASIGNACION` | conflicto | 409 | La propuesta de mesas no es consistente |
| `CONFLICTO_TICKETS_ABIERTOS` | conflicto | 409 | Hay tickets abiertos que impiden la mutación |
| `VERSION_DESACTUALIZADA` | conflicto | 409 | La reservación cambió desde la consulta |
| `CONFLICTO_CONCURRENTE` | conflicto | 409 | Otra operación ganó la condición concurrente |
| `DEPENDE_LIBERACION_PROYECTADA` | advertencia | 200 | La propuesta depende de una liberación futura |
| `LIBERAR_ASIGNACION_ACTUAL` | decisión | 200 | Se requiere confirmación para liberar mesas actuales |
| `LIBERACION_NO_AUTORIZADA` | error | 422 | El actor no puede liberar esa asignación |

La capacidad física, la demanda no asignada y la proyección temporal siguen
siendo temas de las etapas R3/R4. Este catálogo sólo centraliza cómo se
comunican sus resultados.

## Identidad y ciclo de reservación

| Código | Causa | Acción |
|---|---|---|
| `RESERVACION_NO_ENCONTRADA` | No existe el recurso consultado | `ACTUALIZAR` |
| `RESERVACION_NO_PERTENECE_AL_CONTACTO` | El recurso no pertenece a la sesión pública | `VERIFICAR_CONTACTO` |
| `RESERVACION_NO_EDITABLE` / `ESTADO_NO_EDITABLE` | El estado no permite la operación | `CERRAR` |
| `ESTADO_INVALIDO` | La transición solicitada no es válida | `ACTUALIZAR` |
| `RESERVACION_PASADA` / `RESERVACION_HORARIO_PASADO` | La operación llegó después del límite aplicable | `CERRAR` |
| `MODIFICACION_NO_PERMITIDA` | La modificación pública ya no está permitida | `CERRAR` |
| `CANCELACION_NO_PERMITIDA` | La cancelación pública ya no está permitida | `CERRAR` |
| `RESERVACION_DUPLICADA` | Ya existe una reservación activa equivalente | `CONSULTAR_RESERVACIONES` |
| `LIMITE_RESERVACIONES_ALCANZADO` | Se superó el máximo por contacto | `CONSULTAR_RESERVACIONES` |
| `RETENCION_EXPIRADA` | Hold de alta o modificación vencido | `REINTENTAR` |
| `REQUEST_TOKEN_CONFLICTO` | El token se reutilizó con otros datos | `REINTENTAR` |
| `SIN_CONTACTO` | Falta decisión sobre contacto administrativo | `CONFIRMAR_SIN_CONTACTO` |
| `CONTACTO_TIPO_NO_EDITABLE` | Se intentó cambiar el tipo existente | `CORREGIR_DATOS` |
| `COMENTARIO_NO_DISPONIBLE` | El estado no admite comentario | `CERRAR` |

## Tickets, tolerancia y POS

| Código | Tipo | HTTP | Acción primaria |
|---|---|---:|---|
| `TICKET_ABIERTO` | conflicto | 409 | `CONSULTAR_TICKET` |
| `TICKET_ABIERTO_EXISTENTE` | retirado; usar `TICKET_ABIERTO` | — | — |
| `TICKET_NO_ENCONTRADO` | error | 422 | `ACTUALIZAR` |
| `TICKET_YA_CERRADO` | conflicto | 409 | `ACTUALIZAR` |
| `TICKET_CERRADO` | información | 200 | `CERRAR` |
| `TICKET_DUPLICADO` | conflicto | 409 | `ACTUALIZAR` |
| `MESAS_TICKET_EN_CONFLICTO` | conflicto | 409 | `ACTUALIZAR_MAPA` |
| `RESERVACION_YA_EN_CURSO` | conflicto | 409 | `CONSULTAR_TICKET` |
| `RESERVACION_SIN_TICKET` | advertencia | 200 | `ABRIR_TICKET` |
| `RESERVACION_PROXIMA` | advertencia | 200 | `CONFIRMAR_OPERACION` |
| `TOLERANCIA_VIGENTE` | advertencia | 422 | `CERRAR` |
| `TOLERANCIA_LLEGADA_VENCIDA` | decisión | 409 | `REGISTRAR_AUSENCIA` |
| `REGISTRO_AUSENCIA_NO_DISPONIBLE` | error | 422 | `ACTUALIZAR` |
| `RESERVACION_YA_NO_SHOW` | información | 200 | `CERRAR` |
| `RESERVACION_CON_TICKET_ABIERTO` | conflicto | 409 | `CONSULTAR_TICKET` |

El POS recibe `codigo`, `codigo_canonico`, `tipo`, `mensaje`, `consecuencia` y
`acciones`; las advertencias anidadas llevan la misma presentación. La regla física de ticket sigue siendo `estado = abierto` y
`closed_at IS NULL`; el catálogo no modifica esa fuente de ocupación.

## Mantenimiento y resultados informativos

`OK`, `RESERVACION_CREADA`, `RESERVACION_CREADA_SIN_MESA`, `ACTUALIZADA`,
`ACTUALIZADA_REQUIERE_ASIGNACION`, `COMENTARIO_ACTUALIZADO`, `CONFIRMADA`,
`COMPLETADA`, `CANCELADA`, `NO_SHOW`, `RESERVACION_CONFIRMADA`,
`RESERVACION_MODIFICADA`, `RESERVACION_CANCELADA`, `REEMPLAZO_CREADO`,
`REEMPLAZO_CONFIRMADO`, `RETENCION_CREADA`, `RETENCIONES_EXPIRADAS`,
`HORARIOS_ACTUALIZADOS`, `HORARIOS_OBTENIDOS`, `DISPONIBILIDAD_CONSULTADA`,
`HORARIO_DISPONIBLE`, `ASIGNACION_GUARDADA`, `CONTACTO_VERIFICADO`,
`OTP_GENERADO`, `OTP_SOLICITADO`, `GESTION_SALIDA` y `RESERVACIONES_AFECTADAS`
son resultados catalogados; `RESERVACIONES_AFECTADAS` es un conflicto que
requiere actualizar antes de continuar. Los códigos de escritura exitosa se marcan con
`commit=true` por defecto; las consultas no lo hacen.

`AMBIENTE_NO_PERMITIDO` y `CONFIRMACION_INVALIDA` son errores del script de
mantenimiento de fixtures y nunca deben presentarse como fallas públicas del
restaurante.

`RESPUESTA_INVALIDA` y `FECHA_RESPUESTA_MISMATCH` son códigos de integridad del
cliente para respuestas JSON inválidas o fechas que no coinciden con la
consulta. Se catalogan para que el frontend pueda instrumentarlos sin crear
un traductor local; no representan una nueva regla de capacidad u horario.

## Aliases y compatibilidades

| Código heredado | Código canónico | Decisión Etapa 3 |
|---|---|---|
| `RESERVACION_NO_EXISTE` | `RESERVACION_NO_ENCONTRADA` | Unificar; no se emite el nombre heredado |
| `SIN_CAPACIDAD` | `CAPACIDAD_INSUFICIENTE` | Unificar; no se emite el nombre heredado |
| `TICKET_ABIERTO_EXISTENTE` | `TICKET_ABIERTO` | Retirar; no se emite el nombre heredado |
| `msg`, `message`, `mensaje_bloqueo` | `mensaje` | Retirar; el enriquecedor los elimina |

Los nombres de constantes PHP heredados que aún aparecen en algunas fachadas
conservan el valor canónico y no forman aliases de runtime; se mantienen sólo
para no romper referencias internas no textuales. El auditor y el runner
verifican que no haya declaraciones literales ambiguas ni aliases ejecutables.

## Contradicciones para etapas posteriores

- R3: la liberación proyectada y el estado visual de mesa no están alineados.
- R4: la demanda confirmada sin mesa todavía no se incorpora al cálculo real.
- R6: la tolerancia vencida aún puede conservar influencia sobre disponibilidad.
- Las consultas contextuales de ocupación POS siguen duplicadas.
- La verificación dinámica con DB/Apache queda para la etapa de pruebas de
  integración; esta etapa no altera DDL, DML, estados, locks ni asignación.
