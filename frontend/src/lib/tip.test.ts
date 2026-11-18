import assert from 'node:assert/strict'
import { test } from 'node:test'
import { tipOrigin, type TipBox } from './tip.ts'

function box(left: number, top: number, width: number, height: number): TipBox {
  return { left, top, right: left + width, bottom: top + height, width, height }
}

test('a field tip lines up with the label upper left', () => {
  const placed = tipOrigin(box(420, 300, 640, 24), { width: 220, height: 36 }, 1280, false)
  assert.equal(placed.left, 420)
  assert.equal(placed.top, 300 - 36 - 6)
})

test('a field tip stays on screen when the label is at the right', () => {
  const placed = tipOrigin(box(1100, 300, 150, 24), { width: 220, height: 36 }, 1280, false)
  assert.equal(placed.left, 1280 - 8 - 220)
  assert.ok(placed.left + 220 <= 1280 - 8)
})

test('a field tip drops below the label when the top is too close', () => {
  const placed = tipOrigin(box(40, 4, 180, 24), { width: 220, height: 36 }, 1280, false)
  assert.equal(placed.left, 40)
  assert.equal(placed.top, 4 + 24 + 6)
})

test('a sidebar tip stays to the right of the item', () => {
  const placed = tipOrigin(box(16, 200, 48, 40), { width: 120, height: 28 }, 1280, true)
  assert.equal(placed.left, 16 + 48 + 8)
  assert.equal(placed.top, 200 + (40 - 28) / 2)
})
