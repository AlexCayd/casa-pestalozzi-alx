<section class="admin-map admin-page mapa-page" data-page="admin-map">
    <header class="admin-page__header admin-map__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operacion en piso</span>
            <h2 class="admin-page__title">Mapa operativo</h2>
            <p class="admin-page__subtitle">Gestion de mesas, reservaciones y tickets activos.</p>
        </div>

        <div class="admin-toolbar admin-map__toolbar">
            <div class="mapa-date-wrap" id="mapa-date-picker">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <button class="mapa-date-btn" id="mapa-date-display" type="button"></button>
                <input type="hidden" id="mapa-fecha" value="<?php echo date('Y-m-d'); ?>">
                <div class="mapa-cal" id="mapa-calendar" aria-hidden="true">
                    <div class="mapa-cal__nav">
                        <button class="mapa-cal__nav-btn" id="mapa-cal-prev" type="button" aria-label="Mes anterior">&lsaquo;</button>
                        <span class="mapa-cal__label" id="mapa-cal-label"></span>
                        <button class="mapa-cal__nav-btn" id="mapa-cal-next" type="button" aria-label="Mes siguiente">&rsaquo;</button>
                    </div>
                    <div class="mapa-cal__weekdays">
                        <span>D</span><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span>
                    </div>
                    <div class="mapa-cal__grid" id="mapa-cal-grid"></div>
                </div>
            </div>
            <div class="mapa-live-badge" id="mapa-live-badge">
                <span class="mapa-live-dot"></span>
                <span>Live</span>
            </div>
        </div>
    </header>

    <div class="mapa-shell admin-map__shell">
        <div class="mapa-body">
            <aside class="mapa-sidebar admin-card">
                <div class="mapa-sidebar-head">
                    <span class="mapa-sidebar-title">Reservaciones</span>
                    <span class="mapa-reserva-count" id="mapa-reserva-count">0</span>
                </div>

                <div class="mapa-leyenda">
                    <span class="mapa-leyenda-item mapa-leyenda-item--libre">Libre</span>
                    <span class="mapa-leyenda-item mapa-leyenda-item--proxima">Proxima</span>
                    <span class="mapa-leyenda-item mapa-leyenda-item--bloqueada">Bloqueada</span>
                    <span class="mapa-leyenda-item mapa-leyenda-item--ocupada">Ocupada</span>
                    <span class="mapa-leyenda-item mapa-leyenda-item--con-ticket">Ticket</span>
                </div>

                <div class="mapa-reservas-list" id="mapa-reservas-list">
                    <div class="mapa-empty-state">
                        <span class="mapa-empty-icon">○</span>
                        <span>Cargando...</span>
                    </div>
                </div>
            </aside>

            <div class="mapa-canvas-wrap admin-card">
                <div class="mapa-canvas" id="mapa-canvas"></div>
                <div class="mapa-canvas-overlay" id="mapa-loading">
                    <div class="mapa-spinner"></div>
                </div>
            </div>
        </div>

        <div class="mesa-modal" id="mesa-modal">
            <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
            <div class="mesa-modal__panel">
                <div class="mesa-modal__handle"></div>
                <button class="mesa-modal__close" id="mesa-modal-close" type="button" aria-label="Cerrar">x</button>
                <div id="mesa-modal-content"></div>
            </div>
        </div>
    </div>
</section>
