# Línea base de rendimiento D3

Ensayo local sintético, no producción. El contrato de datos es [Arquitectura](../arquitectura.md)
y sus dependencias están en [Modelo de datos](../modelo-datos.md). No ejecutar
`ddl.sql` sobre una base existente ni utilizar el servidor configurado de la
aplicación. La prueba usa un datadir nuevo y privado en `tmp/`, MariaDB en
`127.0.0.1:33077`, base aleatoria con marca de propiedad y fixtures versionadas.
El runner no lee `.env`, valida datadir/puerto y marca antes del DDL, y elimina
solo su propia base tras verificar su token. No muestra datos de clientes ni SQL
con valores. La marca del datadir no sustituye la comprobación del proceso dueño.

## Reproducción en Windows

Se requiere PHP 8.2, dependencias Composer, Git con el commit `2e7d763`, MariaDB
compatible y puerto libre. Comprobar que todos los componentes desde la raíz al
`tmp` no sean reparse points; no reutilizar un directorio ni puerto ajenos. El
siguiente procedimiento crea el directorio, comprueba propiedad del proceso y
mantiene el servidor solo durante la prueba; `data`, marca y logs son privados e
ignorados por Git. Si hay restricción local de sockets, el proceso de prueba
necesita acceso autorizado a ese loopback.

```powershell
$root = (Resolve-Path .).Path
$tmp = Join-Path $root 'tmp'
if (!(Test-Path -LiteralPath $tmp)) { New-Item -ItemType Directory $tmp | Out-Null }
$p = Get-Item -LiteralPath $tmp
while ($p) {
    if ($p.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Ruta con enlace' }
    $p = $p.Parent
}
if (Get-NetTCPConnection -LocalPort 33077 -State Listen -ErrorAction SilentlyContinue) { throw 'Puerto ocupado' }
$dir = Join-Path $tmp ('d3-rendimiento-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory $dir | Out-Null
& C:/xampp/mysql/bin/mysql_install_db.exe "--datadir=$dir/data" --port=33077
if ($LASTEXITCODE -ne 0) { throw 'Inicialización falló' }
[IO.File]::WriteAllText((Join-Path $dir 'd3-owner'), ([guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')))
$argsList = @('--no-defaults','--console','--basedir=C:/xampp/mysql', "--datadir=$dir/data", '--bind-address=127.0.0.1','--port=33077', "--pid-file=$dir/mysqld.pid")
$proc = Start-Process C:/xampp/mysql/bin/mysqld.exe -ArgumentList $argsList -WindowStyle Hidden -RedirectStandardOutput "$dir/stdout.log" -RedirectStandardError "$dir/stderr.log" -PassThru
try {
    for ($i=0; $i -lt 50; $i++) {
        if ($proc.HasExited) { throw 'Servidor terminó' }
        $listener = Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 33077 -State Listen -ErrorAction SilentlyContinue
        if ($listener) { break }
        Start-Sleep -Milliseconds 200
    }
    if (!$listener -or $listener.OwningProcess -ne $proc.Id) { throw 'Servidor no identificado' }
    & php scripts/tests/run-rendimiento-d3.php $dir
    if ($LASTEXITCODE -ne 0) { throw 'Ensayo falló' }
    & C:/xampp/mysql/bin/mysqladmin.exe --no-defaults --protocol=TCP --host=127.0.0.1 --port=33077 --user=root shutdown
} finally {
    if (!$proc.HasExited) { Stop-Process -Id $proc.Id }
}
```

El runner crea una referencia de `FinanzasNegocio` desde `git show 2e7d763`,
renombra solamente su clase en una copia temporal y compara la referencia y el
código actual sobre la misma base. Requiere que esa revisión corresponda a la
base de comparación y que no haya otro proceso usando el puerto. Ante una marca
que no coincida, aborta sin borrar la base. No aplica cambios a la configuración
ni al datadir de XAMPP. El datadir temporal se conserva para inspección; no
eliminar otro directorio por su nombre o por ser temporal.

