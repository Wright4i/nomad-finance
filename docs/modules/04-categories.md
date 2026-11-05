# Module 4 — Buckets

Status: now

A bucket is not a bill type and not an account type. The groups are Needs, Wants, or Savings. Those three are the whole set. The screen says bucket. Routes stay `/categories`.

Every user has one locked bucket per bill type, in that type's group. Other and misc are one each. The name, group, and overspend stay fixed. There is no amount to hold. Archive and edit are refused. A bill files on its type's locked bucket unless the form points it at a goal in the same group. A fuel bill cannot.

A bucket a person creates is a goal. It has a name, a group, an overspend, and a target. The locked bucket names are reserved. Archiving a goal sends its bills back to the locked bucket for each bill's type. Unarchive puts the name back on the list.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters. Fixed on a system bucket |
| Group | Needs, Wants, or Savings. Fixed on a system bucket |
| Overspend | Cover now, or carry. Cover now is the default. Fixed on a system bucket. See [rollover](07-rollover.md) |
| Target | On a goal: none, needed by a date, or a balance to hold. On a locked bucket: none. See [targets](08-targets.md) |

The amount a bucket holds is the sum assigned to it in open months. Demo seed: one locked bucket per bill type, plus one goal in each group, named Needs 1, Wants 1, and Savings 1. Manual tiles come first. Locked tiles follow, with a lock and no amount field.

Envelopes, targets, and postings all use this list. Build this module before those.
