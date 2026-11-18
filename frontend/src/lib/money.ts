import currencyRows from '../../../data/currencies.json'
import { formatAmountInput, parseAmountMinor, settledAmount, signedAmountMinor } from './amountExpression'

export type CurrencyOption = {
  code: string
  name: string
  exponent: number
}

const rows = currencyRows as CurrencyOption[]
const exponents = new Map(rows.map((item) => [item.code, item.exponent]))

export const CURRENCIES: CurrencyOption[] = rows

export function exponentOf(currency: string): number {
  return exponents.get(currency.trim().toUpperCase()) ?? 2
}

export { formatChipDate, formatDate, formatMonth } from './dates'

export function todayISO(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

export function minorToInput(minor: number, exponent: number): string {
  return formatAmountInput(minor, exponent)
}

export { settledAmount }

export function dollarClass(minor: number): 'money-pos' | 'money-neg' | 'money-zero' {
  if (minor > 0) return 'money-pos'
  if (minor < 0) return 'money-neg'
  return 'money-zero'
}

export function formatMinor(minor: number, _currency = '', _exponent = 2): string {
  const sign = minor < 0 ? '-' : ''
  const abs = Math.abs(Math.trunc(minor))
  const whole = Math.trunc(abs / 100).toLocaleString('en-US')
  const frac = String(abs % 100).padStart(2, '0')
  return `${sign}$${whole}.${frac}`
}

export function parseSignedMajor(input: string, exponent: number): number | null {
  return signedAmountMinor(input, exponent)
}

export function parseMajor(input: string, exponent: number): number | null {
  return parseAmountMinor(input, exponent)
}

export function formatBps(bps: number): string {
  const whole = Math.trunc(bps / 100)
  const frac = Math.abs(bps % 100)
  return `${whole}.${String(frac).padStart(2, '0')}`
}

export function parsePercent(input: string): number | null {
  const trimmed = input.trim()
  if (trimmed === '') {
    return null
  }
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) {
    return null
  }
  const [whole, frac = ''] = trimmed.split('.')
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'))
}
