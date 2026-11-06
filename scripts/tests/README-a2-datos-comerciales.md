# A2: sincronización comercial por oferta

El servicio interno `Services\\Menu\\DatosComercialesOferta` materializa los valores efectivos de `productos_oferta` en `oferta_datos_comerciales`. No abre transacciones: cada escritor autorizado lo invoca dentro de su transacción o savepoint para que un error de sincronización revierta toda la operación.

Aplicar primero A1. La integración A2 carga el esquema y fixtures en una base temporal propia, aplica A1 y ejercita las rutas de escritura; requiere una conexión `development` o `test` con permiso para crear y eliminar esa base:

```powershell
npm run test:menu:a2:db -- --host HOST --base BASE --entorno development
```

Usa `--entorno test` si `APP_ENV=test`. `ImportadorMenu` solo se usa durante la importación inicial protegida de fixtures; las altas y actualizaciones operativas pasan por los escritores cubiertos por A2. El import inicial queda cubierto por el backfill A1 antes de habilitar el código A2.
