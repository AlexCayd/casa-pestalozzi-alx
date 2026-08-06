# Módulo de reservaciones — Fuente de verdad vigente

**Proyecto:** Casa Pestalozzi
**Versión:** 2026-08-05
**Estado:** Contrato funcional y técnico vigente
**Zona horaria canónica:** `America/Mexico_City`
**Propósito:** Definir exclusivamente las reglas vigentes del módulo de reservaciones.

> Este documento reemplaza como referencia normativa al archivo histórico anterior.
> Los planes por etapas, reportes de implementación y decisiones sustituidas deben conservarse fuera de esta fuente de verdad.

---

## 1. Precedencia y control de cambios

1. Este documento es la referencia funcional principal del módulo.
2. El comportamiento implementado que contradiga este documento se considera incorrecto.
3. Los reportes de etapas y documentos históricos no pueden modificar estas reglas.
4. Una decisión nueva debe integrarse en la sección correspondiente; no debe agregarse como un anexo que contradiga texto anterior.
5. Antes de implementar un cambio:
   1. actualizar esta fuente de verdad;
   2. identificar servicios y consumidores afectados;
   3. actualizar pruebas;
   4. implementar;
   5. validar landing, administración, mapa y punto de venta.

---

## 2. Objetivo y superficies

El módulo permite:

- Consultar y crear reservaciones desde la landing.
- Verificar el contacto mediante OTP.
- Consultar, modificar o cancelar reservaciones públicas autorizadas.
- Crear, consultar, modificar y cancelar reservaciones administrativas.
- Asignar y reasignar mesas desde el mapa.
- Integrar reservaciones con tickets sin duplicar ocupación.
- Mostrar ocupación actual y proyección futura con una única lógica canónica.

Las superficies son:

1. **Landing pública.**
2. **Panel administrativo.**
3. **Mapa de gestión de reservaciones.**
4. **Mapa del punto de venta.**

Las cuatro superficies consumen los mismos hechos de horario, ocupación, capacidad y conflictos. Cada una aplica una política de aceptación distinta cuando así se define expresamente.

---

## 3. Conceptos canónicos

### 3.1 Ocupación canónica

Es el conjunto de bloqueos que afectan mesas o capacidad en una fecha e intervalo:

- Tickets abiertos.
- Reservaciones `confirmada`.
- Holds vigentes de `pendiente_verificacion`.
- Reservaciones administrativas confirmadas sin mesas, como demanda no asignada.
- Estado activo y reservable de las mesas.

La ocupación canónica se calcula en backend. Ningún JavaScript, controlador o vista debe reconstruirla de forma independiente.

### 3.2 Disponibilidad real

La disponibilidad real es común para landing, administración, mapas y POS.

Incluye:

- Mesas físicamente elegibles.
- Capacidad de sus asientos.
- Intervalos ocupados.
- Proyección de liberación de tickets.
- Reservaciones y holds que se traslapan.
- Demanda confirmada sin mesas asignadas.

La disponibilidad real no cambia por superficie. Lo que cambia es si la superficie permite continuar pese a no existir asignación automática o pese a una advertencia de capacidad.

### 3.3 Asignabilidad automática

Indica si existe una mesa individual o grupo autorizado que pueda asignarse automáticamente para toda la reservación.

La asignabilidad automática es estricta para:

- Landing.
- Modificación pública.

En administración es opcional.

### 3.4 Capacidad física libre

Es la suma de la capacidad de las mesas elegibles que permanecen disponibles durante todo el intervalo solicitado.

```text
capacidad_fisica_libre =
SUM(mesas.capacidad de mesas disponibles durante todo el intervalo)
```

Una mesa se considera disponible sólo si no presenta conflicto durante el intervalo completo.

Ejemplo:

```text
Capacidad total reservable: 44
Una reservación ocupa dos mesas con capacidad conjunta de 8
Capacidad física libre: 36
```

La capacidad física se descuenta por los asientos de las mesas comprometidas, no por los comensales de la reservación asignada.

### 3.5 Demanda no asignada

Una reservación administrativa confirmada sin filas en `reservacion_mesas` debe seguir afectando la disponibilidad.

```text
demanda_no_asignada =
SUM(comensales de reservaciones confirmadas sin mesas
    que se traslapan con el intervalo)
```

