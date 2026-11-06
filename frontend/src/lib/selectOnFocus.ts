/**
 * A filled text field selects its value on focus so typing replaces it.
 * The click that moved focus would place a caret on mouseup and clear that
 * selection, so that one mouseup selects again. A later click in the same
 * field is left alone and can place a caret.
 */

export const TEXT_INPUT_TYPES: ReadonlySet<string> = new Set([
  'text',
  'search',
  'email',
  'url',
  'tel',
  'password',
  'number',
]);

export type TextField = {
  /** Identity of the control across the press, the focus, and the mouseup. */
  id: object;
  kind: 'input' | 'textarea';
  type: string;
  value: string;
  contentEditable: boolean;
  focused: boolean;
  connected: boolean;
};

export function isSelectableText(field: Pick<TextField, 'kind' | 'type' | 'value' | 'contentEditable'>): boolean {
  if (field.contentEditable || field.value.length === 0) return false;
  if (field.kind === 'textarea') return true;
  return field.kind === 'input' && TEXT_INPUT_TYPES.has(field.type.toLowerCase());
}

export function createSelectOnFocus() {
  let armed: object | null = null;
  let composing = false;

  return {
    setComposing(active: boolean): void {
      composing = active;
      if (active) armed = null;
    },

    /** Tabbing in, and the focus half of a click, selects a filled text field. */
    focusIn(field: TextField | null): boolean {
      if (composing || !field || !field.connected) return false;
      return isSelectableText(field);
    },

    /**
     * Arm the mouseup only when this press will move focus into a filled field.
     * A press on a field that is already focused does not arm.
     */
    mouseDown(field: TextField | null, button = 0): void {
      armed = null;
      if (composing || button !== 0 || !field || field.focused || !field.connected) return;
      if (!isSelectableText(field)) return;
      armed = field.id;
    },

    /**
     * The mouseup of the focusing press selects again. Any other mouseup,
     * including a second click in the focused field, does not.
     */
    mouseUp(field: TextField | null, button = 0, isComposing = false): boolean {
      const id = armed;
      armed = null;
      if (composing || isComposing || button !== 0 || !field) return false;
      if (id !== field.id || !field.focused || !field.connected) return false;
      return isSelectableText(field);
    },
  };
}

function textControl(target: EventTarget | null): HTMLInputElement | HTMLTextAreaElement | null {
  if (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement) return target;
  return null;
}

function describe(el: HTMLInputElement | HTMLTextAreaElement, doc: Document): TextField {
  return {
    id: el,
    kind: el instanceof HTMLTextAreaElement ? 'textarea' : 'input',
    type: el instanceof HTMLInputElement ? el.type : 'text',
    value: el.value,
    contentEditable: el.isContentEditable,
    focused: doc.activeElement === el,
    connected: el.isConnected,
  };
}

function selectFieldValue(el: HTMLInputElement | HTMLTextAreaElement): void {
  const end = el.value.length;
  if (end === 0) return;
  try {
    el.setSelectionRange(0, end);
  } catch {
    try {
      el.select();
    } catch {
      // A number input can reject both calls during composition.
    }
  }
}

/** One listener for every text field. Buttons, checks, sliders, and files are ignored. */
export function installSelectOnFocus(doc: Document = document): () => void {
  const session = createSelectOnFocus();

  const onMouseDown = (event: MouseEvent): void => {
    if (event.isComposing) session.setComposing(true);
    const el = textControl(event.target);
    session.mouseDown(el ? describe(el, doc) : null, event.button);
  };

  const onFocusIn = (event: FocusEvent): void => {
    if (event.isComposing) return;
    const el = textControl(event.target);
    if (!el || !session.focusIn(describe(el, doc))) return;
    selectFieldValue(el);
  };

  const onMouseUp = (event: MouseEvent): void => {
    const el = textControl(event.target);
    const select = session.mouseUp(el ? describe(el, doc) : null, event.button, event.isComposing);
    if (!select || !el) return;
    // The browser places a caret on mouseup, after the focus selection.
    event.preventDefault();
    selectFieldValue(el);
  };

  const onCompositionStart = (): void => {
    session.setComposing(true);
  };

  const onCompositionEnd = (): void => {
    session.setComposing(false);
  };

  doc.addEventListener('mousedown', onMouseDown, true);
  doc.addEventListener('focusin', onFocusIn, true);
  doc.addEventListener('mouseup', onMouseUp, true);
  doc.addEventListener('compositionstart', onCompositionStart, true);
  doc.addEventListener('compositionend', onCompositionEnd, true);

  return () => {
    doc.removeEventListener('mousedown', onMouseDown, true);
    doc.removeEventListener('focusin', onFocusIn, true);
    doc.removeEventListener('mouseup', onMouseUp, true);
    doc.removeEventListener('compositionstart', onCompositionStart, true);
    doc.removeEventListener('compositionend', onCompositionEnd, true);
  };
}
