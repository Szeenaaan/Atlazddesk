import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canRequestEscalation, getWorkforceErrorMessage } from '../src/api/escalations.ts';

test('only the current technician may request escalation on an open ticket', () => {
  for (const status of ['New', 'Assigned', 'In Progress', 'Waiting Customer', 'Waiting Vendor', 'Waiting Parts']) {
    assert.equal(canRequestEscalation('technician', 7, 7, status, false), true);
  }
  for (const status of ['Resolved', 'Closed', 'Cancelled', 'Customer Confirmation', 'Unknown']) {
    assert.equal(canRequestEscalation('technician', 7, 7, status, false), false);
  }
  for (const role of ['admin', 'super_admin', 'customer_admin', 'customer_user']) {
    assert.equal(canRequestEscalation(role, 7, 7, 'Assigned', false), false);
  }
  assert.equal(canRequestEscalation('technician', 7, 8, 'Assigned', false), false);
  assert.equal(canRequestEscalation('technician', 7, null, 'Assigned', false), false);
  assert.equal(canRequestEscalation('technician', undefined, 7, 'Assigned', false), false);
});

test('pending requests disable duplicate escalation actions', () => {
  assert.equal(canRequestEscalation('technician', 7, 7, 'Assigned', true), false);
  assert.equal(canRequestEscalation('technician', 7, 7, 'Assigned', false), true);
});

test('server errors identify the affected feature without showing business details', () => {
  const message = getWorkforceErrorMessage({ response: { status: 500, data: {
    message: 'SQLSTATE secret customer record', request_id: 'bc9b-43ed-afbb-ae10e2fed364',
  } } }, 'Pending escalation requests could not be loaded.');
  assert.match(message, /Pending escalation requests could not be loaded/);
  assert.match(message, /contact Admin/);
  assert.match(message, /Reference: bc9b-43ed-afbb-ae10e2fed364/);
  assert.doesNotMatch(message, /SQLSTATE|secret customer record/);
});

test('validation errors retain useful field feedback', () => {
  const message = getWorkforceErrorMessage({ response: { status: 422, data: {
    message: 'Check the highlighted fields.', errors: { reason: ['Enter an escalation reason.'] },
  } } }, 'The escalation could not be saved.');
  assert.equal(message, 'Enter an escalation reason.');
});
