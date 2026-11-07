export const SPAN_LENGTHS = ['1 week', '2 weeks', '4 weeks', '1 month', '2 months', '3 months'] as const;

export type SpanLength = (typeof SPAN_LENGTHS)[number];

const WEEK_DAYS: Record<string, number> = {
  '1 week': 7,
  '2 weeks': 14,
  '4 weeks': 28,
};

const MONTHS: Record<string, number> = {
  '1 month': 1,
  '2 months': 2,
  '3 months': 3,
};

export function spanShift(start: string, length: string, steps: number): string {
  if (steps === 0) return start;
  const weeks = WEEK_DAYS[length];
  if (weeks !== undefined) return addDays(start, weeks * steps);
  const months = MONTHS[length] ?? 1;
  return addMonths(start, months * steps);
}

/** Inclusive last day. A month ends the day before the same day N months later. */
export function spanEnd(start: string, length: string): string {
  return addDays(spanShift(start, length, 1), -1);
}

export function spanDays(start: string, end: string): string[] {
  const days: string[] = [];
  let cursor = start;
  while (cursor <= end) {
    days.push(cursor);
    cursor = addDays(cursor, 1);
  }
  return days;
}

/** Leading empty cells put the start date under its weekday. No day outside the span. */
export function spanCells(start: string, end: string): Array<string | null> {
  const [year, month, day] = start.split('-').map(Number);
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
  return [...Array.from({ length: weekday }, () => null), ...spanDays(start, end)];
}

function addDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function addMonths(start: string, months: number): string {
  const [year, month, day] = start.split('-').map(Number);
  const cursor = new Date(Date.UTC(year, month - 1 + months, 1));
  const last = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 0)).getUTCDate();
  cursor.setUTCDate(Math.min(day, last));
  return cursor.toISOString().slice(0, 10);
}
