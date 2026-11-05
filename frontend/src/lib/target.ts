import { formatDate, formatMinor } from './money';
import type { CategoryTarget } from './types';

export function targetHint(target: CategoryTarget | null | undefined, currency: string, exponent?: number): string {
  if (!target) {
    return '';
  }
  if (target.kind === 'balance') {
    return target.met
      ? 'Holding the target'
      : `Still ${formatMinor(target.neededMinor ?? 0, currency, exponent)} to hold`;
  }
  if (target.met) {
    return 'Target met';
  }
  if (target.passed) {
    return `Date passed · ${formatMinor(target.neededMinor ?? 0, currency, exponent)} still to set aside`;
  }
  if (target.neededMinor === null || target.periodsLeft === null || target.targetDate === null) {
    return 'Needed by date';
  }
  const periods = target.periodsLeft === 1 ? '1 month' : `${target.periodsLeft} months`;
  return `Set aside ${formatMinor(target.neededMinor, currency, exponent)} a month · ${periods} until ${formatDate(target.targetDate)}`;
}
