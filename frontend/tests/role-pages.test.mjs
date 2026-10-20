import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canViewPage, ROLE_PAGES } from '../src/auth/rolePermissions.ts';

test('technical owner only sees system tools, not contractor operations', () => {
  for (const page of ['Monitoring', 'System Support', 'My Profile', 'My Security']) {
    assert.equal(canViewPage('super_admin', page), true);
  }
  for (const page of ['Tickets', 'Customers', 'User Management', 'Reports', 'Client Security', 'Authentication', 'Email Delivery', 'Settings', 'Overview']) {
    assert.equal(canViewPage('super_admin', page), false);
  }
});

test('contractor Admin can manage client MFA but not system diagnostics', () => {
  assert.equal(canViewPage('admin', 'Client Security'), true);
  assert.equal(canViewPage('admin', 'Authentication'), true);
  assert.equal(canViewPage('admin', 'Email Delivery'), true);
  assert.equal(canViewPage('admin', 'User Management'), true);
  assert.equal(canViewPage('admin', 'Monitoring'), false);
  assert.equal(canViewPage('admin', 'Settings'), false);
});

test('technician works only in assigned inbox and permitted resources', () => {
  assert.equal(canViewPage('technician', 'My Inbox'), true);
  assert.equal(canViewPage('technician', 'User Management'), false);
  assert.equal(canViewPage('technician', 'Client Security'), false);
  assert.equal(canViewPage('technician', 'Customers'), false);
});

test('customer administrators can manage own client accounts while customer users cannot', () => {
  assert.equal(canViewPage('customer_admin', 'User Management'), true);
  assert.equal(canViewPage('customer_user', 'User Management'), false);
  assert.equal(canViewPage('customer_admin', 'Assets'), true);
  assert.equal(canViewPage('customer_user', 'Tickets'), true);
  for (const role of ['customer_admin', 'customer_user']) {
    assert.equal(canViewPage(role, 'Monitoring'), false);
    assert.equal(canViewPage(role, 'Client Security'), false);
  }
});

test('unknown role or screen grants no visibility', () => {
  assert.equal(canViewPage('service_manager', 'Customers'), false);
  assert.equal(canViewPage('admin', 'Backdoor'), false);
  for (const pages of Object.values(ROLE_PAGES)) assert.ok(pages.length > 0);
});
