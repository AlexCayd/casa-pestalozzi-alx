<?php
/**
 * La lista de tickets: cabecera con el recuento, tabla y paginación.
 *
 * Es el único bloque que cambia al elegir una tarjeta de la franja, así que se
 * sirve también suelto: AdminController::tickets() lo devuelve sin layout
 * cuando la petición llega de tickets.js (X-Requested-With). Por eso no puede
 * depender de nada que defina index.php: recibe sus datos del controlador y
 * arma aquí sus propios enlaces.
 */
require_once __DIR__ . '/../partials/_icons.php';

$rango = is_array($rango ?? null) ? $rango : [];
$estado = (string) ($estado ?? '');
$ticketRows = is_array($ticketRows ?? null) ? $ticketRows : [];
$totalFiltrado = (int) ($totalFiltrado ?? 0);
$paginaActual = max(1, (int) ($paginaActual ?? 1));
$totalPaginas = max(1, (int) ($totalPaginas ?? 1));
$porPagina = max(1, (int) ($porPagina ?? 20));

$le = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$lDinero = static fn ($v): string => '$' . number_format((float) $v, 2);

$lEstados = [
    'abierto' => ['label' => 'Abierto', 'badge' => 'admin-badge--warning'],
    'cerrado' => ['label' => 'Cerrado', 'badge' => 'admin-badge--success'],
    'cancelado' => ['label' => 'Cancelado', 'badge' => 'admin-badge--danger'],
];
$lMetodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'dividido' => 'Dividido'];

$lTitulos = [
    '' => 'Todos los tickets',
    'abierto' => 'Tickets abiertos',
    'cerrado' => 'Tickets cerrados',
    'cancelado' => 'Tickets cancelados',
];
$lVacios = [
    '' => 'No hay tickets en este periodo. Prueba con uno más largo en el selector de arriba.',
    'abierto' => 'No hay cuentas abiertas: todo está cobrado.',
    'cerrado' => 'Ningún ticket se cerró en este periodo.',
    'cancelado' => 'Ningún ticket se canceló en este periodo.',
];

// El periodo de los enlaces: el mismo que el de la página, para que paginar o
// quitar el filtro no lo pierda.
$lPeriodo = ((int) ($rango['preset'] ?? 0)) > 0
    ? ['rango' => (int) $rango['preset']]
    : ['desde' => (string) ($rango['start'] ?? ''), 'hasta' => (string) ($rango['end'] ?? '')];
$lUrl = static function (string $filtro, int $pagina = 1) use ($lPeriodo): string {
    $params = $lPeriodo;
    if ($filtro !== '') {
        $params['estado'] = $filtro;
    }
    if ($pagina > 1) {
        $params['page'] = $pagina;
    }

    return '/admin/tickets?' . http_build_query($params);
};

$lDesde = $totalFiltrado === 0 ? 0 : (($paginaActual - 1) * $porPagina) + 1;
$lHasta = min($paginaActual * $porPagina, $totalFiltrado);
$lCuantos = number_format($totalFiltrado) . ($totalFiltrado === 1 ? ' ticket' : ' tickets');
$lFecha = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';

if ($estado === 'abierto') {
    // Abiertos no mira el periodo (ver HistorialTickets): hay que decirlo,
    // o un ticket de hace dos semanas en la lista parecería un error.
    $lResumen = $lCuantos . ' sin cobrar, de cualquier fecha: los abiertos no dependen del periodo.';
} else {
    $lResumen = $lCuantos . ' del ' . $lFecha((string) ($rango['start'] ?? '')) . ' al ' . $lFecha((string) ($rango['end'] ?? '')) . '.';
}
if ($totalFiltrado > $porPagina) {
    $lResumen .= ' Mostrando ' . $lDesde . '–' . $lHasta . ', del más reciente al más antiguo.';
}
?>
<div class="tk-panel__cabeza">
    <div class="tk-panel__intro">
        <h3 class="tk-panel__titulo" id="tk-lista-titulo" tabindex="-1"><?php echo $le($lTitulos[$estado] ?? $lTitulos['']); ?></h3>
        <p class="tk-panel__resumen" data-tk-resumen><?php echo $le($lResumen); ?></p>
    </div>
    <?php if ($estado !== '') : ?>
        <a class="admin-btn admin-btn--ghost admin-btn--small tk-panel__quitar" href="<?php echo $le($lUrl('')); ?>" data-tk-estado="">
            <?php echo admin_icon('cerrar', 14, 'admin-btn__icon'); ?>
            Quitar filtro
        </a>
    <?php endif; ?>
