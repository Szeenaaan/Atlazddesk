// UI fixtures only; no business records or real authentication are used.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { response } = require('./ui-pages.cjs');
const base = process.env.UI_BASE_URL || 'http://127.0.0.1:5175';
(async () => {
  const browser = await chromium.launch({args: ['--no-sandbox']});
  let checks = 0;
  for (const width of [390, 1440]) for (const theme of ['dark', 'light']) {
    const context = await browser.newContext({viewport: {width, height: 1000}, reducedMotion: 'reduce'});
    await context.addInitScript(value => localStorage.setItem('atlazddesk-theme', value), theme);
    const page = await context.newPage();
    page.setDefaultTimeout(6000);
    let hold = false, resolveStatus;
    await page.route('**/api/maintenance-status', async route => {
      if (hold) await new Promise(resolve => {resolveStatus = resolve;});
      await route.fulfill({json: {maintenance: false}});
    });
    await page.route('**/api/v2/**', route => route.fulfill({json: response(new URL(route.request().url()).pathname.replace('/api/v2', ''), {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'super_admin'})}));
    await page.goto(base);
    await page.locator('.sidebar').waitFor();
    if (width < 800) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText: /^System Support$/}).click();
    await page.getByRole('tab', {name: 'Configuration', exact: true}).click();
    const form = page.locator('form').filter({has: page.getByRole('button', {name: 'Save MFA settings', exact: true})});
    await form.locator('input[type=password]').fill('Example!123');
    await form.getByRole('button', {name: 'Save MFA settings', exact: true}).click();
    await page.locator('.amc-feedback-toast').waitFor();
    await page.locator('.page-header h1').click();
    await page.locator('.amc-feedback-toast').waitFor({state: 'detached'});
    checks++;
    // An older ready response must not override a newer maintenance event.
    hold = true;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    for (let attempt = 0; !resolveStatus && attempt < 30; attempt++) await page.waitForTimeout(50);
    if (!resolveStatus) throw new Error('No pending status request');
    await page.evaluate(() => window.dispatchEvent(new Event('application-maintenance')));
    resolveStatus();
    await page.getByRole('heading', {name: 'Temporarily unavailable'}).waitFor();
    await page.waitForTimeout(250);
    if (await page.locator('.sidebar').count()) throw new Error('Stale ready response restored the dashboard');
    if (new URL(page.url()).pathname !== '/maintenance') throw new Error('Missing maintenance URL');
    hold = false;
    await page.getByRole('button', {name: 'Check again'}).click();
    await page.locator('.sidebar').waitFor();
    checks++;
    await context.close();
  }
  for (const mode of ['failure', 'timeout']) {
    const context = await browser.newContext();
    const page = await context.newPage();
    let ready = false;
    await page.route('**/api/maintenance-status', async route => {
      if (!ready && mode === 'timeout') return; // No response: exercise the actual 10-second abort.
      await route.fulfill(ready ? {json: {maintenance: false}} : {status: 500, body: 'Unavailable'});
    });
    await page.route('**/api/v2/**', route => route.fulfill({json: response(new URL(route.request().url()).pathname.replace('/api/v2',''), {id:1, name:'Reviewer', role:'super_admin'})}));
    await page.goto(base);
    await page.getByText('Unable to check application availability.', {exact:true}).waitFor({timeout:12000});
    ready = true;
    await page.getByRole('button', {name:'Check again'}).click();
    await page.locator('.sidebar').waitFor();
    checks++;
    await context.close();
  }
  await browser.close();
  console.log(JSON.stringify({checks, failures: 0}));
})().catch(error => {console.error(error); process.exit(1);});
