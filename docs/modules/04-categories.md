# Module 4 — Buckets

Status: now

A bucket is not a bill type and not an account type. The groups are Needs, Wants, or Savings. Those three are the whole set. The screen says bucket. Routes stay `/categories`.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters |
| Group | Needs, Wants, or Savings |
| Overspend | Cover now, or carry. Cover now is the default. See [rollover](07-rollover.md) |
| Target | None, needed by a date, or a balance to hold. See [targets](08-targets.md) |

This module stores the list. The amount a bucket holds is the sum assigned to it in open months. A bill may name a bucket as a label, and that label does not change the amount. Archive is refused while the amount is not zero. Unarchive puts the name back on the list.

Demo seed: one bucket in each group, named for the group, such as Needs 1.

Envelopes, targets, and postings all use this list. Build this module before those.
