# Module 6 — Envelopes

Status: now

Assign cash into buckets for the current month until available-to-assign is zero. This module follows [typed balances](../adr/0001-typed-balances.md). A goal assignment is an amount you type. A locked bucket's assignment is its unpaid bills. Neither replaces the account balance.

Available-to-assign is the typed cash in checking, savings, and cash accounts, minus the sum assigned to buckets. A transfer between those accounts leaves the pool unchanged. Every one of those balances is included. Credit cards, loans, fuel accounts, and tracking accounts stay out. If typed cash drops below what is assigned, the pool goes negative. New assignments are still allowed. You bring the pool back by editing a balance or moving an assignment by hand.

An assignment is zero or more. See [rollover](07-rollover.md).

You assign in the current month, and you can plan the next one. Current Month and Next Month move the assignment window. Previous month opens a closed month when this payor has one. Amounts you type for the next month are in place when it opens. Until then, available-to-assign still follows the current month. If you have not planned the next month, bucket amounts carry in when it opens. A plan replaces that carry. Each month keeps the amount assigned to each bucket after it closes. A closed month can be read and cannot be edited. A correction belongs in the current month.

Each bucket is a square tile: name, glyph, and two fields, Assigned and Max. Goal tiles come first. Locked tiles follow, with a lock. Assigned is the cash for this month. Max is the amount to hold. On a locked bucket Assigned is the unpaid bills in that span and the field cannot be typed. Max starts at that same amount, and typing a different one stores a balance to hold. A goal's Max is its target amount, and typing one stores a balance to hold. A closed month can be read. The water line rises from the bottom. When Max is above zero the fill is Assigned divided by Max; above Max the water stays full and the tile reads Over by the difference. With no Max the fill is this amount divided by the largest in the group. Zero is empty. Assigned and Max save one second after the last change, and Enter saves the same way.

A locked bucket's assignment on screen is the unpaid bills in that span. An overdue unpaid date counts in the open span once. Available-to-assign follows the open span of the payor on screen, and it counts those locked bills once along with goal assignments from every open period. Paying a bill lowers that locked assignment. A goal assignment stays as typed. Pause takes the bill out. Resume puts it back. The row lists each contributing bill and its amount. A bucket that has a target shows what to set aside beside its amount. The target uses the bucket amount, not only the assignment in the month on screen.

Reaching zero available-to-assign celebrates once in that month.

Demo seed: a current month with one assignment on Needs 1, and a closed month that still has its row. Savings 2, Cash 2, and Cash 3 are in the pool as cents.

Each payor has an open span. Available-to-assign is one pool for the books, and an assignment is stored on one payor's span. See [one cash pool](../adr/0012-one-pool-across-payors.md). The pay-period tables keep their names.
