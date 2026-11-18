export function formatBuildStamp(date: Date, timeZone = 'America/Los_Angeles'): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: '2-digit',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date)
  const pick = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? '00'
  let hour = pick('hour')
  if (hour === '24') hour = '00'
  return `${pick('year')}${pick('month')}${pick('day')}.${hour}${pick('minute')}`
}

declare const __BUILD_STAMP__: string | undefined

export const buildStamp = typeof __BUILD_STAMP__ !== 'undefined' ? __BUILD_STAMP__ : 'dev'
