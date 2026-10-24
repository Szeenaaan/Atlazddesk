import { test } from 'node:test';
import assert from 'node:assert/strict';
import { getApiErrorMessage } from '../src/api/contracts.ts';

test('backend validation errors show actionable field messages', () => {
  assert.equal(getApiErrorMessage({ response: { data: {
    message: 'Invalid input', errors: { email: ['Email address is required.'] },
  } } }), 'Email address is required.');
});

test('backend API messages are presented without exposing raw exceptions', () => {
  assert.equal(getApiErrorMessage({ response: { data: {
    message: 'You do not have permission to perform this action.',
  } } }), 'You do not have permission to perform this action.');
  assert.equal(getApiErrorMessage(new Error('Sensitive internal failure')), 'The request could not be completed.');
});

test('server failures include a safe support reference without exposing diagnostics', () => {
  assert.equal(getApiErrorMessage({ response: { data: {
    statuscode: 503, message: 'Email delivery is unavailable.', request_id: 'request-12345678',
  } } }), 'Email delivery is unavailable. Reference: request-12345678.');
  assert.equal(getApiErrorMessage({ response: { data: {
    statuscode: 500, message: 'Could not complete the request.', request_id: '<script>unsafe</script>',
  } } }), 'Could not complete the request.');
});
