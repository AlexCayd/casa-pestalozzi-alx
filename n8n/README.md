# Flujos de n8n

Copia versionada de los flujos de n8n que usa Casa Pestalozzi. **La fuente de
verdad en ejecución es n8n, no este directorio**: n8n guarda los flujos en su
propia base (`~/.n8n/database.sqlite`, tabla `workflow_entity`) y los mantiene
cargados en memoria. Estos JSON son el respaldo que viaja con el repo.

| Archivo | Flujo | Qué hace |
|---|---|---|
| `sugerencias.json` | Sugerencias | Venta sugerida del POS: recibe el ticket de la mesa que abre el mesero y devuelve productos rankeados con su argumento de venta. |
| `areas-de-mejora.json` | Areas de mejora | Analiza el feedback de clientes con un modelo y devuelve las áreas de mejora del panel admin. |

## Exportar (después de tocar un flujo en el editor)

```bash
node n8n/exportar.js
```

Lee la base de n8n en solo lectura y reescribe los JSON. También puedes usar
**Download** en el editor de n8n; el formato es el mismo.

## Importar (máquina nueva, o para restaurar)

En el editor: **Workflows → Import from File**. Después de importar hay que:

1. **Reasignar las credenciales.** No están en el JSON (ver abajo): cada nodo
   MySQL/OpenAI queda apuntando a un id de credencial que en otra instalación
   no existe. Hay que volver a elegirlas en cada nodo.
2. **Activar el workflow.** Se importa inactivo, y sin activarlo la URL de
   producción del webhook responde 404.

## Las credenciales no están aquí

Los JSON solo llevan la *referencia* a la credencial (su id y su nombre); el
usuario y la contraseña de MySQL y la API key de OpenAI viven cifrados en la
base de n8n y nunca salen en la exportación. Por eso este directorio se puede
versionar sin filtrar secretos — y por eso una importación limpia no funciona
hasta reasignar credenciales.

## Contrato con la app

El POS llama al webhook de sugerencias en cada apertura de modal. La URL sale
de `includes/.env`:

```
N8N_WEBHOOK_SUGERENCIAS_URL=http://localhost:5678/webhook/sugerencias
N8N_WEBHOOK_AREAS_MEJORA_URL=http://localhost:5678/webhook/areas-mejora
```

**Lo que manda el POS** (`Services\Sugerencias::contexto()`): `ticket_id`,
`mesa`, `comensales`, `hora_apertura`, `minutos_abierto`, `max`, `items[]` y
`excluir[]` (productos que el flujo no debe repetir en esa mesa). Se intenta
POST y, si el webhook está registrado como GET, se reintenta por query string.

**Lo que espera el POS de vuelta**: la etapa (`etapa_comida` / `etapa_nombre`, o
el número 1/2/3) y las sugerencias, ya sea como `sugerencias[]` o como
`sugerencia_principal` + `opciones_respaldo`. De cada una se usa `producto_id`
(obligatorio, contra la tabla `productos`), `argumento_mesero` y el puntaje.
El nombre, precio y área se resuelven en la BD, no se confía en el flujo.

> Los nodos de recomendación deben rankear **sobre `productos`**, que es de
> donde `Services\Sugerencias::resolverProductos()` resuelve el `producto_id`.
> Antes partían de la tabla `menu`, que tenía su propio `AUTO_INCREMENT`: el id
> devuelto no correspondía al mismo platillo y la sugerencia salía cambiada o se
> descartaba en silencio. `menu` ya no existe — se fusionó con `productos`.

Un 200 con cuerpo vacío significa que el flujo reventó antes de llegar a
"Respond to Webhook": revisa las ejecuciones en n8n.
