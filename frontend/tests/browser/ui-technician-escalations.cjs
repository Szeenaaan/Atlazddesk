// Mocked browser coverage only. Every API request is intercepted; no business records are written.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { response, ticket: fixtureTicket } = require('./ui-pages.cjs');
const assert = require('node:assert/strict');
const base = process.env.UI_BASE_URL || 'http://127.0.0.1:5173';

async function choose(page, container, label, text) {
  await container.getByRole('combobox', { name: label, exact: true }).click();
  await page.getByRole('option', { name: text, exact: true }).click();
}
async function navigate(page, name) {
  if (await page.locator('.mobile-menu').isVisible()) await page.locator('.mobile-menu').click();
  await page.locator('.sidebar .nav-item').filter({ hasText: new RegExp(`^${name}`) }).click();
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] });
  try {
    for (const width of [390, 1440]) for (const theme of ['light', 'dark']) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, ignoreHTTPSErrors: true });
      await context.addInitScript(value => localStorage.setItem('supportdesk-theme', value), theme);
      const page = await context.newPage(); page.setDefaultTimeout(8000);
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      let role = 'admin';
      let ticket = { ...fixtureTicket, status: 'Assigned', subject: 'Escalation browser test' };
      let items = [];
      let designations = [{ id: 1, name: 'Technician', level: 1, is_active: true }, { id: 2, name: 'Senior', level: 3, is_active: true }, { id: 3, name: 'Retired', level: 5, is_active: false }];
      const requests = [];
      let failRequest = false, failEligible = false, failDecision = false;
      let releaseRequest = null, requestStarted = () => {};
      await page.route('**/api/**', async route => {
        const req = route.request();
        const path = new URL(req.url()).pathname.replace('/api/v2', '');
        const method = req.method();
        const user = { id: role === 'technician' ? 4 : 1, name: 'Test actor', email: 'actor@example.test', role };
        if (path === '/api/maintenance-status') return route.fulfill({ json: { maintenance: false } });
        if (!new URL(req.url()).pathname.startsWith('/api/v2/')) return route.fulfill({ json: {} });
        if (method !== 'GET') requests.push({ path, method, body: req.postDataJSON() });
        const accessPaused = role === 'technician' && items.some(item => item.state === 'pending' && item.requester_id === user.id);
        const fail = message => route.fulfill({ status: 422, json: { message, errors: { action: [message] } } });
        if (path === '/designations') {
          if (method === 'POST') designations.push({ id: 10, ...req.postDataJSON() });
          return route.fulfill({ status: method === 'POST' ? 201 : 200, json: { data: designations } });
        }
        if (path.startsWith('/designations/')) {
          const id = Number(path.split('/').pop());
          if (method === 'PATCH') designations = designations.map(d => d.id === id ? { ...d, ...req.postDataJSON() } : d);
          return route.fulfill({ json: { data: designations.find(d => d.id === id) } });
        }
        if (path === '/users' && method === 'POST') return route.fulfill({ status: 201, json: { data: { id: 9 } } });
        if (path === '/escalations') return route.fulfill({ json: { data: items, meta: { last_page: 1 } } });
        if (path === '/tickets/1/escalations/eligible') {
          if (failEligible) return fail('Eligible employees unavailable. Retry.');
          // The backend is authoritative. UI renders only the eligible employees it returns.
          return route.fulfill({ json: { data: [{ id: 8, name: 'Senior employee', designation: designations[1] }] } });
        }
        if (path === '/tickets/1/escalations') {
          if (method === 'POST') {
            if (failRequest) return fail('Enter a more useful escalation reason.');
            await new Promise(resolve => { releaseRequest = resolve; requestStarted(); });
            items.unshift({ id: items.length + 1, reference: `#ES2026${String(items.length + 1).padStart(6, '0')}`, ticket_id: 1, ticket_number: ticket.ticket_number, subject: ticket.subject,
              requester_id: 4, requester_name: 'Assigned technician', current_designation: designations[0],
              previous_designation_name: 'Technician', previous_level: 1, state: 'pending', reason: req.postDataJSON().reason,
              admin_note: null, created_at: '2026-10-31T06:00:00Z', decided_at: null });
          }
          return route.fulfill({ status: method === 'POST' ? 201 : 200, json: { data: items, meta: { last_page: 1 } } });
        }
        if (/^\/tickets\/1\/escalations\/\d+$/.test(path) && method === 'PATCH') {
          if (failDecision) return fail('The ticket assignment changed. Refresh the ticket.');
          const body = req.postDataJSON();
          items[0] = { ...items[0], state: body.decision, admin_note: body.admin_note, reviewer_name: 'Test actor', decided_at: '2026-10-31T07:00:00Z' };
          if (body.decision === 'approved') ticket = { ...ticket, assigned_to: 8, assignee: 'Senior employee' };
          return route.fulfill({ json: { data: items[0] } });
        }
        if (path === '/tickets/1') return route.fulfill({ json: { data: { ...ticket, read_only: accessPaused } } });
        if (path === '/tickets' || path === '/tickets/inbox') return route.fulfill({ json: { data: [{ ...ticket, read_only: accessPaused }], meta: { last_page: 1, total: 1 } } });
        const data = response(path, user);
        if (path === '/bootstrap') data.tickets = [{ ...ticket, read_only: accessPaused }];
        return route.fulfill({ json: data });
      });
      await page.goto(base); await page.locator('.sidebar').waitFor();
      await navigate(page, 'Technicians');
      await page.getByRole('button', { name: 'Manage designations', exact: true }).click();
      let dialog = page.getByRole('dialog', { name: 'Manage designations', exact: true });
      await dialog.getByLabel('Name', { exact: true }).fill('Lead');
      await dialog.getByLabel('Level', { exact: true }).fill('5');
      await dialog.getByRole('button', { name: 'Add designation', exact: true }).click();
      await dialog.getByRole('button', { name: 'Edit Lead', exact: true }).waitFor();
      await dialog.getByRole('button', { name: 'Edit Lead', exact: true }).click();
      await dialog.getByRole('checkbox', { name: 'Active', exact: true }).uncheck();
      await dialog.getByRole('button', { name: 'Save designation', exact: true }).click();
      await dialog.getByRole('button', { name: 'Add designation', exact: true }).waitFor();
      await dialog.getByRole('button', { name: 'Close designations' }).click();
      await page.getByRole('button', { name: '+ Add technician', exact: true }).click();
      dialog = page.getByRole('dialog', { name: 'Technicians', exact: true });
      assert.equal(await dialog.getByRole('button', { name: 'Save', exact: true }).isDisabled(), true);
      await choose(page, dialog, 'Designation', 'Senior · Level 3');
      await choose(page, dialog, 'Workforce classification', 'Employee');
      assert.equal(await dialog.getByRole('button', { name: 'Save', exact: true }).isEnabled(), true);
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      role = 'technician'; await page.reload(); await page.locator('.sidebar').waitFor();
      await page.locator('.tickets-panel tbody tr').first().click();
      let drawer = page.getByRole('dialog', { name: 'Ticket details', exact: true });
      await drawer.getByRole('button', { name: 'Request escalation', exact: true }).click();
      dialog = page.getByRole('dialog', { name: 'Request escalation', exact: true });
      const submitted = requests.length;
      await dialog.getByRole('button', { name: 'Submit request' }).click();
      assert.equal(requests.length, submitted, 'Required reason prevents a request');
      await dialog.getByLabel('Escalation reason').fill('Need specialist assistance');
      failRequest = true; await dialog.getByRole('button', { name: 'Submit request' }).click();
      await dialog.locator('.ui-notice-error').filter({ hasText: 'Enter a more useful escalation reason.' }).waitFor();
      failRequest = false; const requestIsPending = new Promise(resolve => { requestStarted = resolve; });
      await dialog.getByRole('button', { name: 'Submit request' }).click();
      await requestIsPending;
      await dialog.getByRole('button', { name: 'Saving…' }).waitFor();
      assert.equal(await dialog.getByRole('button', { name: 'Cancel', exact: true }).isDisabled(), true);
      assert.ok(releaseRequest); releaseRequest();
      await drawer.getByText(/Your escalation request for .* is awaiting a decision/).waitFor();
      assert.equal(await page.locator('.tickets-panel tbody tr').count(), 1, 'Pending ticket remains visible to its technician');
      role = 'admin'; await page.reload(); await page.locator('.sidebar').waitFor(); await navigate(page, 'Escalations');
      failEligible = true;
      await page.locator('.escalations-panel').getByRole('button', { name: 'Review', exact: true }).click();
      dialog = page.getByRole('dialog', { name: 'Review #ES2026000001', exact: true });
      await dialog.getByRole('button', { name: 'Retry eligible employees' }).waitFor();
      failEligible = false; await dialog.getByRole('button', { name: 'Retry eligible employees' }).click();
      await choose(page, dialog, 'Replacement employee', 'Senior employee · Senior (level 3)');
      const optionValues = await dialog.locator('select').nth(1).locator('option').evaluateAll(options => options.map(option => option.value));
      assert.deepEqual(optionValues, ['', '8'], 'Only server-eligible employees appear');
      failDecision = true; await dialog.getByRole('button', { name: 'Approve and reassign', exact: true }).click();
      await dialog.locator('.ui-notice-error').filter({ hasText: 'The ticket assignment changed.' }).waitFor();
      failDecision = false;
      await choose(page, dialog, 'Decision', 'Reject');
      const beforeReject = requests.length;
      await dialog.getByRole('button', { name: 'Reject request' }).click();
      assert.equal(requests.length, beforeReject, 'Rejection requires an administrative note');
      await dialog.getByLabel('Administrative note', { exact: true }).fill('Continue diagnosis with vendor');
      await dialog.getByRole('button', { name: 'Reject request' }).click();
      await page.locator('.escalations-panel').getByText('Rejected', { exact: true }).waitFor();
      assert.equal(ticket.assigned_to, 4);
      // A new pending request can be approved after rejection.
      items.unshift({ ...items[0], id: 2, reference: '#ES2026000002', state: 'pending', admin_note: null, decided_at: null });
      await page.reload(); await page.locator('.sidebar').waitFor(); await navigate(page, 'Escalations');
      await page.locator('.escalations-panel').getByRole('button', { name: 'Review', exact: true }).click();
      dialog = page.getByRole('dialog', { name: 'Review #ES2026000002', exact: true });
      await choose(page, dialog, 'Replacement employee', 'Senior employee · Senior (level 3)');
      await dialog.getByRole('button', { name: 'Approve and reassign', exact: true }).click();
      await page.locator('.escalations-panel').getByText('Approved', { exact: true }).waitFor();
      assert.equal(ticket.assigned_to, 8);
      assert.deepEqual(errors, []);
      await context.close();
    }
    console.log('Mocked technician designation and escalation UI checks completed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
