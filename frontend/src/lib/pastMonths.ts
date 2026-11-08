/** How many calendar months before the month on screen the globe draws. */
export const PAST_MONTHS_DEFAULT = 0;

/** How many calendar months after the month on screen the globe draws. */
export const FORWARD_MONTHS_DEFAULT = 12;

/** Trip globe label toggles start off until the user hides names, locations, or miles. */
export const HIDE_GLOBE_NAMES_DEFAULT = false;
export const HIDE_GLOBE_LOCATIONS_DEFAULT = false;
export const HIDE_GLOBE_MILES_DEFAULT = false;

export const pastMonthChoices = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as const;

export const forwardMonthChoices = pastMonthChoices;

/** `YYYY-MM` from a month key or calendar date. */
export function monthKey(value: string): string {
  const match = /^(\d{4})-(\d{2})/.exec(value);
  return match ? `${match[1]}-${match[2]}` : value;
}

/** First day of the month `pastMonths` before the month shown on the trip calendar (`YYYY-MM` or `YYYY-MM-DD`). */
export function earliestStayDay(viewMonth: string, pastMonths: number): string {
  const match = /^(\d{4})-(\d{2})$/.exec(monthKey(viewMonth));
  if (!match) {
    return viewMonth;
  }
  const months = Math.min(12, Math.max(0, Math.trunc(pastMonths)));
  const index = Number(match[1]) * 12 + (Number(match[2]) - 1) - months;
  const year = Math.floor(index / 12);
  const month = index % 12;
  return `${year}-${String(month + 1).padStart(2, '0')}-01`;
}

/** Last calendar day of the month on screen, plus `forwardMonths` full months ahead. */
export function latestStayDay(viewMonth: string, forwardMonths = 0): string {
  const match = /^(\d{4})-(\d{2})$/.exec(monthKey(viewMonth));
  if (!match) {
    return viewMonth;
  }
  const months = Math.min(12, Math.max(0, Math.trunc(forwardMonths)));
  const index = Number(match[1]) * 12 + (Number(match[2]) - 1) + months;
  const year = Math.floor(index / 12);
  const mon = (index % 12) + 1;
  const last = new Date(year, mon, 0);
  return `${year}-${String(mon).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`;
}

/** Whether a stay touches the globe window anchored on the trip calendar month. */
export function stayDrawn(
  arriveOn: string,
  departOn: string,
  viewMonth: string,
  pastMonths: number,
  forwardMonths: number,
): boolean {
  const windowStart = earliestStayDay(viewMonth, pastMonths);
  const windowEnd = latestStayDay(viewMonth, forwardMonths);
  if (departOn < windowStart) {
    return false;
  }
  return arriveOn <= windowEnd;
}
