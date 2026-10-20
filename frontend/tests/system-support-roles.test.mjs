import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canViewPage } from '../src/auth/rolePermissions.ts';

test('Super Admin sees technical support without contractor workspace', () => {
  assert.equal(canViewPage('super_admin', 'System Support'), true);
  assert.equal(canViewPage('super_admin', 'Admin Accounts'), true);
  for (const page of ['Tickets','Customers','Technicians','Client Security']) {
    assert.equal(canViewPage('super_admin', page), false, page);
  }
});

test('contractor and client roles cannot see technical support', () => {
  for (const role of ['admin','technician','customer_admin','customer_user']) {
    assert.equal(canViewPage(role, 'System Support'), false, role);
  }
});
