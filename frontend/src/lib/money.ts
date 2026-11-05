import currencyRows from '../../../data/currencies.json';

export type CurrencyOption = {
  code: string;
  name: string;
  exponent: number;
};

const rows = currencyRows as CurrencyOption[];
const exponents = new Map(rows.map((item) => [item.code, item.exponent]));

export const CURRENCIES: CurrencyOption[] = rows;

export function exponentOf(currency: string): number {
  return exponents.get(currency.trim().toUpperCase()) ?? 2;
}

export { formatChipDate, formatDate, formatMonth } from './dates';

export function todayISO(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

export function minorToInput(minor: number, exponent: number): string {
  const abs = Math.abs(minor);
  const scale = 10 ** exponent;
  const whole = Math.trunc(abs / scale);
  const sign = minor < 0 ? '-' : '';
  if (exponent === 0) {
    return `${sign}${whole}`;
  }
  const frac = String(abs % scale).padStart(exponent, '0');
  return `${sign}${whole}.${frac}`;
}

export function formatMinor(minor: number, currency: string, exponent = exponentOf(currency)): string {
  const major = minor / 10 ** exponent;
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: exponent,
      maximumFractionDigits: exponent,
    }).format(major);
  } catch {
    return `${minorToInput(minor, exponent)} ${currency}`;
  }
}

export function parseSignedMajor(input: string, exponent: number): number | null {
  const trimmed = input.trim();
  const negative = trimmed.startsWith('-');
  const minor = parseMajor(negative ? trimmed.slice(1) : trimmed, exponent);
  if (minor === null) {
    return null;
  }
  return negative ? -minor : minor;
}

export function parseMajor(input: string, exponent: number): number | null {
  const trimmed = input.trim();
  if (!/^\d+(\.\d+)?$/.test(trimmed)) {
    return null;
  }
  const [whole, frac = ''] = trimmed.split('.');
  if (frac.length > exponent) {
    return null;
  }
  const padded = frac.padEnd(exponent, '0');
  const minor = Number(whole) * 10 ** exponent + (padded === '' ? 0 : Number(padded));
  return Number.isSafeInteger(minor) ? minor : null;
}

export function formatBps(bps: number): string {
  const whole = Math.trunc(bps / 100);
  const frac = Math.abs(bps % 100);
  return `${whole}.${String(frac).padStart(2, '0')}`;
}

export function parsePercent(input: string): number | null {
  const trimmed = input.trim();
  if (trimmed === '') {
    return null;
  }
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) {
    return null;
  }
  const [whole, frac = ''] = trimmed.split('.');
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'));
}
