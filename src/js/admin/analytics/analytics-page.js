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
    const caption = document.querySelector("[data-analytics-caption]");
    const selects = document.querySelectorAll("[data-analytics-filter]");

    if (!selects.length) {
      return;
    }

    const state = { range: 7, service: "todos", source: "todas" };
    const rangeLabels = { 7: "Últimos 7 días", 3: "Últimos 3 días", 1: "Solo ayer" };
    const serviceLabels = { comida: "Comida", cena: "Cena" };
    const sourceLabels = { web: "Web", whatsapp: "WhatsApp", phone: "Teléfono", walk_in: "Walk-in" };

    function updateCaption() {
      const parts = [rangeLabels[state.range] || "Últimos 7 días"];
      if (serviceLabels[state.service]) parts.push(serviceLabels[state.service]);
      if (sourceLabels[state.source]) parts.push(sourceLabels[state.source]);
      if (caption) caption.textContent = parts.join(" · ");
    }

    selects.forEach((select) => {
      select.addEventListener("change", () => {
        const key = select.getAttribute("data-analytics-filter");

        if (key === "range") state.range = parseInt(select.value, 10) || 7;
        if (key === "service") state.service = select.value;
        if (key === "source") state.source = select.value;

        updateCaption();

        if (window.AdminAnalyticsCharts && window.AdminAnalyticsCharts.applyFilters) {
          window.AdminAnalyticsCharts.applyFilters(state);
        }
      });
    });

    updateCaption();
  }

  function initAnalyticsPage() {
    const page = document.querySelector("[data-admin-analytics]");

    if (!page || !window.AdminAnalyticsMock) {
      return;
    }

    const data = window.AdminAnalyticsMock;

    // Métrica real de propinas (viene del backend); el resto sigue mock.
    const metrics = data.metrics.slice();
    const real = window.CP_METRICS_REALES;
    if (real && real.propinas) {
      const p = real.propinas;
      const money = (n) =>
        "$" + Number(n || 0).toLocaleString("es-MX", { maximumFractionDigits: 0 });
      metrics.splice(1, 0, {
        label: "Propinas del periodo",
        value: money(p.total),
        detail: p.tickets
          ? p.tickets + " ticket(s) · prom. " + money(p.promedio)
          : "Sin propinas registradas",
      });
    }

    renderMetrics(metrics);
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
