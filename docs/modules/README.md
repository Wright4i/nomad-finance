# Modules

Build the module marked `next`. Leave `later` and `blocked` modules unbuilt until this list moves them to `next`. One module is `next` at a time. Screen and field rules for the current app stay in the `now` notes. A `blocked` module waits for an ADR named in its note. A `dropped` module stays unbuilt.

| Status | Module | Purpose |
| --- | --- | --- |
| now | [Portal](00-portal.md) | Sign-in shell, menu, and planner |
| now | [Accounts](01-accounts.md) | Typed balances |
| now | [Bills](02-bills.md) | Reminders that do not change a balance |
| dropped | [Exchange rates](03-rates.md) | Dropped; books are dollars |
| now | [Buckets](04-buckets.md) | Buckets grouped as Needs, Wants, or Savings |
| now | [Pay cycles](05-pay-cycles.md) | One pay cycle per payor |
| now | [Budget](06-envelopes.md) | Assign cash in the current month |
| now | [Rollover](07-rollover.md) | Unspent stays; an assignment is zero or more |
| now | [Targets](08-targets.md) | Dated goals and a balance to hold |
| dropped | [Plans](09-plans.md) | Percent caps, removed; gauges stay on the calendar |
| dropped | [Credit picture](10-debt.md) | Payoff picture, removed; interest, minimum due, due day, and credit limit stay |
| dropped | [Postings](11-postings.md) | Dropped; a balance is only what you type |
| dropped | [Splits](12-splits.md) | Dropped with postings |
| later | [Tags and rules](13-tags-and-rules.md) | Tags, and if-then rules on the payee, memo, or amount |
| later | [Reconcile](14-reconcile.md) | Pending holds, then a statement check |
| dropped | [Attachments](15-attachments.md) | A receipt file is not wanted |
| later | [Import](16-import.md) | CSV, OFX, QFX, and QIF into postings |
| blocked | [Age of money](17-age-of-money.md) | Days from inflow to outflow, after postings |
| later | [Forecast](18-forecast.md) | Bills placed on pay periods |
| later | [Tracking accounts](19-tracking.md) | Net worth without joining the assignment pool |
| dropped | [Portfolio](20-portfolio.md) | Holdings you type; quotes wait for an ADR |
| later | [Privacy](21-privacy.md) | Masked figures, export, and delete |
| dropped | [Household](22-household.md) | A second person sharing a budget |
| dropped | [Bank sync](23-bank-sync.md) | A connection that fetches postings |
| dropped | [Coaching](24-coaching.md) | Courses, challenges, and a community |
| now | [Trips](25-trips.md) | A stay on a globe, with lodging that does not move a balance |
| now | [Transfers](26-transfers.md) | Pay a paid-in-full card, or move money between asset accounts |

Menu items are New log, then Planner, Calendar, and Trips, then Income, Bills, Budget, and Transfers, then Accounts and Vehicles. A light rule sits under Finance, under New log, under Trips, and under Budget. New log is larger, bold, centered, and white on the primary teal, with corners half as round as the other menu pills. The footer sits at the bottom: a light rule, the account name, Reset demo data when the signed-in user is Demo, the period dates, Previous and Next as one slanted pair, a light rule, then a night and day pill immediately above Log out. The pill is one rounded control split into a moon half and a sun half. The active half is teal with a black icon, and the other half is black with a white icon. Anyone who is not Demo opens their name there to change that name or password. Income opens the payor list at `/pay-cycles`. Add bucket on Budget opens a new bucket. The bucket list is Budget. `/buckets` opens Budget. Planner still links to Bills. Vehicles is a direct menu link. Accounts does not link to them.

Press and hover feedback, reduced motion, tooltips, and a modal before a destructive action belong on every screen. Autocomplete belongs where a person types a payee or a bucket. A celebration belongs to a rare milestone: available-to-assign hitting zero once in a month, a debt hitting zero, or a target date met. Ordinary edits stay quiet.

A module is unfinished until the demo seed contains every type, side, recurrence, and status that module can store. The sample is one full-timing RVer: named accounts, three live payors, and campground stays that cover every day from the first arrival through the last departure. `composer demo:reset` restores that set.

`data/usd-rates.json` is not loaded at runtime. Themes beyond the existing light and dark stay a line on the portal note.