### 3.6 Capacidad real disponible

```text
capacidad_real_disponible =
MAX(0, capacidad_fisica_libre - demanda_no_asignada)
```

Esta cifra representa una estimación operativa. No sustituye la validación de combinaciones físicas para landing.

### 3.7 Política de aceptación

| Superficie | Disponibilidad consultada | Regla para continuar |
|---|---|---|
| Landing | Disponibilidad real | Requiere capacidad y asignación automática válida |
| Modificación pública | Disponibilidad real | Requiere capacidad y asignación automática válida |
| Administración | Misma disponibilidad real | Puede confirmar sin mesas mediante advertencia |
| Mapa de reservaciones | Misma ocupación y capacidad | Puede asignar manualmente y confirmar advertencias |
| POS | Misma ocupación actual/proyectada | Opera tickets y reservaciones según capacidades permitidas |

Administración tiene libertad operativa; no utiliza una disponibilidad diferente.

---

## 4. Constantes

Las constantes se definen una sola vez.

```php
final class ReservacionConfig
{
    public const DURACION_RESERVACION_MINUTOS = 90;
    public const DURACION_ESTIMADA_TICKET_MINUTOS = 90;
    public const RETRASO_ESTIMADO_TICKET_MINUTOS = 0;

    public const ANTICIPACION_MINIMA_MINUTOS = 40;
    public const MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION = 90;

    public const VIGENCIA_HOLD_MINUTOS = 15;
    public const TOLERANCIA_LLEGADA_MINUTOS = 15;
    public const LLEGADA_ANTICIPADA_MINUTOS = 30;
    public const AVISO_RESERVACION_PROXIMA_MINUTOS = 60;

    public const LIMITE_MODIFICACION_MINUTOS = 30;
    public const TOLERANCIA_CANCELACION_PUBLICA_MINUTOS = 15;

    public const MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO = 5;
    public const HORIZONTE_MAXIMO_DIAS = 90;

    public const GRUPOS_DOS_MESAS = [
        [7, 8],
        [6, 9],
        [10, 11],
        [3, 4],
    ];

    public const GRUPOS_TRES_MESAS = [
        [2, 4, 5],
        [11, 10, 9],
    ];
}
```

No se repiten números literales equivalentes en controladores, vistas, JavaScript o SQL.

### 4.1 Relación entre duración y tolerancia

La duración estimada de una reservación es de 90 minutos.

Los primeros 15 minutos corresponden a la tolerancia de llegada y ya están incluidos dentro de esos 90 minutos. No se suman 15 minutos adicionales.

```text
inicio_estimado = hora_reservacion
fin_estimado = hora_reservacion + 90 minutos
tolerancia = [hora_reservacion, hora_reservacion + 15 minutos]
```

Si el cliente no llega y no existe ticket al terminar la tolerancia, aplica la regla de ausencia pendiente de la sección 10.

---

## 5. Horarios reservables

### 5.1 Prioridad

1. Excepción activa para la fecha.
2. Si la excepción es `cerrado`, no hay horarios.
3. Si es `horario_especial`, se usa su apertura y cierre.
4. Sin excepción, se usa el horario semanal.
5. Si el día está cerrado, no hay horarios.

### 5.2 Anticipación mínima

Una nueva reservación pública o administrativa no puede iniciar antes de:

```text
hora_actual + 40 minutos
```

Se elige el primer bloque configurado igual o posterior al límite exacto. No se redondea la hora actual antes de sumar 40 minutos.

### 5.3 Última reservación

```text
ultima_hora_reservable = cierre - 90 minutos
```

### 5.4 Horizonte

Se permiten fechas desde el día actual hasta 90 días posteriores.

### 5.5 Navegación del mapa

La anticipación mínima limita altas y cambios de horario; no limita la navegación del mapa.

Para el día actual:

- Se muestra el bloque configurado actual o inmediatamente anterior.
- No se muestran bloques anteriores.
- URL, caché o estado persistido no pueden reactivar horarios históricos.

Para fechas futuras se muestran todos los bloques operativos válidos.

---

## 6. Mesas y grupos

### 6.1 Elegibilidad

Una mesa participa en capacidad y reservaciones cuando:

