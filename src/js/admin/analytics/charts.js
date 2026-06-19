/**
 * Configura las gráficas Chart.js del dashboard de analytics.
 * Centraliza paleta, opciones visuales y creación responsive de cada canvas.
 */
(function () {
    const palette = {
        green: '#476f58',
        greenDark: '#2f4d3d',
        gold: '#b9863f',
        terracotta: '#b95043',
        ink: '#211f1b',
        muted: '#766f65',
        grid: 'rgba(118, 111, 101, 0.18)',
        cream: '#f4f1eb'
    };

    function getCanvas(id) {
        return document.getElementById(id);
    }

    function baseOptions(extraOptions) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: palette.muted,
                        boxWidth: 12,
                        boxHeight: 12
                    }
                },
                tooltip: {
                    backgroundColor: palette.ink,
                    padding: 12,
                    titleColor: '#fff',
                    bodyColor: '#fff'
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: palette.muted }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: palette.grid },
                    ticks: { color: palette.muted }
                }
            }
        }, extraOptions || {});
    }

    function createChart(id, config) {
        const canvas = getCanvas(id);

        if (!canvas || typeof window.Chart === 'undefined') {
            return null;
        }

        return new window.Chart(canvas, config);
    }

    function initAnalyticsCharts(data) {
        createChart('salesByDayChart', {
            type: 'line',
            data: {
                labels: data.salesByDay.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByDay.values,
                    borderColor: palette.green,
                    backgroundColor: 'rgba(71, 111, 88, 0.14)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: baseOptions()
        });

        createChart('salesByCategoryChart', {
            type: 'bar',
            data: {
                labels: data.salesByCategory.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByCategory.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta, palette.greenDark, '#8b7d68'],
                    borderRadius: 6
                }]
            },
            options: baseOptions()
        });

        createChart('paymentMethodsChart', {
            type: 'doughnut',
            data: {
                labels: data.paymentMethods.labels,
                datasets: [{
                    data: data.paymentMethods.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta],
                    borderColor: palette.cream,
                    borderWidth: 4
                }]
            },
            options: baseOptions({
                cutout: '68%',
                scales: {}
            })
        });

        createChart('topProductsChart', {
            type: 'bar',
            data: {
                labels: data.topProducts.labels,
                datasets: [{
                    label: 'Unidades',
                    data: data.topProducts.values,
                    backgroundColor: palette.gold,
                    borderRadius: 6
                }]
            },
            options: baseOptions({
                indexAxis: 'y'
            })
        });

        createChart('reservationsByDayChart', {
            type: 'line',
            data: {
                labels: data.reservationsByDay.labels,
                datasets: [{
                    label: 'Reservaciones',
                    data: data.reservationsByDay.values,
                    borderColor: palette.terracotta,
                    backgroundColor: 'rgba(185, 80, 67, 0.12)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: baseOptions()
        });

        createChart('reservationSourcesChart', {
            type: 'doughnut',
            data: {
                labels: data.reservationSources.labels,
                datasets: [{
                    data: data.reservationSources.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta, palette.greenDark],
                    borderColor: palette.cream,
                    borderWidth: 4
                }]
            },
            options: baseOptions({
                cutout: '62%',
                scales: {}
            })
        });
    }

    window.AdminAnalyticsCharts = {
        init: initAnalyticsCharts
    };
})();
