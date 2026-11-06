import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backdropShouldClose, pointInsidePanel } from './modalBackdrop.ts';

const panel = { left: 320, top: 180, right: 960, bottom: 620 };

test('a click on the backdrop closes the modal', () => {
  assert.equal(backdropShouldClose({
    targetIsDialog: true,
    startedOutside: true,
    pointInsidePanel: false,
  }), true);
  assert.equal(pointInsidePanel({ x: 20, y: 400 }, panel), false);
});

test('a click on the panel stays open', () => {
  assert.equal(backdropShouldClose({
    targetIsDialog: false,
    startedOutside: false,
    pointInsidePanel: true,
  }), false);
  assert.equal(pointInsidePanel({ x: 400, y: 240 }, panel), true);
});

test('a click on the dialog padding stays open', () => {
  assert.equal(backdropShouldClose({
    targetIsDialog: true,
    startedOutside: false,
    pointInsidePanel: true,
  }), false);
  assert.equal(pointInsidePanel({ x: panel.left, y: panel.top }, panel), true);
  assert.equal(pointInsidePanel({ x: panel.right, y: panel.bottom }, panel), true);
});

test('a press that starts inside the panel and ends outside stays open', () => {
  assert.equal(backdropShouldClose({
    targetIsDialog: true,
    startedOutside: false,
    pointInsidePanel: false,
  }), false);
});

test('a press that starts on the backdrop and ends inside the panel stays open', () => {
  assert.equal(backdropShouldClose({
    targetIsDialog: true,
    startedOutside: true,
    pointInsidePanel: true,
  }), false);
  assert.equal(backdropShouldClose({
    targetIsDialog: false,
    startedOutside: true,
    pointInsidePanel: true,
  }), false);
});
