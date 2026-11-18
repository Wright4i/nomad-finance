import assert from 'node:assert/strict'
import { test } from 'node:test'
import { calendarBillColor, calendarBillShown, calendarBillSide } from './calendarBill.ts'

test('a paid occurrence is lilac and the next due date stays coral', () => {
  assert.equal(calendarBillSide('paid', 'due'), 'paid')
  assert.equal(calendarBillColor('paid', 'due'), '--lilac')
  assert.equal(calendarBillSide('active', 'paid'), 'paid')
  assert.equal(calendarBillColor('active', 'paid'), '--lilac')
  assert.equal(calendarBillSide('active', 'due'), 'unpaid')
  assert.equal(calendarBillColor('active', 'due'), '--coral')
  assert.equal(calendarBillSide('paused', 'due'), 'unpaid')
  assert.equal(calendarBillColor('paused', 'due'), '--coral')
})

test('paid and unpaid filters each hide only their own occurrences', () => {
  assert.equal(calendarBillShown('active', 'paid', true, true), true)
  assert.equal(calendarBillShown('active', 'due', true, true), true)
  assert.equal(calendarBillShown('paid', 'due', true, true), true)

  assert.equal(calendarBillShown('active', 'paid', false, true), false)
  assert.equal(calendarBillShown('active', 'due', false, true), true)
  assert.equal(calendarBillShown('paid', 'due', false, true), false)

  assert.equal(calendarBillShown('active', 'paid', true, false), true)
  assert.equal(calendarBillShown('active', 'due', true, false), false)
  assert.equal(calendarBillShown('paused', 'due', true, false), false)

  assert.equal(calendarBillShown('active', 'paid', false, false), false)
  assert.equal(calendarBillShown('active', 'due', false, false), false)
})
