# Arquitectura

Casa Pestalozzi es una aplicación PHP 8 con MVC propio. Las solicitudes entran
por `public/index.php`, pasan por `Router.php` y se atienden con Controllers,
Services, Models y Views. Composer registra `Controllers\\`, `Model\\`,
`Classes\\`, `MVC\\` y `Services\\`.

## Flujo y responsabilidades

```text
HTTP → Router / autenticación → Controller → Service → Model → MySQL
                                      ├→ View (HTML)
                                      └→ Presenter / Serializer → JSON
```

- **Controller:** valida el acceso HTTP, CSRF y la forma básica de la entrada;
  coordina el caso de uso y responde con una View o JSON. Las reglas de dominio
  y las consultas complejas no viven aquí.
- **Service:** ejecuta casos de uso, reglas de negocio y coordinación de
  persistencia e integraciones. Puede llamar a otro Service si la frontera de
  dominio está clara.
- **Model:** representa y persiste datos; no depende de Controller, View ni
  Service.
- **View:** presenta datos preparados por el Controller y no consulta Models,
  base de datos ni Services.
- **Presenter / Serializer:** proyecta hechos ya resueltos al formato de una
  superficie o respuesta. No recalcula reglas temporales ni de disponibilidad.

Dirección habitual: `Controller → Service → Model`; el Controller prepara la
View. Las dependencias inversas deben evitarse. Las integraciones externas se
encapsulan en adaptadores de `Integrations` y no deciden reglas del dominio.

## Dominios de Services

Cada carpeta representa un dominio y cada clase declara el namespace
`Services\\<Dominio>` correspondiente.

| Dominio | Responsabilidad principal |
| --- | --- |
| `Analytics` | Analíticas y recomendaciones |
| `Configuration` | Configuración pública del sitio |
| `Contact` | Contacto y datos de comunicación |
| `Integrations` | Adaptadores a servicios externos |
| `Inventory` | Inventario y recetas |
| `Menu` | Catálogo y reglas del menú |
| `Notifications` | Preparación y orquestación de comunicaciones, contratos y configuración de transporte |
| `Pos` | Casos de uso del punto de venta e impresión |
| `Reservations` | Reglas y operaciones propias del agregado de reservaciones |
| `Scheduling` | Calendarios, reglas de horario y afectaciones de agenda |
| `Security` | Tokens, sesiones y control de acceso |
| `Shared` | Coordinación realmente transversal, como locks compartidos |
| `Tables` | Hechos y proyecciones comunes de mesas |
| `Users` | Acceso y servicios de usuarios |

La estructura física usa un nivel por dominio: `services/Reservations/Clase.php`.
No se crean subcarpetas internas ni Services nuevos directamente en la raíz de
`services/`. `Shared` es una excepción para componentes usados por varios
dominios; no es un cajón para responsabilidades sin clasificar.

Composer mapea `"Services\\": "./services"`; por ejemplo,
`services/Reservations/DisponibilidadReservacionService.php` declara
`namespace Services\Reservations;`.

La carpeta se elige por la responsabilidad principal: que una clase incluya
`Reservation` en el nombre no significa que pertenezca a `Reservations/`.
`Notifications` prepara y coordina las comunicaciones; `Security` administra
tokens, sesiones y accesos; `Scheduling` resuelve horarios y registra sus
afectaciones. `Reservations` conserva las reglas del agregado y sus operaciones.

`HorarioReservacionService` permanece en `Reservations`: además de usar el
calendario operativo, valida reglas de reservación como fechas admisibles,
anticipación y último horario reservable, y expone códigos del servicio de
reservaciones. `HorarioOperacionImpactoService` pertenece a `Scheduling` porque
evalúa y persiste el impacto de cambios de agenda.

El flujo de cambios de horario conserva una colaboración estática entre
`Scheduling` y `Notifications`: el servicio de horario despacha después del
commit, y el servicio de notificaciones consulta y actualiza el impacto
persistido. `HorarioOperacionImpactoService` también usa `Security` para emitir
tokens, mientras que el servicio de acceso valida esos impactos. `Reservations`
llama a `Notifications` para finalizar confirmaciones; `Notifications` consulta
configuración y reglas temporales de `Reservations`. El impacto de agenda usa
configuración y seguimiento operativo de `Reservations`, cuyos casos de uso
también consultan `Scheduling`. Son colaboraciones con llamadas estáticas, sin
ciclo de construcción por inyección; separarlas requiere contratos o cambios de
flujo fuera del alcance de esta redistribución.

## Nuevas clases

Antes de agregar una clase, define el dominio, la capa, la responsabilidad que
representa y si ya existe un componente que debe atenderla. El sufijo describe
el papel: `Service` para casos de uso, `Controller` para HTTP, `Model` para
persistencia, `Presenter` o `Serializer` para proyecciones y `Client` para un
adaptador externo.

Los incumplimientos vigentes que requieren una corrección separada se registran
en [Pendientes arquitectónicos](correcciones_pendientes_arquitectura.md).
