import { test } from 'node:test';
import assert from 'node:assert/strict';
import { filterSelectOptions } from '../src/utils/selectOptions.ts';
test('search matches labels and stored values without changing option values', () => {
  const options = [{value:'INR',label:'Indian rupee'}, {value:'AED',label:'UAE dirham'}];
  assert.deepEqual(filterSelectOptions(options,'  RUPEE '), [options[0]]);
  assert.deepEqual(filterSelectOptions(options,'aed'), [options[1]]);
  assert.deepEqual(filterSelectOptions(options,'unknown'), []);
});
test('all matching choices remain available beyond the old 35-option cutoff', () => {
  const options = Array.from({length:100}, (_, index) => ({value:String(index),label:`Customer ${index}`}));
  assert.equal(filterSelectOptions(options,'Customer').length, 100);
  assert.deepEqual(filterSelectOptions(options,'Customer 99'), [options[99]]);
});
test('disabled placeholders cannot be selected but empty optional choices remain', () => {
  const options = [{value:'',label:'Select',disabled:true},{value:'',label:'None'},{value:'1',label:'Active'}];
  assert.deepEqual(filterSelectOptions(options,''), options.slice(1));
});
test('asset metadata is searchable while unavailable options stay excluded', () => {
  const options = [
    {value:'1',label:'Reception printer',description:'PR-1 · Printers · Shared'},
    {value:'2',label:'Laptop',description:'LT-2 · Computers · Individual',disabled:true},
  ];
  assert.deepEqual(filterSelectOptions(options,'pr-1'), [options[0]]);
  assert.deepEqual(filterSelectOptions(options,'printers'), [options[0]]);
  assert.deepEqual(filterSelectOptions(options,'individual'), []);
});