```text
mesas.activo = 1
mesas.reservable = 1
mesas.tipo = 'mesa'
```

Barras, caja, para llevar y elementos no reservables quedan excluidos.

### 6.2 Identificación

Los grupos se definen por `mesas.numero`, no por `mesas.id`.

### 6.3 Capacidad

La capacidad de una combinación es la suma actual de `mesas.capacidad`.

### 6.4 Asignación automática

Orden de evaluación:

1. Mesas individuales.
2. Grupos autorizados de dos mesas.
3. Grupos autorizados de tres mesas.

Orden de preferencia:

1. Capacidad suficiente.
2. Menor número de mesas.
3. Menor desperdicio de asientos.
4. Sin conflictos.
5. Grupo autorizado.

Si una mesa de un grupo es inactiva, no reservable o presenta conflicto, el grupo completo deja de ser candidato.

### 6.5 Asignación manual

La asignación manual:

- Se realiza exclusivamente en modo explícito de edición.
- Revalida estado, versión, tickets y ocupación.
- Se guarda en una transacción.
- No permite una mutación parcial de una reservación multimesa.
- Puede mostrar advertencias de diferencia entre comensales y capacidad seleccionada.

---

## 7. Intervalos y proyección temporal

### 7.1 Conflicto por intervalo

Existe traslape cuando:

```text
ocupacion_inicio < nueva_fin
AND ocupacion_fin > nueva_inicio
```

No se compara únicamente la igualdad de horas.

### 7.2 Tickets abiertos

`ticket_mesas` es la fuente canónica de ocupación física.

Un ticket está abierto cuando cumple simultáneamente:

```text
tickets.estado = 'abierto'
AND tickets.closed_at IS NULL
```

Para el día actual:

```text
liberacion_estimada_ticket =
hora_apertura
+ 90 minutos
+ RETRASO_ESTIMADO_TICKET_MINUTOS
```

Con la configuración vigente:

```text
liberacion_estimada_ticket = hora_apertura + 90 minutos
```

#### Bloque actual

Un ticket realmente abierto mantiene la mesa ocupada aunque ya haya superado su liberación estimada.

#### Bloque futuro del mismo día

El ticket bloquea una mesa cuando:

```text
hora_apertura < fin_del_bloque
AND liberacion_estimada_ticket > inicio_del_bloque
```

Cuando el bloque comienza en o después de la liberación estimada, ese ticket deja de bloquear la proyección.

Ejemplo:

```text
Ticket abierto: 09:00
Liberación estimada: 10:30

Bloque 10:00 → ocupado
Bloque 10:30 → disponible por ese ticket
Bloque 11:00 → disponible por ese ticket
```

La disponibilidad final todavía debe considerar otras reservaciones, holds o tickets.

### 7.3 Reservaciones confirmadas

Sin ticket vinculado, una reservación confirmada tiene una ocupación estimada de:

```text
[hora_reservacion, hora_reservacion + 90 minutos)
```

La tolerancia de 15 minutos está incluida en los 90 minutos.

Si durante la operación real se abre un ticket vinculado:

- La reservación pasa a `en_curso`.
- La ocupación se toma exclusivamente de `ticket_mesas`.
- No se cuenta simultáneamente `reservacion_mesas`.

### 7.4 Holds

Un hold bloquea sólo cuando:

```text
estado = pendiente_verificacion
AND hold_expires_at > ahora
```

El hold deja de bloquear inmediatamente al vencer, aunque todavía no se haya ejecutado un proceso de limpieza.

### 7.5 Reservaciones sin mesas

Una reservación administrativa `confirmada` sin mesas:

- No bloquea mesas específicas.
- Sí aporta su número de comensales a `demanda_no_asignada`.
- Debe mostrarse como incidencia de asignación manual pendiente.
- No crea un estado adicional.

### 7.6 Fechas posteriores al día actual

Los tickets abiertos actuales no se consideran para otros días.

Se consideran:

- Reservaciones confirmadas.
- Holds vigentes de la fecha.
- Mesas elegibles.
- Demanda no asignada de la fecha.
- Intervalos de 90 minutos.

---

## 8. Disponibilidad y creación pública

### 8.1 Regla estricta

Después de elegir fecha, hora y comensales, la landing sólo permite continuar cuando:

