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

  function initFilters() {
    const rangeSelect = document.querySelector('[data-analytics-filter="range"]');
    const rangeBox = document.querySelector("[data-analytics-range]");
    const applyBtn = document.querySelector("[data-analytics-apply]");
    const desdeInput = document.querySelector("[data-analytics-desde]");
    const hastaInput = document.querySelector("[data-analytics-hasta]");

    if (!rangeSelect) {
      return;
    }

    // Navega recargando la página con los parámetros; el servidor filtra.
    function go(params) {
      const url = new URL(window.location.href);
      url.search = "";
      Object.keys(params).forEach((k) => {
        if (params[k] !== "" && params[k] != null) url.searchParams.set(k, params[k]);
      });
      window.location.assign(url.toString());
    }

    rangeSelect.addEventListener("change", () => {
      const val = rangeSelect.value;
      if (val === "custom") {
        // Mostrar el rango personalizado y esperar a "Aplicar".
        if (rangeBox) rangeBox.hidden = false;
        return;
      }
      go({ rango: val });
    });

    if (applyBtn) {
      applyBtn.addEventListener("click", () => {
        const desde = desdeInput ? desdeInput.value : "";
        const hasta = hastaInput ? hastaInput.value : "";
        if (!desde || !hasta) return;
        go({ desde: desde, hasta: hasta });
      });
    }
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

    initFilters();
  }

  window.AdminAnalyticsPage = {
    init: initAnalyticsPage,
  };
})();
