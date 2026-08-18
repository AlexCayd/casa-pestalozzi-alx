<?php
$horarios = is_array($horarios ?? null) ? $horarios : [];
$excepciones = is_array($excepciones ?? null) ? $excepciones : [];
$alertas = is_array($alertas ?? null) ? $alertas : [];
$excepcionFormulario = is_array($excepcionFormulario ?? null) ? $excepcionFormulario : [];
$abrirModalExcepcion = !empty($abrirModalExcepcion);
$horarioSemanalConErrores = !empty($horarioSemanalConErrores);
$conflictosHorarios = is_array($conflictosHorarios ?? null) ? $conflictosHorarios : [];
$conflictosExcepcion = is_array($conflictosExcepcion ?? null) ? $conflictosExcepcion : [];
$impactoSeguimiento = is_array($impactoSeguimiento ?? null) ? $impactoSeguimiento : null;
$pendingScheduleAction = is_array($pendingScheduleAction ?? null) ? $pendingScheduleAction : null;
$adminCsrfToken = (string)($adminCsrfToken ?? '');
$fechaActual = (string) ($fechaActual ?? '');
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$valorExcepcion = static fn (string $campo, $predeterminado = '') => $excepcionFormulario[$campo] ?? $predeterminado;
$excepcionActiva = (string) $valorExcepcion('activo', '1') === '1';
$excepcionHorarioEspecial = (string) $valorExcepcion('tipo', 'cerrado') === 'horario_especial';

/*
 * Rejilla de horas 00–23 por día.
 *
 * Sustituye a las pestañas de rangos "preferidos", que solo ofrecían las
 * combinaciones ya guardadas: para cualquier otra había que abrir un
 * "Personalizado" con dos selectores. Aquí las 24 horas están a la vista y el
 * rango se marca con dos toques (apertura y cierre).
 *
 * Cada toque abre un pequeño selector que pregunta si es la hora en punto o y
 * media: la resolución real del horario es de media hora (el restaurante abre a
 * las 08:30) y `horarios_operacion.hora_apertura` es TIME, así que el minuto
 * viaja igual que antes. La celda marca con ":30" el extremo que no cae en
 * punto, o 08:00 y 08:30 se verían idénticos en la rejilla.
 */
$horasDelDia = range(0, 23);
?>
<section
    class="admin-configuration admin-menu admin-page"
    data-configuration-page="hours"
    <?php echo $abrirModalExcepcion ? 'data-open-exception-modal' : ''; ?>
    <?php echo $impactoSeguimiento ? 'data-schedule-impact data-impact-id="' . (int)($impactoSeguimiento['id'] ?? 0) . '" data-admin-csrf="' . $h($adminCsrfToken) . '"' : ''; ?>
    <?php echo $pendingScheduleAction ? 'data-open-schedule-impact-confirmation' : ''; ?>