1. El horario es válido.
2. Existe capacidad real suficiente.
3. Existe una combinación automática válida.
4. No existen conflictos.
5. La validación final transaccional confirma el mismo resultado.

La landing no muestra capacidad, asientos libres, mesas candidatas ni grupos internos.

### 8.2 Comensales

- De 1 a 12: flujo público disponible.
- Desde 13: se bloquea el flujo público y se muestran los datos de contacto del restaurante.

### 8.3 Creación

Dentro de una transacción:

1. Revalidar horario.
2. Revalidar disponibilidad.
3. Validar duplicado.
4. Validar máximo por contacto.
5. Crear `pendiente_verificacion`.
6. Asignar mesas.
7. Definir hold de 15 minutos.
8. Crear OTP.
9. Confirmar transacción.

Al validar OTP:

- `pendiente_verificacion → confirmada`.
- Se actualiza `estado_changed_at`.

### 8.4 Duplicados

El mismo contacto no puede tener dos reservaciones activas en la misma fecha y hora.

Cuentan:

- Hold vigente de una alta nueva.
- `confirmada`.

No cuentan:

- Modificación pendiente.
- `en_curso`.
- Estados terminales.

### 8.5 Máximo por contacto

Máximo cinco reservaciones activas futuras por contacto.

Cuentan:

- `confirmada` con fecha igual o posterior a la fecha actual.
- Holds vigentes de altas nuevas.

No cuentan modificaciones pendientes ni `en_curso`.

---

## 9. Administración y creación con libertad operativa

### 9.1 Principio

Administración consulta exactamente la misma disponibilidad real que landing.

La diferencia es la política:

- Landing exige asignación automática.
- Administración puede confirmar sin mesas.
- Administración puede aceptar una advertencia de capacidad.
- La libertad administrativa no altera ni falsifica la capacidad calculada.

### 9.2 Asignación automática opcional

Para 1–12 personas:

- Puede activarse **Asignar mesas automáticamente**.
- Utiliza el mismo algoritmo estricto de landing.
- El operador puede desactivarla.

Desde 13 personas:

- La asignación automática queda deshabilitada.
- La reservación puede confirmarse sin mesas.

### 9.3 Resultados posibles

#### A. Capacidad suficiente y asignación automática disponible

Se puede confirmar con las mesas propuestas.

#### B. Capacidad suficiente pero sin combinación automática

Se presenta una decisión:

```text
Hay capacidad estimada para el horario, pero no existe una combinación
automática válida de mesas. Puedes confirmar la reservación y asignar
las mesas después.
```

Acciones:

- Volver.
- Confirmar sin mesas.

#### C. Capacidad insuficiente

No se oculta el resultado. Se presenta una advertencia reforzada con:

- Capacidad real disponible.
- Comensales solicitados.
- Diferencia.
- Consecuencia operativa.
- Indicación de que no se garantiza espacio físico.

La política administrativa puede permitir confirmar bajo responsabilidad. La operación:

- Crea `confirmada`.
- Puede dejar `reservacion_mesas` vacío.
- Registra que requiere asignación manual.
- No modifica el cálculo real mostrado.

### 9.4 Contacto

El contacto es opcional.

Sin contacto:

```text
contacto_tipo = ninguno
contacto = NULL
```

Si una reservación fue creada sin contacto, puede agregarse posteriormente. El flujo normal no cambia el tipo de contacto de una reservación que ya lo tiene.

### 9.5 Listado

El listado compacto muestra:

- Sin mesas.
- 1 mesa.
- 2 mesas.
- 3 mesas.

Los números de mesa, ticket y diferencias entre asignación planificada y ocupación física se muestran sólo en detalle.

---

## 10. Tolerancia vencida y no-show manual

### 10.1 Condición derivada

Una reservación permanece `confirmada` hasta que un operador registre una transición válida.

Se considera ausencia pendiente cuando:

```text
ahora > fecha_hora_reservacion + 15 minutos
AND estado = confirmada
AND no existe ticket abierto vinculado
```

La comparación es inclusiva:

```text
ahora <= fecha_hora + 15 minutos → dentro de tolerancia
ahora > fecha_hora + 15 minutos  → ausencia pendiente
```

### 10.2 Efecto sobre ocupación

Después de vencer la tolerancia y sin ticket abierto:

