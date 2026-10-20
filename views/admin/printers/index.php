<?php
/* El interruptor GLOBAL del servicio vive en Configuración > POS: es un ajuste
   del punto de venta y no una propiedad de ninguna estación. Aquí se lee para
   rotular las filas —una impresora activa con el servicio pausado NO imprime—,
   pero no se cambia. */
require_once __DIR__ . '/../partials/_icons.php';

$servicioActivo = !isset($servicioActivo) || $servicioActivo;
$areas = is_array($areas ?? null) ? $areas : [];
$pe = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$etiquetaConexion = ['red' => 'Red', 'windows' => 'Windows'];
?>
<section class="admin-menu admin-page prn">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Impresión</span>
            <h2 class="admin-page__title">Estaciones de impresión</h2>
            <p class="admin-page__subtitle">Administra las impresoras térmicas por red (ESC/POS). Una por área de producción para comandas y una para la cuenta del cliente.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/printers/create">Nueva impresora</a>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo $pe($tipo); ?>">
                <?php echo $pe($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php /* El aviso sólo aparece con el servicio pausado: con todo funcionando
             no hay nada que decir y una barra fija encima de la tabla sería
             cromo. Enlaza a donde está el interruptor, que ya no es esta
             pantalla. */ ?>
    <?php if (!$servicioActivo) : ?>
        <section class="admin-panel prn-pausa" id="printers-service">
            <span class="prn-pausa__icono" aria-hidden="true"><?php echo admin_icon('printers', 20); ?></span>
            <div class="prn-pausa__texto">
                <h3 class="prn-pausa__titulo">El servicio de impresión está pausado</h3>
                <p class="prn-pausa__cuerpo">No se envía nada a las impresoras. Los pedidos se guardan, descuentan inventario y llegan al tablero de producción como siempre. «Imprimir prueba» sigue funcionando: es con lo que se comprueba una estación antes de reanudar.</p>
            </div>
            <a class="admin-btn admin-btn--tinted admin-btn--tinted-ambar prn-pausa__accion" href="/admin/configuracion/pos#pos-impresion-title">
                Reanudar en Configuración
            </a>
        </section>
    <?php endif; ?>

    <?php /* Sin .admin-card: la tarjeta compartida sube 3 px al apuntarla, y la
             tabla entera se movía bajo el cursor mientras se elegía una fila. */ ?>
    <section class="admin-menu__panel admin-panel" id="printers">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Impresoras registradas</h3>
                <p>Total: <?php echo count($impresoras); ?> impresora(s). Cada fila lleva el color de su área, el mismo de su tablero de producción.</p>
            </div>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/printers/create">Agregar</a>
        </div>

        <?php if (empty($impresoras)) : ?>
            <p class="admin-menu__empty admin-empty">No hay impresoras registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table prn-tabla">
                    <?php /* Sin columna de ID: es la clave de la base, no un dato con el
                             que se opere una impresora —se la busca por su nombre y su
                             destino—. El id sigue viajando en el data-row-href de la
                             fila, en el enlace de editar y en los formularios POST.

                             ROL y ÁREA eran dos columnas y ahora son una, «Imprime»: una
                             comanda SIEMPRE tiene área (Impresora::validar) y la cuenta
                             nunca, así que se decían lo mismo dos veces. Y tenían colores
                             que chocaban con los de las áreas —«Comanda» iba en turquesa,
                             que es el color de Café; «Cuenta» en índigo, que es el de
                             Horno—: en esta tabla el color significa una sola cosa, la
                             estación. */ ?>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Imprime</th>
                            <th>Destino</th>
                            <th class="prn-tabla__num">Ancho</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($impresoras as $impresora) : ?>
                            <?php
                            $areaId = $impresora->area_id !== null ? (int) $impresora->area_id : 0;
                            $area = ($impresora->rol !== 'cuenta' && $areaId > 0) ? ($areas[$areaId] ?? null) : null;
                            /* El color del área llega de la base (areas_produccion.color, ya
                               validado como hex en el controlador) y entra como custom
                               property en línea: es dato de negocio, no un token, y el
                               SCSS lo consume sin conocerlo. Sin color —cuenta, área sin
                               color válido— la fila se queda neutra. */
                            $areaColor = $area['color'] ?? null;
                            $areaTinta = ($area['tinta'] ?? 'ink') === 'paper' ? 'var(--admin-paper)' : 'var(--admin-ink)';
                            $nombre = (string) $impresora->nombre;
                            ?>
                            <tr class="prn-fila<?php echo $areaColor ? ' prn-fila--area' : ''; ?>"
                                <?php echo $areaColor ? 'style="--prn-area: ' . $pe($areaColor) . '; --prn-area-tinta: ' . $areaTinta . '"' : ''; ?>
                                data-row-href="/admin/printers/edit?id=<?php echo (int) $impresora->id; ?>">
                                <td><span class="admin-table__cell-main"><?php echo $pe($nombre); ?></span></td>
                                <td>
                                    <?php if ($impresora->rol === 'cuenta') : ?>
                                        <span class="prn-imprime prn-imprime--cuenta">
                                            <?php echo admin_icon('tickets', 14); ?>
                                            Cuenta del cliente
                                        </span>
                                    <?php elseif ($area) : ?>
                                        <?php /* Sin el punto de color de las pills del formulario: aquí
                                                 la pastilla entera ya es del color del área, el punto
                                                 salía invisible y dejaba un hueco a la izquierda. */ ?>
                                        <span class="prn-imprime">
                                            <?php echo $pe($area['nombre']); ?>
                                        </span>
                                        <span class="prn-tabla__sub">Comandas del área</span>
                                    <?php else : ?>
                                        <span class="prn-imprime prn-imprime--sin-area">
                                            <?php echo admin_icon('alerta', 14); ?>
                                            Comanda sin área
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php /* La conexión va neutra a propósito: es un detalle
                                         técnico, no una categoría que se busque de un
                                         vistazo. El destino baja a su propia línea y en
                                         mono, que es una dirección de red. */ ?>
                                <td>
                                    <span class="admin-tag admin-tag--neutral"><?php echo $pe($etiquetaConexion[$impresora->conexion] ?? 'Red'); ?></span>
                                    <span class="prn-tabla__destino admin-num"><?php echo $pe($impresora->destino()); ?></span>
                                </td>
                                <td class="admin-num prn-tabla__num"><?php echo (int) $impresora->ancho; ?> col</td>
                                <?php /* Con el servicio pausado, una impresora activa
                                         no imprime: dejarla rotulada «Activa» sería la
                                         mentira que se va a leer cuando alguien venga a
                                         averiguar por qué no sale el papel. Va en ámbar
                                         —advertencia, no error—.

                                         «Inactiva» va neutra y no en rojo: la apagó
                                         alguien a propósito y la configuración está
                                         bien. El rojo la ponía al nivel de una avería. */ ?>
                                <td>
                                    <?php if ($impresora->activo && !$servicioActivo) : ?>
                                        <span class="admin-badge admin-badge--warning">Pausada</span>
                                    <?php elseif ($impresora->activo) : ?>
                                        <span class="admin-badge admin-badge--success">Activa</span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--neutral admin-badge--outline">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <?php /* Imprimir prueba es LA acción de esta pantalla:
                                                 con ella se comprueba una estación. Va en un
                                                 botón sólido con su palabra, en azul —el tono
                                                 de «información» de la paleta, libre de los de
                                                 editar (ámbar) y eliminar (rojo)—. Sin JS es un
                                                 POST que vuelve con la alerta; impresoras.js lo
                                                 resuelve en su sitio: imprimiendo, enviada o
                                                 falló, y un aviso con el detalle.

                                                 Las cuatro etiquetas viven juntas en la misma
                                                 celda de rejilla y sólo una es visible: el botón
                                                 mide siempre lo que la más larga y no empuja a
                                                 sus vecinos al cambiar de estado. El nombre de
                                                 la impresora va oculto DENTRO de la etiqueta
                                                 visible, así el nombre accesible empieza por lo
                                                 que se lee. */ ?>
                                        <form method="POST" action="/admin/printers/test" class="prn-prueba" data-prn-prueba>
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
                                            <button type="submit" class="prn-prueba__boton" data-estado="listo"
                                                    title="Envía un ticket corto a esta impresora para comprobar que imprime">
                                                <span class="prn-prueba__icono" aria-hidden="true">
                                                    <span class="prn-prueba__capa" data-para="listo"><?php echo admin_icon('imprimir', 16); ?></span>
                                                    <span class="prn-prueba__capa" data-para="enviando"><span class="prn-prueba__giro"></span></span>
                                                    <span class="prn-prueba__capa" data-para="ok"><?php echo admin_icon('check', 16); ?></span>
                                                    <span class="prn-prueba__capa" data-para="error"><?php echo admin_icon('alerta', 16); ?></span>
                                                </span>
                                                <span class="prn-prueba__textos">
                                                    <span class="prn-prueba__capa" data-para="listo">Imprimir prueba<span class="admin-visually-hidden"> en <?php echo $pe($nombre); ?></span></span>
                                                    <span class="prn-prueba__capa" data-para="enviando">Imprimiendo…</span>
                                                    <span class="prn-prueba__capa" data-para="ok">Enviada</span>
                                                    <span class="prn-prueba__capa" data-para="error">No imprimió</span>
                                                </span>
                                            </button>
                                        </form>
                                        <a class="admin-icon-button admin-icon-button--edit"
                                           href="/admin/printers/edit?id=<?php echo (int) $impresora->id; ?>"
                                           title="Editar"
                                           aria-label="Editar <?php echo $pe($nombre); ?>">
                                            <?php echo admin_icon('editar', 17); ?>
                                        </a>
                                        <form method="POST" action="/admin/printers/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar impresora"
                                              data-confirm-title="¿Eliminar «<?php echo $pe($nombre); ?>»?"
                                              data-confirm-require="<?php echo $pe($nombre); ?>"
                                              data-confirm-description="Las comandas que se enviaban a ella dejarán de imprimirse."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $impresora->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger"
                                                    title="Eliminar"
                                                    aria-label="Eliminar <?php echo $pe($nombre); ?>">
                                                <?php echo admin_icon('basura', 17); ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
