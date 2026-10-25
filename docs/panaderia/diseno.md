# Diseño de contextos · Etapa 1

> **Histórico — antecedente de la migración.** Describe la revisión y aceptación
> de su etapa, no el procedimiento de instalación ni el estado operativo actual.
> Prevalecen el [contrato rector 3E](../arquitectura.md) y la
> [instalación vigente](../desarrollo/instalacion.md). Se conserva por evidencia y enlaces.


## Overview

Global, Restaurante y Panadería son contextos de una misma aplicación. Esta
etapa amplía el panel administrativo existente: conserva su identidad, su
navegación y sus componentes para que cambiar de negocio no exija aprender
otra interfaz.

El objetivo de la pantalla es consultar y orientarse. El negocio activo, la
sección y la condición de demostración deben reconocerse antes de interpretar
las cantidades o los precios. Panadería ofrece únicamente consultas de prueba;
las funciones pendientes se presentan como tales.

La frontera de este documento es la navegación de contextos y sus vistas de
Etapa 1. Los tokens del panel y las reglas generales siguen en
`src/scss/admin/shared/base/_globals.scss` y `CLAUDE.md`.

## Colors

El panel mantiene sus dos anclas neutras: `--admin-ink` (`#0b0b0c`) y
`--admin-paper` (`#fbfbfa`). La rampa de grises y los roles de superficie, texto,
borde y acento provienen del sistema compartido. Los contextos no añaden
paletas por negocio.

Las vistas usan `--admin-text`, `--admin-muted`, `--admin-surface`,
`--admin-surface-soft`, `--admin-border`, `--admin-border-soft` y
`--admin-accent`. El mismo marcado funciona en claro y oscuro mediante
`data-admin-theme`. La preferencia y el control de tema siguen perteneciendo
al layout administrativo.

El aviso de datos de prueba es una superficie neutra con texto explícito. Los
estados del inventario se expresan en palabras: Disponible, Bajo mínimo de
prueba y Sin existencias.

## Typography

La familia de la interfaz es Geist Variable, heredada del panel y servida
localmente. El logotipo conserva su componente y su tipografía propios. Los
contextos no importan las familias de la landing.

- Título de página: `clamp(26px, 3vw, 36px)`, interlineado `1.15`.
- Títulos de sección: `20px`; títulos de negocio o módulo: `16px`.
- Selector: `13px`, con etiqueta de peso `600`.
- Avisos, ingredientes y tablas: `14px`; las columnas numéricas usan
  `font-variant-numeric: tabular-nums`.
- Descripciones: ancho máximo de `72ch` e interlineado de `1.6` o `1.7`, según
  el componente.

## Layout

Se reutiliza `views/admin/layout.php`, con sidebar, topbar y contenido comunes.
El selector aparece al comienzo del contenido y la topbar combina el contexto
activo con el nombre del módulo. La marca del sidebar vuelve al inicio del
contexto actual.

Las pantallas de inicio presentan filas de negocio o módulo con explicación y
acción. Los destinos pendientes de Global incluyen una explicación y una
salida clara. Los espacios entre grupos usan principalmente `16px`, `20px` y
`24px`; el relleno de las superficies de inicio varía entre `18px` y `28px`.

La navegación conserva el drawer móvil y el colapso de escritorio del panel.
Los ajustes específicos de contextos se activan a `700px`:

- El contexto y el módulo de la topbar se distribuyen en dos líneas.
- La etiqueta del selector y sus controles pasan a una disposición vertical.
- Las filas de negocio sitúan su acción debajo de la descripción.
- Inventario reúne cantidad y unidad en una celda y mantiene visible el estado.
- Menú conserva el desplazamiento dentro de la tabla, con una indicación
  visible para consultar las columnas restantes.
- El rendimiento de cada receta pasa debajo de su nombre.

## Elevation & Depth

Las superficies usan `admin-card` y el acabado compartido del panel. Los
contextos no introducen otro vocabulario de sombras. El aviso de prueba se
distingue mediante superficie suave y borde de un píxel.

## Shapes

El selector tiene radio de `8px`, el aviso de prueba de `12px`. Tarjetas,
botones, tabla y navegación conservan las formas de sus componentes
compartidos.

## Components

- **Selector de contexto:** `select` nativo etiquetado, marcado `data-native`
  para conservar sus controles de teclado. Envía un GET a `/admin/contexto`
  mediante el botón Cambiar. Solo incluye contextos autorizados; con una única
  opción queda deshabilitado y no muestra el botón.
- **Sidebar:** enlaces adaptados al contexto, módulo actual con
  `aria-current="page"`, catálogo SVG compartido y alias para las claves de
  navegación nuevas.
- **Módulos pendientes:** texto con `aria-disabled="true"`, sin enlace y con
  el rótulo Pendiente. No reciben el hover ni el desplazamiento de icono de
  una opción disponible.
- **Datos de prueba:** aviso antes del contenido, con alcance de solo consulta
  y explicación de cantidades y precios ilustrativos.
- **Tablas:** `admin-table` dentro de `admin-table-wrap`, caption, encabezados
  por columna y fila, y región enfocable para el desplazamiento con teclado.
- **Recetas:** `details` y `summary` nativos, abiertos inicialmente, con
  rendimiento, explicación e ingredientes. La referencia a preparación
  intermedia enlaza al identificador de la receta correspondiente.
- **Estados pendientes y acceso denegado:** mensajes concretos con una acción
  para volver al espacio disponible.

## Do's and Don'ts

- Reutilizar tokens, iconos y componentes del panel al ampliar estos espacios.
- Identificar siempre el contexto junto al módulo y marcar claramente la
  información ilustrativa.
- Conservar cantidad, unidad y estado visibles en el inventario móvil.
- Mantener los controles de demostración en consulta y los destinos pendientes
  sin enlaces operativos.
- Evitar introducir la paleta o tipografías de la landing en estas vistas.
- No presentar navegación visible como prueba de autorización: la frontera de
  acceso sigue siendo el servidor.

La revisión `ux-ui` aprobó los archivos y las capturas examinados en escritorio,
tablet y móvil. Su alcance es experiencia y consistencia visual; la
autorización y la operación del POS se validan por separado.
