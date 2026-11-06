import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatDate, formatMonth, isoToInput, maskDateInput, monthCells, monthOf, parseDateInput, shiftYearMonth } from './dates.ts';

test('a full date reads as a short month, a day, and a four-digit year', () => {
  assert.equal(formatDate('2026-11-05'), 'Nov 5, 2026');
  assert.equal(formatDate('2026-11-01'), 'Nov 1, 2026');
  assert.equal(formatDate('2027-02-01'), 'Feb 1, 2027');
});

test('a month with no day reads as a short month and a four-digit year', () => {
  assert.equal(formatMonth('2026-11'), 'Nov 2026');
  assert.equal(formatMonth('2026-11-05'), 'Nov 2026');
  assert.equal(formatMonth('2026-12'), 'Dec 2026');
});

test('MM / DD / YYYY parses to the ISO value the API stores', () => {
  assert.equal(isoToInput('2026-11-05'), '11 / 05 / 2026');
  assert.equal(maskDateInput('11052026'), '11 / 05 / 2026');
  assert.equal(maskDateInput('11 / 05 / 2026'), '11 / 05 / 2026');
  assert.equal(parseDateInput('11 / 05 / 2026'), '2026-11-05');
  assert.equal(parseDateInput(' 02 / 29 / 2024 '), '2024-02-29');
  assert.equal(parseDateInput('02 / 29 / 2000'), '2000-02-29');
});

test('a calendar day is the ISO date the field stores', () => {
  const november = monthCells(2026, 11);
  assert.equal(november[0], '2026-11-01');
  assert.equal(november[14], '2026-11-15');
  assert.equal(november.at(-1), '2026-11-30');
  assert.equal(november.length, 30);

  const october = monthCells(2026, 10);
  assert.deepEqual(october.slice(0, 5), [null, null, null, null, '2026-10-01']);
  assert.equal(october.filter((day) => day !== null).length, 31);
  assert.equal(october.at(-1), '2026-10-31');

  const leap = monthCells(2024, 2);
  assert.equal(leap[4], '2024-02-01');
  assert.equal(leap.at(-1), '2024-02-29');
  assert.equal(monthCells(2026, 2).at(-1), '2026-02-28');

  assert.deepEqual(monthCells(2026, 0), []);
  assert.deepEqual(monthCells(2026, 13), []);
  assert.deepEqual(monthCells(999, 1), []);
  assert.deepEqual(shiftYearMonth(2026, 1, -1), { year: 2025, month: 12 });
  assert.deepEqual(shiftYearMonth(2026, 12, 1), { year: 2027, month: 1 });
  assert.deepEqual(monthOf('2026-11-15'), { year: 2026, month: 11 });
  assert.equal(monthOf('02 / 15 / 2026'), null);
});

test('a partial or impossible date is refused', () => {
  assert.equal(parseDateInput(''), null);
  assert.equal(parseDateInput('11 / 05 / 202'), null);
  assert.equal(parseDateInput('11 / 5 / 2026'), null);
  assert.equal(parseDateInput('11/05/2026'), null);
  assert.equal(parseDateInput('2026-11-05'), null);
  assert.equal(parseDateInput('13 / 01 / 2026'), null);
  assert.equal(parseDateInput('00 / 10 / 2026'), null);
  assert.equal(parseDateInput('02 / 31 / 2026'), null);
  assert.equal(parseDateInput('02 / 29 / 2025'), null);
  assert.equal(parseDateInput('02 / 29 / 1900'), null);
  assert.equal(parseDateInput('04 / 31 / 2026'), null);
});
