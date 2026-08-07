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
$noticeIcon = match ($noticeType) {
    'success' => '✓',
    'warning', 'error' => '!',
    'restricted' => '×',
    default => 'i',
};
$noticeH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<article
    class="operational-global-notice operational-global-notice--<?php echo $noticeH($noticeType); ?>"
    data-operation-global-notice
    role="<?php echo $noticeType === 'error' ? 'alert' : 'status'; ?>"
    <?php echo $noticeHidden ? 'hidden' : ''; ?>
>
    <div class="operational-global-notice__head">
        <span class="operational-global-notice__icon" aria-hidden="true" data-operation-global-notice-icon><?php echo $noticeH($noticeIcon); ?></span>
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
            >&times;</button>
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
