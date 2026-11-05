# Module 4 — Buckets

Status: now

A bucket is not a bill type and not an account type. The groups are Needs, Wants, or Savings. Those three are the whole set. The screen says bucket. Routes stay `/categories`.

Every user has Needs Default, Wants Default, Savings Default, and Fuel Bucket. Those four are created with the user. The name, group, and overspend stay fixed, and archive is refused. The only edit is one amount to hold, with no date and no pay cycle. Fuel Bucket is in Needs. Fuel bills and logged fuel postings are the only things filed there.

A bucket a person creates is a goal. It has a name, a group, an overspend, and a target. It is not a fuel log. The names Needs Default, Wants Default, Savings Default, and Fuel Bucket are reserved. Archiving a goal sends its bills back to that group's default and refills both buckets. Archive is still refused while the amount is not zero. Unarchive puts the name back on the list.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters. Fixed on a system bucket |
| Group | Needs, Wants, or Savings. Fixed on a system bucket |
| Overspend | Cover now, or carry. Cover now is the default. Fixed on a system bucket. See [rollover](07-rollover.md) |
| Target | On a goal: none, needed by a date, or a balance to hold. On a system bucket: one amount to hold. See [targets](08-targets.md) |

The amount a bucket holds is the sum assigned to it in open months. Demo seed: the four system buckets, plus one goal in each group, named Needs 1, Wants 1, and Savings 1.

Envelopes, targets, and postings all use this list. Build this module before those.
