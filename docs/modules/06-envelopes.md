# Module 6 — Budget

Status: now

Assign cash into buckets for the month across every payor. This module follows [typed balances](../adr/0001-typed-balances.md). A goal assignment is an amount you type. A locked bucket starts at its bills and can be typed higher, not lower. Neither replaces the account balance.

Available to assign is income still coming in this month, plus every asset account except savings, minus what the buckets already hold. A payday before today is left out. The line under it is what is already assigned of that income plus those accounts. Needs is 50% of that income plus those accounts, Wants 30%, and Savings 20%, half up to the cent. The modal lists the paychecks still coming in and those accounts, and the lines add up to the amount under the headline. A transfer between those accounts leaves that portion unchanged. Savings accounts, credit cards, loans, fuel accounts, and tracking accounts stay out. A goal's maximum stays on its tile and does not reduce the total. A locked bucket's unpaid bills are counted once for the month, not once per payor. Upcoming and closed months do not change the number.

An assignment is zero or more. See [rollover](07-rollover.md).

You assign in the current month, and you can plan the next one. Current Month and Next Month move the assignment window. Previous month opens a closed month. Amounts you type for the next month are in place when it opens. Until then, available-to-assign still follows the current month. A month you have not planned opens empty, except the bills due that month. Each month keeps the amount assigned to each bucket after it closes. A closed month can be read and cannot be edited. A correction belongs in the current month.

Each group heading names Needs, Wants, or Savings and, beside that name, Assigned, Max, and Target for the month on screen. Assigned is the sum of Assigned on the buckets in that group, across every payor, each goal amount once. Max is the sum of Max: a saved target when one is set, and for a locked bucket with no saved target the unpaid bills in that month. Target is that group's share of available to assign: Needs 50%, Wants 30%, Savings 20%, half up to the cent. The planner gauges keep their share of goal assignments.

Each bucket is a square tile: name, glyph, and two fields, Assigned and Max. Goal tiles come first. Locked tiles follow, with a lock. Assigned is the cash for this month. Max is the amount to hold. On a locked bucket Assigned starts at the bills in that month, the field can be typed, and it cannot be lower than those bills. Max starts at that same amount, and typing a different one stores a balance to hold. A goal's Max is its target amount, and typing one stores a balance to hold. A closed month can be read. The water rises from the bottom, and the surface turns. When Max is above zero the fill is Assigned divided by Max; above Max the water stays full, turns orange, and a red footer under the tile reads Over by the difference. With no Max the fill is this amount divided by the largest in the group. Zero is empty. Assigned and Max save one second after the last change, and Enter saves the same way.

A locked bucket's assignment on screen starts at the bills in that month, counted once, and can be higher. An overdue unpaid date counts in the open month once. Available to assign follows the open month across every payor. Paying a bill lowers that locked assignment, and it lowers the locked maximum when no amount to hold is saved. A goal assignment stays as typed and lowers available to assign by that amount. It does not change an account balance. Pause takes the bill out. Resume puts it back. The row lists each contributing bill and its amount. A tile does not show a date or a still-to-hold line.

Reaching zero available-to-assign celebrates once in that month.

Demo seed: a current month with one assignment on Road cushion, and a closed month that still has its row. Camp jar and Glovebox are in the pool. Rig fund stays out of the pool and stays in net worth.

Each payor has an open span. Available to assign is one total for the books, and an assignment is stored on one payor's span. See [one cash pool](../adr/0012-one-pool-across-payors.md). The pay-period tables keep their names.
