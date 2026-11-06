# Module 5 — Pay cycles

Status: now

Each payor has one pay cycle. A payor is a named source of pay. A second paycheck from the same payor is an edit to a cash balance inside that payor's period. Another payor gets another cycle. Archiving hides the payor and keeps the cycle. The list shows those payor cards two across on a wide screen and one per row on a phone. A card alone on the last row stays in the first column.

The open period on a card is month and day only, in roman type, with the check on the same line, such as Nov 1 - Nov 30 | $7,000.00. Log Payroll sits at the top right of the card, beside the payor name, and opens payroll. Chips show the roll date, the pay kind, and the monthly amount labeled Monthly. The monthly amount is the check scaled to twelve months, half up to the cent. An irregular cycle has no monthly amount. The payday the cycle is based on stays on the pay cycle screen.

| Field | Rule |
| --- | --- |
| Payor | Required, trimmed, 1–80 characters, unique for that user |
| Payday | The date the cycle is based on |
| Length | weekly, biweekly, semimonthly, monthly, or irregular |
| Second day | Required for semimonthly |
| Next payday | Required while the length is irregular |
| Pay | Salary, fixed, or hourly |
| Rate | Greater than zero, in the base currency. Salary is per year. Fixed is the paycheck. Hourly is per hour |
| Hours | Required for hourly when the mode is typed, and for a monthly weekday. Typed hours are the hours in one period. A monthly weekday stores the hours per weekday. Up to 1000, with two decimal places |
| Hours mode | typed or weekdays. Typed is the default. Weekdays is a semimonthly cycle or a monthly weekday |
| Average window | Optional, and only on an hourly cycle. 3, 4, 5, or 6 months. Off by default |

Monthly is one length. The period does not silently become the calendar month. Weekly steps 7 days from the payday, and biweekly steps 14. Semimonthly uses the payday's day and a second day of the month, from 1 to 31, and those two days are different. Short months clamp, then the chosen day returns. The payday you save stays the date the cycle is based on. The open period rolls itself when today reaches the next payday. An irregular cycle stores that next payday and stays open until you type one. When today reaches it, that date becomes the payday and the next one is empty again. There is no close button.

The paycheck for one period is figured from the rate. A salary on a weekly, biweekly, semimonthly, or monthly cycle is the annual rate split across 52, 26, 24, or 12 paydays. Fixed pay is the rate. Hourly pay is the rate times the hours. An irregular salary is not split into a paycheck. See [a paycheck from a rate](../adr/0013-paycheck-from-a-rate.md).

Hours mode is typed or weekdays. Typed is the default and uses the hours on the cycle as the hours in one period. Weekdays on a semimonthly cycle counts Monday through Friday in the calendar month before the payday's month, times 8, times the hourly rate. That gross is split across the two paydays, half up, with the odd minor unit on the earlier day. Weekdays on a monthly cycle is a monthly weekday. It counts those same weekdays, times the hours per weekday on the cycle, times the hourly rate. Saturday and Sunday are left out. That amount is one paycheck. A payday in November uses October. It is a paycheck preview only. See [weekday hours and an inflow average](../adr/0018-weekday-hours-and-inflow-average.md).

Average payroll is a checkbox on any pay cycle, off by default. A payroll entry is one dollar amount on one payday. A keyed amount wins. When average payroll is on and a payday has no entry, that payday shows the average of keyed amounts dated before it, half up. Otherwise the payday shows the paycheck from the rate. See [average payroll](../adr/0023-average-payroll.md).

Budget assigns into the current month. The calendar shows each paycheck as a preview on the timeline.

Demo seed: three live payors — Camp host, Remote salary, and Photo gigs — and one archived cycle for every other length and mode. Each payday is 2026-11-01. Design retainer uses a second day of 15. Photo gigs has next payday 2026-12-15. Camp host is hourly. Remote salary is salary. Photo gigs and Warehouse job are fixed. Editing days is semimonthly in weekdays mode. Client average leaves average payroll on. Contract days is hourly for 3.00 hours per weekday, with payday 2026-11-01, so the preview uses October 2026.
