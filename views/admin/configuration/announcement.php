<?php
$anuncio = is_array($anuncio ?? null) ? $anuncio : [];
$tiposAnuncio = is_array($tiposAnuncio ?? null) ? $tiposAnuncio : \Services\AnuncioConfig::TIPOS;
$erroresCampos = is_array($erroresCampos ?? null) ? $erroresCampos : [];
$fechaActual = (string)($fechaActual ?? '');
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$separarFechaHora = static function ($value): array {
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([0-2]\d:[0-5]\d)/', $value, $matches)) {
        return ['', ''];
    }

    return [$matches[1], $matches[2]];
};
[$fechaInicio, $horaInicio] = $separarFechaHora($anuncio['fecha_inicio'] ?? '');
[$fechaFin, $horaFin] = $separarFechaHora($anuncio['fecha_fin'] ?? '');
$isActive = !empty($anuncio['activo']);
$tiposPermitidos = array_keys($tiposAnuncio);
$tipoPreview = in_array((string) ($anuncio['tipo'] ?? ''), $tiposPermitidos, true)
    ? (string) $anuncio['tipo']
    : \Services\AnuncioConfig::TIPO_PREDETERMINADO;
$configTipoPreview = $tiposAnuncio[$tipoPreview];
$tiposJson = htmlspecialchars(
    json_encode($tiposAnuncio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$textoEnlacePreview = trim((string) ($anuncio['texto_enlace'] ?? ''));
$urlEnlacePreview = trim((string) ($anuncio['url_enlace'] ?? ''));
$mostrarEnlacePreview = $textoEnlacePreview !== ''
    && $urlEnlacePreview !== ''
    && \Model\ConfiguracionAnuncio::esUrlPermitida($urlEnlacePreview);
$enlaceExternoPreview = preg_match('~^https?://~i', $urlEnlacePreview) === 1;
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="announcement">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Anuncio principal</h1>
            <p class="admin-page__subtitle">Configura el único aviso que se mostrará a los visitantes del sitio.</p>
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

    <div class="admin-config-layout">
        <section class="admin-panel admin-card admin-config-panel" aria-labelledby="announcement-form-title">
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="announcement-form-title">Contenido y programación</h2>
                    <p>Los campos de fecha y enlace son opcionales.</p>
                </div>
            </div>

            <form
                class="admin-announcement-form"
                method="POST"
                action="/admin/configuracion/anuncio"
                data-announcement-form
                data-announcement-types="<?php echo $tiposJson; ?>"
                novalidate
            >
                <label class="admin-switch admin-announcement-form__wide">
                    <input type="checkbox" name="activo" value="1" data-announcement-active <?php echo $isActive ? 'checked' : ''; ?>>
                    <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Anuncio activo</span>
                </label>

                <?php /*
                  Tipos como pestañas y no como <select>: son cuatro y cada uno
                  cambia el color, el icono, el placeholder del mensaje y la
                  plantilla sugerida. Con un desplegable había que abrirlo para
                  saber qué opciones existían y elegir a ciegas; aquí se ven los
                  cuatro con su color antes de decidir.
                */ ?>
                <div class="admin-field admin-announcement-form__wide">
                    <span class="admin-field__label">Tipo de anuncio</span>
                    <div class="admin-tabs admin-announcement-types" role="radiogroup" aria-label="Tipo de anuncio">
                        <?php foreach ($tiposAnuncio as $value => $configTipo) : ?>
                            <label class="admin-tabs__tab admin-announcement-type"
                                   style="--announcement-accent: <?php echo $h($configTipo['acento'] ?? '#cca352'); ?>">
                                <input type="radio" name="tipo" value="<?php echo $h($value); ?>"
                                       data-announcement-type
                                       <?php echo $tipoPreview === $value ? 'checked' : ''; ?>>
                                <span>
                                    <svg class="admin-announcement-type__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <?php echo $configTipo['icono'] ?? ''; ?>
                                    </svg>
                                    <?php echo $h($configTipo['etiqueta'] ?? $value); ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="admin-announcement-type-purpose" id="announcement-type-purpose" data-announcement-purpose><?php echo $h($configTipoPreview['descripcion'] ?? ''); ?></p>
                </div>

                <label class="admin-field admin-announcement-form__wide">
                    <span class="admin-field__label admin-field__label--split"><span>Mensaje</span><span data-announcement-counter>0 / 255</span></span>
                    <textarea name="mensaje" maxlength="255" rows="4" placeholder="<?php echo $h($configTipoPreview['placeholder'] ?? 'Escribe el mensaje del anuncio.'); ?>" <?php echo $isActive ? 'required' : ''; ?> data-announcement-message><?php echo $h($anuncio['mensaje'] ?? ''); ?></textarea>
                    <span class="admin-field__error" data-field-error aria-live="polite"></span>
                </label>

                <fieldset class="admin-datetime-field" data-announcement-datetime="start">
                    <legend class="admin-field__label">Inicio <small>(opcional)</small></legend>
                    <input type="hidden" name="fecha_inicio" value="<?php echo $h($anuncio['fecha_inicio'] ?? ''); ?>" data-announcement-start>
                    <div class="admin-datetime-field__controls">
                        <div class="admin-datetime-field__part">
                            <label class="admin-datetime-field__part-label" for="announcement-start-date-display">Fecha</label>
                            <?php
                            $rootId = 'announcement-start-date-picker';
                            $inputId = 'announcement-start-date';
                            $displayId = 'announcement-start-date-display';
                            $calendarId = 'announcement-start-date-calendar';
                            $name = '';
                            $value = $fechaInicio;
                            $min = $fechaActual;
                            $today = $fechaActual;
                            $disabled = false;
                            $enabledWeekdays = [];
                            $allowPast = false;
                            $required = false;
                            $displayAriaDescribedby = 'announcement-start-error';
                            $displayAriaInvalid = false;
                            $inputDataAttributes = [];
                            include __DIR__ . '/../../components/reservations/date-picker.php';
                            ?>
                        </div>
                        <div class="admin-datetime-field__part">
                            <label class="admin-datetime-field__part-label" for="announcement-start-time-display">Hora</label>
                            <?php
                            $rootId = 'announcement-start-time-picker';
                            $inputId = 'announcement-start-time';
                            $displayId = 'announcement-start-time-display';
                            $dropdownId = 'announcement-start-time-dropdown';
                            $name = '';
                            $value = $horaInicio;
                            $endpoint = '';
                            $disabled = false;
                            $placeholder = 'Elige una hora';
                            $staticStep = 30;
                            $required = false;
                            $displayAriaDescribedby = 'announcement-start-error';
                            $displayAriaInvalid = false;
                            $inputDataAttributes = [];
                            include __DIR__ . '/../../components/reservations/time-picker.php';
                            ?>
                        </div>
                    </div>
                    <button type="button" class="admin-datetime-field__clear" data-announcement-datetime-clear>Limpiar inicio</button>
                    <span class="admin-field__error" id="announcement-start-error" data-field-error aria-live="polite"></span>
                </fieldset>

                <fieldset class="admin-datetime-field" data-announcement-datetime="end">
                    <legend class="admin-field__label">Finalización <small>(opcional)</small></legend>
                    <input type="hidden" name="fecha_fin" value="<?php echo $h($anuncio['fecha_fin'] ?? ''); ?>" data-announcement-end>
                    <div class="admin-datetime-field__controls">
                        <div class="admin-datetime-field__part">
                            <label class="admin-datetime-field__part-label" for="announcement-end-date-display">Fecha</label>
                            <?php
                            $rootId = 'announcement-end-date-picker';
                            $inputId = 'announcement-end-date';
                            $displayId = 'announcement-end-date-display';
                            $calendarId = 'announcement-end-date-calendar';
                            $name = '';
                            $value = $fechaFin;
                            $min = $fechaActual;
                            $today = $fechaActual;
                            $disabled = false;
                            $enabledWeekdays = [];
                            $allowPast = false;
                            $required = false;
                            $displayAriaDescribedby = 'announcement-end-error';
                            $displayAriaInvalid = false;
                            $inputDataAttributes = [];
                            include __DIR__ . '/../../components/reservations/date-picker.php';
                            ?>
                        </div>
                        <div class="admin-datetime-field__part">
                            <label class="admin-datetime-field__part-label" for="announcement-end-time-display">Hora</label>
                            <?php
                            $rootId = 'announcement-end-time-picker';
                            $inputId = 'announcement-end-time';
                            $displayId = 'announcement-end-time-display';
                            $dropdownId = 'announcement-end-time-dropdown';
                            $name = '';
                            $value = $horaFin;
                            $endpoint = '';
                            $disabled = false;
                            $placeholder = 'Elige una hora';
                            $staticStep = 30;
                            $required = false;
                            $displayAriaDescribedby = 'announcement-end-error';
                            $displayAriaInvalid = false;
                            $inputDataAttributes = [];
                            include __DIR__ . '/../../components/reservations/time-picker.php';
                            ?>
                        </div>
                    </div>
                    <button type="button" class="admin-datetime-field__clear" data-announcement-datetime-clear>Limpiar finalización</button>
                    <span class="admin-field__error" id="announcement-end-error" data-field-error aria-live="polite"></span>
                </fieldset>

                <label class="admin-field">
                    <span class="admin-field__label">Texto del enlace <small>(opcional)</small></span>
                    <input type="text" name="texto_enlace" maxlength="80" value="<?php echo $h($anuncio['texto_enlace'] ?? ''); ?>" placeholder="<?php echo $h($configTipoPreview['texto_enlace'] ?? ''); ?>" autocomplete="off" data-announcement-link-text>
                    <span class="admin-field__error" data-field-error aria-live="polite"><?php echo $h(implode(' ', $erroresCampos['texto_enlace'] ?? [])); ?></span>
                </label>
                <label class="admin-field">
                    <span class="admin-field__label">URL del enlace <small>(opcional)</small></span>
                    <input type="text" name="url_enlace" maxlength="500" value="<?php echo $h($anuncio['url_enlace'] ?? ''); ?>" autocomplete="off" data-announcement-link-url>
                    <span class="admin-field__error" data-field-error aria-live="polite"><?php echo $h(implode(' ', $erroresCampos['url_enlace'] ?? [])); ?></span>
                </label>

                <div class="admin-config-form-actions admin-announcement-form__wide">
                    <p class="admin-form-status" data-announcement-status aria-live="polite"></p>
                    <button type="submit" class="admin-btn admin-btn--primary" data-announcement-validate>Guardar anuncio</button>
                </div>
            </form>
        </section>

        <aside class="admin-panel admin-card admin-config-preview" aria-labelledby="announcement-preview-title">
            <div class="admin-config-panel__head">
                <div>
                    <h2 id="announcement-preview-title">Vista previa</h2>
                    <p>Así lo verá el comensal al entrar al sitio.</p>
                </div>
                <?php /* El landing tiene fondo oscuro; el panel puede estar en claro.
                         Sin poder alternar, el acento se juzgaba sobre un fondo que
                         el visitante nunca ve. */ ?>
                <div class="admin-announcement-preview-theme" role="group" aria-label="Fondo de la vista previa">
                    <button type="button" class="admin-announcement-preview-theme__btn is-active"
                            data-preview-theme="oscuro" aria-pressed="true">Oscuro</button>
                    <button type="button" class="admin-announcement-preview-theme__btn"
                            data-preview-theme="claro" aria-pressed="false">Claro</button>
                </div>
            </div>
            <div class="admin-announcement-preview-stage" data-preview-stage data-tema="oscuro">
                <span class="admin-announcement-preview-state<?php echo $isActive ? ' is-live' : ''; ?>" data-preview-state>
                    <?php echo $isActive ? 'Activo' : 'Inactivo · no será visible'; ?>
                </span>

                <?php /* Franja que insinúa el hero del landing: el anuncio suelto no
                         daba idea de su peso real sobre la portada. */ ?>
                <div class="admin-announcement-preview-hero" aria-hidden="true">
                    <span class="admin-announcement-preview-hero__brand">Casa Pestalozzi</span>
                    <span class="admin-announcement-preview-hero__line"></span>
                    <span class="admin-announcement-preview-hero__line admin-announcement-preview-hero__line--short"></span>
                </div>

                <div
                    class="hero-announcement hero-announcement--<?php echo $h($tipoPreview); ?> hero-announcement--<?php echo $mostrarEnlacePreview ? 'has-link' : 'without-link'; ?>"
                    data-announcement-preview
                    data-type="<?php echo $h($tipoPreview); ?>"
                    style="--announcement-accent: <?php echo $h($configTipoPreview['acento'] ?? '#9fc2c5'); ?>"
                    aria-label="Vista previa del anuncio"
                >
                    <span class="hero-announcement__indicator" aria-hidden="true"></span>
                    <div class="hero-announcement__content">
                        <div class="hero-announcement__heading">
                            <span class="hero-announcement__icon" aria-hidden="true">
                                <svg data-preview-icon viewBox="0 0 24 24"><?php echo $configTipoPreview['icono'] ?? ''; ?></svg>
                            </span>
                            <span class="hero-announcement__type" data-preview-type-label><?php echo $h($configTipoPreview['etiqueta'] ?? 'Evento'); ?></span>
                        </div>
                        <p class="hero-announcement__message" data-preview-message><?php echo $h(trim((string) ($anuncio['mensaje'] ?? '')) ?: 'Escribe un mensaje para ver la vista previa.'); ?></p>
                        <a
                            class="hero-announcement__link"
                            href="<?php echo $mostrarEnlacePreview ? $h($urlEnlacePreview) : '#'; ?>"
                            data-preview-link
                            <?php echo $mostrarEnlacePreview && $enlaceExternoPreview ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
                            <?php echo $mostrarEnlacePreview ? '' : 'hidden'; ?>
                        ><span data-preview-link-label><?php echo $h($textoEnlacePreview ?: 'Ver más'); ?></span><span aria-hidden="true"> ↗</span></a>
                    </div>
                    <button class="hero-announcement__close" type="button" tabindex="-1" aria-hidden="true">
                        <span aria-hidden="true">×</span>
                    </button>
                    <div class="hero-announcement__progress" aria-hidden="true">
                        <span class="hero-announcement__progress-remaining"></span>
                    </div>
                </div>
            </div>
            <div class="admin-announcement-template" data-announcement-template>
                <span class="admin-announcement-template__label">Ejemplo sugerido</span>
                <div
                    class="hero-announcement hero-announcement--<?php echo $h($tipoPreview); ?> hero-announcement--without-link admin-announcement-example"
                    data-announcement-example-banner
                    data-type="<?php echo $h($tipoPreview); ?>"
                    style="--announcement-accent: <?php echo $h($configTipoPreview['acento'] ?? '#9fc2c5'); ?>"
                    aria-label="Ejemplo sugerido del anuncio"
                >
                    <span class="hero-announcement__indicator" aria-hidden="true"></span>
                    <div class="hero-announcement__content">
                        <div class="hero-announcement__heading">
                            <span class="hero-announcement__icon" aria-hidden="true">
                                <svg data-announcement-example-icon viewBox="0 0 24 24"><?php echo $configTipoPreview['icono'] ?? ''; ?></svg>
                            </span>
                            <span class="hero-announcement__type" data-announcement-example-type><?php echo $h($configTipoPreview['etiqueta'] ?? 'Evento'); ?></span>
                        </div>
                        <p class="hero-announcement__message" data-announcement-example><?php echo $h($configTipoPreview['ejemplo'] ?? ''); ?></p>
                    </div>
                </div>
                <button type="button" class="admin-btn admin-btn--secondary" data-announcement-use-example>Usar ejemplo</button>
            </div>
        </aside>
    </div>
</section>
