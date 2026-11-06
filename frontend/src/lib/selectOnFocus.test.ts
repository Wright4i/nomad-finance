import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createSelectOnFocus, isSelectableText, type TextField } from './selectOnFocus.ts';

function field(overrides: Partial<TextField> = {}): TextField {
  return {
    id: {},
    kind: 'input',
    type: 'text',
    value: '2490.00',
    contentEditable: false,
    focused: false,
    connected: true,
    ...overrides,
  };
}

test('a click on a filled text field selects on focus and again on that mouseup', () => {
  const session = createSelectOnFocus();
  const amount = field();
  session.mouseDown(amount);
  amount.focused = true;
  assert.equal(session.focusIn(amount), true);
  assert.equal(session.mouseUp(amount), true);
});

test('a second click in the focused field does not select again', () => {
  const session = createSelectOnFocus();
  const amount = field({ focused: true });
  session.mouseDown(amount);
  assert.equal(session.mouseUp(amount), false);
});

test('tabbing into a filled field selects, and a later mouseup does not', () => {
  const session = createSelectOnFocus();
  const amount = field({ focused: true });
  assert.equal(session.focusIn(amount), true);
  assert.equal(session.mouseUp(amount), false);
});

test('an empty text field and an empty textarea do nothing', () => {
  const session = createSelectOnFocus();
  const empty = field({ value: '' });
  session.mouseDown(empty);
  empty.focused = true;
  assert.equal(session.focusIn(empty), false);
  assert.equal(session.mouseUp(empty), false);

  const notes = field({ kind: 'textarea', type: 'text', value: '' });
  assert.equal(isSelectableText(notes), false);
  assert.equal(session.focusIn({ ...notes, focused: true }), false);
});

test('a filled textarea, money amount, and the other text-like types select', () => {
  const session = createSelectOnFocus();
  for (const type of ['text', 'search', 'email', 'url', 'tel', 'password', 'number']) {
    const control = field({ type, value: type === 'number' ? '12' : 'Checking 1' });
    session.mouseDown(control);
    control.focused = true;
    assert.equal(session.focusIn(control), true, type);
    assert.equal(session.mouseUp(control), true, type);
  }

  const notes = field({ kind: 'textarea', value: 'Note' });
  session.mouseDown(notes);
  notes.focused = true;
  assert.equal(session.focusIn(notes), true);
  assert.equal(session.mouseUp(notes), true);
});

test('checks, radios, files, sliders, buttons, and date controls are left alone', () => {
  const session = createSelectOnFocus();
  for (const type of ['checkbox', 'radio', 'file', 'range', 'button', 'submit', 'date', 'datetime-local', 'time', 'color', 'hidden']) {
    const control = field({ type, value: '1' });
    assert.equal(isSelectableText(control), false, type);
    session.mouseDown(control);
    control.focused = true;
    assert.equal(session.focusIn(control), false, type);
    assert.equal(session.mouseUp(control), false, type);
  }

  assert.equal(session.focusIn(null), false);
  assert.equal(session.mouseUp(null), false);
});

test('a contenteditable control is left alone', () => {
  const session = createSelectOnFocus();
  const editable = field({ contentEditable: true });
  session.mouseDown(editable);
  editable.focused = true;
  assert.equal(session.focusIn(editable), false);
  assert.equal(session.mouseUp(editable), false);
});

test('composition is not interrupted', () => {
  const session = createSelectOnFocus();
  const amount = field();
  session.setComposing(true);
  session.mouseDown(amount);
  amount.focused = true;
  assert.equal(session.focusIn(amount), false);
  assert.equal(session.mouseUp(amount, 0, true), false);

  session.setComposing(false);
  const next = field();
  session.mouseDown(next);
  next.focused = true;
  assert.equal(session.focusIn(next), true);
  assert.equal(session.mouseUp(next), true);
});

test('a mouseup on a different control does not use a stale arm', () => {
  const session = createSelectOnFocus();
  const amount = field();
  const other = field({ focused: true });
  session.mouseDown(amount);
  assert.equal(session.mouseUp(other), false);
  amount.focused = true;
  assert.equal(session.mouseUp(amount), false);
});
