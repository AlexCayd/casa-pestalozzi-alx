<?php
/**
 * Superficie flotante única para avisos del módulo operativo.
 *
 * @var array{type?: string, title?: string, summary?: string, message?: string, hidden?: bool} $operationalGlobalNotice
 */
$notice = is_array($operationalGlobalNotice ?? null) ? $operationalGlobalNotice : [];
$noticeType = in_array(($notice['type'] ?? ''), ['info', 'warning', 'success', 'error', 'restricted'], true)
    ? (string)$notice['type']
    : 'info';
$noticeTitle = (string)($notice['title'] ?? '');
$noticeSummary = trim((string)($notice['summary'] ?? ''));
$noticeMessage = trim((string)($notice['mensaje'] ?? ''));
$noticeSummary = $noticeSummary !== '' ? $noticeSummary : 'Consulta este aviso operativo.';
$noticeMessage = $noticeMessage !== ''
    ? $noticeMessage
    : 'Revisa el contexto mostrado y continúa con una opción disponible.';
$noticeHidden = (bool)($notice['hidden'] ?? ($noticeTitle === ''));

/*
 * El icono, en SVG y desde el catálogo compartido.
 *
 * Eran cuatro caracteres —✓ ! × i— que la fuente del sistema pintaba a su
 * manera: distinto tamaño y grosor en cada plataforma y sin heredar el color
 * del aviso, así que los cuatro tipos no se leían como un mismo juego.
 *
 * El mapa tiene que coincidir con el de operation.js (función que repinta el
 * aviso al vuelo): si divergen, el primer render y el siguiente enseñarían
 * iconos distintos para el mismo estado.
 */
require_once __DIR__ . '/../../admin/partials/_icons.php';
$noticeIcon = admin_icon(match ($noticeType) {
    'success' => 'check',
    'warning', 'error' => 'alerta',
    'restricted' => 'cerrar',
    default => 'info',
}, 16);
$noticeH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<article
    class="operational-global-notice operational-global-notice--<?php echo $noticeH($noticeType); ?>"
    data-operation-global-notice
    role="<?php echo $noticeType === 'error' ? 'alert' : 'status'; ?>"
    <?php echo $noticeHidden ? 'hidden' : ''; ?>
>
    <div class="operational-global-notice__head">
        <?php /* Sin escapar: es marcado SVG que arma admin_icon() a partir de
                 un catálogo cerrado, no texto de usuario. */ ?>
        <span class="operational-global-notice__icon" aria-hidden="true" data-operation-global-notice-icon><?php echo $noticeIcon; ?></span>
        <span class="operational-global-notice__copy">
            <strong data-operation-global-notice-title><?php echo $noticeH($noticeTitle); ?></strong>
            <span data-operation-global-notice-summary><?php echo $noticeH($noticeSummary); ?></span>
        </span>
        <span class="operational-global-notice__controls">
            <button
                type="button"
                class="operational-global-notice__expand"
                aria-expanded="false"
                aria-controls="operation-global-notice-detail"
                data-operation-global-notice-expand
            >Expandir</button>
            <button
                type="button"
                class="operational-global-notice__close"
                aria-label="Cerrar aviso"
                data-operation-global-notice-close
            ><?php /* Misma aspa que el icono del aviso: el catálogo ya está
                      requerido arriba. */ ?><?php echo admin_icon('cerrar', 16); ?></button>
        </span>
    </div>
    <div
        class="operational-global-notice__detail"
        id="operation-global-notice-detail"
        aria-hidden="true"
        data-operation-global-notice-detail
    >
        <p data-operation-global-notice-message><?php echo $noticeH($noticeMessage); ?></p>
    </div>
</article>
