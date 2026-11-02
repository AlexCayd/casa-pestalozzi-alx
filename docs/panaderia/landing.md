# Landing de la Panadería

`/panaderia` es la landing pública de la panadería de Calle San Borja 1026
(tel. 55 1229 9648). La portada del restaurante (`/`) está en José Enrique
Pestalozzi 1250: son **dos casas en dos calles**, y cada una firma con sus datos.

Secciones, en este orden y con su tono: portada (crema) · vitrina (beige) ·
temporada (verde) · encargos (oro) · el restaurante (beige) · visítanos (verde)
· pie. Hoja y JS propios: `panaderia.css` (~69 KB) y `panaderia.min.js`
(~27 KB), frente a los ~233 y ~179 KB de la portada.
`PaletaLanding::estiloEnLinea()` va después de su hoja, como en toda página de
marca.

## Tres capas

Cada archivo del sitio público pertenece a una capa, y su ruta lo dice:

| Capa | Dónde vive | Qué es |
|---|---|---|
| **Panadería** | `controllers/Panaderia/`, `services/Panaderia/`, `views/panaderia/`, `src/scss/panaderia/`, `src/js/panaderia/`, `assets/images/panaderia/`, variables `PANADERIA_*` | sólo existe por la panadería; para quitarla se borran esas carpetas |
| **Restaurante** | `HomeController`, `SitioConfig` (dirección, teléfono, mapa), `views/home/`, `src/scss/components/`, el resto de `src/js/modules/` | la portada |
| **Marca** | `views/components/marca/` (nav, pie, visor, ubicacion, redes, cursor, medallon), `views/templates/header-casa-pestalozzi.php`, `src/scss/shared/marca/`, `src/scss/layout/` (salvo `_rail`), `Carta`, `PaletaLanding`, `SitioConfig::url()` e `instagramUrl()` | lo que usan las dos |

**La regla: panadería y restaurante usan la marca, nunca la una a la otra.**
`scripts/tests/run-frontera-panaderia.php` lo vigila (ignora comentarios): la
panadería no incluye `views/home/`, no hace `@use` de `components/` ni lee la
dirección, el teléfono o el mapa de `SitioConfig`; la portada no lee
`PanaderiaConfig::`; y un componente de marca no lee la configuración de
ninguna de las dos casas — **recibe sus datos por parámetro**, porque un pie que
leyera `SitioConfig` por dentro firmaría la panadería con «Pestalozzi 1250». Lo
que vayan a usar las dos se mueve a marca la primera vez que se comparte; no se
copia.

- Los componentes de marca (`views/components/marca/*.php`) declaran sus
  parámetros en la cabecera y los liberan con `unset()` al final. La portada
  los usa desde sus adaptadores, que conservan nombre y lógica:
  `views/home/_nav.php` (destinos, ficha con el horario de hoy y el `.rail`,
  que es sólo de la portada), `_footer.php` (columnas, horario con
  excepciones, visor y panel de tweaks) y `_ubicacion.php` (datos y calles del
  plano).
- **Los módulos de JS no cambian de carpeta**: el bundle del restaurante se
  concatena por orden alfabético de ruta, y la frontera la hace la lista
  explícita `paths.panaderiaJs` de `gulpfile.js` —la declaración de qué toma la
  panadería de la marca: `app.js` (su `boot()` sale solo fuera de la portada),
  Lenis, cursor, imán, menú, visor, revelados, negativo de la marca y
  `pestanas.js`—. `src/js/panaderia/` está excluido del bundle general. Si la
  panadería necesita otro módulo de `modules/`, se añade a esa lista.
- `modules/pestanas.js` (`initPestanasImpresas(raiz, lista)`) es de marca:
  pestañas WAI-ARIA sobre secciones impresas en el servidor. Lo usan la carta
  de maridaje (`maridaje.js`) y la vitrina. Un panel con `display: grid`/`flex`
  necesita `&[hidden] { display: none; }`, o salen todos a la vez.
- El adelanto de la portada (`views/home/_adelanto-panaderia.php` +
  `components/_adelanto-panaderia.scss`) es del **restaurante**: lleva
  «Conocer la panadería» (`/panaderia`) y «Cómo llegar»
  (`/panaderia#visitanos`). No abre ningún mapa: abría el del restaurante como
  si fuera el de la panadería.

## Datos

- **`PanaderiaConfig`**: dirección, teléfono, `mapsUrl()`, `whatsappUrl()`,
  `HORARIO`, `ENCARGOS` y `EN_LA_CARTA`, con variables `PANADERIA_*` y respaldo
  ([configuración](../config.md)). ⚠️ El horario es **de ejemplo** (sólo se
  sabe que cierra a las 7 p.m.), igual que las anticipaciones de los encargos.
- **Vitrina**: la carta de comida del negocio Panadería («Menú de Panadería»,
  unidad `panaderia`), que se administra en `/admin/panaderia/menu`. La lee
  `PanaderiaCatalogo::vitrina()` con `Carta::publicaDe('panaderia')`:
  una pestaña por categoría con piezas, con la foto de la categoría o
  `panaderia-vitrina.webp`. Sin piezas —o con el esquema legado, que no tiene
  menú de la Panadería— sale una línea y WhatsApp. En el formulario de
  categoría de la Panadería, la imagen de una categoría de su carta de comida
  se explica como «la foto de esta sección en la vitrina».
- **Temporada**: `Services\Panaderia\Temporadas::PANES`, cinco panes **de
  ejemplo** con fechas mes-día que se repiten cada año (la rosca cruza el año y
  se dibuja en dos tramos). La tarjeta enseña la de ahora o, si no hay, la
  siguiente.
- **El restaurante**: los platillos de `EN_LA_CARTA` que siguen en la carta
  pública del Restaurante (`PanaderiaCatalogo::enLaCarta()`, por nombre) y
  botones a `/` y `/#reserva`. No escribe la dirección del restaurante: la
  panadería no lee `SitioConfig`.
- **Visítanos**: el componente de ubicación con un plano SVG propio (San Borja,
  Pestalozzi y División del Norte en aspa en la puerta). El cruce está en el
  centro del `viewBox` y el SVG cubre la lámina con `slice`, así que el pin
  (`ancla: 'punto'`, en 50 %/50 %) cae en la esquina mida lo que mida la
  lámina; los rótulos se quedan lejos de los bordes porque `slice` recorta.
- **SEO**: JSON-LD `Bakery` con `parentOrganization` → `/#restaurante`;
  `/panaderia` está en el sitemap.

## Rutas

`/panaderia` → `Controllers\Panaderia\PanaderiaController::index`. Las
subrutas que reservó el andamio anterior redirigen (302) a su sección:
`/carta` → `#vitrina`, `/pedidos` → `#encargos`, `/nosotros` → `#portada` y
`/contacto` → `#visitanos`. Pública: no cuelga de `/admin` ni de `/api`.

## Build y pruebas

```sh
npx gulp panaderiaCss panaderiaJs
php scripts/tests/run-panaderia.php
php scripts/tests/run-frontera-panaderia.php
```

Todo cambio visual pasa por el subagente `ux-ui` (ver `CLAUDE.md`).
