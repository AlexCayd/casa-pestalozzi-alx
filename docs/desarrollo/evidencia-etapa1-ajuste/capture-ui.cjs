const { chromium } = require('playwright');
const path = require('node:path');

(async () => {
  const executablePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ headless: true, executablePath });
  const errors = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    page.on('pageerror', e => errors.push(e.message));
    const base = 'http://127.0.0.1:8097';
    const response = await page.goto(base + '/_qa-precios-proveedores-edicion.html', { waitUntil: 'networkidle' });
    if (!response || response.status() !== 200) throw new Error('Fixture edición no disponible.');
    await page.evaluate(() => document.fonts.ready);
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    const linksEdit = await page.locator('a.proveedor-historial-link').count();
    const linkHref = await page.locator('a.proveedor-historial-link').first().getAttribute('href');
    const nextHref = await page.locator('.proveedor-precios__paginacion a').last().getAttribute('href');
    const contextual = await page.locator('.proveedor-precios__contexto').innerText();
    if (!linksEdit || !linkHref?.includes('historial_articulo=1002#historial-precios')) throw new Error('Falta link de histórico en modo edición.');
    if (!nextHref?.includes('historial_articulo=1002')) throw new Error('La página siguiente no conserva el artículo filtrado.');
    if (!contextual.includes('Proveedor Restaurante') || !contextual.includes('Leche compartida') || !contextual.includes('$0.0150')) throw new Error('Panel contextual incompleto.');
    await page.screenshot({ path: path.join(__dirname, 'precios-proveedor-desktop.png') });

    await page.setViewportSize({ width: 390, height: 844 });
    const mobile = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    const scrollData = await page.evaluate(() => {
      const wrap = document.querySelector('.proveedor-precios__tabla')?.parentElement;
      if (!wrap) return null;
      const max = wrap.scrollWidth - wrap.clientWidth;
      wrap.scrollLeft = max;
      return { maxScroll: max, scrollLeft: wrap.scrollLeft };
    });
    await page.screenshot({ path: path.join(__dirname, 'precios-proveedor-mobile.png') });
    await page.locator('.proveedor-asignaciones').screenshot({ path: path.join(__dirname, 'precios-proveedor-mobile-asignaciones.png') });
    await page.locator('.proveedor-precios__tabla').locator('..').screenshot({ path: path.join(__dirname, 'precios-proveedor-mobile-tabla.png') });
    if (mobile.document > mobile.viewport) throw new Error('Overflow móvil: ' + mobile.document + '/' + mobile.viewport);
    if (!scrollData || scrollData.maxScroll < 1 || scrollData.scrollLeft !== scrollData.maxScroll) throw new Error('La tabla no conserva desplazamiento interno.');

    await page.setViewportSize({ width: 1440, height: 1000 });
    const readResponse = await page.goto(base + '/_qa-precios-proveedores-consulta.html', { waitUntil: 'networkidle' });
    if (!readResponse || readResponse.status() !== 200) throw new Error('Fixture solo lectura no disponible.');
    const linksRead = await page.locator('a.proveedor-historial-link').count();
    const writableForm = await page.locator('form[action*="/asignaciones"],form[action*="/guardar"]').count();
    if (!linksRead || writableForm !== 0) throw new Error('La consulta no muestra enlace histórico o expone escritura.');
    await page.screenshot({ path: path.join(__dirname, 'precios-proveedor-lectura.png') });
    if (errors.length) throw new Error(errors.join('\n'));
    console.log(JSON.stringify({
      desktop: '1440x1000',
      mobileViewport: mobile.viewport,
      mobileDocumentWidth: mobile.document,
      linksEdicion: linksEdit,
      linksConsulta: linksRead,
      hrefHistorial: linkHref,
      paginacionConservaFiltro: true,
      contexto: contextual.replace(/\s+/g, ' ').trim(),
      scrollTabla: scrollData,
      formulariosEscrituraEnConsulta: writableForm,
      pageErrors: errors.length
    }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