- La reservación deja de bloquear capacidad y mesas.
- No cambia automáticamente a `no_show`.
- La mesa puede utilizarse para una nueva operación.
- La acción pendiente continúa visible hasta que el operador la resuelva.

### 10.3 Representación visual

Si no existe otro bloqueo físico:

- Fondo verde: mesa disponible.
- Borde o indicador gris: reservación vencida pendiente de registrar ausencia.
- Texto accesible: `Acción pendiente: registrar ausencia`.

No se usa azul para esta condición.

Si existe ticket abierto, el rojo tiene prioridad y no se muestra como ausencia pendiente.

### 10.4 Acción

- `puede_iniciar = false`.
- `puede_marcar_no_show = true`.
- El registro es manual.
- La única acción sobre la reservación vencida es **Registrar ausencia**.

Al confirmar:

```text
confirmada → no_show
```

Se libera la asignación de forma atómica. No se abre automáticamente un walk-in.

---

## 11. Estados

| Estado | Significado | Bloqueo |
|---|---|---|
| `pendiente_verificacion` | Hold esperando OTP | Sí, mientras siga vigente |
| `confirmada` | Reservación aceptada | Sí, salvo ausencia pendiente |
| `en_curso` | Existe ticket abierto vinculado | Por `ticket_mesas` |
| `completada` | Ticket cerrado | No |
| `cancelada` | Cancelación explícita | No |
| `no_show` | Ausencia registrada manualmente | No |
| `expirada` | Hold vencido | No |
| `reemplazada` | Versión sustituida | No |

Transiciones:

```text
pendiente_verificacion
├── confirmada
└── expirada

confirmada
├── en_curso
├── cancelada
├── no_show
└── reemplazada

en_curso
└── completada
```

Estados terminales:

- `completada`
- `cancelada`
- `no_show`
- `expirada`
- `reemplazada`

`en_curso` no cuenta como reservación activa y se representa mediante el ticket.

---

## 12. Modificación y cancelación pública

### 12.1 Modificación

Se permite hasta 30 minutos antes del inicio original.

La original permanece `confirmada`. Se crea una propuesta:

```text
estado = pendiente_verificacion
hold_expires_at = ahora + 15 minutos
reemplaza_reservacion_id = original
origen = landing
```

La propuesta requiere disponibilidad estricta y asignación automática.

Secuencia:

```text
Modificar
→ editar
→ Aceptar
→ Revisa tu cambio
→ Confirmar modificación
```

No se solicita un segundo OTP mientras la sesión pública siga vigente.

La confirmación final es transaccional:

- propuesta `pendiente_verificacion → confirmada`;
- original `confirmada → reemplazada`.

Si vence el hold, la original sigue vigente.

### 12.2 Horario original

El horario original puede conservarse cuando:

- No cambia fecha ni hora.
- Faltan al menos 30 minutos.
- Sigue siendo un horario operativo.
- La nueva cantidad puede asignarse estrictamente.

La anticipación de 40 minutos aplica sólo a una fecha u hora diferente.

### 12.3 Cancelación

Se permite hasta:

```text
fecha_hora_reservacion + 15 minutos
```

Al cancelar:

```text
confirmada → cancelada
```

No existe eliminación física.

---

## 13. Integración con punto de venta

### 13.1 Inicio de servicio

Desde 30 minutos antes puede iniciarse una reservación confirmada.

En una transacción:

1. Validar estado y ventana.
2. Validar todas las mesas.
3. Crear un ticket.
4. Crear todas las relaciones `ticket_mesas`.
5. Vincular `tickets.reservacion_id`.
6. Cambiar reservación a `en_curso`.
7. Actualizar `estado_changed_at`.

Una reservación multimesa inicia de forma atómica. No se permite iniciar un subconjunto.

### 13.2 Selección de mesa con ticket abierto

La prioridad de interacción es:

```text
1. Ticket abierto existente.
2. Reservación confirmada operable.
3. Apertura de ticket walk-in.
```

Cuando una mesa pertenece a un ticket abierto:

- Se muestra el ticket existente.
- No se muestra **Abrir ticket**.
- La acción primaria es **Ver ticket** o **Continuar ticket**.
- Se utiliza el `ticket_id` ya existente.
- Se muestran todas las mesas vinculadas.
- Seleccionar cualquiera de sus mesas resuelve el mismo ticket.
- No se llama al endpoint de creación.

