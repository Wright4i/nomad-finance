import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fieldTips } from './fieldTips.ts';

test('a label tip stays brief and names the hidden effect', () => {
  for (const text of Object.values(fieldTips)) {
    assert.equal(text.length > 0 && text.length <= 110, true, text);
  }
  assert.match(fieldTips.mpgMin, /left out of the average/);
  assert.match(fieldTips.logAccount, /bill stays open/);
  assert.match(fieldTips.logAccount, /bill is paid/);
  assert.match(fieldTips.accountBalance, /log or a transfer/);
  assert.match(fieldTips.billAutopay, /does not pay/);
  assert.match(fieldTips.accountBudget, /available to assign/);
  assert.equal(fieldTips.accountGas, 'Default Fuel');
  assert.equal(fieldTips.accountShopping, 'Default Shopping');
  assert.equal(fieldTips.accountAsset, 'Default Asset');
});
