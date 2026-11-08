import assert from 'node:assert/strict';
import test from 'node:test';
import { dayChips, plannerDayCount, plannerDayDates, stayCaption } from './daySquare.ts';

const stay = {
  id: 1,
  placeName: 'Camp 1',
  arriveOn: '2026-11-05',
  departOn: '2026-11-06',
};

const payday = {
  id: 4,
  payor: 'Weekly Payor',
  on: '2026-11-05',
  paycheckMinor: 100000,
  length: 'weekly',
};

const due = {
  id: 9,
  name: 'Rent',
  type: 'rent',
  on: '2026-11-05',
  amountMinor: 38000,
  currency: 'USD',
  exponent: 2,
  paid: false,
  status: 'active' as const,
};

const paid = {
  id: 10,
  name: 'Power',
  type: 'electricity',
  on: '2026-11-05',
  amountMinor: 4000,
  currency: 'USD',
  exponent: 2,
  paid: true,
  status: 'active' as const,
};

test('a day lists the stay, the payday, then the bills', () => {
  const chips = dayChips(
    '2026-11-05',
    [stay, { ...stay, id: 2, arriveOn: '2026-11-08', departOn: '2026-11-09', placeName: 'Later' }],
    [payday, { ...payday, id: 5, on: '2026-11-08', payor: 'Later Payor' }],
    [due, paid, { ...due, id: 11, on: '2026-11-06', name: 'Tomorrow' }],
    'USD',
    2,
  );
  assert.deepEqual(chips.map((chip) => chip.name), ['Camp 1', 'Weekly Payor', 'Rent', 'Power']);
  assert.equal(chips[0].kind, 'stay');
  assert.equal(chips[0].glyph, 'tree');
  assert.equal(chips[0].caption, 'Nov 5, 2026 – Nov 6, 2026');
  assert.equal(chips[1].kind, 'income');
  assert.equal(chips[1].amountMinor, 100000);
  assert.equal(chips[2].paid, false);
  assert.equal(chips[2].amountMinor, -38000);
  assert.equal(chips[3].paid, true);
  assert.equal(chips[3].amountMinor, -4000);
});

test('a stay line names the city and state and hides the dates', () => {
  const dated = { arriveOn: '2026-11-20', departOn: '2026-11-24' };
  assert.equal(stayCaption({ ...dated, city: 'Apache Junction', region: 'AZ' }), 'Apache Junction, AZ');
  assert.equal(stayCaption({ ...dated, city: '  Mesa ', region: ' AZ ' }), 'Mesa, AZ');
  assert.equal(stayCaption({ ...dated, city: 'Mesa', region: null }), 'Mesa');
  assert.equal(stayCaption({ ...dated, city: '', region: 'AZ' }), 'AZ');
  assert.equal(stayCaption({ ...dated, city: null, region: null }), 'Nov 20, 2026 – Nov 24, 2026');
  assert.equal(stayCaption({ arriveOn: '2026-11-20', departOn: '2026-11-20' }), 'Stay');
  assert.equal(stayCaption({ arriveOn: 'soon', departOn: 'later' }), 'Stay');
  const chips = dayChips('2026-11-20', [{ ...stay, ...dated, city: 'Apache Junction', region: 'AZ' }], [], [], 'USD', 2);
  assert.equal(chips[0].caption, 'Apache Junction, AZ');
  assert.equal(chips[0].caption.includes('Nov'), false);
});

test('a day with nothing on it is empty', () => {
  assert.deepEqual(dayChips('2026-11-05', [], [], [], 'USD', 2), []);
});

test('an entry shows on each day from start through end, after stays and before paydays', () => {
  const early = { id: 3, text: 'Calendar entry 1', startOn: '2026-11-04', endOn: '2026-11-06' };
  const sameDay = { id: 8, text: 'Calendar entry 2', startOn: '2026-11-05', endOn: '2026-11-05' };
  const later = { id: 1, text: 'Calendar entry 0', startOn: '2026-11-05', endOn: '2026-11-07' };
  const middle = dayChips('2026-11-05', [stay], [payday], [due], 'USD', 2, [sameDay, early, later]);
  assert.deepEqual(middle.map((chip) => chip.name), [
    'Camp 1',
    'Calendar entry 1',
    'Calendar entry 0',
    'Calendar entry 2',
    'Weekly Payor',
    'Rent',
  ]);
  assert.equal(middle[1].kind, 'entry');
  assert.equal(middle[1].glyph, 'entry');
  assert.equal(middle[1].id, 3);
  assert.deepEqual(dayChips('2026-11-04', [], [], [], 'USD', 2, [early]).map((chip) => chip.name), ['Calendar entry 1']);
  assert.deepEqual(dayChips('2026-11-06', [], [], [], 'USD', 2, [early]).map((chip) => chip.name), ['Calendar entry 1']);
  assert.deepEqual(dayChips('2026-11-03', [], [], [], 'USD', 2, [early]), []);
  assert.deepEqual(dayChips('2026-11-07', [], [], [], 'USD', 2, [early, later]).map((chip) => chip.name), ['Calendar entry 0']);
});

test('planner span 1 and 2 show one day, 3 and 4 show two, 5 and 6 show three', () => {
  assert.equal(plannerDayCount(1), 1);
  assert.equal(plannerDayCount(2), 1);
  assert.equal(plannerDayCount(3), 2);
  assert.equal(plannerDayCount(4), 2);
  assert.equal(plannerDayCount(5), 3);
  assert.equal(plannerDayCount(6), 3);
  assert.deepEqual(plannerDayDates('2026-11-05', 1), ['2026-11-05']);
  assert.deepEqual(plannerDayDates('2026-11-05', 2), ['2026-11-05']);
  assert.deepEqual(plannerDayDates('2026-11-05', 3), ['2026-11-05', '2026-11-06']);
  assert.deepEqual(plannerDayDates('2026-11-05', 4), ['2026-11-05', '2026-11-06']);
  assert.deepEqual(plannerDayDates('2026-11-05', 5), ['2026-11-05', '2026-11-06', '2026-11-07']);
  assert.deepEqual(plannerDayDates('2026-11-30', 6), ['2026-11-30', '2026-12-01', '2026-12-02']);
  assert.deepEqual(plannerDayDates('2026-11-30', 6, '2026-11-01', '2026-11-30'), ['2026-11-30']);
  assert.deepEqual(plannerDayDates('2026-12-05', 5, '2026-11-01', '2026-11-30'), ['2026-11-01', '2026-11-02', '2026-11-03']);
});
