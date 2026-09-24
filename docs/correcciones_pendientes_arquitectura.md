# Pendientes arquitectónicos

## ARQ-001 · Model de usuario depende de un Service

**Estado:** pendiente
**Problema:** `Model\Usuario::porNip()` llama a `NipService::lookup()`, lo que invierte la dirección de capas.
**Archivos:** `models/Usuario.php`, `services/Users/NipService.php`.
**Impacto:** persistencia y autenticación quedan acopladas; corregirlo requiere separar la consulta del NIP del Model.

## ARQ-003 · Views llaman directamente a `HorarioOperacionService`

**Estado:** pendiente
**Problema:** las vistas del pie y del formulario público preparan excepciones semanales mediante un Service.
**Archivos:** `views/home/_footer.php`, `views/home/_reserva.php`.
**Impacto:** la presentación depende de autoload y lógica de Services; el Controller debería entregar los datos listos.

## ARQ-004 · Scheduling depende de `Reservations` para normalizar horas

**Estado:** pendiente
**Problema:** `HorarioOperacionService::horaComparable()` usa `HorarioReservacionService::normalizarHoraSql()`.
**Archivos:** `services/Scheduling/HorarioOperacionService.php`, `services/Reservations/HorarioReservacionService.php`.
**Impacto:** comparar una hora operativa requiere una clase de otro dominio.

## ARQ-005 · Dependencia circular entre horario e impactos de reservaciones

**Estado:** pendiente
**Problema:** `HorarioOperacionService` delega impactos a `HorarioOperacionImpactoService`; éste vuelve a consultar `HorarioOperacionService::estaAbierto()`.
**Archivos:** `services/Scheduling/HorarioOperacionService.php`, `services/Reservations/HorarioOperacionImpactoService.php`.
**Impacto:** los dos dominios no se prueban ni evolucionan de forma independiente con facilidad.
