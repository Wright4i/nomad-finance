export type CalendarBillStatus = 'active' | 'paused' | 'paid';

export type CalendarBillKind = 'due' | 'paid';

export type CalendarBillSide = 'paid' | 'unpaid';

/**
 * A one-off is paid when its status is paid.
 * A repeating bill's paid occurrence is the stored due date (kind paid). Its next due date stays unpaid.
 */
export function calendarBillSide(status: CalendarBillStatus, kind: CalendarBillKind): CalendarBillSide {
  return status === 'paid' || kind === 'paid' ? 'paid' : 'unpaid';
}

/** Unpaid marks stay on coral. Paid marks use the lilac token. */
export function calendarBillColor(status: CalendarBillStatus, kind: CalendarBillKind): '--coral' | '--lilac' {
  return calendarBillSide(status, kind) === 'paid' ? '--lilac' : '--coral';
}

export function calendarBillShown(
  status: CalendarBillStatus,
  kind: CalendarBillKind,
  showPaid: boolean,
  showUnpaid: boolean,
): boolean {
  return calendarBillSide(status, kind) === 'paid' ? showPaid : showUnpaid;
}
