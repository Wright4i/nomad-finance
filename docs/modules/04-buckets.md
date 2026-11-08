# Module 4 — Buckets

Status: now

A bucket is not a bill type and not an account type. The groups are Needs, Wants, or Savings. Those three are the whole set. The screen says bucket. Budget lists the buckets. `/buckets` opens Budget. Add and edit stay at `/buckets/new` and `/buckets/:id`. The bucket name opens that form. Cancel, save, archive, and unarchive return to Budget. Show archived on Budget lists a hidden goal.

Every user has one locked bucket per bill type, in that type's group. Other and misc are one each. The name and group stay fixed. Archive and edit are refused. On Budget a locked bucket shows three amounts. Bill assignments is a chip above its amount. A goal with no target shows a No goal chip instead, and hides that chip and Target. Bill assignments are the bills for the accounting period on screen, and on Fuel they also include the fuel estimate of each stay that arrives in that period and has not ended. The amount is locked. Envelope is the only amount you type. Target is auto-assigned plus the envelope, and it is locked. A new bill, a new log, or new trip fuel raises auto-assigned and lowers the envelope by the same amount when the envelope has room, so the target stays. When the envelope cannot cover the increase it reaches zero and the target rises. The envelope cannot go below zero. A bill files on its type's locked bucket unless the form points it at a goal in the same group. A fuel bill cannot.

A bucket a person creates is a goal. It has a name, a group, and a target. The locked bucket names are reserved. Archiving a goal sends its bills back to the locked bucket for each bill's type. Unarchive puts the name back on the list.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters. Fixed on a system bucket |
| Group | Needs, Wants, or Savings. Fixed on a system bucket |
| Target | On the budget tile, auto-assigned plus the envelope. On a goal form: none, or a balance to hold. See [targets](08-targets.md) |

The amount a bucket holds is the sum assigned to it in open months. An assignment is zero or more. Demo seed: one locked bucket per bill type, plus one goal in each group, named Road cushion, Fun money, and Next rig. Manual tiles come first. Locked tiles follow, with a lock, a locked auto-assigned amount, an envelope you type, and a locked target. The name on a locked tile opens Bills with that bill type already chosen.

Budget, targets, and postings all use this list. Build this module before those.
