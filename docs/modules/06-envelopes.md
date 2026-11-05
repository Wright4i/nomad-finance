# Module 6 — Envelopes

Status: now

Assign cash into buckets for the current month until available-to-assign is zero. This module follows [typed balances](../adr/0001-typed-balances.md): assignments are amounts you type. It does not create a posting, and it does not replace the account balance.

Available-to-assign is the typed cash in checking, savings, and cash accounts, converted into the base currency with the manual rate, minus the sum assigned to buckets. A transfer between those accounts leaves the pool unchanged. Cash with no rate is named and left out of the pool. Credit cards, loans, and tracking accounts stay out. If typed cash drops below what is assigned, the pool goes negative. New assignments are still allowed. You bring the pool back by editing a balance or moving an assignment by hand.

An assignment may be negative. That is overspending, and the bucket's overspend choice decides whether it is pulled from available-to-assign or carried. See [rollover](07-rollover.md).

You assign in the current month, and you can plan the next one. Current Month and Next Month move the assignment window. Previous month opens a closed month when this payor has one. Amounts you type for the next month are in place when it opens. Until then, available-to-assign still follows the current month. If you have not planned the next month, bucket amounts carry in when it opens. A plan replaces that carry. Each month keeps the amount assigned to each bucket after it closes. A closed month can be read and cannot be edited. A correction belongs in the current month.

Each bucket shows a bar of what is still remaining of the amount assigned in the month on screen. The bar grows from the left. Remaining at or below zero is over and uses the over color. The assigned amount stays beside the name.

A bill fills its bucket on the oldest active pay cycle. Each unpaid due date inside the open span, and inside the next span, adds the bill amount converted to the base currency. An overdue unpaid date fills the open span once. The next span is written as soon as a due date falls there. Available-to-assign still follows the open span. A missing rate leaves that bill out and names it. A later rate refresh does not rewrite the row. Closed spans stay as they were. Cash typed above or below the bill portion stays through the next recompute. Mark paid and unpay leave the assignment alone. Pause takes the bill out. Resume puts it back. The row lists each contributing bill and its converted amount. A bucket that has a target shows what to set aside beside its amount. The target uses the bucket amount, not only the assignment in the month on screen.

Reaching zero available-to-assign celebrates once in that month.

Demo seed: a current month with one assignment on Needs 1, a closed month that still has its row, and a cash account whose currency has no rate so the pool names it.

Each payor has an open span. Available-to-assign is one pool for the books, and an assignment is stored on one payor's span. See [one cash pool](../adr/0012-one-pool-across-payors.md). The pay-period tables keep their names.
