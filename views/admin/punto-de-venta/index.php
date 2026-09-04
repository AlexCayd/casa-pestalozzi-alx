<?php
/**
 * Módulo de Punto de Venta en el admin: sólo la tarjeta que lanza el POS.
 *
 * La "Lista estructurada de mesas" que vivía debajo se retiró. Era una foto del
 * piso al momento de cargar la página —sin refresco— y el mismo estado, vivo,
 * está a un clic en el mapa del POS: mantener las dos sólo abría la puerta a
 * que discreparan.
 */
?>
<section class="admin-map admin-map--launch admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación en piso</span>
            <h2 class="admin-page__title">Punto de Venta</h2>
            <p class="admin-page__subtitle">
                Gestión de mesas, reservaciones y tickets activos en tiempo real.
                El punto de venta se abre como herramienta operativa a pantalla completa.
            </p>
        </div>
    </header>

    <article class="admin-card admin-launch-card" data-reveal>
        <div class="admin-launch-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z"/>
                <path d="M9 3v15"/><path d="M15 6v15"/>
            </svg>
        </div>
        <div class="admin-launch-card__body">
            <h3>Abrir el punto de venta</h3>
            <p>Reservaciones del día, apertura y cierre de tickets, y envío de comandas por área desde una vista dedicada.</p>
        </div>
        <a
            class="admin-btn admin-btn--primary admin-launch-card__button"
            href="/punto-de-venta"
            target="_blank"
            rel="noopener"
            data-admin-magnetic
        >
            Abrir punto de venta
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M7 17 17 7"/><path d="M7 7h10v10"/>
            </svg>
        </a>
    </article>
</section>
