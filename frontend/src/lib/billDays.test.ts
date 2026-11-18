import assert from 'node:assert/strict'
import { test } from 'node:test'
import { billDays } from './billDays.ts'

test('bills on one day run from the largest amount, then name, then id', () => {
  const days = billDays([
    { id: 2, name: 'Roadside Bill', shownOn: '2026-11-20', amountMinor: 1000 },
    { id: 9, name: 'Phone Bill', shownOn: '2026-11-12', amountMinor: 4500 },
    { id: 1, name: 'Recurring Yearly Bill', shownOn: '2026-11-20', amountMinor: 120000 },
    { id: 4, name: 'Emergency Bill', shownOn: '2026-11-20', amountMinor: 1000 },
    { id: 3, name: 'Emergency Bill', shownOn: '2026-11-20', amountMinor: 1000 },
    { id: 8, name: 'Loan 1', shownOn: '2026-12-01', amountMinor: 500000 },
  ])

  assert.deepEqual(days.map((day) => day.date), ['2026-11-12', '2026-11-20', '2026-12-01'])
  assert.deepEqual(days[1].bills.map((bill) => [bill.id, bill.amountMinor]), [
    [1, 120000],
    [3, 1000],
    [4, 1000],
    [2, 1000],
  ])
  assert.deepEqual(days[0].bills.map((bill) => bill.id), [9])
  assert.deepEqual(days[2].bills.map((bill) => bill.id), [8])
})