>
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Horarios de operación</h1>
            <p class="admin-page__subtitle">Define el horario semanal y registra cierres o aperturas especiales sin modificar la operación habitual.</p>
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

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="weekly-schedule-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="weekly-schedule-title">Horario semanal</h2>
                <p>Activa cada día y marca su apertura y su cierre en la rejilla de horas.</p>
            </div>
        </div>

        <form
            id="weekly-schedule-form"
            action="/admin/configuracion/horarios"
            method="post"
            data-schedule-form
            data-schedule-api="/api/configuracion/horarios/semanales"
            data-schedule-read-api="/api/configuracion/horarios/semanales"
            data-initial-dirty="<?php echo $horarioSemanalConErrores ? '1' : '0'; ?>"
            novalidate
        >
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
            <input type="hidden" name="confirmar_conflictos" value="0" data-confirm-schedule-conflicts>
            <div class="admin-schedule" role="group" aria-label="Horario semanal">
                <?php foreach ($horarios as $index => $horario) : ?>
                    <?php
                    $diaSemana = (int) ($horario['dia_semana'] ?? $index);
                    $isOpen = (bool) ($horario['abierto'] ?? false);
                    $openErrorId = "schedule-open-error-{$diaSemana}";
                    $closeErrorId = "schedule-close-error-{$diaSemana}";
                    ?>
                    <div class="admin-schedule__row" data-schedule-row>
                        <div class="admin-schedule__head">
                            <strong class="admin-schedule__day"><?php echo $h($horario['nombre'] ?? ''); ?></strong>

                            <input type="hidden" name="horarios[<?php echo $index; ?>][dia_semana]" value="<?php echo $diaSemana; ?>">
                            <input type="hidden" name="horarios[<?php echo $index; ?>][abierto]" value="0">
                            <label class="admin-switch">
                                <input
                                    type="checkbox"
                                    name="horarios[<?php echo $index; ?>][abierto]"
                                    value="1"
                                    aria-label="Abrir <?php echo $h($horario['nombre'] ?? ''); ?>"
                                    data-schedule-toggle
                                    <?php echo $isOpen ? 'checked' : ''; ?>
                                >
                                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                                <span class="admin-switch__label" data-switch-label><?php echo $isOpen ? 'Abierto' : 'Cerrado'; ?></span>
                            </label>
                        </div>

                        <?php /*
                          Envuelto en .admin-field porque setFieldError() busca
                          ese contenedor para colocar el mensaje: un solo error
                          para las dos horas, que son una sola decisión.

                          Los inputs siguen siendo la fuente de verdad del envío
                          y del control de cambios sin guardar; la rejilla solo
                          los escribe. Así el resto del flujo (dirty state,
                          conflictos, guardado por API) no cambia.
                        */ ?>
                        <div class="admin-field admin-schedule__picker">
                            <input type="hidden" name="horarios[<?php echo $index; ?>][hora_apertura]"
                                   value="<?php echo $h(substr((string)($horario['hora_apertura'] ?? ''), 0, 5)); ?>"
                                   data-schedule-open>
                            <input type="hidden" name="horarios[<?php echo $index; ?>][hora_cierre]"
                                   value="<?php echo $h(substr((string)($horario['hora_cierre'] ?? ''), 0, 5)); ?>"
                                   data-schedule-close>

                            <div class="admin-schedule__hours" role="group"
                                 aria-label="Horario de <?php echo $h($horario['nombre'] ?? ''); ?>"
                                 data-schedule-hours>
                                <?php foreach ($horasDelDia as $hora) : ?>
                                    <button type="button"
                                            class="admin-schedule__hour"
                                            data-schedule-hour="<?php echo $hora; ?>"
                                            aria-pressed="false"
                                            aria-haspopup="true"
                                            aria-expanded="false"
                                            title="<?php echo sprintf('%02d:00 o %02d:30', $hora, $hora); ?>">
                                        <?php echo sprintf('%02d', $hora); ?><span class="admin-schedule__hour-min" data-schedule-hour-min></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <p class="admin-schedule__summary" data-schedule-summary aria-live="polite"></p>
                            <span class="admin-field__error" id="<?php echo $openErrorId; ?>" data-field-error aria-live="polite"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="admin-config-form-actions">
                <p class="admin-form-status" data-schedule-status aria-live="polite"></p>
                <?php if ($conflictosHorarios !== []) : ?>
                    <div class="admin-config-conflict" role="alert">
                        Este cambio dejaría <?php echo count($conflictosHorarios); ?>
                        reservación(es) confirmada(s) fuera del nuevo horario.
                        Las reservaciones no serán canceladas automáticamente.
                    </div>
                    <button
                        type="submit"
                        class="admin-btn admin-btn--danger-solid"
                        name="confirmar_conflictos"
                        value="1"
                    >Guardar de todas formas</button>
                <?php endif; ?>
                <button type="submit" class="admin-btn admin-btn--primary" data-schedule-validate disabled>Guardar horarios</button>
                <button type="button" class="admin-btn admin-btn--secondary" data-schedule-reset disabled>Descartar cambios</button>
            </div>
        </form>
    </section>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="exceptions-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="exceptions-title">Cierres y horarios especiales</h2>
                <p>Las excepciones tienen prioridad sobre el horario semanal.</p>
            </div>
            <button type="button" class="admin-btn admin-btn--primary" data-exception-create data-admin-modal-open="schedule-exception-modal" aria-haspopup="dialog" aria-controls="schedule-exception-modal">Registrar excepción</button>
        </div>

        <?php if (empty($excepciones)) : ?>
            <div class="admin-empty admin-config-empty">
                <strong>No hay excepciones registradas</strong>
                <span>Los cierres y horarios especiales aparecerán aquí.</span>
            </div>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-config-table">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Motivo</th><th>Horario</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($excepciones as $excepcion) : ?>
                            <?php
                            $activo = !empty($excepcion['activo']);
                            $datosEdicion = htmlspecialchars(
                                json_encode($excepcion, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}',
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                            <tr>
                                <td data-label="Fecha"><?php echo $h($excepcion['fecha'] ?? ''); ?></td>
                                <td data-label="Tipo"><?php echo $h($excepcion['tipo_nombre'] ?? ''); ?></td>
                                <td data-label="Motivo"><?php echo $h(($excepcion['motivo'] ?? '') !== '' ? $excepcion['motivo'] : '—'); ?></td>
                                <td data-label="Horario"><?php echo $h($excepcion['horario'] ?? '—'); ?></td>
                                <td data-label="Estado">
                                    <form action="/admin/configuracion/horarios/excepciones/estado" method="post" data-exception-state-form>
                                        <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) ($excepcion['id'] ?? 0); ?>">
                                        <input type="hidden" name="activo" value="<?php echo $activo ? '1' : '0'; ?>" data-exception-state-value>
                                        <label class="admin-switch">
                                            <input
                                                type="checkbox"
                                                aria-label="<?php echo $activo ? 'Desactivar' : 'Activar'; ?> excepción del <?php echo $h($excepcion['fecha'] ?? ''); ?>"
                                                data-exception-state-toggle
                                                <?php echo $activo ? 'checked' : ''; ?>
                                            >
                                            <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                                            <span class="admin-switch__label" data-switch-label><?php echo $activo ? 'Activa' : 'Inactiva'; ?></span>
                                        </label>
                                    </form>
                                </td>
                                <td data-label="Acciones">
                                    <div class="admin-table-actions">
                                        <button
                                            type="button"
                                            class="admin-btn admin-btn--secondary admin-btn--small"
                                            data-exception-edit="<?php echo $datosEdicion; ?>"
                                        >Editar</button>
                                        <button
                                            type="button"
                                            class="admin-btn admin-btn--danger admin-btn--small"
                                            data-exception-delete="<?php echo $datosEdicion; ?>"
                                            aria-haspopup="dialog"
                                            aria-controls="schedule-exception-delete-modal"
                                        >Eliminar</button>
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

