<?php
/**
 * Shell operativo compartido por el mapa de mesas y el mapa de reservaciones.
 *
 * El header y el contenedor principal tienen la misma estructura en ambos
 * modulos. Cada consumidor solo entrega sus slots de contenido y sus clases
 * de contexto; la logica del mapa permanece en su adaptador JavaScript.
 */
$operationalShellClass = trim((string)($operationalShellClass ?? 'operation-shell'));
$operationalMainClass = trim((string)($operationalMainClass ?? 'operation-main operational-layout'));
$operationalMainId = trim((string)($operationalMainId ?? ''));
$operationalMainAttributes = is_array($operationalMainAttributes ?? null) ? $operationalMainAttributes : [];
$operationalContentHtml = (string)($operationalContentHtml ?? '');
$operationalShellH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<div class="operational-shell <?php echo $operationalShellH($operationalShellClass); ?>">
    <main
        class="operational-main <?php echo $operationalShellH($operationalMainClass); ?>"
        <?php echo $operationalMainId !== '' ? 'id="' . $operationalShellH($operationalMainId) . '"' : ''; ?>
        <?php foreach ($operationalMainAttributes as $attribute => $attributeValue) : ?>
            <?php
            $attribute = strtolower(trim((string)$attribute));
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $attribute)) {
                continue;
            }
            ?>
            <?php echo $operationalShellH($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $operationalShellH($attributeValue) . '"'; ?>
        <?php endforeach; ?>
    >
        <?php include __DIR__ . '/header.php'; ?>
        <?php echo $operationalContentHtml; ?>
    </main>
</div>
<?php unset($operationalShellClass, $operationalMainClass, $operationalMainId, $operationalMainAttributes, $operationalContentHtml, $operationalShellH); ?>
