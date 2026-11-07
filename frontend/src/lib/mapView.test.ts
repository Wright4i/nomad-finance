import assert from 'node:assert/strict';
import { test } from 'node:test';
import { basemapId } from './basemap.ts';
import { paintLeg } from './legPaint.ts';
import { PAST_MONTHS_DEFAULT, pastMonthChoices, stayDrawn } from './pastMonths.ts';

test('the globe past-month control defaults to 0 and includes a stay only when the choice reaches it', () => {
  assert.equal(PAST_MONTHS_DEFAULT, 0);
  assert.deepEqual(pastMonthChoices, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
  const today = '2026-11-05';
  assert.equal(stayDrawn('2026-08-15', today, 0), false);
  assert.equal(stayDrawn('2026-08-15', today, 2), false);
  assert.equal(stayDrawn('2026-08-15', today, 3), true);
  assert.equal(stayDrawn('2026-10-30', today, 0), false);
  assert.equal(stayDrawn('2026-10-30', today, 1), true);
  assert.equal(stayDrawn('2026-11-13', today, 0), true);
  assert.equal(stayDrawn('2027-01-08', today, 0), true);
});

test('a drive with no road route is dashed with no miles and no airplane, and a flight keeps the airplane', () => {
  const missed = paintLeg(
    { driving: false, miles: 240, coordinates: [], travel: 'drive' },
    [-160, 0],
    [-140, 0],
  );
  assert.equal(missed.dashed, true);
  assert.equal(missed.miles, null);
  assert.equal(missed.airplane, false);
  assert.ok(missed.coordinates.length >= 2);

  const painted = paintLeg(
    { driving: false, miles: 240, coordinates: [], travel: 'fly' },
    [-160, 0],
    [-140, 0],
  );
  assert.equal(painted.dashed, true);
  assert.equal(painted.miles, null);
  assert.equal(painted.airplane, true);
  assert.ok(painted.coordinates.length >= 2);
  const middle = painted.coordinates[Math.floor(painted.coordinates.length / 2)];
  assert.ok(middle[0] > -160 && middle[0] < -140);

  const road = paintLeg(
    { driving: true, miles: 12, coordinates: [[-111.6, 33.48], [-111.7, 33.69]] },
    [-111.6, 33.48],
    [-111.7, 33.69],
  );
  assert.equal(road.dashed, false);
  assert.equal(road.miles, 12);
  assert.equal(road.airplane, false);
  assert.deepEqual(road.coordinates, [[-111.6, 33.48], [-111.7, 33.69]]);
  assert.deepEqual(road.segments, []);
});

test('a no-road leg across the date line is the short path, split so it does not wrap', () => {
  const painted = paintLeg(
    { driving: false, miles: 5000, coordinates: [], travel: 'fly' },
    [-114.215, 33.63],
    [135.502, 34.694],
  );
  assert.equal(painted.dashed, true);
  assert.equal(painted.miles, null);
  assert.equal(painted.airplane, true);
  assert.ok(painted.segments.length >= 2);
  let span = 0;
  for (const segment of painted.segments) {
    assert.ok(segment.length >= 2);
    for (let i = 1; i < segment.length; i++) {
      const step = segment[i][0] - segment[i - 1][0];
      assert.ok(Math.abs(step) <= 180);
      span += step;
    }
  }
  assert.ok(Math.abs(span) < 180, `short-path span ${span}`);
  const middle = painted.coordinates[Math.floor(painted.coordinates.length / 2)];
  assert.ok(Math.abs(middle[0]) > 90, `airplane longitude ${middle[0]}`);
  const onSegment = painted.segments.some((segment) =>
    segment.some((point) => Math.abs(point[0] - middle[0]) < 0.001 && Math.abs(point[1] - middle[1]) < 0.001),
  );
  assert.equal(onSegment, true);
});

test('night selects positron and day selects voyager', () => {
  assert.equal(basemapId('dark'), 'positron');
  assert.equal(basemapId(undefined), 'positron');
  assert.equal(basemapId('light'), 'voyager');
  assert.equal(basemapId('light', true), 'satellite');
  assert.equal(basemapId('dark', true), 'satellite');
});
