import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatBuildStamp } from './buildStamp.ts';

test('the build stamp is yymmdd.HH24mm', () => {
  const stamp = formatBuildStamp(new Date('2026-11-12T20:41:00-08:00'));
  assert.equal(stamp, '261112.2041');
});

test('midnight uses 00 for the hour', () => {
  const stamp = formatBuildStamp(new Date('2026-01-01T00:05:00-08:00'));
  assert.equal(stamp, '260101.0005');
});
