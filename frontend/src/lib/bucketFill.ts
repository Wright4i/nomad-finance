export const MANUAL_FULL_MINOR = 100_000;

export type FillBucket = {
  role?: 'goal' | 'locked';
  amountMinor: number;
  envelopeMinor: number;
  target: { kind: 'needed' | 'balance'; amountMinor: number | null } | null;
};

export type Fill = {
  level: number;
  over: boolean;
  overBy: number;
};

export function balanceGoalMinor(bucket: FillBucket): number | null {
  if (bucket.role === 'locked' || bucket.target?.kind !== 'balance') return null;
  const amount = bucket.target.amountMinor ?? 0;
  return amount > 0 ? amount : null;
}

export function fillFor(bucket: FillBucket, group: { goalMinor: number; buckets: FillBucket[] }): Fill {
  const goal = balanceGoalMinor(bucket);
  if (goal !== null) {
    const envelope = Math.max(0, bucket.envelopeMinor);
    const over = envelope > goal;
    return {
      level: envelope <= 0 ? 0 : Math.min(1, envelope / goal),
      over,
      overBy: over ? envelope - goal : 0,
    };
  }

  const needed = bucket.role !== 'locked' && bucket.target?.kind === 'needed' ? (bucket.target.amountMinor ?? 0) : 0;
  if (needed > 0) {
    const amount = Math.max(0, bucket.amountMinor);
    const over = amount > needed;
    return {
      level: amount <= 0 ? 0 : Math.min(1, amount / needed),
      over,
      overBy: over ? amount - needed : 0,
    };
  }

  if (bucket.role === 'locked') {
    const amount = Math.max(0, bucket.amountMinor);
    if (amount <= 0) return { level: 0, over: false, overBy: 0 };
    const sum = group.buckets.reduce((total, row) => total + Math.max(0, row.amountMinor), 0);
    const funded = group.buckets.filter((row) => row.role === 'locked' && row.amountMinor > 0).length;
    if (group.goalMinor <= 0 || sum > group.goalMinor) {
      const overage = Math.max(0, sum - group.goalMinor);
      return { level: 1, over: true, overBy: funded > 0 ? Math.round(overage / funded) : 0 };
    }
    return { level: sum / group.goalMinor, over: false, overBy: 0 };
  }

  const amount = Math.max(0, bucket.amountMinor);
  return {
    level: amount <= 0 ? 0 : Math.min(1, amount / MANUAL_FULL_MINOR),
    over: false,
    overBy: 0,
  };
}
