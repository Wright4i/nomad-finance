# Module 2 — Bills

A bill is a reminder drawn from one account.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters |
| Type | A needs, wants, or savings kind. Fuel homes on the locked Fuel bucket. Other and misc each store a name you type |
| Draw-from account | Required, not archived |
| Amount | Integer cents, greater than zero |
| Recurrence | once, weekly, monthly, yearly |
| Next due date | Calendar date. Becomes the anchor when you save the schedule |
| Autopay | Flag only |
| Notes | Optional, up to 2000 characters |

Mark paid records that occurrence on the due date it had. A repeating bill then advances one interval from that due date. Monthly and yearly keep the anchor day and clamp to the last day of a short month, so 31 January becomes 28 February and then 31 March. The new date can still sit in the past. A one-off becomes paid and leaves the due list. The paid occurrence of a repeating bill stays on that due date, counts in the paid total, and offers Unpay only. The next occurrence stays unpaid and offers Mark paid only. One occurrence does not show both. Unpay removes that latest paid occurrence and leaves the bill in place. A repeating bill moves its next due date back one interval. A one-off becomes active again. Another unpay is refused, because only that latest date is stored. Pause hides a bill from due-soon and leaves it on the list. Resume puts it back on due-soon. Mark paid is for active bills. A paid one-off does not block archiving the draw-from account. An active or paused bill does. Autopay stays a flag. Mark paid and unpay leave the balance as you last typed it. A paid fuel bill can store a vehicle, an odometer, and a price per gallon. Those readings do not change the account balance. The type is the kind of reminder, and it picks the group. Other and misc each ask for a name you type, from 1 to 80 characters, and the list shows that name. A preset type stores no extra name. Every bill sits on a bucket in that group. A fuel bill sits on Fuel Bucket and cannot move. Saving, moving, pausing, or resuming the bill writes its unpaid dues into that bucket on the oldest active pay cycle. Mark paid and unpay leave the assignment alone. See [a bill fills its bucket](../adr/0020-bill-fills-bucket.md).

Calendar shows a repeating bill's paid occurrence on the due date that was paid, and the next occurrence on its new due date. Later months show each following due date as unpaid, stepped forward from that next date by the bill's recurrence. A paid occurrence is not also drawn as unpaid. An unpaid occurrence is a coral box and a paid occurrence is a lilac box, with the name, a small mark for that bill type, and the amount. The day dialog shows that same mark and colors the amount the same way. The month bar's Paid figure includes those paid amounts. Stays and paydays share that month. The month on screen labels the sum of the amounts shown. A note when you save a higher amount than the one already stored still waits. That price note needs no posting. [Forecast](18-forecast.md) places these due dates onto pay periods.
