import { test } from 'node:test';
import assert from 'node:assert/strict';
import {setInstallationTimezone, getInstallationTimezone, getInstallationCountry, formatInstallationDateTime} from '../src/utils/installationTime.ts';

test('saved installation timezone controls display independently of the browser timezone', () => {
  const previous = globalThis.window;
  globalThis.window = new EventTarget();
  try {
    let updates = 0;
    window.addEventListener('amc:timezone-updated', () => updates++);
    setInstallationTimezone('Asia/Kolkata', 'IN');
    assert.equal(getInstallationCountry(), 'IN');
    assert.equal(getInstallationTimezone(), 'Asia/Kolkata');
    assert.match(formatInstallationDateTime('2026-10-20T11:57:00Z'), /17:27/);
    setInstallationTimezone('Asia/Dubai', 'AE');
    assert.match(formatInstallationDateTime('2026-10-20T11:57:00Z'), /15:57/);
    assert.equal(updates, 2);
    setInstallationTimezone('UTC', null);
    assert.match(formatInstallationDateTime('2026-10-20T11:57:00Z'), /11:57/);
    assert.equal(getInstallationCountry(), null);
  } finally { globalThis.window = previous; }
});
