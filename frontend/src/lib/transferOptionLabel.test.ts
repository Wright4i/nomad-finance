import assert from 'node:assert/strict';
import test from 'node:test';
import { transferOptionLabel } from './transferOptionLabel.ts';

test('a transfer dropdown separates the account label and balance with a pipe', () => {
  assert.equal(
    transferOptionLabel({ name: 'Everyday checking', institution: null, holder: 'Demo', side: 'asset', balanceMinor: 318_000 }, 'USD'),
    'Everyday checking (Demo) | $3,180.00',
  );
  assert.equal(
    transferOptionLabel({ name: 'Camp card', institution: 'Camp bank', holder: 'Ada', side: 'liability', balanceMinor: 2_000 }, 'USD'),
    'Camp card - Camp bank (Ada) | -$20.00',
  );
});
