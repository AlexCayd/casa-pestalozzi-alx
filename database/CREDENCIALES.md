# Credenciales de prueba (usuarios registrados en la DB)

Usuarios demo que crea `dml.sql`. Hay dos accesos distintos:

- **Admin** → `/admin/login` con usuario + contraseña alfanumérica.
- **Personal de piso** (meseros/cajeros/observador) → `/login` con NIP numérico.

| Usuario           | Nombre               | Rol           | Acceso         | Credencial            | Activo |
| ----------------- | -------------------- | ------------- | -------------- | --------------------- | ------ |
| `admin_demo`      | Administrador Demo   | Administrador | `/admin/login` | Pass: `Pestalozzi2026` | ✅     |
| `observador1`     | Observador General   | Observador    | `/login`       | NIP: `5678`           | ✅     |
| `mesero1`         | Carlos Hernández     | Mesero        | `/login`       | NIP: `2345`           | ✅     |
| `cajero1`         | Mariana López        | Cajero        | `/login`       | NIP: `3456`           | ✅     |
| `mesero_inactivo` | Daniel Torres        | Mesero        | —              | Sin acceso (inactivo) | ❌     |
