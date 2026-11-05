# Module 2 — Bills

A bill is a reminder drawn from one account.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters |
| Type | lodging, rent, electricity, water, internet, cellular, gas, groceries, restaurants, vehicle, debt, healthcare, entertainment, subscriptions, shopping, travel, other |
| Draw-from account | Required, not archived. The amount uses this account's currency |
| Amount | Integer minor units, greater than zero |
| Vendor amount | Optional, together with a vendor currency from the same dropdown |
| Recurrence | once, weekly, monthly, yearly |
| Next due date | Calendar date. Becomes the anchor when you save the schedule |
| Autopay | Flag only |
| Notes | Optional, up to 2000 characters |

Mark paid records that occurrence on the due date it had. A repeating bill then advances one interval from that due date. Monthly and yearly keep the anchor day and clamp to the last day of a short month, so 31 January becomes 28 February and then 31 March. The new date can still sit in the past. A one-off becomes paid and leaves the due list. The paid occurrence of a repeating bill stays on that due date, counts in the paid total, and offers Unpay only. The next occurrence stays unpaid and offers Mark paid only. One occurrence does not show both. Unpay removes that latest paid occurrence and leaves the bill in place. A repeating bill moves its next due date back one interval. A one-off becomes active again. Another unpay is refused, because only that latest date is stored. Pause hides a bill from due-soon and leaves it on the list. Resume puts it back on due-soon. Mark paid is for active bills. A paid one-off does not block archiving the draw-from account. An active or paused bill does. Autopay stays a flag. Mark paid and unpay leave the balance as you last typed it. The type is the kind of reminder. Other asks for a name you type, from 1 to 80 characters, and the list shows that name. A preset type stores no extra name. A bucket on a bill is an optional label. It does not change the balance or the bucket amount.

Calendar shows a repeating bill's paid occurrence on the due date that was paid, and the next occurrence on its new due date. Later months show each following due date as unpaid, stepped forward from that next date by the bill's recurrence. A paid occurrence is not also drawn as unpaid. An unpaid occurrence is a coral box and a paid occurrence is a lilac box, with the name, a small mark for that bill type, and the amount. The day dialog shows that same mark and colors the amount the same way. The month bar's Paid figure includes those paid amounts. Stays and paydays share that month. The month on screen labels the sum of the amounts shown, one total per currency. A note when you save a higher amount than the one already stored still waits. That price note needs no posting. [Forecast](18-forecast.md) places these due dates onto pay periods.
