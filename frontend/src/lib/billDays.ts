export type DatedBill = {
  id: number
  name: string
  shownOn: string
  amountMinor: number
}

export function billDays<T extends DatedBill>(rows: T[]): { date: string, bills: T[] }[] {
  const sorted = [...rows].sort((left, right) =>
    left.shownOn.localeCompare(right.shownOn)
    || right.amountMinor - left.amountMinor
    || left.name.localeCompare(right.name)
    || left.id - right.id,
  )
  const days: { date: string, bills: T[] }[] = []
  for (const bill of sorted) {
    const last = days[days.length - 1]
    if (!last || last.date !== bill.shownOn) {
      days.push({ date: bill.shownOn, bills: [bill] })
    } else {
      last.bills.push(bill)
    }
  }
  return days
}
