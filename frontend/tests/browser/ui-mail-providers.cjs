// UI fixtures only. No real accounts, SMTP connections or provider records are changed.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { response } = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
const base = process.env.UI_BASE_URL || 'https://localhost:8443';

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
  let checks = 0;
  for (const width of [390, 1440]) for (const theme of ['dark', 'light']) {
    const context = await browser.newContext({viewport: {width, height: 1000}, ignoreHTTPSErrors: true, reducedMotion: 'reduce'});
    await context.addInitScript(value => localStorage.setItem('supportdesk-theme', value), theme);
    const page = await context.newPage(); page.setDefaultTimeout(7000);
    let provider = {id: 1, name: 'Business provider', driver: 'smtp', from_email: 'support@example.test', from_name: 'Support', is_active: true, credential_configured: true};
    let assigned = false;
    let defaultId = null;
    await page.route('**/api/maintenance-status', route => route.fulfill({json: {maintenance: false}}));
    await page.route('**/api/v2/**', route => {
      const path = new URL(route.request().url()).pathname.replace('/api/v2', '');
      const method = route.request().method();
      if (path === '/admin/mail/providers') return route.fulfill({json: {data: {providers: provider ? [provider] : [], default_provider_id: defaultId, notifications_enabled: false, notification_email: null}}});
      if (path === '/admin/mail/default' && method === 'PUT') {
        defaultId = route.request().postDataJSON().default_provider_id;
        return route.fulfill({json: {success: true}});
      }
      if (path === '/admin/mail/providers/1' && method === 'PATCH') {
        provider.is_active = route.request().postDataJSON().is_active;
        return route.fulfill({json: {data: provider}});
      }
      if (path === '/admin/mail/providers/1' && method === 'DELETE') {
        const password = route.request().postDataJSON().current_password;
        if (password !== 'Example!123') return route.fulfill({status: 422, json: {message: 'Current password is incorrect.'}});
        if (assigned) return route.fulfill({status: 422, json: {message: 'Select a replacement before deleting this email provider.'}});
        provider = null;
        return route.fulfill({json: {success: true, message: 'Email provider deleted.'}});
      }
      return route.fulfill({json: response(path, {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'admin'})});
    });
    await page.goto(base); await page.locator('.sidebar').waitFor();
    if (width < 800) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText: /^Email Delivery$/}).click();
    await page.getByLabel('Confirm changes with your current password').fill('Example!123');
    for (const name of ['Disable', 'Enable']) {
      await page.getByRole('button', {name, exact: true}).click();
      const toast = page.locator('.amc-feedback-toast');
      await toast.filter({hasText: 'Email provider status updated.'}).waitFor();
      await toast.getByRole('button', {name: 'Dismiss notification'}).click(); await toast.waitFor({state: 'detached'});
      await page.getByRole('button', {name: name === 'Disable' ? 'Enable' : 'Disable', exact: true}).waitFor();
      checks++;
    }
    await page.getByRole('button', {name: 'Use as default', exact: true}).click();
    const defaultToast = page.locator('.amc-feedback-toast');
    await defaultToast.filter({hasText: 'Default email settings saved.'}).waitFor();
    assert.equal(defaultId, 1);
    await page.locator('.mail-provider-row small').filter({hasText: 'Default sender'}).waitFor();
    await defaultToast.getByRole('button', {name: 'Dismiss notification'}).click(); await defaultToast.waitFor({state: 'detached'});
    checks++;
    await page.getByRole('button', {name: 'Delete provider', exact: true}).click();
    let dialog = page.getByRole('dialog', {name: 'Delete email provider?'});
    await dialog.getByLabel('Current Contractor Admin password').fill('Wrong!123');
    await dialog.getByRole('button', {name: 'Delete provider', exact: true}).click();
    let toast = dialog.locator('.amc-feedback-toast');
    await toast.filter({hasText: 'Current password is incorrect.'}).waitFor();
    await toast.getByRole('button', {name: 'Dismiss notification'}).click(); await toast.waitFor({state: 'detached'});
    checks++;
    assigned = true;
    await dialog.getByLabel('Current Contractor Admin password').fill('Example!123');
    await dialog.getByRole('button', {name: 'Delete provider', exact: true}).click();
    toast = dialog.locator('.amc-feedback-toast');
    await toast.filter({hasText: 'Select a replacement'}).waitFor();
    await toast.getByRole('button', {name: 'Dismiss notification'}).click(); await toast.waitFor({state: 'detached'});
    checks++;
    assigned = false;
    defaultId = null;
    await dialog.getByRole('button', {name: 'Delete provider', exact: true}).click();
    await dialog.waitFor({state: 'detached'});
    await page.getByText('No email providers configured.', {exact: false}).waitFor();
    await page.locator('.amc-feedback-toast').filter({hasText: 'Email provider deleted.'}).waitFor();
    assert.equal(provider, null); checks++;
    await context.close();
  }
  await browser.close();
  console.log(`Passed ${checks} mocked email-provider lifecycle checks across mobile/desktop and both themes.`);
})().catch(error => {console.error(error); process.exit(1);});
