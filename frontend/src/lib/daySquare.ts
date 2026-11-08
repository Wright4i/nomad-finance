import { calendarBillColor, calendarBillShown } from './calendarBill.ts';
import { formatDate } from './dates.ts';

export type DayStay = {
  id: number;
  placeName: string;
  arriveOn: string;
  departOn: string;
  city?: string | null;
  region?: string | null;
};

export type DayPayday = {
  id: number;
  payor: string;
  on: string;
  paycheckMinor: number;
  length: string;
};

export type DayBill = {
  id: number;
  name: string;
  type: string;
  on: string;
  amountMinor: number;
  currency: string;
  exponent: number;
  paid: boolean;
  status: 'active' | 'paused' | 'paid';
};

export type DayEntry = {
  id: number;
  text: string;
  startOn: string;
  endOn: string;
};

export type DayChip = {
  key: string;
  id: number;
  kind: 'stay' | 'income' | 'bill' | 'entry';
  name: string;
  glyph: string;
  caption: string;
  amountMinor: number | null;
  currency: string;
  exponent: number;
  paid: boolean;
};

/**
 * City and state, such as Apache Junction, AZ. Dates stay off that line when a place is known.
 * A stay with only a city or only a state shows that one. Neither falls back to the date span,
 * or Stay when the dates are the same day or cannot be read.
 */
export function stayCaption(stay: {
  city?: string | null;
  region?: string | null;
  arriveOn: string;
  departOn: string;
}): string {
  const city = (stay.city ?? '').trim();
  const region = (stay.region ?? '').trim();
  if (city !== '' && region !== '') return `${city}, ${region}`;
  if (city !== '') return city;
  if (region !== '') return region;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(stay.arriveOn) || !/^\d{4}-\d{2}-\d{2}$/.test(stay.departOn)) return 'Stay';
  if (stay.arriveOn === stay.departOn) return 'Stay';
  return `${formatDate(stay.arriveOn)} – ${formatDate(stay.departOn)}`;
}

/** Stays, then calendar entries, then paydays, then bills, in the same order as a Calendar day. */
export function dayChips(
  date: string,
  stays: DayStay[],
  paydays: DayPayday[],
  bills: DayBill[],
  currency: string,
  exponent: number,
  entries: DayEntry[] = [],
): DayChip[] {
  const chips: DayChip[] = [];
  for (const stay of stays) {
    if (stay.arriveOn > date || date > stay.departOn) continue;
    chips.push({
      key: `stay-${stay.id}`,
      id: stay.id,
      kind: 'stay',
      name: stay.placeName,
      glyph: 'tree',
      caption: stayCaption(stay),
      amountMinor: null,
      currency,
      exponent,
      paid: false,
    });
  }
  const covered = entries
    .filter((entry) => entry.startOn <= date && date <= entry.endOn)
    .slice()
    .sort((left, right) => left.startOn.localeCompare(right.startOn) || left.id - right.id);
  for (const entry of covered) {
    chips.push({
      key: `entry-${entry.id}`,
      id: entry.id,
      kind: 'entry',
      name: entry.text,
      glyph: 'entry',
      caption: '',
      amountMinor: null,
      currency,
      exponent,
      paid: false,
    });
  }
  for (const payday of paydays) {
    if (payday.on !== date) continue;
    chips.push({
      key: `income-${payday.id}`,
      id: payday.id,
      kind: 'income',
      name: payday.payor,
      glyph: payday.length,
      caption: '',
      amountMinor: payday.paycheckMinor,
      currency,
      exponent,
      paid: false,
    });
  }
  for (const bill of bills) {
    if (bill.on !== date) continue;
    const kind = bill.paid ? 'paid' : 'due';
    if (!calendarBillShown(bill.status, kind, true, true)) continue;
    chips.push({
      key: `bill-${kind}-${bill.id}`,
      id: bill.id,
      kind: 'bill',
      name: bill.name,
      glyph: bill.type,
      caption: '',
      amountMinor: -bill.amountMinor,
      currency: bill.currency,
      exponent: bill.exponent,
      paid: calendarBillColor(bill.status, kind) === '--lilac',
    });
  }
  return chips;
}

/** Span 1–2 is today, 3–4 adds the next day, and 5–6 adds the day after that. */
export function plannerDayCount(span: number): number {
  if (span >= 5) return 3;
  if (span >= 3) return 2;
  return 1;
}

function shiftIso(iso: string, days: number): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match) return iso;
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + days);
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/** Today and the following days, in calendar order, for this saved planner span. */
export function plannerDayDates(today: string, span: number, start?: string, end?: string): string[] {
  const count = plannerDayCount(span);
  const origin = start && end && (today < start || today > end) ? start : today;
  const dates: string[] = [];
  for (let index = 0; index < count; index += 1) {
    const date = shiftIso(origin, index);
    if (end && date > end) break;
    if (start && date < start) continue;
    dates.push(date);
  }
  return dates;
}
