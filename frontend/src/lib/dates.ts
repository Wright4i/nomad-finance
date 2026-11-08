const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export const DATE_INPUT_ERROR = 'Use MM / DD / YYYY.';

const INPUT_PATTERN = /^(\d{2}) \/ (\d{2}) \/ (\d{4})$/;

export function formatDate(iso: string): string {
  const parts = dateParts(iso);
  if (!parts) return iso;
  return `${MONTHS[parts.month - 1]} ${parts.day}, ${parts.year}`;
}

/** Month and day for the balance-due chip. Other dates keep formatDate. */
export function formatChipDate(iso: string): string {
  const parts = dateParts(iso);
  if (!parts) return iso;
  return `${MONTHS[parts.month - 1]} ${parts.day}`;
}

/**
 * Stay-list dates: month and day, joined with an en dash. The year is dropped.
 * It stays when dropping it would read as a different length: more than one year
 * apart, or one year apart while the end month and day are not earlier.
 */
export function formatStaySpan(start: string, end: string): string {
  const from = dateParts(start);
  const to = dateParts(end);
  if (!from || !to) return `${formatDate(start)} – ${formatDate(end)}`;
  const yearGap = Number(to.year) - Number(from.year);
  const endIsEarlier = to.month < from.month || (to.month === from.month && to.day < from.day);
  const ambiguous = yearGap < 0 || yearGap > 1 || (yearGap === 1 && !endIsEarlier);
  const fromText = ambiguous ? formatDate(start) : formatChipDate(start);
  const toText = ambiguous ? formatDate(end) : formatChipDate(end);
  return `${fromText} – ${toText}`;
}

/** Month/day through month/day, as in 10/16-11/15. */
export function formatSlashSpan(start: string, end: string): string {
  const from = dateParts(start);
  const to = dateParts(end);
  if (!from || !to) return '';
  return `${from.month}/${from.day}-${to.month}/${to.day}`;
}

export function formatMonth(iso: string): string {
  const match = /^(\d{4})-(\d{2})(?:-\d{2})?$/.exec(iso);
  if (!match) return iso;
  const month = Number(match[2]);
  if (month < 1 || month > 12) return iso;
  return `${MONTHS[month - 1]} ${match[1]}`;
}

export function isoToInput(iso: string): string {
  const parts = dateParts(iso);
  if (!parts) return '';
  return `${String(parts.month).padStart(2, '0')} / ${String(parts.day).padStart(2, '0')} / ${parts.year}`;
}

export function maskDateInput(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 8);
  const month = digits.slice(0, 2);
  const day = digits.slice(2, 4);
  const year = digits.slice(4, 8);
  if (digits.length <= 2) return month;
  if (digits.length <= 4) return `${month} / ${day}`;
  return `${month} / ${day} / ${year}`;
}

export function parseDateInput(raw: string): string | null {
  const match = INPUT_PATTERN.exec(raw.trim());
  if (!match) return null;
  const month = Number(match[1]);
  const day = Number(match[2]);
  const year = Number(match[3]);
  if (!isRealDate(year, month, day)) return null;
  return `${match[3]}-${match[1]}-${match[2]}`;
}

/** Monday–Friday in the calendar month before this payday. */
export function priorMonthWeekdays(iso: string): { weekdays: number; month: string } | null {
  const parts = dateParts(iso);
  if (!parts) return null;
  const prior = new Date(Number(parts.year), parts.month - 2, 1);
  const priorYear = prior.getFullYear();
  const priorMonth = prior.getMonth();
  let weekdays = 0;
  for (let day = 1; day <= 31; day++) {
    const date = new Date(priorYear, priorMonth, day);
    if (date.getMonth() !== priorMonth) break;
    const dow = date.getDay();
    if (dow !== 0 && dow !== 6) weekdays++;
  }
  return { weekdays, month: `${priorYear}-${String(priorMonth + 1).padStart(2, '0')}` };
}

export function readDateInput(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const input = document.querySelector<HTMLInputElement>(`input[name="${name}"]`);
  return input ? parseDateInput(input.value) : null;
}

/** Sunday-first cells for one month. Each day is the YYYY-MM-DD value a field stores. */
export function monthCells(year: number, month: number): Array<string | null> {
  if (!Number.isInteger(year) || !Number.isInteger(month) || year < 1000 || month < 1 || month > 12) {
    return [];
  }
  const leading = new Date(year, month - 1, 1).getDay();
  const count = new Date(year, month, 0).getDate();
  const cells: Array<string | null> = Array.from({ length: leading }, () => null);
  const prefix = `${year}-${String(month).padStart(2, '0')}`;
  for (let day = 1; day <= count; day += 1) {
    cells.push(`${prefix}-${String(day).padStart(2, '0')}`);
  }
  return cells;
}

export function shiftYearMonth(year: number, month: number, by: number): { year: number; month: number } {
  const date = new Date(year, month - 1 + by, 1);
  return { year: date.getFullYear(), month: date.getMonth() + 1 };
}

export function monthOf(iso: string): { year: number; month: number } | null {
  const parts = dateParts(iso);
  if (!parts) return null;
  return { year: Number(parts.year), month: parts.month };
}

function dateParts(iso: string): { year: string; month: number; day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match) return null;
  const month = Number(match[2]);
  const day = Number(match[3]);
  const year = Number(match[1]);
  if (!isRealDate(year, month, day)) return null;
  return { year: match[1], month, day };
}

function isRealDate(year: number, month: number, day: number): boolean {
  if (month < 1 || month > 12 || day < 1 || year < 1000) return false;
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}
