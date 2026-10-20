import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { canViewPage } from '../src/auth/rolePermissions.ts';

const app = readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8');

test('AMC contract management is contractor Admin-only', () => {
  assert.equal(canViewPage('admin', 'Contracts'), true);
  assert.equal(canViewPage('customer_admin', 'Contracts'), false);
  assert.equal(canViewPage('customer_user', 'Contracts'), false);
  assert.equal(canViewPage('technician', 'Contracts'), false);
  assert.equal(canViewPage('super_admin', 'Contracts'), false);
});

test('customer dashboards show assets instead of contractor contract counts', () => {
  assert.match(app, /role === 'admin'\s*\? <div className="kpi">.*?Active contracts/s);
  assert.match(app, /Registered assets/);
  assert.match(app, /WORKSPACE_LABELS\[role\]/);
});

test('remove placeholder chrome and misleading copy', () => {
  assert.doesNotMatch(app, /Self-hosted installation/);
  assert.doesNotMatch(app, /Manage \$\{page\.toLowerCase\(\)\} in your workspace/);
  assert.doesNotMatch(app, /<span className="select-like">Last 7 days/);
  assert.doesNotMatch(app, /API mode requires session auth and configured CORS/);
});