Contrato mínimo de proyección:

```json
{
  "ticket_abierto": {
    "id": 123,
    "mesa_ids": [7, 8],
    "hora_apertura": "14:20:00",
    "reservacion_id": 45
  },
  "puede_abrir_ticket": false,
  "accion_primaria": "VER_TICKET"
}
```

El frontend no deduce esta acción únicamente por el color.

### 13.3 Walk-in con reservación próxima

Entre 60 y 30 minutos antes:

- Se permite continuar sólo después de una advertencia.
- El modal muestra hora, nombre cuando corresponda, comensales, minutos restantes y consecuencia.
- El backend vuelve a validar.

Dentro de 30 minutos, la reservación confirmada bloquea el walk-in conforme a las capacidades canónicas.

### 13.4 Cierre

Cerrar un ticket es transaccional e idempotente.

- Actualiza el ticket existente.
- Si la reservación vinculada está `en_curso`, pasa a `completada`.
- No crea ni reabre tickets.
- Las consultas de abiertos usan simultáneamente `estado = abierto` y `closed_at IS NULL`.

---

## 14. Mapas y representación visual

### 14.1 Componente compartido

POS y gestión de reservaciones reutilizan:

- Mismo mapa.
- Mismas coordenadas.
- Mismo shell.
- Misma toolbar.
- Misma proyección backend.
- Mismos componentes de modal y alerta.

No existen dos motores de disponibilidad.

### 14.2 Bloque actual y bloques futuros

- Bloque actual: fotografía física real.
- Bloques futuros: proyección con liberación estimada.

Para la misma fecha y hora, ambos mapas reciben la misma ocupación canónica. Sólo cambian las acciones disponibles.

### 14.3 Estados visuales

Prioridad:

```text
1. Selección actual → amarillo
2. Ticket abierto → rojo
3. Reservación confirmada o hold aplicable → azul
4. No utilizable → neutro
5. Disponible → verde
```

Excepción:

- Una reservación con tolerancia vencida, sin ticket, deja la mesa verde y añade indicador gris.

Una mesa roja con riesgo de reservación próxima continúa roja; el riesgo se explica mediante texto.

### 14.4 Modo de asignación

Estados de frontend:

- `viewing`
- `assignment_edit`
- `saving`
- `conflict`

Fuera de `assignment_edit`, tocar una mesa no modifica la asignación.

### 14.5 Alertas

Las alertas se superponen sin desplazar el mapa.

Casos mínimos:

- Sin disponibilidad.
- Reservación sin mesas.
- Capacidad insuficiente.
- Ticket abierto.
- Reservación próxima.
- Fecha cerrada.
- Horario inválido.
- Cambio no guardado.
- Conflicto al guardar.
- Proyección actualizada.

---

## 15. Shell de confirmación

Las confirmaciones de landing, administración, mapa y POS utilizan un componente compartido.

### 15.1 Contenido obligatorio

El shell admite:

- Título.
- Descripción.
- Resumen.
- Advertencia.
- Consecuencia.
- Acción primaria.
- Acción secundaria.
- Estado de carga.
- Estado deshabilitado.
- Retorno de foco.

Cada consumidor aporta la causa y la consecuencia. No se acepta un cuerpo genérico con sólo botones.

### 15.2 Dimensiones y legibilidad

Escritorio:

```css
width: clamp(560px, 64vw, 760px);
max-height: calc(100dvh - 32px);
```

Móvil:

```css
width: calc(100vw - 24px);
max-height: calc(100dvh - 24px);
```

Mínimos:

- Texto principal: `16px`.
- Interlineado: `1.45`.
- Título: `22px`.
- Botones: `44px` de alto.
- Padding: `24px` móvil y `32px` escritorio.

El cuerpo puede desplazarse verticalmente. Encabezado y acciones deben permanecer accesibles.

Por debajo de 640 px:

- Comparaciones y resúmenes en columnas se apilan.
- Los botones pueden apilarse cuando no quepan con claridad.

Ningún módulo puede sobrescribir el ancho del shell con medidas locales menores.

### 15.3 Accesibilidad

