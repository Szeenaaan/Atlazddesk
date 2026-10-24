// Mocked browser checks only: no live login, SMTP calls or business-record changes.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { response } = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
const base = process.env.UI_BASE_URL || 'http://127.0.0.1:5175';

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] });
  let checks = 0;
  for (const width of [390, 1440]) for (const theme of ['dark', 'light']) {
    const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: 'reduce' });
    await context.addInitScript(value => localStorage.setItem('atlazddesk-theme', value), theme);
    const page = await context.newPage();
    page.setDefaultTimeout(7000);
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    let outcome = 'error';
    await page.route('**/api/maintenance-status', route => route.fulfill({ json: { maintenance: false } }));
    await page.route('**/api/v2/**', route => {
      const path = new URL(route.request().url()).pathname.replace('/api/v2', '');
      if (path === '/users/2' && route.request().method() === 'DELETE') {
        return route.fulfill({status: 422, json: {message: 'This account has business history and cannot be deleted. Deactivate it instead.'}});
      }
      if (path === '/admin/security/mfa' && route.request().method() === 'PATCH') {
        if (outcome === 'error') return route.fulfill({status: 422, json: {success: false, statuscode: 422, message: 'Current password is incorrect.'}});
        return route.fulfill({json: {data: {totp_enabled: false, email_enabled: outcome === 'reauth'},
          reauthentication_required: outcome === 'reauth', message: outcome === 'reauth'
            ? 'Sign-in security settings saved. Sign in again to complete the required verification.' : 'Sign-in security settings saved.'}});
      }
      return route.fulfill({ json: response(path, {id: 1, name: 'Reviewer', email: 'review@example.test', role: 'admin'}) });
    });
    await page.goto(base);
    await page.locator('.sidebar').waitFor();
    if (width < 800) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText: /^Authentication$/}).click();
    const password = page.getByLabel('Confirm with your Contractor Admin password');
    const save = page.getByRole('button', {name: 'Save MFA settings', exact: true});
    for (let attempt = 0; attempt < 2; attempt++) {
      await password.fill('Example!123');
      await save.click();
      const toast = page.locator('.amc-feedback-toast');
      await toast.filter({hasText: 'Current password is incorrect.'}).waitFor();
      assert.equal(await toast.count(), 1);
      assert.equal(await toast.getAttribute('role'), 'alert');
      const box = await toast.boundingBox();
      assert(box.x >= 0 && box.y >= 0 && box.x + box.width <= width);
      await toast.getByRole('button', {name: 'Dismiss notification'}).click();
      await toast.waitFor({state: 'detached'});
      checks++;
    }
    outcome = 'success';
    await save.click();
    const success = page.locator('.amc-feedback-toast');
    await success.filter({hasText: 'Sign-in security settings saved.'}).waitFor();
    assert.equal(await success.getAttribute('role'), 'status');
    await page.locator('.page-header h1').click();
    await success.waitFor({state: 'detached'});
    checks++;
    outcome = 'reauth';
    await password.fill('Example!123'); await save.click();
    await page.locator('.amc-feedback-warning').filter({hasText: 'Sign in again'}).waitFor();
    await page.getByRole('button', {name: 'Continue to sign-in'}).waitFor();
    assert.equal(await save.isDisabled(), true);
    if (width < 800) await page.locator('.mobile-menu').click();
    await page.locator('.sidebar .nav-item').filter({hasText: /^User Management$/}).click();
    await page.getByRole('button', {name: 'Actions for Managed user'}).click();
    await page.getByRole('menuitem', {name: 'Delete account'}).click();
    const dialog = page.getByRole('dialog', {name: 'Delete account?'});
    await dialog.getByRole('button', {name: 'Delete permanently'}).click();
    const dialogToast = dialog.locator('.amc-feedback-toast');
    await dialogToast.filter({hasText: 'business history'}).waitFor();
    assert.equal(await dialogToast.isVisible(), true);
    checks++;
    assert.deepEqual(failures, []);
    checks++;
    await context.close();
  }
  const context = await browser.newContext({viewport: {width: 390, height: 844}});
  const page = await context.newPage();
  page.setDefaultTimeout(7000);
  await page.route('**/api/maintenance-status', route => route.fulfill({json: {maintenance: false}}));
  await page.route('**/api/v2/auth/me', route => route.fulfill({status: 401, json: {message: 'Unauthenticated.'}}));
  await page.route('**/sanctum/csrf-cookie', route => route.fulfill({status: 204}));
  await page.route('**/api/v2/auth/login', route => route.fulfill({status: 422, json: {message: 'Invalid credentials or inactive account.'}}));
  await page.goto(base);
  await page.getByLabel('Email address').fill('review@example.test');
  await page.locator('input[name=password]').fill('Wrong!123');
  await page.getByRole('button', {name: 'Sign in', exact: true}).click();
  await page.locator('.login-panel [role=alert]').waitFor();
  assert.equal(await page.locator('.amc-feedback-toast').count(), 0);
  checks++;
  await context.close(); await browser.close();
  console.log(`Passed ${checks} mocked dashboard feedback checks, including mobile, both themes, repeated MFA errors, success, reauthentication and unchanged login feedback.`);
})().catch(error => {console.error(error);process.exit(1);});
