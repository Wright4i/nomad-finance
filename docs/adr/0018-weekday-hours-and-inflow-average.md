# Weekday hours and an inflow average

The average half of this note is superseded by [average payroll](0023-average-payroll.md). Weekday hours stay.

This extends docs/adr/0013-paycheck-from-a-rate.md ([a paycheck is figured from a rate](0013-paycheck-from-a-rate.md)). Hourly pay can use a typed hours number, and that stays the default. Weekdays mode counts Monday through Friday in the calendar month before the payday's month. On a semimonthly cycle that count is times 8, times the hourly rate, and the gross is split across the two paydays, half up, with the odd minor unit on the earlier day. A monthly weekday uses the same count times the hours per weekday stored on the cycle, times the hourly rate. That amount is one paycheck and is not split. It is a paycheck preview only.

The rolling average is separate. An inflow may name a pay cycle. An outflow may not. The window is 3, 4, 5, or 6 months on an hourly cycle, and the default is off. The average is the sum of that cycle's confirmed inflows in the previous N calendar months, divided by N, half up, in the base currency. A month with no inflow counts as zero. An inflow with no usable rate is left out and named. The average does not replace the paycheck preview and does not change available-to-assign.
