import assert from 'node:assert/strict';
import { test } from 'node:test';
import { billAccount, payFromAccount, stayAccount } from './payFrom.ts';

const checking = { name: 'Everyday checking', assetFavorite: false, side: 'asset', archivedAt: null };
const fund = { name: 'Emergency fund', assetFavorite: true, side: 'asset', archivedAt: null };
const card = { name: 'Travel card', assetFavorite: false, shoppingFavorite: true, type: 'credit', side: 'liability', archivedAt: null };

test('the asset favorite is the pay-from account for transfers and new bills', () => {
  const accounts = [checking, fund, card];
  assert.equal(payFromAccount(accounts)?.name, 'Emergency fund');
  assert.equal(billAccount(accounts)?.name, 'Emergency fund');
});

test('a liability favorite does not start a transfer', () => {
  const favoriteCard = { ...card, assetFavorite: true };
  assert.equal(payFromAccount([checking, favoriteCard]), undefined);
  assert.equal(billAccount([checking, favoriteCard])?.name, 'Travel card');
});

test('with no asset favorite a new bill uses the first open account', () => {
  const accounts = [checking, { ...fund, assetFavorite: false }];
  assert.equal(payFromAccount(accounts), undefined);
  assert.equal(billAccount(accounts)?.name, 'Everyday checking');
});

test('the shopping favorite is the default account for a new stay', () => {
  const accounts = [checking, fund, card];
  assert.equal(stayAccount(accounts)?.name, 'Travel card');
});

test('with no shopping favorite a new stay uses the first open account', () => {
  const plainCard = { ...card, shoppingFavorite: false };
  assert.equal(stayAccount([checking, plainCard])?.name, 'Everyday checking');
});
