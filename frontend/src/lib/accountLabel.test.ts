import assert from 'node:assert/strict'
import { test } from 'node:test'
import { accountLabel } from './accountLabel.ts'

test('an account dropdown reads Name - Institution (Holder)', () => {
  assert.equal(accountLabel({ name: 'Everyday checking', institution: 'Camp credit union', holder: 'Sam' }), 'Everyday checking - Camp credit union (Sam)')
  assert.equal(accountLabel({ name: ' Wallet ', institution: ' First Credit Union ', holder: ' Alex ' }), 'Wallet - First Credit Union (Alex)')
})

test('a blank institution or holder is left out of the dropdown', () => {
  assert.equal(accountLabel({ name: 'Wallet', institution: null, holder: null }), 'Wallet')
  assert.equal(accountLabel({ name: 'Wallet', institution: '   ', holder: '' }), 'Wallet')
  assert.equal(accountLabel({ name: 'Wallet', institution: 'Camp credit union', holder: null }), 'Wallet - Camp credit union')
  assert.equal(accountLabel({ name: 'Wallet', institution: null, holder: 'Sam' }), 'Wallet (Sam)')
})
