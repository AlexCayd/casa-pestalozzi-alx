<section class="admin-map admin-map--launch admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Operación en piso</span>
            <h2 class="admin-page__title">Mapa operativo</h2>
            <p class="admin-page__subtitle">
                Gestión de mesas, reservaciones y tickets activos en tiempo real.
                El mapa se abre como herramienta operativa a pantalla completa.
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
            <span class="admin-launch-card__eyebrow">Live</span>
            <h3>Abrir el mapa de mesas</h3>
            <p>Reservaciones del día, apertura y cierre de tickets, y envío de comandas por área desde una vista dedicada.</p>
        </div>
        <a
            class="admin-btn admin-btn--primary admin-launch-card__button"
            href="/mapa"
            target="_blank"
            rel="noopener"
            data-admin-magnetic
        >
            Abrir mapa
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M7 17 17 7"/><path d="M7 7h10v10"/>
            </svg>
        </a>
    </article>
</section>

<style>
    .admin-launch-card {
        display: flex;
        align-items: center;
        gap: clamp(16px, 3vw, 32px);
        padding: clamp(22px, 3vw, 34px);
        flex-wrap: wrap;
    }

    .admin-launch-card__icon {
        display: grid;
        place-items: center;
        width: 68px;
        height: 68px;
        flex: 0 0 auto;
        border-radius: 18px;
        color: var(--admin-gold);
        background: var(--admin-neutral-bg);
        border: 1px solid var(--admin-border);
    }

    .admin-launch-card__icon svg { width: 30px; height: 30px; }

    .admin-launch-card__body {
        flex: 1 1 260px;
        min-width: 0;
    }

    .admin-launch-card__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--admin-sage);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .admin-launch-card__eyebrow::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--admin-sage);
        box-shadow: 0 0 8px var(--admin-sage);
    }

    .admin-launch-card__body h3 { margin: 6px 0 6px; }

    .admin-launch-card__button {
        gap: 8px;
        padding-inline: 22px;
        min-height: 48px;
        flex: 0 0 auto;
    }

    .admin-launch-card__button svg { width: 16px; height: 16px; }
</style>
