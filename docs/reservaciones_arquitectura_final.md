# Arquitectura final de reservaciones

Documento canónico de cierre técnico de Etapa 12. Las reglas de negocio siguen viviendo en `reservaciones_fuente_de_verdad.md`; este documento fija la implementación consolidada y sus fronteras.

## 1. Capas y responsabilidades

```text
HTTP / vistas / JS
        │
        ├── Controllers\ReservacionController
        ├── Controllers\ReservacionOperacionController
        └── Controllers\PuntoVentaController
                │
                ├── ReservacionPublicaService
                ├── ReservacionAdministrativaService
                ├── ReservacionService (fachada estable)
                ├── DisponibilidadReservacionService
                ├── OcupacionMesasService
                ├── AsignacionMesasService
                ├── ReservacionVigenciaService
                ├── PuntoVentaReservacionService
                ├── PosReservacionQueryService
                └── MesaEstadoService / PosReservacionSerializer
                        │
                        └── ActiveRecord + MySQL
```

- Los controladores traducen HTTP, sesión, CSRF y códigos de estado.
- `ReservacionPublicaService` resuelve retención, OTP, creación pública, reemplazo y cancelación pública.
- `ReservacionAdministrativaService` resuelve altas y actualizaciones internas; `ReservacionService` conserva la fachada usada por módulos existentes.
- `DisponibilidadReservacionService`, `OcupacionMesasService` y `AsignacionMesasService` son la única cadena válida para capacidad y mesas.
- `ReservacionVigenciaService` normaliza holds, expiraciones, ventanas y estados derivados.
- `PuntoVentaReservacionService` controla inicio, cancelación, no-show y walk-in con transacciones y locks.
- `PosReservacionQueryService` obtiene el estado operativo; `PosReservacionSerializer` fija `pos-reservacion.v1`; `MesaEstadoService` resuelve precedencia visual sin recalcular capacidad.

## 2. Fuente de datos y contrato de ocupación

La ocupación física se deriva de `tickets` + `ticket_mesas`. La ocupación de reservaciones se calcula desde `reservaciones` + `reservacion_mesas`, considerando confirmadas y holds vigentes. Para el día actual se respeta la ventana física abierta; para fechas futuras se proyecta la ventana operativa. Los tickets abiertos conservan prioridad física en el mapa.

La liberación canónica de un ticket es:

```text
hora_apertura + DURACION_ESTIMADA_TICKET_MINUTOS + RETRASO_ESTIMADO_TICKET_MINUTOS
```

El margen `MARGEN_PREPARACION_MESA_MINUTOS` sólo produce `hora_objetivo_preparacion` y una advertencia informativa; no altera capacidad, bloqueo ni `liberacion_estimada`.

Las combinaciones permitidas de mesas, grupos contiguos y triadas provienen exclusivamente de `ReservacionConfig`. No existen rutas alternativas que inyecten ocupación física.

## 3. Estados y representación visual

Los ocho estados persistidos son `pendiente_verificacion`, `confirmada`, `en_curso`, `completada`, `cancelada`, `no_show`, `expirada` y `reemplazada`. `llego` no es un estado vigente. `no_show` es una transición aditiva de la acción pendiente del POS; no adelanta el inicio ni altera el ticket abierto.

El mapa distingue estado persistido, ocupación derivada y modificadores visuales. Una reserva pendiente puede mostrarse en verde con borde gris; un ticket abierto conserva el estado físico de sus mesas; las reservas reemplazadas quedan fuera de la operación vigente.

## 4. Rutas canónicas

### Público

- `GET /api/reservation-schedules`
- `GET /api/reservaciones/disponibilidad`
- `POST /api/reservaciones/retencion`
- `POST /api/reservaciones/crear`
- `POST /api/reservaciones/modificar`
- `POST /api/reservaciones/confirmar-modificacion`
- `POST /api/reservaciones/cancelar`
- `POST /api/reservaciones/contacto/codigo`
- `POST /api/reservaciones/contacto/verificar`
- `GET /api/reservaciones/mis-reservaciones`
- `POST /api/reservaciones/contacto/logout`

La modificación y cancelación pública requieren la sesión de contacto verificada y CSRF. La modificación no abre un segundo OTP: la misma sesión verificada autoriza el reemplazo y su confirmación.

### Administración y operación

- `GET /admin/reservations`, `/create`, `/show`, `/operation`
- `POST /admin/reservations/create`, `/update`, `/status`, `/reassign`
- `GET /admin/api/reservations/disponibilidad`
- `GET /admin/api/reservations/operation`
- `POST /admin/api/reservations/operation/assign-tables`
- `POST /admin/api/reservations/operation/clear-tables`
- `POST /admin/api/reservations/operation/reassign`
- `POST /admin/api/reservations/operation/update-comment`
- `POST /admin/api/reservations/operation/status`
- `POST /admin/reservations/development-tools/process-expired`

Las escrituras internas requieren sesión de personal y `StaffCsrfService`. Las rutas sin prefijo `/admin/api/reservations/operation/` que duplicaban asignación/comentario fueron retiradas.

### POS

- `GET /api/punto-de-venta/reservaciones`
- `GET /api/punto-de-venta/mesa-contexto`
- `POST /api/punto-de-venta/reservaciones/comenzar`
- `POST /api/punto-de-venta/reservaciones/cancelar`
- `POST /api/punto-de-venta/reservaciones/no-show`
- `POST /api/abrir-ticket`, `POST /api/cerrar-ticket`

El mapa operativo y el POS consumen la misma consulta/serialización `pos-reservacion.v1`. La ruta general de tickets se conserva porque también atiende walk-ins y el flujo general de venta.

## 5. Seguridad y transacciones

CSRF público y CSRF de personal se validan antes de cualquier escritura. Los reemplazos, cancelaciones, inicio de ticket, walk-ins y cambios de mesas se ejecutan bajo transacción; los servicios vuelven a comprobar pertenencia, estado, vigencia y disponibilidad dentro del lock. La idempotencia usa `request_token` y la identidad de contacto usa `ReservationClientSession`.

## 6. Instalación y verificación

La instalación limpia se ejecuta con:

```text
php tests/php/etapa12_instalacion_limpia.php
```

El instalador crea una base temporal `casa_pestalozzi_etapa12_clean_*`, carga `database/ddl.sql` y `database/dml.sql`, ejecuta contratos públicos, administrativos, POS y concurrencia, y elimina la base al finalizar. La suite está diseñada para devolver JSON con `ok`, `dropped` y el detalle de cada bloque incluso si MySQL no está disponible.

La verificación rápida de frontend es:

```text
npm.cmd run test:js
npm.cmd test
npm.cmd run build
```

## 7. Compatibilidad que permanece

Se conserva `GET /api/reservation-schedules` por su consumo activo en el selector público y vistas compartidas. Se conservan las rutas generales `/api/abrir-ticket` y `/api/cerrar-ticket`, y `GET /api/punto-de-venta/mesa-contexto` como consulta informativa. También se conserva la fachada `ReservacionService` para consumidores existentes.

No se conservan aliases de constantes, `reenviarOtpModificacion`, `/api/operacion/horario-efectivo`, `/api/liberar-reservacion`, las dos rutas administrativas legacy ni ramas de ocupación forzada. Las referencias históricas de migración están identificadas como `PRUEBA_DE_MIGRACION` y no forman parte del runtime canónico.
