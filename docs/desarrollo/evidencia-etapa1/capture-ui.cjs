const { chromium } = require('playwright');
const path = require('node:path');

(async () => {
  const executablePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ headless: true, executablePath });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    const response = await page.goto('http://127.0.0.1:8097/_qa-precios-proveedores-fixture.html', { waitUntil: 'networkidle' });
    if (!response || response.status() !== 200) throw new Error(`Preview HTTP ${response?.status() ?? 'sin respuesta'}`);
    await page.evaluate(() => document.fonts.ready);
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    await page.screenshot({ path: path.join(__dirname, 'precios-proveedor-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(150);
    await page.screenshot({ path: path.join(__dirname, 'precios-proveedor-mobile.png') });
    await page.locator('.proveedor-asignaciones').screenshot({ path: path.join(__dirname, 'precios-proveedor-mobile-asignaciones.png') });
    const layout = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, title: document.querySelector('h2')?.textContent?.trim(), wraps: [...document.querySelectorAll('.admin-table-wrap')].map(el => ({ rect: Math.round(el.getBoundingClientRect().right), width: el.clientWidth, scroll: el.scrollWidth, overflowX: getComputedStyle(el).overflowX })), overflowing: [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1 && !el.classList.contains('admin-visually-hidden')).map(el => ({ tag: el.tagName, className: typeof el.className === 'string' ? el.className : '', right: Math.round(el.getBoundingClientRect().right), width: Math.round(el.getBoundingClientRect().width), client: el.clientWidth, scroll: el.scrollWidth, overflowX: getComputedStyle(el).overflowX })).slice(-20) }));
    const tableScroll = await page.evaluate(() => { const el = document.querySelector('.proveedor-precios__tabla')?.parentElement; if (!el) return null; el.scrollLeft = el.scrollWidth; return { maxScroll: el.scrollWidth - el.clientWidth, scrollLeft: el.scrollLeft }; });
    console.log(JSON.stringify({ desktop: '1440x1000', mobile: '390x844', mobileDocumentWidth: layout.document, title: layout.title, pageErrors: failures.length, tableWraps: layout.wraps, priceTableScroll: tableScroll }, null, 2));
    if (layout.document > layout.viewport) throw new Error(`Overflow horizontal: ${JSON.stringify(layout.overflowing)}`);
    if (failures.length) throw new Error(failures.join('\n'));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
