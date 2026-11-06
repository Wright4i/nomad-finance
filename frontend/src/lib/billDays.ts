export type DatedBill = {
  id: number;
  name: string;
  shownOn: string;
  amountMinor: number;
};

/** Dates stay in order. On one date the largest amount comes first, then the name, then the id. */
export function billDays<T extends DatedBill>(rows: T[]): { date: string; bills: T[] }[] {
  const sorted = [...rows].sort((left, right) =>
    left.shownOn.localeCompare(right.shownOn)
    || right.amountMinor - left.amountMinor
    || left.name.localeCompare(right.name)
    || left.id - right.id,
  );
  const days: { date: string; bills: T[] }[] = [];
  for (const bill of sorted) {
    const last = days[days.length - 1];
    if (!last || last.date !== bill.shownOn) {
      days.push({ date: bill.shownOn, bills: [bill] });
    } else {
      last.bills.push(bill);
    }
  }
  return days;
}
