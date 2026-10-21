import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultCurrency, CURRENCIES, currencyLabel } from '../src/utils/currency.ts';
test('uses the installation country for currency defaults', () => {
  assert.equal(defaultCurrency('IN'), 'INR');
  assert.equal(defaultCurrency('AE'), 'AED');
  assert.equal(defaultCurrency('US'), 'USD');
  assert.equal(defaultCurrency('GB'), 'GBP');
  assert.equal(defaultCurrency('DE'), 'EUR');
  assert.equal(defaultCurrency('jp'), 'JPY');
});
test('unknown or unconfigured country requires an explicit selection', () => {
  assert.equal(defaultCurrency(null), '');
  assert.equal(defaultCurrency('ZZ'), '');
});
test('currency choices have unique codes and readable labels', () => {
  assert.equal(new Set(CURRENCIES).size, CURRENCIES.length);
  assert.ok(CURRENCIES.includes('INR'));
  assert.ok(CURRENCIES.every(code => /^[A-Z]{3}$/.test(code)));
  assert.match(currencyLabel('INR'), /^INR/);
});
