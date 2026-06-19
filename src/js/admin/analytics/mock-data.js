/**
 * Proporciona datos mock para métricas, actividad y gráficas de analytics.
 * Simula la respuesta operativa hasta conectar una fuente real del backend.
 */
(function () {
    window.AdminAnalyticsMock = {
        period: {
            from: '2026-06-10',
            to: '2026-06-16',
            label: 'Últimos 7 días'
        },
        metrics: [
            { label: 'Ventas totales', value: '$86,480', detail: '+12% vs. periodo anterior', featured: true },
            { label: 'Ticket promedio', value: '$642', detail: '135 tickets cerrados' },
            { label: 'Reservaciones del día', value: '28', detail: '92 personas esperadas' },
            { label: 'Ocupación estimada', value: '74%', detail: 'Cena con mayor demanda' },
            { label: 'Tasa de no-show', value: '6.5%', detail: '-1.4 pts esta semana' },
            { label: 'Producto más vendido', value: 'Pizza Pestalozzi', detail: '64 unidades', priority: 'secondary' },
            { label: 'Método principal', value: 'Tarjeta', detail: '54% del total cobrado', priority: 'secondary' },
            { label: 'Tickets abiertos', value: '9', detail: '3 requieren seguimiento', priority: 'secondary' }
        ],
        tickets: [
            { folio: 'T-1048', total: 1260, subtotal: 1360, descuento: 100, status: 'closed', created_at: '2026-06-16 13:12:00', closed_at: '2026-06-16 14:05:00' },
            { folio: 'T-1047', total: 740, subtotal: 740, descuento: 0, status: 'closed', created_at: '2026-06-16 12:44:00', closed_at: '2026-06-16 13:18:00' },
            { folio: 'T-1046', total: 980, subtotal: 1040, descuento: 60, status: 'open', created_at: '2026-06-16 12:20:00', closed_at: null },
            { folio: 'T-1045', total: 520, subtotal: 520, descuento: 0, status: 'cancelled', created_at: '2026-06-15 20:22:00', closed_at: null },
            { folio: 'T-1044', total: 2140, subtotal: 2140, descuento: 0, status: 'closed', created_at: '2026-06-15 19:45:00', closed_at: '2026-06-15 21:10:00' }
        ],
        ticketItems: [
            { folio: 'T-1048', producto: 'Pizza Pestalozzi', cantidad: 3, subtotal: 870 },
            { folio: 'T-1048', producto: 'Vino de casa', cantidad: 2, subtotal: 490 },
            { folio: 'T-1047', producto: 'Pan artesanal', cantidad: 4, subtotal: 320 },
            { folio: 'T-1047', producto: 'Ensalada de temporada', cantidad: 2, subtotal: 420 },
            { folio: 'T-1046', producto: 'Pasta fresca', cantidad: 2, subtotal: 560 },
            { folio: 'T-1046', producto: 'Pizza Pestalozzi', cantidad: 1, subtotal: 290 }
        ],
        products: [
            { nombre: 'Pizza Pestalozzi', categoria: 'Pizzas', precio: 290 },
            { nombre: 'Pasta fresca', categoria: 'Pastas', precio: 280 },
            { nombre: 'Pan artesanal', categoria: 'Panadería', precio: 80 },
            { nombre: 'Ensalada de temporada', categoria: 'Entradas', precio: 210 },
            { nombre: 'Vino de casa', categoria: 'Bebidas', precio: 245 }
        ],
        payments: [
            { folio: 'T-1048', metodo: 'Tarjeta', monto: 1260 },
            { folio: 'T-1047', metodo: 'Efectivo', monto: 740 },
            { folio: 'T-1045', metodo: 'Cancelado', monto: 0 },
            { folio: 'T-1044', metodo: 'Tarjeta', monto: 1640 },
            { folio: 'T-1044', metodo: 'Transferencia', monto: 500 }
        ],
        reservations: [
            { fecha: '2026-06-16', hora: '13:30', personas: 4, fuente: 'web', servicio: 'comida', estado: 'confirmada' },
            { fecha: '2026-06-16', hora: '14:00', personas: 2, fuente: 'phone', servicio: 'comida', estado: 'confirmada' },
            { fecha: '2026-06-16', hora: '20:30', personas: 6, fuente: 'whatsapp', servicio: 'cena', estado: 'pendiente' },
            { fecha: '2026-06-15', hora: '21:00', personas: 3, fuente: 'walk_in', servicio: 'cena', estado: 'no_show' },
            { fecha: '2026-06-14', hora: '18:00', personas: 5, fuente: 'web', servicio: 'cena', estado: 'confirmada' }
        ],
        tables: [
            { codigo: 'M1', capacidad: 2, tipo: 'mesa' },
            { codigo: 'M2', capacidad: 4, tipo: 'mesa' },
            { codigo: 'M3', capacidad: 6, tipo: 'mesa' },
            { codigo: 'B1', capacidad: 1, tipo: 'barra' },
            { codigo: 'B2', capacidad: 1, tipo: 'barra' }
        ],
        charts: {
            salesByDay: {
                labels: ['10 Jun', '11 Jun', '12 Jun', '13 Jun', '14 Jun', '15 Jun', '16 Jun'],
                values: [9800, 11320, 10750, 14200, 16840, 12680, 10890]
            },
            salesByCategory: {
                labels: ['Pizzas', 'Pastas', 'Panadería', 'Bebidas', 'Entradas'],
                values: [28400, 19680, 12800, 15720, 9880]
            },
            paymentMethods: {
                labels: ['Tarjeta', 'Efectivo', 'Transferencia'],
                values: [46699, 24647, 15134]
            },
            topProducts: {
                labels: ['Pizza Pestalozzi', 'Pasta fresca', 'Pan artesanal', 'Vino de casa', 'Ensalada de temporada'],
                values: [64, 48, 42, 35, 31]
            },
            reservationsByDay: {
                labels: ['10 Jun', '11 Jun', '12 Jun', '13 Jun', '14 Jun', '15 Jun', '16 Jun'],
                values: [18, 22, 19, 31, 34, 27, 28]
            },
            reservationSources: {
                labels: ['Web', 'WhatsApp', 'Teléfono', 'Walk-in'],
                values: [42, 31, 18, 9]
            }
        }
    };
})();
