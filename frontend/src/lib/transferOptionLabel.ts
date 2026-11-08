import { accountLabel } from './accountLabel.ts';

function formatBalanceMinor(minor: number): string {
  const sign = minor < 0 ? '-' : '';
  const abs = Math.abs(Math.trunc(minor));
  const whole = Math.trunc(abs / 100).toLocaleString('en-US');
  const frac = String(abs % 100).padStart(2, '0');
  return `${sign}$${whole}.${frac}`;
}

export function transferOptionLabel(
  account: {
    name: string;
    institution?: string | null;
    holder?: string | null;
    side: string;
    balanceMinor: number;
  },
  _currency: string,
): string {
  const shown = account.side === 'liability' ? -account.balanceMinor : account.balanceMinor;
  return `${accountLabel(account)} | ${formatBalanceMinor(shown)}`;
}
