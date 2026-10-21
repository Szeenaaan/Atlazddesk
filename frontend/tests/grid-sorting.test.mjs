import { test } from 'node:test';
import assert from 'node:assert/strict';
import { compareGridValues } from '../src/utils/gridSorting.ts';
test('sorts dates chronologically', () => {
  assert.ok(compareGridValues('2027-01-01', '2026-12-31', 'Expiry') > 0);
  assert.ok(compareGridValues('9 Jan 2026', '2 Feb 2026', 'Created') < 0);
});
test('sorts numbers and identifiers naturally', () => {
  assert.ok(compareGridValues('1,200', '99') > 0);
  assert.ok(compareGridValues('12 ms', '100 ms') < 0);
  assert.ok(compareGridValues('AMC-2', 'AMC-10') < 0);
  assert.equal(compareGridValues('ACTIVE', 'active'), 0);
});
test('handles missing and equal values consistently', () => {
  assert.ok(compareGridValues('—', 'active') < 0);
  assert.equal(compareGridValues(null, ''), 0);
});
