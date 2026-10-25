// Layout fixtures only; no real account or MFA policy changes.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {response} = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
const base = process.env.UI_BASE_URL || 'https://localhost:8443';

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
  let checks = 0;
  for (const width of [390, 1440]) for (const theme of ['dark', 'light']) {
    const context = await browser.newContext({viewport: {width, height: 1100}, ignoreHTTPSErrors: true, reducedMotion: 'reduce'});
    await context.addInitScript(value => localStorage.setItem('supportdesk-theme', value), theme);
    const page = await context.newPage(); page.setDefaultTimeout(7000);
    await page.route('**/api/maintenance-status', route => route.fulfill({json: {maintenance: false}}));
    await page.route('**/api/v2/**', route => {
      const path = new URL(route.request().url()).pathname.replace('/api/v2', '');
      const policy = {totp_enabled: false, email_enabled: false, require_mfa: false};
      if (path.startsWith('/admin/client-mfa/customers/')) return route.fulfill({json: {data: policy}});
      if (path.startsWith('/admin/client-mfa/users/')) return route.fulfill({json: {data: {effective: policy, override: policy}}});
      return route.fulfill({json: response(path, {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'admin'})});
    });
    await page.goto(base); await page.locator('.sidebar').waitFor();
    if (width < 800) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText: /^Client Security$/}).click();
    async function choose(label, term, option) {
      await page.getByRole('combobox', {name: label, exact: true}).fill(term);
      await page.getByRole('option', {name: option, exact: true}).click();
    }
    await choose('Client company', 'UI test', 'UI test customer');
    await choose('Policy level', 'Individual', 'Individual customer user');
    await choose('Customer account', 'Managed', 'Managed user — user@example.test');
    const save = page.getByRole('button', {name: 'Save policy', exact: true});
    const restore = page.getByRole('button', {name: 'Restore company policy', exact: true});
    await restore.waitFor();
    const first = await save.boundingBox(), second = await restore.boundingBox();
    assert(first.height >= 44 && second.height >= 44);
    const gap = width < 600 ? second.y - first.y - first.height : second.x - first.x - first.width;
    assert(Math.abs(gap - 12) < 1, `Expected 12px button gap, got ${gap}`);
    const field = await page.getByLabel('Confirm with your Admin password').boundingBox();
    assert(first.y - field.y - field.height >= 24);
    assert(first.x >= 0 && second.x + second.width <= width);
    await context.close(); checks++;
  }
  await browser.close(); console.log(`Passed ${checks} mocked Client Security spacing checks across mobile/desktop and both themes.`);
})().catch(error => {console.error(error);process.exit(1);});
