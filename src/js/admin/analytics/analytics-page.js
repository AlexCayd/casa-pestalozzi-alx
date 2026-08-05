/**
 * Renderiza métricas y actividad reciente del dashboard de analytics.
 * Conecta los datos mock con la vista y delega la creación de gráficas.
 */
(function () {
  function formatCurrency(amount) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 0,
    }).format(amount);
  }

  function formatDate(value) {
    return new Intl.DateTimeFormat("es-MX", {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(value.replace(" ", "T")));
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(
      /[&<>"']/g,
      (ch) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[ch],
    );
  }

  /** Propina en monto y, si el ticket tuvo consumo, su porcentaje. */
  function tipCell(ticket) {
    const tip = Number(ticket.propina) || 0;

    if (tip <= 0) {
      return '<span class="admin-tk__tip admin-tk__tip--none">—</span>';
    }

    const pct =
      ticket.propina_pct != null
        ? `<span class="admin-tk__tip-pct">${Number(ticket.propina_pct).toFixed(1)}%</span>`
        : "";

    return `<span class="admin-tk__tip">${formatCurrency(tip)}</span>${pct}`;
  }

  function statusLabel(status) {
    const labels = {
      closed: "Cerrado",
      open: "Abierto",
      cancelled: "Cancelado",
    };

    return labels[status] || status;
  }

  function deltaBadge(metric) {
    if (metric.delta == null) {
      return "";
    }

    const up = metric.delta >= 0;
    const sign = up ? "+" : "";
    const dir = up ? "is-up" : "is-down";
    const arrow = up ? "M7 14 12 9l5 5" : "M7 10l5 5 5-5";

    return `
      <span class="admin-hero__delta ${dir}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="${arrow}"/></svg>
        ${sign}${metric.delta}%
        <small>${escapeHtml(metric.delta_label || "")}</small>
      </span>
    `;
  }

  function renderMetrics(metrics) {
    const heroContainer = document.querySelector("[data-admin-metrics-hero]");
    const supportContainer = document.querySelector(
      "[data-admin-metrics-primary]",
    );
    const stripContainer = document.querySelector(
      "[data-admin-metrics-secondary]",
    );

    if (!heroContainer || !supportContainer || !stripContainer) {
      return;
    }

    // Nivel 1 — el número con el que abre el tablero.
    const hero = metrics.find((metric) => metric.featured);

    // Tres bloques repartidos en la altura de la tarjeta: encabezado, cifra y
    // pie comparativo. Antes todo se apilaba arriba y la mitad inferior quedaba
    // vacía, porque la gráfica vecina impone la altura. El periodo anterior
    // (hero.prev) ya llegaba del backend y no se pintaba.
    heroContainer.innerHTML = hero
      ? `
        <article class="admin-hero">
          <div class="admin-hero__head">
            <span class="admin-hero__label">${escapeHtml(hero.label)}</span>
          </div>
          <div class="admin-hero__main">
            <strong class="admin-hero__value">${escapeHtml(hero.value)}</strong>
            <span class="admin-hero__detail">${escapeHtml(hero.detail)}</span>
          </div>
          <div class="admin-hero__foot">
            ${deltaBadge(hero)}
            ${
              hero.prev
                ? `<span class="admin-hero__prev">${escapeHtml(hero.prev)} en el periodo anterior</span>`
                : ""
            }
          </div>
        </article>
      `
      : "";

    // Niveles 2 y 3 — soporte y contexto.
    const tile = (metric) => `
      <article class="admin-metric-card">
        <span>${escapeHtml(metric.label)}</span>
        <strong>${escapeHtml(metric.value)}</strong>
        <small>${escapeHtml(metric.detail)}</small>
      </article>
    `;

    const chip = (metric) => `
      <div class="admin-metric-chip">
        <span class="admin-metric-chip__label">${escapeHtml(metric.label)}</span>
        <strong class="admin-metric-chip__value">${escapeHtml(metric.value)}</strong>
        <small class="admin-metric-chip__detail">${escapeHtml(metric.detail)}</small>
      </div>
    `;

    supportContainer.innerHTML = metrics
      .filter((metric) => !metric.featured && metric.priority !== "secondary")
      .map(tile)
      .join("");

    stripContainer.innerHTML = metrics
      .filter((metric) => metric.priority === "secondary")
      .map(chip)
      .join("");
  }

  function renderSummary(data) {
    const tbody = document.querySelector("[data-admin-summary]");

    if (!tbody) {
      return;
    }

    tbody.innerHTML = data.tickets
      .map((ticket) => {
        const payment = data.payments.find(
          (item) => item.folio === ticket.folio,
        );
        const statusClass =
          ticket.status === "open"
            ? "admin-status--open"
            : ticket.status === "cancelled"
              ? "admin-status--cancelled"
              : "";

        return `
                <tr>
                    <td>${ticket.folio}</td>
                    <td>${escapeHtml(ticket.mesa || "—")}</td>
                    <td>${formatDate(ticket.created_at)}</td>
                    <td><span class="admin-status ${statusClass}">${statusLabel(ticket.status)}</span></td>
                    <td>${formatCurrency(ticket.total)}</td>
                    <td>${tipCell(ticket)}</td>
                    <td>${payment ? payment.metodo : "Pendiente"}</td>
                </tr>
            `;
      })
      .join("");
  }

  function initAnalyticsPage() {
    const page = document.querySelector("[data-admin-analytics]");

    if (!page || !window.AdminAnalyticsMock) {
      return;
    }

    const data = window.AdminAnalyticsMock;

    // Datos reales de la BD (métricas, tabla y gráficas).
    renderMetrics(data.metrics || []);
    renderSummary(data);

    if (window.AdminAnalyticsCharts) {
      window.AdminAnalyticsCharts.init(data.charts);
    }
    // El filtro de periodo lo maneja range-picker.js, que se autoinicializa.
  }

  window.AdminAnalyticsPage = {
    init: initAnalyticsPage,
  };
})();
