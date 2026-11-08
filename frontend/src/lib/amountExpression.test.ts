import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseAmountMinor, settledAmount, signedAmountMinor } from './amountExpression.ts';

test('an amount expression resolves to integer cents', () => {
  assert.equal(parseAmountMinor('(20+5)*(20/2)', 2), 25000);
  assert.equal(settledAmount('(20+5)*(20/2)', 2), '250.00');
  assert.equal(parseAmountMinor('  ( 20 + 5 ) * ( 20 / 2 )  ', 2), 25000);
});

test('times and divide happen before plus and minus', () => {
  assert.equal(parseAmountMinor('2+3*4', 2), 1400);
  assert.equal(settledAmount('2+3*4', 2), '14.00');
  assert.equal(parseAmountMinor('(2+3)*4', 2), 2000);
  assert.equal(parseAmountMinor('((20))', 2), 2000);
});

test('division uses the same half-up cents a typed amount already stores', () => {
  assert.equal(parseAmountMinor('10/4', 2), 250);
  assert.equal(settledAmount('10/4', 2), '2.50');
  assert.equal(parseAmountMinor('1/8', 2), 13);
  assert.equal(parseAmountMinor('2.50', 2), 250);
  assert.equal(parseAmountMinor('2.5', 2), 250);
  assert.equal(parseAmountMinor('2.505', 2), null);
  assert.equal(settledAmount('2.50', 2), null);
});

test('division by zero, a broken expression, and a letter do not become a number', () => {
  assert.equal(parseAmountMinor('10/0', 2), null);
  assert.equal(parseAmountMinor('1/(2-2)', 2), null);
  assert.equal(parseAmountMinor('20+', 2), null);
  assert.equal(parseAmountMinor('(20', 2), null);
  assert.equal(parseAmountMinor('20)', 2), null);
  assert.equal(parseAmountMinor('()', 2), null);
  assert.equal(parseAmountMinor('', 2), null);
  assert.equal(parseAmountMinor('   ', 2), null);
  assert.equal(parseAmountMinor('20a', 2), null);
  assert.equal(parseAmountMinor('abc', 2), null);
  assert.equal(settledAmount('10/0', 2), null);
  assert.equal(settledAmount('20a', 2), null);
});

test('a minus sign is a sign, and a negative result stays out of an unsigned amount', () => {
  assert.equal(signedAmountMinor('-5', 2), -500);
  assert.equal(signedAmountMinor('10*-2', 2), -2000);
  assert.equal(parseAmountMinor('10*-2', 2), null);
  assert.equal(parseAmountMinor('10*-2*-1', 2), 2000);
  assert.equal(parseAmountMinor('10--2', 2), 1200);
  assert.equal(parseAmountMinor('-(5-10)', 2), 500);
});
