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