</div>

<?php if (empty($ticketRows)) : ?>
    <p class="admin-empty tk-vacio"><?php echo $le($lVacios[$estado] ?? $lVacios['']); ?></p>
<?php else : ?>
    <div class="admin-table-wrap">
        <table class="admin-table tk-tabla">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Mesa</th>
                    <th>Cliente</th>
                    <th class="tk-tabla__num">Comensales</th>
                    <th class="tk-tabla__num">Items</th>
                    <th class="tk-tabla__num">Total</th>
                    <th class="tk-tabla__num">Propina</th>
                    <th>Pago</th>
                    <th>Estado</th>
                    <th>Apertura</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ticketRows as $t) : ?>
                    <?php
                    $lEstado = (string) ($t['estado'] ?? 'abierto');
                    $lInfo = $lEstados[$lEstado] ?? ['label' => ucfirst($lEstado), 'badge' => 'admin-badge--neutral'];
                    $lMesa = $t['mesa_nombre'] ?: ('Mesa ' . ($t['mesa_numero'] ?? '—'));
                    $lPropina = (float) ($t['propina'] ?? 0);
                    $lTotal = (float) $t['total'];
                    $lPct = ($lPropina > 0 && $lTotal > 0) ? ($lPropina / $lTotal * 100) : 0;
                    $lDiasAbierto = (int) ($t['dias_abierto'] ?? 0);
                    ?>
                    <tr>
                        <td class="admin-num tk-tabla__folio">T-<?php echo str_pad((string) $t['id'], 4, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo $le($lMesa); ?></td>
                        <td><?php echo $le($t['nombre'] ?: '—'); ?></td>
                        <td class="admin-num tk-tabla__num"><?php echo (int) $t['comensales']; ?></td>
                        <td class="admin-num tk-tabla__num"><?php echo (int) $t['num_items']; ?></td>
                        <td class="admin-num tk-tabla__num tk-tabla__total"><?php echo $lDinero($lTotal); ?></td>
                        <td class="admin-num tk-tabla__num">
                            <?php if ($lPropina > 0) : ?>
                                <span class="admin-tk__tip"><?php echo $lDinero($lPropina); ?></span>
                                <span class="admin-tk__tip-pct"><?php echo number_format($lPct, 1); ?>%</span>
                            <?php else : ?>
                                <span class="tk-tabla__nada">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $le(!empty($t['metodo_pago']) ? ($lMetodos[$t['metodo_pago']] ?? '—') : '—'); ?></td>
                        <td><span class="admin-badge <?php echo $lInfo['badge']; ?>"><?php echo $le($lInfo['label']); ?></span></td>
                        <td>
                            <span class="tk-tabla__fecha">
                                <?php echo $t['hora_apertura'] ? $le(date('d/m/Y H:i', strtotime($t['hora_apertura']))) : '—'; ?>
                            </span>
                            <?php /* Un abierto de otro día es casi siempre un cobro
                                     olvidado: es lo único de la fila que pide
                                     hacer algo, y por eso es lo único en ámbar. */ ?>
                            <?php if ($lEstado === 'abierto' && $lDiasAbierto > 0) : ?>
                                <span class="tk-tabla__antiguedad">
                                    <?php echo admin_icon('alerta', 12); ?>
                                    <?php echo $lDiasAbierto === 1 ? 'Abierto desde ayer' : 'Abierto hace ' . $lDiasAbierto . ' días'; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $pagPagina = $paginaActual;
    $pagTotal = $totalPaginas;
    $pagUrl = static fn (int $p): string => $lUrl($estado, $p);
    $pagEtiqueta = 'Paginación de tickets';
    // Sin data-reactive-page: la paginación la intercepta tickets.js (no hay
    // andamiaje de filtros reactivos en esta vista) y sin JS navega normal.
    include __DIR__ . '/../partials/_pagination.php';
    ?>
<?php endif; ?>
