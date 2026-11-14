const { chromium } = require('playwright');
const path = require('node:path');

const [port, token, evidence] = process.argv.slice(2);
if (!port || !token || !evidence) throw new Error('Falta puerto, token o carpeta de evidencia.');
const origin = `http://127.0.0.1:${port}`;
const root = '/admin/almacenes/proveedores';

(async () => {
  const executablePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ headless: true, executablePath });
  const errors = [];
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, hasTouch: true, extraHTTPHeaders: { 'X-Proveedores-Test-Token': token } });
  const page = await context.newPage();
  page.on('pageerror', e => errors.push(e.message));
  const settle = async () => {
    await page.evaluate(() => document.fonts.ready);
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    await page.waitForTimeout(300);
    const sidebarOpen = await page.locator('body').evaluate(el => el.classList.contains('is-sidebar-open'));
    if (sidebarOpen) {
      await page.locator('[data-admin-sidebar-close]').click();
      await page.waitForTimeout(300);
    }
  };
  const navigate = async url => {
    const result = await page.goto(url, { waitUntil: 'networkidle' });
    await settle();
    return result;
  };
  const login = async actor => {
    await context.clearCookies();
    const response = await context.request.get(`${origin}/__fixture/session?actor=${actor}`);
    if (response.status() !== 204) throw new Error(`No inició actor ${actor}: ${response.status()}`);
  };
  try {
    await login('edit');
    let response = await navigate(`${origin}${root}/ficha?id=1001`);
    if (!response || response.status() !== 200 || await page.locator('[data-proveedor-vista="articulos"]').count() !== 1) throw new Error('No abrió la ficha real de Artículos.');
    const activeArticleTab = page.locator('.proveedores-vistas a[aria-current="page"]');
    if (await activeArticleTab.count() !== 1 || (await activeArticleTab.evaluate(el => getComputedStyle(el).backgroundColor)) === 'rgba(0, 0, 0, 0)') throw new Error('La vista activa no se reconoce con el fondo de navegación.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-articulos-desktop.png') });
    const editLink = await page.getByRole('link', { name: 'Editar artículos' }).count();
    const assignmentsForm = await page.locator('form[action$="/asignaciones"]').count();
    const viewHeadings = await page.locator('#proveedor-articulos,#proveedor-pedidos,#proveedor-datos').count();
    if (editLink !== 1 || assignmentsForm !== 0 || viewHeadings !== 1) throw new Error('La vista de lectura no está separada de la edición.');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(300);
    const articlesMobileWidth = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    const articleScroll = await page.evaluate(() => {
      const wrap = document.querySelector('#proveedor-articulos .admin-table-wrap');
      if (!wrap) return { maxScroll: 0, scrollLeft: 0 };
      const maxScroll = wrap.scrollWidth - wrap.clientWidth; wrap.scrollLeft = maxScroll;
      return { maxScroll, scrollLeft: wrap.scrollLeft };
    });
    await page.screenshot({ path: path.join(evidence, 'proveedores-articulos-mobile.png') });
    if (articlesMobileWidth.document > articlesMobileWidth.viewport) throw new Error(`Overflow Artículos: ${articlesMobileWidth.document}px.`);

    await page.setViewportSize({ width: 1440, height: 1000 });
    response = await navigate(`${origin}${root}/ficha?id=1001&vista=entradas`);
    if (!response || response.status() !== 200 || await page.locator('#proveedor-pedidos').count() !== 1 || await page.locator('#proveedor-articulos,#proveedor-datos').count() !== 0) throw new Error('Entradas no es una vista independiente.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-entradas-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(300);
    const entriesWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    if (entriesWidth > 390) throw new Error(`Overflow Entradas: ${entriesWidth}px.`);
    await page.setViewportSize({ width: 1440, height: 1000 });
    response = await navigate(`${origin}${root}/ficha?id=1001&vista=datos`);
    if (!response || response.status() !== 200 || await page.locator('#proveedor-datos').count() !== 1 || await page.locator('#proveedor-articulos,#proveedor-pedidos').count() !== 0) throw new Error('Datos no es una vista independiente.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-datos-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(300);
    const dataWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    if (dataWidth > 390) throw new Error(`Overflow Datos: ${dataWidth}px.`);
    await page.setViewportSize({ width: 1440, height: 1000 });

    response = await navigate(`${origin}${root}/historial?id=1001&articulo=1002`);
    if (!response || response.status() !== 200 || await page.locator('.proveedor-precios__tabla tbody tr').count() !== 25) throw new Error('El historial HTTP real no carga 25 filas.');
    const chartPoints = page.locator('[data-price-point]');
    const chartPointCount = await chartPoints.count();
    if (chartPointCount !== 40) throw new Error('El gráfico no limita su DOM a 40 eventos.');
    const firstPoint = chartPoints.first();
    const pointInfo = await firstPoint.evaluate(el => ({ date: el.dataset.date, price: el.dataset.value, difference: el.dataset.difference, variation: el.dataset.variation }));
    await firstPoint.hover();
    let tipText = await page.locator('[data-price-tooltip]').innerText();
    if (!tipText.includes(pointInfo.date) || !tipText.includes(pointInfo.price) || (pointInfo.difference && !tipText.includes(pointInfo.difference.replace('-', ''))) || (pointInfo.variation && !tipText.includes(pointInfo.variation))) throw new Error('Hover no muestra fecha, precio y variación exactos del punto.');
    await firstPoint.focus();
    await page.keyboard.press('End');
    if (await page.evaluate(() => document.activeElement?.dataset.index) !== '39') throw new Error('End no recorre al último punto del gráfico.');
    await page.keyboard.press('Enter');
    if (await chartPoints.nth(39).getAttribute('aria-pressed') !== 'true' || await chartPoints.nth(39).getAttribute('tabindex') !== '0') throw new Error('Enter no selecciona el punto ni conserva el foco roving.');
    await page.keyboard.press('ArrowLeft');
    if (await page.evaluate(() => document.activeElement?.dataset.index) !== '38') throw new Error('ArrowLeft no recorre al punto anterior.');
    await page.keyboard.press('ArrowRight');
    if (await page.evaluate(() => document.activeElement?.dataset.index) !== '39') throw new Error('ArrowRight no recorre al punto siguiente.');
    await page.keyboard.press('Home');
    await page.keyboard.press('Space');
    if (await chartPoints.first().getAttribute('aria-pressed') !== 'true') throw new Error('Espacio no activa el punto enfocado.');
    await page.keyboard.press('Escape');
    if (await page.locator('[data-price-point][aria-pressed="true"]').count() !== 0 || !(await page.locator('[data-price-tooltip]').innerText()).includes('Enfoca')) throw new Error('Escape no limpia la selección de forma accesible.');
    await chartPoints.nth(22).click();
    if (await chartPoints.nth(22).getAttribute('aria-pressed') !== 'true') throw new Error('Click de mouse no fija el punto seleccionado.');
    // Verificar los tres puntos mediante las tres modalidades en cada ancho.
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.waitForTimeout(200);
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw new Error('Overflow global en ' + width);
      for (const index of [0, 20, 39]) {
        const point = chartPoints.nth(index);
        await point.click();
        if (await point.getAttribute('aria-pressed') !== 'true') throw new Error('Ratón ' + width + '/' + index);
        await point.tap();
        if (await point.getAttribute('aria-pressed') !== 'true') throw new Error('Toque ' + width + '/' + index);
        await chartPoints.first().focus();
        if (index === 39) await page.keyboard.press('End');
        else for (let i = 0; i < index; i++) await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');
        if (await point.getAttribute('aria-pressed') !== 'true') throw new Error('Teclado ' + width + '/' + index);
      }
      await page.screenshot({ path: path.join(evidence, 'historico-despues-' + width + '.png') });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    const firstPageNext = await page.getByRole('link', { name: 'Siguiente' }).getAttribute('href');
    if (!firstPageNext?.includes('articulo=1002&pagina=2')) throw new Error('El vínculo de paginación no conserva el artículo.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-historial-desktop.png') });
    await page.locator('.proveedor-precios__tabla').locator('..').screenshot({ path: path.join(evidence, 'proveedores-historial-tabla-desktop.png') });
    await page.getByRole('link', { name: 'Siguiente' }).click();
    await page.waitForLoadState('networkidle');
    await settle();
    if (await page.locator('.proveedor-precios__tabla tbody tr').count() !== 20 || !page.url().includes('articulo=1002&pagina=2')) throw new Error('La navegación paginada HTTP no conserva artículo o 20 eventos.');
    await page.getByRole('link', { name: 'Volver a Artículos' }).click();
    await page.waitForLoadState('networkidle');
    await settle();
    if (await page.locator('[data-proveedor-vista="articulos"]').count() !== 1) throw new Error('El regreso del histórico no vuelve a Artículos.');
    await navigate(`${origin}${root}/historial?id=1001&articulo=1002`);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(300);
    const historyMobileWidth = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    const historyScroll = await page.evaluate(() => {
      const wrap = document.querySelector('.proveedor-precios__tabla')?.closest('.admin-table-wrap');
      if (!wrap) return { maxScroll: 0, scrollLeft: 0 };
      const maxScroll = wrap.scrollWidth - wrap.clientWidth; wrap.scrollLeft = maxScroll;
      return { maxScroll, scrollLeft: wrap.scrollLeft };
    });
    await chartPoints.nth(18).tap();
    if (await chartPoints.nth(18).getAttribute('aria-pressed') !== 'true' || !(await page.locator('[data-price-tooltip]').innerText()).includes(await chartPoints.nth(18).getAttribute('data-date'))) throw new Error('Toque no selecciona el punto cercano ni muestra la fecha.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-historial-mobile.png') });
    await page.locator('.proveedor-precios__tabla').locator('..').screenshot({ path: path.join(evidence, 'proveedores-historial-tabla-mobile.png') });
    if (historyMobileWidth.document > historyMobileWidth.viewport) throw new Error(`Overflow Historial: ${historyMobileWidth.document}px.`);
    if (historyScroll.maxScroll < 1 || historyScroll.scrollLeft !== historyScroll.maxScroll) throw new Error('La tabla de historial no desplaza por dentro en móvil.');

    await login('read');
    response = await navigate(`${origin}${root}/ficha?id=1001`);
    if (!response || response.status() !== 200 || await page.getByRole('link', { name: 'Editar artículos' }).count() !== 0 || await page.locator('form[action$="/asignaciones"],form[action$="/guardar"]').count() !== 0) throw new Error('El usuario lector recibe controles de escritura.');
    await page.screenshot({ path: path.join(evidence, 'proveedores-articulos-lectura.png') });
    await navigate(`${origin}${root}/historial?id=1001&articulo=1002`);
    if (await page.locator('.proveedor-historial form').count() !== 0) throw new Error('La vista individual de histórico no es de solo lectura.');
    if (errors.length) throw new Error(errors.join('\n'));
    console.log(JSON.stringify({
      routes: ['articulos', 'entradas', 'datos', 'historial'],
      desktop: '1440x1000',
      articlesMobile: articlesMobileWidth,
      entriesMobileWidth: entriesWidth,
      dataMobileWidth: dataWidth,
      historyMobile: historyMobileWidth,
      scrollArticulos: articleScroll,
      scrollHistorial: historyScroll,
      enlacePaginacionConservaArticulo: firstPageNext,
      consultaSinControlesEdicion: true,
      chartPoints: chartPointCount,
      chartHoverExacto: true,
      chartTeclado: 'End, Enter, Home, Space, Escape',
      chartTouchSelected: true,
      erroresPagina: errors.length,
      capturas: ['proveedores-articulos-desktop.png', 'proveedores-articulos-mobile.png', 'proveedores-entradas-desktop.png', 'proveedores-datos-desktop.png', 'proveedores-historial-desktop.png', 'proveedores-historial-tabla-desktop.png', 'proveedores-historial-mobile.png', 'proveedores-historial-tabla-mobile.png', 'proveedores-articulos-lectura.png']
    }, null, 2));
  } finally {
    await context.close();
    await browser.close();
  }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
