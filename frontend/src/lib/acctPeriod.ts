export const PERIOD_LENGTHS = ['1 week', '2 weeks', '4 weeks', '1 month', '2 months', '3 months'] as const

export type PeriodLength = (typeof PERIOD_LENGTHS)[number]

const WEEK_DAYS: Record<string, number> = {
  '1 week': 7,
  '2 weeks': 14,
  '4 weeks': 28,
}

const MONTHS: Record<string, number> = {
  '1 month': 1,
  '2 months': 2,
  '3 months': 3,
}

export function periodShift(start: string, length: string, steps: number): string {
  if (steps === 0) return start
  const weeks = WEEK_DAYS[length]
  if (weeks !== undefined) return addDays(start, weeks * steps)
  const months = MONTHS[length] ?? 1
  return addMonths(start, months * steps)
}

export function periodEnd(start: string, length: string): string {
  return addDays(periodShift(start, length, 1), -1)
}

export function periodDays(start: string, end: string): string[] {
  const days: string[] = []
  let cursor = start
  while (cursor <= end) {
    days.push(cursor)
    cursor = addDays(cursor, 1)
  }
  return days
}

export function periodCells(start: string, end: string): Array<string | null> {
  const [year, month, day] = start.split('-').map(Number)
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay()
  return [...Array.from({ length: weekday }, () => null), ...periodDays(start, end)]
}

function addDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day))
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

export const PERIOD_REACH = 11

export function periodStepsFromToday(start: string, length: string, today: string): number {
  const weeks = WEEK_DAYS[length]
  if (weeks !== undefined) return Math.floor(daySpan(start, today) / weeks)
  if (start <= today && today <= periodEnd(start, length)) return 0

  let step = 0
  if (today > start) {
    do {
      step += 1
      if (step > 24000) return step
    } while (today > addDays(periodShift(start, length, step + 1), -1))
    return step
  }
  do {
    step -= 1
    if (step < -24000) return step
  } while (periodShift(start, length, step) > today)
  return step
}

export function periodWithinReach(start: string, length: string, today: string): boolean {
  const steps = periodStepsFromToday(start, length, today)
  return steps >= -PERIOD_REACH && steps <= PERIOD_REACH
}

function daySpan(from: string, to: string): number {
  const [fromYear, fromMonth, fromDay] = from.split('-').map(Number)
  const [toYear, toMonth, toDay] = to.split('-').map(Number)
  return Math.round((Date.UTC(toYear, toMonth - 1, toDay) - Date.UTC(fromYear, fromMonth - 1, fromDay)) / 86400000)
}

function addMonths(start: string, months: number): string {
  const [year, month, day] = start.split('-').map(Number)
  const cursor = new Date(Date.UTC(year, month - 1 + months, 1))
  const last = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 0)).getUTCDate()
  cursor.setUTCDate(Math.min(day, last))
  return cursor.toISOString().slice(0, 10)
}
