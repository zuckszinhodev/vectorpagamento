import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../src/lib/productDiscount.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } });
const { prepareProductDiscount: discount } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);

test('direct percentage and fixed discounts persist cents and original price', () => {
  assert.deepEqual(discount(100, true, 'direct', 'percentage', 15), { price: 8500, original_price: 10000 });
  assert.deepEqual(discount(49.99, true, 'direct', 'fixed', 9.99), { price: 4000, original_price: 4999 });
});
test('coupon does not reduce the base product price', () => {
  assert.deepEqual(discount(100, true, 'coupon', 'percentage', 20), { price: 10000, original_price: null });
});
test('disabling discount restores the base price even with stale discount values', () => {
  assert.deepEqual(discount(100, false, 'direct', 'fixed', 200), { price: 10000, original_price: null });
});
test('rejects missing, negative, nonfinite and excessive discounts', () => {
  for (const value of [undefined, NaN, Infinity, 0, -1, 101]) {
    assert.throws(() => discount(100, true, 'direct', 'percentage', value));
  }
  assert.throws(() => discount(10, true, 'direct', 'fixed', 9.01));
  assert.deepEqual(discount(10, true, 'direct', 'fixed', 9), { price: 100, original_price: 1000 });
});
