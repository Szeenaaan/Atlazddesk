import { test } from 'node:test';
import assert from 'node:assert/strict';
import { idleTimeoutMs, idleActivityKey, readIdleActivity } from '../src/auth/idleSession.ts';

test('browser idle deadlines match the server role policy', () => {
  assert.equal(idleTimeoutMs('super_admin'), 60_000);
  assert.equal(idleTimeoutMs('admin'), 180_000);
  for (const role of ['customer_admin', 'customer_user', 'technician']) {
    assert.equal(idleTimeoutMs(role), 600_000);
  }
});

test('shared-tab timestamps preserve elapsed idle time on reload and reject future or malformed activity', () => {
  assert.equal(readIdleActivity('1000', 61_000), 1000);
  for (const value of [null, '', ' ', 'NaN', 'Infinity', '-1', '0', '100001']) {
    assert.equal(readIdleActivity(value, 100_000), null);
  }
  assert.notEqual(idleActivityKey(1), idleActivityKey(2));
});
