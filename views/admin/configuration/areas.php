<?php
/**
 * Configuración → Colores de las áreas.
 *
 * Una fila por estación de producción con los diez colores de la paleta
 * funcional como radios. El radio es real (recortado, como en .admin-pills):
 * conserva el envío, las flechas dentro del grupo y el estado tras un POST
 * fallido sin una línea de JS. La vista previa de cada fila (la barra del
 * tablero) la pinta el CSS con :has(); configuration.js sólo marca, mientras se
 * elige, los colores que ya tiene otra estación.
 *
 * Ningún hex en el marcado: las muestras piden el token (--admin-c-*), y el
 * color que se guarda lo decide AreasProduccion a partir de la clave.
 */
require_once __DIR__ . '/../partials/_icons.php';

$areas = is_array($areas ?? null) ? $areas : [];
$elegidos = is_array($elegidos ?? null) ? $elegidos : [];
$paleta = is_array($paleta ?? null) ? $paleta : \Services\Configuration\AreasProduccion::PALETA;
$deEspera = is_array($deEspera ?? null) ? $deEspera : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Qué estación tiene cada color ahora mismo, para marcar en cada fila los que
// ya están tomados por otra.
$duenoDe = [];
foreach ($elegidos as $slug => $clave) {
    if ($clave !== null && isset($areas[$slug])) {
        $duenoDe[$clave][] = $slug;
    }
}
$nombresDeEspera = array_map(static fn (string $c): string => 'el ' . mb_strtolower($paleta[$c]['nombre'] ?? $c), $deEspera);
// Los colores del semáforo de las bandas (Enviados, En preparación, Listos).
$nombresDeEstado = array_map(
    static fn (string $c): string => 'el ' . mb_strtolower($paleta[$c]['nombre'] ?? $c),
    \Services\Configuration\AreasProduccion::DE_ESTADO
);
// «el rojo, el ámbar y el verde»
$enumerar = static function (array $nombres): string {
    $ultimo = array_pop($nombres);
    return $nombres === [] ? (string) $ultimo : implode(', ', $nombres) . ' y ' . $ultimo;
};
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="areas">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Colores de las áreas</h1>
            <p class="admin-page__subtitle">Cada estación de producción tiene un color: llena la barra de su tablero y la identifica en el selector de estación, en Impresoras y en el POS. Se elige entre los colores del sistema, y dos estaciones no pueden repetirlo.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/configuracion">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="areas-form-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="areas-form-title">Estaciones</h2>
                <p>Cada tablero toma su color nuevo la próxima vez que se abre o se recarga.</p>
            </div>
        </div>

        <form class="admin-area-colors" method="POST" action="/admin/configuracion/areas" data-area-colors>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

            <div class="admin-area-colors__list">
                <?php foreach ($areas as $slug => $area) : ?>
                    <?php
                    $elegido = $elegidos[$slug] ?? null;
                    $idBase = 'area-' . $slug;
                    // El color con el que nace la vista previa. Si la base trae
                    // uno fuera de la paleta, la fila lo enseña tal cual (es un
                    // dato, no un literal del marcado) y ningún radio sale
                    // marcado: hay que elegir uno para poder guardar.
                    $inicial = $elegido !== null
                        ? 'var(--admin-c-' . $elegido . ')'
                        : (string) $area['color'];
                    // La tinta del contador relleno, del mismo color que arranca.
                    $tintaInicial = ($elegido !== null ? ($paleta[$elegido]['tinta'] ?? null) : null)
                        ?? ($area['tinta'] ?? 'ink');
                    $tintaInicial = $tintaInicial === 'paper' ? 'paper' : 'ink';
                    ?>
                    <fieldset class="admin-area-color" data-area-color="<?php echo $h($slug); ?>" style="--area-inicial: <?php echo $h($inicial); ?>; --area-tinta-inicial: var(--admin-<?php echo $h($tintaInicial); ?>)">
                        <legend class="admin-area-color__name"><?php echo $h($area['nombre']); ?></legend>

                        <div class="admin-area-color__fila">
                        <div class="admin-area-color__lado">
                        <div class="admin-area-color__meta">
                            <span class="admin-area-color__actual" data-area-actual>
                                <?php echo $elegido !== null ? $h($paleta[$elegido]['nombre']) : 'Fuera de la paleta: elige uno'; ?>
                            </span>
                            <a class="admin-area-color__link" href="<?php echo $h($area['path']); ?>" target="_blank" rel="noopener">
                                Ver tablero<?php echo admin_icon('externo', 14); ?><span class="admin-sr-only"> (se abre en otra pestaña)</span>
                            </a>
                        </div>

                        <?php /* El tablero en miniatura, en oscuro porque el KDS
                                 siempre lo es. El color de la estación llena la
                                 barra; las tres bandas van debajo con su
                                 semáforo fijo (rojo, amarillo, verde), para ver
                                 de un vistazo si el color elegido choca con
                                 alguno. */ ?>
                        <div class="admin-area-color__preview" aria-hidden="true">
                            <span class="admin-area-color__barra"><?php echo $h($area['nombre']); ?></span>
                            <span class="admin-area-color__band admin-area-color__band--enviados"><span class="admin-area-color__lomo">Enviados <span class="admin-area-color__cuenta">3</span></span></span>
                            <span class="admin-area-color__band admin-area-color__band--prep"><span class="admin-area-color__lomo">En preparación <span class="admin-area-color__cuenta">2</span></span></span>
                            <span class="admin-area-color__band admin-area-color__band--listo"><span class="admin-area-color__lomo">Listos <span class="admin-area-color__cuenta">1</span></span></span>
                        </div>
                        </div>

                        <div class="admin-area-swatches">
                            <?php foreach ($paleta as $clave => $color) : ?>
                                <?php
                                $otras = array_values(array_filter(
                                    $duenoDe[$clave] ?? [],
                                    static fn (string $s): bool => $s !== $slug
                                ));
                                $otraNombre = $otras !== [] ? ($areas[$otras[0]]['nombre'] ?? '') : '';
                                ?>
                                <label class="admin-area-swatch<?php echo $otras !== [] ? ' is-ocupado' : ''; ?>"
                                       style="--swatch: var(--admin-c-<?php echo $h($clave); ?>)"
                                       data-area-swatch="<?php echo $h($clave); ?>">
                                    <input
                                        type="radio"
                                        name="areas[<?php echo $h($slug); ?>]"
                                        value="<?php echo $h($clave); ?>"
                                        data-tinta="<?php echo $h(($color['tinta'] ?? 'ink') === 'paper' ? 'paper' : 'ink'); ?>"
                                        <?php echo $elegido === $clave ? 'checked' : ''; ?>
                                    >
                                    <span class="admin-area-swatch__dot" aria-hidden="true"></span>
                                    <span class="admin-area-swatch__name"><?php echo $h($color['nombre']); ?></span>
                                    <span class="admin-sr-only" data-area-ocupado><?php echo $otraNombre !== '' ? ' (ya es de ' . $h($otraNombre) . ')' : ''; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        </div>

                        <p class="admin-area-color__aviso" data-area-aviso aria-live="polite"></p>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <?php if ($nombresDeEstado !== [] || $nombresDeEspera !== []) : ?>
                <p class="admin-area-colors__nota">
                    <?php echo admin_icon('info', 16); ?>
                    <span>
                        En el tablero,
                        <?php if ($nombresDeEstado !== []) : ?>
                            <?php echo $h($enumerar($nombresDeEstado)); ?> son también los estados de las bandas (Enviados, En preparación y Listos)<?php echo $nombresDeEspera !== [] ? ', y' : '.'; ?>
                        <?php endif; ?>
                        <?php if ($nombresDeEspera !== []) : ?>
                            <?php echo $h($enumerar($nombresDeEspera)); ?> marcan una comanda atrasada: tiñen su hora a los 5 y a los 10 minutos.
                        <?php endif; ?>
                        Se pueden elegir, porque el color de la estación sólo llena la barra de arriba, pero en el tablero de esa estación el mismo color dirá dos cosas.
                    </span>
                </p>
            <?php endif; ?>

            <div class="admin-config-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Guardar colores</button>
            </div>
        </form>
    </section>
</section>