- `role="dialog"`.
- `aria-modal="true"`.
- Título y descripción asociados.
- Focus trap.
- Escape cierra cuando la acción sea cancelable.
- Foco regresa al disparador.
- El fondo no queda con `inert`, `aria-hidden` o scroll bloqueado después del cierre.

---

## 16. Contrato de errores

### 16.1 Regla

El catálogo completo de errores se obtiene mediante un escaneo del módulo antes de centralizarse. No se inventa una lista basada únicamente en documentación.

El escaneo debe cubrir:

- Controladores.
- Servicios de dominio.
- Modelos y repositorios.
- Serializers.
- Endpoints.
- JavaScript.
- Vistas y modales.
- Pruebas.
- Excepciones SQL y transacciones.

### 16.2 Inventario obligatorio

Cada error detectado debe registrar:

| Campo | Descripción |
|---|---|
| Identificador actual | Código o texto existente |
| Archivo y línea | Origen exacto |
| Capa | Dominio, API, infraestructura o interfaz |
| Condición | Qué lo provoca |
| HTTP | Estado devuelto |
| Mensaje actual | Texto mostrado |
| Consumidores | Landing, admin, mapa o POS |
| Coherencia | Correcto, ambiguo, duplicado o incorrecto |
| Consecuencia real | Qué operación se realizó o revirtió |
| Acción esperada | Qué puede hacer el usuario |
| Código canónico propuesto | Resultado de la normalización |

### 16.3 Categorías

El catálogo final distingue:

- `VALIDATION`
- `AUTHENTICATION`
- `AUTHORIZATION`
- `CONFLICT`
- `CAPACITY`
- `ASSIGNMENT`
- `TICKET`
- `STATE`
- `SECURITY`
- `INFRASTRUCTURE`
- `INTERNAL`

### 16.4 Respuesta

Las respuestas de operación deben separar:

```text
ok
decision_required
error
```

Una falta de asignación automática en administración es `decision_required`, no un error.

Contrato sugerido:

```json
{
  "status": "error",
  "code": "CODIGO_CANONICO",
  "category": "CONFLICT",
  "message": "Mensaje operativo",
  "consequence": "No se realizó ningún cambio.",
  "actions": [
    {"id": "RELOAD", "label": "Actualizar"}
  ],
  "context": {},
  "request_id": "..."
}
```

No se exponen:

- Excepciones PHP.
- SQL.
- Stack traces.
- Flags internos.
- Mensajes técnicos sin traducción.

### 16.5 Códigos ya confirmados

Estos códigos mantienen su separación:

- `SESION_PUBLICA_EXPIRADA`
- `CSRF_INVALIDO`
- `OTP_INCORRECTO`
- `OTP_EXPIRADO`
- `OTP_INTENTOS_AGOTADOS`
- `VERIFICACION_NO_ENCONTRADA`
- `CONTACTO_NO_COINCIDE`
- `TOLERANCIA_LLEGADA_VENCIDA`
- `ERROR_INTERNO`

El resto debe confirmarse durante el escaneo.

### 16.6 Centralización

Después del inventario:

- El backend usa un registro único de errores.
- Los servicios emiten excepciones o resultados tipados.
- Los controladores no redactan mensajes ad hoc.
- El frontend renderiza el contrato recibido.
- Ninguna vista conserva mensajes contradictorios hardcodeados.
- Las pruebas validan código, categoría, mensaje, consecuencia y acciones.

---

## 17. Servicios de dominio

Responsabilidades:

### `HorarioReservacionService`

- Horarios efectivos, excepciones, anticipación, cierre y horizonte.

### `TicketTemporalService`

- Liberación estimada y proyección actual o futura de tickets.

### `OcupacionMesasService` y `MesaEstadoService`

- Tickets, reservaciones, holds, ausencias pendientes, bloqueos por mesa y prevención de doble conteo.

### `CapacidadReservacionesService` y `DisponibilidadReservacionService`

- Capacidad física libre, capacidad real disponible, demanda sin asignar y resultado común para las superficies.

### `AsignacionMesasService` y `ReservacionMapaAdministrativaService`

- Candidatos automáticos, grupos autorizados, capacidad de combinaciones, validación manual y versión de asignación.

