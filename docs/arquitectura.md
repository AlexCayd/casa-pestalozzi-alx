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
| `Notifications` | Configuración y herramientas transversales de transporte |
| `Pos` | Casos de uso del punto de venta e impresión |
| `Reservations` | Reservaciones, capacidad y sus reglas |
| `Scheduling` | Horario de operación del restaurante |
| `Security` | Servicios transversales de seguridad |
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

## Nuevas clases

Antes de agregar una clase, define el dominio, la capa, la responsabilidad que
representa y si ya existe un componente que debe atenderla. El sufijo describe
el papel: `Service` para casos de uso, `Controller` para HTTP, `Model` para
persistencia, `Presenter` o `Serializer` para proyecciones y `Client` para un
adaptador externo.

Los incumplimientos vigentes que requieren una corrección separada se registran
en [Pendientes arquitectónicos](correcciones_pendientes_arquitectura.md).
