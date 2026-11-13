export type WantGaugeLevels = {
  /** Solid fill from the bottom: Assigned / Max, capped at full. */
  assignedLevel: number;
  /** Striped addition above Assigned. The two levels sum to at most 1. */
  previewLevel: number;
  /** Assigned plus the typed amount is above Max. */
  over: boolean;
  /** Cents above Max. Zero when the bar is not over. */
  overMinor: number;
  /** Max is zero, so the bar stays empty and no ratio is taken. */
  neutral: boolean;
};

/**
 * Max for a locked bucket: a saved target when one is set, otherwise the unpaid bill amount.
 * A missing or zero target is not a saved maximum.
 */
export function lockedBucketMaxMinor(targetMinor: number | null | undefined, billMinor: number): number {
  if (typeof targetMinor === 'number' && Number.isFinite(targetMinor) && targetMinor > 0) {
    return Math.trunc(targetMinor);
  }
  if (Number.isFinite(billMinor) && billMinor > 0) {
    return Math.trunc(billMinor);
  }
  return 0;
}

/**
 * Preview fill for a want log. The typed amount does not change the bucket.
 * A zero Max stays an empty bar. Assigned plus the typed amount above Max is the overage.
 */
/** Rounded percent of goal assigned. Null when goal is zero but something is assigned. */
export function wantGaugeGoalPercent(assignedMinor: number, goalMinor: number): number | null {
  const assigned = positive(assignedMinor);
  const goal = positive(goalMinor);
  if (goal === 0) {
    return assigned === 0 ? 0 : null;
  }
  return Math.round((assigned * 100) / goal);
}

export type WantGaugeSpendLevels = {
  /** Fill height: envelope left after this spend, over the starting envelope. */
  remainingLevel: number;
  over: boolean;
  overMinor: number;
  /** Envelope minus the typed amount when not over; zero when over. */
  remainingMinor: number;
  /** Envelope minus the typed amount; may be negative. */
  afterMinor: number;
  neutral: boolean;
};

/** Preview for a need or want log: typing spends down the envelope, not on top of it. */
export function wantGaugeSpendLevels(availableMinor: number, typedMinor: number): WantGaugeSpendLevels {
  const available = positive(availableMinor);
  const typed = positive(typedMinor);
  if (available === 0) {
    const after = -typed;
    return {
      remainingLevel: 0,
      over: typed > 0,
      overMinor: typed,
      remainingMinor: 0,
      afterMinor: after,
      neutral: true,
    };
  }
  const after = available - typed;
  const over = after < 0;
  return {
    remainingLevel: over ? 0 : after / available,
    over,
    overMinor: over ? typed - available : 0,
    remainingMinor: over ? 0 : after,
    afterMinor: after,
    neutral: false,
  };
}

export function wantGaugeLevels(assignedMinor: number, maxMinor: number, typedMinor: number): WantGaugeLevels {
  const assigned = positive(assignedMinor);
  const max = positive(maxMinor);
  const typed = positive(typedMinor);
  const total = assigned + typed;

  if (max === 0) {
    const over = total > 0;
    return {
      assignedLevel: 0,
      previewLevel: 0,
      over,
      overMinor: over ? total : 0,
      neutral: true,
    };
  }

  const assignedLevel = Math.min(1, assigned / max);
  const over = total > max;
  const previewLevel = Math.min(1 - assignedLevel, typed / max);

  return {
    assignedLevel,
    previewLevel,
    over,
    overMinor: over ? total - max : 0,
    neutral: false,
  };
}

function positive(value: number): number {
  if (!Number.isFinite(value) || value <= 0) {
    return 0;
  }
  return Math.trunc(value);
}
