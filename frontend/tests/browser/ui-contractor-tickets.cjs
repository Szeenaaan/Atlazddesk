// Mocked UI only. No real account sessions, ticket changes or business records.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { response } = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
const base = process.env.UI_BASE_URL || 'https://localhost:8443';

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
  let checks = 0;
  for (const width of [390, 1440]) for (const theme of ['dark', 'light']) {
    const context = await browser.newContext({viewport: {width, height: 1000}, ignoreHTTPSErrors: true, reducedMotion: 'reduce'});
    await context.addInitScript(value => localStorage.setItem('atlazddesk-theme', value), theme);
    const page = await context.newPage(); page.setDefaultTimeout(7000);
    await page.route('**/api/maintenance-status', route => route.fulfill({json: {maintenance: false}}));
    await page.route('**/api/v2/**', route => route.fulfill({json: response(new URL(route.request().url()).pathname.replace('/api/v2', ''),
      {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'admin'})}));
    await page.goto(base); await page.locator('.sidebar').waitFor();
    assert.equal(await page.getByRole('button', {name: /New ticket|Create ticket/i}).count(), 0);
    if (width < 800) await page.locator('.mobile-menu').click();
    assert.equal(await page.locator('.sidebar .nav-item').filter({hasText: /^Assets$/}).count(), 0);
    await page.locator('.sidebar .nav-item').filter({hasText: /^Tickets/}).click();
    await page.locator('.tickets-panel tbody tr').first().waitFor();
    assert.equal(await page.getByRole('button', {name: /New ticket|Create ticket/i}).count(), 0); checks++;
    await page.locator('.tickets-panel tbody tr').first().click();
    const drawer = page.getByRole('dialog', {name: 'Ticket details'});
    await drawer.waitFor();
    await drawer.getByText('Technician', {exact: true}).waitFor();
    await drawer.getByText('Status', {exact: true}).waitFor();
    await drawer.getByLabel('Reply or internal note').fill('Mocked contractor reply');
    await drawer.getByText('Internal note — hidden from customers', {exact: true}).waitFor();
    assert.equal(await page.getByRole('button', {name: /New ticket|Create ticket/i}).count(), 0); checks++;
    await context.close();
  }
  await browser.close(); console.log(`Passed ${checks} mocked contractor ticket UI checks across mobile/desktop and both themes.`);
})().catch(error => {console.error(error);process.exit(1);});
