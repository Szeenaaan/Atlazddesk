import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canViewPage, canCreateTicket, ROLE_PAGES } from '../src/auth/rolePermissions.ts';

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
  assert.equal(canViewPage('admin', 'Categories'), true);
  assert.equal(canViewPage('admin', 'Tickets'), true);
  assert.equal(canViewPage('admin', 'Assets'), false);
  assert.equal(canViewPage('admin', 'Monitoring'), false);
  assert.equal(canViewPage('admin', 'Settings'), false);
  assert.equal(canViewPage('admin', 'Maintenance'), false);
});

test('contractor can view tickets but cannot create one, including in a delegated workspace', () => {
  for (const role of ['admin', 'super_admin', 'technician']) assert.equal(canCreateTicket(role), false);
  for (const role of ['customer_admin', 'customer_user']) assert.equal(canCreateTicket(role), true);
  assert.equal(canCreateTicket('customer_admin', 'admin'), false);
});

test('technician works only in assigned inbox and permitted resources', () => {
  assert.equal(canViewPage('technician', 'My Inbox'), true);
  assert.equal(canViewPage('technician', 'User Management'), false);
  assert.equal(canViewPage('technician', 'Client Security'), false);
  for (const page of ['Customers', 'Assets', 'My Assets', 'Maintenance']) assert.equal(canViewPage('technician', page), false);
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

test('category management navigation is exclusive to Contractor Admin', () => {
  for (const role of ['super_admin', 'customer_admin', 'customer_user', 'technician']) {
    assert.equal(canViewPage(role, 'Categories'), false);
  }
});

test('maintenance page follows installation flag and role', () => {
  assert.equal(canViewPage('admin', 'Maintenance', {maintenance_enabled: true}), true);
  assert.equal(canViewPage('admin', 'Maintenance', {maintenance_enabled: false}), false);
  for (const role of ['super_admin','technician','customer_admin','customer_user']) assert.equal(canViewPage(role, 'Maintenance', {maintenance_enabled: true}), false);
});
