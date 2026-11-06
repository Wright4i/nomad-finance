# Tests

A change is finished when a unit test covers the rule and, if the screen changed, a Selenium test covers what a person sees, and both `composer test` and `composer test:browser` have been run.

`composer test` is the unit suite. `composer test:browser` is the Selenium suite. `phpunit.xml` lists the directories in each suite.

## Unit

Put a rule that does not need a browser under `tests/`, outside `tests/Browser`. Money, dates, account fields, bill schedules, lockout, and "mark paid leaves the balance alone" belong here. Each test opens its own SQLite file in the system temp directory and signs in as Demo when it writes books. Add a new directory to the `unit` suite in `phpunit.xml` when you create one.

## Browser

A change a person can see gets a Selenium test in `tests/Browser`. The suite starts Chrome through ChromeDriver, serves `public/build` on its own PHP port, and sets `FINANCE_DB` to a throwaway file. It signs in as Demo on that file. `data/finance.sqlite` stays out of the suite, and the owner's rows stay out of the suite. The first run downloads a ChromeDriver build that matches the installed Chrome into `var/`.

When an agent uses the running app, sign in as Demo. `composer demo:reset` restores Demo's sample books in the app database and does not change the owner.

## Demo seed

The seed in `src/Demo/DemoLibrary.php` is the sample set. It holds one account for every account type, both sides of other, and an archived account. Every account is in dollars. Savings 2, Cash 2, and Cash 3 keep the minor units they had, now as cents, and they are included in net worth and the pool. It holds one bill for every bill type, recurrence, and status, plus autopay, and an overdue due date. Paid Weekly Bill is weekly, paid on Nov 4, and next due Nov 11. Other Bill stores the other name Custom 1. Misc Bill stores the misc name Custom 2. Vehicle 1 is an rv, starred, with a minimum of 6 mpg and a maximum of 12, a current odometer of 10500, and an origin of 9800. Paid fuel bills on Cash 1 and the locked Fuel bucket hold the fill-ups: 2026-09-15 at 9980 miles for $10.00 at $4.00 a gallon, 2026-10-01 at 10000 as two charges of $6.00 and $4.00, 2026-10-15 for $5.00 with no vehicle, 2026-11-01 at 10100 for $20.00, 2026-11-08 at 10300 for $10.00, and 2026-11-18 at 10500 for $10.00. One of the vehicle fill-ups is inside the mpg range and one is outside it. One-Time Bill points at Needs 1. The fuel bill stays on the locked Fuel bucket. It holds one locked bucket per bill type, plus one goal in each group — Needs 1, Wants 1, and Savings 1 — and an archived category. Fuel 1 is a fuel liability with a credit limit. Card 1 keeps a minimum bill. Loan 1 keeps a current-balance bill. Archived Bill is archived. Needs 1 holds 500.00 from the open period. It holds one pay cycle for every length — Weekly Payor, Biweekly Payor, Semimonthly Payor, Monthly Payor, and Irregular Payor — and Archived Payor. Averaged Payor is semimonthly with average payroll on, and keyed amounts of 3000.00, 5000.00, and 4000.00. Each payday is 2026-11-01. Semimonthly Payor uses a second day of 15. Irregular Payor has next payday 2026-12-15. Weekly Payor is hourly at 25.00 for 40.00 hours. Biweekly Payor is hourly at 30.00 for 80.00 hours. Semimonthly Payor is a 60,000.00 salary. Monthly Payor is an 84,000.00 salary. Monthly Weekday Payor is hourly at 110.00 for 8.00 hours per weekday in the month before the payday. Irregular Payor is a fixed 1,500.00. Archived Payor is a fixed 100.00. Monthly Payor keeps a closed period for 2026-10-01 through 2026-10-31 with Needs 1 at 200.00, and an open period for 2026-11-01 through 2026-11-30 with Needs 1 at 500.00. Assignments are zero or more. A locked bucket's assignment is its unpaid bills. Names say what the row is: Checking 1, Card 1, One-Time Bill, Recurring Monthly Bill. Needed By Date 1 is a 1,200.00 goal in Savings on Monthly Payor for 2027-02-01. Target Balance 1 is 500.00 in Savings. Cap 1 names Needs 1 at 40%. Cap 2 names the Wants group at 30%.

When a module adds a type, side, recurrence, or status, add a seeded row for it in the same pass as the module. A variant with no seeded row is unfinished.

When `frontend/` changed, build before the browser suite:

```bash
npm run build --prefix frontend
```

## Finish a change

1. Add or update the unit test that fails if the rule regresses.
2. When the screen changed, add or update the Selenium test for what a person sees.
3. Run `composer test`.
4. Run `composer test:browser`.
5. Both commands exit 0.
