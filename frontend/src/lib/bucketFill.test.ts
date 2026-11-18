import assert from 'node:assert/strict'
import test from 'node:test'
import { balanceGoalMinor, bucketMark, fillFor, type FillBucket } from './bucketFill.ts'

const balance: FillBucket = {
  role: 'goal',
  amountMinor: 20000,
  envelopeMinor: 25000,
  target: { kind: 'balance', amountMinor: 50000 },
}

test('a target balance fills from the envelope against the amount to hold', () => {
  assert.equal(balanceGoalMinor(balance), 50000)
  const half = fillFor(balance, { goalMinor: 0, buckets: [balance] })
  assert.equal(half.level, 0.5)
  assert.equal(half.over, false)

  const empty = fillFor({ ...balance, envelopeMinor: 0 }, { goalMinor: 0, buckets: [balance] })
  assert.equal(empty.level, 0)
  assert.equal(empty.over, false)

  const past = fillFor({ ...balance, envelopeMinor: 80000 }, { goalMinor: 0, buckets: [balance] })
  assert.equal(past.level, 1)
  assert.equal(past.over, true)
  assert.equal(past.overBy, 30000)
})

test('locked buckets rise together with the category and split the overage', () => {
  const goal = 88375
  const first: FillBucket = { role: 'locked', amountMinor: 44000, envelopeMinor: 44000, target: null }
  const idle: FillBucket = { role: 'locked', amountMinor: 0, envelopeMinor: 0, target: null }
  const alone = fillFor(first, { goalMinor: goal, buckets: [first, idle] })
  assert.equal(alone.level, 44000 / goal)
  assert.equal(alone.over, false)
  assert.equal(fillFor(idle, { goalMinor: goal, buckets: [first, idle] }).level, 0)

  const second: FillBucket = { role: 'locked', amountMinor: 44000, envelopeMinor: 44000, target: null }
  const pair = [first, second, idle]
  const left = fillFor(first, { goalMinor: goal, buckets: pair })
  const right = fillFor(second, { goalMinor: goal, buckets: pair })
  assert.equal(left.level, 88000 / goal)
  assert.equal(right.level, left.level)
  assert.ok(left.level > 0.99 && left.level < 1)
  assert.equal(left.over, false)

  const third: FillBucket = { role: 'locked', amountMinor: 10000, envelopeMinor: 10000, target: null }
  const three = [first, second, third]
  const share = Math.round((98000 - goal) / 3)
  for (const bucket of three) {
    const fill = fillFor(bucket, { goalMinor: goal, buckets: three })
    assert.equal(fill.level, 1)
    assert.equal(fill.over, true)
    assert.equal(fill.overBy, share)
  }
  assert.equal(share, 3208)
})

test('a goal with no target is marked none, and a target balance is marked balance', () => {
  const plain: FillBucket = { role: 'goal', amountMinor: 0, envelopeMinor: 0, target: null }
  const balance: FillBucket = { role: 'goal', amountMinor: 0, envelopeMinor: 0, target: { kind: 'balance', amountMinor: 50000 } }
  const needed: FillBucket = { role: 'goal', amountMinor: 0, envelopeMinor: 0, target: { kind: 'needed', amountMinor: 120000 } }
  const locked: FillBucket = { role: 'locked', amountMinor: 100, envelopeMinor: 0, target: null }
  assert.equal(bucketMark(plain), 'none')
  assert.equal(bucketMark(balance), 'balance')
  assert.equal(bucketMark(needed), 'auto')
  assert.equal(bucketMark(locked), 'auto')
})

test('a manual bucket with no goal maps zero through $1000 onto the fill', () => {
  const cushion: FillBucket = { role: 'goal', amountMinor: 25000, envelopeMinor: 25000, target: null }
  const quarter = fillFor(cushion, { goalMinor: 500000, buckets: [cushion] })
  assert.equal(quarter.level, 0.25)
  assert.equal(quarter.over, false)
  assert.equal(balanceGoalMinor(cushion), null)

  const past = fillFor({ ...cushion, amountMinor: 150000 }, { goalMinor: 0, buckets: [cushion] })
  assert.equal(past.level, 1)
  assert.equal(past.over, false)
})
