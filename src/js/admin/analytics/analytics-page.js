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

  function statusLabel(status) {
    const labels = {
      closed: "Cerrado",
      open: "Abierto",
      cancelled: "Cancelado",
    };

    return labels[status] || status;
  }

  function renderMetrics(metrics) {
    const primaryContainer = document.querySelector(
      "[data-admin-metrics-primary]",
    );
    const secondaryContainer = document.querySelector(
      "[data-admin-metrics-secondary]",
    );

    if (!primaryContainer || !secondaryContainer) {
      return;
    }

    const metricMarkup = (metric) => {
      const classes = [
        "admin-metric-card",
        metric.featured ? "admin-metric-card--featured" : "",
        metric.priority === "secondary" ? "admin-metric-card--secondary" : "",
      ]
        .filter(Boolean)
        .join(" ");

      return `
            <article class="${classes}">
                <span>${metric.label}</span>
                <strong>${metric.value}</strong>
                <small>${metric.detail}</small>
            </article>
        `;
    };

    primaryContainer.innerHTML = metrics
      .filter((metric) => metric.priority !== "secondary")
      .map(metricMarkup)
      .join("");

    secondaryContainer.innerHTML = metrics
      .filter((metric) => metric.priority === "secondary")
      .map(metricMarkup)
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
                    <td>${formatDate(ticket.created_at)}</td>
                    <td><span class="admin-status ${statusClass}">${statusLabel(ticket.status)}</span></td>
                    <td>${formatCurrency(ticket.total)}</td>
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

    renderMetrics(data.metrics);
    renderSummary(data);

    if (window.AdminAnalyticsCharts) {
      window.AdminAnalyticsCharts.init(data.charts);
    }
  }

  window.AdminAnalyticsPage = {
    init: initAnalyticsPage,
  };
})();
