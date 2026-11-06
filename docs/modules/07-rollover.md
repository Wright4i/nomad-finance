# Module 7 — Rollover

Status: now

When a month ends, money still assigned to a bucket stays in that bucket. It does not return to available-to-assign.

An assignment is zero or more. A positive amount enters the next period. The closed period keeps the amount it had. See [assignments stay at zero or more](../adr/0024-assignments-stay-zero-or-more.md).

Demo seed: Remote salary's closed month holds Road cushion at 200.00, and the open month holds Road cushion at 500.00.

Requires envelopes.
