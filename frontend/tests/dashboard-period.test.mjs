import {test} from 'node:test';
import assert from 'node:assert/strict';
import {DASHBOARD_PERIODS, inDashboardPeriod} from '../src/utils/dashboardPeriod.ts';
test('rolling periods filter timestamps and reject future or invalid dates', () => {
  const now = Date.parse('2026-10-20T12:00:00Z');
  assert.equal(DASHBOARD_PERIODS.length, 6);
  assert.equal(inDashboardPeriod('2026-10-20T11:30:00Z','1h',now),true);
  assert.equal(inDashboardPeriod('2026-10-20T10:00:00Z','1h',now),false);
  assert.equal(inDashboardPeriod('2026-10-20T10:00:00Z','4h',now),true);
  assert.equal(inDashboardPeriod('2026-10-20T13:00:00Z','year',now),false);
  assert.equal(inDashboardPeriod('invalid','year',now),false);
});
