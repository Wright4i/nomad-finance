# Module 4 — Buckets

Status: now

A bucket is not a bill type and not an account type. The groups are Needs, Wants, or Savings. Those three are the whole set. The screen says bucket. Budget lists the buckets. `/buckets` opens Budget. Add and edit stay at `/buckets/new` and `/buckets/:id`. The bucket name opens that form. Cancel, save, archive, and unarchive return to Budget. Show archived on Budget lists a hidden goal.

Every user has one locked bucket per bill type, in that type's group. Other and misc are one each. The name and group stay fixed. Archive and edit are refused. On Budget its assignment starts at the bills for the month on screen. Fuel also starts with the fuel estimate of each stay that arrives that month and has not ended. Assigned can be typed, and it cannot be lower than those bills. The maximum starts at that same amount and can be typed. A bill files on its type's locked bucket unless the form points it at a goal in the same group. A fuel bill cannot.

A bucket a person creates is a goal. It has a name, a group, and a target. The locked bucket names are reserved. Archiving a goal sends its bills back to the locked bucket for each bill's type. Unarchive puts the name back on the list.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters. Fixed on a system bucket |
| Group | Needs, Wants, or Savings. Fixed on a system bucket |
| Target | On a goal: none, or a balance to hold. On a locked bucket the maximum is an amount to hold, starting at the unpaid bills. See [targets](08-targets.md) |

The amount a bucket holds is the sum assigned to it in open months. An assignment is zero or more. Demo seed: one locked bucket per bill type, plus one goal in each group, named Road cushion, Fun money, and Next rig. Manual tiles come first. Locked tiles follow, with a lock, an assignment that starts at the bills and can be typed higher, and an open maximum. The name on a locked tile opens Bills with that bill type already chosen.

Budget, targets, and postings all use this list. Build this module before those.
