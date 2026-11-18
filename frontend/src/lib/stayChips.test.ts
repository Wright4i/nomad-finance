import assert from 'node:assert/strict'
import { test } from 'node:test'
import type { Trip } from './types'
import { showStayBalanceDue, showStayFlight, showStayFuelCost, stayLocality } from './stayChips.ts'

test('a locality chip joins city and state', () => {
  assert.equal(stayLocality({ city: 'Moab', region: 'UT' }), 'Moab, UT')
  assert.equal(stayLocality({ city: 'Moab', region: null }), 'Moab')
  assert.equal(stayLocality({ city: null, region: 'UT' }), 'UT')
  assert.equal(stayLocality({ city: null, region: null }), '')
})

test('a flight chip shows only for fly travel', () => {
  assert.equal(showStayFlight({ travel: 'fly' }), true)
  assert.equal(showStayFlight({ travel: 'drive' }), false)
})

test('balance due and fuel chips hide at zero', () => {
  assert.equal(showStayBalanceDue({ amountDueMinor: 14000, balanceDueOn: '2026-11-22' }), true)
  assert.equal(showStayBalanceDue({ amountDueMinor: 0, balanceDueOn: '2026-11-22' }), false)
  const fuel = (costMinor: number | null): Trip['fuel'] => ({
    miles: null,
    milesPerGallon: null,
    costMinor,
    priceMinor: null,
  })
  assert.equal(showStayFuelCost({ fuel: fuel(1200) }), true)
  assert.equal(showStayFuelCost({ fuel: fuel(0) }), false)
  assert.equal(showStayFuelCost({ fuel: fuel(null) }), false)
})
