import assert from 'node:assert/strict';
import { test } from 'node:test';
import { basemapId } from './basemap.ts';
import { paintLeg } from './legPaint.ts';
import {
  FORWARD_MONTHS_DEFAULT,
  HIDE_GLOBE_LOCATIONS_DEFAULT,
  HIDE_GLOBE_MILES_DEFAULT,
  HIDE_GLOBE_NAMES_DEFAULT,
  PAST_MONTHS_DEFAULT,
  forwardMonthChoices,
  pastMonthChoices,
  stayDrawn,
} from './pastMonths.ts';
import { globeFlyMs, locateZoom, stayZoom, tripFrame, worldZoom } from './tripFrame.ts';

test('the globe month controls default to 0 past and 12 forward', () => {
  assert.equal(PAST_MONTHS_DEFAULT, 0);
  assert.equal(FORWARD_MONTHS_DEFAULT, 12);
  assert.equal(HIDE_GLOBE_NAMES_DEFAULT, false);
  assert.equal(HIDE_GLOBE_LOCATIONS_DEFAULT, false);
  assert.equal(HIDE_GLOBE_MILES_DEFAULT, false);
  assert.deepEqual(pastMonthChoices, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
  assert.deepEqual(forwardMonthChoices, pastMonthChoices);
});

test('past and forward months anchor on the month shown on the trip calendar', () => {
  const viewMonth = '2026-11';
  const draw = (arrive: string, depart: string, month: string, past: number, forward: number) =>
    stayDrawn(arrive, depart, month, past, forward);
  assert.equal(draw('2026-08-01', '2026-08-15', viewMonth, 0, 0), false);
  assert.equal(draw('2026-08-01', '2026-08-15', viewMonth, 2, 0), false);
  assert.equal(draw('2026-08-01', '2026-08-15', viewMonth, 3, 0), true);
  assert.equal(draw('2026-10-01', '2026-10-30', viewMonth, 0, 0), false);
  assert.equal(draw('2026-10-01', '2026-10-30', viewMonth, 1, 0), true);
  assert.equal(draw('2026-11-13', '2026-11-20', viewMonth, 0, 0), true);
  assert.equal(draw('2027-01-02', '2027-01-08', viewMonth, 0, 0), false);
  assert.equal(draw('2027-01-02', '2027-01-08', viewMonth, 0, 12), true);
  assert.equal(draw('2027-12-01', '2027-12-15', viewMonth, 0, 12), false);
});

test('forward months clip stays after the window', () => {
  const draw = (arrive: string, depart: string, month: string, past: number, forward: number) =>
    stayDrawn(arrive, depart, month, past, forward);
  assert.equal(draw('2026-05-20', '2026-05-31', '2026-06', 0, 0), false);
  assert.equal(draw('2026-06-01', '2026-06-15', '2026-06', 0, 0), true);
  assert.equal(draw('2026-06-28', '2026-07-05', '2026-06', 0, 0), true);
  assert.equal(draw('2026-11-20', '2026-11-24', '2026-06', 0, 0), false);
  assert.equal(draw('2026-11-20', '2026-11-24', '2026-06', 0, 5), true);
  assert.equal(draw('2026-05-01', '2026-05-15', '2026-06', 3, 0), true);
  assert.equal(draw('2026-07-20', '2026-07-31', '2026-08', 0, 0), false);
  assert.equal(draw('2026-08-01', '2026-08-10', '2026-08', 0, 0), true);
  assert.equal(draw('2026-05-01', '2026-05-15', '2026-08', 2, 0), false);
  assert.equal(draw('2026-05-01', '2026-05-15', '2026-08', 3, 0), true);
});

test('the globe flight between stays lasts one second', () => {
  assert.equal(globeFlyMs, 1000);
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

test('the trips globe centers on the stays, and a step sits halfway to the locate zoom', () => {
  assert.equal(worldZoom, 3.4);
  assert.equal(locateZoom, 15);
  assert.equal(stayZoom(), (worldZoom + locateZoom) / 2);
  assert.equal(stayZoom(), 9.2);

  const drawn = [
    { longitude: -111.6135, latitude: 33.4805 },
    { longitude: -111.707, latitude: 33.689 },
    { longitude: -111.4778, latitude: 33.4812 },
    { longitude: -114.163, latitude: 34.361 },
    { longitude: -115.81, latitude: 33.741 },
    { longitude: -114.803, latitude: 33.748 },
    { longitude: -114.215, latitude: 33.63 },
  ];
  const frame = tripFrame(drawn);
  assert.ok(frame);
  assert.ok(Math.abs(frame.longitude + 113.3985) < 0.02);
  assert.ok(Math.abs(frame.latitude - 33.733) < 0.02);

  const across = tripFrame([
    { longitude: 170, latitude: 10 },
    { longitude: -170, latitude: 20 },
  ]);
  assert.ok(across);
  assert.ok(Math.abs(Math.abs(across.longitude) - 180) < 1);
  assert.equal(across.latitude, 15);
  assert.equal(tripFrame([]), null);
});

test('night selects positron and day selects voyager', () => {
  assert.equal(basemapId('dark'), 'positron');
  assert.equal(basemapId(undefined), 'positron');
  assert.equal(basemapId('light'), 'voyager');
  assert.equal(basemapId('light', true), 'satellite');
  assert.equal(basemapId('dark', true), 'satellite');
});
