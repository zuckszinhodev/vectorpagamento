import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../src/lib/checkoutSubmission.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } });
const { prepareCheckoutSubmission } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);

test('keeps the card method selected in the modern checkout when the parent defaults to PIX', () => {
  const card = { number: 'test-card', installments: 3 };
  const result = prepareCheckoutSubmission({ payment_method: 'credit_card', card }, 'pix');
  assert.equal(result.payment_method, 'credit_card');
  assert.deepEqual(result.card, card);
});

test('keeps boleto and drops stale card input after changing methods', () => {
  const result = prepareCheckoutSubmission({ payment_method: 'boleto', card: { number: 'stale' } }, 'pix');
  assert.equal(result.payment_method, 'boleto');
  assert.equal(result.card, undefined);
});

test('uses the classic checkout method when the form does not include one', () => {
  assert.equal(prepareCheckoutSubmission({ name: 'Comprador' }, 'pix').payment_method, 'pix');
});

test('preserves the delivery selection and address without mutating the form', () => {
  const data = { payment_method: 'pix', shipping_service_id: 2, address: { zipcode: '01001-000' }, card: { number: 'stale' } };
  const result = prepareCheckoutSubmission(data, 'credit_card');
  assert.equal(result.shipping_service_id, 2);
  assert.deepEqual(result.address, data.address);
  assert.equal(result.card, undefined);
  assert.equal(data.card.number, 'stale');
});
