import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lockedBucketMaxMinor, wantGaugeLevels } from './wantGauge.ts';

test('a locked bucket max is the saved target, otherwise the bill amount', () => {
  assert.equal(lockedBucketMaxMinor(5000, 2200), 5000);
  assert.equal(lockedBucketMaxMinor(null, 2200), 2200);
  assert.equal(lockedBucketMaxMinor(undefined, 2200), 2200);
  assert.equal(lockedBucketMaxMinor(0, 2200), 2200);
  assert.equal(lockedBucketMaxMinor(null, 0), 0);
  assert.equal(lockedBucketMaxMinor(0, 0), 0);
});

test('the fill is assigned over max and caps at full before an amount is typed', () => {
  const under = wantGaugeLevels(2200, 10000, 0);
  assert.equal(under.assignedLevel, 0.22);
  assert.equal(under.previewLevel, 0);
  assert.equal(under.over, false);
  assert.equal(under.overMinor, 0);
  assert.equal(under.neutral, false);

  const full = wantGaugeLevels(2200, 2200, 0);
  assert.equal(full.assignedLevel, 1);
  assert.equal(full.previewLevel, 0);
  assert.equal(full.over, false);

  const alreadyOver = wantGaugeLevels(3000, 2200, 0);
  assert.equal(alreadyOver.assignedLevel, 1);
  assert.equal(alreadyOver.previewLevel, 0);
  assert.equal(alreadyOver.over, true);
  assert.equal(alreadyOver.overMinor, 800);
});

test('a typed amount adds a striped section above assigned and stops at the top', () => {
  const fits = wantGaugeLevels(2200, 10000, 3000);
  assert.equal(fits.assignedLevel, 0.22);
  assert.equal(fits.previewLevel, 0.3);
  assert.equal(fits.over, false);
  assert.equal(fits.overMinor, 0);

  const spills = wantGaugeLevels(2200, 10000, 9000);
  assert.equal(spills.assignedLevel, 0.22);
  assert.equal(spills.previewLevel, 0.78);
  assert.equal(spills.assignedLevel + spills.previewLevel, 1);
  assert.equal(spills.over, true);
  assert.equal(spills.overMinor, 1200);
});

test('a zero max stays an empty bar and does not divide', () => {
  const empty = wantGaugeLevels(0, 0, 0);
  assert.equal(empty.assignedLevel, 0);
  assert.equal(empty.previewLevel, 0);
  assert.equal(empty.neutral, true);
  assert.equal(empty.over, false);

  const keyed = wantGaugeLevels(0, 0, 500);
  assert.equal(keyed.assignedLevel, 0);
  assert.equal(keyed.previewLevel, 0);
  assert.equal(keyed.neutral, true);
  assert.equal(keyed.over, true);
  assert.equal(keyed.overMinor, 500);

  const assignedOnly = wantGaugeLevels(2200, 0, 0);
  assert.equal(assignedOnly.assignedLevel, 0);
  assert.equal(assignedOnly.neutral, true);
  assert.equal(assignedOnly.over, true);
  assert.equal(assignedOnly.overMinor, 2200);
});