<?php if ($impactoSeguimiento) : ?>
    <div class="admin-modal schedule-impact-modal" id="schedule-impact-modal" data-admin-modal data-schedule-impact-modal data-impact-required hidden>
        <div class="admin-modal__backdrop schedule-impact-modal__backdrop" aria-hidden="true"></div>
        <div class="admin-modal__dialog admin-modal__dialog--wide schedule-impact-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="schedule-impact-title" aria-describedby="schedule-impact-description" tabindex="-1" data-admin-modal-dialog>
            <div class="admin-modal__head">
                <div>
                    <span class="admin-modal__eyebrow">Atención requerida</span>
                    <h2 class="admin-modal__title" id="schedule-impact-title">Reservaciones afectadas</h2>
                </div>
                <span class="schedule-impact-modal__count" data-impact-pending-count aria-live="polite"></span>
            </div>
            <p class="admin-modal__text" id="schedule-impact-description">
                Este cambio no cancela reservaciones. Contacta primero a quienes tienen un medio disponible y después atiende los casos sin contacto.
            </p>
            <div class="schedule-impact-modal__toolbar">
                <button type="button" class="admin-btn admin-btn--primary" data-impact-notify-all>Enviar avisos disponibles</button>
                <span class="admin-form-status" data-impact-status role="status" aria-live="polite"></span>
            </div>
            <div class="schedule-impact-modal__notice" data-impact-test-link-notice hidden>
                <strong>Enlace de prueba listo.</strong>
                <span data-impact-test-link-label>El enlace sólo vive en esta pantalla.</span>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-impact-copy-link>Copiar link de prueba</button>
            </div>
            <div class="schedule-impact-modal__list" data-impact-list aria-live="polite">
                <?php foreach ((array)($impactoSeguimiento['reservaciones'] ?? []) as $afectacion) : ?>
                    <?php
                    $estadoAfectacion = (string)($afectacion['estado'] ?? '');
                    $contactoAfectacion = (string)($afectacion['contacto'] ?? '');
                    ?>
                    <article class="schedule-impact-row" data-impact-row data-impact-reservation-id="<?php echo (int)($afectacion['id'] ?? 0); ?>">
                        <div class="schedule-impact-row__identity">
                            <strong data-impact-name><?php echo $h($afectacion['nombre'] ?? ''); ?></strong>
                            <span data-impact-date><?php echo $h(($afectacion['fecha'] ?? '') . ' · ' . ($afectacion['hora'] ?? '')); ?></span>
                            <span data-impact-guests><?php echo (int)($afectacion['comensales'] ?? 0); ?> comensales</span>
                        </div>
                        <div class="schedule-impact-row__contact">
                            <span class="schedule-impact-row__label">Contacto</span>
                            <span data-impact-contact><?php echo $h($contactoAfectacion !== '' ? $contactoAfectacion : 'Sin contacto'); ?></span>
                        </div>
                        <div class="schedule-impact-row__state">
                            <span class="schedule-impact-row__label">Estado</span>
                            <span data-impact-state><?php echo $h(str_replace('_', ' ', $estadoAfectacion)); ?></span>
                        </div>
                        <div class="schedule-impact-row__actions" data-impact-actions>
                            <?php if ($estadoAfectacion === 'pendiente_notificacion' && !empty($afectacion['tiene_contacto'])) : ?>
                                <button type="button" class="admin-btn admin-btn--primary admin-btn--small" data-impact-notify>Enviar aviso</button>
                            <?php elseif ($estadoAfectacion === 'sin_contacto') : ?>
                                <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-impact-add-contact>Agregar contacto</button>
                                <button type="button" class="admin-btn admin-btn--danger admin-btn--small" data-impact-manual <?php echo empty($afectacion['manual_habilitada']) ? 'disabled' : ''; ?>>Atender manualmente</button>
                            <?php elseif (!empty($afectacion['test_link_disponible'])) : ?>
                                <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-impact-test-link>Generar link de prueba</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="schedule-impact-modal__complete" data-impact-complete hidden>
                <strong>Todas las reservaciones de este cambio están atendidas.</strong>
                <span>El seguimiento quedó resuelto y ya puedes continuar con la configuración.</span>
                <a class="admin-btn admin-btn--primary" href="/admin/configuracion/horarios">Finalizar seguimiento</a>
            </div>
        </div>
    </div>

    <div class="admin-modal schedule-impact-contact-modal" id="schedule-impact-contact-modal" data-admin-modal hidden>
        <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
        <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="schedule-impact-contact-title" tabindex="-1" data-admin-modal-dialog>
            <div class="admin-modal__head">
                <div>
                    <span class="admin-modal__eyebrow">Atención manual</span>
                    <h2 class="admin-modal__title" id="schedule-impact-contact-title">Agregar contacto</h2>
                </div>
                <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
            </div>
            <p class="admin-modal__text">El contacto se agregará sólo a la reservación seleccionada para poder enviarle el aviso del cambio.</p>
            <form class="admin-modal__form" data-impact-contact-form novalidate>
                <input type="hidden" name="impacto_id" data-impact-contact-impact-id>
                <input type="hidden" name="impacto_reservacion_id" data-impact-contact-reservation-id>
                <label class="admin-field">
                    <span class="admin-field__label">Tipo de contacto</span>
                    <select name="tipo" data-impact-contact-type required>
                        <option value="email">Correo electrónico</option>
                        <option value="telefono">Teléfono</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span class="admin-field__label">Correo o teléfono</span>
                    <input type="text" name="contacto" data-impact-contact-value required autocomplete="off">
                </label>
                <p class="admin-form-status" data-impact-contact-status role="status" aria-live="polite"></p>
                <div class="admin-modal__actions">
                    <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button>
                    <button type="submit" class="admin-btn admin-btn--primary">Guardar contacto</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="admin-modal" id="schedule-exception-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div class="admin-modal__dialog admin-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="exception-modal-title" tabindex="-1" data-admin-modal-dialog>
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Horario especial</span>
                <h2 class="admin-modal__title" id="exception-modal-title">Registrar excepción</h2>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <form action="/admin/configuracion/horarios/excepciones/guardar" method="post" class="admin-modal__form" data-exception-form novalidate>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
            <input type="hidden" name="id" value="<?php echo $h($valorExcepcion('id')); ?>" data-exception-id>
            <input type="hidden" name="confirmar_conflictos" value="0">
            <div class="admin-field">
                <label class="admin-field__label" for="exception-date-display">Fecha</label>
                <?php
                $rootId = 'exception-date-picker';
                $inputId = 'exception-date';
                $displayId = 'exception-date-display';
                $calendarId = 'exception-date-calendar';
                $name = 'fecha';
                $value = (string)$valorExcepcion('fecha');
                $min = $fechaActual;
                $today = $fechaActual;
                $disabled = false;
                $enabledWeekdays = [];
                $allowPast = false;
                $required = true;
                $displayAriaDescribedby = 'exception-date-error';
                $displayAriaInvalid = false;
                $inputDataAttributes = ['data-exception-date' => true];
                include __DIR__ . '/../../components/reservations/date-picker.php';
                ?>
                <span class="admin-field__error" id="exception-date-error" data-field-error aria-live="polite"></span>
            </div>
            <label class="admin-field">
                <span class="admin-field__label">Tipo</span>
                <select name="tipo" required data-exception-type>
                    <option value="cerrado" <?php echo $valorExcepcion('tipo', 'cerrado') === 'cerrado' ? 'selected' : ''; ?>>Cerrado</option>
                    <option value="horario_especial" <?php echo $valorExcepcion('tipo') === 'horario_especial' ? 'selected' : ''; ?>>Horario especial</option>
                </select>
            </label>
            <label class="admin-field admin-modal__field--wide">
                <span class="admin-field__label">Motivo <small>(opcional)</small></span>
                <input type="text" name="motivo" value="<?php echo $h($valorExcepcion('motivo')); ?>" maxlength="160">
            </label>
            <div class="admin-modal__form admin-modal__field--wide admin-exception-times" data-exception-times hidden>
                <div class="admin-field">
                    <label class="admin-field__label" for="exception-open-time-display">Hora de apertura</label>
                    <?php
                    $rootId = 'exception-open-time-picker';
                    $inputId = 'exception-open-time';
                    $displayId = 'exception-open-time-display';
                    $dropdownId = 'exception-open-time-dropdown';
                    $name = 'hora_apertura';
                    $value = substr((string)$valorExcepcion('hora_apertura'), 0, 5);
                    $endpoint = '';
                    $disabled = !$excepcionHorarioEspecial;
                    $placeholder = 'Elige una hora';
                    $staticStep = 30;
                    $required = $excepcionHorarioEspecial;
                    $displayAriaDescribedby = 'exception-open-error';
                    $displayAriaInvalid = false;
                    $inputDataAttributes = ['data-exception-open' => true];
                    include __DIR__ . '/../../components/reservations/time-picker.php';
                    ?>
                    <span class="admin-field__error" id="exception-open-error" data-field-error aria-live="polite"></span>
                </div>
                <div class="admin-field">
                    <label class="admin-field__label" for="exception-close-time-display">Hora de cierre</label>
                    <?php
                    $rootId = 'exception-close-time-picker';
                    $inputId = 'exception-close-time';
                    $displayId = 'exception-close-time-display';
                    $dropdownId = 'exception-close-time-dropdown';
                    $name = 'hora_cierre';
                    $value = substr((string)$valorExcepcion('hora_cierre'), 0, 5);
                    $endpoint = '';
                    $disabled = !$excepcionHorarioEspecial;
                    $placeholder = 'Elige una hora';
                    $staticStep = 30;
                    $required = $excepcionHorarioEspecial;
                    $displayAriaDescribedby = 'exception-close-error';
                    $displayAriaInvalid = false;
                    $inputDataAttributes = ['data-exception-close' => true];
                    include __DIR__ . '/../../components/reservations/time-picker.php';
                    ?>
                    <span class="admin-field__error" id="exception-close-error" data-field-error aria-live="polite"></span>
                </div>
            </div>
            <input type="hidden" name="activo" value="0">
            <label class="admin-switch admin-modal__field--wide">
                <input type="checkbox" name="activo" value="1" <?php echo $excepcionActiva ? 'checked' : ''; ?>>
                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Excepción activa</span>
            </label>
            <p class="admin-form-status admin-modal__field--wide" data-exception-status aria-live="polite"></p>
            <?php if ($conflictosExcepcion !== []) : ?>
                <div class="admin-config-conflict admin-modal__field--wide" role="alert">
                    La excepción dejaría <?php echo count($conflictosExcepcion); ?>
                    reservación(es) fuera del horario. Se conservarán para atención manual.
                </div>
            <?php endif; ?>
            <div class="admin-modal__actions admin-modal__field--wide">
                <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button>
                <?php if ($conflictosExcepcion !== []) : ?>
                    <button type="submit" class="admin-btn admin-btn--danger-solid" name="confirmar_conflictos" value="1">Guardar de todas formas</button>
                <?php endif; ?>
                <button type="submit" class="admin-btn admin-btn--primary" data-exception-validate>Guardar excepción</button>
            </div>
        </form>
    </div>
