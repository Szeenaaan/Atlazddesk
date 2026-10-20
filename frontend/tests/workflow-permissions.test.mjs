import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canViewPage } from '../src/auth/rolePermissions.ts';
import { statusOptions } from '../src/tickets/statusTransitions.ts';

test('technical operator provisions contractor Admin but cannot view contractor tickets', () => {
  assert.equal(canViewPage('super_admin', 'Admin Accounts'), true);
  assert.equal(canViewPage('super_admin', 'Tickets'), false);
  assert.equal(canViewPage('admin', 'Admin Accounts'), false);
});
test('technician sees only valid progress transitions', () => {
  assert.deepEqual(statusOptions('technician', 'Assigned'), ['Assigned','In Progress','Waiting Customer','Waiting Vendor','Waiting Parts']);
  assert.deepEqual(statusOptions('technician', 'Closed'), ['Closed']);
});
test('client cannot move unconfirmed ticket into internal workflow', () => {
  assert.deepEqual(statusOptions('customer_user','Assigned'), ['Assigned']);
  assert.deepEqual(statusOptions('customer_admin','Resolved'), ['Resolved','Customer Confirmation','In Progress']);
});
