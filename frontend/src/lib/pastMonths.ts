/** How many calendar months before the current one the globe draws. */
export const PAST_MONTHS_DEFAULT = 0;

export const pastMonthChoices = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as const;

/** First day of the month `pastMonths` before the month that contains today. */
export function earliestStayDay(today: string, pastMonths: number): string {
  const match = /^(\d{4})-(\d{2})-\d{2}$/.exec(today);
  if (!match) return today;
  const months = Math.min(12, Math.max(0, Math.trunc(pastMonths)));
  const index = Number(match[1]) * 12 + (Number(match[2]) - 1) - months;
  const year = Math.floor(index / 12);
  const month = index % 12;
  return `${year}-${String(month + 1).padStart(2, '0')}-01`;
}

/**
 * Current and future stays stay drawn at 0.
 * A stay that ended in an earlier month is drawn only when the past-month choice reaches that month.
 */
export function stayDrawn(departOn: string, today: string, pastMonths: number): boolean {
  return departOn >= earliestStayDay(today, pastMonths);
}