</div>

<div class="admin-modal" id="schedule-unsaved-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div
        class="admin-modal__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="schedule-unsaved-title"
        aria-describedby="schedule-unsaved-description"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Cambios pendientes</span>
                <h2 class="admin-modal__title" id="schedule-unsaved-title">Salir sin guardar</h2>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <p class="admin-modal__text" id="schedule-unsaved-description">
            Tienes cambios sin guardar en los horarios semanales. Si sales ahora, se perderán.
        </p>

        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Continuar editando</button>
            <a class="admin-btn admin-btn--danger-solid" href="/admin/configuracion" data-unsaved-leave>Salir sin guardar</a>
        </div>
    </div>
</div>

<div class="admin-modal" id="schedule-exception-delete-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div
        class="admin-modal__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="exception-delete-title"
        aria-describedby="exception-delete-description"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Acción destructiva</span>
                <h2 class="admin-modal__title" id="exception-delete-title">Eliminar excepción</h2>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <p class="admin-modal__text" id="exception-delete-description">
            Se eliminará <strong data-exception-delete-type></strong> del
            <strong data-exception-delete-date></strong><span data-exception-delete-reason-wrap hidden>, con motivo “<strong data-exception-delete-reason></strong>”</span>.
        </p>
        <p class="admin-modal__text">Al eliminar esta excepción, la fecha volverá a utilizar el horario semanal habitual.</p>

        <form action="/admin/configuracion/horarios/excepciones/eliminar" method="post" data-exception-delete-form>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
            <input type="hidden" name="id" value="" data-exception-delete-id>
            <div class="admin-modal__actions">
                <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button>
                <button type="submit" class="admin-btn admin-btn--danger-solid" data-exception-delete-submit>Eliminar excepción</button>
            </div>
        </form>
    </div>