### `ReservacionPublicaService` y `ReservacionAdministrativaService`

- Altas, confirmación, modificación, cancelación, reemplazo, confirmación sin mesas y decisiones explícitas de capacidad.

### `ReservacionVigenciaService`

- Tolerancia de llegada, ausencia pendiente, no-show manual y clasificación operativa.

### `PuntoVentaReservacionService`

- Inicio y cierre de servicio, tickets vinculados, transiciones POS e idempotencia operativa.

### `ReservacionService`, `ContactoAccesoService` y `ReservationClientSession`

- Fachada de transiciones históricas delegadas, OTP, intentos, vigencia y sesión pública aislada.

### `PosReservacionQueryService`

- Proyección operativa.
- Ticket abierto.
- Capacidades y acciones por mesa.

### `PosReservacionSerializer`

- Contrato `pos-reservacion.v1`.
- No decide lógica de negocio.

La fachada `ReservacionService`, si se conserva, delega y no implementa un segundo motor.

---

## 18. Transacciones e idempotencia

Se requiere transacción para:

- Crear reservación.
- Confirmar hold.
- Crear modificación.
- Confirmar modificación.
- Cancelar.
- Reasignar mesas.
- Abrir ticket desde reservación.
- Cerrar ticket.
- Registrar no-show.

`request_token` evita doble envío y no sustituye CSRF.

Toda mutación:

1. Valida identidad y CSRF.
2. Abre transacción.
3. Bloquea recursos en orden estable.
4. Recalcula ocupación.
5. Valida estado y versión.
6. Ejecuta el cambio.
7. Confirma o revierte completamente.

---

## 19. Criterios de aceptación

### Disponibilidad y capacidad

1. Todas las superficies reciben la misma ocupación canónica.
2. Landing exige combinación automática válida.
3. Administración puede confirmar sin mesas sin alterar el cálculo real.
4. La capacidad física libre suma asientos de mesas disponibles.
5. Una combinación de mesas con ocho asientos reduce ocho lugares físicos.
6. Una reservación confirmada sin mesas descuenta sus comensales como demanda no asignada.
7. Los tickets actuales no afectan días futuros.
8. Los bloques futuros del mismo día liberan tickets en `hora_apertura + 90 minutos`.
9. El bloque actual mantiene rojo un ticket que continúa abierto.
10. La tolerancia de reservación está incluida en los 90 minutos.

### POS y mapa

11. Seleccionar una mesa con ticket abierto muestra ese ticket.
12. Una mesa con ticket abierto nunca muestra **Abrir ticket**.
13. Un ticket multimesa se resuelve desde cualquiera de sus mesas.
14. POS y mapa reciben la misma proyección.
15. JavaScript no recalcula capacidad o liberación.
16. Una ausencia pendiente deja la mesa verde con indicador gris.
17. El no-show sólo se registra manualmente.
18. Una ausencia pendiente no permite iniciar servicio.

### Administración

19. De 1 a 12 la asignación automática es opcional.
20. Desde 13 queda deshabilitada.
21. Sin combinación automática puede confirmarse sin mesas.
22. Capacidad insuficiente produce advertencia reforzada y no un mensaje engañoso.
23. Una reservación sin mesas queda visible como asignación pendiente.

### Modales y errores

24. Todos los modales utilizan el mismo shell.
25. El modal cumple dimensiones y tipografía mínimas.
26. Cada confirmación explica causa y consecuencia.
27. No existen mensajes técnicos expuestos.
28. El catálogo se basa en el escaneo del código.
29. Un mismo código produce el mismo significado en todas las superficies.
30. `decision_required` no se presenta como error.

### Integridad

31. No existe doble conteo entre reservación `en_curso` y ticket.
32. Holds vencidos dejan de bloquear inmediatamente.
33. Operaciones multimesa son atómicas.
34. No existe eliminación física desde interfaces.
35. `estado_changed_at` cambia sólo al cambiar de estado.

---

## 20. Documentación complementaria

Esta fuente de verdad no contiene planes de etapas ni reportes históricos.

Los documentos complementarios no modifican este contrato:

```text
docs/reservaciones/catalogo_errores.md
docs/reservaciones/historial/
plan_estabilizacion_reservaciones.md
```

Sólo esta fuente de verdad define comportamiento vigente.
