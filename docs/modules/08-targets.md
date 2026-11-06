# Module 8 — Targets

Status: now

A target sits on a bucket. It compares itself to the bucket amount, which is the sum of open assignments. It cannot see a purchase. That waits for postings. A bucket can have one target, or none. The bucket form offers none or a target balance. Tiles do not show a date or a still-to-hold line.

| Kind | Rule |
| --- | --- |
| Needed by date | An amount, a date, and one pay cycle. The gap is the target minus the bucket amount. The per-period amount divides that gap, half up, by the periods of that cycle that start on or before the date, including the open one. An irregular cycle counts the open period and its next payday, then stops. When no period is left, the whole gap remains. |
| Target balance | An amount to hold, with no end date. The remainder is the whole gap. It is not divided. |

The amount is greater than zero for the first two kinds. A needed-by-date target refuses an archived pay cycle. Leaving the target off the save keeps the one already stored. Sending none removes it. Changing the kind, amount, date, or pay cycle clears a celebration that already happened.

A needed-by-date target is met when the bucket amount has reached the target and the date has not passed. That celebrates once. A date that has passed does not celebrate. A target balance can be met, and it stays quiet.

See [one pay cycle per dated target](../adr/0015-target-uses-one-pay-cycle.md).

Demo seed: Tires and Repair reserve, each in Savings. Tires is 1,200.00 on Remote salary for 2027-02-01. Repair reserve is 500.00.

Requires buckets, pay cycles, and Budget.