</div>

<?php if ($pendingScheduleAction) : ?>
    <?php
    $pendingActionId = (int)($pendingScheduleAction['id'] ?? 0);
    $pendingActionIsState = ($pendingScheduleAction['tipo'] ?? '') === 'estado';
    $pendingActionActive = !empty($pendingScheduleAction['activo']);
    $pendingActionUrl = $pendingActionIsState
        ? '/admin/configuracion/horarios/excepciones/estado'
        : '/admin/configuracion/horarios/excepciones/eliminar';
    $pendingActionLabel = $pendingActionIsState
        ? ($pendingActionActive ? 'activar' : 'desactivar')
        : 'eliminar';
    $pendingActionCount = count((array)($pendingScheduleAction['conflictos'] ?? []));
    ?>
    <div class="admin-modal" id="schedule-impact-confirmation-modal" data-admin-modal hidden>
        <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
        <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="schedule-impact-confirmation-title" tabindex="-1" data-admin-modal-dialog>
            <div class="admin-modal__head">
                <div>
                    <span class="admin-modal__eyebrow">Reservaciones afectadas</span>
                    <h2 class="admin-modal__title" id="schedule-impact-confirmation-title">Confirmar cambio de horario</h2>
                </div>
                <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
            </div>
            <p class="admin-modal__text">
                La acción para <?php echo $h($pendingActionLabel); ?> afectaría a
                <strong><?php echo $pendingActionCount; ?> reservación<?php echo $pendingActionCount === 1 ? '' : 'es'; ?></strong>.
                No se cancelarán automáticamente; quedarán en seguimiento para contactar a cada cliente.
            </p>
            <form action="<?php echo $h($pendingActionUrl); ?>" method="post">
                <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken); ?>">
                <input type="hidden" name="id" value="<?php echo $pendingActionId; ?>">
                <input type="hidden" name="confirmar_conflictos" value="1">
                <?php if ($pendingActionIsState) : ?>
                    <input type="hidden" name="activo" value="<?php echo $pendingActionActive ? '1' : '0'; ?>">
                <?php endif; ?>
                <div class="admin-modal__actions">
                    <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Volver</button>
                    <button type="submit" class="admin-btn admin-btn--danger-solid">Confirmar y continuar</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
