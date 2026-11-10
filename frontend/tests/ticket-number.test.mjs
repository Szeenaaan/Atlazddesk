import assert from 'node:assert/strict';
import test from 'node:test';
import { formatTicketNumber } from '../src/utils/ticketNumber.ts';

test('ticket labels use TK, creation year and six-digit id', () => {
  assert.equal(formatTicketNumber(1, '2026-10-31T12:00:00Z'), '#TK2026000001');
  assert.equal(formatTicketNumber(123456, '2025-01-01T00:00:00Z'), '#TK2025123456');
});
