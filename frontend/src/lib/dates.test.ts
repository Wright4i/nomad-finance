import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatDate, formatMonth, isoToInput, maskDateInput, parseDateInput } from './dates.ts';

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
