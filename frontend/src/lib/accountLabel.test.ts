import assert from 'node:assert/strict';
import { test } from 'node:test';
import { accountLabel } from './accountLabel.ts';

test('an account dropdown reads Name - Institution (Holder)', () => {
  assert.equal(accountLabel({ name: 'Everyday checking', institution: 'Camp credit union', holder: 'Ester' }), 'Everyday checking - Camp credit union (Ester)');
  assert.equal(accountLabel({ name: ' Wallet ', institution: ' NFCU ', holder: ' Joseph ' }), 'Wallet - NFCU (Joseph)');
});

test('a blank institution or holder is left out of the dropdown', () => {
  assert.equal(accountLabel({ name: 'Wallet', institution: null, holder: null }), 'Wallet');
  assert.equal(accountLabel({ name: 'Wallet', institution: '   ', holder: '' }), 'Wallet');
  assert.equal(accountLabel({ name: 'Wallet', institution: 'Camp credit union', holder: null }), 'Wallet - Camp credit union');
  assert.equal(accountLabel({ name: 'Wallet', institution: null, holder: 'Ester' }), 'Wallet (Ester)');
});
