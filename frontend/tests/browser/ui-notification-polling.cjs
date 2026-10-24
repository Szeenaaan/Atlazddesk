// Mocked API coverage only: no account authentication or business-record writes.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {response} = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
  try {
    const context = await browser.newContext({ignoreHTTPSErrors: true});
    const page = await context.newPage();
    await page.clock.install();
    await page.addInitScript(() => {
      let state = 'visible';
      Object.defineProperty(document, 'visibilityState', {get: () => state});
      window.setTestVisibility = value => {state = value; document.dispatchEvent(new Event('visibilitychange'));};
    });
    let requests = 0;
    await page.route('**/api/maintenance-status', route => route.fulfill({json: {maintenance: false}}));
    await page.route('**/api/v2/**', route => {
      const path = new URL(route.request().url()).pathname.replace('/api/v2', '');
      if (path === '/notifications') requests++;
      return route.fulfill({json: response(path, {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'admin'})});
    });
    const firstResponse = page.waitForResponse(res => res.url().endsWith('/notifications'));
    await page.goto(process.env.UI_BASE_URL || 'https://localhost:8443');
    await page.locator('.sidebar').waitFor();
    await firstResponse;
    const initial = requests;
    assert(initial >= 1);
    await page.evaluate(() => window.setTestVisibility('hidden'));
    await page.clock.fastForward(61000);
    assert.equal(requests, initial, 'Hidden tabs must not poll');
    const resumed = page.waitForResponse(res => res.url().endsWith('/notifications'));
    await page.evaluate(() => window.setTestVisibility('visible'));
    await resumed;
    assert.equal(requests, initial + 1, 'Visible tab must refresh immediately');
    await context.close();
    console.log('Passed mocked notification polling checks: hidden-tab pause and immediate visible-tab refresh.');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exit(1);});
