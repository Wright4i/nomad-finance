# Module 6 — Envelopes

Status: now

Assign cash into buckets for the current month until available-to-assign is zero. This module follows [typed balances](../adr/0001-typed-balances.md): assignments are amounts you type. It does not replace the account balance.

Available-to-assign is the typed cash in checking, savings, and cash accounts, minus the sum assigned to buckets. A transfer between those accounts leaves the pool unchanged. Every one of those balances is included. Credit cards, loans, fuel accounts, and tracking accounts stay out. If typed cash drops below what is assigned, the pool goes negative. New assignments are still allowed. You bring the pool back by editing a balance or moving an assignment by hand.

An assignment may be negative. That is overspending, and the bucket's overspend choice decides whether it is pulled from available-to-assign or carried. See [rollover](07-rollover.md).

You assign in the current month, and you can plan the next one. Current Month and Next Month move the assignment window. Previous month opens a closed month when this payor has one. Amounts you type for the next month are in place when it opens. Until then, available-to-assign still follows the current month. If you have not planned the next month, bucket amounts carry in when it opens. A plan replaces that carry. Each month keeps the amount assigned to each bucket after it closes. A closed month can be read and cannot be edited. A correction belongs in the current month.

Each bucket is a square tile: name, glyph, and two fields, Assigned and Max. Goal tiles come first. Locked tiles follow, with a lock. Assigned is the cash for this month. Max is the amount to hold. On a locked bucket Max is the unpaid bills in that span and the field cannot be typed. A goal's Max is its target amount, and typing one stores a balance to hold. A closed month can be read. The water line rises from the bottom. When Max is above zero the fill is Assigned divided by Max; above Max the water stays full and the tile reads Over by the difference. With no Max the fill is this amount divided by the largest in the group. Zero is empty. A negative assignment stays cover-now or carry. Assigned and Max save one second after the last change, and Enter saves the same way.

A bill fills its bucket on the oldest active pay cycle. Each unpaid due date inside the open span, and inside the next span, adds the bill amount. An overdue unpaid date fills the open span once. The next span is written as soon as a due date falls there. Available-to-assign still follows the open span. Closed spans stay as they were. Cash typed above or below the bill portion stays through the next recompute. Mark paid and unpay leave the assignment alone. Pause takes the bill out. Resume puts it back. The row lists each contributing bill and its amount. A bucket that has a target shows what to set aside beside its amount. The target uses the bucket amount, not only the assignment in the month on screen.

Reaching zero available-to-assign celebrates once in that month.

Demo seed: a current month with one assignment on Needs 1, and a closed month that still has its row. Savings 2, Cash 2, and Cash 3 are in the pool as cents.

Each payor has an open span. Available-to-assign is one pool for the books, and an assignment is stored on one payor's span. See [one cash pool](../adr/0012-one-pool-across-payors.md). The pay-period tables keep their names.
