export type CalendarBillStatus = 'active' | 'paused' | 'paid'

export type CalendarBillKind = 'due' | 'paid'

export type CalendarBillSide = 'paid' | 'unpaid'

export function calendarBillSide(status: CalendarBillStatus, kind: CalendarBillKind): CalendarBillSide {
  return status === 'paid' || kind === 'paid' ? 'paid' : 'unpaid'
}

export function calendarBillColor(status: CalendarBillStatus, kind: CalendarBillKind): '--coral' | '--lilac' {
  return calendarBillSide(status, kind) === 'paid' ? '--lilac' : '--coral'
}

export function calendarBillShown(
  status: CalendarBillStatus,
  kind: CalendarBillKind,
  showPaid: boolean,
  showUnpaid: boolean,
): boolean {
  return calendarBillSide(status, kind) === 'paid' ? showPaid : showUnpaid
}
