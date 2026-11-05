# Module 7 — Rollover

Status: now

When a month ends, money still assigned to a bucket stays in that bucket. It does not return to available-to-assign.

A negative assignment is overspending. Each bucket chooses how it is covered. See [cover or carry](../adr/0014-overspend-cover-or-carry.md).

| Choice | Rule |
| --- | --- |
| Cover now | The default. While the period is open, the negative amount is pulled from available-to-assign. It does not enter the next period |
| Carry | The negative amount stays in the bucket, does not change available-to-assign, and enters the next month with it |

A positive amount enters the next period either way. The closed period keeps the amount it had.

Demo seed: Needs 1 on cover now, and Wants 1 on carry. Monthly Payor's closed month and current month each hold Wants 1 at −150.00.

Requires envelopes.
