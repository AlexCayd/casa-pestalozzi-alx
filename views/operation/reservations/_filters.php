<?php $operationalFilterScope = (string)($operationalFilterScope ?? 'all'); ?>
<form
    class="reservation-operation__filters"
    method="GET"
    action="/admin/reservaciones/operacion"
    aria-label="Filtros de operación de reservaciones"
    data-operation-filters
>
    <?php if ($operationalFilterScope !== 'drawer'): ?>
    <div class="reservation-operation__filter-actions">
        <button type="submit" class="admin-btn admin-btn--secondary operational-toolbar-icon" data-operation-load aria-label="Actualizar mapa" title="Actualizar mapa">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M20 11a8 8 0 1 0 1 4"></path>
                <path d="M20 4v7h-7"></path>
            </svg>
            <span class="operational-visually-hidden" data-operation-load-label>Actualizar mapa</span>
        </button>
    </div>

    <div class="reservation-operation__filter reservation-operation__date-group" data-operation-date-group>
        <label class="operational-visually-hidden" for="operation-fecha-display">Fecha</label>
        <?php
        $rootId = 'operation-date-picker';
        $inputId = 'operation-fecha';
        $displayId = 'operation-fecha-display';
        $calendarId = 'operation-date-calendar';
        $name = 'fecha';
        $value = $fechaInicial;
        $min = '';
        $today = $fechaMinima;
        $disabled = false;
        $enabledWeekdays = [];
        $inputDataAttributes = ['data-operational-context-date' => true];
        $displayAriaDescribedby = 'operation-date-warning';
        $displayAriaInvalid = $fechaInvalidaRecibida !== '';
        $rootClass = 'operational-context-date';
        $showIcon = true;
        $prevId = '';
        $nextId = '';
        $labelId = '';
        $gridId = '';
        include __DIR__ . '/../../components/reservations/date-picker.php';
        ?>
        <div
            class="reservation-operation-notice reservation-operation-notice--warning reservation-operation-date-warning"
            id="operation-date-warning"
            role="status"
            aria-live="polite"
            data-operation-date-warning
            <?php echo $fechaInvalidaRecibida !== '' ? '' : 'hidden'; ?>
        >
            <span class="reservation-operation-notice__icon" aria-hidden="true">!</span>
            <span class="reservation-operation-notice__copy">
                <strong>Fecha inválida</strong>
                <span data-operation-date-warning-message>
                    La fecha recibida no tiene un formato válido.
                    <?php if ($fechaInvalidaRecibida !== ''): ?> Valor recibido: <?php echo $h($fechaInvalidaRecibida); ?>.<?php endif; ?>
                </span>
            </span>
        </div>
    </div>

    <div class="reservation-operation__filter" data-operation-time-group>
        <label class="operational-visually-hidden" for="operation-hora-display">Horario</label>
        <?php
        $rootId = 'operation-time-picker';
        $inputId = 'operation-hora';
        $displayId = 'operation-hora-display';
        $dropdownId = 'operation-time-dropdown';
        $name = 'hora';
        $value = $horaInicial;
        $endpoint = '';
        $disabled = false;
        $placeholder = 'Cargando horarios';
        $staticStep = 0;
        $required = false;
        $displayAriaDescribedby = '';
        $displayAriaInvalid = false;
        $inputDataAttributes = [
            'data-operation-hour' => true,
            'data-operational-context-hour' => true,
        ];
        include __DIR__ . '/../../components/reservations/time-picker.php';
        ?>
    </div>
    <?php endif; ?>

    <?php if ($operationalFilterScope !== 'context'): ?>
    <div class="reservation-operation__filter reservation-operation__assignment-filter">
        <label class="operational-visually-hidden" for="operation-asignacion-filtro">Estado de asignacion</label>
        <select id="operation-asignacion-filtro" data-operation-assignment-filter>
            <option value="all">Todas</option>
            <option value="pending">Sin mesa</option>
            <option value="assigned">Con mesa</option>
        </select>
    </div>

    <div class="reservation-operation__filter reservation-operation__search-filter">
        <label class="operational-visually-hidden" for="operation-reservacion-busqueda"><?php echo $superficieOperativa === 'admin' ? 'Nombre o contacto' : 'Buscar por nombre'; ?></label>
        <input id="operation-reservacion-busqueda" type="search" placeholder="<?php echo $superficieOperativa === 'admin' ? 'Nombre o contacto' : 'Buscar por nombre'; ?>" autocomplete="off" data-operation-reservation-search>
    </div>
    <?php endif; ?>

</form>
