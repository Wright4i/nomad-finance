export type PanelBox = {
  left: number;
  top: number;
  right: number;
  bottom: number;
};

export type BackdropPress = {
  /** The click landed on the dialog element, which is where a backdrop click is delivered. */
  targetIsDialog: boolean;
  /** The press started on the backdrop, outside the panel. */
  startedOutside: boolean;
  /** The click coordinates sit inside the dialog's border box, including its padding. */
  pointInsidePanel: boolean;
};

/**
 * A backdrop click closes the modal. A click on the panel stays open, including
 * a press that starts inside the panel and ends outside it.
 */
export function backdropShouldClose(press: BackdropPress): boolean {
  return press.targetIsDialog && press.startedOutside && !press.pointInsidePanel;
}

export function pointInsidePanel(point: { x: number; y: number }, panel: PanelBox): boolean {
  return point.x >= panel.left && point.x <= panel.right && point.y >= panel.top && point.y <= panel.bottom;
}

const startedOutside = new WeakMap<HTMLDialogElement, boolean>();

function modalOf(target: EventTarget | null): HTMLDialogElement | null {
  if (target instanceof HTMLDialogElement && target.classList.contains('modal')) {
    return target;
  }
  if (target instanceof Element) {
    const dialog = target.closest('dialog.modal');
    if (dialog instanceof HTMLDialogElement) return dialog;
  }
  return null;
}

function outsidePanel(dialog: HTMLDialogElement, point: { x: number; y: number }): boolean {
  return !pointInsidePanel(point, dialog.getBoundingClientRect());
}

function onPointerDown(event: PointerEvent): void {
  const dialog = modalOf(event.target);
  if (!dialog) return;
  const backdrop = event.button === 0 && event.target === dialog && outsidePanel(dialog, { x: event.clientX, y: event.clientY });
  startedOutside.set(dialog, backdrop);
}

function onClick(event: MouseEvent): void {
  if (!(event.target instanceof HTMLDialogElement)) return;
  const dialog = event.target;
  if (!dialog.classList.contains('modal') || !dialog.open) return;
  const close = backdropShouldClose({
    targetIsDialog: true,
    startedOutside: startedOutside.get(dialog) === true,
    pointInsidePanel: pointInsidePanel({ x: event.clientX, y: event.clientY }, dialog.getBoundingClientRect()),
  });
  startedOutside.set(dialog, false);
  if (close) dialog.close();
}

/** One listener for every modal. A backdrop click is a click on the dialog element itself. */
export function installModalBackdrop(doc: Document = document): () => void {
  doc.addEventListener('pointerdown', onPointerDown, true);
  doc.addEventListener('click', onClick);
  return () => {
    doc.removeEventListener('pointerdown', onPointerDown, true);
    doc.removeEventListener('click', onClick);
  };
}
