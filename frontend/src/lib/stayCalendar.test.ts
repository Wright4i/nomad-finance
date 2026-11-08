import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nestedStay } from './stayCalendar.ts';

test('a day inside overlapping stays picks the latest arrival', () => {
  const host = { id: 1, arriveOn: '2026-07-21', departOn: '2026-07-26' };
  const conference = { id: 2, arriveOn: '2026-07-23', departOn: '2026-07-25' };
  const covering = [host, conference];
  assert.equal(nestedStay(covering, '2026-07-22'), undefined);
  assert.equal(nestedStay(covering, '2026-07-24')?.id, 2);
  assert.equal(nestedStay(covering, '2026-07-23'), undefined);
});