## Método y límites

En cada medición hay 5 calentamientos y 30 muestras. PHP es tiempo total del
servicio con `hrtime`; SQL es suma de duraciones de `SHOW PROFILES` de la misma
sesión, sin tiempo de transporte. Se verifica continuidad de IDs y se rechaza un
perfil que alcance el límite de 100; se informa el número de sentencias. Los
servicios se ejecutan directamente en CLI con datos sintéticos, no miden HTTP,
renderizado, navegador, hardware, concurrencia ni latencia de red. Las pruebas
versionadas tienen fechas relativas, por lo que sus conteos pueden cambiar entre
días; la comparación base/actual dentro de un ensayo usa exactamente la misma
base. Solo lectura: no hay duración de transacción para esos casos.

PHP 8.2.12, Node 24.12.0, MariaDB 10.4.32; una ejecución con 3.516 tickets,
13.333 partidas y 142 ofertas produjo los siguientes milisegundos p50/p95:

| Servicio medido | PHP | SQL | Sentencias |
|---|---:|---:|---:|
| Catálogo POS y presentaciones | 4,268 / 4,468 | 2,584 / 2,747 | 10 |
| Mapa y disponibilidad de reservaciones | 27,416 / 30,231 | 15,355 / 16,837 | 67 |
| Búsqueda de menú y recetas (`q=cafe`) | 14,901 / 16,893 | 11,742 / 13,568 | 20 |
| Finanzas: agregados de `2e7d763` | 27,229 / 53,881 | 26,470 / 52,921 | 3 |
| Finanzas: agregados actuales | 16,233 / 17,956 | 15,830 / 17,110 | 2 |

Una ejecución anterior con la misma metodología registró para Finanzas base
26,599 / 33,559 ms PHP y actual 16,072 / 19,779 ms; el p95 varió entre ensayos.
La mediana mejoró aproximadamente 40 % en estos servicios y datos, sin acreditar
una mejora de la página completa ni de otras cargas. `EXPLAIN FORMAT=JSON` de
los dos SELECT anteriores y del SELECT combinado capturó acceso `ref` por
`ticket_id` para `ti`; no se atribuye el cambio a un índice. La reducción evita
una consulta y un recorrido repetido del mismo JOIN.

La comparación verificó igualdad exacta entre base y actual en ocho casos de
Restaurante y Panadería, periodos con datos y vacíos, extremos del día, costos
conocidos, nulos y cero, partidas canceladas y tickets abiertos; además comprobó
importes esperados para fronteras y periodos vacíos. No son pruebas de una base
operativa ni de restauración.

**Comanda no medida:** preparar la variante falla con error SQL 1064 en
`PermisosUsuario.php:49` al ejecutar `FOR SHARE` en MariaDB 10.4.32; no se
cuentan resultados parciales como muestras y no hay duración de transacción.
No se cambió el bloqueo ni se evaluó equivalencia de su comportamiento en MySQL
8.0. La evaluación completa de comanda, consumo y cancelación queda pendiente
sobre un motor compatible y una base aislada. Las simulaciones no certifican
integraciones externas ni equipos físicos.

## Regresión financiera adicional

La suite existente `scripts/tests/run-finanzas-ambito-db.php` se ejecutó como
proceso hijo contra la misma base sintética verificada, con configuración de
conexión explícita y sin utilizar credenciales locales. La versión actual y la
clase base de `2e7d763` terminaron con código 1 en la misma aserción: «Panadería
guarda su gasto fijo» (línea 178). La prueba no informa la causa; no se atribuye
al cambio ni a un error SQL concreto. No es un PASS de la suite. Las ocho
comparaciones de agregados anteriores sí finalizaron; no sustituyen esta prueba.

Para repetirla añadir `--finance-suite` al comando del runner. Al fallar la suite
actual, el runner también ejecuta la base, guarda salidas en archivos privados
del runtime y termina con código distinto de cero después de verificar y limpiar
su base. La prueba de Análisis/Asistente completa no se ejecutó.
