# Modules

Build the module marked `next`. Leave `later` and `blocked` modules unbuilt until this list moves them to `next`. One module is `next` at a time. Screen and field rules for the current app stay in the `now` notes. A `blocked` module waits for an ADR named in its note. A `dropped` module stays unbuilt.

| Status | Module | Purpose |
| --- | --- | --- |
| now | [Portal](00-portal.md) | Sign-in shell, menu, and planner |
| now | [Accounts](01-accounts.md) | Typed balances |
| now | [Bills](02-bills.md) | Reminders that do not change a balance |
| now | [Exchange rates](03-rates.md) | A daily live quote, with an editable fallback |
| now | [Buckets](04-categories.md) | Buckets grouped as Needs, Wants, or Savings |
| now | [Pay cycles](05-pay-cycles.md) | One pay cycle per payor |
| now | [Envelopes](06-envelopes.md) | Assign base-currency cash in the current month |
| now | [Rollover](07-rollover.md) | Unspent stays; overspend is covered or carried |
| now | [Targets](08-targets.md) | Dated goals and a balance to hold |
| dropped | [Plans](09-plans.md) | Percent caps, removed; gauges stay on the calendar |
| dropped | [Credit picture](10-debt.md) | Payoff picture, removed; interest, minimum due, due day, and credit limit stay |
| now | [Postings](11-postings.md) | Confirmed money movement |
| now | [Splits](12-splits.md) | One posting across many buckets |
| later | [Tags and rules](13-tags-and-rules.md) | Tags, and if-then rules on the payee, memo, or amount |
| later | [Reconcile](14-reconcile.md) | Pending holds, then a statement check |
| dropped | [Attachments](15-attachments.md) | A receipt file is not wanted |
| later | [Import](16-import.md) | CSV, OFX, QFX, and QIF into postings |
| blocked | [Age of money](17-age-of-money.md) | Days from inflow to outflow, after postings |
| later | [Forecast](18-forecast.md) | Bills placed on pay periods |
| later | [Tracking accounts](19-tracking.md) | Net worth without joining the assignment pool |
| dropped | [Portfolio](20-portfolio.md) | Holdings you type; quotes wait for an ADR |
| later | [Privacy](21-privacy.md) | Masked figures, export, and delete |
| dropped | [Household](22-household.md) | A second person sharing envelopes |
| dropped | [Bank sync](23-bank-sync.md) | A connection that fetches postings |
| dropped | [Coaching](24-coaching.md) | Courses, challenges, and a community |
| now | [Trips](25-trips.md) | A stay on a globe, with lodging you confirm |
| later | [Transfers](26-transfers.md) | One action between two accounts |

Menu items are New Log, Planner, Income, Bills, Calendar, Trips, Envelopes, Accounts, and Settings. New Log sits above a light rule, larger, bold, centered, and white on the primary teal, with corners half as round as the other menu pills. A large gap sits under Accounts, then the account name, then Reset demo data when the signed-in user is Demo, then a light rule, then Settings immediately above Log out. Settings uses the same control styling as Log out. Income opens the payor list at `/pay-cycles`. Add Bucket on Envelopes opens a new bucket. The bucket list stays at `/categories`. Planner still links to Bills. Vehicles and exchange rates are reached from Settings. Accounts does not link to them.

Press and hover feedback, reduced motion, tooltips, and a modal before a destructive action belong on every screen. Autocomplete belongs where a person types a payee or a bucket. A celebration belongs to a rare milestone: available-to-assign hitting zero once in a month, a debt hitting zero, or a target date met. Ordinary edits stay quiet.

A module is unfinished until the demo seed contains every type, side, recurrence, and status that module can store, under a generic name. `composer demo:reset` restores that set.

A checked-in USD snapshot in `data/usd-rates.json` is the starting fallback. A live quote for currencies on open accounts refreshes at most once a day and is used ahead of that fallback. Themes beyond the existing light and dark stay a line on the portal note.
