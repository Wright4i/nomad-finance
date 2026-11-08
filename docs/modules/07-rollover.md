# Module 7 — Rollover

Status: now

When a month ends, the envelope stays in that bucket. It does not return to available-to-assign, and it does not become a different amount. The envelope is one number for every accounting period. Changing it on a current or later period changes it on every period. Auto-assigned is the only amount that differs, and it is the bills in the period on screen. See [the envelope is one amount](../adr/0028-envelope-is-one-amount.md).

An assignment is zero or more. See [assignments stay at zero or more](../adr/0024-assignments-stay-zero-or-more.md).

Demo seed: Road cushion has one envelope. The closed month and the open month show that same envelope. Auto-assigned follows each month's bills.

Requires envelopes.
